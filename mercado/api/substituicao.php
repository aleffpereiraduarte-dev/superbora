<?php
/**
 * API SUBSTITUIÇÃO DE PRODUTOS
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$pdo = getPDO();

// Criar tabela
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS om_market_substitutions (
            substitution_id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            item_id INT NOT NULL DEFAULT 0,
            original_name VARCHAR(255) NOT NULL,
            original_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            suggested_name VARCHAR(255) NOT NULL,
            suggested_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            reason VARCHAR(255) DEFAULT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            shopper_id INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            responded_at DATETIME DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

// Input
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input) $input = array_merge($_GET, $_POST);

$action = isset($input['action']) ? $input['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

// GET - Lista
if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($action)) {
    $order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
    if (!$order_id) {
        echo json_encode(['success' => false, 'error' => 'order_id obrigatório']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT * FROM om_market_substitutions WHERE order_id = ? ORDER BY created_at DESC");
    $stmt->execute([$order_id]);
    echo json_encode(['success' => true, 'substitutions' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// SUGERIR
if ($action === 'sugerir') {
    $order_id = isset($input['order_id']) ? (int)$input['order_id'] : 0;
    $item_id = isset($input['item_id']) ? (int)$input['item_id'] : 0;
    $shopper_id = isset($input['shopper_id']) ? (int)$input['shopper_id'] : 0;
    $original_name = isset($input['original_name']) ? trim($input['original_name']) : '';
    $original_price = isset($input['original_price']) ? (float)$input['original_price'] : 0;
    $suggested_name = isset($input['suggested_name']) ? trim($input['suggested_name']) : '';
    $suggested_price = isset($input['suggested_price']) ? (float)$input['suggested_price'] : 0;
    $reason = isset($input['reason']) ? trim($input['reason']) : 'Produto indisponível';
    
    if (!$order_id || !$original_name || !$suggested_name) {
        echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT order_id, status, shopper_name FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
        exit;
    }
    
    if (!in_array($order['status'], ['confirmed', 'shopping'])) {
        echo json_encode(['success' => false, 'error' => 'Pedido não está em compras (status: ' . $order['status'] . ')']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("INSERT INTO om_market_substitutions (order_id, item_id, original_name, original_price, suggested_name, suggested_price, reason, shopper_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$order_id, $item_id, $original_name, $original_price, $suggested_name, $suggested_price, $reason, $shopper_id]);
        $sub_id = $pdo->lastInsertId();
        
        $diff = $suggested_price - $original_price;
        $diff_text = $diff > 0 ? "+R$ " . number_format($diff, 2, ',', '.') : ($diff < 0 ? "-R$ " . number_format(abs($diff), 2, ',', '.') : "mesmo preço");
        $msg = "🔄 Substituição sugerida\n\n❌ {$original_name}\n✅ {$suggested_name}\n💰 R$ " . number_format($suggested_price, 2, ',', '.') . " ({$diff_text})";
        
        $shopper_name = $order['shopper_name'] ? $order['shopper_name'] : 'Shopper';
        $pdo->prepare("INSERT INTO om_market_chat (order_id, sender_type, sender_id, sender_name, message, date_added) VALUES (?, 'shopper', ?, ?, ?, NOW())")->execute([$order_id, $shopper_id, $shopper_name, $msg]);
        
        echo json_encode(['success' => true, 'substitution_id' => $sub_id]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// RESPONDER
if ($action === 'responder') {
    $substitution_id = isset($input['substitution_id']) ? (int)$input['substitution_id'] : 0;
    $response = isset($input['response']) ? $input['response'] : '';
    
    if (!$substitution_id || !in_array($response, ['approve', 'reject'])) {
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT * FROM om_market_substitutions WHERE substitution_id = ?");
    $stmt->execute([$substitution_id]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$sub) {
        echo json_encode(['success' => false, 'error' => 'Não encontrada']);
        exit;
    }
    
    if ($sub['status'] !== 'pending') {
        echo json_encode(['success' => false, 'error' => 'Já respondida']);
        exit;
    }
    
    $new_status = ($response === 'approve') ? 'approved' : 'rejected';
    $pdo->prepare("UPDATE om_market_substitutions SET status = ?, responded_at = NOW() WHERE substitution_id = ?")->execute([$new_status, $substitution_id]);
    
    if ($response === 'approve' && $sub['item_id']) {
        $pdo->prepare("UPDATE om_market_order_items SET name = ?, price = ?, total = quantity * ? WHERE item_id = ?")->execute([$sub['suggested_name'], $sub['suggested_price'], $sub['suggested_price'], $sub['item_id']]);
    }
    
    $msg = $response === 'approve' ? "✅ Substituição aprovada!" : "❌ Substituição recusada";
    $pdo->prepare("INSERT INTO om_market_chat (order_id, sender_type, sender_id, sender_name, message, date_added) VALUES (?, 'system', 0, 'Sistema', ?, NOW())")->execute([$sub['order_id'], $msg]);
    
    echo json_encode(['success' => true, 'status' => $new_status]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Ação não reconhecida']);
