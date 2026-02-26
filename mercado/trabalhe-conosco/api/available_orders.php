<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
/**
 * API: Lista pedidos disponíveis para o shopper
 * GET /api/available_orders.php
 */

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

session_start();

$workerId = $_SESSION["worker_id"] ?? null;
if (!$workerId) {
    http_response_code(401);
    exit(json_encode(["success" => false, "error" => "Não autenticado"]));
}

try {
    $db = new PDO("mysql:host=localhost;dbname=love1;charset=utf8mb4",
        "love1",
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Buscar notificações pendentes do shopper
    $stmt = $db->prepare("
        SELECT 
            n.notification_id,
            n.order_id,
            n.title,
            n.message,
            n.data,
            n.expires_at,
            n.created_at,
            o.total,
            o.shipping_address,
            o.shipping_city,
            o.shipping_postcode,
            p.name as partner_name
        FROM om_shopper_notifications n
        JOIN om_market_orders o ON n.order_id = o.order_id
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE n.worker_id = ?
        AND n.status = 'pendente'
        AND n.expires_at > NOW()
        ORDER BY n.created_at DESC
    ");
    $stmt->execute([$workerId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatar dados
    foreach ($orders as &$order) {
        $order["data"] = json_decode($order["data"], true);
        $order["total_formatted"] = "R$ " . number_format($order["total"], 2, ",", ".");
        $order["time_remaining"] = max(0, strtotime($order["expires_at"]) - time());
    }
    
    echo json_encode([
        "success" => true,
        "count" => count($orders),
        "orders" => $orders
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
