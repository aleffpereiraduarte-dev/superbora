<?php
/**
 * GET /campaign/admin-qr.php?id=1&pin=SB2026
 * Standalone HTML page with rotating QR code + live dashboard.
 * Shows GREEN flash when someone redeems, RED for errors.
 */
require_once __DIR__ . "/../config/database.php";

$campaignId = (int)($_GET['id'] ?? 0);
$pin = trim($_GET['pin'] ?? '');

if (!$campaignId || empty($pin)) {
    die('Acesso negado. Use: ?id=X&pin=XXXX');
}

$db = getDB();
$stmt = $db->prepare("SELECT * FROM om_campaigns WHERE campaign_id = ?");
$stmt->execute([$campaignId]);
$campaign = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$campaign || !hash_equals($campaign['admin_pin'], $pin)) {
    die('PIN invalido.');
}

$remaining = (int)$campaign['max_redemptions'] - (int)$campaign['current_redemptions'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SuperBora - <?= htmlspecialchars($campaign['name']) ?></title>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #111;
    color: #fff;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 20px;
    transition: background 0.3s;
}
body.flash-green { background: #16a34a !important; }
body.flash-red { background: #dc2626 !important; }

.header {
    text-align: center;
    margin-bottom: 20px;
}
.header h1 {
    font-size: 28px;
    background: linear-gradient(135deg, #FF6B00, #E65100);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    margin-bottom: 4px;
}
.header .subtitle {
    color: #888;
    font-size: 14px;
}

.qr-container {
    background: #fff;
    border-radius: 24px;
    padding: 24px;
    margin-bottom: 20px;
    position: relative;
    box-shadow: 0 8px 32px rgba(255,107,0,0.2);
}
.qr-container canvas { display: block; }
.qr-timer {
    position: absolute;
    bottom: 8px;
    right: 12px;
    background: rgba(0,0,0,0.7);
    color: #fff;
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 10px;
}

.stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    width: 100%;
    max-width: 340px;
    margin-bottom: 20px;
}
.stat-card {
    background: #1a1a1a;
    border-radius: 16px;
    padding: 16px;
    text-align: center;
}
.stat-value {
    font-size: 36px;
    font-weight: 800;
}
.stat-label {
    font-size: 12px;
    color: #888;
    margin-top: 4px;
}
.stat-remaining .stat-value { color: #22c55e; }
.stat-redeemed .stat-value { color: #FF6B00; }

.recent {
    width: 100%;
    max-width: 340px;
}
.recent h3 {
    font-size: 14px;
    color: #888;
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 1px;
}
.recent-item {
    background: #1a1a1a;
    border-radius: 12px;
    padding: 10px 14px;
    margin-bottom: 6px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    animation: slideIn 0.3s ease;
}
.recent-item .name { font-weight: 600; font-size: 14px; }
.recent-item .code {
    background: #22c55e;
    color: #fff;
    font-weight: 800;
    font-size: 13px;
    padding: 2px 10px;
    border-radius: 8px;
    letter-spacing: 1px;
}
.recent-item .time { color: #666; font-size: 11px; }

/* Flash overlay for redemption */
.flash-overlay {
    position: fixed;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 100;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s;
}
.flash-overlay.active { opacity: 1; pointer-events: auto; }
.flash-overlay.success { background: #16a34a; }
.flash-overlay.error { background: #dc2626; }
.flash-icon { font-size: 80px; margin-bottom: 16px; }
.flash-text { font-size: 28px; font-weight: 800; text-align: center; }
.flash-code { font-size: 48px; font-weight: 900; letter-spacing: 6px; margin-top: 12px; }
.flash-name { font-size: 20px; margin-top: 8px; opacity: 0.9; }

.status-dot {
    width: 10px; height: 10px; border-radius: 50%;
    display: inline-block; margin-right: 6px;
}
.status-dot.active { background: #22c55e; animation: pulse 2s infinite; }
.status-dot.inactive { background: #dc2626; }

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.4; }
}
@keyframes slideIn {
    from { transform: translateY(-10px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.instructions {
    background: #1a1a1a;
    border-radius: 12px;
    padding: 12px 16px;
    width: 100%;
    max-width: 340px;
    margin-bottom: 16px;
    font-size: 13px;
    color: #aaa;
    text-align: center;
    line-height: 1.5;
}
.instructions strong { color: #FF6B00; }
</style>
</head>
<body>

<div class="header">
    <h1>SuperBora</h1>
    <div class="subtitle">
        <span class="status-dot active" id="statusDot"></span>
        <?= htmlspecialchars($campaign['name']) ?>
    </div>
</div>

<div class="instructions">
    O cliente abre o app SuperBora → toca no banner → <strong>Escanear QR Code</strong> → aponta pra ca!
</div>

<div class="qr-container" id="qrContainer">
    <div id="qrcode"></div>
    <div class="qr-timer" id="qrTimer">30s</div>
</div>

<div class="stats">
    <div class="stat-card stat-remaining">
        <div class="stat-value" id="remaining"><?= $remaining ?></div>
        <div class="stat-label">Restantes</div>
    </div>
    <div class="stat-card stat-redeemed">
        <div class="stat-value" id="redeemed"><?= (int)$campaign['current_redemptions'] ?></div>
        <div class="stat-label">Resgatados</div>
    </div>
</div>

<div class="recent">
    <h3>Ultimos resgates</h3>
    <div id="recentList">
        <div style="color:#555;font-size:13px;text-align:center;padding:20px;">Nenhum resgate ainda</div>
    </div>
</div>

<!-- Flash overlay -->
<div class="flash-overlay" id="flashOverlay">
    <div class="flash-icon" id="flashIcon"></div>
    <div class="flash-text" id="flashText"></div>
    <div class="flash-code" id="flashCode"></div>
    <div class="flash-name" id="flashName"></div>
</div>

<script>
const CAMPAIGN_ID = <?= $campaignId ?>;
const PIN = '<?= htmlspecialchars($pin) ?>';
const DATA_URL = `admin-qr-data.php?id=${CAMPAIGN_ID}&pin=${PIN}`;

let qrInstance = null;
let rotationSeconds = 30;
let countdown = rotationSeconds;
let lastRedeemed = 0;
let pollInterval;

// Initialize QR code
function initQR() {
    qrInstance = new QRCode(document.getElementById('qrcode'), {
        width: 280,
        height: 280,
        colorDark: '#000000',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.H,
    });
}

// Refresh QR data
async function refreshQR() {
    try {
        const res = await fetch(DATA_URL + '&_=' + Date.now());
        const data = await res.json();

        if (data.error) {
            console.error('QR data error:', data.error);
            return;
        }

        // Update QR
        qrInstance.clear();
        qrInstance.makeCode(data.qr_data);

        // Update stats
        document.getElementById('remaining').textContent = data.remaining;
        document.getElementById('redeemed').textContent = data.total_redeemed;
        rotationSeconds = data.rotation_seconds || 30;
        countdown = rotationSeconds;

        // Check for new redemptions (flash green!)
        if (lastRedeemed > 0 && data.total_redeemed > lastRedeemed) {
            const newest = data.recent_redemptions[0];
            showFlash('success', newest);
        }
        lastRedeemed = data.total_redeemed;

        // Update recent list
        updateRecentList(data.recent_redemptions);

        // Status
        document.getElementById('statusDot').className =
            data.status === 'active' ? 'status-dot active' : 'status-dot inactive';

    } catch (e) {
        console.error('Refresh error:', e);
    }
}

function updateRecentList(items) {
    const list = document.getElementById('recentList');
    if (!items || items.length === 0) {
        list.innerHTML = '<div style="color:#555;font-size:13px;text-align:center;padding:20px;">Nenhum resgate ainda</div>';
        return;
    }
    list.innerHTML = items.map(item => {
        const time = new Date(item.redeemed_at).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
        const name = item.customer_name || 'Cliente';
        return `<div class="recent-item">
            <div>
                <div class="name">${escHtml(name)}</div>
                <div class="time">${time} · ${escHtml(item.customer_phone || '--')}</div>
            </div>
            <div class="code">${escHtml(item.redemption_code)}</div>
        </div>`;
    }).join('');
}

function showFlash(type, redemption) {
    const overlay = document.getElementById('flashOverlay');
    const icon = document.getElementById('flashIcon');
    const text = document.getElementById('flashText');
    const code = document.getElementById('flashCode');
    const nameEl = document.getElementById('flashName');

    overlay.className = `flash-overlay active ${type}`;

    if (type === 'success') {
        icon.textContent = '✅';
        text.textContent = 'RESGATE APROVADO!';
        code.textContent = redemption?.redemption_code || '';
        nameEl.textContent = redemption?.customer_name || '';
        // Play sound
        try {
            const audio = new Audio('data:audio/wav;base64,UklGRl9vT19teleWF2ZWZtdCAQAAAAEAABAESsAABErAAAEABAAGRhdGE=');
            audio.play().catch(() => {});
        } catch (e) {}
        // Vibrate
        if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
    } else {
        icon.textContent = '❌';
        text.textContent = 'NEGADO';
        code.textContent = '';
        nameEl.textContent = '';
    }

    setTimeout(() => {
        overlay.className = 'flash-overlay';
    }, 4000);
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

// Timer
function updateTimer() {
    countdown--;
    if (countdown <= 0) {
        refreshQR();
        countdown = rotationSeconds;
    }
    document.getElementById('qrTimer').textContent = countdown + 's';
}

// Poll for new redemptions every 3 seconds
function startPolling() {
    pollInterval = setInterval(async () => {
        try {
            const res = await fetch(DATA_URL + '&_=' + Date.now());
            const data = await res.json();
            if (data.error) return;

            // Update stats
            document.getElementById('remaining').textContent = data.remaining;
            document.getElementById('redeemed').textContent = data.total_redeemed;

            // Flash green for new redemptions
            if (lastRedeemed > 0 && data.total_redeemed > lastRedeemed) {
                const newest = data.recent_redemptions[0];
                showFlash('success', newest);
            }
            lastRedeemed = data.total_redeemed;

            updateRecentList(data.recent_redemptions);
        } catch (e) {}
    }, 3000);
}

// Init
initQR();
refreshQR();
setInterval(updateTimer, 1000);
startPolling();
</script>
</body>
</html>
