<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_admin_login();

// Ensure new custom-field columns/enum exist (idempotent).
function ensure_event_form_fields_columns(): void
{
    static $done = false;
    if ($done) {
        return;
    }
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
        // Ignore migration errors; assume migration already applied.
    }
    $done = true;
}

ensure_event_form_fields_columns();

function post_redirect_target(string $default = 'admin/events.php'): string
{
    $target = trim((string) ($_POST['return_to'] ?? ''));
    if ($target === '') {
        return $default;
    }

    // Allow only known admin routes to avoid open redirects.
    $allowed = [
        'admin/events.php' => true,
        'admin/dashboard.php' => true,
    ];

    return isset($allowed[$target]) ? $target : $default;
}

function normalize_custom_fields(?string $jsonPayload): array
{
    if ($jsonPayload === null || trim($jsonPayload) === '') {
        return [];
    }

    $decoded = json_decode($jsonPayload, true);
    if (!is_array($decoded)) {
        return [];
    }

    // Allowed custom field types (maps to UI labels)
    $allowedTypes = ['text', 'textarea', 'roll', 'tel', 'number', 'email', 'date', 'select', 'photo', 'video'];
    $normalized = [];

    foreach ($decoded as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        if (count($normalized) >= 20) {
            break;
        }

        $label = trim((string) ($entry['label'] ?? ''));
        if ($label === '') {
            continue;
        }

        if (strlen($label) > 120) {
            $label = substr($label, 0, 120);
        }

        $type = (string) ($entry['type'] ?? 'text');
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'text';
        }

        $placeholder = trim((string) ($entry['placeholder'] ?? ''));
        if (strlen($placeholder) > 150) {
            $placeholder = substr($placeholder, 0, 150);
        }

        $required = !empty($entry['required']) ? 1 : 0;
        $optionValues = '';
        $minLen = isset($entry['min_length']) ? (int) $entry['min_length'] : null;
        $maxLen = isset($entry['max_length']) ? (int) $entry['max_length'] : null;

        if ($minLen !== null && $minLen < 0) {
            $minLen = 0;
        }
        if ($maxLen !== null && $maxLen < 0) {
            $maxLen = null;
        }
        if ($minLen !== null && $maxLen !== null && $minLen > $maxLen) {
            $minLen = $maxLen;
        }

        if ($type === 'select') {
            $rawOptions = preg_split('/\r\n|\r|\n/', (string) ($entry['options'] ?? '')) ?: [];
            $cleanOptions = [];

            foreach ($rawOptions as $option) {
                $option = trim((string) $option);
                if ($option === '') {
                    continue;
                }

                if (strlen($option) > 80) {
                    $option = substr($option, 0, 80);
                }

                if (!in_array($option, $cleanOptions, true)) {
                    $cleanOptions[] = $option;
                }

                if (count($cleanOptions) >= 40) {
                    break;
                }
            }

            if (count($cleanOptions) === 0) {
                continue;
            }

            $optionValues = implode("\n", $cleanOptions);
        }

        $normalized[] = [
            'field_label' => $label,
            'field_type' => $type,
            'placeholder' => $placeholder,
            'option_values' => $optionValues,
            'is_required' => $required,
            'sort_order' => count($normalized) + 1,
            'min_length' => $minLen,
            'max_length' => $maxLen,
        ];
    }

    return $normalized;
}

function save_uploaded_file(?array $file, array $allowedMimeExt, int $maxSizeBytes, string $subFolder): ?string
{
    if ($file === null || !isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed for ' . $subFolder . '.');
    }

    if ((int) $file['size'] <= 0 || (int) $file['size'] > $maxSizeBytes) {
        throw new RuntimeException('Invalid file size for ' . $subFolder . '.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Invalid uploaded file for ' . $subFolder . '.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpName);

    if (!isset($allowedMimeExt[$mime])) {
        throw new RuntimeException('Unsupported file type for ' . $subFolder . '.');
    }

    $ext = $allowedMimeExt[$mime];
    $relativeDir = 'uploads/events/' . trim($subFolder, '/');
    $absoluteDir = dirname(__DIR__) . '/' . $relativeDir;

    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0777, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('Could not create upload folder.');
    }

    $fileName = 'evt_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    $absolutePath = $absoluteDir . '/' . $fileName;

    if (!move_uploaded_file($tmpName, $absolutePath)) {
        throw new RuntimeException('Could not save uploaded file.');
    }

    return $relativeDir . '/' . $fileName;
}

function resolve_uploaded_absolute_path(string $relativePath): ?string
{
    $relativePath = trim(str_replace('\\', '/', $relativePath));
    if ($relativePath === '') {
        return null;
    }

    $relativePath = ltrim($relativePath, '/');
    if (stripos($relativePath, 'uploads/events/') !== 0) {
        return null;
    }

    $absolute = dirname(__DIR__) . '/' . $relativePath;
    $real = realpath($absolute);
    if ($real === false) {
        return null;
    }

    $uploadsRoot = realpath(dirname(__DIR__) . '/uploads/events');
    if ($uploadsRoot === false) {
        return null;
    }

    $uploadsRoot = rtrim(str_replace('\\', '/', $uploadsRoot), '/');
    $realNorm = str_replace('\\', '/', (string) $real);
    if (stripos($realNorm, $uploadsRoot . '/') !== 0 && $realNorm !== $uploadsRoot) {
        return null;
    }

    return $realNorm;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid form token.');
        redirect('admin/events.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_event') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $rules = trim((string) ($_POST['rules'] ?? ''));
        $venue = trim((string) ($_POST['venue'] ?? ''));
        $eventDateInput = trim((string) ($_POST['event_date'] ?? ''));
        $registrationMode = (string) ($_POST['registration_mode'] ?? 'solo');
        $teamMinMembers = (int) ($_POST['team_min_members'] ?? 2);
        $teamMaxMembers = (int) ($_POST['team_max_members'] ?? 5);
        $paymentRequired = isset($_POST['payment_required']) ? 1 : 0;
        $paymentAmount = (float) ($_POST['payment_amount'] ?? 0);
        $paymentNote = trim((string) ($_POST['payment_note'] ?? ''));
        $registrationOpen = isset($_POST['registration_open']) ? 1 : 0;
        $customFields = normalize_custom_fields((string) ($_POST['custom_fields_json'] ?? '[]'));

        if ($title === '' || $description === '' || $rules === '' || $venue === '' || $eventDateInput === '') {
            set_flash('error', 'All event fields are required.');
            redirect('admin/events.php');
        }

        if (!in_array($registrationMode, ['solo', 'team', 'both'], true)) {
            set_flash('error', 'Invalid registration mode selected.');
            redirect('admin/events.php');
        }

        if ($registrationMode === 'solo') {
            $teamMinMembers = 1;
            $teamMaxMembers = 1;
        } else {
            if ($teamMinMembers < 2 || $teamMaxMembers < $teamMinMembers || $teamMaxMembers > 50) {
                set_flash('error', 'For team registration, set valid team member range (min >= 2 and max <= 50).');
                redirect('admin/events.php');
            }
        }

        if ($paymentRequired === 1) {
            if ($paymentAmount <= 0) {
                set_flash('error', 'Payment amount must be greater than zero when payment is required.');
                redirect('admin/events.php');
            }
        } else {
            $paymentAmount = 0;
            $paymentNote = '';
        }

        if (strlen($paymentNote) > 2000) {
            $paymentNote = substr($paymentNote, 0, 2000);
        }

        $eventDate = DateTime::createFromFormat('Y-m-d\TH:i', $eventDateInput);
        if (!$eventDate) {
            set_flash('error', 'Invalid event date format.');
            redirect('admin/events.php');
        }

        $eventDateSql = $eventDate->format('Y-m-d H:i:s');
        $db = db();
        $savedFiles = [];

        try {
            $flyerPath = save_uploaded_file(
                $_FILES['flyer_image'] ?? null,
                [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    'image/gif' => 'gif',
                ],
                5 * 1024 * 1024,
                'images'
            );
            if ($flyerPath !== null) {
                $savedFiles[] = $flyerPath;
            }

            $rulesPdfPath = save_uploaded_file(
                $_FILES['rules_pdf'] ?? null,
                ['application/pdf' => 'pdf'],
                10 * 1024 * 1024,
                'docs'
            );
            if ($rulesPdfPath !== null) {
                $savedFiles[] = $rulesPdfPath;
            }

            $schedulePdfPath = save_uploaded_file(
                $_FILES['schedule_pdf'] ?? null,
                ['application/pdf' => 'pdf'],
                10 * 1024 * 1024,
                'docs'
            );
            if ($schedulePdfPath !== null) {
                $savedFiles[] = $schedulePdfPath;
            }

            $flyerValue = $flyerPath ?? '';
            $rulesPdfValue = $rulesPdfPath ?? '';
            $schedulePdfValue = $schedulePdfPath ?? '';

            $db->begin_transaction();

            $insertStmt = $db->prepare(
                'INSERT INTO events
                    (title, description, rules, event_date, venue, registration_mode,
                     team_min_members, team_max_members, payment_required, payment_amount, payment_note,
                     flyer_path, rules_pdf_path, schedule_pdf_path, registration_open)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insertStmt->bind_param(
                'ssssssiiidssssi',
                $title,
                $description,
                $rules,
                $eventDateSql,
                $venue,
                $registrationMode,
                $teamMinMembers,
                $teamMaxMembers,
                $paymentRequired,
                $paymentAmount,
                $paymentNote,
                $flyerValue,
                $rulesPdfValue,
                $schedulePdfValue,
                $registrationOpen
            );
            $insertStmt->execute();

            $eventId = (int) $insertStmt->insert_id;

            if (count($customFields) > 0) {
                $fieldInsertStmt = $db->prepare(
                    'INSERT INTO event_form_fields
                        (event_id, field_label, field_type, placeholder, option_values, is_required, sort_order, min_length, max_length)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );

                foreach ($customFields as $field) {
                    $fieldLabel = $field['field_label'];
                    $fieldType = $field['field_type'];
                    $placeholder = $field['placeholder'];
                    $optionValues = $field['option_values'];
                    $isRequired = (int) $field['is_required'];
                    $sortOrder = (int) $field['sort_order'];
                    $minLen = $field['min_length'] === null ? null : (int) $field['min_length'];
                    $maxLen = $field['max_length'] === null ? null : (int) $field['max_length'];

                    $fieldInsertStmt->bind_param(
                        'isssssiii',
                        $eventId,
                        $fieldLabel,
                        $fieldType,
                        $placeholder,
                        $optionValues,
                        $isRequired,
                        $sortOrder,
                        $minLen,
                        $maxLen
                    );
                    $fieldInsertStmt->execute();
                }
            }

            $db->commit();

            $fieldCount = count($customFields);
            if ($fieldCount > 0) {
                set_flash('success', 'Event created with media uploads and ' . $fieldCount . ' custom field(s).');
            } else {
                set_flash('success', 'Event created successfully with your selected settings.');
            }
        } catch (Throwable $exception) {
            try {
                $db->rollback();
            } catch (Throwable $ignored) {
                // Ignore rollback issues.
            }

            foreach ($savedFiles as $relativePath) {
                $absolutePath = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
                if (is_file($absolutePath)) {
                    @unlink($absolutePath);
                }
            }

            if ($exception instanceof RuntimeException) {
                set_flash('error', 'Event creation failed: ' . $exception->getMessage());
            } else {
                set_flash('error', 'Event creation failed. Please run DB migration and try again.');
            }
        }

        redirect(post_redirect_target('admin/events.php'));
    }

    if ($action === 'update_event') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        if ($eventId <= 0) {
            set_flash('error', 'Invalid event selected.');
            redirect(post_redirect_target('admin/events.php'));
        }

        $eventStmt = db()->prepare(
            'SELECT id, flyer_path, rules_pdf_path, schedule_pdf_path FROM events WHERE id = ?'
        );
        $eventStmt->bind_param('i', $eventId);
        $eventStmt->execute();
        $existingEvent = $eventStmt->get_result()->fetch_assoc();

        if (!$existingEvent) {
            set_flash('error', 'Event not found.');
            redirect(post_redirect_target('admin/events.php'));
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $rules = trim((string) ($_POST['rules'] ?? ''));
        $venue = trim((string) ($_POST['venue'] ?? ''));
        $eventDateInput = trim((string) ($_POST['event_date'] ?? ''));
        $registrationMode = (string) ($_POST['registration_mode'] ?? 'solo');
        $teamMinMembers = (int) ($_POST['team_min_members'] ?? 2);
        $teamMaxMembers = (int) ($_POST['team_max_members'] ?? 5);
        $paymentRequired = isset($_POST['payment_required']) ? 1 : 0;
        $paymentAmount = (float) ($_POST['payment_amount'] ?? 0);
        $paymentNote = trim((string) ($_POST['payment_note'] ?? ''));
        $registrationOpen = isset($_POST['registration_open']) ? 1 : 0;
        $customFields = normalize_custom_fields((string) ($_POST['custom_fields_json'] ?? '[]'));

        if ($title === '' || $description === '' || $rules === '' || $venue === '' || $eventDateInput === '') {
            set_flash('error', 'All event fields are required.');
            redirect('admin/events.php?edit_id=' . $eventId);
        }

        if (!in_array($registrationMode, ['solo', 'team', 'both'], true)) {
            set_flash('error', 'Invalid registration mode selected.');
            redirect('admin/events.php?edit_id=' . $eventId);
        }

        if ($registrationMode === 'solo') {
            $teamMinMembers = 1;
            $teamMaxMembers = 1;
        } else {
            if ($teamMinMembers < 2 || $teamMaxMembers < $teamMinMembers || $teamMaxMembers > 50) {
                set_flash('error', 'For team registration, set valid team member range (min >= 2 and max <= 50).');
                redirect('admin/events.php?edit_id=' . $eventId);
            }
        }

        if ($paymentRequired === 1) {
            if ($paymentAmount <= 0) {
                set_flash('error', 'Payment amount must be greater than zero when payment is required.');
                redirect('admin/events.php?edit_id=' . $eventId);
            }
        } else {
            $paymentAmount = 0;
            $paymentNote = '';
        }

        if (strlen($paymentNote) > 2000) {
            $paymentNote = substr($paymentNote, 0, 2000);
        }

        $eventDate = DateTime::createFromFormat('Y-m-d\TH:i', $eventDateInput);
        if (!$eventDate) {
            set_flash('error', 'Invalid event date format.');
            redirect('admin/events.php?edit_id=' . $eventId);
        }

        $eventDateSql = $eventDate->format('Y-m-d H:i:s');
        $db = db();
        $savedFiles = [];
        $filesToDeleteAfterCommit = [];

        try {
            $newFlyerPath = save_uploaded_file(
                $_FILES['flyer_image'] ?? null,
                [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    'image/gif' => 'gif',
                ],
                5 * 1024 * 1024,
                'images'
            );
            if ($newFlyerPath !== null) {
                $savedFiles[] = $newFlyerPath;
            }

            $newRulesPdfPath = save_uploaded_file(
                $_FILES['rules_pdf'] ?? null,
                ['application/pdf' => 'pdf'],
                10 * 1024 * 1024,
                'docs'
            );
            if ($newRulesPdfPath !== null) {
                $savedFiles[] = $newRulesPdfPath;
            }

            $newSchedulePdfPath = save_uploaded_file(
                $_FILES['schedule_pdf'] ?? null,
                ['application/pdf' => 'pdf'],
                10 * 1024 * 1024,
                'docs'
            );
            if ($newSchedulePdfPath !== null) {
                $savedFiles[] = $newSchedulePdfPath;
            }

            $flyerValue = (string) ($existingEvent['flyer_path'] ?? '');
            $rulesPdfValue = (string) ($existingEvent['rules_pdf_path'] ?? '');
            $schedulePdfValue = (string) ($existingEvent['schedule_pdf_path'] ?? '');

            if (!empty($_POST['remove_flyer'])) {
                $flyerValue = '';
            }
            if (!empty($_POST['remove_rules_pdf'])) {
                $rulesPdfValue = '';
            }
            if (!empty($_POST['remove_schedule_pdf'])) {
                $schedulePdfValue = '';
            }

            if ($newFlyerPath !== null) {
                $flyerValue = $newFlyerPath;
            }
            if ($newRulesPdfPath !== null) {
                $rulesPdfValue = $newRulesPdfPath;
            }
            if ($newSchedulePdfPath !== null) {
                $schedulePdfValue = $newSchedulePdfPath;
            }

            // Defer deletion of replaced/removed files until after DB commit.
            $oldFlyer = (string) ($existingEvent['flyer_path'] ?? '');
            $oldRules = (string) ($existingEvent['rules_pdf_path'] ?? '');
            $oldSchedule = (string) ($existingEvent['schedule_pdf_path'] ?? '');

            if ($oldFlyer !== '' && $oldFlyer !== $flyerValue) {
                $filesToDeleteAfterCommit[] = $oldFlyer;
            }
            if ($oldRules !== '' && $oldRules !== $rulesPdfValue) {
                $filesToDeleteAfterCommit[] = $oldRules;
            }
            if ($oldSchedule !== '' && $oldSchedule !== $schedulePdfValue) {
                $filesToDeleteAfterCommit[] = $oldSchedule;
            }

            $db->begin_transaction();

            $updateStmt = $db->prepare(
                'UPDATE events
                 SET title = ?,
                     description = ?,
                     rules = ?,
                     event_date = ?,
                     venue = ?,
                     registration_mode = ?,
                     team_min_members = ?,
                     team_max_members = ?,
                     payment_required = ?,
                     payment_amount = ?,
                     payment_note = ?,
                     flyer_path = ?,
                     rules_pdf_path = ?,
                     schedule_pdf_path = ?,
                     registration_open = ?
                 WHERE id = ?'
            );
            $updateStmt->bind_param(
                'ssssssiiidssssii',
                $title,
                $description,
                $rules,
                $eventDateSql,
                $venue,
                $registrationMode,
                $teamMinMembers,
                $teamMaxMembers,
                $paymentRequired,
                $paymentAmount,
                $paymentNote,
                $flyerValue,
                $rulesPdfValue,
                $schedulePdfValue,
                $registrationOpen,
                $eventId
            );
            $updateStmt->execute();

            // We allow updating custom questions even if registrations exist.
            $db->query('DELETE FROM event_form_fields WHERE event_id = ' . (int) $eventId);

            if (count($customFields) > 0) {
                $fieldInsertStmt = $db->prepare(
                    'INSERT INTO event_form_fields
                        (event_id, field_label, field_type, placeholder, option_values, is_required, sort_order, min_length, max_length)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );

                foreach ($customFields as $field) {
                    $fieldLabel = $field['field_label'];
                    $fieldType = $field['field_type'];
                    $placeholder = $field['placeholder'];
                    $optionValues = $field['option_values'];
                    $isRequired = (int) $field['is_required'];
                    $sortOrder = (int) $field['sort_order'];
                    $minLen = $field['min_length'] === null ? null : (int) $field['min_length'];
                    $maxLen = $field['max_length'] === null ? null : (int) $field['max_length'];

                    $fieldInsertStmt->bind_param(
                        'isssssiii',
                        $eventId,
                        $fieldLabel,
                        $fieldType,
                        $placeholder,
                        $optionValues,
                        $isRequired,
                        $sortOrder,
                        $minLen,
                        $maxLen
                    );
                    $fieldInsertStmt->execute();
                }
            }

            $db->commit();

            foreach ($filesToDeleteAfterCommit as $relativePath) {
                $absolutePath = resolve_uploaded_absolute_path($relativePath);
                if ($absolutePath !== null && is_file($absolutePath)) {
                    @unlink($absolutePath);
                }
            }

            set_flash('success', 'Event updated successfully.');
        } catch (Throwable $exception) {
            try {
                $db->rollback();
            } catch (Throwable $ignored) {
                // Ignore rollback issues.
            }

            foreach ($savedFiles as $relativePath) {
                $absolutePath = resolve_uploaded_absolute_path($relativePath);
                if ($absolutePath !== null && is_file($absolutePath)) {
                    @unlink($absolutePath);
                }
            }

            if ($exception instanceof RuntimeException) {
                set_flash('error', 'Event update failed: ' . $exception->getMessage());
            } else {
                set_flash('error', 'Event update failed. Please try again.');
            }

            redirect('admin/events.php?edit_id=' . $eventId);
        }

        redirect(post_redirect_target('admin/events.php'));
    }

    if ($action === 'delete_event') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        if ($eventId <= 0) {
            set_flash('error', 'Invalid event selected.');
            redirect(post_redirect_target('admin/events.php'));
        }

        $eventStmt = db()->prepare(
            'SELECT id, title, flyer_path, rules_pdf_path, schedule_pdf_path FROM events WHERE id = ?'
        );
        $eventStmt->bind_param('i', $eventId);
        $eventStmt->execute();
        $event = $eventStmt->get_result()->fetch_assoc();

        if (!$event) {
            set_flash('error', 'Event not found.');
            redirect(post_redirect_target('admin/events.php'));
        }

        $filesToDelete = [
            (string) ($event['flyer_path'] ?? ''),
            (string) ($event['rules_pdf_path'] ?? ''),
            (string) ($event['schedule_pdf_path'] ?? ''),
        ];

        $deleteStmt = db()->prepare('DELETE FROM events WHERE id = ?');
        $deleteStmt->bind_param('i', $eventId);
        $deleteStmt->execute();

        foreach ($filesToDelete as $relativePath) {
            $absolutePath = resolve_uploaded_absolute_path($relativePath);
            if ($absolutePath !== null && is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }

        set_flash('success', 'Event deleted: ' . (string) $event['title']);
        redirect(post_redirect_target('admin/events.php'));
    }

    if ($action === 'toggle_registration') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $newStatus = (int) ($_POST['new_status'] ?? 0) === 1 ? 1 : 0;

        if ($eventId <= 0) {
            set_flash('error', 'Invalid event selected.');
            redirect(post_redirect_target('admin/events.php'));
        }

        $toggleStmt = db()->prepare('UPDATE events SET registration_open = ? WHERE id = ?');
        $toggleStmt->bind_param('ii', $newStatus, $eventId);
        $toggleStmt->execute();

        set_flash('success', $newStatus === 1 ? 'Registration opened for the event.' : 'Registration closed for the event.');
        redirect(post_redirect_target('admin/events.php'));
    }
}

$flashes = get_flashes();

$eventsStmt = db()->query(
    "SELECT e.id, e.title, e.event_date, e.venue, e.registration_open,
            e.registration_mode, e.team_min_members, e.team_max_members,
            e.payment_required, e.payment_amount,
            e.flyer_path, e.rules_pdf_path, e.schedule_pdf_path,
            COUNT(r.id) AS total_registrations,
            SUM(CASE WHEN r.attendance_status = 'present' THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN r.attendance_status = 'absent' THEN 1 ELSE 0 END) AS absent_count
     FROM events e
     LEFT JOIN registrations r ON r.event_id = e.id
     GROUP BY e.id, e.title, e.event_date, e.venue, e.registration_open,
              e.registration_mode, e.team_min_members, e.team_max_members,
              e.payment_required, e.payment_amount,
              e.flyer_path, e.rules_pdf_path, e.schedule_pdf_path
     ORDER BY e.event_date DESC"
);
$events = $eventsStmt->fetch_all(MYSQLI_ASSOC);

$editEventId = isset($_GET['edit_id']) ? (int) $_GET['edit_id'] : 0;
$editEvent = null;
$editCustomFieldsJson = '[]';
$customFieldsLocked = false;

if ($editEventId > 0) {
    $editStmt = db()->prepare(
        'SELECT id, title, description, rules, event_date, venue, registration_open,
                registration_mode, team_min_members, team_max_members,
                payment_required, payment_amount, payment_note,
                flyer_path, rules_pdf_path, schedule_pdf_path
         FROM events
         WHERE id = ?'
    );
    $editStmt->bind_param('i', $editEventId);
    $editStmt->execute();
    $editEvent = $editStmt->get_result()->fetch_assoc();

    if (!$editEvent) {
        set_flash('error', 'Event not found.');
        redirect('admin/events.php');
    }

    if (defined('OTP_FLOW_ENABLED') && OTP_FLOW_ENABLED) {
        ensure_registration_email_verifications_table();
        $regCountStmt = db()->prepare(
            'SELECT COUNT(*) AS c FROM registrations r
             INNER JOIN registration_email_verifications rev ON rev.registration_id = r.id
             WHERE r.event_id = ? AND rev.verified_at IS NOT NULL'
        );
    } else {
        $regCountStmt = db()->prepare('SELECT COUNT(*) AS c FROM registrations WHERE event_id = ?');
    }
    $regCountStmt->bind_param('i', $editEventId);
    $regCountStmt->execute();
    $regCountRow = $regCountStmt->get_result()->fetch_assoc();
    $customFieldsLocked = false; // allow adding/editing custom questions even after registrations

    $editFields = [];
    try {
        $fieldsStmt = db()->prepare(
            'SELECT field_label, field_type, placeholder, option_values, is_required, min_length, max_length
             FROM event_form_fields
             WHERE event_id = ?
             ORDER BY sort_order ASC, id ASC'
        );
        $fieldsStmt->bind_param('i', $editEventId);
        $fieldsStmt->execute();
        $rows = $fieldsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($rows as $row) {
            $editFields[] = [
                'label' => (string) $row['field_label'],
                'type' => (string) $row['field_type'],
                'placeholder' => (string) $row['placeholder'],
                'required' => ((int) $row['is_required']) === 1,
                'options' => (string) ($row['option_values'] ?? ''),
                'min_length' => isset($row['min_length']) ? (int) $row['min_length'] : null,
                'max_length' => isset($row['max_length']) ? (int) $row['max_length'] : null,
            ];
        }
    } catch (Throwable $ignored) {
        $editFields = [];
    }

    $editCustomFieldsJson = json_encode($editFields, JSON_UNESCAPED_UNICODE) ?: '[]';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Events | Attendance QR</title>
<link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>">
<link rel="stylesheet" href="<?= e(app_url('assets/css/christ-theme.css')) ?>">
<style>
.small-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 0.5rem;
}
</style>
</head>
<body>
<header class="topbar">
    <div class="container">
       
        <a class="brand" href="<?= e(app_url('admin/dashboard.php')) ?>">Admin Panel</a>
        <nav class="nav-links">
          
            <a href="<?= e(app_url('admin/dashboard.php')) ?>">Dashboard</a>
            <a href="<?= e(app_url('admin/scan.php')) ?>">Scan QR</a>
            <a href="<?= e(app_url('admin/email.php')) ?>">Email Settings</a>
            <a href="<?= e(app_url('admin/logout.php')) ?>">Logout</a>
        </nav>
        
    </div>
</header>

<main class="container">
    <section class="hero">
        <h1><?= $editEvent ? 'Edit Event' : 'Create Event Registration Forms' ?></h1>
        <p>Google-Forms style event form builder with media, team mode, and payment options.</p>
        <?php if ($editEvent): ?>
            <p class="small" style="margin-top: 0.35rem;">
                Editing: <strong><?= e((string) $editEvent['title']) ?></strong>
                · <a href="<?= e(app_url('admin/events.php')) ?>">Cancel edit</a>
            </p>
            <?php if ($customFieldsLocked): ?>
                <div class="alert info" style="margin-top: 0.75rem;">
                    Custom questions are locked because registrations already exist for this event.
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php foreach ($flashes as $flash): ?>
        <div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>

    <form id="eventForm" class="form-wrap" method="post" action="<?= e(app_url('admin/events.php')) ?>" enctype="multipart/form-data">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="<?= $editEvent ? 'update_event' : 'create_event' ?>">
        <?php if ($editEvent): ?>
            <input type="hidden" name="event_id" value="<?= (int) $editEvent['id'] ?>">
        <?php endif; ?>
        <input type="hidden" name="custom_fields_json" id="customFieldsJson" value="<?= e($editCustomFieldsJson) ?>">

        <div class="field">
            <label for="title">Event Title</label>
            <input id="title" name="title" maxlength="200" required value="<?= e((string) ($editEvent['title'] ?? '')) ?>">
        </div>

        <div class="field">
            <label for="description">Event Details</label>
            <textarea id="description" name="description" required><?= e((string) ($editEvent['description'] ?? '')) ?></textarea>
        </div>

        <div class="field">
            <label for="rules">Rules Summary</label>
            <textarea id="rules" name="rules" required><?= e((string) ($editEvent['rules'] ?? '')) ?></textarea>
        </div>

        <div class="field">
            <label for="venue">Venue</label>
            <input id="venue" name="venue" maxlength="200" required value="<?= e((string) ($editEvent['venue'] ?? '')) ?>">
        </div>

        <div class="field">
            <label for="event_date">Event Date and Time</label>
            <input
                id="event_date"
                name="event_date"
                type="datetime-local"
                required
                value="<?= $editEvent ? e(date('Y-m-d\\TH:i', strtotime((string) $editEvent['event_date']))) : '' ?>"
            >
        </div>

        <div class="field">
            <label for="flyer_image">Flyer/Event Icon Image (optional)</label>
            <input id="flyer_image" name="flyer_image" type="file" accept=".jpg,.jpeg,.png,.webp,.gif,image/*">
            <p class="small">Allowed: JPG, PNG, WEBP, GIF. Max size: 5 MB.</p>
            <?php if ($editEvent && (string) ($editEvent['flyer_path'] ?? '') !== ''): ?>
                <p class="small">
                    Current: <a href="<?= e(app_url((string) $editEvent['flyer_path'])) ?>" target="_blank" rel="noopener">View flyer</a>
                </p>
                <label class="check-control small">
                    <input type="checkbox" name="remove_flyer" value="1">
                    <span>Remove flyer</span>
                </label>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="rules_pdf">Rules PDF (optional)</label>
            <input id="rules_pdf" name="rules_pdf" type="file" accept=".pdf,application/pdf">
            <p class="small">Max size: 10 MB.</p>
            <?php if ($editEvent && (string) ($editEvent['rules_pdf_path'] ?? '') !== ''): ?>
                <p class="small">
                    Current: <a href="<?= e(app_url((string) $editEvent['rules_pdf_path'])) ?>" target="_blank" rel="noopener">View rules PDF</a>
                </p>
                <label class="check-control small">
                    <input type="checkbox" name="remove_rules_pdf" value="1">
                    <span>Remove rules PDF</span>
                </label>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="schedule_pdf">Schedule PDF (optional)</label>
            <input id="schedule_pdf" name="schedule_pdf" type="file" accept=".pdf,application/pdf">
            <p class="small">Max size: 10 MB.</p>
            <?php if ($editEvent && (string) ($editEvent['schedule_pdf_path'] ?? '') !== ''): ?>
                <p class="small">
                    Current: <a href="<?= e(app_url((string) $editEvent['schedule_pdf_path'])) ?>" target="_blank" rel="noopener">View schedule PDF</a>
                </p>
                <label class="check-control small">
                    <input type="checkbox" name="remove_schedule_pdf" value="1">
                    <span>Remove schedule PDF</span>
                </label>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="registration_mode">Registration Mode</label>
            <select id="registration_mode" name="registration_mode" required>
                <?php $mode = (string) ($editEvent['registration_mode'] ?? 'solo'); ?>
                <option value="solo" <?= $mode === 'solo' ? 'selected' : '' ?>>Solo Only</option>
                <option value="team" <?= $mode === 'team' ? 'selected' : '' ?>>Team Only</option>
                <option value="both" <?= $mode === 'both' ? 'selected' : '' ?>>Solo and Team</option>
            </select>
        </div>

        <div id="teamSettings" class="form-wrap" style="margin-bottom: 1rem;">
            <h3 style="margin-top: 0;">Team Settings</h3>
            <div class="field">
                <label for="team_min_members">Minimum Team Members</label>
                <input id="team_min_members" name="team_min_members" type="number" min="2" max="50" value="<?= (int) ($editEvent['team_min_members'] ?? 2) ?>">
            </div>
            <div class="field">
                <label for="team_max_members">Maximum Team Members</label>
                <input id="team_max_members" name="team_max_members" type="number" min="2" max="50" value="<?= (int) ($editEvent['team_max_members'] ?? 5) ?>">
            </div>
        </div>

        <div class="field check-field">
            <label class="check-control">
                <input id="payment_required" name="payment_required" type="checkbox" <?= (int) ($editEvent['payment_required'] ?? 0) === 1 ? 'checked' : '' ?>>
                <span>Payment Required for Registration</span>
            </label>
        </div>

        <div id="paymentSettings" class="form-wrap" style="display:none; margin-bottom: 0.6rem;">
            <h3 style="margin-top: 0;">Payment Settings</h3>
            <div class="field">
                <label for="payment_amount">Amount</label>
                <input id="payment_amount" name="payment_amount" type="number" min="0" step="0.01" placeholder="Example: 150.00" value="<?= e((string) ($editEvent['payment_amount'] ?? '')) ?>">
            </div>
            <div class="field">
                <label for="payment_note">Payment Instructions (optional)</label>
                <textarea id="payment_note" name="payment_note" placeholder="Example: Pay via UPI to xyz@upi and enter transaction ID."><?= e((string) ($editEvent['payment_note'] ?? '')) ?></textarea>
            </div>
        </div>

        <div class="field check-field">
            <label class="check-control">
                <input id="registration_open" name="registration_open" type="checkbox" <?= $editEvent ? ((int) $editEvent['registration_open'] === 1 ? 'checked' : '') : 'checked' ?>>
                <span>Registration Open (uncheck to close)</span>
            </label>
        </div>

        <div class="field form-builder-wrap">
            <div class="row" style="justify-content: space-between; align-items: center;">
                <label style="margin: 0;">Registration Form Questions</label>
                <button class="btn outline" type="button" id="addCustomFieldBtn">+ Add Question</button>
            </div>
            <p class="small">Build event-specific questions like Google Forms. For dropdown questions, add choices below.</p>
            <div id="customFieldsList" class="gf-question-list"></div>
            <p id="questionEmptyState" class="small muted">No custom questions yet. Click <strong>Add Question</strong>.</p>
        </div>

        <section class="form-wrap" id="registrationPreviewWrap" style="margin-bottom: 1rem;">
            <h2 class="section-title">Registration Form Preview</h2>
            <p class="small">This is a live preview of what students will see on the registration page.</p>
            <div id="registrationPreview"></div>
        </section>

        <button class="btn" type="submit"><?= $editEvent ? 'Update Event' : 'Create Event Form' ?></button>
    </form>

    <section class="form-wrap">
        <h2 class="section-title">Existing Events</h2>

        <table>
            <thead>
            <tr>
                <th>Event</th>
                <th>Date</th>
                <th>Venue</th>
                <th>Mode</th>
                <th>Payment</th>
                <th>Assets</th>
                <th>Registered</th>
                <th>Present</th>
                <th>Absent</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if (count($events) === 0): ?>
                <tr>
                    <td colspan="10">No events created yet.</td>
                </tr>
            <?php endif; ?>

            <?php foreach ($events as $event): ?>
                <tr>
                    <td><?= e($event['title']) ?></td>
                    <td><?= e(date('d M Y, h:i A', strtotime((string) $event['event_date']))) ?></td>
                    <td><?= e($event['venue']) ?></td>
                    <td>
                        <?php if ($event['registration_mode'] === 'solo'): ?>
                            Solo
                        <?php elseif ($event['registration_mode'] === 'team'): ?>
                            Team (<?= (int) $event['team_min_members'] ?>-<?= (int) $event['team_max_members'] ?>)
                        <?php else: ?>
                            Solo/Team (<?= (int) $event['team_min_members'] ?>-<?= (int) $event['team_max_members'] ?>)
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int) $event['payment_required'] === 1): ?>
                            Required (<?= e(number_format((float) $event['payment_amount'], 2)) ?>)
                        <?php else: ?>
                            Not Required
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((string) $event['flyer_path'] !== ''): ?>
                            <a href="<?= e(app_url((string) $event['flyer_path'])) ?>" target="_blank" rel="noopener">Flyer</a><br>
                        <?php endif; ?>
                        <?php if ((string) $event['rules_pdf_path'] !== ''): ?>
                            <a href="<?= e(app_url((string) $event['rules_pdf_path'])) ?>" target="_blank" rel="noopener">Rules PDF</a><br>
                        <?php endif; ?>
                        <?php if ((string) $event['schedule_pdf_path'] !== ''): ?>
                            <a href="<?= e(app_url((string) $event['schedule_pdf_path'])) ?>" target="_blank" rel="noopener">Schedule PDF</a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= (int) $event['total_registrations'] ?>
                        <?php if ((int) $event['registration_open'] === 1): ?>
                            <span class="badge present">Open</span>
                        <?php else: ?>
                            <span class="badge absent">Closed</span>
                        <?php endif; ?>
                    </td>
                    <td><?= (int) ($event['present_count'] ?? 0) ?></td>
                    <td><?= (int) ($event['absent_count'] ?? 0) ?></td>
                    <td>
                        <div class="row">
                            <a class="btn outline" href="<?= e(app_url('admin/events.php?edit_id=' . (int) $event['id'])) ?>">Edit</a>
                            <a class="btn outline" href="<?= e(app_url('admin/registrations.php?event_id=' . (int) $event['id'])) ?>">View</a>
                            <a class="btn outline" href="<?= e(app_url('admin/export_event_excel.php?event_id=' . (int) $event['id'])) ?>">Excel</a>
                            <a class="btn" href="<?= e(app_url('admin/scan.php?event_id=' . (int) $event['id'])) ?>">Scan</a>
                            <form method="post" action="<?= e(app_url('admin/events.php')) ?>">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="toggle_registration">
                                <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                                <input type="hidden" name="new_status" value="<?= (int) $event['registration_open'] === 1 ? 0 : 1 ?>">
                                <input type="hidden" name="return_to" value="admin/events.php">
                                <button class="btn warn" type="submit">
                                    <?= (int) $event['registration_open'] === 1 ? 'Close Reg.' : 'Open Reg.' ?>
                                </button>
                            </form>
                            <form method="post" action="<?= e(app_url('admin/events.php')) ?>" onsubmit="return confirm('Delete this event and all registrations/attendance? This cannot be undone.');">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="delete_event">
                                <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                                <input type="hidden" name="return_to" value="admin/events.php">
                                <button class="btn danger" type="submit">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <div class="footer-gap"></div>
</main>

<script>
const IS_EDIT_MODE = <?= $editEvent ? 'true' : 'false' ?>;
const CUSTOM_FIELDS_LOCKED = false;
const registrationModeEl = document.getElementById('registration_mode');
const teamSettingsEl = document.getElementById('teamSettings');
const paymentRequiredEl = document.getElementById('payment_required');
const paymentSettingsEl = document.getElementById('paymentSettings');

const customFieldsListEl = document.getElementById('customFieldsList');
const addCustomFieldBtn = document.getElementById('addCustomFieldBtn');
const customFieldsJsonEl = document.getElementById('customFieldsJson');
const eventFormEl = document.getElementById('eventForm');
const emptyStateEl = document.getElementById('questionEmptyState');
const previewEl = document.getElementById('registrationPreview');

function toggleTeamSettings() {
    const mode = registrationModeEl.value;
    teamSettingsEl.style.display = mode === 'solo' ? 'none' : 'block';
}

function togglePaymentSettings() {
    paymentSettingsEl.style.display = paymentRequiredEl.checked ? 'block' : 'none';
}

function updateEmptyState() {
    const count = customFieldsListEl.querySelectorAll('.custom-field-row').length;
    emptyStateEl.style.display = count === 0 ? 'block' : 'none';
}

function createOptionRow(value = '') {
    const row = document.createElement('div');
    row.className = 'gf-option-row';
    row.innerHTML = `
        <input type="text" class="gf-option-input" maxlength="80" placeholder="Choice" value="${value.replace(/"/g, '&quot;')}">
        <button type="button" class="btn danger gf-option-remove">X</button>
    `;

    row.querySelector('.gf-option-remove').addEventListener('click', () => {
        row.remove();
    });

    return row;
}

function ensureSelectOptions(questionEl) {
    const optionsList = questionEl.querySelector('.gf-options-list');
    if (optionsList.children.length === 0) {
        optionsList.appendChild(createOptionRow('Option 1'));
        optionsList.appendChild(createOptionRow('Option 2'));
    }
}

function fieldTemplate(index) {
    const wrapper = document.createElement('div');
    wrapper.className = 'custom-field-row';
    wrapper.innerHTML = `
        <div class="gf-question-head">
            <strong class="gf-question-title">Question ${index}</strong>
            <button type="button" class="btn danger custom-remove-btn">Remove</button>
        </div>

        <div class="custom-field-grid">
            <div class="field">
                <label>Question Title</label>
                <input type="text" class="custom-label" maxlength="120" placeholder="Untitled question">
            </div>

            <div class="field">
                <label>Answer Type</label>
                    <select class="custom-type">
                        <option value="text">Short Answer</option>
                        <option value="textarea">Paragraph</option>
                        <option value="roll">Roll Number</option>
                        <option value="tel">Phone Number</option>
                        <option value="number">Number</option>
                        <option value="email">Email</option>
                        <option value="date">Date</option>
                        <option value="select">Dropbox</option>
                        <option value="photo">Photos</option>
                        <option value="video">Video</option>
                    </select>
                </div>

            <div class="field">
                <label>Placeholder / Hint</label>
                <input type="text" class="custom-placeholder" maxlength="150" placeholder="Optional hint text">
            </div>

            <div class="field small-grid">
                <div>
                    <label>Min Length</label>
                    <input type="number" class="custom-minlen" min="0" max="2000" placeholder="e.g., 0">
                </div>
                <div>
                    <label>Max Length</label>
                    <input type="number" class="custom-maxlen" min="1" max="5000" placeholder="e.g., 200">
                </div>
            </div>

            <div class="field">
                <label class="check-control">
                    <input type="checkbox" class="custom-required">
                    <span>Required</span>
                </label>
            </div>
        </div>

        <div class="field custom-options-wrap" style="display:none;">
            <label>Dropdown Choices</label>
            <div class="gf-options-list"></div>
            <div class="row" style="margin-top: 0.45rem;">
                <button type="button" class="btn outline add-option-btn">+ Add Choice</button>
            </div>
        </div>
    `;

    const removeBtn = wrapper.querySelector('.custom-remove-btn');
    const typeEl = wrapper.querySelector('.custom-type');
    const optionsWrap = wrapper.querySelector('.custom-options-wrap');
    const addOptionBtn = wrapper.querySelector('.add-option-btn');
    const optionsList = wrapper.querySelector('.gf-options-list');

    removeBtn.addEventListener('click', () => {
        wrapper.remove();
        renumberFields();
        updateEmptyState();
    });

    addOptionBtn.addEventListener('click', () => {
        optionsList.appendChild(createOptionRow(''));
    });

    typeEl.addEventListener('change', () => {
        const isSelect = typeEl.value === 'select';
        optionsWrap.style.display = isSelect ? 'block' : 'none';
        if (isSelect) {
            ensureSelectOptions(wrapper);
        }
    });

    return wrapper;
}

function renumberFields() {
    const rows = customFieldsListEl.querySelectorAll('.custom-field-row');
    rows.forEach((row, idx) => {
        const title = row.querySelector('.gf-question-title');
        if (title) {
            title.textContent = 'Question ' + (idx + 1);
        }
    });
}

function addField() {
    const count = customFieldsListEl.querySelectorAll('.custom-field-row').length;
    if (count >= 20) {
        alert('Maximum 20 custom questions allowed per event.');
        return;
    }

    const row = fieldTemplate(count + 1);
    customFieldsListEl.appendChild(row);
    updateEmptyState();
}

function serializeFields() {
    const rows = customFieldsListEl.querySelectorAll('.custom-field-row');
    const fields = [];

    rows.forEach((row) => {
        const label = row.querySelector('.custom-label').value.trim();
        const type = row.querySelector('.custom-type').value;
        const placeholder = row.querySelector('.custom-placeholder').value.trim();
        const required = row.querySelector('.custom-required').checked;
        const minLenRaw = row.querySelector('.custom-minlen')?.value.trim() ?? '';
        const maxLenRaw = row.querySelector('.custom-maxlen')?.value.trim() ?? '';
        const min_length = minLenRaw === '' ? null : Number(minLenRaw);
        const max_length = maxLenRaw === '' ? null : Number(maxLenRaw);

        let options = '';
        if (type === 'select') {
            const optionValues = [];
            row.querySelectorAll('.gf-option-input').forEach((optInput) => {
                const val = optInput.value.trim();
                if (val !== '') {
                    optionValues.push(val);
                }
            });
            options = optionValues.join('\n');
        }

        if (label !== '') {
            fields.push({ label, type, placeholder, required, options, min_length, max_length });
        }
    });

    customFieldsJsonEl.value = JSON.stringify(fields);
    return fields;
}

registrationModeEl.addEventListener('change', toggleTeamSettings);
paymentRequiredEl.addEventListener('change', togglePaymentSettings);
addCustomFieldBtn.addEventListener('click', addField);

function loadInitialFields() {
    let existing = [];
    try {
        existing = JSON.parse(customFieldsJsonEl.value || '[]');
    } catch (e) {
        existing = [];
    }

    if (!Array.isArray(existing) || existing.length === 0) {
        return;
    }

    customFieldsListEl.innerHTML = '';

    existing.slice(0, 20).forEach((f, idx) => {
        const row = fieldTemplate(idx + 1);
        const labelEl = row.querySelector('.custom-label');
        const typeEl = row.querySelector('.custom-type');
        const placeholderEl = row.querySelector('.custom-placeholder');
        const requiredEl = row.querySelector('.custom-required');
        const minLenEl = row.querySelector('.custom-minlen');
        const maxLenEl = row.querySelector('.custom-maxlen');
        const optionsWrap = row.querySelector('.custom-options-wrap');
        const optionsList = row.querySelector('.gf-options-list');

        if (labelEl) labelEl.value = (f && f.label) ? String(f.label) : '';
        if (typeEl) typeEl.value = (f && f.type) ? String(f.type) : 'text';
        if (placeholderEl) placeholderEl.value = (f && f.placeholder) ? String(f.placeholder) : '';
        if (requiredEl) requiredEl.checked = !!(f && f.required);
        if (minLenEl) minLenEl.value = (f && typeof f.min_length !== 'undefined' && f.min_length !== null) ? String(f.min_length) : '';
        if (maxLenEl) maxLenEl.value = (f && typeof f.max_length !== 'undefined' && f.max_length !== null) ? String(f.max_length) : '';

        if (typeEl && typeEl.value === 'select') {
            if (optionsWrap) optionsWrap.style.display = 'block';
            if (optionsList) {
                optionsList.innerHTML = '';
                const opts = String((f && f.options) ? f.options : '')
                    .split('\n')
                    .map((x) => x.trim())
                    .filter(Boolean);
                if (opts.length === 0) {
                    optionsList.appendChild(createOptionRow('Option 1'));
                    optionsList.appendChild(createOptionRow('Option 2'));
                } else {
                    opts.slice(0, 40).forEach((o) => optionsList.appendChild(createOptionRow(o)));
                }
            }
        }

        customFieldsListEl.appendChild(row);
    });
}

function lockCustomFieldsUI() {
    if (!CUSTOM_FIELDS_LOCKED) return;

    if (addCustomFieldBtn) {
        addCustomFieldBtn.disabled = true;
        addCustomFieldBtn.title = 'Custom questions are locked for this event.';
    }

    customFieldsListEl.querySelectorAll('input, select, textarea, button').forEach((el) => {
        if (el.classList.contains('gf-option-remove') || el.classList.contains('custom-remove-btn') || el.classList.contains('add-option-btn')) {
            el.disabled = true;
            return;
        }

        if (el.type === 'checkbox' || el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'TEXTAREA') {
            el.disabled = true;
        }
    });
}

function eHtml(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
}

function collectPreviewState() {
    const title = document.getElementById('title')?.value?.trim() ?? '';
    const venue = document.getElementById('venue')?.value?.trim() ?? '';
    const date = document.getElementById('event_date')?.value ?? '';
    const mode = registrationModeEl.value;
    const teamMin = document.getElementById('team_min_members')?.value ?? '2';
    const teamMax = document.getElementById('team_max_members')?.value ?? '5';
    const paymentRequired = paymentRequiredEl.checked;
    const paymentAmount = document.getElementById('payment_amount')?.value ?? '';
    const paymentNote = document.getElementById('payment_note')?.value?.trim() ?? '';
    const registrationOpen = document.getElementById('registration_open')?.checked ?? true;
    const fields = serializeFields();

    return {
        title,
        venue,
        date,
        mode,
        teamMin,
        teamMax,
        paymentRequired,
        paymentAmount,
        paymentNote,
        registrationOpen,
        fields,
    };
}

function renderPreview() {
    if (!previewEl) return;

    const state = collectPreviewState();

    const badge = state.registrationOpen
        ? '<span class="badge present">Open</span>'
        : '<span class="badge absent">Closed</span>';

    const eventMeta = [
        state.title ? `<div><strong>Event:</strong> ${eHtml(state.title)}</div>` : '',
        state.date ? `<div><strong>Date:</strong> ${eHtml(state.date.replace('T', ' '))}</div>` : '',
        state.venue ? `<div><strong>Venue:</strong> ${eHtml(state.venue)}</div>` : '',
        `<div><strong>Registration:</strong> ${badge}</div>`,
    ].filter(Boolean).join('');

    let formBits = '';

    formBits += `
        <div class="form-section">
            <h3 class="form-section-title">Student Details</h3>
            <div class="form-grid">
                <div class="field">
                    <label>Full Name <span class="req">*</span></label>
                    <input type="text" placeholder="e.g., Priya Sharma" disabled>
                </div>
                <!-- Roll Number preview removed per requirement -->
                <div class="field">
                    <label>Email <span class="req">*</span></label>
                    <input type="email" placeholder="you@example.com" disabled>
                </div>
                <div class="field">
                    <label>Phone <span class="req">*</span></label>
                    <input type="tel" placeholder="+91 98765 43210" disabled>
                </div>
            </div>
        </div>
    `;

    if (state.mode === 'both') {
        formBits += `
            <div class="field">
                <label>Registration Type <span class="req">*</span></label>
                <select disabled>
                    <option>Solo</option>
                    <option>Team</option>
                </select>
            </div>
        `;
    }

    if (state.mode === 'team' || state.mode === 'both') {
        formBits += `
            <div class="form-wrap" style="margin-bottom: 1rem;">
                <h3 style="margin-top: 0;">Team Details</h3>
                <div class="form-grid">
                    <div class="field">
                        <label>Team Name <span class="req">*</span></label>
                        <input type="text" placeholder="Enter team name" disabled>
                    </div>
                    <div class="field">
                        <label>Number of Members</label>
                        <input type="number" value="${eHtml(state.teamMin)}" min="${eHtml(state.teamMin)}" max="${eHtml(state.teamMax)}" disabled>
                        <p class="small">Allowed range: ${eHtml(state.teamMin)} - ${eHtml(state.teamMax)}</p>
                    </div>
                </div>
            </div>
        `;
    }

    if (state.paymentRequired) {
        const amountText = state.paymentAmount ? eHtml(state.paymentAmount) : '0.00';
        formBits += `
            <div class="form-wrap" style="margin-bottom: 1rem;">
                <h3 style="margin-top: 0;">Payment Details</h3>
                <p><strong>Amount:</strong> ${amountText}</p>
                ${state.paymentNote ? `<p class="small">${eHtml(state.paymentNote)}</p>` : ''}
                <div class="field">
                    <label>Payment Reference / Transaction ID <span class="req">*</span></label>
                    <input type="text" placeholder="Enter transaction ID" disabled>
                </div>
            </div>
        `;
    }

    if (Array.isArray(state.fields) && state.fields.length > 0) {
        formBits += `<div class="form-section"><h3 class="form-section-title">Event Questions</h3>`;
        state.fields.forEach((f) => {
            const req = f.required ? ' <span class="req">*</span>' : '';
            const ph = f.placeholder ? ` placeholder="${eHtml(f.placeholder)}"` : '';
            if (f.type === 'textarea') {
                formBits += `
                    <div class="field">
                        <label>${eHtml(f.label)}${req}</label>
                        <textarea${ph} disabled></textarea>
                    </div>
                `;
            } else if (f.type === 'select') {
                const options = (f.options || '').split('\n').map((x) => x.trim()).filter(Boolean);
                formBits += `
                    <div class="field">
                        <label>${eHtml(f.label)}${req}</label>
                        <select disabled>
                            <option>Select an option</option>
                            ${options.map((o) => `<option>${eHtml(o)}</option>`).join('')}
                        </select>
                    </div>
                `;
            } else if (f.type === 'photo') {
                formBits += `
                    <div class="field">
                        <label>${eHtml(f.label)}${req}</label>
                        <input type="file" accept="image/*" disabled>
                    </div>
                `;
            } else if (f.type === 'video') {
                formBits += `
                    <div class="field">
                        <label>${eHtml(f.label)}${req}</label>
                        <input type="file" accept="video/*" disabled>
                    </div>
                `;
            } else if (f.type === 'roll') {
                formBits += `
                    <div class="field">
                        <label>${eHtml(f.label)}${req}</label>
                        <input type="text" inputmode="numeric" pattern="[0-9]{1,12}" placeholder="Digits only" disabled>
                    </div>
                `;
            } else {
                formBits += `
                    <div class="field">
                        <label>${eHtml(f.label)}${req}</label>
                        <input type="${eHtml(f.type)}"${ph} disabled>
                    </div>
                `;
            }
        });
        formBits += `</div>`;
    } else {
        formBits += `<p class="small muted">No extra questions added yet.</p>`;
    }

    previewEl.innerHTML = `
        <div class="form-section">
            <h3 class="form-section-title">Event Summary</h3>
            <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0.8rem;">
                <div class="card" style="box-shadow: none; padding: 0.9rem;">${eventMeta}</div>
                <div class="card" style="box-shadow: none; padding: 0.9rem;">
                    <div><strong>Mode:</strong> ${eHtml(state.mode)}</div>
                    <div><strong>Payment:</strong> ${state.paymentRequired ? 'Required' : 'Not required'}</div>
                </div>
            </div>
        </div>
        ${formBits}
        <div class="form-actions">
            <button class="btn outline" type="button" id="scrollToQuestionsBtn">Add more questions/options</button>
        </div>
    `;

    const scrollBtn = document.getElementById('scrollToQuestionsBtn');
    if (scrollBtn) {
        scrollBtn.addEventListener('click', () => {
            const target = document.getElementById('addCustomFieldBtn');
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                target.focus();
            }
        });
    }
}

function validateFields(fields) {
    for (const f of fields) {
        if (f.type === 'select') {
            const options = (f.options || '').split('\n').map((x) => x.trim()).filter(Boolean);
            if (options.length < 2) {
                return `Dropdown question "${f.label}" needs at least 2 choices.`;
            }
        }
    }
    return '';
}

let isSubmitting = false;
eventFormEl.addEventListener('submit', (event) => {
    if (isSubmitting) return;

    const fields = serializeFields();
    const error = validateFields(fields);
    if (error) {
        event.preventDefault();
        alert(error);
        renderPreview();
        return;
    }

    // ensure serialized before native submit
    customFieldsJsonEl.value = JSON.stringify(fields);
    isSubmitting = true;
});

customFieldsListEl.addEventListener('input', renderPreview);
customFieldsListEl.addEventListener('change', renderPreview);
registrationModeEl.addEventListener('change', renderPreview);
paymentRequiredEl.addEventListener('change', renderPreview);
document.getElementById('registration_open')?.addEventListener('change', renderPreview);
['title','venue','event_date','team_min_members','team_max_members','payment_amount','payment_note'].forEach((id) => {
    const el = document.getElementById(id);
    if (el) {
        el.addEventListener('input', renderPreview);
        el.addEventListener('change', renderPreview);
    }
});

toggleTeamSettings();
togglePaymentSettings();
loadInitialFields();
lockCustomFieldsUI();
updateEmptyState();
renderPreview();
</script>
</body>
</html>
