<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$registrationId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
$token = strtolower(trim($token));

if ($registrationId <= 0 || $token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    set_flash('error', 'Invalid verification link. Please register again with a correct email.');
    redirect('index.php');
}

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
$row = $registrationStmt->get_result()->fetch_assoc();

if (!$row) {
    set_flash('error', 'Registration not found for verification.');
    redirect('index.php');
}

$email = strtolower(trim((string) ($row['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    set_flash('error', 'Email address is invalid. Please register again with a correct email.');
    redirect('registration_success.php?id=' . $registrationId);
}

try {
    $verified = mark_registration_email_verified($registrationId, $token);
} catch (Throwable $e) {
    set_flash('error', 'Could not mark email as verified. Reason: ' . $e->getMessage());
    redirect('index.php');
}

if (!$verified) {
    set_flash('error', 'Verification failed. The link may be invalid or already used.');
    redirect('index.php');
}

try {
    $mailCfg = mail_config();
    if ($mailCfg['enabled']) {
        $eventTitle = (string) ($row['event_title'] ?? 'your event');
        $fullName = (string) ($row['full_name'] ?? '');
        $qrToken = (string) ($row['qr_token'] ?? '');

        $qrPayload = $qrToken !== '' ? ('ATTENDANCEQR|' . $qrToken) : '';

        $subjectTpl = (string) ($mailCfg['receipt_subject'] ?? '');
        if ($subjectTpl === '') {
            $subjectTpl = 'Your receipt & QR for {event_title}';
        }

        $replacements = [
            '{name}' => $fullName !== '' ? $fullName : 'Student',
            '{event_title}' => $eventTitle !== '' ? $eventTitle : 'your event',
            '{qr_token}' => $qrToken !== '' ? $qrToken : 'N/A',
            '{app_name}' => 'Attendance QR',
        ];

        $subject = strtr($subjectTpl, $replacements);

        // Plain-text fallback (no links).
        $textBody =
            "Hi {$replacements['{name}']},\n\n" .
            "Here is your registration receipt for {$replacements['{event_title}']}.\n" .
            "QR Token: {$replacements['{qr_token}']}\n" .
            "Show the QR image in this email at check-in.\n\n" .
            "If images are blocked, give the token to the admin.\n\n" .
            "Thanks,\n{$replacements['{app_name}']}";

        // HTML body with embedded QR image and receipt details (no external links required).
        $htmlBody = '<div style="font-family: Inter, Segoe UI, Arial, sans-serif; line-height: 1.6; color: #0f172a;">'
            . '<h2 style="margin:0 0 8px;">' . e($replacements['{event_title}']) . '</h2>'
            . '<p style="margin:0 0 16px;">Here is your QR pass and receipt. Show this email at check-in.</p>';

        if ($qrPayload !== '') {
            // Always include QR image in the receipt email (ignore admin toggle for this message).
            $imgUrl = qr_image_url($qrPayload);
            $htmlBody .= '<div style="margin: 10px 0 14px;">'
                . '<div style="font-weight: 700; margin-bottom: 8px;">Your QR Code</div>'
                . '<img alt="QR Code" src="' . e($imgUrl) . '" width="260" height="260" style="border: 1px solid #cbd5e1; border-radius: 16px; display: block;">'
                . '<div style="font-size: 13px; color: #475569; margin-top: 8px;">Keep this email ready; no link needed.</div>'
                . '</div>';
        }

        if ($qrToken !== '') {
            $htmlBody .= '<div style="margin: 10px 0 14px;">'
                . '<div style="font-weight: 700; margin-bottom: 6px;">Your Token (backup)</div>'
                . '<div style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, \'Liberation Mono\', \'Courier New\', monospace;'
                . ' font-size: 13px; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 12px; background: #f8fafc; word-break: break-all;">'
                . e($qrToken)
                . '</div>'
                . '<div style="font-size: 13px; color: #475569; margin-top: 8px;">If the QR image is blocked, show this token.</div>'
                . '</div>';
        }

        // Minimal receipt details (no external links).
        $htmlBody .= '<div style="margin: 12px 0; padding: 12px; border: 1px solid #e2e8f0; border-radius: 12px; background: #f8fafc;">'
            . '<div style="font-weight:700; margin-bottom:6px;">Receipt Summary</div>'
            . '<p style="margin:4px 0;"><strong>Name:</strong> ' . e($fullName) . '</p>'
            . '<p style="margin:4px 0;"><strong>Email:</strong> ' . e($email) . '</p>'
            . '<p style="margin:4px 0;"><strong>Event:</strong> ' . e($eventTitle) . '</p>'
            . '<p style="margin:4px 0;"><strong>Registered:</strong> ' . e($registrationId) . '</p>'
            . '</div>';

        $htmlBody .= '</div>';

        send_app_mail($email, $subject, $htmlBody, $textBody);
        set_flash('success', 'Email verified. Receipt + QR sent to ' . $email . '.');
    }
} catch (Throwable $e) {
    set_flash('error', 'Email verified, but sending the receipt failed. Reason: ' . $e->getMessage());
}

redirect('registration_success.php?id=' . $registrationId);
