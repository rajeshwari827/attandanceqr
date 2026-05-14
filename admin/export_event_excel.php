<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_admin_login();

function xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function safe_filename(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'export';
    }

    $value = preg_replace('/[^\pL\pN\-_ ]+/u', '_', $value) ?? 'export';
    $value = trim((string) $value, " \t\n\r\0\x0B._-");

    return $value === '' ? 'export' : $value;
}

function sanitize_sheet_name(string $name, array &$usedNames): string
{
    $name = trim($name);
    $name = str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name);
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
    $name = trim($name);

    $strlen = static function (string $value): int {
        return function_exists('mb_strlen') ? (int) mb_strlen($value, 'UTF-8') : strlen($value);
    };
    $substr = static function (string $value, int $start, int $length): string {
        return function_exists('mb_substr') ? (string) mb_substr($value, $start, $length, 'UTF-8') : substr($value, $start, $length);
    };

    if ($name === '') {
        $name = 'Participant';
    }

    if ($strlen($name) > 31) {
        $name = $substr($name, 0, 31);
    }

    $base = $name;
    $i = 2;
    while (isset($usedNames[$name])) {
        $suffix = ' (' . $i . ')';
        $maxBaseLen = 31 - $strlen($suffix);
        $trimmedBase = $base;
        if ($strlen($trimmedBase) > $maxBaseLen) {
            $trimmedBase = $substr($trimmedBase, 0, $maxBaseLen);
        }
        $name = $trimmedBase . $suffix;
        $i++;
    }

    $usedNames[$name] = true;
    return $name;
}

function excel_row(array $cells): string
{
    $out = '<Row>';
    foreach ($cells as $cell) {
        $out .= '<Cell><Data ss:Type="String">' . xml_escape((string) $cell) . '</Data></Cell>';
    }
    $out .= '</Row>';
    return $out;
}

$eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
if ($eventId <= 0) {
    set_flash('error', 'Invalid event selected.');
    redirect('admin/events.php');
}

$eventStmt = db()->prepare('SELECT id, title, event_date, venue FROM events WHERE id = ?');
$eventStmt->bind_param('i', $eventId);
$eventStmt->execute();
$event = $eventStmt->get_result()->fetch_assoc();

if (!$event) {
    set_flash('error', 'Event not found.');
    redirect('admin/events.php');
}

$verifiedJoin = '';
$verifiedWhere = '';
if (defined('OTP_FLOW_ENABLED') && OTP_FLOW_ENABLED) {
    ensure_registration_email_verifications_table();
    $verifiedJoin = 'INNER JOIN registration_email_verifications rev ON rev.registration_id = r.id';
    $verifiedWhere = ' AND rev.verified_at IS NOT NULL';
}

$sql = "SELECT r.id, r.attendance_status, r.attendance_marked_at, r.registered_at,
               r.registration_type, r.team_name, r.team_size,
               r.payment_status, r.payment_reference,
            s.full_name, s.email, s.phone
        FROM registrations r
        INNER JOIN students s ON s.id = r.student_id
        $verifiedJoin
        WHERE r.event_id = ? $verifiedWhere
        ORDER BY s.full_name ASC";

$registrationsStmt = db()->prepare($sql);
$registrationsStmt->bind_param('i', $eventId);
$registrationsStmt->execute();
$registrations = $registrationsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$customValuesByRegistration = [];
try {
    $customValuesStmt = db()->prepare(
        "SELECT rv.registration_id, f.field_label, rv.field_value
         FROM registration_field_values rv
         INNER JOIN event_form_fields f ON f.id = rv.event_field_id
         INNER JOIN registrations r ON r.id = rv.registration_id
         WHERE r.event_id = ?
         ORDER BY rv.registration_id, f.sort_order ASC, f.id ASC"
    );
    $customValuesStmt->bind_param('i', $eventId);
    $customValuesStmt->execute();
    $customRows = $customValuesStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($customRows as $row) {
        $rid = (int) $row['registration_id'];
        if (!isset($customValuesByRegistration[$rid])) {
            $customValuesByRegistration[$rid] = [];
        }

        $customValuesByRegistration[$rid][] = [
            'label' => (string) $row['field_label'],
            'value' => (string) $row['field_value'],
        ];
    }
} catch (Throwable $ignored) {
    $customValuesByRegistration = [];
}

$title = (string) $event['title'];
$datePart = date('Y-m-d_H-i', strtotime((string) $event['event_date']));
$fileBase = safe_filename('event_' . (int) $event['id'] . '_' . $title . '_' . $datePart);
$fileName = $fileBase . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fileName . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$xml = [];
$xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
$xml[] = '<?mso-application progid="Excel.Sheet"?>';
$xml[] = '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"';
$xml[] = ' xmlns:o="urn:schemas-microsoft-com:office:office"';
$xml[] = ' xmlns:x="urn:schemas-microsoft-com:office:excel"';
$xml[] = ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"';
$xml[] = ' xmlns:html="http://www.w3.org/TR/REC-html40">';

$usedNames = [];

// Summary sheet
$xml[] = '<Worksheet ss:Name="' . xml_escape(sanitize_sheet_name('All Participants', $usedNames)) . '"><Table>';
    $xml[] = excel_row([
        'Name',
        'Email',
        'Phone',
        'Registration Type',
        'Team Name',
        'Team Size',
        'Registered At',
        'Attendance Status',
        'Attendance Marked At',
        'Payment Status',
        'Payment Reference',
        'Custom Details',
    ]);

foreach ($registrations as $registration) {
    $rid = (int) $registration['id'];
    $customPairs = $customValuesByRegistration[$rid] ?? [];
    $customSummaryParts = [];
    foreach ($customPairs as $pair) {
        $label = trim((string) ($pair['label'] ?? ''));
        $value = trim((string) ($pair['value'] ?? ''));
        if ($label === '' && $value === '') {
            continue;
        }
        if ($label === '') {
            $customSummaryParts[] = $value;
            continue;
        }
        $customSummaryParts[] = $label . ': ' . $value;
    }

    $xml[] = excel_row([
        (string) $registration['full_name'],
        (string) $registration['email'],
        (string) $registration['phone'],
        ucfirst((string) $registration['registration_type']),
        (string) ($registration['registration_type'] === 'team' ? (string) $registration['team_name'] : ''),
        (string) ($registration['registration_type'] === 'team' ? (string) $registration['team_size'] : ''),
        (string) $registration['registered_at'],
        (string) $registration['attendance_status'],
        (string) ($registration['attendance_marked_at'] ?? ''),
        (string) $registration['payment_status'],
        (string) ($registration['payment_reference'] ?? ''),
        implode(' | ', $customSummaryParts),
    ]);
}

$xml[] = '</Table></Worksheet>';

// One worksheet per participant (registration)
$counter = 1;
foreach ($registrations as $registration) {
    $rid = (int) $registration['id'];
    $sheetNameRaw = (string) $registration['full_name'];
    if (trim($sheetNameRaw) === '') {
        $sheetNameRaw = 'Participant ' . $counter;
    }

    $sheetName = sanitize_sheet_name($sheetNameRaw, $usedNames);
    $counter++;

    $xml[] = '<Worksheet ss:Name="' . xml_escape($sheetName) . '"><Table>';
    $xml[] = excel_row(['Event Title', (string) $event['title']]);
    $xml[] = excel_row(['Event Date', (string) $event['event_date']]);
    $xml[] = excel_row(['Venue', (string) $event['venue']]);
    $xml[] = excel_row(['', '']);
    $xml[] = excel_row(['Full Name', (string) $registration['full_name']]);
    $xml[] = excel_row(['Email', (string) $registration['email']]);
    $xml[] = excel_row(['Phone', (string) $registration['phone']]);
    $xml[] = excel_row(['Registration Type', ucfirst((string) $registration['registration_type'])]);
    $xml[] = excel_row(['Team Name', (string) ($registration['registration_type'] === 'team' ? (string) $registration['team_name'] : '')]);
    $xml[] = excel_row(['Team Size', (string) ($registration['registration_type'] === 'team' ? (string) $registration['team_size'] : '')]);
    $xml[] = excel_row(['Registered At', (string) $registration['registered_at']]);
    $xml[] = excel_row(['Attendance Status', (string) $registration['attendance_status']]);
    $xml[] = excel_row(['Attendance Marked At', (string) ($registration['attendance_marked_at'] ?? '')]);
    $xml[] = excel_row(['Payment Status', (string) $registration['payment_status']]);
    $xml[] = excel_row(['Payment Reference', (string) ($registration['payment_reference'] ?? '')]);

    $customPairs = $customValuesByRegistration[$rid] ?? [];
    if (count($customPairs) > 0) {
        $xml[] = excel_row(['', '']);
        $xml[] = excel_row(['Custom Fields', 'Value']);
        foreach ($customPairs as $pair) {
            $xml[] = excel_row([(string) ($pair['label'] ?? ''), (string) ($pair['value'] ?? '')]);
        }
    }

    $xml[] = '</Table></Worksheet>';
}

$xml[] = '</Workbook>';

echo implode("\n", $xml);
exit;
