<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$registrationId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($registrationId <= 0) {
    set_flash('error', 'Invalid registration reference.');
    redirect('index.php');
}

$registrationStmt = db()->prepare(
    'SELECT r.id, r.qr_token, r.registered_at,
            r.registration_type, r.team_name, r.team_size,
            r.payment_status, r.payment_reference,
            s.full_name, s.email, s.phone,
            e.id AS event_id, e.title, e.event_date, e.venue,
            e.payment_required, e.payment_amount,
            e.flyer_path
     FROM registrations r
     INNER JOIN students s ON s.id = r.student_id
     INNER JOIN events e ON e.id = r.event_id
     WHERE r.id = ?'
);
$registrationStmt->bind_param('i', $registrationId);
$registrationStmt->execute();
$registration = $registrationStmt->get_result()->fetch_assoc();

if (!$registration) {
    set_flash('error', 'Registration not found.');
    redirect('index.php');
}

$customValues = [];
try {
    $customValuesStmt = db()->prepare(
        'SELECT f.field_label, f.field_type, rv.field_value
         FROM registration_field_values rv
         INNER JOIN event_form_fields f ON f.id = rv.event_field_id
         WHERE rv.registration_id = ?
         ORDER BY f.sort_order ASC, f.id ASC'
    );
    $customValuesStmt->bind_param('i', $registrationId);
    $customValuesStmt->execute();
    $customValues = $customValuesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $ignored) {
    $customValues = [];
}

$flashes = get_flashes();
$qrPayload = 'ATTENDANCEQR|' . $registration['qr_token'];
$mailCfg = mail_config();
$emailVerified = false;
if ($mailCfg['enabled']) {
    try {
        $emailVerified = is_registration_email_verified((int) $registration['id']);
    } catch (Throwable $ignored) {
        $emailVerified = false;
    }
}

// If OTP/email verification is required, force users to complete it before seeing the QR.
if (defined('OTP_FLOW_ENABLED') && OTP_FLOW_ENABLED && !$emailVerified) {
    set_flash('error', 'OTP verification pending. Please enter the code sent to your email to view your QR.');
    redirect('verify_otp.php?id=' . $registrationId);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QR Pass | Attendance QR</title>
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/christ-theme.css')) ?>">
</head>
<body>
<header class="topbar">
    <div class="container">
        <a class="brand" href="<?= e(app_url()) ?>">Attendance QR</a>
        <nav class="nav-links">
            <a href="<?= e(app_url()) ?>">All Events</a>
            <a href="<?= e(app_url('event.php?id=' . (int) $registration['event_id'])) ?>">Back to Event</a>
        </nav>
    </div>
</header>

<main class="container">
    <section class="hero">
        <h1>Registration QR Pass</h1>
        <p>Show this QR code to admin during event check-in.</p>
    </section>

    <?php foreach ($flashes as $flash): ?>
        <div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>

    <?php if ($mailCfg['enabled'] && !$emailVerified): ?>
        <div class="alert info">
            Email verification pending. Check your inbox for the verification link.
            If you entered a wrong email, you will not receive it—please register again with the correct email.
        </div>
    <?php elseif ($mailCfg['enabled'] && $emailVerified): ?>
        <div class="alert success">Email verified. Receipt email should be delivered to <?= e((string) $registration['email']) ?>.</div>
    <?php endif; ?>

    <section class="grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); margin-bottom: 1rem;">
        <article class="card">
            <?php if ((string) $registration['flyer_path'] !== ''): ?>
                <img
                    src="<?= e(app_url((string) $registration['flyer_path'])) ?>"
                    alt="Event Flyer"
                    style="width: 100%; max-height: 220px; object-fit: cover; border-radius: 10px; border: 1px solid #cbd5e1; margin-bottom: 0.8rem;"
                >
            <?php endif; ?>

            <h2><?= e($registration['title']) ?></h2>
            <p class="meta">
                <?= e(date('d M Y, h:i A', strtotime((string) $registration['event_date']))) ?><br>
                Venue: <?= e($registration['venue']) ?>
            </p>
            <p><strong>Student:</strong> <?= e($registration['full_name']) ?></p>
            <!-- Roll number hidden as per new requirement -->
            <p><strong>Email:</strong> <?= e($registration['email']) ?></p>
            <p><strong>Phone:</strong> <?= e($registration['phone']) ?></p>
            <p><strong>Registration Type:</strong> <?= e(ucfirst((string) $registration['registration_type'])) ?></p>

            <?php if ((string) $registration['registration_type'] === 'team'): ?>
                <p><strong>Team Name:</strong> <?= e((string) $registration['team_name']) ?></p>
                <p><strong>Team Members:</strong> <?= (int) $registration['team_size'] ?></p>
            <?php endif; ?>

            <p>
                <strong>Payment Status:</strong>
                <?php if ($registration['payment_status'] === 'paid'): ?>
                    <span class="badge present">Paid</span>
                <?php elseif ($registration['payment_status'] === 'pending'): ?>
                    <span class="badge absent">Pending</span>
                <?php else: ?>
                    <span class="badge present">Not Required</span>
                <?php endif; ?>
            </p>

            <?php if ((string) $registration['payment_reference'] !== ''): ?>
                <p><strong>Payment Ref:</strong> <?= e((string) $registration['payment_reference']) ?></p>
            <?php endif; ?>

            <?php if (count($customValues) > 0): ?>
                <hr style="border: 0; border-top: 1px solid #e2e8f0;">
                <h3>Additional Details</h3>
                <?php foreach ($customValues as $customValue): ?>
                    <?php
                    $val = (string) ($customValue['field_value'] ?? '');
                    $type = (string) ($customValue['field_type'] ?? '');
                    $isFile = stripos($val, 'uploads/custom_fields/') === 0;
                    $isPhoto = $isFile && $type === 'photo';
                    ?>
                    <p>
                        <strong><?= e((string) $customValue['field_label']) ?>:</strong>
                        <?php if ($isPhoto): ?>
                            <br>
                            <img
                                src="<?= e(app_url($val)) ?>"
                                alt="<?= e((string) $customValue['field_label']) ?>"
                                style="max-width: 220px; width: 100%; height: auto; border-radius: 10px; border: 1px solid #cbd5e1; margin-top: 0.4rem;"
                            >
                        <?php elseif ($isFile): ?>
                            <a href="<?= e(app_url($val)) ?>" target="_blank" rel="noopener">Open</a>
                        <?php else: ?>
                            <?= e($val) ?>
                        <?php endif; ?>
                    </p>
                <?php endforeach; ?>
            <?php endif; ?>

            <p><strong>Registered:</strong> <?= e(date('d M Y, h:i A', strtotime((string) $registration['registered_at']))) ?></p>
        </article>

        <article class="card" style="text-align: center;">
            <h2>Your QR</h2>
            <div id="qrCodeTarget" style="display: inline-block; padding: 0.4rem; border: 1px solid #cbd5e1; border-radius: 10px;"></div>
            <p class="small">Token</p>
            <p class="mono" style="word-break: break-all;"><?= e($registration['qr_token']) ?></p>
            <div class="row" style="justify-content: center;">
                <button class="btn" onclick="window.print()" type="button">Print Pass</button>
            </div>
            <p id="qrError" class="small" style="display:none; color:#991b1b;">Could not render QR. Use token manually.</p>
        </article>
    </section>

    <div class="footer-gap"></div>
</main>

<script src="<?= e(app_url('assets/js/qrcode.min.js')) ?>"></script>
<script>
(function () {
    const payload = <?= json_encode($qrPayload, JSON_UNESCAPED_SLASHES) ?>;
    const target = document.getElementById('qrCodeTarget');
    const errorEl = document.getElementById('qrError');

    try {
        if (typeof QRCode === 'undefined') {
            throw new Error('QRCode library missing');
        }

        new QRCode(target, {
            text: payload,
            width: 300,
            height: 300,
            colorDark: '#111827',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
    } catch (error) {
        if (errorEl) {
            errorEl.style.display = 'block';
        }
    }
})();
</script>
</body>
</html>
