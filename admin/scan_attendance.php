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

if ($eventId <= 0) {
    admin_json_response('error', 'Please select a valid event.');
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
        admin_json_response('error', 'This QR belongs to another event: ' . $otherEvent['title'] . '.');
    }

    admin_json_response('error', 'No registration found for this QR token.');
}

if ($registration['attendance_status'] === 'present') {
    $alreadyAt = $registration['attendance_marked_at']
        ? date('d M Y, h:i A', strtotime((string) $registration['attendance_marked_at']))
        : 'already recorded';

    admin_json_response(
        'info',
        $registration['full_name'] . ' is already marked present at ' . $alreadyAt . '.'
    );
}

$registrationId = (int) $registration['id'];

// Require an email OTP at entry (default), so printed QR alone cannot be used.
if (defined('ENTRY_OTP_ENABLED') && ENTRY_OTP_ENABLED) {
    $mailCfg = mail_config();
    $email = strtolower(trim((string) ($registration['email'] ?? '')));

    if (!($mailCfg['enabled'] ?? false)) {
        admin_json_response('error', 'Entry OTP is enabled but email sending is disabled in settings. Enable email in Admin > Email Settings.');
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        admin_json_response('error', 'This registration has an invalid email. Cannot send OTP for entry.');
    }

    ensure_entry_otps_table();

    $otpStmt = db()->prepare('SELECT otp_code, expires_at, attempts, last_sent_at FROM entry_otps WHERE registration_id = ? LIMIT 1');
    $otpStmt->bind_param('i', $registrationId);
    $otpStmt->execute();
    $otpRow = $otpStmt->get_result()->fetch_assoc();

    $now = new DateTimeImmutable('now');
    $cooldownSeconds = defined('ENTRY_OTP_RESEND_COOLDOWN_SECONDS') ? (int) ENTRY_OTP_RESEND_COOLDOWN_SECONDS : 30;
    $ttlSeconds = defined('ENTRY_OTP_TTL_SECONDS') ? (int) ENTRY_OTP_TTL_SECONDS : 300;

    $shouldSend = true;
    $existingCode = '';
    $expiresAtSql = '';
    $expiresAtTs = 0;

    if ($otpRow) {
        $existingCode = (string) ($otpRow['otp_code'] ?? '');
        $expiresAtSql = (string) ($otpRow['expires_at'] ?? '');
        $expiresAtTs = strtotime($expiresAtSql);
        $lastSentAtSql = (string) ($otpRow['last_sent_at'] ?? '');
        $lastSentAtTs = $lastSentAtSql !== '' ? strtotime($lastSentAtSql) : 0;

        $stillValid = $expiresAtTs !== false && $expiresAtTs > time();
        $cooldownActive = $lastSentAtTs && (time() - $lastSentAtTs) < $cooldownSeconds;

        if ($stillValid && $cooldownActive) {
            $shouldSend = false;
        }
    }

    if ($shouldSend) {
        $otpCode = (string) random_int(100000, 999999);
        $expiresAt = $now->add(new DateInterval('PT' . max(60, $ttlSeconds) . 'S'));
        $expiresAtSql = $expiresAt->format('Y-m-d H:i:s');
        $expiresAtTs = $expiresAt->getTimestamp();

        if ($otpRow) {
            $upd = db()->prepare(
                'UPDATE entry_otps
                 SET otp_code = ?, expires_at = ?, attempts = 0, last_sent_at = CURRENT_TIMESTAMP
                 WHERE registration_id = ?'
            );
            $upd->bind_param('ssi', $otpCode, $expiresAtSql, $registrationId);
            $upd->execute();
        } else {
            $ins = db()->prepare(
                'INSERT INTO entry_otps (registration_id, otp_code, expires_at, last_sent_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)'
            );
            $ins->bind_param('iss', $registrationId, $otpCode, $expiresAtSql);
            $ins->execute();
        }

        $studentName = (string) ($registration['full_name'] ?? 'Student');
        $eventTitle = (string) ($registration['title'] ?? 'your event');

        $subject = 'Entry OTP for ' . $eventTitle;
        $mins = (int) ceil(max(60, $ttlSeconds) / 60);
        $textBody =
            "Hi {$studentName},\n\n" .
            "Your OTP for entry check-in is: {$otpCode}\n" .
            "Valid for {$mins} minutes.\n\n" .
            "If you did not request this, ignore.\n\n" .
            "Thanks,\nAttendance QR";

        $htmlBody = '<div style="font-family: Inter, Segoe UI, Arial, sans-serif; line-height: 1.6; color: #0f172a;">'
            . '<h2 style="margin:0 0 8px;">Entry OTP</h2>'
            . '<p style="margin:0 0 12px;">Event: <strong>' . e($eventTitle) . '</strong></p>'
            . '<p style="margin:0 0 10px;">Hello ' . e($studentName) . ',</p>'
            . '<p style="margin:0 0 12px;">Your OTP for entry check-in is:</p>'
            . '<div style="font-size: 28px; letter-spacing: 6px; font-weight: 800; padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 14px; display:inline-block; background:#f8fafc;">'
            . e($otpCode)
            . '</div>'
            . '<p style="margin:12px 0 0; color:#475569; font-size: 13px;">Valid for ' . e((string) $mins) . ' minutes.</p>'
            . '</div>';

        try {
            send_app_mail($email, $subject, $htmlBody, $textBody);
        } catch (Throwable $e) {
            admin_json_response('error', 'Could not send OTP email. Reason: ' . $e->getMessage());
        }
    }

    $masked = mask_email($email);
    $expiresIn = $expiresAtTs ? max(0, $expiresAtTs - time()) : $ttlSeconds;

    admin_json_response(
        'otp_required',
        'OTP sent to ' . $masked . '. Ask the student for the OTP to confirm entry.',
        200,
        [
            'registration_id' => $registrationId,
            'student_name' => (string) ($registration['full_name'] ?? ''),
            'email_masked' => $masked,
            'expires_in_seconds' => (int) $expiresIn,
        ]
    );
}

$updateStmt = db()->prepare(
    "UPDATE registrations
     SET attendance_status = 'present', attendance_marked_at = NOW()
     WHERE id = ?"
);
$updateStmt->bind_param('i', $registrationId);
$updateStmt->execute();

admin_json_response('success', 'Attendance marked present for ' . $registration['full_name'] . '.');
