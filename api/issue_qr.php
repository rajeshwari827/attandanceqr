<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// Issues a fresh 30s QR token for a ticket. Old tokens for that ticket are revoked immediately.
// GET params: ticket_id
// Response: { token, expires_at_epoch, expires_in_seconds, ticket, student, event }

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response('error', 'METHOD_NOT_ALLOWED', 405);
}

$ticketId = (int) ($_GET['ticket_id'] ?? 0);
if ($ticketId <= 0) {
    json_response('error', 'ticket_id is required', 400);
}

// Optional hardening: require student_id match (prevents guessing ticket ids).
$studentId = (int) ($_GET['student_id'] ?? 0);

$stmt = db()->prepare(
    'SELECT t.id, t.ticket_code, t.student_id, t.event_id, t.status, t.used_at,
            t.student_photo_path,
            s.full_name, s.roll_no, s.email, s.phone,
            e.title AS event_title
     FROM tickets t
     JOIN students s ON s.id = t.student_id
     JOIN events e ON e.id = t.event_id
     WHERE t.id = ?
     LIMIT 1'
);
$stmt->bind_param('i', $ticketId);
$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();

if (!$ticket) {
    json_response('error', 'TICKET_NOT_FOUND', 404);
}
if ($studentId > 0 && (int) $ticket['student_id'] !== $studentId) {
    json_response('error', 'TICKET_STUDENT_MISMATCH', 403);
}
if ((string) $ticket['status'] !== 'active') {
    json_response('error', 'TICKET_INACTIVE', 403, ['ticket_status' => (string) $ticket['status']]);
}
if ($ticket['used_at'] !== null) {
    json_response('error', 'ENTRY_ALREADY_USED', 409);
}

$now = now_utc();
$ttlSeconds = 30;
$exp = $now->modify('+' . $ttlSeconds . ' seconds');

$jti = base64url_encode(random_bytes(16));
$payload = [
    'ticket_id' => (int) $ticket['id'],
    'student_id' => (int) $ticket['student_id'],
    'event_id' => (int) $ticket['event_id'],
    'iat' => $now->getTimestamp(),
    'exp' => $exp->getTimestamp(),
    'jti' => $jti,
];
$token = qr_sign_token($payload);
$hash = token_hash($token);

db()->begin_transaction();
try {
    // Revoke any previously active token for this ticket.
    $revoke = db()->prepare(
        'UPDATE qr_tokens
         SET revoked_at = ?
         WHERE ticket_id = ? AND revoked_at IS NULL AND used_at IS NULL'
    );
    $revokedAt = dt_to_sql($now);
    $revoke->bind_param('si', $revokedAt, $ticketId);
    $revoke->execute();

    $insert = db()->prepare(
        'INSERT INTO qr_tokens (ticket_id, token_hash, issued_at, expires_at)
         VALUES (?, ?, ?, ?)'
    );
    $issuedAt = dt_to_sql($now);
    $expiresAt = dt_to_sql($exp);
    $insert->bind_param('isss', $ticketId, $hash, $issuedAt, $expiresAt);
    $insert->execute();

    db()->commit();
} catch (Throwable $e) {
    db()->rollback();
    json_response('error', 'FAILED_TO_ISSUE_TOKEN', 500);
}

json_response('ok', 'TOKEN_ISSUED', 200, [
    'token' => $token,
    'expires_at_epoch' => $exp->getTimestamp(),
    'expires_in_seconds' => $ttlSeconds,
    'ticket' => [
        'id' => (int) $ticket['id'],
        'ticket_code' => (string) $ticket['ticket_code'],
    ],
    'student' => [
        'id' => (int) $ticket['student_id'],
        'full_name' => (string) $ticket['full_name'],
        'enrollment' => (string) $ticket['roll_no'],
        'email' => (string) $ticket['email'],
        'phone' => (string) $ticket['phone'],
        'photo_url' => (string) $ticket['student_photo_path'],
    ],
    'event' => [
        'id' => (int) $ticket['event_id'],
        'name' => (string) $ticket['event_title'],
    ],
]);
