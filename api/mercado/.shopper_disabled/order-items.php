<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
setCorsHeaders();
try {
    $db = getDB(); OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requireShopper(); $shopper_id = $payload['sub'] ?? $payload['uid'];
    $order_id = (int)($_GET['order_id'] ?? 0);
    if (!$order_id) response(false, null, "order_id obrigatório", 400);
    $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND shopper_id = ?");
    $stmt->execute([$order_id, $shopper_id]);
    if (!$stmt->fetch()) response(false, null, "Pedido não encontrado ou não atribuído a você", 403);
    $stmt = $db->prepare("SELECT i.*, pb.barcode, pb.image FROM om_market_order_items i LEFT JOIN om_market_products_base pb ON i.product_id = pb.product_id WHERE i.order_id = ?");
    $stmt->execute([$order_id]);
    response(true, $stmt->fetchAll());
} catch (Exception $e) { error_log("[shopper/order-items] " . $e->getMessage()); response(false, null, "Erro", 500); }
