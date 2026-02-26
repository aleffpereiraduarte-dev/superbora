<?php
require_once __DIR__ . '/../config/database.php';
/**
 * ══════════════════════════════════════════════════════════════
 * 📦 ONEMUNDO - SISTEMA DE QR CODE PARA ENTREGAS
 * Gera QR para caixa + validação por scanner
 * ══════════════════════════════════════════════════════════════
 *
 * FLUXO:
 * 1. Shopper finaliza → Gera QR da caixa → Imprime
 * 2. Delivery pega → Escaneia QR da caixa (confirma caixa certa)
 * 3. Cliente mostra QR no celular → Delivery escaneia → Entrega confirmada
 */

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'error' => 'Erro de conexão']));
}

// ══════════════════════════════════════════════════════════════
// FUNÇÕES AUXILIARES
// ══════════════════════════════════════════════════════════════

/**
 * Gera código único para QR
 */
function gerarCodigoQR($order_id, $tipo = 'box') {
    $prefix = $tipo === 'box' ? 'BOX' : 'DEL';
    $hash = strtoupper(substr(md5($order_id . time() . rand()), 0, 8));
    return $prefix . '-' . $order_id . '-' . $hash;
}

/**
 * Gera URL do QR Code usando API gratuita
 */
function gerarQRCodeURL($data, $size = 300) {
    $encoded = urlencode($data);
    return "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data={$encoded}&bgcolor=ffffff&color=000000&margin=10";
}

/**
 * Gera QR Code em Base64 (alternativa offline)
 */
function gerarQRCodeBase64($data, $size = 300) {
    // Usando API externa para simplicidade
    $url = gerarQRCodeURL($data, $size);
    $imageData = @file_get_contents($url);
    if ($imageData) {
        return 'data:image/png;base64,' . base64_encode($imageData);
    }
    return null;
}

// ══════════════════════════════════════════════════════════════
// API ENDPOINTS
// ══════════════════════════════════════════════════════════════

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ─────────────────────────────────────────────────────────────
// GERAR QR CODE DA CAIXA (Shopper)
// ─────────────────────────────────────────────────────────────
if ($action === 'generate_box_qr') {
    $order_id = intval($_POST['order_id'] ?? 0);
    
    if (!$order_id) {
        echo json_encode(['success' => false, 'error' => 'Order ID inválido']);
        exit;
    }
    
    // Verificar se já tem código
    $stmt = $pdo->prepare("SELECT box_qr_code FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $box_code = $order['box_qr_code'] ?? null;
    
    // Gerar novo código se não existir
    if (!$box_code) {
        $box_code = gerarCodigoQR($order_id, 'box');
        $stmt = $pdo->prepare("UPDATE om_market_orders SET box_qr_code = ? WHERE order_id = ?");
        $stmt->execute([$box_code, $order_id]);
    }
    
    echo json_encode([
        'success' => true,
        'code' => $box_code,
        'qr_url' => gerarQRCodeURL($box_code, 400)
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────
// VALIDAR QR DA CAIXA (Delivery pegando no mercado)
// ─────────────────────────────────────────────────────────────
if ($action === 'validate_box_qr') {
    $scanned_code = trim($_POST['code'] ?? '');
    $delivery_id = intval($_POST['delivery_id'] ?? 0);
    
    if (!$scanned_code) {
        echo json_encode(['success' => false, 'error' => 'Código inválido']);
        exit;
    }
    
    // Buscar pedido pelo código da caixa
    $stmt = $pdo->prepare("
        SELECT o.*, p.name as partner_name 
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.box_qr_code = ?
    ");
    $stmt->execute([$scanned_code]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Caixa não encontrada']);
        exit;
    }
    
    // Marcar que o delivery pegou a caixa
    $stmt = $pdo->prepare("UPDATE om_market_orders SET box_picked_at = NOW(), box_picked_by = ? WHERE order_id = ?");
    $stmt->execute([$delivery_id, $order['order_id']]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Caixa confirmada!',
        'order' => [
            'order_id' => $order['order_id'],
            'customer_name' => $order['customer_name'],
            'address' => $order['shipping_address'],
            'delivery_code' => $order['delivery_code']
        ]
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────
// GERAR QR DE ENTREGA (Cliente no banner)
// ─────────────────────────────────────────────────────────────
if ($action === 'generate_delivery_qr') {
    $order_id = intval($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
    
    if (!$order_id) {
        echo json_encode(['success' => false, 'error' => 'Order ID inválido']);
        exit;
    }
    
    // Buscar código de entrega
    $stmt = $pdo->prepare("SELECT delivery_code FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order || !$order['delivery_code']) {
        echo json_encode(['success' => false, 'error' => 'Código não encontrado']);
        exit;
    }
    
    // Gerar QR com o código de entrega
    $qr_data = 'DELIVERY:' . $order['delivery_code'];
    
    echo json_encode([
        'success' => true,
        'code' => $order['delivery_code'],
        'qr_url' => gerarQRCodeURL($qr_data, 300)
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────
// VALIDAR QR DE ENTREGA (Delivery confirmando entrega)
// ─────────────────────────────────────────────────────────────
if ($action === 'validate_delivery_qr') {
    $scanned_code = trim($_POST['code'] ?? '');
    $route_id = intval($_POST['route_id'] ?? 0);
    $order_id = intval($_POST['order_id'] ?? 0);
    
    // Limpar prefixo se escaneou QR
    $scanned_code = str_replace('DELIVERY:', '', $scanned_code);
    $scanned_code = strtoupper(trim($scanned_code));
    
    if (!$scanned_code) {
        echo json_encode(['success' => false, 'error' => 'Código inválido']);
        exit;
    }
    
    // Buscar pedido
    $stmt = $pdo->prepare("SELECT delivery_code FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
        exit;
    }
    
    // Validar código
    if ($order['delivery_code'] !== $scanned_code) {
        echo json_encode(['success' => false, 'error' => 'Código incorreto']);
        exit;
    }
    
    // Confirmar entrega
    $chat_expires = date('Y-m-d H:i:s', strtotime('+60 minutes'));
    
    $pdo->exec("UPDATE om_market_orders SET status = 'delivered', delivered_at = NOW() WHERE order_id = $order_id");
    $pdo->exec("UPDATE om_order_assignments SET status = 'completed', delivered_at = NOW(), chat_expires_at = '$chat_expires' WHERE order_id = $order_id");
    
    if ($route_id) {
        $pdo->exec("UPDATE om_delivery_route_stops SET status = 'completed', delivered_at = NOW() WHERE route_id = $route_id AND order_id = $order_id");
        $pdo->exec("UPDATE om_delivery_routes SET completed_stops = completed_stops + 1 WHERE route_id = $route_id");
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Entrega confirmada!'
    ]);
    exit;
}

// Resposta padrão
echo json_encode(['success' => false, 'error' => 'Ação não reconhecida']);
