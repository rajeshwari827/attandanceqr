<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

function admin_json_response(string $type, string $message, int $statusCode = 200, array $extra = []): void
{
    http_response_code($statusCode);
    echo json_encode(array_merge([
        'type' => $type,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

function admin_starts_with(string $haystack, string $needle): bool
{
    return substr($haystack, 0, strlen($needle)) === $needle;
}

function admin_extract_qr_token(string $payload): ?string
{
    $payload = trim($payload);
    if ($payload === '') {
        return null;
    }

    if (admin_starts_with($payload, 'ATTENDANCEQR|')) {
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
    admin_json_response('error', 'Admin session expired. Please log in again.', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_json_response('error', 'Method not allowed.', 405);
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    admin_json_response('error', 'Invalid form token.', 419);
}

$eventId = (int) ($_POST['event_id'] ?? 0);
$payload = (string) ($_POST['qr_payload'] ?? '');
$otpInput = trim((string) ($_POST['otp'] ?? ''));

if ($eventId <= 0) {
    admin_json_response('error', 'Please select a valid event.');
}

if (preg_match('/^[0-9]{6}$/', $otpInput) !== 1) {
    admin_json_response('error', 'Enter a 6-digit OTP.');
}

$token = admin_extract_qr_token($payload);
if ($token === null) {
    admin_json_response('error', 'Invalid QR payload. Expected format ATTENDANCEQR|token.');
}

$verifiedJoin = '';
$verifiedWhere = '';
if (defined('OTP_FLOW_ENABLED') && OTP_FLOW_ENABLED) {
    ensure_registration_email_verifications_table();
    $verifiedJoin = 'INNER JOIN registration_email_verifications rev ON rev.registration_id = r.id';
    $verifiedWhere = ' AND rev.verified_at IS NOT NULL';
}

$registrationSql = "SELECT r.id, r.attendance_status, r.attendance_marked_at,
            s.full_name, s.email,
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
    admin_json_response('error', 'No registration found for this QR token.');
}

if ($registration['attendance_status'] === 'present') {
    $alreadyAt = $registration['attendance_marked_at']
        ? date('d M Y, h:i A', strtotime((string) $registration['attendance_marked_at']))
        : 'already recorded';

    admin_json_response('info', $registration['full_name'] . ' is already marked present at ' . $alreadyAt . '.');
}

$registrationId = (int) $registration['id'];

if (!(defined('ENTRY_OTP_ENABLED') && ENTRY_OTP_ENABLED)) {
    admin_json_response('error', 'Entry OTP is disabled.');
}

ensure_entry_otps_table();

$otpStmt = db()->prepare(
    'SELECT otp_code, expires_at, attempts FROM entry_otps WHERE registration_id = ? LIMIT 1'
);
$otpStmt->bind_param('i', $registrationId);
$otpStmt->execute();
$otpRow = $otpStmt->get_result()->fetch_assoc();

if (!$otpRow) {
    admin_json_response('error', 'OTP not found or expired. Please scan again to request a new OTP.');
}

$attempts = (int) ($otpRow['attempts'] ?? 0);
if ($attempts >= 5) {
    admin_json_response('error', 'Too many incorrect attempts. Please rescan to generate a fresh OTP.');
}

$expiresAt = strtotime((string) $otpRow['expires_at']);
if ($expiresAt !== false && $expiresAt < time()) {
    $del = db()->prepare('DELETE FROM entry_otps WHERE registration_id = ?');
    $del->bind_param('i', $registrationId);
    $del->execute();
    admin_json_response('error', 'OTP expired. Please scan again to request a new OTP.');
}

if ($otpInput !== (string) $otpRow['otp_code']) {
    $inc = db()->prepare('UPDATE entry_otps SET attempts = attempts + 1 WHERE registration_id = ?');
    $inc->bind_param('i', $registrationId);
    $inc->execute();
    admin_json_response('error', 'Incorrect OTP. Please try again.');
}

// OTP correct: consume OTP and mark attendance.
$del = db()->prepare('DELETE FROM entry_otps WHERE registration_id = ?');
$del->bind_param('i', $registrationId);
$del->execute();

$updateStmt = db()->prepare(
    "UPDATE registrations
     SET attendance_status = 'present', attendance_marked_at = NOW()
     WHERE id = ?"
);
$updateStmt->bind_param('i', $registrationId);
$updateStmt->execute();

admin_json_response('success', 'Attendance marked present for ' . $registration['full_name'] . '.');
