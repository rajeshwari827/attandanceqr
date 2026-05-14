<?php
declare(strict_types=1);

session_start();

const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'attendanceqr';
const DB_USER = 'root';
const DB_PASS = '';

// Optional manual override. Example: '/attendanceqr'.
// Leave empty to auto-detect from Apache document root.
const APP_BASE_URL = '';

// Feature flag: set to false to disable OTP flow and revert to direct-send.
const OTP_FLOW_ENABLED = true;

// Entry security: require an email OTP at admin check-in before marking attendance.
// This prevents a printed QR from being used without access to the registered email.
const ENTRY_OTP_ENABLED = true;
const ENTRY_OTP_TTL_SECONDS = 300; // 5 minutes
const ENTRY_OTP_RESEND_COOLDOWN_SECONDS = 30;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function db(): mysqli
{
    static $db = null;

    if ($db instanceof mysqli) {
        return $db;
    }

    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    $db->set_charset('utf8mb4');

    return $db;
}

const PERMANENT_ADMIN_USERNAME = 'CHRISTCOLLEGE';
const PERMANENT_ADMIN_PASSWORD = 'christevent';

// QR token signing secret (change in production).
// Keep this value private. Rotating it will invalidate all existing QR codes.
const QR_JWT_SECRET = 'change-me-please-qr-secret';

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string
{
    $b64 = strtr($data, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad !== 0) {
        $b64 .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode($b64, true);
    return $decoded === false ? '' : $decoded;
}

function json_response(string $status, string $message, int $httpCode = 200, array $extra = []): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(
        array_merge(['status' => $status, 'message' => $message], $extra),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

function ensure_admins_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db()->query(
        "CREATE TABLE IF NOT EXISTS admins (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(60) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $done = true;
}

function ensure_permanent_admin(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    ensure_admins_table();

    $username = PERMANENT_ADMIN_USERNAME;
    $password = PERMANENT_ADMIN_PASSWORD;

    $stmt = db()->prepare('SELECT id, password_hash FROM admins WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if (!$row) {
        $insert = db()->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)');
        $insert->bind_param('ss', $username, $passwordHash);
        $insert->execute();
        $done = true;
        return;
    }

    $existingHash = (string) $row['password_hash'];
    if (!password_verify($password, $existingHash)) {
        $id = (int) $row['id'];
        $update = db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
        $update->bind_param('si', $passwordHash, $id);
        $update->execute();
    }

    $done = true;
}

function ensure_qr_entry_tables(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db()->query(
        "CREATE TABLE IF NOT EXISTS tickets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_code CHAR(16) NOT NULL UNIQUE,
            student_id INT UNSIGNED NOT NULL,
            event_id INT UNSIGNED NOT NULL,
            student_photo_path VARCHAR(255) NOT NULL DEFAULT '',
            status ENUM('active', 'cancelled') NOT NULL DEFAULT 'active',
            used_at DATETIME NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_tickets_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
            CONSTRAINT fk_tickets_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
            UNIQUE KEY uq_ticket_student_event (student_id, event_id),
            KEY idx_tickets_event_used (event_id, used_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    db()->query(
        "CREATE TABLE IF NOT EXISTS qr_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            issued_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME NULL DEFAULT NULL,
            used_at DATETIME NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_qr_tokens_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
            KEY idx_qr_tokens_ticket_active (ticket_id, revoked_at, used_at, expires_at),
            KEY idx_qr_tokens_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    db()->query(
        "CREATE TABLE IF NOT EXISTS entry_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT UNSIGNED NOT NULL,
            student_id INT UNSIGNED NOT NULL,
            event_id INT UNSIGNED NOT NULL,
            qr_token_id BIGINT UNSIGNED NULL DEFAULT NULL,
            scanned_at DATETIME NOT NULL,
            result ENUM('accepted', 'rejected_expired', 'rejected_used', 'rejected_invalid') NOT NULL,
            scanner_admin_id INT UNSIGNED NULL DEFAULT NULL,
            scanner_ip VARCHAR(64) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_entry_logs_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
            CONSTRAINT fk_entry_logs_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
            CONSTRAINT fk_entry_logs_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
            CONSTRAINT fk_entry_logs_qr_token FOREIGN KEY (qr_token_id) REFERENCES qr_tokens(id) ON DELETE SET NULL,
            KEY idx_entry_logs_event_time (event_id, scanned_at),
            KEY idx_entry_logs_student_time (student_id, scanned_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $done = true;
}

function now_utc(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function dt_to_sql(DateTimeInterface $dt): string
{
    $utc = (new DateTimeImmutable($dt->format('c')))->setTimezone(new DateTimeZone('UTC'));
    return $utc->format('Y-m-d H:i:s');
}

function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return substr($ip, 0, 64);
}

function qr_sign_token(array $payload): string
{
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $h = base64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
    $p = base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $sig = hash_hmac('sha256', $h . '.' . $p, QR_JWT_SECRET, true);
    return $h . '.' . $p . '.' . base64url_encode($sig);
}

function qr_verify_token(string $token): array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return ['ok' => false, 'error' => 'INVALID_TOKEN', 'payload' => null];
    }

    [$h, $p, $s] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', $h . '.' . $p, QR_JWT_SECRET, true));
    if (!hash_equals($expected, $s)) {
        return ['ok' => false, 'error' => 'INVALID_SIGNATURE', 'payload' => null];
    }

    $payloadJson = base64url_decode($p);
    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'INVALID_PAYLOAD', 'payload' => null];
    }

    return ['ok' => true, 'error' => null, 'payload' => $payload];
}

ensure_permanent_admin();

function app_base_url(): string
{
    static $base = null;

    if ($base !== null) {
        return $base;
    }

    $manual = trim(APP_BASE_URL);
    if ($manual !== '') {
        $manual = '/' . trim(str_replace('\\', '/', $manual), '/');
        $base = $manual === '/' ? '' : $manual;
        return $base;
    }

    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
    $projectRoot = realpath(__DIR__);

    if ($docRoot !== false && $projectRoot !== false) {
        $docNorm = rtrim(str_replace('\\', '/', (string) $docRoot), '/');
        $projNorm = rtrim(str_replace('\\', '/', (string) $projectRoot), '/');

        if ($docNorm !== '' && stripos($projNorm, $docNorm) === 0) {
            $suffix = trim(substr($projNorm, strlen($docNorm)), '/');
            $base = $suffix === '' ? '' : '/' . $suffix;
            return $base;
        }
    }

    $base = '';
    return $base;
}

function app_url(string $path = ''): string
{
    $base = rtrim(app_base_url(), '/');
    $path = ltrim($path, '/');

    if ($base === '') {
        return $path === '' ? '/' : '/' . $path;
    }

    return $path === '' ? $base . '/' : $base . '/' . $path;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . app_url($path));
    exit;
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash_messages'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function get_flashes(): array
{
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);

    return $messages;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf_token(?string $token): bool
{
    if (!isset($_SESSION['csrf_token']) || $token === null) {
        return false;
    }

    return hash_equals((string) $_SESSION['csrf_token'], $token);
}

function is_admin_logged_in(): bool
{
    return isset($_SESSION['admin_id']);
}

function require_admin_login(): void
{
    if (!is_admin_logged_in()) {
        set_flash('error', 'Please log in as admin.');
        redirect('admin/login.php');
    }
}

function set_old_input(array $input): void
{
    $_SESSION['old_input'] = $input;
}

function pull_old_input(): array
{
    $input = $_SESSION['old_input'] ?? [];
    unset($_SESSION['old_input']);

    return is_array($input) ? $input : [];
}

function app_origin(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return '';
    }

    $https = (string) ($_SERVER['HTTPS'] ?? '');
    $scheme = ($https !== '' && strtolower($https) !== 'off') ? 'https' : 'http';

    return $scheme . '://' . $host;
}

function app_full_url(string $path = ''): string
{
    $origin = app_origin();
    if ($origin === '') {
        return app_url($path);
    }

    return rtrim($origin, '/') . app_url($path);
}

function ensure_app_settings_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db()->query(
        "CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(120) NOT NULL PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $done = true;
}

function ensure_registration_email_verifications_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db()->query(
        "CREATE TABLE IF NOT EXISTS registration_email_verifications (
            registration_id INT UNSIGNED NOT NULL PRIMARY KEY,
            verify_token CHAR(64) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            verified_at TIMESTAMP NULL DEFAULT NULL,
            last_sent_at TIMESTAMP NULL DEFAULT NULL,
            CONSTRAINT fk_rev_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_verify_token (verify_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $done = true;
}

function ensure_registration_otps_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db()->query(
        "CREATE TABLE IF NOT EXISTS registration_otps (
            registration_id INT UNSIGNED NOT NULL PRIMARY KEY,
            otp_code CHAR(6) NOT NULL,
            expires_at DATETIME NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_rotp_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $done = true;
}

function ensure_entry_otps_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db()->query(
        "CREATE TABLE IF NOT EXISTS entry_otps (
            registration_id INT UNSIGNED NOT NULL PRIMARY KEY,
            otp_code CHAR(6) NOT NULL,
            expires_at DATETIME NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_sent_at TIMESTAMP NULL DEFAULT NULL,
            CONSTRAINT fk_eotp_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $done = true;
}

function mask_email(string $email): string
{
    $email = trim($email);
    $at = strrpos($email, '@');
    if ($at === false) {
        return $email;
    }

    $local = substr($email, 0, $at);
    $domain = substr($email, $at + 1);

    if ($local === '') {
        return '*@' . $domain;
    }

    if (strlen($local) <= 2) {
        return substr($local, 0, 1) . '*@' . $domain;
    }

    return substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 2)) . substr($local, -1) . '@' . $domain;
}

function ensure_email_verification_for_registration(int $registrationId): array
{
    ensure_registration_email_verifications_table();

    $stmt = db()->prepare(
        'SELECT registration_id, verify_token, verified_at
         FROM registration_email_verifications
         WHERE registration_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $registrationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if ($row) {
        return [
            'token' => (string) $row['verify_token'],
            'verified' => $row['verified_at'] !== null,
        ];
    }

    $token = bin2hex(random_bytes(32));
    $insert = db()->prepare(
        'INSERT INTO registration_email_verifications (registration_id, verify_token) VALUES (?, ?)'
    );
    $insert->bind_param('is', $registrationId, $token);
    $insert->execute();

    return [
        'token' => $token,
        'verified' => false,
    ];
}

function mark_registration_email_verified(int $registrationId, string $token): bool
{
    ensure_registration_email_verifications_table();

    $stmt = db()->prepare(
        'UPDATE registration_email_verifications
         SET verified_at = COALESCE(verified_at, CURRENT_TIMESTAMP)
         WHERE registration_id = ? AND verify_token = ?
         LIMIT 1'
    );
    $stmt->bind_param('is', $registrationId, $token);
    $stmt->execute();

    return $stmt->affected_rows > 0;
}

function is_registration_email_verified(int $registrationId): bool
{
    ensure_registration_email_verifications_table();

    $stmt = db()->prepare(
        'SELECT verified_at FROM registration_email_verifications WHERE registration_id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $registrationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return $row && $row['verified_at'] !== null;
}

function touch_verification_email_sent(int $registrationId): void
{
    ensure_registration_email_verifications_table();
    $stmt = db()->prepare(
        'UPDATE registration_email_verifications SET last_sent_at = CURRENT_TIMESTAMP WHERE registration_id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $registrationId);
    $stmt->execute();
}

function get_setting(string $key, string $default = ''): string
{
    ensure_app_settings_table();

    $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        return $default;
    }

    return (string) $row['setting_value'];
}

function set_setting(string $key, string $value): void
{
    ensure_app_settings_table();

    $stmt = db()->prepare(
        'INSERT INTO app_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
}

function mail_config(): array
{
    $enabled = get_setting('mail_enabled', '0') === '1';
    $service = get_setting('mail_service', 'gmail');
    $includeQrImage = get_setting('mail_include_qr_image', '1') === '1';
    $qrProvider = get_setting('mail_qr_provider', 'qrserver');

    $secure = $service === 'gmail' ? 'tls' : get_setting('mail_secure', 'tls');
    $host = $service === 'gmail' ? 'smtp.gmail.com' : trim(get_setting('mail_host', ''));
    $port = $service === 'gmail' ? 587 : (int) get_setting('mail_port', '587');

    $fromEmail = trim(get_setting('mail_from_email', ''));
    if ($fromEmail === '') {
        $fromEmail = trim(get_setting('mail_user', ''));
    }

    $fromName = get_setting('mail_from_name', 'Attendance QR');

    return [
        'enabled' => $enabled,
        'service' => $service,
        'include_qr_image' => $includeQrImage,
        'qr_provider' => $qrProvider,
        'secure' => $secure,
        'host' => $host,
        'port' => $port,
        'user' => trim(get_setting('mail_user', '')),
        'pass' => (string) get_setting('mail_pass', ''),
        'from_email' => $fromEmail,
        'from_name' => $fromName,
        'verify_subject' => get_setting('mail_verify_subject', 'Confirm your email for {event_title}'),
        'verify_message' => get_setting(
            'mail_verify_message',
            "Hi {name},\n\nPlease confirm your email to receive your receipt and QR:\n{verify_link}\n\nIf you didn't request this, ignore this email.\n\nThanks,\n{app_name}"
        ),
        'receipt_subject' => get_setting('mail_receipt_subject', 'Your receipt & QR for {event_title}'),
        'receipt_message' => get_setting(
            'mail_receipt_message',
            "Hi {name},\n\nYour registration receipt for {event_title}:\n\nQR Pass: {qr_link}\nToken: {qr_token}\n\nThanks,\n{app_name}"
        ),
    ];
}

function qr_image_url(string $payload): string
{
    $provider = get_setting('mail_qr_provider', 'qrserver');

    if ($provider === 'qrserver') {
        return 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . rawurlencode($payload);
    }

    // Default fallback.
    return 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . rawurlencode($payload);
}

function send_app_mail(string $toEmail, string $subject, string $htmlBody, string $textBody = ''): void
{
    $cfg = mail_config();
    if (!$cfg['enabled']) {
        throw new RuntimeException('Email is disabled in settings.');
    }

    $host = (string) $cfg['host'];
    $user = (string) $cfg['user'];
    $pass = (string) $cfg['pass'];
    $fromEmail = (string) $cfg['from_email'];

    if ($host === '' || $fromEmail === '') {
        throw new RuntimeException('Email host/from is not configured.');
    }

    require_once __DIR__ . '/lib/smtp_mailer.php';
    $mailer = new SmtpMailer($host, (int) $cfg['port'], (string) $cfg['secure']);
    $mailer->send(
        $user,
        $pass,
        $fromEmail,
        (string) $cfg['from_name'],
        $toEmail,
        $subject,
        $htmlBody,
        $textBody
    );
}
