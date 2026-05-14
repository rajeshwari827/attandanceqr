<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

ensure_qr_entry_tables();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function require_admin_api(): void
{
    // Reuse existing admin session-based auth from the PHP app.
    if (!is_admin_logged_in()) {
        json_response('error', 'ADMIN_AUTH_REQUIRED', 401);
    }
}

function token_hash(string $token): string
{
    return hash('sha256', $token);
}

