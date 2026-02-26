<?php
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$workerId = getWorkerId();
$orderId = $_GET['id'] ?? 0;

if (!$orderId) {
    header('Location: dashboard.php');
    exit;
}

$pdo = getDB();
$stmt = $pdo->prepare("
    SELECT o.*, p.name as partner_name,
           c.name as customer_name, c.phone as customer_phone,
           o.delivery_address, o.delivery_lat, o.delivery_lng
    FROM om_market_orders o
    LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
    LEFT JOIN om_customers c ON o.customer_id = c.customer_id
    WHERE o.order_id = ? AND (o.shopper_id = ? OR o.delivery_id = ?)
");
$stmt->execute([$orderId, $workerId, $workerId]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: dashboard.php');
    exit;
}

// Gerar código de entrega se não existir
$deliveryCode = $order['delivery_code'] ?? '';
if (!$deliveryCode) {
    $deliveryCode = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
    $pdo->prepare("UPDATE om_market_orders SET delivery_code = ? WHERE order_id = ?")->execute([$deliveryCode, $orderId]);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Entrega - #<?= $order['order_number'] ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --green: #108910;
            --green-dark: #0D6B0D;
            --orange: #FF5500;
            --red: #dc2626;
            --gray-900: #1C1C1C;
            --gray-500: #8B8B8B;
            --gray-300: #C7C7C7;
            --gray-100: #F6F6F6;
            --white: #FFFFFF;
            --safe-top: env(safe-area-inset-top, 0px);
            --safe-bottom: env(safe-area-inset-bottom, 0px);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--gray-100); min-height: 100vh; }
        
        .header { background: var(--white); padding: 12px 16px; padding-top: calc(12px + var(--safe-top)); display: flex; align-items: center; gap: 12px; border-bottom: 1px solid var(--gray-300); }
        .back-btn { width: 40px; height: 40px; border: none; background: var(--gray-100); border-radius: 12px; display: flex; align-items: center; justify-content: center; }
        .header-info { flex: 1; }
        .header-title { font-size: 16px; font-weight: 700; }
        .header-subtitle { font-size: 13px; color: var(--gray-500); }
        
        .map-container { height: 200px; background: var(--gray-100); position: relative; }
        .map-container iframe { width: 100%; height: 100%; border: none; }
        
        .customer-card { background: var(--white); margin: 16px; border-radius: 16px; padding: 20px; }
        .customer-name { font-size: 18px; font-weight: 700; margin-bottom: 8px; }
        .customer-address { font-size: 14px; color: var(--gray-500); line-height: 1.5; margin-bottom: 16px; }
        .customer-actions { display: flex; gap: 10px; }
        .customer-btn { flex: 1; padding: 12px; border: none; border-radius: 12px; font-size: 14px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .customer-btn.call { background: var(--green); color: var(--white); }
        .customer-btn.navigate { background: #3B82F6; color: var(--white); }
        
        .delivery-code-section { background: var(--white); margin: 16px; border-radius: 16px; padding: 24px; text-align: center; }
        .code-label { font-size: 14px; color: var(--gray-500); margin-bottom: 8px; }
        .code-display { font-size: 48px; font-weight: 800; letter-spacing: 8px; color: var(--green); }
        .code-instruction { font-size: 13px; color: var(--gray-500); margin-top: 12px; }
        
        .confirm-section { background: var(--white); margin: 16px; border-radius: 16px; padding: 20px; }
        .confirm-title { font-size: 16px; font-weight: 700; margin-bottom: 16px; }
        .code-input { display: flex; gap: 8px; margin-bottom: 16px; justify-content: center; }
        .code-input input { width: 50px; height: 60px; border: 2px solid var(--gray-300); border-radius: 12px; font-size: 24px; font-weight: 700; text-align: center; }
        .code-input input:focus { border-color: var(--green); outline: none; }
        
        .photo-section { background: var(--white); margin: 16px; border-radius: 16px; padding: 20px; }
        .photo-title { font-size: 16px; font-weight: 700; margin-bottom: 16px; }
        .photo-preview { width: 100%; height: 200px; background: var(--gray-100); border-radius: 12px; display: flex; align-items: center; justify-content: center; overflow: hidden; margin-bottom: 12px; }
        .photo-preview img { width: 100%; height: 100%; object-fit: cover; }
        .photo-preview.empty { flex-direction: column; gap: 8px; color: var(--gray-500); }
        .photo-btn { width: 100%; padding: 14px; background: var(--gray-100); border: 2px dashed var(--gray-300); border-radius: 12px; font-size: 14px; font-weight: 600; cursor: pointer; }
        
        .bottom-action { position: fixed; bottom: 0; left: 0; right: 0; padding: 16px; padding-bottom: calc(16px + var(--safe-bottom)); background: var(--white); border-top: 1px solid var(--gray-300); }
        .confirm-btn { width: 100%; padding: 18px; background: var(--green); color: var(--white); border: none; border-radius: 14px; font-size: 16px; font-weight: 700; cursor: pointer; }
        .confirm-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        
        .toast { position: fixed; bottom: 100px; left: 50%; transform: translateX(-50%) translateY(100px); background: var(--gray-900); color: var(--white); padding: 14px 24px; border-radius: 12px; font-size: 14px; opacity: 0; transition: all 0.3s; z-index: 1000; }
        .toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        .toast.success { background: var(--green); }
        .toast.error { background: var(--red); }
        
        /* Camera Modal */
        .camera-modal { display: none; position: fixed; inset: 0; background: #000; z-index: 1000; flex-direction: column; }
        .camera-modal.active { display: flex; }
        .camera-video { flex: 1; object-fit: cover; }
        .camera-controls { padding: 20px; display: flex; justify-content: center; gap: 20px; padding-bottom: calc(20px + var(--safe-bottom)); }
        .camera-btn { width: 70px; height: 70px; border-radius: 50%; border: 4px solid #fff; background: transparent; cursor: pointer; }
        .camera-btn.capture { background: #fff; }
        .camera-btn.close { background: var(--red); border-color: var(--red); color: #fff; font-size: 24px; }
    </style>
</head>
<body>

<header class="header">
    <button class="back-btn" onclick="history.back()">
        <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    </button>
    <div class="header-info">
        <div class="header-title">Entrega #<?= $order['order_number'] ?></div>
        <div class="header-subtitle"><?= htmlspecialchars($order['partner_name'] ?? '') ?></div>
    </div>
</header>

<!-- Map -->
<div class="map-container">
    <?php if ($order['delivery_lat'] && $order['delivery_lng']): ?>
    <iframe src="https://maps.google.com/maps?q=<?= $order['delivery_lat'] ?>,<?= $order['delivery_lng'] ?>&z=15&output=embed"></iframe>
    <?php else: ?>
    <div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--gray-500);">📍 Mapa indisponível</div>
    <?php endif; ?>
</div>

<!-- Customer Info -->
<div class="customer-card">
    <div class="customer-name"><?= htmlspecialchars($order['customer_name'] ?? 'Cliente') ?></div>
    <div class="customer-address"><?= htmlspecialchars($order['delivery_address'] ?? 'Endereço não informado') ?></div>
    <div class="customer-actions">
        <a href="tel:<?= $order['customer_phone'] ?>" class="customer-btn call">📞 Ligar</a>
        <a href="https://www.google.com/maps/dir/?api=1&destination=<?= $order['delivery_lat'] ?>,<?= $order['delivery_lng'] ?>" target="_blank" class="customer-btn navigate">🧭 Navegar</a>
    </div>
</div>

<!-- Delivery Code Display -->
<div class="delivery-code-section">
    <div class="code-label">Código de Entrega</div>
    <div class="code-display"><?= $deliveryCode ?></div>
    <div class="code-instruction">Peça este código ao cliente para confirmar</div>
</div>

<!-- Code Confirmation -->
<div class="confirm-section">
    <div class="confirm-title">Digite o código do cliente</div>
    <div class="code-input">
        <input type="text" maxlength="1" inputmode="numeric" class="code-digit" data-index="0">
        <input type="text" maxlength="1" inputmode="numeric" class="code-digit" data-index="1">
        <input type="text" maxlength="1" inputmode="numeric" class="code-digit" data-index="2">
        <input type="text" maxlength="1" inputmode="numeric" class="code-digit" data-index="3">
    </div>
</div>

<!-- Photo Section -->
<div class="photo-section">
    <div class="photo-title">📷 Foto da Entrega</div>
    <div class="photo-preview empty" id="photoPreview">
        <span>📷</span>
        <span>Nenhuma foto</span>
    </div>
    <button class="photo-btn" onclick="openCamera()">Tirar Foto</button>
</div>

<!-- Bottom Action -->
<div class="bottom-action">
    <button class="confirm-btn" id="confirmBtn" onclick="confirmDelivery()" disabled>✓ Confirmar Entrega</button>
</div>

<!-- Camera Modal -->
<div class="camera-modal" id="cameraModal">
    <video class="camera-video" id="cameraVideo" autoplay playsinline></video>
    <div class="camera-controls">
        <button class="camera-btn close" onclick="closeCamera()">✕</button>
        <button class="camera-btn capture" onclick="capturePhoto()"></button>
    </div>
</div>

<!-- Hidden Canvas for Photo -->
<canvas id="photoCanvas" style="display:none;"></canvas>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
const ORDER_ID = <?= $orderId ?>;
const DELIVERY_CODE = '<?= $deliveryCode ?>';
let enteredCode = '';
let photoData = null;
let cameraStream = null;

// Toast
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show ' + type;
    setTimeout(() => t.className = 'toast', 3000);
}

// Code Input Handler
document.querySelectorAll('.code-digit').forEach((input, idx) => {
    input.addEventListener('input', (e) => {
        const val = e.target.value;
        if (val && idx < 3) {
            document.querySelectorAll('.code-digit')[idx + 1].focus();
        }
        updateCode();
    });
    
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && !e.target.value && idx > 0) {
            document.querySelectorAll('.code-digit')[idx - 1].focus();
        }
    });
});

function updateCode() {
    enteredCode = Array.from(document.querySelectorAll('.code-digit')).map(i => i.value).join('');
    checkCanConfirm();
}

function checkCanConfirm() {
    const codeOk = enteredCode.length === 4;
    document.getElementById('confirmBtn').disabled = !codeOk;
}

// Camera
function openCamera() {
    const modal = document.getElementById('cameraModal');
    const video = document.getElementById('cameraVideo');
    
    navigator.mediaDevices.getUserMedia({ 
        video: { facingMode: 'environment' }, 
        audio: false 
    })
    .then(stream => {
        cameraStream = stream;
        video.srcObject = stream;
        modal.classList.add('active');
    })
    .catch(err => {
        showToast('Erro ao acessar câmera', 'error');
        console.error(err);
    });
}

function closeCamera() {
    if (cameraStream) {
        cameraStream.getTracks().forEach(t => t.stop());
        cameraStream = null;
    }
    document.getElementById('cameraModal').classList.remove('active');
}

function capturePhoto() {
    const video = document.getElementById('cameraVideo');
    const canvas = document.getElementById('photoCanvas');
    const ctx = canvas.getContext('2d');
    
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    ctx.drawImage(video, 0, 0);
    
    photoData = canvas.toDataURL('image/jpeg', 0.8);
    
    // Mostrar preview
    const preview = document.getElementById('photoPreview');
    preview.innerHTML = `<img src="${photoData}" alt="Foto">`;
    preview.classList.remove('empty');
    
    closeCamera();
    showToast('Foto capturada!');
}

// Confirm Delivery
function confirmDelivery() {
    if (enteredCode !== DELIVERY_CODE) {
        showToast('Código incorreto!', 'error');
        return;
    }
    
    const formData = new FormData();
    formData.append('order_id', ORDER_ID);
    formData.append('code', enteredCode);
    if (photoData) {
        formData.append('photo_base64', photoData);
        formData.append('type', 'delivery');
    }
    
    // Upload foto se tiver
    if (photoData) {
        fetch('api/upload-photo.php', {
            method: 'POST',
            body: formData
        });
    }
    
    // Confirmar entrega
    fetch('api/confirm-delivery.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({order_id: ORDER_ID, code: enteredCode})
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            showToast('Entrega confirmada! 🎉');
            setTimeout(() => location.href = 'dashboard.php', 1500);
        } else {
            showToast(d.error || 'Erro', 'error');
        }
    });
}
</script>

</body>
</html>