<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Ensure custom field columns/enum exist for runtime safety.
function ensure_event_form_fields_columns(): void
{
    static $done = false;
    if ($done) return;
    try {
        db()->query(
            "ALTER TABLE event_form_fields
                ADD COLUMN IF NOT EXISTS min_length SMALLINT UNSIGNED NULL DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS max_length SMALLINT UNSIGNED NULL DEFAULT NULL"
        );
        db()->query(
            "ALTER TABLE event_form_fields
                MODIFY field_type ENUM('text','textarea','number','email','tel','select','date','roll','photo','video')
                NOT NULL DEFAULT 'text'"
        );
    } catch (Throwable $ignored) {
        // assume migration already applied
    }
    $done = true;
}

ensure_event_form_fields_columns();

function parse_option_lines(string $raw): array
{
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $options = [];

    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }

        $options[] = $line;
    }

    return $options;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    set_flash('error', 'Invalid form token. Please retry registration.');
    redirect('index.php');
}

$eventId = (int) ($_POST['event_id'] ?? 0);

function stash_old_registration_input(int $eventId): void
{
    $old = [
        'event_id' => (string) $eventId,
    ];

    $scalarKeys = [
        'full_name',
        'email',
        'phone',
        'registration_type',
        'team_name',
        'team_size',
        'payment_reference',
    ];

    foreach ($scalarKeys as $key) {
        if (!array_key_exists($key, $_POST)) {
            continue;
        }

        $value = $_POST[$key];
        if (is_scalar($value) || $value === null) {
            $old[$key] = trim((string) $value);
        }
    }

    foreach ($_POST as $key => $value) {
        if (!is_string($key) || strpos($key, 'custom_field_') !== 0) {
            continue;
        }

        if (is_scalar($value) || $value === null) {
            $old[$key] = trim((string) $value);
        }
    }

    set_old_input($old);
}

$fullName = trim((string) ($_POST['full_name'] ?? ''));
$rollNo = strtoupper(trim((string) ($_POST['roll_no'] ?? '')));
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$phone = trim((string) ($_POST['phone'] ?? ''));

function email_domain_seems_valid(string $email): bool
{
    $email = trim($email);
    $atPos = strrpos($email, '@');
    if ($atPos === false) {
        return false;
    }

    $domain = trim(substr($email, $atPos + 1));
    if ($domain === '' || strpos($domain, '.') === false) {
        return false;
    }

    // If DNS checks are unavailable, fall back to accepting.
    if (!function_exists('checkdnsrr')) {
        return true;
    }

    return checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA');
}

if ($eventId <= 0 || $fullName === '' || $email === '' || $phone === '') {
    set_flash('error', 'All fields are required for registration.');
    stash_old_registration_input($eventId);
    redirect('event.php?id=' . $eventId);
}

// Name: letters, spaces, dots, apostrophes, hyphens; 2-80 chars.
if (preg_match('/^[A-Za-z\\s\\.\'-]{2,80}$/u', $fullName) !== 1) {
    set_flash('error', 'Full Name: use letters/spaces only (2-80 chars).');
    stash_old_registration_input($eventId);
    redirect('event.php?id=' . $eventId . '#registrationForm');
}

// Roll number removed from flow.
$rollNo = '';

// Basic phone validation: digits only, exactly 10 digits.
$phoneDigits = preg_replace('/\\D+/', '', $phone);
if ($phoneDigits === '' || strlen($phoneDigits) !== 10) {
    set_flash('error', 'Please enter a valid 10-digit phone number.');
    stash_old_registration_input($eventId);
    redirect('event.php?id=' . $eventId . '#registrationForm');
}
// Store normalized digits in DB while keeping original formatting in case UI needs it.
$phone = $phoneDigits;

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    set_flash('error', 'Please provide a valid email address.');
    stash_old_registration_input($eventId);
    redirect('event.php?id=' . $eventId);
}

$atPos = strpos($email, '@');
$localPart = $atPos !== false ? substr($email, 0, $atPos) : '';
if ($localPart === '' || strlen($localPart) < 3) {
    set_flash('error', 'Email looks invalid. Please enter a valid email address (at least 3 characters before @).');
    stash_old_registration_input($eventId);
    redirect('event.php?id=' . $eventId . '#registrationForm');
}

    // Confirm Email removed; no double-entry check needed.

if (!email_domain_seems_valid($email)) {
    set_flash('error', 'Email looks invalid or undeliverable. Please enter a correct email.');
    stash_old_registration_input($eventId);
    redirect('event.php?id=' . $eventId . '#registrationForm');
}

$db = db();
$registrationId = 0;
$alreadyRegistered = false;

try {
    $db->begin_transaction();

    $eventStmt = $db->prepare(
        'SELECT id, registration_mode, team_min_members, team_max_members, payment_required
         FROM events
         WHERE id = ? AND registration_open = 1'
    );
    $eventStmt->bind_param('i', $eventId);
    $eventStmt->execute();
    $eventConfig = $eventStmt->get_result()->fetch_assoc();

    if (!$eventConfig) {
        throw new RuntimeException('Registration for this event is closed.');
    }

    $registrationTypeInput = (string) ($_POST['registration_type'] ?? 'solo');
    $registrationMode = (string) $eventConfig['registration_mode'];

    if ($registrationMode === 'solo') {
        $registrationType = 'solo';
    } elseif ($registrationMode === 'team') {
        $registrationType = 'team';
    } else {
        if (!in_array($registrationTypeInput, ['solo', 'team'], true)) {
            throw new RuntimeException('Invalid registration type selected.');
        }
        $registrationType = $registrationTypeInput;
    }

    $teamName = trim((string) ($_POST['team_name'] ?? ''));
    $teamSize = (int) ($_POST['team_size'] ?? 1);

    if ($registrationType === 'team') {
        if ($teamName === '') {
            throw new RuntimeException('Team name is required for team registration.');
        }

        $minMembers = (int) $eventConfig['team_min_members'];
        $maxMembers = (int) $eventConfig['team_max_members'];

        if ($teamSize < $minMembers || $teamSize > $maxMembers) {
            throw new RuntimeException(
                'Team size must be between ' . $minMembers . ' and ' . $maxMembers . ' members.'
            );
        }
    } else {
        $teamName = '';
        $teamSize = 1;
    }

    $paymentRequired = (int) $eventConfig['payment_required'] === 1;
    $paymentReference = trim((string) ($_POST['payment_reference'] ?? ''));

    if ($paymentRequired && $paymentReference === '') {
        throw new RuntimeException('Payment reference / transaction ID is required.');
    }

    if (!$paymentRequired) {
        $paymentReference = '';
    }

    $eventFields = [];
    try {
        $eventFieldsStmt = $db->prepare(
            'SELECT id, field_label, field_type, option_values, is_required, min_length, max_length
             FROM event_form_fields
             WHERE event_id = ?
             ORDER BY sort_order ASC, id ASC'
        );
        $eventFieldsStmt->bind_param('i', $eventId);
        $eventFieldsStmt->execute();
        $eventFields = $eventFieldsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $ignored) {
        $eventFields = [];
    }

    $customFieldValues = [];
    foreach ($eventFields as $field) {
        $fieldId = (int) $field['id'];
        $fieldLabel = (string) $field['field_label'];
        $fieldType = (string) $field['field_type'];
        $isRequired = (int) $field['is_required'] === 1;
        $minLen = isset($field['min_length']) ? (int) $field['min_length'] : null;
        $maxLen = isset($field['max_length']) ? (int) $field['max_length'] : null;

        $inputName = 'custom_field_' . $fieldId;
        $value = '';

        // Handle file uploads for photo/video
        if (in_array($fieldType, ['photo', 'video'], true)) {
            $file = $_FILES[$inputName] ?? null;
            $hasFile = $file && isset($file['error']) && (int) $file['error'] !== UPLOAD_ERR_NO_FILE;

            if ($isRequired && !$hasFile) {
                throw new RuntimeException($fieldLabel . ' is required.');
            }

            if ($hasFile) {
                if ((int) $file['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Upload failed for ' . $fieldLabel . '.');
                }
                $maxSize = $fieldType === 'photo' ? 5 * 1024 * 1024 : 30 * 1024 * 1024;
                if ((int) $file['size'] <= 0 || (int) $file['size'] > $maxSize) {
                    throw new RuntimeException($fieldLabel . ' file size is too large.');
                }
                $tmpName = (string) ($file['tmp_name'] ?? '');
                if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                    throw new RuntimeException('Invalid upload for ' . $fieldLabel . '.');
                }
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = (string) $finfo->file($tmpName);
                $allowed = $fieldType === 'photo'
                    ? ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp']
                    : ['video/mp4' => 'mp4', 'video/quicktime' => 'mov'];
                if (!isset($allowed[$mime])) {
                    throw new RuntimeException('Unsupported file type for ' . $fieldLabel . '.');
                }
                $ext = $allowed[$mime];
                $relativeDir = 'uploads/custom_fields/event_' . $eventId;
                $absoluteDir = __DIR__ . '/' . $relativeDir;
                if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0777, true) && !is_dir($absoluteDir)) {
                    throw new RuntimeException('Could not create upload folder for ' . $fieldLabel . '.');
                }
                $fileName = 'cf_' . $fieldId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $absolutePath = $absoluteDir . '/' . $fileName;
                if (!move_uploaded_file($tmpName, $absolutePath)) {
                    throw new RuntimeException('Could not save uploaded file for ' . $fieldLabel . '.');
                }
                $value = $relativeDir . '/' . $fileName;
            }
        } else {
            $value = trim((string) ($_POST[$inputName] ?? ''));

            if ($isRequired && $value === '') {
                throw new RuntimeException($fieldLabel . ' is required.');
            }

        if ($value !== '') {
            if ($fieldType === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid value for ' . $fieldLabel . '.');
            }

                if ($fieldType === 'number' && !is_numeric($value)) {
                    throw new RuntimeException('Invalid numeric value for ' . $fieldLabel . '.');
                }

                if ($fieldType === 'roll' && preg_match('/^[0-9]{1,12}$/', $value) !== 1) {
                    throw new RuntimeException('Invalid roll number for ' . $fieldLabel . '.');
                }

            if ($fieldType === 'date') {
                $date = DateTime::createFromFormat('Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value) {
                    throw new RuntimeException('Invalid date value for ' . $fieldLabel . '.');
                }
            }

            if ($fieldType === 'select') {
                $options = parse_option_lines((string) $field['option_values']);
                if (!in_array($value, $options, true)) {
                    throw new RuntimeException('Invalid option selected for ' . $fieldLabel . '.');
                }
            }

            if (strlen($value) > 2000) {
                throw new RuntimeException($fieldLabel . ' is too long.');
            }

            if ($minLen !== null && strlen($value) < $minLen) {
                throw new RuntimeException($fieldLabel . ' must be at least ' . $minLen . ' characters.');
            }
            if ($maxLen !== null && strlen($value) > $maxLen) {
                throw new RuntimeException($fieldLabel . ' must be at most ' . $maxLen . ' characters.');
            }
        }
        }

        $customFieldValues[$fieldId] = $value;
    }

    $studentLookupStmt = $db->prepare(
        'SELECT id, email FROM students WHERE email = ?'
    );
    $studentLookupStmt->bind_param('s', $email);
    $studentLookupStmt->execute();
    $studentMatches = $studentLookupStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (count($studentMatches) > 1) {
        throw new RuntimeException(
            'Registration conflict: this roll number and email belong to different student records.'
        );
    }

    if (count($studentMatches) === 1) {
        $studentId = (int) $studentMatches[0]['id'];

        $updateStudentStmt = $db->prepare(
            'UPDATE students SET full_name = ?, email = ?, phone = ? WHERE id = ?'
        );
        $updateStudentStmt->bind_param('sssi', $fullName, $email, $phone, $studentId);
        $updateStudentStmt->execute();
    } else {
        $insertStudentStmt = $db->prepare(
            'INSERT INTO students (full_name, email, phone) VALUES (?, ?, ?)'
        );
        $insertStudentStmt->bind_param('sss', $fullName, $email, $phone);
        $insertStudentStmt->execute();
        $studentId = (int) $insertStudentStmt->insert_id;
    }

    $registrationCheckStmt = $db->prepare(
        'SELECT id, payment_status FROM registrations WHERE event_id = ? AND student_id = ?'
    );
    $registrationCheckStmt->bind_param('ii', $eventId, $studentId);
    $registrationCheckStmt->execute();
    $existingRegistration = $registrationCheckStmt->get_result()->fetch_assoc();

    if ($existingRegistration) {
        $registrationId = (int) $existingRegistration['id'];
        $alreadyRegistered = true;

        if (!$paymentRequired) {
            $paymentStatus = 'not_required';
        } else {
            $paymentStatus = $existingRegistration['payment_status'] === 'paid' ? 'paid' : 'pending';
        }

        $updateRegistrationStmt = $db->prepare(
            'UPDATE registrations
             SET registration_type = ?, team_name = ?, team_size = ?, payment_status = ?, payment_reference = ?
             WHERE id = ?'
        );
        $updateRegistrationStmt->bind_param(
            'ssissi',
            $registrationType,
            $teamName,
            $teamSize,
            $paymentStatus,
            $paymentReference,
            $registrationId
        );
        $updateRegistrationStmt->execute();
    } else {
        $qrToken = bin2hex(random_bytes(16));
        $paymentStatus = $paymentRequired ? 'pending' : 'not_required';

        $insertRegistrationStmt = $db->prepare(
            'INSERT INTO registrations
                (event_id, student_id, qr_token, registration_type, team_name, team_size, payment_status, payment_reference)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertRegistrationStmt->bind_param(
            'iisssiss',
            $eventId,
            $studentId,
            $qrToken,
            $registrationType,
            $teamName,
            $teamSize,
            $paymentStatus,
            $paymentReference
        );
        $insertRegistrationStmt->execute();
        $registrationId = (int) $insertRegistrationStmt->insert_id;
    }

    if (count($eventFields) > 0) {
        $upsertFieldValueStmt = $db->prepare(
            'INSERT INTO registration_field_values (registration_id, event_field_id, field_value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE field_value = VALUES(field_value), updated_at = CURRENT_TIMESTAMP'
        );

        $deleteFieldValueStmt = $db->prepare(
            'DELETE FROM registration_field_values WHERE registration_id = ? AND event_field_id = ?'
        );

    foreach ($customFieldValues as $fieldId => $fieldValue) {
        if ($fieldValue === '') {
            $deleteFieldValueStmt->bind_param('ii', $registrationId, $fieldId);
            $deleteFieldValueStmt->execute();
            continue;
        }

        $upsertFieldValueStmt->bind_param('iis', $registrationId, $fieldId, $fieldValue);
        $upsertFieldValueStmt->execute();
    }
    }

} catch (Throwable $exception) {
    try {
        $db->rollback();
    } catch (Throwable $ignored) {
        // Ignore rollback failures and continue with error response.
    }

    if ($exception instanceof RuntimeException) {
        set_flash('error', 'Registration failed: ' . $exception->getMessage());
    } else {
        set_flash('error', 'Registration failed due to a server/database issue. Please try again.');
    }

    stash_old_registration_input($eventId);
    redirect('event.php?id=' . $eventId);
}

if ($alreadyRegistered) {
    set_flash('info', 'You are already registered for this event. Existing registration updated.');
} else {
    if (OTP_FLOW_ENABLED) {
        set_flash('info', 'Registration started. Enter the OTP sent to your email to get your QR.');
    } else {
        set_flash('success', 'Registration successful. Your QR pass is ready.');
    }
}

try {
    $mailCfg = mail_config();
    if ($mailCfg['enabled'] && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $eventTitle = '';
        try {
            $eventTitleStmt = db()->prepare('SELECT title FROM events WHERE id = ? LIMIT 1');
            $eventTitleStmt->bind_param('i', $eventId);
            $eventTitleStmt->execute();
            $eventTitle = (string) ($eventTitleStmt->get_result()->fetch_assoc()['title'] ?? '');
        } catch (Throwable $ignored) {
            $eventTitle = '';
        }

        // Fetch QR token
        $qrToken = '';
        try {
            $qrStmt = db()->prepare('SELECT qr_token FROM registrations WHERE id = ? LIMIT 1');
            $qrStmt->bind_param('i', $registrationId);
            $qrStmt->execute();
            $qrToken = (string) ($qrStmt->get_result()->fetch_assoc()['qr_token'] ?? '');
        } catch (Throwable $ignored) {
            $qrToken = '';
        }

        if (OTP_FLOW_ENABLED) {
            // Generate and store OTP
            ensure_registration_otps_table();
            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiresAt = (new DateTime('+10 minutes'))->format('Y-m-d H:i:s');

            // Upsert OTP for this registration
            $deleteOtp = db()->prepare('DELETE FROM registration_otps WHERE registration_id = ?');
            $deleteOtp->bind_param('i', $registrationId);
            $deleteOtp->execute();

            $insertOtp = db()->prepare(
                'INSERT INTO registration_otps (registration_id, otp_code, expires_at) VALUES (?, ?, ?)'
            );
            $insertOtp->bind_param('iss', $registrationId, $otp, $expiresAt);
            $insertOtp->execute();

            // Commit before sending mail
            $db->commit();

            $otpLink = app_full_url('verify_otp.php?id=' . $registrationId);

            $subject = 'Your OTP for ' . ($eventTitle !== '' ? $eventTitle : 'registration');
            $textBody = "Hi {$fullName},\n\nEnter this OTP to complete your registration: {$otp}\nLink: {$otpLink}\nThis code expires in 10 minutes.\n\nThanks,\nAttendance QR";

            $htmlBody = '<div style="font-family: Inter, Segoe UI, Arial, sans-serif; line-height: 1.55; color: #0f172a;">'
                . '<p style="margin: 0 0 12px;">Hi ' . e($fullName) . ',</p>'
                . '<p style="margin: 0 0 10px;">Enter this OTP to complete your registration for <strong>' . e($eventTitle !== '' ? $eventTitle : 'your event') . '</strong>:</p>'
                . '<p style="font-size: 24px; font-weight: 700; letter-spacing: 4px; margin: 0 0 12px;">' . e($otp) . '</p>'
                . '<p style="margin: 0 0 10px; font-size: 13px; color: #475569;">This code expires in 10 minutes.</p>'
                . '<p style="margin: 0 0 8px; font-size: 13px;">Enter it here: <a href="' . e($otpLink) . '">' . e($otpLink) . '</a></p>'
                . '</div>';

            send_app_mail($email, $subject, $htmlBody, $textBody);
            set_flash('info', 'OTP sent to ' . $email . '. Enter it to get your QR.');
            stash_old_registration_input($eventId);
            redirect('verify_otp.php?id=' . $registrationId);
        } else {
            // Direct send receipt with QR (fallback if OTP_FLOW_ENABLED is false)
            $qrPayload = $qrToken !== '' ? ('ATTENDANCEQR|' . $qrToken) : '';

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
            touch_verification_email_sent($registrationId);
            $db->commit();
            set_flash('success', 'Receipt sent to ' . $email . ' with QR and token.');
            stash_old_registration_input($eventId);
            redirect('registration_success.php?id=' . $registrationId);
        }
    }
} catch (Throwable $e) {
    // If SMTP says recipient invalid, rollback registration and show a clear prompt.
    try {
        $db->rollback();
    } catch (Throwable $ignored) {
        // ignore rollback failure
    }

    if (preg_match('/(550|recipient|invalid|user unknown|mailbox unavailable)/i', (string) $e->getMessage())) {
        set_flash('error', 'Invalid email address. Please enter a correct, deliverable email.');
    } else {
        set_flash('error', 'Could not send receipt email. Reason: ' . $e->getMessage());
    }
    stash_old_registration_input($eventId);
    redirect('event.php?id=' . $eventId . '#registrationForm');
}

// If email sending is disabled or email not valid, commit DB changes and show success page.
try {
    $db->commit();
} catch (Throwable $ignored) {
}
redirect('registration_success.php?id=' . $registrationId);
