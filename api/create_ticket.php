<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// Simple helper to create (or fetch existing) ticket for a student + event.
// POST JSON: { student_id: 1, event_id: 2, student_photo_path?: "/attendanceqr/uploads/..." }
// Requires admin session.

require_admin_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response('error', 'METHOD_NOT_ALLOWED', 405);
}

$body = read_json_body();
$studentId = (int) ($body['student_id'] ?? 0);
$eventId = (int) ($body['event_id'] ?? 0);
$photoPath = (string) ($body['student_photo_path'] ?? '');

if ($studentId <= 0 || $eventId <= 0) {
    json_response('error', 'student_id and event_id are required', 400);
}

$ticketCode = strtoupper(substr(bin2hex(random_bytes(8)), 0, 16));

db()->begin_transaction();
try {
    $existing = db()->prepare('SELECT id, ticket_code FROM tickets WHERE student_id = ? AND event_id = ? LIMIT 1');
    $existing->bind_param('ii', $studentId, $eventId);
    $existing->execute();
    $row = $existing->get_result()->fetch_assoc();
    if ($row) {
        db()->commit();
        json_response('ok', 'TICKET_EXISTS', 200, [
            'ticket' => ['id' => (int) $row['id'], 'ticket_code' => (string) $row['ticket_code']],
        ]);
    }

    $insert = db()->prepare(
        'INSERT INTO tickets (ticket_code, student_id, event_id, student_photo_path) VALUES (?, ?, ?, ?)'
    );
    $insert->bind_param('siis', $ticketCode, $studentId, $eventId, $photoPath);
    $insert->execute();

    $id = (int) db()->insert_id;
    db()->commit();
} catch (Throwable $e) {
    db()->rollback();
    json_response('error', 'FAILED_TO_CREATE_TICKET', 500);
}

json_response('ok', 'TICKET_CREATED', 201, [
    'ticket' => ['id' => $id, 'ticket_code' => $ticketCode],
]);

