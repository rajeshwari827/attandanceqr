CREATE DATABASE IF NOT EXISTS attendanceqr CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE attendanceqr;

CREATE TABLE IF NOT EXISTS admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(60) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    rules TEXT NOT NULL,
    event_date DATETIME NOT NULL,
    venue VARCHAR(200) NOT NULL,
    registration_mode ENUM('solo', 'team', 'both') NOT NULL DEFAULT 'solo',
    team_min_members TINYINT UNSIGNED NOT NULL DEFAULT 2,
    team_max_members TINYINT UNSIGNED NOT NULL DEFAULT 5,
    payment_required TINYINT(1) NOT NULL DEFAULT 0,
    payment_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_note TEXT NULL,
    flyer_path VARCHAR(255) NULL,
    rules_pdf_path VARCHAR(255) NULL,
    schedule_pdf_path VARCHAR(255) NULL,
    registration_open TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS students (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    roll_no VARCHAR(60) NOT NULL UNIQUE,
    email VARCHAR(120) NOT NULL,
    phone VARCHAR(25) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_students_email (email)
);

CREATE TABLE IF NOT EXISTS registrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    qr_token CHAR(32) NOT NULL UNIQUE,
    registration_type ENUM('solo', 'team') NOT NULL DEFAULT 'solo',
    team_name VARCHAR(160) NULL,
    team_size TINYINT UNSIGNED NOT NULL DEFAULT 1,
    payment_status ENUM('not_required', 'pending', 'paid') NOT NULL DEFAULT 'not_required',
    payment_reference VARCHAR(120) NULL,
    attendance_status ENUM('absent', 'present') NOT NULL DEFAULT 'absent',
    attendance_marked_at DATETIME NULL,
    registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_registrations_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_registrations_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    UNIQUE KEY uq_event_student (event_id, student_id),
    KEY idx_registrations_event_status (event_id, attendance_status),
    KEY idx_registrations_payment_status (event_id, payment_status)
);

CREATE TABLE IF NOT EXISTS event_form_fields (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    field_label VARCHAR(120) NOT NULL,
    field_type ENUM('text', 'textarea', 'number', 'email', 'tel', 'select', 'date', 'roll', 'photo', 'video') NOT NULL DEFAULT 'text',
    placeholder VARCHAR(150) NOT NULL DEFAULT '',
    option_values TEXT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT UNSIGNED NOT NULL DEFAULT 1,
    min_length SMALLINT UNSIGNED NULL DEFAULT NULL,
    max_length SMALLINT UNSIGNED NULL DEFAULT NULL,
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
