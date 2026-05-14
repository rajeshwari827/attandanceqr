# QR Entry Verification (React + Vite)

## Dev setup

1. Install deps:
   - `cd frontend`
   - `npm install`
2. Start Vite:
   - `npm run dev`

By default, the frontend calls the PHP backend at `/attendanceqr/api`.

## Routes

- `/scanner` — security staff dashboard (camera scanner)
- `/pass?ticket_id=123&student_id=45` — student live QR pass (refreshes every 30s)

## Backend endpoints used

- `GET /attendanceqr/api/issue_qr.php?ticket_id=...&student_id=...`
- `POST /attendanceqr/api/validate_qr.php` (admin session required)
- `POST /attendanceqr/api/confirm_entry.php` (admin session required)

