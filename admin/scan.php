<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_admin_login();

$selectedEventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
$flashes = get_flashes();

$eventsStmt = db()->query('SELECT id, title, event_date FROM events ORDER BY event_date DESC');
$events = $eventsStmt->fetch_all(MYSQLI_ASSOC);

if ($selectedEventId <= 0 && count($events) > 0) {
    $selectedEventId = (int) $events[0]['id'];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Scan QR | Attendance QR</title>
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/christ-theme.css')) ?>">
</head>
<body>
<header class="topbar">
    <div class="container">
        <a class="brand" href="<?= e(app_url('admin/dashboard.php')) ?>">Admin Panel</a>
        <nav class="nav-links">
            <a href="<?= e(app_url('admin/dashboard.php')) ?>">Dashboard</a>
            <a href="<?= e(app_url('admin/events.php')) ?>">Manage Events</a>
            <a href="<?= e(app_url('admin/email.php')) ?>">Email Settings</a>
            <a href="<?= e(app_url('admin/logout.php')) ?>">Logout</a>
        </nav>
    </div>
</header>

<main class="container">
    <section class="hero">
        <h1>Scan Student QR for Attendance</h1>
        <p>Optimized for fast check-in queues. Keep scanner running and scan continuously.</p>
    </section>

    <?php foreach ($flashes as $flash): ?>
        <div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>

    <section class="form-wrap">
        <?php if (count($events) === 0): ?>
            <div class="alert info">
                No events found.
                <a href="<?= e(app_url('admin/events.php')) ?>">Create an event first</a>.
            </div>
        <?php else: ?>
            <div id="scanToast" class="scan-toast" role="status" aria-live="polite"></div>
            <div id="scanResult" class="alert info" style="display: none;"></div>

            <div id="scanCenterToast" class="scan-center-toast" aria-live="assertive" aria-atomic="true">
                <div id="scanCenterIcon" class="scan-center-icon">?</div>
                <div id="scanCenterText" class="scan-center-text">Attendance Marked</div>
            </div>

            <div id="otpModal" class="popup-modal" style="display:none;" aria-hidden="true">
                <div class="popup-modal__box">
                    <h3 class="popup-modal__title">Enter Entry OTP</h3>
                    <p id="otpModalMsg" class="popup-modal__msg">Ask the student for the OTP sent to their email.</p>
                    <form id="otpForm" autocomplete="off">
                        <div class="field">
                            <label for="otpInput">OTP (6 digits)</label>
                            <input id="otpInput" inputmode="numeric" pattern="[0-9]*" maxlength="6" class="mono" placeholder="123456">
                        </div>
                        <div id="otpInlineError" class="alert error" style="display:none; margin-top: 0.75rem;"></div>
                        <div class="row" style="margin-top: 0.9rem;">
                            <button id="otpVerifyBtn" type="submit" class="btn" style="flex:1;">Verify & Mark</button>
                            <button id="otpCancelBtn" type="button" class="btn outline" style="flex:1;">Cancel</button>
                        </div>
                    </form>
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
                    z-index: 10000;
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
                    margin: 0 0 0.75rem;
                    color: #475569;
                    line-height: 1.5;
                }
            </style>

            <div class="field">
                <label for="eventSelect">Select Event</label>
                <select id="eventSelect" name="event_id">
                    <?php foreach ($events as $event): ?>
                        <option value="<?= (int) $event['id'] ?>" <?= (int) $event['id'] === $selectedEventId ? 'selected' : '' ?>>
                            <?= e($event['title']) ?> (<?= e(date('d M Y', strtotime((string) $event['event_date']))) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="scanner-box">
                <div class="scanner-stage">
                    <video id="scannerVideo" autoplay muted playsinline></video>
                    <div class="scan-corner tl"></div>
                    <div class="scan-corner tr"></div>
                    <div class="scan-corner bl"></div>
                    <div class="scan-corner br"></div>
                </div>
                <canvas id="scannerCanvas" style="display:none;"></canvas>
                <div class="row" style="margin-top: 0.8rem; justify-content: center;">
                    <button class="btn" id="startScanBtn" type="button">Start Scanner</button>
                    <button class="btn warn" id="stopScanBtn" type="button">Stop Scanner</button>
                </div>
                <p class="small">Local offline scanner. Duplicate scans are auto-filtered for speed.</p>
            </div>

            <form id="manualForm" class="form-wrap" style="margin-top: 1rem;">
                <div class="field">
                    <label for="manualToken">Manual QR Payload or Token</label>
                    <input id="manualToken" name="manual_token" class="mono" placeholder="ATTENDANCEQR|token or token">
                </div>
                <button class="btn outline" type="submit">Mark Attendance Manually</button>
            </form>

            <div class="scan-log-wrap">
                <h3>Recent Scan Updates</h3>
                <ul id="scanLog" class="scan-log"></ul>
            </div>
        <?php endif; ?>
    </section>

    <div class="footer-gap"></div>
</main>

<?php if (count($events) > 0): ?>
<script src="<?= e(app_url('assets/js/jsQR.js')) ?>"></script>
<script>
const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>;
const scanEndpoint = <?= json_encode(app_url('admin/scan_attendance.php'), JSON_UNESCAPED_SLASHES) ?>;
const verifyOtpEndpoint = <?= json_encode(app_url('admin/verify_entry_otp.php'), JSON_UNESCAPED_SLASHES) ?>;

let scannerActive = false;
let toastTimer = null;
let centerToastTimer = null;
let scanAnimationId = 0;
let cameraStream = null;
let isSending = false;
let lastFrameScanAt = 0;
let otpGate = null;

const frameScanIntervalMs = 110;
const duplicateWindowMs = 2400;
const recentPayloadTimestamps = new Map();
const scanQueue = [];

const resultEl = document.getElementById('scanResult');
const toastEl = document.getElementById('scanToast');
const centerToastEl = document.getElementById('scanCenterToast');
const centerIconEl = document.getElementById('scanCenterIcon');
const centerTextEl = document.getElementById('scanCenterText');
const scanLogEl = document.getElementById('scanLog');
const eventSelectEl = document.getElementById('eventSelect');
const manualFormEl = document.getElementById('manualForm');
const manualTokenEl = document.getElementById('manualToken');

const otpModalEl = document.getElementById('otpModal');
const otpModalMsgEl = document.getElementById('otpModalMsg');
const otpFormEl = document.getElementById('otpForm');
const otpInputEl = document.getElementById('otpInput');
const otpInlineErrorEl = document.getElementById('otpInlineError');
const otpCancelBtnEl = document.getElementById('otpCancelBtn');
const otpVerifyBtnEl = document.getElementById('otpVerifyBtn');

const videoEl = document.getElementById('scannerVideo');
const canvasEl = document.getElementById('scannerCanvas');
const canvasCtx = canvasEl.getContext('2d', { willReadFrequently: true });

function normalizedType(type) {
    if (type === 'success' || type === 'error' || type === 'info') {
        return type;
    }

    return 'info';
}

function showResult(type, message) {
    const status = normalizedType(type);
    resultEl.style.display = 'block';
    resultEl.className = 'alert ' + status;
    resultEl.textContent = message;
}

function showToast(type, message) {
    const status = normalizedType(type);

    toastEl.className = 'scan-toast show ' + status;
    toastEl.textContent = message;

    if (toastTimer) {
        window.clearTimeout(toastTimer);
    }

    toastTimer = window.setTimeout(() => {
        toastEl.className = 'scan-toast';
    }, 2500);
}

function showCenterToast(type, message) {
    const status = normalizedType(type);
    if (status === 'info') {
        return;
    }

    centerToastEl.className = 'scan-center-toast show ' + status;
    centerIconEl.textContent = status === 'success' ? '?' : '?';
    centerTextEl.textContent = message;

    if (centerToastTimer) {
        window.clearTimeout(centerToastTimer);
    }

    centerToastTimer = window.setTimeout(() => {
        centerToastEl.className = 'scan-center-toast';
    }, status === 'success' ? 1200 : 1500);
}

function addScanLog(type, message) {
    const status = normalizedType(type);
    const item = document.createElement('li');
    const timestamp = new Date().toLocaleTimeString();

    item.className = 'scan-log-item ' + status;
    item.textContent = timestamp + ' - ' + message;

    scanLogEl.prepend(item);

    while (scanLogEl.children.length > 12) {
        scanLogEl.removeChild(scanLogEl.lastChild);
    }
}

function playFeedbackTone(type) {
    if (!(type === 'success' || type === 'error')) {
        return;
    }

    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) {
        return;
    }

    try {
        const context = new AudioContextClass();
        const oscillator = context.createOscillator();
        const gainNode = context.createGain();

        oscillator.type = 'sine';
        oscillator.frequency.value = type === 'success' ? 980 : 220;
        gainNode.gain.value = 0.055;

        oscillator.connect(gainNode);
        gainNode.connect(context.destination);

        oscillator.start();
        oscillator.stop(context.currentTime + 0.1);
    } catch (error) {
        // ignore audio errors
    }

    if ('vibrate' in navigator && type === 'success') {
        navigator.vibrate(35);
    }
}

function notify(type, message) {
    showResult(type, message);
    showToast(type, message);
    showCenterToast(type, type === 'success' ? 'Attendance Marked' : (type === 'error' ? 'Scan Failed' : ''));
    addScanLog(type, message);
    playFeedbackTone(type);
}

function purgeRecentPayloadMap(nowMs) {
    recentPayloadTimestamps.forEach((time, payload) => {
        if (nowMs - time > duplicateWindowMs) {
            recentPayloadTimestamps.delete(payload);
        }
    });
}

async function sendAttendance(payload) {
    if (!eventSelectEl.value) {
        notify('error', 'Please select an event.');
        return;
    }

    if (otpGate) {
        return;
    }

    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('event_id', eventSelectEl.value);
    formData.append('qr_payload', payload);

    try {
        const response = await fetch(scanEndpoint, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        const rawText = await response.text();
        let data = null;
        try {
            data = JSON.parse(rawText);
        } catch (e) {
            data = null;
        }

        if (!data || typeof data !== 'object') {
            const snippet = rawText ? rawText.slice(0, 180) : '';
            notify('error', 'Scan request failed (HTTP ' + response.status + '). ' + (snippet ? 'Response: ' + snippet : ''));
            return;
        }

        if (data.type === 'otp_required') {
            notify('info', data.message || 'OTP required.');
            openOtpModal(payload, data);
            return;
        }

        notify(data.type || 'info', data.message || 'Unexpected response.');
    } catch (error) {
        notify('error', 'Could not send attendance request. Check network / admin session.');
    }
}

function openOtpModal(payload, data) {
    otpGate = {
        payload,
        eventId: eventSelectEl.value
    };

    otpInlineErrorEl.style.display = 'none';
    otpInlineErrorEl.textContent = '';

    const maskedEmail = data.email_masked ? String(data.email_masked) : 'registered email';
    const studentName = data.student_name ? String(data.student_name) : 'Student';

    otpModalMsgEl.textContent = 'OTP sent to ' + maskedEmail + '. Ask ' + studentName + ' for the 6-digit OTP.';
    otpInputEl.value = '';

    otpModalEl.style.display = 'flex';
    otpModalEl.setAttribute('aria-hidden', 'false');

    window.setTimeout(() => otpInputEl.focus(), 50);
}

function closeOtpModal() {
    otpGate = null;
    otpModalEl.style.display = 'none';
    otpModalEl.setAttribute('aria-hidden', 'true');
    otpInputEl.value = '';
    otpInlineErrorEl.style.display = 'none';
    otpInlineErrorEl.textContent = '';
}

async function verifyEntryOtp(otpCode) {
    if (!otpGate) {
        return;
    }

    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('event_id', otpGate.eventId);
    formData.append('qr_payload', otpGate.payload);
    formData.append('otp', otpCode);

    otpVerifyBtnEl.disabled = true;

    try {
        const response = await fetch(verifyOtpEndpoint, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        const rawText = await response.text();
        let data = null;
        try {
            data = JSON.parse(rawText);
        } catch (e) {
            data = null;
        }

        if (!data || typeof data !== 'object') {
            const snippet = rawText ? rawText.slice(0, 180) : '';
            otpInlineErrorEl.style.display = 'block';
            otpInlineErrorEl.textContent = 'OTP verify failed (HTTP ' + response.status + '). ' + (snippet ? 'Response: ' + snippet : '');
            return;
        }

        if (data.type === 'success') {
            closeOtpModal();
        }
        notify(data.type || 'info', data.message || 'Unexpected response.');

        if (data.type !== 'success') {
            otpInlineErrorEl.style.display = 'block';
            otpInlineErrorEl.textContent = data.message || 'OTP verification failed.';
        }
    } catch (error) {
        otpInlineErrorEl.style.display = 'block';
        otpInlineErrorEl.textContent = 'Could not verify OTP. Check network and try again.';
    } finally {
        otpVerifyBtnEl.disabled = false;
    }
}

function enqueuePayload(payload) {
    if (!payload) {
        return;
    }

    if (otpGate) {
        return;
    }

    if (!scanQueue.includes(payload)) {
        scanQueue.push(payload);
    }

    processQueue();
}

async function processQueue() {
    if (otpGate || isSending || scanQueue.length === 0) {
        return;
    }

    isSending = true;
    const payload = scanQueue.shift();
    await sendAttendance(payload);
    isSending = false;

    if (scanQueue.length > 0) {
        processQueue();
    }
}

function onDetectedPayload(rawPayload) {
    const payload = rawPayload.trim();
    if (payload === '') {
        return;
    }

    const now = Date.now();
    purgeRecentPayloadMap(now);

    const lastSeen = recentPayloadTimestamps.get(payload);
    if (lastSeen && now - lastSeen < duplicateWindowMs) {
        return;
    }

    recentPayloadTimestamps.set(payload, now);
    addScanLog('info', 'QR detected. Sending for verification...');
    enqueuePayload(payload);
}

function scanFrame(frameTime) {
    if (!scannerActive) {
        return;
    }

    if (frameTime - lastFrameScanAt >= frameScanIntervalMs) {
        lastFrameScanAt = frameTime;

        if (videoEl.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA) {
            const width = videoEl.videoWidth;
            const height = videoEl.videoHeight;

            if (width > 0 && height > 0) {
                if (canvasEl.width !== width || canvasEl.height !== height) {
                    canvasEl.width = width;
                    canvasEl.height = height;
                }

                canvasCtx.drawImage(videoEl, 0, 0, width, height);
                const imageData = canvasCtx.getImageData(0, 0, width, height);
                const code = jsQR(imageData.data, width, height, { inversionAttempts: 'attemptBoth' });

                if (code && code.data) {
                    onDetectedPayload(code.data);
                }
            }
        }
    }

    scanAnimationId = window.requestAnimationFrame(scanFrame);
}

async function startScanner() {
    if (scannerActive) {
        return;
    }

    if (typeof jsQR === 'undefined') {
        notify('error', 'Offline scanner library failed to load.');
        return;
    }

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        notify('error', 'Camera API is not supported in this browser. Use manual token mode.');
        return;
    }

    try {
        cameraStream = await navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: { ideal: 'environment' },
                width: { ideal: 960 },
                height: { ideal: 960 }
            },
            audio: false
        });

        videoEl.srcObject = cameraStream;
        await videoEl.play();

        scannerActive = true;
        notify('info', 'Scanner started. Ready for rapid check-in.');

        scanAnimationId = window.requestAnimationFrame(scanFrame);
    } catch (error) {
        notify('error', 'Unable to start scanner. Allow camera permission or use manual token.');
    }
}

async function stopScanner() {
    if (!scannerActive && !cameraStream) {
        return;
    }

    scannerActive = false;
    scanQueue.length = 0;

    if (scanAnimationId) {
        window.cancelAnimationFrame(scanAnimationId);
        scanAnimationId = 0;
    }

    if (videoEl) {
        videoEl.pause();
        videoEl.srcObject = null;
    }

    if (cameraStream) {
        cameraStream.getTracks().forEach((track) => track.stop());
        cameraStream = null;
    }

    notify('info', 'Scanner stopped.');
}

document.getElementById('startScanBtn').addEventListener('click', startScanner);
document.getElementById('stopScanBtn').addEventListener('click', stopScanner);

manualFormEl.addEventListener('submit', async (event) => {
    event.preventDefault();
    const payload = manualTokenEl.value.trim();

    if (!payload) {
        notify('error', 'Enter a token or QR payload first.');
        return;
    }

    enqueuePayload(payload);
});

otpCancelBtnEl.addEventListener('click', () => {
    closeOtpModal();
});

otpFormEl.addEventListener('submit', async (event) => {
    event.preventDefault();
    const otpCode = otpInputEl.value.trim();
    if (!/^[0-9]{6}$/.test(otpCode)) {
        otpInlineErrorEl.style.display = 'block';
        otpInlineErrorEl.textContent = 'Enter a 6-digit OTP.';
        otpInputEl.focus();
        return;
    }
    await verifyEntryOtp(otpCode);
});

window.addEventListener('beforeunload', () => {
    stopScanner();
});
</script>
<?php endif; ?>
</body>
</html>
