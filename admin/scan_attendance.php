<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

function json_response(string $type, string $message, int $statusCode = 200, array $extra = []): void
{
    http_response_code($statusCode);
    echo json_encode(array_merge([
        'type' => $type,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

function starts_with(string $haystack, string $needle): bool
{
    return substr($haystack, 0, strlen($needle)) === $needle;
}

function extract_qr_token(string $payload): ?string
{
    $payload = trim($payload);
    if ($payload === '') {
        return null;
    }

    if (starts_with($payload, 'ATTENDANCEQR|')) {
        $payload = substr($payload, strlen('ATTENDANCEQR|'));
    }

    if (preg_match('/token=([a-f0-9]{32})/i', $payload, $matches) === 1) {
        return strtolower($matches[1]);
    }

    if (preg_match('/^[a-f0-9]{32}$/i', $payload) === 1) {
        return strtolower($payload);
    }

    return null;
}

if (!is_admin_logged_in()) {
    json_response('error', 'Admin session expired. Please log in again.', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response('error', 'Method not allowed.', 405);
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    json_response('error', 'Invalid form token.', 419);
}

$eventId = (int) ($_POST['event_id'] ?? 0);
$payload = (string) ($_POST['qr_payload'] ?? '');

if ($eventId <= 0) {
    json_response('error', 'Please select a valid event.');
}

$token = extract_qr_token($payload);
if ($token === null) {
    json_response('error', 'Invalid QR payload. Expected format ATTENDANCEQR|token.');
}

$verifiedJoin = '';
$verifiedWhere = '';
if (defined('OTP_FLOW_ENABLED') && OTP_FLOW_ENABLED) {
    ensure_registration_email_verifications_table();
    $verifiedJoin = 'INNER JOIN registration_email_verifications rev ON rev.registration_id = r.id';
    $verifiedWhere = ' AND rev.verified_at IS NOT NULL';
}

$registrationSql = "SELECT r.id, r.attendance_status, r.attendance_marked_at,
            s.full_name,
            e.title
     FROM registrations r
     INNER JOIN students s ON s.id = r.student_id
     INNER JOIN events e ON e.id = r.event_id
     $verifiedJoin
     WHERE r.event_id = ? AND r.qr_token = ? $verifiedWhere
     LIMIT 1";

$registrationStmt = db()->prepare($registrationSql);
$registrationStmt->bind_param('is', $eventId, $token);
$registrationStmt->execute();
$registration = $registrationStmt->get_result()->fetch_assoc();

if (!$registration) {
    $otherEventStmt = db()->prepare(
        "SELECT e.title
         FROM registrations r
         INNER JOIN events e ON e.id = r.event_id
         WHERE r.qr_token = ?
         LIMIT 1"
    );
    $otherEventStmt->bind_param('s', $token);
    $otherEventStmt->execute();
    $otherEvent = $otherEventStmt->get_result()->fetch_assoc();

    if ($otherEvent) {
        json_response('error', 'This QR belongs to another event: ' . $otherEvent['title'] . '.');
    }

    json_response('error', 'No registration found for this QR token.');
}

if ($registration['attendance_status'] === 'present') {
    $alreadyAt = $registration['attendance_marked_at']
        ? date('d M Y, h:i A', strtotime((string) $registration['attendance_marked_at']))
        : 'already recorded';

    json_response(
        'info',
        $registration['full_name'] . ' is already marked present at ' . $alreadyAt . '.'
    );
}

$registrationId = (int) $registration['id'];
$updateStmt = db()->prepare(
    "UPDATE registrations
     SET attendance_status = 'present', attendance_marked_at = NOW()
     WHERE id = ?"
);
$updateStmt->bind_param('i', $registrationId);
$updateStmt->execute();

json_response(
    'success',
    'Attendance marked present for ' . $registration['full_name'] . '.'
);
