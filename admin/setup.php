<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

if (is_admin_logged_in()) {
    redirect('admin/dashboard.php');
}

$adminCount = (int) db()->query('SELECT COUNT(*) AS c FROM admins')->fetch_assoc()['c'];
$flashes = get_flashes();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid form token.');
        redirect('admin/setup.php');
    }

    if ($adminCount > 0) {
        set_flash('error', 'An admin account already exists. Please log in.');
        redirect('admin/login.php');
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($username === '' || $password === '' || $confirmPassword === '') {
        set_flash('error', 'All fields are required.');
        redirect('admin/setup.php');
    }

    if (strlen($username) < 3) {
        set_flash('error', 'Username must be at least 3 characters.');
        redirect('admin/setup.php');
    }

    if (strlen($password) < 6) {
        set_flash('error', 'Password must be at least 6 characters.');
        redirect('admin/setup.php');
    }

    if ($password !== $confirmPassword) {
        set_flash('error', 'Password confirmation does not match.');
        redirect('admin/setup.php');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $insertStmt = db()->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)');
    $insertStmt->bind_param('ss', $username, $passwordHash);
    $insertStmt->execute();

    set_flash('success', 'Admin account created. Please sign in.');
    redirect('admin/login.php');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Setup | Attendance QR</title>
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/christ-theme.css')) ?>">
</head>
<body>
<header class="topbar">
    <div class="container">
        <a class="brand" href="<?= e(app_url()) ?>">Attendance QR</a>
        <nav class="nav-links">
            <a href="<?= e(app_url()) ?>">Student Site</a>
            <a href="<?= e(app_url('admin/login.php')) ?>">Admin Login</a>
        </nav>
    </div>
</header>

<main class="container" style="max-width: 720px;">
    <section class="hero">
        <h1>Admin First-Time Setup</h1>
        <p>Create the initial admin account.</p>
    </section>

    <?php foreach ($flashes as $flash): ?>
        <div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>

    <?php if ($adminCount > 0): ?>
        <div class="form-wrap">
            <p>An admin account already exists.</p>
            <a class="btn" href="<?= e(app_url('admin/login.php')) ?>">Go to Login</a>
        </div>
    <?php else: ?>
        <form class="form-wrap" method="post" action="<?= e(app_url('admin/setup.php')) ?>">
            <?= csrf_input() ?>
            <div class="field">
                <label for="username">Username</label>
                <input id="username" name="username" maxlength="60" required>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" minlength="6" required>
            </div>

            <div class="field">
                <label for="confirm_password">Confirm Password</label>
                <input id="confirm_password" name="confirm_password" type="password" minlength="6" required>
            </div>

            <button class="btn" type="submit">Create Admin</button>
        </form>
    <?php endif; ?>

    <div class="footer-gap"></div>
</main>
</body>
</html>
