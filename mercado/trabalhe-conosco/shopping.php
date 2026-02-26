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

// Buscar pedido
$pdo = getDB();
$stmt = $pdo->prepare("
    SELECT o.*, p.name as partner_name, p.logo as partner_logo, p.address as partner_address
    FROM om_market_orders o
    LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
    WHERE o.order_id = ? AND o.shopper_id = ?
");
$stmt->execute([$orderId, $workerId]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: dashboard.php');
    exit;
}

// Buscar itens
$stmt = $pdo->prepare("SELECT * FROM om_market_order_items WHERE order_id = ? ORDER BY status ASC, product_name ASC");
$stmt->execute([$orderId]);
$items = $stmt->fetchAll();

$totalItems = count($items);
$pickedItems = count(array_filter($items, fn($i) => in_array($i['status'], ['picked', 'replaced'])));
$progress = $totalItems > 0 ? round(($pickedItems / $totalItems) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Compras - #<?= $order['order_number'] ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Html5Qrcode para scanner -->
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <style>
        :root {
            --green: #108910;
            --green-dark: #0D6B0D;
            --green-light: #E8F5E8;
            --orange: #FF5500;
            --red: #dc2626;
            --gray-900: #1C1C1C;
            --gray-700: #5C5C5C;
            --gray-500: #8B8B8B;
            --gray-300: #C7C7C7;
            --gray-100: #F6F6F6;
            --white: #FFFFFF;
            --safe-top: env(safe-area-inset-top, 0px);
            --safe-bottom: env(safe-area-inset-bottom, 0px);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body { font-family: 'Inter', sans-serif; background: var(--gray-100); min-height: 100vh; padding-bottom: calc(80px + var(--safe-bottom)); }
        
        /* Header */
        .header { background: var(--white); padding: 12px 16px; padding-top: calc(12px + var(--safe-top)); display: flex; align-items: center; gap: 12px; border-bottom: 1px solid var(--gray-300); position: sticky; top: 0; z-index: 100; }
        .back-btn { width: 40px; height: 40px; border: none; background: var(--gray-100); border-radius: 12px; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        .header-info { flex: 1; }
        .header-title { font-size: 16px; font-weight: 700; color: var(--gray-900); }
        .header-subtitle { font-size: 13px; color: var(--gray-500); }
        .scan-btn { width: 48px; height: 48px; background: var(--green); border: none; border-radius: 12px; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        .scan-btn svg { width: 24px; height: 24px; color: var(--white); }
        
        /* Progress */
        .progress-section { padding: 16px; background: var(--white); margin-bottom: 8px; }
        .progress-header { display: flex; justify-content: space-between; margin-bottom: 8px; }
        .progress-text { font-size: 14px; font-weight: 600; color: var(--gray-900); }
        .progress-count { font-size: 14px; color: var(--gray-500); }
        .progress-bar { height: 8px; background: var(--gray-100); border-radius: 4px; overflow: hidden; }
        .progress-fill { height: 100%; background: var(--green); border-radius: 4px; transition: width 0.3s; }
        
        /* Items List */
        .items-list { padding: 0 16px; }
        .item-card { background: var(--white); border-radius: 12px; padding: 16px; margin-bottom: 8px; display: flex; gap: 12px; align-items: center; }
        .item-card.picked { opacity: 0.6; }
        .item-card.picked .item-name { text-decoration: line-through; }
        .item-image { width: 56px; height: 56px; background: var(--gray-100); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 24px; overflow: hidden; }
        .item-image img { width: 100%; height: 100%; object-fit: cover; }
        .item-info { flex: 1; }
        .item-name { font-size: 14px; font-weight: 600; color: var(--gray-900); margin-bottom: 4px; }
        .item-details { font-size: 12px; color: var(--gray-500); }
        .item-qty { font-size: 13px; font-weight: 700; color: var(--gray-700); }
        .item-actions { display: flex; gap: 8px; }
        .item-btn { width: 40px; height: 40px; border: none; border-radius: 10px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 18px; }
        .item-btn.check { background: var(--green-light); color: var(--green); }
        .item-btn.check.active { background: var(--green); color: var(--white); }
        .item-btn.replace { background: #FEF3C7; color: #D97706; }
        .item-btn.missing { background: #FEE2E2; color: var(--red); }
        
        /* Scanner Modal */
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
        .modal.active { display: flex; }
        .modal-content { background: var(--white); border-radius: 20px; width: 100%; max-width: 400px; overflow: hidden; }
        .modal-header { padding: 16px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--gray-100); }
        .modal-title { font-size: 18px; font-weight: 700; }
        .modal-close { width: 32px; height: 32px; border: none; background: var(--gray-100); border-radius: 50%; cursor: pointer; font-size: 18px; }
        .modal-body { padding: 16px; }
        #scanner-container { width: 100%; height: 300px; background: #000; border-radius: 12px; overflow: hidden; }
        .manual-input { margin-top: 16px; }
        .manual-input input { width: 100%; padding: 14px; border: 2px solid var(--gray-300); border-radius: 12px; font-size: 16px; text-align: center; letter-spacing: 2px; }
        .manual-input input:focus { border-color: var(--green); outline: none; }
        
        /* QR Code Modal */
        .qr-modal-content { text-align: center; padding: 24px; }
        .qr-code { width: 200px; height: 200px; margin: 20px auto; background: var(--gray-100); border-radius: 12px; display: flex; align-items: center; justify-content: center; }
        .qr-code img { width: 100%; height: 100%; }
        .handoff-code { font-size: 32px; font-weight: 800; letter-spacing: 4px; color: var(--green); margin: 16px 0; }
        .qr-instructions { font-size: 14px; color: var(--gray-500); }
        
        /* Replace Modal */
        .replace-form { display: flex; flex-direction: column; gap: 12px; }
        .replace-form input { padding: 14px; border: 2px solid var(--gray-300); border-radius: 12px; font-size: 15px; }
        .replace-form input:focus { border-color: var(--green); outline: none; }
        
        /* Bottom Actions */
        .bottom-actions { position: fixed; bottom: 0; left: 0; right: 0; background: var(--white); padding: 16px; padding-bottom: calc(16px + var(--safe-bottom)); border-top: 1px solid var(--gray-300); display: flex; gap: 12px; }
        .btn { flex: 1; padding: 16px; border: none; border-radius: 12px; font-size: 15px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-primary { background: var(--green); color: var(--white); }
        .btn-secondary { background: var(--gray-100); color: var(--gray-900); }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        
        /* Chat Button */
        .chat-fab { position: fixed; bottom: 100px; right: 16px; width: 56px; height: 56px; background: var(--green); border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(0,0,0,0.2); cursor: pointer; z-index: 50; }
        .chat-fab svg { width: 24px; height: 24px; color: var(--white); }
        .chat-badge { position: absolute; top: -4px; right: -4px; background: var(--red); color: var(--white); font-size: 11px; font-weight: 700; padding: 2px 6px; border-radius: 10px; }
        
        /* Toast */
        .toast { position: fixed; bottom: 120px; left: 50%; transform: translateX(-50%) translateY(100px); background: var(--gray-900); color: var(--white); padding: 14px 24px; border-radius: 12px; font-size: 14px; opacity: 0; transition: all 0.3s; z-index: 2000; }
        .toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        .toast.success { background: var(--green); }
        .toast.error { background: var(--red); }
    </style>
</head>
<body>

<!-- Header -->
<header class="header">
    <button class="back-btn" onclick="history.back()">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    </button>
    <div class="header-info">
        <div class="header-title"><?= htmlspecialchars($order['partner_name'] ?? 'Mercado') ?></div>
        <div class="header-subtitle">Pedido #<?= $order['order_number'] ?></div>
    </div>
    <button class="scan-btn" onclick="openScanner()">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
    </button>
</header>

<!-- Progress -->
<section class="progress-section">
    <div class="progress-header">
        <span class="progress-text">Progresso</span>
        <span class="progress-count" id="progressCount"><?= $pickedItems ?>/<?= $totalItems ?> itens</span>
    </div>
    <div class="progress-bar">
        <div class="progress-fill" id="progressFill" style="width: <?= $progress ?>%"></div>
    </div>
</section>

<!-- Items List -->
<section class="items-list">
    <?php foreach ($items as $item): 
        $isPicked = in_array($item['status'], ['picked', 'replaced']);
    ?>
    <div class="item-card <?= $isPicked ? 'picked' : '' ?>" data-item-id="<?= $item['item_id'] ?>" data-ean="<?= $item['ean'] ?? '' ?>">
        <div class="item-image">
            <?php if ($item['image']): ?>
            <img src="<?= $item['image'] ?>" alt="">
            <?php else: ?>
            🛒
            <?php endif; ?>
        </div>
        <div class="item-info">
            <div class="item-name"><?= htmlspecialchars($item['product_name']) ?></div>
            <div class="item-details"><?= $item['ean'] ?? 'Sem código' ?></div>
        </div>
        <div class="item-qty"><?= $item['quantity'] ?>x</div>
        <div class="item-actions">
            <button class="item-btn check <?= $isPicked ? 'active' : '' ?>" onclick="toggleItem(<?= $item['item_id'] ?>, 'picked')" title="Coletado">✓</button>
            <button class="item-btn replace" onclick="openReplace(<?= $item['item_id'] ?>, '<?= htmlspecialchars($item['product_name'], ENT_QUOTES) ?>')" title="Substituir">↔</button>
            <button class="item-btn missing" onclick="toggleItem(<?= $item['item_id'] ?>, 'missing')" title="Faltando">✕</button>
        </div>
    </div>
    <?php endforeach; ?>
</section>

<!-- Chat FAB -->
<a href="chat.php?order_id=<?= $orderId ?>" class="chat-fab">
    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
</a>

<!-- Bottom Actions -->
<div class="bottom-actions">
    <button class="btn btn-secondary" onclick="openQRCode()">📱 QR Handoff</button>
    <button class="btn btn-primary" id="finishBtn" onclick="finishShopping()" <?= $progress < 100 ? 'disabled' : '' ?>>
        ✓ Finalizar Compras
    </button>
</div>

<!-- Scanner Modal -->
<div class="modal" id="scannerModal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="modal-title">📷 Scanner</span>
            <button class="modal-close" onclick="closeScanner()">✕</button>
        </div>
        <div class="modal-body">
            <div id="scanner-container"></div>
            <div class="manual-input">
                <input type="text" id="manualEan" placeholder="Ou digite o código de barras" inputmode="numeric" onkeypress="if(event.key==='Enter')searchByEan()">
            </div>
        </div>
    </div>
</div>

<!-- QR Code Modal -->
<div class="modal" id="qrModal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="modal-title">📱 QR Code Handoff</span>
            <button class="modal-close" onclick="closeQRCode()">✕</button>
        </div>
        <div class="qr-modal-content">
            <p class="qr-instructions">Mostre este código para o entregador escanear</p>
            <div class="qr-code" id="qrCodeContainer">
                <span>Gerando...</span>
            </div>
            <div class="handoff-code" id="handoffCode">--------</div>
            <p class="qr-instructions">Código manual de 8 dígitos</p>
        </div>
    </div>
</div>

<!-- Replace Modal -->
<div class="modal" id="replaceModal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="modal-title">↔ Substituir Produto</span>
            <button class="modal-close" onclick="closeReplace()">✕</button>
        </div>
        <div class="modal-body">
            <p style="margin-bottom:12px;color:var(--gray-500);">Substituindo: <strong id="replacingItem"></strong></p>
            <div class="replace-form">
                <input type="hidden" id="replaceItemId">
                <input type="text" id="replaceName" placeholder="Nome do produto substituto">
                <input type="number" id="replacePrice" placeholder="Preço (R$)" step="0.01">
                <input type="text" id="replaceEan" placeholder="Código de barras (opcional)">
                <input type="text" id="replaceReason" placeholder="Motivo" value="Produto indisponível">
                <button class="btn btn-primary" onclick="confirmReplace()">Confirmar Substituição</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
const ORDER_ID = <?= $orderId ?>;
let html5QrCode = null;
let totalItems = <?= $totalItems ?>;
let pickedItems = <?= $pickedItems ?>;

// Toast
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show ' + type;
    setTimeout(() => t.className = 'toast', 3000);
}

// Update Progress
function updateProgress(done, total) {
    pickedItems = done;
    totalItems = total;
    const pct = Math.round((done / total) * 100);
    document.getElementById('progressCount').textContent = `${done}/${total} itens`;
    document.getElementById('progressFill').style.width = pct + '%';
    document.getElementById('finishBtn').disabled = pct < 100;
}

// Toggle Item Status
function toggleItem(itemId, status) {
    fetch('api/update-item.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({item_id: itemId, status: status})
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            const card = document.querySelector(`[data-item-id="${itemId}"]`);
            const btn = card.querySelector('.item-btn.check');
            
            if (status === 'picked') {
                card.classList.add('picked');
                btn.classList.add('active');
            } else if (status === 'missing') {
                card.classList.add('picked');
                card.style.background = '#FEE2E2';
            }
            
            updateProgress(d.progress.done, d.progress.total);
            showToast(status === 'picked' ? 'Item coletado!' : 'Item marcado como faltante');
        } else {
            showToast(d.error || 'Erro', 'error');
        }
    });
}

// Scanner
function openScanner() {
    document.getElementById('scannerModal').classList.add('active');
    startScanner();
}

function closeScanner() {
    document.getElementById('scannerModal').classList.remove('active');
    stopScanner();
}

function startScanner() {
    if (html5QrCode) return;
    
    html5QrCode = new Html5Qrcode("scanner-container");
    html5QrCode.start(
        { facingMode: "environment" },
        { fps: 10, qrbox: { width: 250, height: 250 } },
        (decodedText) => {
            onScanSuccess(decodedText);
        },
        (errorMessage) => {}
    ).catch(err => {
        console.log('Scanner error:', err);
        showToast('Erro ao iniciar câmera', 'error');
    });
}

function stopScanner() {
    if (html5QrCode) {
        html5QrCode.stop().then(() => {
            html5QrCode = null;
        }).catch(err => console.log(err));
    }
}

function onScanSuccess(ean) {
    // Vibrar
    if (navigator.vibrate) navigator.vibrate(100);
    
    // Procurar item com este EAN
    const item = document.querySelector(`[data-ean="${ean}"]`);
    if (item) {
        const itemId = item.dataset.itemId;
        toggleItem(itemId, 'picked');
        closeScanner();
    } else {
        showToast('Produto não encontrado neste pedido', 'error');
    }
}

function searchByEan() {
    const ean = document.getElementById('manualEan').value.trim();
    if (ean) {
        onScanSuccess(ean);
        document.getElementById('manualEan').value = '';
    }
}

// QR Code Handoff
function openQRCode() {
    document.getElementById('qrModal').classList.add('active');
    generateQRCode();
}

function closeQRCode() {
    document.getElementById('qrModal').classList.remove('active');
}

function generateQRCode() {
    fetch('api/generate-qrcode.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({order_id: ORDER_ID})
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            document.getElementById('handoffCode').textContent = d.handoff_code;
            document.getElementById('qrCodeContainer').innerHTML = `<img src="${d.qr_url}" alt="QR Code">`;
        } else {
            showToast(d.error || 'Erro ao gerar QR', 'error');
        }
    });
}

// Replace
function openReplace(itemId, itemName) {
    document.getElementById('replaceModal').classList.add('active');
    document.getElementById('replaceItemId').value = itemId;
    document.getElementById('replacingItem').textContent = itemName;
}

function closeReplace() {
    document.getElementById('replaceModal').classList.remove('active');
}

function confirmReplace() {
    const itemId = document.getElementById('replaceItemId').value;
    const data = {
        item_id: itemId,
        replacement_name: document.getElementById('replaceName').value,
        replacement_price: parseFloat(document.getElementById('replacePrice').value) || 0,
        replacement_ean: document.getElementById('replaceEan').value,
        reason: document.getElementById('replaceReason').value
    };
    
    if (!data.replacement_name) {
        showToast('Informe o nome do produto', 'error');
        return;
    }
    
    fetch('api/replace-item.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            showToast('Produto substituído!');
            closeReplace();
            location.reload();
        } else {
            showToast(d.error || 'Erro', 'error');
        }
    });
}

// Finish Shopping
function finishShopping() {
    if (confirm('Finalizar compras e aguardar entregador?')) {
        fetch('api/complete-shopping.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({order_id: ORDER_ID})
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                showToast('Compras finalizadas!');
                setTimeout(() => openQRCode(), 500);
            } else {
                showToast(d.error || 'Erro', 'error');
            }
        });
    }
}
</script>

</body>
</html>