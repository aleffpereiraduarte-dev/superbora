<?php
require_once dirname(__DIR__) . '/config/database.php';
header('Content-Type: application/json');

try {
    $db = getPDO();
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'error' => 'DB Error']));
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$params = array_merge($_GET, $_POST, $input);

switch ($action) {
    
    case 'submit':
        $orderId = intval($params['order_id'] ?? 0);
        $customerId = intval($params['customer_id'] ?? 0);
        
        // Buscar dados do pedido
        $stmt = $db->prepare("SELECT shopper_id, delivery_id FROM om_market_orders WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
            break;
        }
        
        $stmt = $db->prepare("INSERT INTO om_market_order_ratings 
            (order_id, customer_id, shopper_id, shopper_rating, shopper_comment, shopper_tags,
             delivery_id, delivery_rating, delivery_comment, delivery_tags,
             overall_rating, overall_comment, tip_amount)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            shopper_rating = VALUES(shopper_rating), shopper_comment = VALUES(shopper_comment),
            delivery_rating = VALUES(delivery_rating), delivery_comment = VALUES(delivery_comment),
            overall_rating = VALUES(overall_rating), overall_comment = VALUES(overall_comment),
            tip_amount = VALUES(tip_amount)");
        
        $stmt->execute([
            $orderId,
            $customerId,
            $order['shopper_id'],
            intval($params['shopper_rating'] ?? 0) ?: null,
            $params['shopper_comment'] ?? null,
            json_encode($params['shopper_tags'] ?? []),
            $order['delivery_id'],
            intval($params['delivery_rating'] ?? 0) ?: null,
            $params['delivery_comment'] ?? null,
            json_encode($params['delivery_tags'] ?? []),
            intval($params['overall_rating'] ?? 0) ?: null,
            $params['overall_comment'] ?? null,
            floatval($params['tip_amount'] ?? 0)
        ]);
        
        // Atualizar média do worker
        foreach (['shopper' => $order['shopper_id'], 'delivery' => $order['delivery_id']] as $type => $workerId) {
            if ($workerId) {
                $stmt = $db->prepare("SELECT AVG({$type}_rating) as avg_rating, COUNT(*) as total 
                    FROM om_market_order_ratings WHERE {$type}_id = ? AND {$type}_rating IS NOT NULL");
                $stmt->execute([$workerId]);
                $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $db->prepare("UPDATE om_workers SET rating = ?, total_ratings = ? WHERE worker_id = ?")
                   ->execute([round($stats['avg_rating'], 2), $stats['total'], $workerId]);
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Avaliação enviada!']);
        break;
        
    case 'get':
        $orderId = intval($params['order_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM om_market_order_ratings WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $rating = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'rating' => $rating]);
        break;
        
    case 'worker_stats':
        $workerId = intval($params['worker_id'] ?? 0);
        $stmt = $db->prepare("SELECT 
            AVG(COALESCE(shopper_rating, delivery_rating)) as avg_rating,
            COUNT(*) as total_ratings
            FROM om_market_order_ratings 
            WHERE shopper_id = ? OR delivery_id = ?");
        $stmt->execute([$workerId, $workerId]);
        
        echo json_encode(['success' => true, 'stats' => $stmt->fetch(PDO::FETCH_ASSOC)]);
        break;
        
    default:
        echo json_encode(['api' => 'Ratings API', 'actions' => ['submit', 'get', 'worker_stats']]);
}