<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!OTP_FLOW_ENABLED) {
    redirect('index.php');
}

$registrationId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($registrationId <= 0) {
    set_flash('error', 'Missing registration reference.');
    redirect('index.php');
}

$flashes = get_flashes();
$otpError = '';

// Fetch registration, student, and event info.
$registrationStmt = db()->prepare(
    'SELECT r.id, r.qr_token,
            s.full_name, s.email,
            e.title AS event_title
     FROM registrations r
     INNER JOIN students s ON s.id = r.student_id
     INNER JOIN events e ON e.id = r.event_id
     WHERE r.id = ?
     LIMIT 1'
);
$registrationStmt->bind_param('i', $registrationId);
$registrationStmt->execute();
$reg = $registrationStmt->get_result()->fetch_assoc();

if (!$reg) {
    set_flash('error', 'Registration not found.');
    redirect('index.php');
}

$fullName = (string) ($reg['full_name'] ?? '');
$email = (string) ($reg['email'] ?? '');
$eventTitle = (string) ($reg['event_title'] ?? 'your event');
$qrToken = (string) ($reg['qr_token'] ?? '');
$qrPayload = $qrToken !== '' ? ('ATTENDANCEQR|' . $qrToken) : '';

// Handle POST (OTP submit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid form token.');
        redirect('verify_otp.php?id=' . $registrationId);
    }

    $otpInput = trim((string) ($_POST['otp'] ?? ''));
    if (preg_match('/^[0-9]{6}$/', $otpInput) !== 1) {
        set_flash('error', 'Enter a 6-digit OTP.');
        redirect('verify_otp.php?id=' . $registrationId);
    }

    ensure_registration_otps_table();

    $otpStmt = db()->prepare(
        'SELECT otp_code, expires_at, attempts FROM registration_otps WHERE registration_id = ? LIMIT 1'
    );
    $otpStmt->bind_param('i', $registrationId);
    $otpStmt->execute();
    $otpRow = $otpStmt->get_result()->fetch_assoc();

    if (!$otpRow) {
        set_flash('error', 'OTP not found or expired. Please request a new OTP by re-registering.');
        redirect('verify_otp.php?id=' . $registrationId);
    }

    $attempts = (int) ($otpRow['attempts'] ?? 0);
    if ($attempts >= 5) {
        set_flash('error', 'Too many incorrect attempts. Please re-register to get a new OTP.');
        redirect('verify_otp.php?id=' . $registrationId);
    }

    $expiresAt = strtotime((string) $otpRow['expires_at']);
    if ($expiresAt !== false && $expiresAt < time()) {
        $del = db()->prepare('DELETE FROM registration_otps WHERE registration_id = ?');
        $del->bind_param('i', $registrationId);
        $del->execute();
        set_flash('error', 'OTP expired. Please re-register to get a new OTP.');
        redirect('verify_otp.php?id=' . $registrationId);
    }

    if ($otpInput !== (string) $otpRow['otp_code']) {
        $inc = db()->prepare('UPDATE registration_otps SET attempts = attempts + 1 WHERE registration_id = ?');
        $inc->bind_param('i', $registrationId);
        $inc->execute();
        set_flash('error', 'Incorrect OTP. Please try again.');
        redirect('verify_otp.php?id=' . $registrationId);
    }

    // OTP correct: remove record
    $del = db()->prepare('DELETE FROM registration_otps WHERE registration_id = ?');
    $del->bind_param('i', $registrationId);
    $del->execute();

    // Mark email verified using existing verification table
    try {
        $verify = ensure_email_verification_for_registration($registrationId);
        $verifyToken = (string) ($verify['token'] ?? '');
        if ($verifyToken !== '') {
            mark_registration_email_verified($registrationId, $verifyToken);
        }
    } catch (Throwable $ignored) {
        // best effort; continue
    }

    // Send QR receipt email
    try {
        $mailCfg = mail_config();
        if ($mailCfg['enabled'] && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $subjectTpl = (string) ($mailCfg['receipt_subject'] ?? '');
            if ($subjectTpl === '') {
                $subjectTpl = 'Your receipt & QR for {event_title}';
            }

            $messageTpl = (string) ($mailCfg['receipt_message'] ?? '');
            if ($messageTpl === '') {
                $messageTpl = "Hi {name},\n\nYour registration receipt for {event_title}.\nQR Token: {qr_token}\nKeep this email for check-in.\n\nThanks,\n{app_name}";
            }

            $replacements = [
                '{name}' => $fullName !== '' ? $fullName : 'Student',
                '{event_title}' => $eventTitle !== '' ? $eventTitle : 'your event',
                '{qr_token}' => $qrToken !== '' ? $qrToken : 'N/A',
                '{app_name}' => 'Attendance QR',
            ];

            $subject = strtr($subjectTpl, $replacements);
            $textBody = strtr($messageTpl, $replacements);

            $htmlBody = '<div style="font-family: Inter, Segoe UI, Arial, sans-serif; line-height: 1.55; color: #0f172a;">'
                . '<h2 style="margin:0 0 10px;">' . e($replacements['{event_title}']) . '</h2>'
                . '<p style="margin: 0 0 12px;">Here is your QR pass and receipt. Show this email at check-in.</p>';

            if ($qrPayload !== '') {
                $imgUrl = qr_image_url($qrPayload);
                $htmlBody .= '<div style="margin: 10px 0 14px;">'
                    . '<div style="font-weight:700; margin-bottom: 6px;">Your QR Code</div>'
                    . '<img alt="QR Code" src="' . e($imgUrl) . '" width="260" height="260" style="border: 1px solid #cbd5e1; border-radius: 14px; display:block;">'
                    . '<div style="font-size: 12px; color: #475569; margin-top: 6px;">Keep this email handy at entry.</div>'
                    . '</div>';
            }

            if ($qrToken !== '') {
                $htmlBody .= '<div style="margin: 8px 0 14px;">'
                    . '<div style="font-weight:700; margin-bottom: 6px;">Your Token (backup)</div>'
                    . '<div style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, \'Liberation Mono\', \'Courier New\', monospace; font-size: 13px; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 12px; background: #f8fafc; word-break: break-all;">'
                    . e($qrToken)
                    . '</div>'
                    . '<div style="font-size: 12px; color: #475569; margin-top: 6px;">If the QR image is blocked, show this token.</div>'
                    . '</div>';
            }

            $htmlBody .= '<div style="margin: 12px 0; padding: 12px; border: 1px solid #e2e8f0; border-radius: 12px; background: #f8fafc;">'
                . '<div style="font-weight:700; margin-bottom:6px;">Receipt Summary</div>'
                . '<p style="margin:4px 0;"><strong>Name:</strong> ' . e($fullName) . '</p>'
                . '<p style="margin:4px 0;"><strong>Email:</strong> ' . e($email) . '</p>'
                . '<p style="margin:4px 0;"><strong>Event:</strong> ' . e($eventTitle) . '</p>'
                . '<p style="margin:4px 0;"><strong>Registration ID:</strong> ' . e((string) $registrationId) . '</p>'
                . '</div>'
                . '</div>';

            send_app_mail($email, $subject, $htmlBody, $textBody);
        }
    } catch (Throwable $ignored) {
        // ignore send failure here; user can show QR on success page
    }

    set_flash('success', 'OTP verified. QR receipt sent to ' . $email . '.');
    redirect('registration_success.php?id=' . $registrationId);
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Enter OTP | Attendance QR</title>
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/christ-theme.css')) ?>">
</head>
<body>
<header class="topbar">
    <div class="container">
        <a class="brand" href="<?= e(app_url()) ?>">Attendance QR</a>
        <nav class="nav-links">
            <a href="<?= e(app_url()) ?>">All Events</a>
        </nav>
    </div>
</header>

<main class="container">
    <section class="hero">
        <h1>Enter OTP</h1>
        <p>We sent a 6-digit OTP to <?= e($email) ?>. Enter it below to get your QR receipt.</p>
    </section>

    <?php foreach ($flashes as $flash): ?>
        <div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>

    <section class="form-wrap">
        <form method="post" action="<?= e(app_url('verify_otp.php?id=' . $registrationId)) ?>">
            <?= csrf_input() ?>
            <div class="field">
                <label for="otp">OTP</label>
                <input id="otp" name="otp" maxlength="6" pattern="[0-9]{6}" required placeholder="Enter 6-digit code" autocomplete="one-time-code">
            </div>
            <button class="btn" type="submit">Verify OTP</button>
        </form>
    </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const otpInput = document.getElementById('otp');
    if (otpInput) {
        otpInput.focus();
        otpInput.select();
    }
});
</script>
</body>
</html>
