<?php
/**
 * 👩‍🦰 SHOPPER V4 API - MEGA COMPLETA
 * - Distribuição automática por menos pedidos
 * - Histórico de compras
 * - Estatísticas
 * - QR Handoff
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

session_start();

require_once dirname(__DIR__) . '/config/database.php';

try {
    $pdo = getPDO();
} catch (Exception $e) {
    die(json_encode(['success' => false, 'error' => 'Database error']));
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

// ══════════════════════════════════════════════════════════════════════════════
// TOGGLE PRODUTO (via scanner)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'toggle_product') {
    $product_id = intval($input['product_id'] ?? 0);
    $is_picked = $input['is_picked'] ? 1 : 0;
    
    if (!$product_id) {
        echo json_encode(['success' => false, 'error' => 'ID inválido']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("UPDATE om_market_order_items SET scanned = ?, scanned_at = NOW() WHERE id = ?");
        $stmt->execute([$is_picked, $product_id]);

        $stmt = $pdo->prepare("
            SELECT
                (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = op.order_id) as total,
                (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = op.order_id AND scanned = 1) as picked
            FROM om_market_order_items op WHERE op.id = ?
        ");
        $stmt->execute([$product_id]);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'total' => intval($counts['total'] ?? 0),
            'picked' => intval($counts['picked'] ?? 0)
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// ACEITAR PEDIDO (com distribuição por menos pedidos)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'accept_order') {
    $order_id = intval($input['order_id'] ?? 0);
    $shopper_id = intval($input['shopper_id'] ?? $_SESSION['shopper_id'] ?? 0);
    
    if (!$order_id || !$shopper_id) {
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
        exit;
    }
    
    try {
        // Verificar se pedido disponível (aceita pending, confirmed, paid, awaiting_shopper)
        $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND status IN ('pending', 'confirmed', 'paid', 'awaiting_shopper') AND (shopper_id IS NULL OR shopper_id = 0)");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(['success' => false, 'error' => 'Pedido não disponível']);
            exit;
        }
        
        // Verificar se shopper já tem pedido ativo
        $stmt = $pdo->prepare("SELECT order_id FROM om_market_orders WHERE shopper_id = ? AND status IN ('confirmed', 'shopping')");
        $stmt->execute([$shopper_id]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Você já tem um pedido em andamento']);
            exit;
        }
        
        // Calcular ganho do shopper (5% do total ou mínimo R$ 5)
        $earning = max(5, $order['total'] * 0.05);
        
        // Atualizar pedido
        $stmt = $pdo->prepare("
            UPDATE om_market_orders 
            SET status = 'confirmed', 
                shopper_id = ?, 
                shopper_started_at = NOW(),
                shopper_earning = ?
            WHERE order_id = ?
        ");
        $stmt->execute([$shopper_id, $earning, $order_id]);
        
        // Atualizar shopper
        $pdo->prepare("
            UPDATE om_market_shoppers 
            SET status = 'comprando', 
                is_busy = 1, 
                current_order_id = ?,
                last_activity = NOW()
            WHERE shopper_id = ?
        ")->execute([$order_id, $shopper_id]);
        
        // Notificar no chat
        try {
            $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message) VALUES (?, 'system', 0, ?)")
                ->execute([$order_id, '🛒 Um shopper aceitou seu pedido e está a caminho do mercado!']);
        } catch (Exception $e) {}
        
        echo json_encode(['success' => true, 'earning' => $earning]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// ATUALIZAR STATUS DO PEDIDO
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'update_status') {
    $order_id = intval($input['order_id'] ?? 0);
    $status = $input['status'] ?? '';
    
    $valid = ['confirmed', 'shopping', 'ready', 'delivering', 'delivered'];
    
    if (!$order_id || !in_array($status, $valid)) {
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("UPDATE om_market_orders SET status = ? WHERE order_id = ?");
        $stmt->execute([$status, $order_id]);
        
        // Quando pronto, gerar códigos
        if ($status === 'ready') {
            $stmt = $pdo->prepare("SELECT delivery_code, box_qr_code, shopper_id, total, shopper_earning FROM om_market_orders WHERE order_id = ?");
            $stmt->execute([$order_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $updates = [];
            
            if (empty($order['delivery_code'])) {
                $palavras = ["BANANA", "LARANJA", "MORANGO", "ABACAXI", "MELANCIA", "MANGA", "UVA", "LIMAO", "KIWI", "PERA"];
                $delivery_code = $palavras[array_rand($palavras)] . '-' . rand(100, 999);
                $updates[] = "delivery_code = '$delivery_code'";
            }
            
            if (empty($order['box_qr_code'])) {
                $box_code = 'BOX-' . $order_id . '-' . strtoupper(substr(md5(time() . rand()), 0, 8));
                $updates[] = "box_qr_code = '$box_code'";
            }
            
            $updates[] = "shopper_completed_at = NOW()";
            
            if (!empty($updates)) {
                $pdo->exec("UPDATE om_market_orders SET " . implode(', ', $updates) . " WHERE order_id = $order_id");
            }
            
            // Registrar no histórico do shopper
            $stmt = $pdo->prepare("SELECT COUNT(*) as items FROM om_market_order_items WHERE order_id = ?");
            $stmt->execute([$order_id]);
            $items = $stmt->fetchColumn();
            
            // Calcular duração
            $stmt = $pdo->prepare("SELECT TIMESTAMPDIFF(MINUTE, shopper_started_at, NOW()) FROM om_market_orders WHERE order_id = ?");
            $stmt->execute([$order_id]);
            $duration = $stmt->fetchColumn() ?: 0;
            
            $pdo->prepare("
                INSERT INTO om_shopper_history (shopper_id, order_id, items_count, order_total, shopper_earning, started_at, completed_at, duration_minutes)
                SELECT shopper_id, order_id, ?, total, shopper_earning, shopper_started_at, NOW(), ?
                FROM om_market_orders WHERE order_id = ?
            ")->execute([$items, $duration, $order_id]);
            
            // Atualizar estatísticas do shopper
            $pdo->prepare("
                UPDATE om_market_shoppers 
                SET total_orders_today = total_orders_today + 1,
                    total_orders_week = total_orders_week + 1,
                    total_orders_month = total_orders_month + 1,
                    total_earnings_today = total_earnings_today + ?,
                    total_earnings_week = total_earnings_week + ?,
                    total_earnings_month = total_earnings_month + ?
                WHERE shopper_id = ?
            ")->execute([$order['shopper_earning'], $order['shopper_earning'], $order['shopper_earning'], $order['shopper_id']]);
            
            // Notificar cliente
            try {
                $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message) VALUES (?, 'system', 0, ?)")
                    ->execute([$order_id, '✅ Seu pedido está pronto! Em breve sairá para entrega.']);
            } catch (Exception $e) {}
        }
        
        // Mensagens de status
        $msgs = [
            'shopping' => '🛒 Shopper iniciou as compras!',
            'delivering' => '🚚 Seu pedido saiu para entrega!'
        ];
        
        if (isset($msgs[$status])) {
            try {
                $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message) VALUES (?, 'system', 0, ?)")
                    ->execute([$order_id, $msgs[$status]]);
            } catch (Exception $e) {}
        }
        
        echo json_encode(['success' => true, 'status' => $status]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// VERIFICAR HANDOFF
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'check_handoff') {
    $order_id = intval($_GET['order_id'] ?? $input['order_id'] ?? 0);
    
    if (!$order_id) {
        echo json_encode(['success' => false, 'error' => 'ID inválido']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT status FROM om_market_orders WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $confirmed = ($order['status'] === 'delivering');
        
        echo json_encode(['success' => true, 'confirmed' => $confirmed]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// CONFIRMAR HANDOFF (delivery)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'confirm_handoff') {
    $order_id = intval($input['order_id'] ?? 0);
    $box_code = $input['box_code'] ?? '';
    $delivery_id = intval($input['delivery_id'] ?? 0);
    
    if (!$order_id || !$box_code) {
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND box_qr_code = ? AND status = 'ready'");
        $stmt->execute([$order_id, $box_code]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(['success' => false, 'error' => 'QR Code inválido']);
            exit;
        }
        
        // Adicionar colunas se não existem
        try { $pdo->exec("ALTER TABLE om_market_orders ADD COLUMN delivery_id INT(11) DEFAULT NULL"); } catch(Exception $e) {}
        try { $pdo->exec("ALTER TABLE om_market_orders ADD COLUMN handoff_at DATETIME DEFAULT NULL"); } catch(Exception $e) {}
        
        // Atualizar
        $stmt = $pdo->prepare("UPDATE om_market_orders SET status = 'delivering', delivery_id = ?, handoff_at = NOW() WHERE order_id = ?");
        $stmt->execute([$delivery_id, $order_id]);
        
        // Liberar shopper
        $pdo->prepare("UPDATE om_market_shoppers SET is_busy = 0, current_order_id = NULL, status = 'disponivel' WHERE shopper_id = ?")
            ->execute([$order['shopper_id']]);
        
        // Notificar
        try {
            $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message) VALUES (?, 'system', 0, ?)")
                ->execute([$order_id, '🚚 Entregador confirmou recebimento! Seu pedido está a caminho.']);
        } catch (Exception $e) {}
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// BUSCAR PEDIDO POR QR
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'get_order_by_qr') {
    $box_code = $_GET['box_code'] ?? $input['box_code'] ?? '';
    
    if (!$box_code) {
        echo json_encode(['success' => false, 'error' => 'Código inválido']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT o.order_id, o.customer_name, o.shipping_address, o.total, o.status,
                   (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as total_items
            FROM om_market_orders o
            WHERE o.box_qr_code = ?
        ");
        $stmt->execute([$box_code]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
            exit;
        }
        
        echo json_encode(['success' => true, 'order' => $order]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// ESTATÍSTICAS DO SHOPPER
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'stats') {
    $shopper_id = intval($_GET['shopper_id'] ?? $input['shopper_id'] ?? $_SESSION['shopper_id'] ?? 0);
    
    if (!$shopper_id) {
        echo json_encode(['success' => false, 'error' => 'ID inválido']);
        exit;
    }
    
    try {
        // Hoje
        $stmt = $pdo->prepare("SELECT COUNT(*) as total, COALESCE(SUM(shopper_earning), 0) as earnings FROM om_shopper_history WHERE shopper_id = ? AND DATE(created_at) = CURRENT_DATE");
        $stmt->execute([$shopper_id]);
        $today = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Semana
        $stmt = $pdo->prepare("SELECT COUNT(*) as total, COALESCE(SUM(shopper_earning), 0) as earnings FROM om_shopper_history WHERE shopper_id = ? AND YEARWEEK(created_at) = YEARWEEK(NOW())");
        $stmt->execute([$shopper_id]);
        $week = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Mês
        $stmt = $pdo->prepare("SELECT COUNT(*) as total, COALESCE(SUM(shopper_earning), 0) as earnings FROM om_shopper_history WHERE shopper_id = ? AND EXTRACT(MONTH FROM created_at) = EXTRACT(MONTH FROM NOW())");
        $stmt->execute([$shopper_id]);
        $month = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'today' => $today,
            'week' => $week,
            'month' => $month
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// RANKING DO DIA
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'ranking') {
    try {
        $stmt = $pdo->query("
            SELECT s.shopper_id, s.name, s.avatar, COUNT(h.id) as orders, COALESCE(SUM(h.shopper_earning), 0) as earnings
            FROM om_market_shoppers s
            LEFT JOIN om_shopper_history h ON s.shopper_id = h.shopper_id AND DATE(h.created_at) = CURRENT_DATE
            GROUP BY s.shopper_id
            ORDER BY orders DESC, earnings DESC
            LIMIT 20
        ");
        $ranking = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'ranking' => $ranking]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// BUSCAR SHOPPER COM MENOS PEDIDOS (para distribuição automática)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'get_available_shopper') {
    try {
        // Buscar shopper online/disponível com menos pedidos no dia
        $stmt = $pdo->query("
            SELECT s.shopper_id, s.name
            FROM om_market_shoppers s
            WHERE s.status IN ('online', 'disponivel') 
            AND s.is_busy = 0
            ORDER BY s.total_orders_today ASC, s.last_activity DESC
            LIMIT 1
        ");
        $shopper = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'shopper' => $shopper]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// LISTAR PEDIDOS DISPONÍVEIS / ACEITOS / EM COMPRAS
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'list_orders') {
    $status = $_GET['status'] ?? $input['status'] ?? 'pending';
    $shopper_id = intval($_GET['shopper_id'] ?? $input['shopper_id'] ?? $_SESSION['shopper_id'] ?? 0);

    try {
        if ($status === 'pending') {
            // Pedidos disponíveis (ofertas pendentes para este shopper)
            $stmt = $pdo->prepare("
                SELECT
                    o.order_id as id,
                    o.order_number,
                    o.customer_name,
                    o.shipping_address,
                    o.shipping_number,
                    o.shipping_city,
                    o.items_count,
                    o.total,
                    o.created_at,
                    offers.expires_at,
                    'pending' as status
                FROM om_shopper_offers offers
                INNER JOIN om_market_orders o ON offers.order_id = o.order_id
                WHERE offers.worker_id = ?
                AND offers.status = 'pending'
                AND offers.expires_at > NOW()
                ORDER BY offers.created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$shopper_id]);
        } elseif ($status === 'accepted') {
            // Pedidos aceitos pelo shopper (aguardando iniciar compras)
            $stmt = $pdo->prepare("
                SELECT
                    o.order_id as id,
                    o.order_number,
                    o.customer_name,
                    o.shipping_address,
                    o.shipping_number,
                    o.shipping_city,
                    o.items_count,
                    o.total,
                    o.created_at,
                    'accepted' as status
                FROM om_market_orders o
                WHERE o.shopper_id = ?
                AND o.status IN ('confirmed', 'accepted', 'assigned')
                ORDER BY o.created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$shopper_id]);
        } elseif ($status === 'shopping') {
            // Pedidos em processo de compra
            $stmt = $pdo->prepare("
                SELECT
                    o.order_id as id,
                    o.order_number,
                    o.customer_name,
                    o.shipping_address,
                    o.shipping_number,
                    o.shipping_city,
                    o.items_count,
                    o.total,
                    o.created_at,
                    'shopping' as status
                FROM om_market_orders o
                WHERE o.shopper_id = ?
                AND o.status IN ('shopping', 'picking', 'in_progress')
                ORDER BY o.created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$shopper_id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT
                    o.order_id as id,
                    o.order_number,
                    o.customer_name,
                    o.shipping_address,
                    o.items_count,
                    o.total,
                    o.status,
                    o.created_at
                FROM om_market_orders o
                WHERE o.shopper_id = ?
                ORDER BY o.created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$shopper_id]);
        }

        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Contar pedidos pendentes (ofertas disponíveis)
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM om_shopper_offers WHERE worker_id = ? AND status = 'pending' AND expires_at > NOW()");
        $stmtCount->execute([$shopper_id]);
        $pendingCount = $stmtCount->fetchColumn();

        echo json_encode([
            'success' => true,
            'orders' => $orders,
            'pending_count' => (int)$pendingCount
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

echo json_encode(['success' => false, 'error' => 'Ação não reconhecida']);
