<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// Confirms entry for a validated QR token (one-time use).
// POST JSON: { token: "..." }
// Requires admin session.

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
    json_response('error', 'INVALID_QR', 400);
}

$payload = $verify['payload'];
$ticketId = (int) ($payload['ticket_id'] ?? 0);
$studentId = (int) ($payload['student_id'] ?? 0);
$eventId = (int) ($payload['event_id'] ?? 0);
$exp = (int) ($payload['exp'] ?? 0);

$now = now_utc();
if ($ticketId <= 0 || $studentId <= 0 || $eventId <= 0 || $exp <= 0) {
    json_response('error', 'INVALID_QR', 400);
}
if ($now->getTimestamp() > $exp) {
    json_response('error', 'QR_EXPIRED', 410);
}

$hash = token_hash($token);

db()->begin_transaction();
try {
    $qrStmt = db()->prepare(
        'SELECT id, ticket_id, revoked_at, used_at
         FROM qr_tokens
         WHERE token_hash = ?
         LIMIT 1
         FOR UPDATE'
    );
    $qrStmt->bind_param('s', $hash);
    $qrStmt->execute();
    $qr = $qrStmt->get_result()->fetch_assoc();

    if (!$qr || $qr['revoked_at'] !== null) {
        throw new RuntimeException('INVALID_OR_OLD_QR');
    }
    if ($qr['used_at'] !== null) {
        throw new RuntimeException('TOKEN_ALREADY_USED');
    }

    $tStmt = db()->prepare(
        'SELECT id, status, used_at
         FROM tickets
         WHERE id = ? AND student_id = ? AND event_id = ?
         LIMIT 1
         FOR UPDATE'
    );
    $tStmt->bind_param('iii', $ticketId, $studentId, $eventId);
    $tStmt->execute();
    $ticket = $tStmt->get_result()->fetch_assoc();

    if (!$ticket) {
        throw new RuntimeException('TICKET_NOT_FOUND');
    }
    if ((string) $ticket['status'] !== 'active') {
        throw new RuntimeException('TICKET_INACTIVE');
    }
    if ($ticket['used_at'] !== null) {
        throw new RuntimeException('ENTRY_ALREADY_USED');
    }

    $usedAt = dt_to_sql($now);

    $markTicket = db()->prepare('UPDATE tickets SET used_at = ? WHERE id = ? LIMIT 1');
    $markTicket->bind_param('si', $usedAt, $ticketId);
    $markTicket->execute();

    $qrTokenId = (int) $qr['id'];
    $markToken = db()->prepare('UPDATE qr_tokens SET used_at = ? WHERE id = ? LIMIT 1');
    $markToken->bind_param('si', $usedAt, $qrTokenId);
    $markToken->execute();

    $log = db()->prepare(
        'INSERT INTO entry_logs (ticket_id, student_id, event_id, qr_token_id, scanned_at, result, scanner_admin_id, scanner_ip)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $result = 'accepted';
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    $ip = client_ip();
    $log->bind_param('iiiissis', $ticketId, $studentId, $eventId, $qrTokenId, $usedAt, $result, $adminId, $ip);
    $log->execute();

    db()->commit();
} catch (Throwable $e) {
    db()->rollback();

    $msg = $e->getMessage();
    if ($msg === 'ENTRY_ALREADY_USED' || $msg === 'TOKEN_ALREADY_USED') {
        json_response('error', 'ENTRY_ALREADY_USED', 409);
    }
    if ($msg === 'INVALID_OR_OLD_QR') {
        json_response('error', 'INVALID_OR_OLD_QR', 400);
    }
    if ($msg === 'TICKET_NOT_FOUND') {
        json_response('error', 'TICKET_NOT_FOUND', 404);
    }
    if ($msg === 'TICKET_INACTIVE') {
        json_response('error', 'TICKET_INACTIVE', 403);
    }

    json_response('error', 'FAILED_TO_CONFIRM_ENTRY', 500);
}

json_response('ok', 'ENTRY_ACCEPTED', 200, [
    'ticket_id' => $ticketId,
    'used_at' => dt_to_sql($now),
]);

