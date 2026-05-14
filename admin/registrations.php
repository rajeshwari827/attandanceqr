<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_admin_login();

$eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid form token.');
        redirect('admin/registrations.php?event_id=' . $eventId);
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'set_status') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $registrationId = (int) ($_POST['registration_id'] ?? 0);
        $newStatus = (string) ($_POST['new_status'] ?? '');

        if (!in_array($newStatus, ['present', 'absent'], true)) {
            set_flash('error', 'Invalid attendance status.');
            redirect('admin/registrations.php?event_id=' . $eventId);
        }

        $updateStmt = db()->prepare(
            "UPDATE registrations
             SET attendance_status = ?,
                 attendance_marked_at = CASE WHEN ? = 'present' THEN NOW() ELSE NULL END
             WHERE id = ? AND event_id = ?"
        );
        $updateStmt->bind_param('ssii', $newStatus, $newStatus, $registrationId, $eventId);
        $updateStmt->execute();

        set_flash('success', 'Attendance updated successfully.');
        redirect('admin/registrations.php?event_id=' . $eventId);
    }

    if ($action === 'set_payment_status') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $registrationId = (int) ($_POST['registration_id'] ?? 0);
        $newPaymentStatus = (string) ($_POST['new_payment_status'] ?? '');

        if (!in_array($newPaymentStatus, ['pending', 'paid', 'not_required'], true)) {
            set_flash('error', 'Invalid payment status.');
            redirect('admin/registrations.php?event_id=' . $eventId);
        }

        $updatePaymentStmt = db()->prepare(
            'UPDATE registrations SET payment_status = ? WHERE id = ? AND event_id = ?'
        );
        $updatePaymentStmt->bind_param('sii', $newPaymentStatus, $registrationId, $eventId);
        $updatePaymentStmt->execute();

        set_flash('success', 'Payment status updated successfully.');
        redirect('admin/registrations.php?event_id=' . $eventId);
    }
}

if ($eventId <= 0) {
    set_flash('error', 'Please select an event first.');
    redirect('admin/dashboard.php');
}

$eventStmt = db()->prepare(
    'SELECT id, title, event_date, venue, payment_required, payment_amount
     FROM events
     WHERE id = ?'
);
$eventStmt->bind_param('i', $eventId);
$eventStmt->execute();
$event = $eventStmt->get_result()->fetch_assoc();

if (!$event) {
    set_flash('error', 'Event not found.');
    redirect('admin/dashboard.php');
}

$verifiedJoin = '';
$verifiedWhere = '';
if (defined('OTP_FLOW_ENABLED') && OTP_FLOW_ENABLED) {
    // Only include registrations that have been email/OTP verified.
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

$presentCount = 0;
$absentCount = 0;
$paidCount = 0;
$pendingCount = 0;

foreach ($registrations as $registration) {
    if ($registration['attendance_status'] === 'present') {
        $presentCount++;
    } else {
        $absentCount++;
    }

    if ($registration['payment_status'] === 'paid') {
        $paidCount++;
    }

    if ($registration['payment_status'] === 'pending') {
        $pendingCount++;
    }
}

$flashes = get_flashes();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Event Registrations | Attendance QR</title>
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/christ-theme.css')) ?>">
</head>
<body>
<header class="topbar">
    <div class="container">
        <a class="brand" href="<?= e(app_url('admin/dashboard.php')) ?>">Admin Panel</a>
        <nav class="nav-links">
            <a href="<?= e(app_url('admin/events.php')) ?>">Manage Events</a>
            <a href="<?= e(app_url('admin/scan.php?event_id=' . (int) $eventId)) ?>">Scan QR</a>
            <a href="<?= e(app_url('admin/email.php')) ?>">Email Settings</a>
            <a href="<?= e(app_url('admin/logout.php')) ?>">Logout</a>
        </nav>
    </div>
</header>

<main class="container">
    <section class="hero">
        <h1><?= e($event['title']) ?> Attendance</h1>
        <p>
            <?= e(date('d M Y, h:i A', strtotime((string) $event['event_date']))) ?>
            | <?= e($event['venue']) ?>
        </p>
    </section>

    <?php foreach ($flashes as $flash): ?>
        <div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>

    <section class="kpis">
        <article class="kpi">
            <h4>Total Registered</h4>
            <p><?= count($registrations) ?></p>
        </article>
        <article class="kpi">
            <h4>Present</h4>
            <p><?= $presentCount ?></p>
        </article>
        <article class="kpi">
            <h4>Absent</h4>
            <p><?= $absentCount ?></p>
        </article>
        <article class="kpi">
            <h4>Paid</h4>
            <p><?= $paidCount ?></p>
        </article>
        <article class="kpi">
            <h4>Payment Pending</h4>
            <p><?= $pendingCount ?></p>
        </article>
    </section>

    <div class="row" style="justify-content: flex-end; margin: 0 0 1rem;">
        <a class="btn" href="<?= e(app_url('admin/export_event_excel.php?event_id=' . (int) $eventId)) ?>">Download Excel</a>
    </div>

    <?php if ((int) $event['payment_required'] === 1): ?>
        <div class="alert info">Event payment required amount: <?= e(number_format((float) $event['payment_amount'], 2)) ?></div>
    <?php endif; ?>

    <section class="form-wrap">
        <h2 class="section-title">Student Attendance List</h2>

        <table>
            <thead>
            <tr>
                <th>Name</th>
                <th>Roll/Contact</th>
                <th>Type / Team</th>
                <th>Payment</th>
                <th>Custom Details</th>
                <th>Attendance</th>
                <th>Marked At</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if (count($registrations) === 0): ?>
                <tr>
                    <td colspan="8">No students have registered for this event.</td>
                </tr>
            <?php endif; ?>

            <?php foreach ($registrations as $registration): ?>
                <?php $rid = (int) $registration['id']; ?>
                <tr>
                    <td>
                        <strong><?= e($registration['full_name']) ?></strong><br>
                        <span class="small">Registered: <?= e(date('d M Y, h:i A', strtotime((string) $registration['registered_at']))) ?></span>
                    </td>
                    <td>
                        <?= e($registration['email']) ?><br>
                        <?= e($registration['phone']) ?>
                    </td>
                    <td>
                        <?= e(ucfirst((string) $registration['registration_type'])) ?><br>
                        <?php if ($registration['registration_type'] === 'team'): ?>
                            Team: <?= e((string) $registration['team_name']) ?><br>
                            Members: <?= (int) $registration['team_size'] ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($registration['payment_status'] === 'paid'): ?>
                            <span class="badge present">Paid</span>
                        <?php elseif ($registration['payment_status'] === 'pending'): ?>
                            <span class="badge absent">Pending</span>
                        <?php else: ?>
                            <span class="badge present">Not Required</span>
                        <?php endif; ?>

                        <?php if ((string) $registration['payment_reference'] !== ''): ?>
                            <br><span class="small">Ref: <?= e((string) $registration['payment_reference']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($customValuesByRegistration[$rid])): ?>
                            <?php foreach ($customValuesByRegistration[$rid] as $detail): ?>
                                <?php
                                $val = (string) ($detail['value'] ?? '');
                                $isFile = stripos($val, 'uploads/custom_fields/') === 0;
                                ?>
                                <div class="small">
                                    <strong><?= e($detail['label']) ?>:</strong>
                                    <?php if ($isFile): ?>
                                        <a href="<?= e(app_url($val)) ?>" target="_blank" rel="noopener">Open</a>
                                    <?php else: ?>
                                        <?= e($val) ?>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($registration['attendance_status'] === 'present'): ?>
                            <span class="badge present">Present</span>
                        <?php else: ?>
                            <span class="badge absent">Absent</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= $registration['attendance_marked_at'] ? e(date('d M Y, h:i A', strtotime((string) $registration['attendance_marked_at']))) : '-' ?>
                    </td>
                    <td>
                        <div class="row">
                            <form method="post" action="<?= e(app_url('admin/registrations.php?event_id=' . (int) $eventId)) ?>">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="set_status">
                                <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
                                <input type="hidden" name="registration_id" value="<?= $rid ?>">
                                <?php if ($registration['attendance_status'] === 'present'): ?>
                                    <input type="hidden" name="new_status" value="absent">
                                    <button class="btn warn" type="submit">Mark Absent</button>
                                <?php else: ?>
                                    <input type="hidden" name="new_status" value="present">
                                    <button class="btn" type="submit">Mark Present</button>
                                <?php endif; ?>
                            </form>

                            <?php if ($registration['payment_status'] !== 'not_required'): ?>
                                <form method="post" action="<?= e(app_url('admin/registrations.php?event_id=' . (int) $eventId)) ?>">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="set_payment_status">
                                    <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
                                    <input type="hidden" name="registration_id" value="<?= $rid ?>">
                                    <?php if ($registration['payment_status'] === 'paid'): ?>
                                        <input type="hidden" name="new_payment_status" value="pending">
                                        <button class="btn warn" type="submit">Set Pending</button>
                                    <?php else: ?>
                                        <input type="hidden" name="new_payment_status" value="paid">
                                        <button class="btn" type="submit">Mark Paid</button>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <div class="footer-gap"></div>
</main>
</body>
</html>
