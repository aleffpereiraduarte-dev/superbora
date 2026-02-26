<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  🔧 INSTALADOR - COMPLETA OS 9 ITENS FALTANTES
 *  OneMundo Shopper App
 * ═══════════════════════════════════════════════════════════════════════════════
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$baseDir = __DIR__;
$results = [];

function saveFile($path, $content) {
    global $results, $baseDir;
    $fullPath = $baseDir . '/' . $path;
    $dir = dirname($fullPath);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (file_put_contents($fullPath, $content)) {
        $results[] = ['status' => 'ok', 'file' => $path];
        return true;
    }
    $results[] = ['status' => 'error', 'file' => $path];
    return false;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 1. API: UPDATE ITEM (Marcar item como coletado)
// ═══════════════════════════════════════════════════════════════════════════════

$apiUpdateItem = <<<'PHP'
<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$workerId = $_SESSION['worker_id'];
$itemId = $data['item_id'] ?? 0;
$status = $data['status'] ?? 'picked';
$quantity = $data['quantity'] ?? null;

if (!$itemId) {
    echo json_encode(['success' => false, 'error' => 'Item não informado']);
    exit;
}

try {
    $pdo = getDB();
    
    // Verificar permissão
    $stmt = $pdo->prepare("
        SELECT oi.*, o.shopper_id, o.order_id
        FROM om_market_order_items oi
        JOIN om_market_orders o ON oi.order_id = o.order_id
        WHERE oi.item_id = ? AND o.shopper_id = ?
    ");
    $stmt->execute([$itemId, $workerId]);
    $item = $stmt->fetch();
    
    if (!$item) {
        echo json_encode(['success' => false, 'error' => 'Item não encontrado']);
        exit;
    }
    
    // Atualizar
    $sql = "UPDATE om_market_order_items SET status = ?, picked_at = NOW()";
    $params = [$status];
    
    if ($quantity !== null) {
        $sql .= ", picked_quantity = ?";
        $params[] = $quantity;
    }
    
    $sql .= " WHERE item_id = ?";
    $params[] = $itemId;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    // Progresso
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN status IN ('picked','replaced') THEN 1 ELSE 0 END) as done
        FROM om_market_order_items WHERE order_id = ?
    ");
    $stmt->execute([$item['order_id']]);
    $progress = $stmt->fetch();
    
    echo json_encode([
        'success' => true,
        'progress' => [
            'done' => (int)$progress['done'],
            'total' => (int)$progress['total']
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
PHP;
saveFile('api/update-item.php', $apiUpdateItem);

// ═══════════════════════════════════════════════════════════════════════════════
// 2. API: REPLACE ITEM (Substituir produto)
// ═══════════════════════════════════════════════════════════════════════════════

$apiReplaceItem = <<<'PHP'
<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$workerId = $_SESSION['worker_id'];
$itemId = $data['item_id'] ?? 0;
$replacementName = $data['replacement_name'] ?? '';
$replacementPrice = $data['replacement_price'] ?? 0;
$replacementEan = $data['replacement_ean'] ?? '';
$reason = $data['reason'] ?? 'Produto indisponível';

if (!$itemId || !$replacementName) {
    echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
    exit;
}

try {
    $pdo = getDB();
    $pdo->beginTransaction();
    
    // Verificar permissão
    $stmt = $pdo->prepare("
        SELECT oi.*, o.shopper_id, o.order_id
        FROM om_market_order_items oi
        JOIN om_market_orders o ON oi.order_id = o.order_id
        WHERE oi.item_id = ? AND o.shopper_id = ?
    ");
    $stmt->execute([$itemId, $workerId]);
    $item = $stmt->fetch();
    
    if (!$item) {
        throw new Exception('Item não encontrado');
    }
    
    // Atualizar como substituído
    $stmt = $pdo->prepare("
        UPDATE om_market_order_items SET 
            status = 'replaced',
            replacement_name = ?,
            replacement_price = ?,
            replacement_ean = ?,
            replacement_reason = ?,
            picked_at = NOW()
        WHERE item_id = ?
    ");
    $stmt->execute([$replacementName, $replacementPrice, $replacementEan, $reason, $itemId]);
    
    // Mensagem no chat
    $msg = "📦 Substituição: \"{$item['product_name']}\" → \"{$replacementName}\"";
    $stmt = $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message, created_at) VALUES (?, 'shopper', ?, ?, NOW())");
    $stmt->execute([$item['order_id'], $workerId, $msg]);
    
    $pdo->commit();
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
PHP;
saveFile('api/replace-item.php', $apiReplaceItem);

// ═══════════════════════════════════════════════════════════════════════════════
// 3. API: GENERATE QRCODE (QR Code handoff para delivery)
// ═══════════════════════════════════════════════════════════════════════════════

$apiGenerateQR = <<<'PHP'
<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$workerId = $_SESSION['worker_id'];
$orderId = $data['order_id'] ?? 0;

if (!$orderId) {
    echo json_encode(['success' => false, 'error' => 'Pedido não informado']);
    exit;
}

try {
    $pdo = getDB();
    
    // Verificar permissão
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND shopper_id = ?");
    $stmt->execute([$orderId, $workerId]);
    $order = $stmt->fetch();
    
    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
        exit;
    }
    
    // Gerar código único para handoff
    $handoffCode = strtoupper(substr(md5($orderId . time() . $workerId), 0, 8));
    
    // Salvar código
    $stmt = $pdo->prepare("UPDATE om_market_orders SET handoff_code = ?, handoff_generated_at = NOW() WHERE order_id = ?");
    $stmt->execute([$handoffCode, $orderId]);
    
    // Dados para o QR Code
    $qrData = json_encode([
        'type' => 'onemundo_handoff',
        'order_id' => $orderId,
        'code' => $handoffCode,
        'shopper_id' => $workerId,
        'timestamp' => time()
    ]);
    
    // URL do QR Code usando API do Google Charts
    $qrUrl = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . urlencode($qrData) . '&choe=UTF-8';
    
    echo json_encode([
        'success' => true,
        'handoff_code' => $handoffCode,
        'qr_data' => $qrData,
        'qr_url' => $qrUrl
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
PHP;
saveFile('api/generate-qrcode.php', $apiGenerateQR);

// ═══════════════════════════════════════════════════════════════════════════════
// 4. API: GET MESSAGES (Histórico do chat)
// ═══════════════════════════════════════════════════════════════════════════════

$apiGetMessages = <<<'PHP'
<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$workerId = $_SESSION['worker_id'];
$orderId = $_GET['order_id'] ?? 0;
$lastId = $_GET['last_id'] ?? 0;

if (!$orderId) {
    echo json_encode(['success' => false, 'error' => 'Pedido não informado']);
    exit;
}

try {
    $pdo = getDB();
    
    // Verificar permissão
    $stmt = $pdo->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND shopper_id = ?");
    $stmt->execute([$orderId, $workerId]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Não autorizado']);
        exit;
    }
    
    // Buscar mensagens
    $sql = "SELECT * FROM om_order_chat WHERE order_id = ?";
    $params = [$orderId];
    
    if ($lastId > 0) {
        $sql .= " AND chat_id > ?";
        $params[] = $lastId;
    }
    
    $sql .= " ORDER BY created_at ASC LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $messages = $stmt->fetchAll();
    
    // Formatar
    $formatted = [];
    foreach ($messages as $msg) {
        $formatted[] = [
            'id' => $msg['chat_id'],
            'sender_type' => $msg['sender_type'],
            'sender_id' => $msg['sender_id'],
            'message' => $msg['message'],
            'image_url' => $msg['image_url'] ?? null,
            'time' => date('H:i', strtotime($msg['created_at'])),
            'is_mine' => ($msg['sender_type'] === 'shopper' && $msg['sender_id'] == $workerId)
        ];
    }
    
    echo json_encode([
        'success' => true,
        'messages' => $formatted,
        'count' => count($formatted)
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
PHP;
saveFile('api/get-messages.php', $apiGetMessages);

// ═══════════════════════════════════════════════════════════════════════════════
// 5. API: UPLOAD PHOTO (Foto de entrega/perfil)
// ═══════════════════════════════════════════════════════════════════════════════

$apiUploadPhoto = <<<'PHP'
<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$workerId = $_SESSION['worker_id'];
$type = $_POST['type'] ?? 'delivery'; // delivery, profile, chat
$orderId = $_POST['order_id'] ?? 0;

// Verificar se tem arquivo ou base64
$imageData = null;
$filename = null;

if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $imageData = file_get_contents($_FILES['photo']['tmp_name']);
    $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION) ?: 'jpg';
} elseif (isset($_POST['photo_base64'])) {
    $base64 = $_POST['photo_base64'];
    if (preg_match('/^data:image\/(\w+);base64,/', $base64, $matches)) {
        $ext = $matches[1];
        $imageData = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $base64));
    }
}

if (!$imageData) {
    echo json_encode(['success' => false, 'error' => 'Nenhuma imagem enviada']);
    exit;
}

try {
    $pdo = getDB();
    
    // Criar diretório se não existir
    $uploadDir = __DIR__ . '/../uploads/' . $type . '/' . date('Y/m');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Nome único
    $filename = $workerId . '_' . time() . '_' . uniqid() . '.' . $ext;
    $filepath = $uploadDir . '/' . $filename;
    $relativePath = '/uploads/' . $type . '/' . date('Y/m') . '/' . $filename;
    
    // Salvar arquivo
    if (!file_put_contents($filepath, $imageData)) {
        throw new Exception('Erro ao salvar arquivo');
    }
    
    // Ações conforme tipo
    switch ($type) {
        case 'delivery':
            if ($orderId) {
                $stmt = $pdo->prepare("UPDATE om_market_orders SET delivery_photo = ? WHERE order_id = ? AND (shopper_id = ? OR delivery_id = ?)");
                $stmt->execute([$relativePath, $orderId, $workerId, $workerId]);
            }
            break;
            
        case 'profile':
            $stmt = $pdo->prepare("UPDATE om_market_workers SET photo = ? WHERE worker_id = ?");
            $stmt->execute([$relativePath, $workerId]);
            break;
            
        case 'chat':
            if ($orderId) {
                $stmt = $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message, image_url, created_at) VALUES (?, 'shopper', ?, '[Foto]', ?, NOW())");
                $stmt->execute([$orderId, $workerId, $relativePath]);
            }
            break;
    }
    
    echo json_encode([
        'success' => true,
        'url' => $relativePath,
        'type' => $type
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
PHP;
saveFile('api/upload-photo.php', $apiUploadPhoto);

// ═══════════════════════════════════════════════════════════════════════════════
// 6. SHOPPING.PHP COMPLETO (com Scanner e QR Code)
// ═══════════════════════════════════════════════════════════════════════════════

$shoppingPhp = <<<'PHP'
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
PHP;
saveFile('shopping.php', $shoppingPhp);

// ═══════════════════════════════════════════════════════════════════════════════
// 7. ENTREGA.PHP COMPLETO (com Captura de Foto)
// ═══════════════════════════════════════════════════════════════════════════════

$entregaPhp = <<<'PHP'
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
PHP;
saveFile('entrega.php', $entregaPhp);

// ═══════════════════════════════════════════════════════════════════════════════
// 8. ATUALIZAR FUNCTIONS.PHP (Adicionar notification helper)
// ═══════════════════════════════════════════════════════════════════════════════

$notificationHelper = <<<'PHP'

// ═══════════════════════════════════════════════════════════════════════════════
// NOTIFICATION HELPERS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Criar notificação para worker
 */
function createWorkerNotification($workerId, $title, $message, $type = 'info', $data = []) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO om_worker_notifications 
        (worker_id, title, message, type, data, created_at) 
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    return $stmt->execute([$workerId, $title, $message, $type, json_encode($data)]);
}

/**
 * Enviar push notification (placeholder - integrar com OneSignal/Firebase)
 */
function sendPushNotification($workerId, $title, $body, $data = []) {
    // TODO: Integrar com serviço de push (OneSignal, Firebase, etc)
    // Por enquanto apenas cria notificação no banco
    createWorkerNotification($workerId, $title, $body, 'push', $data);
    return true;
}

/**
 * Notificar nova oferta
 */
function notifyNewOffer($workerId, $orderId, $earning) {
    $title = "Nova oferta de pedido!";
    $message = "Ganhe R$ " . number_format($earning, 2, ',', '.') . " - Aceite agora!";
    return sendPushNotification($workerId, $title, $message, [
        'type' => 'new_offer',
        'order_id' => $orderId,
        'earning' => $earning
    ]);
}

/**
 * Obter notificações não lidas
 */
function getUnreadNotifications($workerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT * FROM om_worker_notifications 
        WHERE worker_id = ? AND read_at IS NULL 
        ORDER BY created_at DESC LIMIT 50
    ");
    $stmt->execute([$workerId]);
    return $stmt->fetchAll();
}

/**
 * Marcar notificação como lida
 */
function markNotificationRead($notificationId, $workerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE om_worker_notifications SET read_at = NOW() WHERE notification_id = ? AND worker_id = ?");
    return $stmt->execute([$notificationId, $workerId]);
}
PHP;

// Verificar se functions.php existe e adicionar
$functionsPath = $baseDir . '/includes/functions.php';
if (file_exists($functionsPath)) {
    $content = file_get_contents($functionsPath);
    if (strpos($content, 'createWorkerNotification') === false) {
        file_put_contents($functionsPath, $content . $notificationHelper);
        $results[] = ['status' => 'ok', 'file' => 'includes/functions.php (atualizado)'];
    } else {
        $results[] = ['status' => 'skip', 'file' => 'includes/functions.php (já tem notifications)'];
    }
} else {
    $results[] = ['status' => 'error', 'file' => 'includes/functions.php não encontrado'];
}

// ═══════════════════════════════════════════════════════════════════════════════
// RESULTADO DA INSTALAÇÃO
// ═══════════════════════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>✅ Instalação Completa</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #108910, #0D6B0D); min-height: 100vh; padding: 20px; display: flex; align-items: center; justify-content: center; }
        .card { background: #fff; border-radius: 24px; padding: 40px; max-width: 600px; width: 100%; box-shadow: 0 25px 50px rgba(0,0,0,0.25); }
        .icon { font-size: 64px; text-align: center; margin-bottom: 20px; }
        h1 { font-size: 28px; text-align: center; margin-bottom: 8px; color: #1C1C1C; }
        .subtitle { text-align: center; color: #8B8B8B; margin-bottom: 30px; }
        .results { background: #F6F6F6; border-radius: 12px; padding: 20px; max-height: 300px; overflow-y: auto; }
        .result { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid #E5E5E5; }
        .result:last-child { border-bottom: none; }
        .result-icon { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px; }
        .result-icon.ok { background: #E8F5E8; color: #108910; }
        .result-icon.error { background: #FEE2E2; color: #dc2626; }
        .result-icon.skip { background: #FEF3C7; color: #D97706; }
        .result-file { font-size: 14px; font-weight: 600; color: #1C1C1C; }
        .summary { display: flex; justify-content: center; gap: 30px; margin: 30px 0; }
        .summary-item { text-align: center; }
        .summary-value { font-size: 32px; font-weight: 800; }
        .summary-value.ok { color: #108910; }
        .summary-value.error { color: #dc2626; }
        .summary-label { font-size: 12px; color: #8B8B8B; }
        .actions { display: flex; gap: 12px; justify-content: center; margin-top: 20px; }
        .btn { padding: 14px 28px; border-radius: 12px; font-size: 15px; font-weight: 600; text-decoration: none; transition: all 0.2s; }
        .btn-primary { background: #108910; color: #fff; }
        .btn-secondary { background: #F6F6F6; color: #1C1C1C; }
    </style>
</head>
<body>
<div class="card">
    <div class="icon">✅</div>
    <h1>Instalação Completa!</h1>
    <p class="subtitle">9 itens faltantes foram adicionados</p>
    
    <div class="summary">
        <div class="summary-item">
            <div class="summary-value ok"><?= count(array_filter($results, fn($r) => $r['status'] === 'ok')) ?></div>
            <div class="summary-label">Criados</div>
        </div>
        <div class="summary-item">
            <div class="summary-value" style="color:#D97706;"><?= count(array_filter($results, fn($r) => $r['status'] === 'skip')) ?></div>
            <div class="summary-label">Ignorados</div>
        </div>
        <div class="summary-item">
            <div class="summary-value error"><?= count(array_filter($results, fn($r) => $r['status'] === 'error')) ?></div>
            <div class="summary-label">Erros</div>
        </div>
    </div>
    
    <div class="results">
        <?php foreach ($results as $r): ?>
        <div class="result">
            <div class="result-icon <?= $r['status'] ?>">
                <?= $r['status'] === 'ok' ? '✓' : ($r['status'] === 'error' ? '✕' : '○') ?>
            </div>
            <div class="result-file"><?= $r['file'] ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <div class="actions">
        <a href="DIAGNOSTICO_COMPLETO.php" class="btn btn-secondary">🔍 Verificar Novamente</a>
        <a href="dashboard.php" class="btn btn-primary">📱 Abrir App</a>
    </div>
</div>
</body>
</html>
