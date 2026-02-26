<?php
/**
 * API Handoff - OneMundo Market
 * Transferência Shopper → Entregador via QR Code
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

session_start();

// Conexao segura via config central
require_once dirname(__DIR__) . '/config/database.php';
try {
    $db = getPDO();
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'error' => 'Database error']));
}

function generateHandoffCode() {
    return strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$params = array_merge($_GET, $_POST, $input);
$workerId = $_SESSION['worker_id'] ?? $params['worker_id'] ?? 0;

switch ($action) {
    case 'generate':
        $orderId = intval($params['order_id'] ?? 0);
        
        if (!$orderId) {
            echo json_encode(['success' => false, 'error' => 'Order ID required']);
            break;
        }
        
        $code = generateHandoffCode();
        
        $stmt = $db->prepare("UPDATE om_market_orders SET 
            handoff_code = ?,
            handoff_generated_at = NOW(),
            handoff_by_worker_id = ?,
            status = 'ready_for_pickup'
            WHERE order_id = ?");
        $stmt->execute([$code, $workerId, $orderId]);
        
        $qrData = json_encode([
            'type' => 'handoff',
            'order_id' => $orderId,
            'code' => $code,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+30 minutes'))
        ]);
        
        echo json_encode([
            'success' => true,
            'order_id' => $orderId,
            'handoff_code' => $code,
            'qr_data' => base64_encode($qrData),
            'expires_in' => 1800
        ]);
        break;
        
    case 'scan':
        $qrData = $params['qr_data'] ?? '';
        $data = json_decode(base64_decode($qrData), true) ?: json_decode($qrData, true);
        
        if (!$data || ($data['type'] ?? '') !== 'handoff') {
            echo json_encode(['success' => false, 'error' => 'QR Code inválido']);
            break;
        }
        
        $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND handoff_code = ?");
        $stmt->execute([$data['order_id'], $data['code']]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(['success' => false, 'error' => 'Código inválido']);
            break;
        }
        
        if ($order['handoff_scanned_at']) {
            echo json_encode(['success' => false, 'error' => 'QR já utilizado']);
            break;
        }
        
        $stmt = $db->prepare("UPDATE om_market_orders SET 
            handoff_scanned_at = NOW(),
            handoff_to_worker_id = ?,
            delivery_id = ?,
            status = 'out_for_delivery'
            WHERE order_id = ?");
        $stmt->execute([$workerId, $workerId, $data['order_id']]);
        
        echo json_encode([
            'success' => true,
            'order_id' => $data['order_id'],
            'message' => 'Handoff realizado!'
        ]);
        break;
        
    case 'status':
        $orderId = intval($params['order_id'] ?? 0);
        $stmt = $db->prepare("SELECT order_id, status, handoff_code, handoff_generated_at, handoff_scanned_at FROM om_market_orders WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => (bool)$order,
            'data' => $order ?: null
        ]);
        break;
        
    default:
        echo json_encode([
            'api' => 'Handoff API',
            'endpoints' => ['generate', 'scan', 'status']
        ]);
}