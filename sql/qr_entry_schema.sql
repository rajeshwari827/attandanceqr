-- QR-based Event Entry Verification System schema
-- Database: attendanceqr (matches existing project config.php)

CREATE TABLE IF NOT EXISTS tickets (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qr_tokens (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS entry_logs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
