<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function short_text(string $text, int $length = 165): string
{
    if (strlen($text) <= $length) {
        return $text;
    }

    return rtrim(substr($text, 0, $length - 3)) . '...';
}

$eventsStmt = db()->query(
    'SELECT id, title, description, event_date, venue, registration_open,
            registration_mode, team_min_members, team_max_members,
            payment_required, payment_amount,
            flyer_path
     FROM events
     ORDER BY event_date ASC'
);
$events = $eventsStmt->fetch_all(MYSQLI_ASSOC);
$flashes = get_flashes();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Event Attendance QR System</title>
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/christ-theme.css')) ?>">
</head>
<body>
<header class="topbar">
    <div class="container">
        <a class="brand" href="<?= e(app_url()) ?>">Attendance QR</a>
        <nav class="nav-links">
            <a href="<?= e(app_url()) ?>">Events</a>
            <a class="nav-btn" href="<?= e(app_url('admin/login.php')) ?>">Admin Login</a>
        </nav>
    </div>
</header>

<main class="container">
    <section class="hero">
        <h1>Student Event Registration</h1>
        <p>Choose events, view flyer + rules/schedule PDFs, register, and get your QR pass.</p>
    </section>

    <?php foreach ($flashes as $flash): ?>
        <div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>

    <section class="grid events">
        <?php if (count($events) === 0): ?>
            <div class="card">
                <h3>No events available</h3>
                <p class="muted">Admin has not created any events yet.</p>
            </div>
        <?php endif; ?>

        <?php foreach ($events as $event): ?>
            <article class="card">
                <?php if ((string) $event['flyer_path'] !== ''): ?>
                    <img
                        src="<?= e(app_url((string) $event['flyer_path'])) ?>"
                        alt="Event Flyer"
                        style="width: 100%; height: 170px; object-fit: cover; border-radius: 10px; border: 1px solid #cbd5e1; margin-bottom: 0.7rem;"
                    >
                <?php endif; ?>

                <h3><?= e($event['title']) ?></h3>
                <p class="meta">
                    <?= e(date('d M Y, h:i A', strtotime((string) $event['event_date']))) ?>
                    <br>
                    Venue: <?= e($event['venue']) ?>
                </p>
                <p><?= e(short_text((string) $event['description'])) ?></p>

                <p class="small" style="margin-bottom: 0.6rem;">
                    <strong>Mode:</strong>
                    <?php if ($event['registration_mode'] === 'solo'): ?>
                        Solo
                    <?php elseif ($event['registration_mode'] === 'team'): ?>
                        Team (<?= (int) $event['team_min_members'] ?>-<?= (int) $event['team_max_members'] ?>)
                    <?php else: ?>
                        Solo/Team
                    <?php endif; ?>
                    <br>
                    <strong>Payment:</strong>
                    <?php if ((int) $event['payment_required'] === 1): ?>
                        Required (<?= e(number_format((float) $event['payment_amount'], 2)) ?>)
                    <?php else: ?>
                        Not required
                    <?php endif; ?>
                </p>

                <div class="row">
                    <a class="btn" href="<?= e(app_url('event.php?id=' . (int) $event['id'])) ?>">View Details</a>
                    <?php if ((int) $event['registration_open'] === 1): ?>
                        <span class="badge present">Registration Open</span>
                    <?php else: ?>
                        <span class="badge absent">Registration Closed</span>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
    <div class="footer-gap"></div>
</main>
</body>
</html>
