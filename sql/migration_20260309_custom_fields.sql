USE attendanceqr;

ALTER TABLE events
    ADD COLUMN IF NOT EXISTS registration_mode ENUM('solo', 'team', 'both') NOT NULL DEFAULT 'solo' AFTER venue,
    ADD COLUMN IF NOT EXISTS team_min_members TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER registration_mode,
    ADD COLUMN IF NOT EXISTS team_max_members TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER team_min_members,
    ADD COLUMN IF NOT EXISTS payment_required TINYINT(1) NOT NULL DEFAULT 0 AFTER team_max_members,
    ADD COLUMN IF NOT EXISTS payment_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER payment_required,
    ADD COLUMN IF NOT EXISTS payment_note TEXT NULL AFTER payment_amount,
    ADD COLUMN IF NOT EXISTS flyer_path VARCHAR(255) NULL AFTER payment_note,
    ADD COLUMN IF NOT EXISTS rules_pdf_path VARCHAR(255) NULL AFTER flyer_path,
    ADD COLUMN IF NOT EXISTS schedule_pdf_path VARCHAR(255) NULL AFTER rules_pdf_path;

ALTER TABLE registrations
    ADD COLUMN IF NOT EXISTS registration_type ENUM('solo', 'team') NOT NULL DEFAULT 'solo' AFTER qr_token,
    ADD COLUMN IF NOT EXISTS team_name VARCHAR(160) NULL AFTER registration_type,
    ADD COLUMN IF NOT EXISTS team_size TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER team_name,
    ADD COLUMN IF NOT EXISTS payment_status ENUM('not_required', 'pending', 'paid') NOT NULL DEFAULT 'not_required' AFTER team_size,
    ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(120) NULL AFTER payment_status;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'registrations'
      AND index_name = 'idx_registrations_payment_status'
);
SET @idx_sql := IF(@idx_exists = 0,
    'ALTER TABLE registrations ADD INDEX idx_registrations_payment_status (event_id, payment_status)',
    'SELECT 1'
);
PREPARE idx_stmt FROM @idx_sql;
EXECUTE idx_stmt;
DEALLOCATE PREPARE idx_stmt;

CREATE TABLE IF NOT EXISTS event_form_fields (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    field_label VARCHAR(120) NOT NULL,
    field_type ENUM('text', 'textarea', 'number', 'email', 'tel', 'select', 'date') NOT NULL DEFAULT 'text',
    placeholder VARCHAR(150) NOT NULL DEFAULT '',
    option_values TEXT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_event_form_fields_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    KEY idx_event_form_fields_event (event_id, sort_order)
);

CREATE TABLE IF NOT EXISTS registration_field_values (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_id INT UNSIGNED NOT NULL,
    event_field_id INT UNSIGNED NOT NULL,
    field_value TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_registration_field_values_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
    CONSTRAINT fk_registration_field_values_field FOREIGN KEY (event_field_id) REFERENCES event_form_fields(id) ON DELETE CASCADE,
    UNIQUE KEY uq_registration_field (registration_id, event_field_id)
);