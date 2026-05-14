<<<<<<< HEAD

=======
# Attendance QR (PHP + MySQL)

Event attendance system with student registration, QR-based check-in, team/solo mode, payments, custom fields, and event media uploads.

## Features

- Admin can create events with:
  - Solo only, Team only, or Solo + Team registration mode
  - Team min/max members
  - Payment required option and amount/instructions
  - Flyer/event icon image upload
  - Rules PDF upload
  - Schedule PDF upload
  - Event-specific custom registration fields
- Students can:
  - View event details, flyer image, rules PDF, schedule PDF
  - Register for any open event
  - Choose solo/team (based on event settings)
  - Submit payment reference when payment is required
  - Receive a QR pass generated offline (local JS library)
- Admin can:
  - Scan QR offline (local JS scanner, no CDN)
  - Auto-mark attendance in DB
  - View present/absent lists
  - View team info, payment status, custom fields
  - Mark payment as paid/pending

## Tech Stack

- PHP (mysqli, sessions)
- MySQL / MariaDB
- HTML/CSS + Vanilla JS
- Local QR libs:
  - `assets/js/qrcode.min.js`
  - `assets/js/jsQR.js`

## Project Structure

- `index.php`: student event listing
- `event.php`: event details + registration form + PDFs
- `register.php`: registration backend
- `registration_success.php`: QR pass page
- `admin/events.php`: event creation (mode, payment, uploads, custom fields)
- `admin/scan.php`: offline camera QR scanner
- `admin/scan_attendance.php`: attendance API endpoint
- `admin/registrations.php`: attendance/payment/custom-field admin view
- `sql/schema.sql`: full schema for fresh install
- `sql/migration_20260309_custom_fields.sql`: migration for existing DB

## XAMPP Setup

1. Start `Apache` and `MySQL` in XAMPP.
2. Put project in:
   - `C:\xampp\htdocs\attendanceqr`
3. Open phpMyAdmin:
   - `http://localhost/phpmyadmin`

### Fresh install

- Create DB `attendanceqr`
- Import:
  - `sql/schema.sql`

### Existing install (already using old DB)

- Keep your existing `attendanceqr` DB
- Import migration:
  - `sql/migration_20260309_custom_fields.sql`

## Config

Edit `config.php`:

- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- Optional `APP_BASE_URL` (example: `/attendanceqr`)

## QR Event Entry Verification (React + Vite + PHP API)

This repo also includes a secure, time-limited, rotating QR entry system:

- PHP REST API endpoints: `api/`
- React + Vite frontend (scanner + student pass): `frontend/`
- MySQL schema (tables for `tickets`, `qr_tokens`, `entry_logs`): `sql/qr_entry_schema.sql`

### Endpoints

- `GET /attendanceqr/api/issue_qr.php?ticket_id=...&student_id=...` (student pass; revokes old token immediately)
- `POST /attendanceqr/api/validate_qr.php` (admin session required; returns student + event details, or `QR_EXPIRED` / `ENTRY_ALREADY_USED`)
- `POST /attendanceqr/api/confirm_entry.php` (admin session required; marks entry USED + logs)
- `POST /attendanceqr/api/create_ticket.php` (admin helper; creates a ticket for student+event)

### Security model (core)

- QR codes are signed (HMAC) and always validated on the backend.
- Tokens refresh every 30 seconds; old tokens become invalid immediately (anti-screenshot/print).
- One-time entry: once accepted, re-scan returns `ENTRY_ALREADY_USED`.

## Run

- Student side: `http://localhost/attendanceqr/`
- Admin setup (first time): `http://localhost/attendanceqr/admin/setup.php`
- Admin login: `http://localhost/attendanceqr/admin/login.php`

## Uploads

Event uploads are stored in:

- `uploads/events/images`
- `uploads/events/docs`

Ensure web server can write to `uploads/`.
>>>>>>> 731631b (College system)
