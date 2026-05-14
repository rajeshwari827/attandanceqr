<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_admin_login();

$flashes = get_flashes();
$cfg = mail_config();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid form token.');
        redirect('admin/email.php');
    }

    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'save') {
        $enabled = isset($_POST['mail_enabled']) ? '1' : '0';
        $service = (string) ($_POST['mail_service'] ?? 'gmail');
        if (!in_array($service, ['gmail', 'custom'], true)) {
            $service = 'gmail';
        }
        $includeQrImage = isset($_POST['mail_include_qr_image']) ? '1' : '0';
        $qrProvider = (string) ($_POST['mail_qr_provider'] ?? 'qrserver');
        if (!in_array($qrProvider, ['qrserver'], true)) {
            $qrProvider = 'qrserver';
        }

        $host = trim((string) ($_POST['mail_host'] ?? ''));
        $port = (int) ($_POST['mail_port'] ?? 587);
        if ($port <= 0 || $port > 65535) {
            $port = 587;
        }

        $secure = (string) ($_POST['mail_secure'] ?? 'tls');
        if (!in_array($secure, ['tls', 'ssl', 'none'], true)) {
            $secure = 'tls';
        }

        $user = trim((string) ($_POST['mail_user'] ?? ''));

        $passInput = (string) ($_POST['mail_pass'] ?? '');
        $pass = $cfg['pass'];
        if ($passInput !== '' && $passInput !== '********') {
            $pass = trim($passInput);
        }

        $fromEmail = trim((string) ($_POST['mail_from_email'] ?? ''));
        $fromName = trim((string) ($_POST['mail_from_name'] ?? 'Attendance QR'));
        $verifySubject = trim((string) ($_POST['mail_verify_subject'] ?? ''));
        $verifyMessage = (string) ($_POST['mail_verify_message'] ?? '');
        $receiptSubject = trim((string) ($_POST['mail_receipt_subject'] ?? ''));
        $receiptMessage = (string) ($_POST['mail_receipt_message'] ?? '');

        set_setting('mail_enabled', $enabled);
        set_setting('mail_service', $service);
        set_setting('mail_include_qr_image', $includeQrImage);
        set_setting('mail_qr_provider', $qrProvider);
        set_setting('mail_host', $host);
        set_setting('mail_port', (string) $port);
        set_setting('mail_secure', $secure);
        set_setting('mail_user', $user);
        set_setting('mail_pass', $pass);
        set_setting('mail_from_email', $fromEmail);
        set_setting('mail_from_name', $fromName);
        set_setting('mail_verify_subject', $verifySubject);
        set_setting('mail_verify_message', $verifyMessage);
        set_setting('mail_receipt_subject', $receiptSubject);
        set_setting('mail_receipt_message', $receiptMessage);

        set_flash('success', 'Email settings saved.');
        redirect('admin/email.php');
    }

    if ($action === 'test') {
        $testTo = strtolower(trim((string) ($_POST['test_to'] ?? '')));
        if ($testTo === '' || !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
            set_flash('error', 'Enter a valid test email address.');
            redirect('admin/email.php');
        }

        try {
            $subject = 'Test Email - Attendance QR';
            $html = '<p>This is a test email from <strong>Attendance QR</strong>.</p>';
            $text = 'This is a test email from Attendance QR.';

            $mailCfg = mail_config();
            if ($mailCfg['include_qr_image']) {
                $payload = 'ATTENDANCEQR|TEST';
                $imgUrl = qr_image_url($payload);
                $html .= '<p style="margin: 16px 0 8px;">Sample QR:</p>'
                    . '<img alt="QR Code" src="' . e($imgUrl) . '" width="200" height="200" style="border: 1px solid #cbd5e1; border-radius: 14px;">';
                $text .= "\n\nSample QR payload: " . $payload;
            }

            send_app_mail($testTo, $subject, $html, $text);
            set_flash('success', 'Test email sent to ' . $testTo . '.');
        } catch (Throwable $e) {
            set_flash('error', 'Test email failed: ' . $e->getMessage());
        }

        redirect('admin/email.php');
    }
}

$cfg = mail_config();
$maskedPass = $cfg['pass'] !== '' ? '********' : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Email Settings | Attendance QR</title>
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/christ-theme.css')) ?>">
</head>
<body>
<header class="topbar">
    <div class="container">
        <a class="brand" href="<?= e(app_url('admin/dashboard.php')) ?>">Admin Panel</a>
        <nav class="nav-links">
            <a href="<?= e(app_url('admin/dashboard.php')) ?>">Dashboard</a>
            <a href="<?= e(app_url('admin/events.php')) ?>">Manage Events</a>
            <a href="<?= e(app_url('admin/scan.php')) ?>">Scan QR</a>
            <a href="<?= e(app_url('admin/email.php')) ?>">Email Settings</a>
            <a href="<?= e(app_url('admin/logout.php')) ?>">Logout</a>
        </nav>
    </div>
</header>

<main class="container">
    <section class="hero">
        <h1>Email Settings</h1>
        <p>Configure SMTP to send registration confirmations and QR pass links.</p>
    </section>

    <?php foreach ($flashes as $flash): ?>
        <div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>

    <section class="form-wrap">
        <form method="post" action="<?= e(app_url('admin/email.php')) ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="save">

            <div class="field check-field">
                <label class="check-control">
                    <input name="mail_enabled" type="checkbox" <?= $cfg['enabled'] ? 'checked' : '' ?>>
                    <span>Enable Email Sending</span>
                </label>
            </div>

            <div class="field check-field">
                <label class="check-control">
                    <input name="mail_include_qr_image" type="checkbox" <?= $cfg['include_qr_image'] ? 'checked' : '' ?>>
                    <span>Include QR Image in Email</span>
                </label>
                <p class="hint">Note: many email clients block images by default. Also, QR images are generated by an external QR service.</p>
            </div>

            <div class="field">
                <label for="mail_qr_provider">QR Image Provider</label>
                <select id="mail_qr_provider" name="mail_qr_provider">
                    <option value="qrserver" <?= $cfg['qr_provider'] === 'qrserver' ? 'selected' : '' ?>>qrserver.com</option>
                </select>
            </div>

            <div class="field">
                <label for="mail_service">Email Service</label>
                <select id="mail_service" name="mail_service">
                    <option value="gmail" <?= $cfg['service'] === 'gmail' ? 'selected' : '' ?>>Gmail (SMTP)</option>
                    <option value="custom" <?= $cfg['service'] === 'custom' ? 'selected' : '' ?>>Custom SMTP</option>
                </select>
                <p class="hint">For Gmail, use an App Password (16 characters) instead of your normal password.</p>
            </div>

            <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0.9rem;">
                <div class="field">
                    <label for="mail_host">SMTP Host</label>
                    <input id="mail_host" name="mail_host" value="<?= e((string) $cfg['host']) ?>" placeholder="smtp.example.com">
                </div>

                <div class="field">
                    <label for="mail_port">SMTP Port</label>
                    <input id="mail_port" name="mail_port" type="number" min="1" max="65535" value="<?= (int) $cfg['port'] ?>">
                </div>

                <div class="field">
                    <label for="mail_secure">Security</label>
                    <select id="mail_secure" name="mail_secure">
                        <option value="tls" <?= $cfg['secure'] === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS)</option>
                        <option value="ssl" <?= $cfg['secure'] === 'ssl' ? 'selected' : '' ?>>SSL</option>
                        <option value="none" <?= $cfg['secure'] === 'none' ? 'selected' : '' ?>>None</option>
                    </select>
                </div>
            </div>

            <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0.9rem;">
                <div class="field">
                    <label for="mail_user">SMTP Username</label>
                    <input id="mail_user" name="mail_user" type="email" value="<?= e((string) $cfg['user']) ?>" placeholder="your-email@gmail.com">
                </div>

                <div class="field">
                    <label for="mail_pass">SMTP Password / App Password</label>
                    <input id="mail_pass" name="mail_pass" type="password" value="<?= e($maskedPass) ?>" placeholder="<?= $maskedPass === '' ? 'Enter password' : '********' ?>">
                </div>
            </div>

            <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0.9rem;">
                <div class="field">
                    <label for="mail_from_email">From Email (optional)</label>
                    <input id="mail_from_email" name="mail_from_email" type="email" value="<?= e((string) $cfg['from_email']) ?>" placeholder="defaults to username">
                </div>

                <div class="field">
                    <label for="mail_from_name">From Name</label>
                    <input id="mail_from_name" name="mail_from_name" value="<?= e((string) $cfg['from_name']) ?>" placeholder="Attendance QR">
                </div>
            </div>

            <div class="form-wrap" style="margin-bottom: 1rem;">
                <h3 style="margin-top: 0;">Verification Email</h3>
                <div class="field">
                    <label for="mail_verify_subject">Subject</label>
                    <input id="mail_verify_subject" name="mail_verify_subject" value="<?= e((string) $cfg['verify_subject']) ?>">
                </div>
                <div class="field">
                    <label for="mail_verify_message">Message Template</label>
                    <textarea id="mail_verify_message" name="mail_verify_message"><?= e((string) $cfg['verify_message']) ?></textarea>
                    <p class="hint">Variables: {name}, {event_title}, {verify_link}, {app_name}</p>
                </div>
            </div>

            <div class="form-wrap" style="margin-bottom: 1rem;">
                <h3 style="margin-top: 0;">Receipt Email</h3>
                <div class="field">
                    <label for="mail_receipt_subject">Subject</label>
                    <input id="mail_receipt_subject" name="mail_receipt_subject" value="<?= e((string) $cfg['receipt_subject']) ?>">
                </div>
                <div class="field">
                    <label for="mail_receipt_message">Message Template</label>
                    <textarea id="mail_receipt_message" name="mail_receipt_message"><?= e((string) $cfg['receipt_message']) ?></textarea>
                    <p class="hint">Variables: {name}, {event_title}, {qr_link}, {qr_token}, {qr_payload}, {app_name}</p>
                </div>
            </div>

            <div class="form-actions">
                <a class="btn outline" href="<?= e(app_url('admin/dashboard.php')) ?>">Back</a>
                <button class="btn" type="submit">Save Settings</button>
            </div>
        </form>
    </section>

    <section class="form-wrap">
        <h2 class="section-title">Send Test Email</h2>
        <form method="post" action="<?= e(app_url('admin/email.php')) ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="test">

            <div class="field">
                <label for="test_to">Recipient Email</label>
                <input id="test_to" name="test_to" type="email" required placeholder="test@example.com">
            </div>

            <button class="btn" type="submit">Send Test</button>
        </form>
    </section>

    <div class="footer-gap"></div>
</main>
</body>
</html>
