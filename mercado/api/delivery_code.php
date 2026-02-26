<?php
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/config/database.php';

try {
    $db = getPDO();
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'error' => 'DB Error']));
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$params = array_merge($_GET, $_POST, $input);

switch ($action) {
    
    case 'generate':
        // Gerar código para o pedido
        $orderId = intval($params['order_id'] ?? 0);
        
        if (!$orderId) {
            echo json_encode(['success' => false, 'error' => 'Order ID required']);
            break;
        }
        
        // Gerar PIN de 4 dígitos
        $code = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
        
        $stmt = $db->prepare("UPDATE om_market_orders SET delivery_code = ? WHERE order_id = ?");
        $stmt->execute([$code, $orderId]);
        
        echo json_encode([
            'success' => true,
            'order_id' => $orderId,
            'delivery_code' => $code
        ]);
        break;
        
    case 'verify':
        // Entregador verifica o código
        $orderId = intval($params['order_id'] ?? 0);
        $code = trim($params['code'] ?? '');
        
        if (!$orderId || !$code) {
            echo json_encode(['success' => false, 'error' => 'Missing params']);
            break;
        }
        
        // Verificar código
        $stmt = $db->prepare("SELECT delivery_code, delivery_code_verified FROM om_market_orders WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(['success' => false, 'error' => 'Order not found']);
            break;
        }
        
        if ($order['delivery_code_verified']) {
            echo json_encode(['success' => false, 'error' => 'Code already verified']);
            break;
        }
        
        if ($order['delivery_code'] !== $code) {
            echo json_encode(['success' => false, 'error' => 'Invalid code']);
            break;
        }
        
        // Marcar como verificado e entregue
        $stmt = $db->prepare("UPDATE om_market_orders SET 
            delivery_code_verified = 1, 
            delivery_code_verified_at = NOW(),
            status = 'delivered',
            delivered_at = NOW()
            WHERE order_id = ?");
        $stmt->execute([$orderId]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Entrega confirmada!'
        ]);
        break;
        
    case 'status':
        $orderId = intval($params['order_id'] ?? 0);
        $stmt = $db->prepare("SELECT delivery_code, delivery_code_verified, delivery_code_verified_at FROM om_market_orders WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => (bool)$order,
            'data' => $order ?: null
        ]);
        break;
        
    default:
        echo json_encode(['api' => 'Delivery Code API', 'actions' => ['generate', 'verify', 'status']]);
}