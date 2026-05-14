<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function parse_option_lines(string $raw): array
{
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $options = [];

    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }

        $options[] = $line;
    }

    return $options;
}

$eventId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($eventId <= 0) {
    set_flash('error', 'Invalid event selected.');
    redirect('index.php');
}

$eventStmt = db()->prepare(
    'SELECT id, title, description, rules, event_date, venue, registration_open,
            registration_mode, team_min_members, team_max_members,
            payment_required, payment_amount, payment_note,
            flyer_path, rules_pdf_path, schedule_pdf_path
     FROM events
     WHERE id = ?'
);
$eventStmt->bind_param('i', $eventId);
$eventStmt->execute();
$event = $eventStmt->get_result()->fetch_assoc();

if (!$event) {
    set_flash('error', 'Event not found.');
    redirect('index.php');
}

$customFields = [];
try {
    $customFieldsStmt = db()->prepare(
        'SELECT id, field_label, field_type, placeholder, option_values, is_required, min_length, max_length
         FROM event_form_fields
         WHERE event_id = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $customFieldsStmt->bind_param('i', $eventId);
    $customFieldsStmt->execute();
    $customFields = $customFieldsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $ignored) {
    $customFields = [];
}

$flashes = get_flashes();
$old = pull_old_input();
$isTeamAllowed = in_array((string) $event['registration_mode'], ['team', 'both'], true);
$mailCfg = mail_config();
$requireEmailConfirm = (bool) ($mailCfg['enabled'] ?? false);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($event['title']) ?> | Attendance QR</title>
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/christ-theme.css')) ?>">
</head>
<body>
<header class="topbar">
    <div class="container">
        <a class="brand" href="<?= e(app_url()) ?>">Attendance QR</a>
        <nav class="nav-links">
            <a href="<?= e(app_url()) ?>">All Events</a>
            <a class="nav-btn" href="<?= e(app_url('admin/login.php')) ?>">Admin Login</a>
        </nav>
    </div>
</header>

<main class="container">
    <section class="hero">
        <h1><?= e($event['title']) ?></h1>
        <p>
            <?= e(date('d M Y, h:i A', strtotime((string) $event['event_date']))) ?>
            | Venue: <?= e($event['venue']) ?>
        </p>
    </section>

    <?php foreach ($flashes as $flash): ?>
        <div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>

    <!-- Popup modal for error alerts -->
    <div id="alertModal" class="popup-modal" style="display:none;">
        <div class="popup-modal__box">
            <h3 class="popup-modal__title">Alert</h3>
            <p id="alertModalMsg" class="popup-modal__msg"></p>
            <button id="alertModalClose" type="button" class="btn" style="width:100%;">OK</button>
        </div>
    </div>
    <style>
        .popup-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(0,0,0,0.45);
            z-index: 9999;
            padding: 1rem;
        }
        .popup-modal__box {
            background: #fff;
            border-radius: 12px;
            max-width: 420px;
            width: 100%;
            box-shadow: 0 12px 30px rgba(0,0,0,0.2);
            padding: 1.25rem;
        }
        .popup-modal__title {
            margin: 0 0 0.5rem;
            font-size: 1.1rem;
            color: #0f172a;
        }
        .popup-modal__msg {
            margin: 0 0 1rem;
            color: #475569;
            line-height: 1.5;
        }
    </style>

    <section class="form-wrap">
        <?php if ((string) $event['flyer_path'] !== ''): ?>
            <div style="margin-bottom: 1rem; text-align: center;">
                <img
                    src="<?= e(app_url((string) $event['flyer_path'])) ?>"
                    alt="Event Flyer"
                    style="max-width: min(420px, 100%); border-radius: 12px; border: 1px solid #cbd5e1;"
                >
            </div>
        <?php endif; ?>

        <h2 class="section-title">Event Details</h2>
        <p><?= nl2br(e((string) $event['description'])) ?></p>

        <h3>Rules Summary</h3>
        <p><?= nl2br(e((string) $event['rules'])) ?></p>

        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); margin-top: 1rem;">
            <div class="card">
                <h3>Registration Mode</h3>
                <p>
                    <?php if ($event['registration_mode'] === 'solo'): ?>
                        Solo Only
                    <?php elseif ($event['registration_mode'] === 'team'): ?>
                        Team Only (<?= (int) $event['team_min_members'] ?>-<?= (int) $event['team_max_members'] ?> members)
                    <?php else: ?>
                        Solo/Team (<?= (int) $event['team_min_members'] ?>-<?= (int) $event['team_max_members'] ?> members for teams)
                    <?php endif; ?>
                </p>
            </div>
            <div class="card">
                <h3>Payment</h3>
                <?php if ((int) $event['payment_required'] === 1): ?>
                    <p>Required: <?= e(number_format((float) $event['payment_amount'], 2)) ?></p>
                    <?php if ((string) $event['payment_note'] !== ''): ?>
                        <p class="small"><?= nl2br(e((string) $event['payment_note'])) ?></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p>Not required</p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="form-wrap">
        <h2 class="section-title">Rules & Schedule PDFs</h2>

        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
            <article class="card">
                <h3>Rules PDF</h3>
                <?php if ((string) $event['rules_pdf_path'] !== ''): ?>
                    <p><a class="btn outline" href="<?= e(app_url((string) $event['rules_pdf_path'])) ?>" target="_blank" rel="noopener">Open Rules PDF</a></p>
                    <iframe
                        src="<?= e(app_url((string) $event['rules_pdf_path'])) ?>"
                        title="Rules PDF"
                        style="width: 100%; min-height: 360px; border: 1px solid #cbd5e1; border-radius: 8px;"
                    ></iframe>
                <?php else: ?>
                    <p class="muted">Rules PDF not uploaded for this event.</p>
                <?php endif; ?>
            </article>

            <article class="card">
                <h3>Schedule PDF</h3>
                <?php if ((string) $event['schedule_pdf_path'] !== ''): ?>
                    <p><a class="btn outline" href="<?= e(app_url((string) $event['schedule_pdf_path'])) ?>" target="_blank" rel="noopener">Open Schedule PDF</a></p>
                    <iframe
                        src="<?= e(app_url((string) $event['schedule_pdf_path'])) ?>"
                        title="Schedule PDF"
                        style="width: 100%; min-height: 360px; border: 1px solid #cbd5e1; border-radius: 8px;"
                    ></iframe>
                <?php else: ?>
                    <p class="muted">Schedule PDF not uploaded for this event.</p>
                <?php endif; ?>
            </article>
        </div>
    </section>

    <section class="form-wrap">
        <h2 class="section-title">Register for this Event</h2>

        <?php if ((int) $event['registration_open'] !== 1): ?>
            <div class="alert error">Registration for this event is currently closed.</div>
        <?php else: ?>
            <?php
            $oldRegistrationType = (string) ($old['registration_type'] ?? 'solo');
            if ($event['registration_mode'] === 'team') {
                $oldRegistrationType = 'team';
            }
            if ($event['registration_mode'] === 'solo') {
                $oldRegistrationType = 'solo';
            }

            $teamMinMembers = (int) $event['team_min_members'];
            $teamMaxMembers = (int) $event['team_max_members'];
            $oldTeamSizeRaw = trim((string) ($old['team_size'] ?? ''));
            $oldTeamSize = $oldTeamSizeRaw === '' ? $teamMinMembers : (int) $oldTeamSizeRaw;
            if ($oldTeamSize < $teamMinMembers) {
                $oldTeamSize = $teamMinMembers;
            }
            if ($oldTeamSize > $teamMaxMembers) {
                $oldTeamSize = $teamMaxMembers;
            }
            ?>

            <form id="registrationForm" method="post" action="<?= e(app_url('register.php')) ?>" autocomplete="on" enctype="multipart/form-data">
                <?= csrf_input() ?>
                <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">

                <div class="form-section">
                    <h3 class="form-section-title">Your Details</h3>

                    <div class="form-grid">
                        <div class="field">
                            <label for="full_name">Full Name <span class="req" aria-hidden="true">*</span></label>
                            <input
                                id="full_name"
                                name="full_name"
                                type="text"
                                maxlength="120"
                                placeholder="e.g., Priya Sharma"
                                value="<?= e((string) ($old['full_name'] ?? '')) ?>"
                                autocomplete="name"
                                aria-describedby="full_name_hint"
                                required
                            >
                            <p class="hint" id="full_name_hint">Use your official name for certificates.</p>
                        </div>

                <div class="field">
                    <label for="email">Email <span class="req" aria-hidden="true">*</span></label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        maxlength="120"
                        placeholder="you@example.com"
                        value="<?= e((string) ($old['email'] ?? '')) ?>"
                        autocomplete="email"
                        required
                    >
                </div>

                <!-- Confirm Email field removed per requirement -->

                        <div class="field">
                            <label for="phone">Phone <span class="req" aria-hidden="true">*</span></label>
                            <input
                                id="phone"
                                name="phone"
                                type="tel"
                                inputmode="tel"
                                maxlength="25"
                                placeholder="e.g., +91 98765 43210"
                                value="<?= e((string) ($old['phone'] ?? '')) ?>"
                                autocomplete="tel"
                                pattern="^[0-9+() -]{7,25}$"
                                aria-describedby="phone_hint"
                                required
                            >
                            <p class="hint" id="phone_hint">Digits, spaces, +, -, () allowed.</p>
                        </div>
                    </div>
                </div>

                <?php if ($event['registration_mode'] === 'both'): ?>
                    <div class="field">
                        <label for="registration_type">Registration Type <span class="req" aria-hidden="true">*</span></label>
                        <select id="registration_type" name="registration_type" required>
                            <option value="solo" <?= $oldRegistrationType === 'solo' ? 'selected' : '' ?>>Solo</option>
                            <option value="team" <?= $oldRegistrationType === 'team' ? 'selected' : '' ?>>Team</option>
                        </select>
                        <p class="hint">Choose team only if you’re participating with other members.</p>
                    </div>
                <?php elseif ($event['registration_mode'] === 'team'): ?>
                    <input type="hidden" id="registration_type" name="registration_type" value="team">
                <?php else: ?>
                    <input type="hidden" id="registration_type" name="registration_type" value="solo">
                <?php endif; ?>

                <?php if ($isTeamAllowed): ?>
                    <div id="teamFieldsWrap" class="form-wrap" style="margin-bottom: 1rem;">
                        <h3 style="margin-top: 0;">Team Details</h3>

                        <div class="field">
                            <label for="team_name">Team Name <span class="req" aria-hidden="true">*</span></label>
                            <input
                                id="team_name"
                                name="team_name"
                                type="text"
                                maxlength="160"
                                placeholder="Enter team name"
                                value="<?= e((string) ($old['team_name'] ?? '')) ?>"
                                autocomplete="organization"
                            >
                        </div>

                        <div class="field">
                            <label for="team_size">Number of Members</label>
                            <input
                                id="team_size"
                                name="team_size"
                                type="number"
                                min="<?= (int) $event['team_min_members'] ?>"
                                max="<?= (int) $event['team_max_members'] ?>"
                                value="<?= (int) $oldTeamSize ?>"
                            >
                            <p class="small">Allowed range: <?= (int) $event['team_min_members'] ?> - <?= (int) $event['team_max_members'] ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ((int) $event['payment_required'] === 1): ?>
                    <div class="form-wrap" style="margin-bottom: 1rem;">
                        <h3 style="margin-top: 0;">Payment Details</h3>
                        <p><strong>Amount:</strong> <?= e(number_format((float) $event['payment_amount'], 2)) ?></p>
                        <?php if ((string) $event['payment_note'] !== ''): ?>
                            <p><?= nl2br(e((string) $event['payment_note'])) ?></p>
                        <?php endif; ?>

                        <div class="field">
                            <label for="payment_reference">Payment Reference / Transaction ID <span class="req" aria-hidden="true">*</span></label>
                            <input
                                id="payment_reference"
                                name="payment_reference"
                                maxlength="120"
                                placeholder="Enter transaction ID"
                                value="<?= e((string) ($old['payment_reference'] ?? '')) ?>"
                                aria-describedby="payment_reference_hint"
                                required
                            >
                            <p class="hint" id="payment_reference_hint">Paste the exact ID shown in your payment app.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php foreach ($customFields as $field): ?>
                    <?php
                    $fieldId = (int) $field['id'];
                    $fieldLabel = (string) $field['field_label'];
                    $fieldType = (string) $field['field_type'];
        $placeholder = (string) $field['placeholder'];
        $isRequired = (int) $field['is_required'] === 1;
        $minLen = isset($field['min_length']) ? (int) $field['min_length'] : null;
        $maxLen = isset($field['max_length']) ? (int) $field['max_length'] : null;
        $inputName = 'custom_field_' . $fieldId;
                    ?>
                    <div class="field">
                        <label for="<?= e($inputName) ?>">
                            <?= e($fieldLabel) ?>
                            <?php if ($isRequired): ?>
                                <span style="color: #b91c1c;">*</span>
                            <?php endif; ?>
                        </label>

                        <?php if ($fieldType === 'textarea'): ?>
                            <textarea
                                id="<?= e($inputName) ?>"
                                name="<?= e($inputName) ?>"
                                placeholder="<?= e($placeholder) ?>"
                                <?= $minLen !== null ? 'minlength="' . (int) $minLen . '"' : '' ?>
                                <?= $maxLen !== null ? 'maxlength="' . (int) $maxLen . '"' : '' ?>
                                <?= $isRequired ? 'required' : '' ?>
                            ><?= e((string) ($old[$inputName] ?? '')) ?></textarea>
                        <?php elseif ($fieldType === 'select'): ?>
                            <?php $options = parse_option_lines((string) $field['option_values']); ?>
                            <select id="<?= e($inputName) ?>" name="<?= e($inputName) ?>" <?= $isRequired ? 'required' : '' ?>>
                                <option value="">Select an option</option>
                                <?php foreach ($options as $option): ?>
                                    <option
                                        value="<?= e($option) ?>"
                                        <?= ((string) ($old[$inputName] ?? '') === $option) ? 'selected' : '' ?>
                                    ><?= e($option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($fieldType === 'photo'): ?>
                            <input
                                id="<?= e($inputName) ?>"
                                name="<?= e($inputName) ?>"
                                type="file"
                                accept="image/*"
                                capture="environment"
                                <?= $isRequired ? 'required' : '' ?>
                            >
                            <p class="hint">Optional: you can take a live photo (camera) or upload from gallery.</p>
                        <?php elseif ($fieldType === 'video'): ?>
                            <input
                                id="<?= e($inputName) ?>"
                                name="<?= e($inputName) ?>"
                                type="file"
                                accept="video/*"
                                <?= $isRequired ? 'required' : '' ?>
                            >
                        <?php elseif ($fieldType === 'roll'): ?>
                            <input
                                id="<?= e($inputName) ?>"
                                name="<?= e($inputName) ?>"
                                type="text"
                                inputmode="numeric"
                                pattern="[0-9]{1,12}"
                                placeholder="Digits only"
                                value="<?= e((string) ($old[$inputName] ?? '')) ?>"
                                <?= $minLen !== null ? 'minlength="' . (int) $minLen . '"' : '' ?>
                                <?= $maxLen !== null ? 'maxlength="' . (int) $maxLen . '"' : '' ?>
                                <?= $isRequired ? 'required' : '' ?>
                            >
                        <?php else: ?>
                            <input
                                id="<?= e($inputName) ?>"
                                name="<?= e($inputName) ?>"
                                type="<?= e($fieldType) ?>"
                                placeholder="<?= e($placeholder) ?>"
                                value="<?= e((string) ($old[$inputName] ?? '')) ?>"
                                <?= $minLen !== null ? 'minlength="' . (int) $minLen . '"' : '' ?>
                                <?= $maxLen !== null ? 'maxlength="' . (int) $maxLen . '"' : '' ?>
                                <?= $isRequired ? 'required' : '' ?>
                            >
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <div class="form-actions">
                    <a class="btn outline" href="<?= e(app_url()) ?>">Back to Events</a>
                    <button class="btn" type="submit">Register and Generate QR</button>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <div class="footer-gap"></div>
</main>

<script>
// Show popup for server-side error flashes
(function () {
    const errorAlert = document.querySelector('.alert.error');
    const modal = document.getElementById('alertModal');
    const msgEl = document.getElementById('alertModalMsg');
    const closeBtn = document.getElementById('alertModalClose');

    if (errorAlert && modal && msgEl && closeBtn) {
        msgEl.textContent = errorAlert.textContent.trim();
        modal.style.display = 'flex';
        closeBtn.addEventListener('click', () => modal.style.display = 'none');
    }
})();

(() => {
    const form = document.getElementById('registrationForm');
    if (!form) return;

    form.addEventListener('submit', (event) => {
        if (!form.checkValidity()) {
            event.preventDefault();
            form.classList.add('was-validated');

            const firstInvalid = form.querySelector(':invalid');
            if (firstInvalid instanceof HTMLElement) {
                firstInvalid.focus();
                firstInvalid.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }

            return;
        }

        const submitButton = form.querySelector('button[type="submit"]');
        if (submitButton instanceof HTMLButtonElement) {
            submitButton.disabled = true;
            submitButton.textContent = 'Submitting…';
        }
    });
})();
</script>

<?php if ($isTeamAllowed): ?>
<script>
const eventRegistrationMode = <?= json_encode((string) $event['registration_mode'], JSON_UNESCAPED_SLASHES) ?>;
const teamFieldsWrapEl = document.getElementById('teamFieldsWrap');
const registrationTypeEl = document.getElementById('registration_type');
const teamNameEl = document.getElementById('team_name');
const teamSizeEl = document.getElementById('team_size');

function selectedRegistrationType() {
    if (eventRegistrationMode === 'team') {
        return 'team';
    }

    if (eventRegistrationMode === 'solo') {
        return 'solo';
    }

    return registrationTypeEl ? registrationTypeEl.value : 'solo';
}

function syncTeamFields() {
    const type = selectedRegistrationType();
    const showTeam = type === 'team';

    teamFieldsWrapEl.style.display = showTeam ? 'block' : 'none';

    if (teamNameEl) {
        teamNameEl.required = showTeam;
        if (!showTeam) {
            teamNameEl.value = '';
        }
    }

    if (teamSizeEl) {
        teamSizeEl.required = showTeam;
        if (!showTeam) {
            teamSizeEl.value = '1';
        }
    }
}

if (registrationTypeEl && eventRegistrationMode === 'both') {
    registrationTypeEl.addEventListener('change', syncTeamFields);
}

syncTeamFields();
</script>
<?php endif; ?>
</body>
</html>
