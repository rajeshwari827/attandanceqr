<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

if (is_admin_logged_in()) {
    redirect('admin/dashboard.php');
}

$adminCount = (int) db()->query('SELECT COUNT(*) AS c FROM admins')->fetch_assoc()['c'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid form token.');
        redirect('admin/login.php');
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $adminStmt = db()->prepare('SELECT id, username, password_hash FROM admins WHERE username = ? LIMIT 1');
    $adminStmt->bind_param('s', $username);
    $adminStmt->execute();
    $admin = $adminStmt->get_result()->fetch_assoc();

    if ($admin && password_verify($password, (string) $admin['password_hash'])) {
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['admin_username'] = (string) $admin['username'];

        set_flash('success', 'Welcome, ' . $admin['username'] . '.');
        redirect('admin/dashboard.php');
    }

    set_flash('error', 'Invalid username or password.');
    redirect('admin/login.php');
}

$flashes = get_flashes();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login | Attendance QR</title>
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/christ-theme.css')) ?>">
</head>
<body>
<header class="topbar">
    <div class="container">
        <a class="brand" href="<?= e(app_url()) ?>">Attendance QR</a>
        <nav class="nav-links">
            <a href="<?= e(app_url()) ?>">Student Site</a>
            <?php if ($adminCount === 0): ?>
                <a href="<?= e(app_url('admin/setup.php')) ?>">Admin Setup</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main class="container" style="max-width: 720px;">
    <section class="hero">
        <h1>Admin Login</h1>
        <p>Sign in to create events, scan QR codes, and view attendance.</p>
    </section>

    <?php foreach ($flashes as $flash): ?>
        <div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>

    <?php if ($adminCount === 0): ?>
        <div class="alert info">
            No admin account exists yet.
            <a href="<?= e(app_url('admin/setup.php')) ?>"><strong>Create one now</strong></a>.
        </div>
    <?php endif; ?>

    <form class="form-wrap" method="post" action="<?= e(app_url('admin/login.php')) ?>">
        <?= csrf_input() ?>

        <div class="field">
            <label for="username">Username</label>
            <input id="username" name="username" maxlength="60" required>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" required>
        </div>

        <button class="btn" type="submit">Login</button>
    </form>

    <div class="footer-gap"></div>
</main>
</body>
</html>
