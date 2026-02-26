<?php
/**
 * DELIVERY CONFIG - Gerado automaticamente
 */

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 1);
    ini_set('session.use_strict_mode', 1);
    session_start();
}

// Carregar config central SEGURA (le do .env)
require_once dirname(__DIR__) . '/config/database.php';

// Funcao getDB() usando config central
function getDB() {
    return getPDO();
}

function requireLogin() {
    if (!isset($_SESSION["delivery_id"]) || empty($_SESSION["delivery_id"])) {
        if (!headers_sent()) {
            header("Location: login.php", true, 302);
        }
        exit;
    }
}

function getDelivery() {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM om_market_deliveries WHERE delivery_id = ?");
    $stmt->execute([$_SESSION["delivery_id"]]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function getActiveRoute($delivery_id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM om_delivery_routes WHERE delivery_id = ? AND status = \"active\" LIMIT 1");
    $stmt->execute([$delivery_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getRouteStops($route_id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM om_delivery_route_stops WHERE route_id = ? ORDER BY stop_order ASC");
    $stmt->execute([$route_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getPendingDeliveries($delivery_id = null) {
    $pdo = getDB();
    $sql = "
        SELECT o.*, 
               COALESCE(p.total_items, 0) as total_items
        FROM om_market_orders o
        LEFT JOIN (
            SELECT order_id, COUNT(*) as total_items 
            FROM om_market_order_products 
            GROUP BY order_id
        ) p ON o.order_id = p.order_id
        WHERE o.status = 'ready' AND o.route_id IS NULL";
    
    $params = [];
    if ($delivery_id) {
        $sql .= " AND o.delivery_id = ?";
        $params[] = $delivery_id;
    }
    
    $sql .= " ORDER BY o.order_id ASC LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateDeliveryCode() {
    $palavras = ["BANANA", "LARANJA", "MORANGO", "ABACAXI", "MELANCIA", "MANGA", "UVA", "LIMAO", "COCO", "PERA"];
    return $palavras[array_rand($palavras)] . "-" . random_int(1000, 9999);
}

function isLoggedIn() {
    return isset($_SESSION["delivery_id"]) && !empty($_SESSION["delivery_id"]);
}

function acceptDelivery($order_id, $delivery_id) {
    $pdo = getDB();

    // Verificar se pedido está disponível
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND status IN ('ready', 'purchased', 'awaiting_delivery') AND (delivery_id IS NULL OR delivery_id = 0) FOR UPDATE");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();

    if (!$order) {
        return ['success' => false, 'error' => 'Pedido não disponível'];
    }

    // Buscar dados do delivery
    $stmt = $pdo->prepare("SELECT name, phone FROM om_market_deliveries WHERE delivery_id = ?");
    $stmt->execute([$delivery_id]);
    $delivery = $stmt->fetch();

    // Atribuir pedido ao delivery
    $stmt = $pdo->prepare("UPDATE om_market_orders SET
        delivery_id = ?,
        delivery_name = ?,
        delivery_phone = ?,
        status = 'delivering',
        delivery_accepted_at = NOW()
        WHERE order_id = ?");
    $stmt->execute([$delivery_id, $delivery['name'] ?? 'Entregador', $delivery['phone'] ?? '', $order_id]);

    // Atualizar status do delivery
    $pdo->prepare("UPDATE om_market_deliveries SET current_order_id = ?, is_busy = 1 WHERE delivery_id = ?")
        ->execute([$order_id, $delivery_id]);

    // Expirar outras ofertas para este pedido
    $pdo->prepare("UPDATE om_delivery_offers SET status = 'expired' WHERE order_id = ? AND status = 'pending'")
        ->execute([$order_id]);

    return [
        'success' => true,
        'message' => 'Entrega aceita!',
        'order_id' => $order_id
    ];
}
