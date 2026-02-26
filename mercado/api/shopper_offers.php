<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║  API DE OFERTAS - SHOPPER                                                    ║
 * ║  Aceitar, rejeitar e listar ofertas disponíveis                              ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 * 
 * Endpoints:
 * GET  ?action=list              - Listar ofertas pendentes
 * POST ?action=accept&offer_id=X - Aceitar oferta
 * POST ?action=reject&offer_id=X - Rejeitar oferta
 * GET  ?action=details&order_id=X - Detalhes do pedido
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(json_encode(['ok' => true]));
}

// Conexão
try {
    $pdo = getPDO();
} catch (Exception $e) {
    die(json_encode(['success' => false, 'error' => 'DB Error']));
}

// Autenticação
session_name('OCSESSID');
session_start();

$shopper_id = $_SESSION['shopper_id'] ?? 0;

// Permitir passar shopper_id via header para testes
if (!$shopper_id && isset($_SERVER['HTTP_X_SHOPPER_ID'])) {
    $shopper_id = (int)$_SERVER['HTTP_X_SHOPPER_ID'];
}

// Input
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? $_GET['action'] ?? '';

// ═══════════════════════════════════════════════════════════════════════════════
// LISTAR OFERTAS PENDENTES
// ═══════════════════════════════════════════════════════════════════════════════

if ($action === 'list') {
    if (!$shopper_id) {
        echo json_encode(['success' => false, 'error' => 'Não autenticado']);
        exit;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            so.offer_id,
            so.order_id,
            so.offer_amount,
            so.expires_at,
            so.wave,
            TIMESTAMPDIFF(SECOND, NOW(), so.expires_at) as seconds_remaining,
            o.order_number,
            o.total,
            o.shipping_neighborhood,
            o.shipping_city,
            (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as items_count,
            p.name as partner_name,
            p.trade_name as partner_trade_name,
            p.address as partner_address
        FROM om_shopper_offers so
        JOIN om_market_orders o ON so.order_id = o.order_id
        JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE so.shopper_id = ?
          AND so.status = 'pending'
          AND so.expires_at > NOW()
        ORDER BY so.expires_at ASC
    ");
    $stmt->execute([$shopper_id]);
    $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatar dados
    foreach ($offers as &$offer) {
        $offer['offer_amount'] = (float)$offer['offer_amount'];
        $offer['total'] = (float)$offer['total'];
        $offer['seconds_remaining'] = max(0, (int)$offer['seconds_remaining']);
        $offer['partner_display_name'] = $offer['partner_trade_name'] ?: $offer['partner_name'];
    }
    
    echo json_encode([
        'success' => true,
        'offers' => $offers,
        'count' => count($offers)
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// ACEITAR OFERTA
// ═══════════════════════════════════════════════════════════════════════════════

if ($action === 'accept') {
    $offer_id = (int)($input['offer_id'] ?? $_GET['offer_id'] ?? 0);
    
    if (!$shopper_id) {
        echo json_encode(['success' => false, 'error' => 'Não autenticado']);
        exit;
    }
    
    if (!$offer_id) {
        echo json_encode(['success' => false, 'error' => 'offer_id obrigatório']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Verificar oferta
        $stmt = $pdo->prepare("
            SELECT so.*, o.order_number, o.status as order_status, o.shopper_id as current_shopper
            FROM om_shopper_offers so
            JOIN om_market_orders o ON so.order_id = o.order_id
            WHERE so.offer_id = ? AND so.shopper_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$offer_id, $shopper_id]);
        $offer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$offer) {
            throw new Exception('Oferta não encontrada');
        }
        
        if ($offer['status'] !== 'pending') {
            throw new Exception('Oferta já foi processada');
        }
        
        if (strtotime($offer['expires_at']) < time()) {
            throw new Exception('Oferta expirada');
        }
        
        if ($offer['current_shopper']) {
            throw new Exception('Pedido já tem um shopper');
        }
        
        // Verificar se shopper não está ocupado
        $stmt = $pdo->prepare("
            SELECT order_id FROM om_market_orders 
            WHERE shopper_id = ? AND status IN ('shopping', 'packing')
        ");
        $stmt->execute([$shopper_id]);
        if ($stmt->fetch()) {
            throw new Exception('Você já está com um pedido em andamento');
        }
        
        // Aceitar oferta
        $pdo->prepare("
            UPDATE om_shopper_offers 
            SET status = 'accepted', accepted_at = NOW()
            WHERE offer_id = ?
        ")->execute([$offer_id]);
        
        // Atribuir shopper ao pedido
        $pdo->prepare("
            UPDATE om_market_orders 
            SET shopper_id = ?,
                status = 'accepted',
                matching_status = 'matched',
                accepted_at = NOW(),
                date_modified = NOW()
            WHERE order_id = ?
        ")->execute([$shopper_id, $offer['order_id']]);
        
        // Cancelar outras ofertas do mesmo pedido
        $pdo->prepare("
            UPDATE om_shopper_offers 
            SET status = 'cancelled', cancelled_at = NOW()
            WHERE order_id = ? AND offer_id != ? AND status = 'pending'
        ")->execute([$offer['order_id'], $offer_id]);
        
        // Registrar na timeline
        $pdo->prepare("
            INSERT INTO om_order_timeline (order_id, status, title, description, actor_type, actor_id, created_at)
            VALUES (?, 'accepted', 'Shopper Confirmado', 'Shopper aceitou o pedido e vai iniciar as compras', 'shopper', ?, NOW())
        ")->execute([$offer['order_id'], $shopper_id]);
        
        // Notificar cliente
        $order_data = $pdo->query("
            SELECT customer_id, customer_name FROM om_market_orders WHERE order_id = {$offer['order_id']}
        ")->fetch();
        
        $shopper_data = $pdo->query("
            SELECT name, photo FROM om_market_shoppers WHERE shopper_id = $shopper_id
        ")->fetch();
        
        $pdo->prepare("
            INSERT INTO om_notifications (
                user_type, user_id, title, body, type, data, status, created_at
            ) VALUES ('customer', ?, '🛒 Shopper a Caminho!', ?, 'shopper_assigned', ?, 'unread', NOW())
        ")->execute([
            $order_data['customer_id'],
            ($shopper_data['name'] ?? 'Seu shopper') . ' aceitou seu pedido e vai começar as compras!',
            json_encode([
                'order_id' => $offer['order_id'],
                'shopper_id' => $shopper_id,
                'shopper_name' => $shopper_data['name'] ?? ''
            ])
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Oferta aceita com sucesso!',
            'order_id' => $offer['order_id'],
            'order_number' => $offer['order_number']
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// REJEITAR OFERTA
// ═══════════════════════════════════════════════════════════════════════════════

if ($action === 'reject') {
    $offer_id = (int)($input['offer_id'] ?? $_GET['offer_id'] ?? 0);
    $reason = $input['reason'] ?? '';
    
    if (!$shopper_id || !$offer_id) {
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
        exit;
    }
    
    $stmt = $pdo->prepare("
        UPDATE om_shopper_offers 
        SET status = 'rejected', rejected_at = NOW(), reject_reason = ?
        WHERE offer_id = ? AND shopper_id = ? AND status = 'pending'
    ");
    $stmt->execute([$reason, $offer_id, $shopper_id]);
    
    if ($stmt->rowCount() > 0) {
        // Atualizar taxa de aceitação do shopper
        $pdo->prepare("
            UPDATE om_market_shoppers s
            SET acceptance_rate = (
                SELECT ROUND(
                    SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1
                )
                FROM om_shopper_offers
                WHERE shopper_id = s.shopper_id
                  AND status IN ('accepted', 'rejected')
                  AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            )
            WHERE shopper_id = ?
        ")->execute([$shopper_id]);
        
        echo json_encode(['success' => true, 'message' => 'Oferta rejeitada']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Oferta não encontrada ou já processada']);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// DETALHES DO PEDIDO (para shopper visualizar antes de aceitar)
// ═══════════════════════════════════════════════════════════════════════════════

if ($action === 'details') {
    $order_id = (int)($input['order_id'] ?? $_GET['order_id'] ?? 0);
    
    if (!$order_id) {
        echo json_encode(['success' => false, 'error' => 'order_id obrigatório']);
        exit;
    }
    
    // Buscar pedido
    $stmt = $pdo->prepare("
        SELECT 
            o.order_id, o.order_number, o.total, o.subtotal,
            o.shipping_neighborhood, o.shipping_city, o.shipping_cep,
            o.date_added,
            p.name as partner_name, p.trade_name, p.address as partner_address,
            p.latitude as partner_lat, p.longitude as partner_lng
        FROM om_market_orders o
        JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
        exit;
    }
    
    // Buscar itens
    $stmt = $pdo->prepare("
        SELECT name, quantity, image
        FROM om_market_order_items
        WHERE order_id = ?
        ORDER BY item_id
    ");
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'order' => [
            'order_id' => $order['order_id'],
            'order_number' => $order['order_number'],
            'total' => (float)$order['total'],
            'items_count' => count($items),
            'delivery_area' => $order['shipping_neighborhood'] . ', ' . $order['shipping_city'],
            'partner' => $order['trade_name'] ?: $order['partner_name'],
            'partner_address' => $order['partner_address'],
            'created_at' => $order['date_added']
        ],
        'items' => array_map(function($item) {
            return [
                'name' => $item['name'],
                'qty' => (int)$item['quantity'],
                'image' => $item['image']
            ];
        }, $items)
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// AÇÃO NÃO RECONHECIDA
// ═══════════════════════════════════════════════════════════════════════════════

echo json_encode([
    'success' => false,
    'error' => 'Ação não reconhecida',
    'available_actions' => ['list', 'accept', 'reject', 'details']
]);
