<?php
require_once __DIR__ . '/../config/database.php';
/**
 * Motor de Matching - OneMundo Market
 * Encontra e notifica shoppers/entregadores disponíveis
 */

header('Content-Type: application/json');

$db = getPDO();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$params = array_merge($_GET, $_POST, $input);

switch ($action) {
    
    case 'find_shoppers':
        $orderId = intval($params['order_id'] ?? 0);
        $wave = intval($params['wave'] ?? 1);
        $radius = [2, 5, 10][$wave - 1] ?? 10; // km por onda
        
        // Buscar localização do mercado
        $stmt = $db->prepare("SELECT p.lat, p.lng FROM om_market_orders o 
            JOIN om_market_partners p ON o.partner_id = p.partner_id WHERE o.order_id = ?");
        $stmt->execute([$orderId]);
        $origin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$origin) {
            echo json_encode(['success' => false, 'error' => 'Origin not found']);
            break;
        }
        
        // Buscar shoppers
        $stmt = $db->prepare("SELECT w.worker_id, w.name, w.rating, dl.lat, dl.lng,
            (6371 * acos(cos(radians(?)) * cos(radians(dl.lat)) * cos(radians(dl.lng) - radians(?)) + sin(radians(?)) * sin(radians(dl.lat)))) AS distance
            FROM om_workers w
            JOIN om_delivery_locations dl ON w.worker_id = dl.worker_id
            WHERE w.type IN ('shopper', 'both') AND w.status = 'aprovado' AND dl.is_online = 1
            HAVING distance < ?
            ORDER BY w.rating DESC, distance ASC
            LIMIT 20");
        $stmt->execute([$origin['lat'], $origin['lng'], $origin['lat'], $radius]);
        
        echo json_encode([
            'success' => true,
            'wave' => $wave,
            'radius_km' => $radius,
            'shoppers' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
        break;
        
    case 'accept_order':
        $offerId = intval($params['offer_id'] ?? 0);
        $workerId = intval($params['worker_id'] ?? 0);
        $type = $params['type'] ?? 'shopper'; // shopper ou delivery
        
        $table = $type === 'shopper' ? 'om_shopper_offers' : 'om_delivery_offers';
        
        $db->beginTransaction();
        try {
            // Verificar e aceitar
            $stmt = $db->prepare("SELECT * FROM $table WHERE offer_id = ? AND worker_id = ? AND status = 'pending' FOR UPDATE");
            $stmt->execute([$offerId, $workerId]);
            $offer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$offer) throw new Exception('Oferta não disponível');
            
            $db->prepare("UPDATE $table SET status = 'accepted', responded_at = NOW() WHERE offer_id = ?")->execute([$offerId]);
            $db->prepare("UPDATE $table SET status = 'expired' WHERE order_id = ? AND offer_id != ?")->execute([$offer['order_id'], $offerId]);
            
            // Atualizar pedido
            $field = $type === 'shopper' ? 'shopper_id' : 'delivery_id';
            $status = $type === 'shopper' ? 'shopping' : 'out_for_delivery';
            $db->prepare("UPDATE om_market_orders SET $field = ?, status = ? WHERE order_id = ?")->execute([$workerId, $status, $offer['order_id']]);
            
            $db->commit();
            echo json_encode(['success' => true, 'order_id' => $offer['order_id']]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
        
    default:
        echo json_encode(['api' => 'Matching Engine', 'actions' => ['find_shoppers', 'accept_order']]);
}