<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_admin_login();

$flashes = get_flashes();

if (defined('OTP_FLOW_ENABLED') && OTP_FLOW_ENABLED) {
    ensure_registration_email_verifications_table();
    $statsSql = "
        SELECT
            (SELECT COUNT(*) FROM events) AS events_count,
            (SELECT COUNT(*) FROM registrations r INNER JOIN registration_email_verifications rev ON rev.registration_id = r.id WHERE rev.verified_at IS NOT NULL) AS registrations_count,
            (SELECT COUNT(*) FROM registrations r INNER JOIN registration_email_verifications rev ON rev.registration_id = r.id WHERE rev.verified_at IS NOT NULL AND r.attendance_status = 'present') AS present_count,
            (SELECT COUNT(*) FROM registrations r INNER JOIN registration_email_verifications rev ON rev.registration_id = r.id WHERE rev.verified_at IS NOT NULL AND r.attendance_status = 'absent') AS absent_count
    ";
} else {
    $statsSql = "
        SELECT
            (SELECT COUNT(*) FROM events) AS events_count,
            (SELECT COUNT(*) FROM registrations) AS registrations_count,
            (SELECT COUNT(*) FROM registrations WHERE attendance_status = 'present') AS present_count,
            (SELECT COUNT(*) FROM registrations WHERE attendance_status = 'absent') AS absent_count
    ";
}
$stats = db()->query($statsSql)->fetch_assoc();

if (defined('OTP_FLOW_ENABLED') && OTP_FLOW_ENABLED) {
    $eventsSql = "
        SELECT e.id, e.title, e.event_date, e.registration_open,
               SUM(CASE WHEN rev.verified_at IS NOT NULL THEN 1 ELSE 0 END) AS total_registrations,
               SUM(CASE WHEN rev.verified_at IS NOT NULL AND r.attendance_status = 'present' THEN 1 ELSE 0 END) AS total_present,
               SUM(CASE WHEN rev.verified_at IS NOT NULL AND r.attendance_status = 'absent' THEN 1 ELSE 0 END) AS total_absent
        FROM events e
        LEFT JOIN registrations r ON r.event_id = e.id
        LEFT JOIN registration_email_verifications rev ON rev.registration_id = r.id
        GROUP BY e.id, e.title, e.event_date, e.registration_open
        ORDER BY e.event_date DESC
    ";
} else {
    $eventsSql = "
        SELECT e.id, e.title, e.event_date, e.registration_open,
               COUNT(r.id) AS total_registrations,
               SUM(CASE WHEN r.attendance_status = 'present' THEN 1 ELSE 0 END) AS total_present,
               SUM(CASE WHEN r.attendance_status = 'absent' THEN 1 ELSE 0 END) AS total_absent
        FROM events e
        LEFT JOIN registrations r ON r.event_id = e.id
        GROUP BY e.id, e.title, e.event_date, e.registration_open
        ORDER BY e.event_date DESC
    ";
}
$eventsStmt = db()->query($eventsSql);
$events = $eventsStmt->fetch_all(MYSQLI_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard | Attendance QR</title>
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/christ-theme.css')) ?>">
</head>
<body>
<header class="topbar">
    <div class="container">
        <a class="brand" href="<?= e(app_url('admin/dashboard.php')) ?>">Admin Panel</a>
        <nav class="nav-links">
            <a href="<?= e(app_url('admin/events.php')) ?>">Manage Events</a>
            <a href="<?= e(app_url('admin/scan.php')) ?>">Scan QR</a>
            <a href="<?= e(app_url('admin/email.php')) ?>">Email Settings</a>
            <a href="<?= e(app_url()) ?>">Student Site</a>
            <a href="<?= e(app_url('admin/logout.php')) ?>">Logout</a>
        </nav>
    </div>
</header>

<main class="container">
    <section class="hero">
        <h1>Dashboard</h1>
        <p>Logged in as <strong><?= e((string) ($_SESSION['admin_username'] ?? 'Admin')) ?></strong>.</p>
    </section>

    <?php foreach ($flashes as $flash): ?>
        <div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>

    <section class="kpis">
        <article class="kpi">
            <h4>Events</h4>
            <p><?= (int) ($stats['events_count'] ?? 0) ?></p>
        </article>
        <article class="kpi">
            <h4>Registrations</h4>
            <p><?= (int) ($stats['registrations_count'] ?? 0) ?></p>
        </article>
        <article class="kpi">
            <h4>Present</h4>
            <p><?= (int) ($stats['present_count'] ?? 0) ?></p>
        </article>
        <article class="kpi">
            <h4>Absent</h4>
            <p><?= (int) ($stats['absent_count'] ?? 0) ?></p>
        </article>
    </section>

    <section class="form-wrap">
        <h2 class="section-title">Event Attendance Overview</h2>

        <table>
            <thead>
            <tr>
                <th>Event</th>
                <th>Date</th>
                <th>Registration</th>
                <th>Present</th>
                <th>Absent</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if (count($events) === 0): ?>
                <tr>
                    <td colspan="6">No events yet. Create one from Manage Events.</td>
                </tr>
            <?php endif; ?>

            <?php foreach ($events as $event): ?>
                <tr>
                    <td><?= e($event['title']) ?></td>
                    <td><?= e(date('d M Y, h:i A', strtotime((string) $event['event_date']))) ?></td>
                    <td>
                        <?= (int) $event['total_registrations'] ?>
                        <?php if ((int) $event['registration_open'] === 1): ?>
                            <span class="badge present">Open</span>
                        <?php else: ?>
                            <span class="badge absent">Closed</span>
                        <?php endif; ?>
                    </td>
                    <td><?= (int) ($event['total_present'] ?? 0) ?></td>
                    <td><?= (int) ($event['total_absent'] ?? 0) ?></td>
                    <td>
                        <div class="row">
                            <a class="btn outline" href="<?= e(app_url('admin/events.php?edit_id=' . (int) $event['id'])) ?>">Edit</a>
                            <a class="btn outline" href="<?= e(app_url('admin/registrations.php?event_id=' . (int) $event['id'])) ?>">View List</a>
                            <a class="btn outline" href="<?= e(app_url('admin/export_event_excel.php?event_id=' . (int) $event['id'])) ?>">Excel</a>
                            <a class="btn" href="<?= e(app_url('admin/scan.php?event_id=' . (int) $event['id'])) ?>">Scan</a>
                            <form method="post" action="<?= e(app_url('admin/events.php')) ?>" onsubmit="return confirm('Delete this event and all registrations/attendance? This cannot be undone.');">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="delete_event">
                                <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                                <input type="hidden" name="return_to" value="admin/dashboard.php">
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
</body>
</html>
