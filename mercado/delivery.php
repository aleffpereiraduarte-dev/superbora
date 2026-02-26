<?php
/**
 * 🚴 API DO APP DELIVERY
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

session_start();
require_once __DIR__ . '/../../config.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$delivery_id = $_SESSION['delivery_id'] ?? 0;

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// AÇÕES
// ═══════════════════════════════════════════════════════════════════════════════

switch ($action) {
    
    // Buscar ofertas disponíveis
    case 'get_offers':
        if (!$delivery_id) {
            echo json_encode(['success' => false, 'error' => 'Não autenticado']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT o.*, 
                   ord.order_number, ord.total, ord.delivery_address,
                   p.trade_name as market_name
            FROM om_delivery_offers o
            JOIN om_market_orders ord ON o.order_id = ord.order_id
            LEFT JOIN om_market_partners p ON ord.partner_id = p.partner_id
            WHERE o.delivery_id = ? AND o.status = 'pending'
            ORDER BY o.created_at DESC
        ");
        $stmt->execute([$delivery_id]);
        echo json_encode(['success' => true, 'offers' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;
        
    // Aceitar oferta
    case 'accept_offer':
        $offer_id = (int)($_POST['offer_id'] ?? 0);
        if (!$delivery_id || !$offer_id) {
            echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
            exit;
        }
        
        $pdo->beginTransaction();
        try {
            // Atualizar oferta
            $stmt = $pdo->prepare("UPDATE om_delivery_offers SET status = 'accepted', accepted_at = NOW() WHERE offer_id = ? AND delivery_id = ?");
            $stmt->execute([$offer_id, $delivery_id]);
            
            // Buscar order_id
            $stmt = $pdo->prepare("SELECT order_id FROM om_delivery_offers WHERE offer_id = ?");
            $stmt->execute([$offer_id]);
            $offer = $stmt->fetch();
            
            if ($offer) {
                // Atualizar pedido
                $stmt = $pdo->prepare("UPDATE om_market_orders SET delivery_id = ?, status = 'delivery_assigned' WHERE order_id = ?");
                $stmt->execute([$delivery_id, $offer['order_id']]);
                
                // Rejeitar outras ofertas
                $stmt = $pdo->prepare("UPDATE om_delivery_offers SET status = 'rejected' WHERE order_id = ? AND offer_id != ?");
                $stmt->execute([$offer['order_id'], $offer_id]);
            }
            
            $pdo->commit();
            echo json_encode(['success' => true, 'order_id' => $offer['order_id'] ?? 0]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
        
    // Recusar oferta
    case 'reject_offer':
        $offer_id = (int)($_POST['offer_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE om_delivery_offers SET status = 'rejected' WHERE offer_id = ? AND delivery_id = ?");
        $stmt->execute([$offer_id, $delivery_id]);
        echo json_encode(['success' => true]);
        break;
        
    // Atualizar localização
    case 'update_location':
        $lat = (float)($_POST['lat'] ?? 0);
        $lng = (float)($_POST['lng'] ?? 0);
        
        if ($delivery_id && $lat && $lng) {
            $stmt = $pdo->prepare("
                INSERT INTO om_delivery_locations (delivery_id, latitude, longitude, updated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE latitude = ?, longitude = ?, updated_at = NOW()
            ");
            $stmt->execute([$delivery_id, $lat, $lng, $lat, $lng]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false]);
        }
        break;
        
    // Buscar pedido atual
    case 'get_current_order':
        if (!$delivery_id) {
            echo json_encode(['success' => false, 'error' => 'Não autenticado']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT o.*, p.trade_name as market_name, p.address as market_address
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.delivery_id = ? AND o.status IN ('delivery_assigned', 'out_for_delivery')
            ORDER BY o.date_modified DESC
            LIMIT 1
        ");
        $stmt->execute([$delivery_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'order' => $order ?: null]);
        break;
        
    // Confirmar coleta
    case 'confirm_pickup':
        $order_id = (int)($_POST['order_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE om_market_orders SET status = 'out_for_delivery', pickup_at = NOW() WHERE order_id = ? AND delivery_id = ?");
        $stmt->execute([$order_id, $delivery_id]);
        echo json_encode(['success' => $stmt->rowCount() > 0]);
        break;
        
    // Confirmar entrega
    case 'confirm_delivery':
        $order_id = (int)($_POST['order_id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        
        // Verificar código
        $stmt = $pdo->prepare("SELECT delivery_code FROM om_market_orders WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();
        
        if (!$order || strtoupper($order['delivery_code']) !== strtoupper($code)) {
            echo json_encode(['success' => false, 'error' => 'Código inválido']);
            exit;
        }
        
        $stmt = $pdo->prepare("UPDATE om_market_orders SET status = 'delivered', delivered_at = NOW() WHERE order_id = ? AND delivery_id = ?");
        $stmt->execute([$order_id, $delivery_id]);
        echo json_encode(['success' => true]);
        break;
        
    // Estatísticas
    case 'get_stats':
        if (!$delivery_id) {
            echo json_encode(['success' => false]);
            exit;
        }
        
        $stats = [];
        
        // Entregas hoje
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_market_orders WHERE delivery_id = ? AND status = 'delivered' AND DATE(delivered_at) = CURRENT_DATE");
        $stmt->execute([$delivery_id]);
        $stats['hoje'] = (int)$stmt->fetchColumn();
        
        // Entregas semana
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_market_orders WHERE delivery_id = ? AND status = 'delivered' AND delivered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stmt->execute([$delivery_id]);
        $stats['semana'] = (int)$stmt->fetchColumn();
        
        // Ganhos hoje
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(delivery_fee), 0) FROM om_market_orders WHERE delivery_id = ? AND status = 'delivered' AND DATE(delivered_at) = CURRENT_DATE");
        $stmt->execute([$delivery_id]);
        $stats['ganhos_hoje'] = (float)$stmt->fetchColumn();
        
        echo json_encode(['success' => true, 'stats' => $stats]);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Ação inválida']);
}