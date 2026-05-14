<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// Validates a scanned QR token and returns student + event details for photo verification.
// POST JSON: { token: "..." }
// Requires admin session (security staff dashboard).

require_admin_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response('error', 'METHOD_NOT_ALLOWED', 405);
}

$body = read_json_body();
$token = trim((string) ($body['token'] ?? ''));
if ($token === '') {
    json_response('error', 'token is required', 400);
}

$verify = qr_verify_token($token);
if (!$verify['ok']) {
    json_response('error', 'INVALID_QR', 400, ['reason' => (string) $verify['error']]);
}

$payload = $verify['payload'];
$ticketId = (int) ($payload['ticket_id'] ?? 0);
$studentId = (int) ($payload['student_id'] ?? 0);
$eventId = (int) ($payload['event_id'] ?? 0);
$exp = (int) ($payload['exp'] ?? 0);

if ($ticketId <= 0 || $studentId <= 0 || $eventId <= 0 || $exp <= 0) {
    json_response('error', 'INVALID_QR', 400, ['reason' => 'MISSING_FIELDS']);
}

$now = now_utc();
if ($now->getTimestamp() > $exp) {
    // Log expired scan attempt.
    $log = db()->prepare(
        'INSERT INTO entry_logs (ticket_id, student_id, event_id, qr_token_id, scanned_at, result, scanner_admin_id, scanner_ip)
         VALUES (?, ?, ?, NULL, ?, ?, ?, ?)'
    );
    $scannedAt = dt_to_sql($now);
    $result = 'rejected_expired';
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    $ip = client_ip();
    $log->bind_param('iiissis', $ticketId, $studentId, $eventId, $scannedAt, $result, $adminId, $ip);
    $log->execute();

    json_response('error', 'QR_EXPIRED', 410);
}

$hash = token_hash($token);

$qrStmt = db()->prepare(
    'SELECT id, ticket_id, expires_at, revoked_at, used_at
     FROM qr_tokens
     WHERE token_hash = ?
     LIMIT 1'
);
$qrStmt->bind_param('s', $hash);
$qrStmt->execute();
$qr = $qrStmt->get_result()->fetch_assoc();

if (!$qr || $qr['revoked_at'] !== null) {
    $log = db()->prepare(
        'INSERT INTO entry_logs (ticket_id, student_id, event_id, qr_token_id, scanned_at, result, scanner_admin_id, scanner_ip)
         VALUES (?, ?, ?, NULL, ?, ?, ?, ?)'
    );
    $scannedAt = dt_to_sql($now);
    $result = 'rejected_invalid';
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    $ip = client_ip();
    $log->bind_param('iiissis', $ticketId, $studentId, $eventId, $scannedAt, $result, $adminId, $ip);
    $log->execute();

    json_response('error', 'INVALID_OR_OLD_QR', 400);
}

// Ticket + student + event details for verification screen.
$stmt = db()->prepare(
    'SELECT t.id AS ticket_id, t.ticket_code, t.status, t.used_at,
            t.student_photo_path,
            s.id AS student_id, s.full_name, s.roll_no, s.email, s.phone,
            e.id AS event_id, e.title AS event_title, e.event_date, e.venue
     FROM tickets t
     JOIN students s ON s.id = t.student_id
     JOIN events e ON e.id = t.event_id
     WHERE t.id = ? AND t.student_id = ? AND t.event_id = ?
     LIMIT 1'
);
$stmt->bind_param('iii', $ticketId, $studentId, $eventId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    json_response('error', 'TICKET_NOT_FOUND', 404);
}

if ((string) $row['status'] !== 'active') {
    json_response('error', 'TICKET_INACTIVE', 403);
}

if ($row['used_at'] !== null) {
    $log = db()->prepare(
        'INSERT INTO entry_logs (ticket_id, student_id, event_id, qr_token_id, scanned_at, result, scanner_admin_id, scanner_ip)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $scannedAt = dt_to_sql($now);
    $result = 'rejected_used';
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    $ip = client_ip();
    $qrTokenId = (int) $qr['id'];
    $log->bind_param('iiiissis', $ticketId, $studentId, $eventId, $qrTokenId, $scannedAt, $result, $adminId, $ip);
    $log->execute();

    json_response('error', 'ENTRY_ALREADY_USED', 409);
}

json_response('ok', 'VALID_QR', 200, [
    'token' => $token,
    'qr_token_id' => (int) $qr['id'],
    'student' => [
        'id' => (int) $row['student_id'],
        'full_name' => (string) $row['full_name'],
        'enrollment' => (string) $row['roll_no'],
        'department' => '', // add column in students table if needed
        'photo_url' => (string) $row['student_photo_path'],
    ],
    'event' => [
        'id' => (int) $row['event_id'],
        'name' => (string) $row['event_title'],
        'venue' => (string) $row['venue'],
        'event_date' => (string) $row['event_date'],
    ],
    'ticket' => [
        'id' => (int) $row['ticket_id'],
        'code' => (string) $row['ticket_code'],
        'status' => (string) $row['status'],
    ],
]);
