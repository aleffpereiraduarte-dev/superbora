<?php
/**
 * GET /api/mercado/pedidos/historico.php?customer_id=X&limit=5
 * Returns customer's recent orders with items for reorder functionality
 */
require_once __DIR__ . "/../config/database.php";

try {
    $customer_id = requireCustomerAuth();
    $limit = min(20, max(1, (int)($_GET["limit"] ?? 5)));

    $db = getDB();

    // Recent completed orders
    $stmt = $db->prepare("
        SELECT o.order_id, o.partner_id, o.total, o.delivery_fee, o.status,
               o.date_added, o.partner_name,
               p.name as parceiro_nome, p.logo as parceiro_logo
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.customer_id = ?
          AND o.status IN ('entregue','retirado','completed')
        ORDER BY o.date_added DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $customer_id, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll();

    if (empty($orders)) {
        response(true, ["pedidos" => []]);
    }

    // Fetch items for each order
    $orderIds = array_map(function($o) { return $o['order_id']; }, $orders);
    $ph = implode(',', array_fill(0, count($orderIds), '?'));

    $stmt = $db->prepare("
        SELECT oi.order_id, oi.product_id, oi.product_name, oi.quantity,
               oi.price, oi.product_image, oi.unit
        FROM om_market_order_items oi
        WHERE oi.order_id IN ($ph)
        ORDER BY oi.order_id, oi.item_id
    ");
    $stmt->execute($orderIds);
    $allItems = $stmt->fetchAll();

    $itemsByOrder = [];
    foreach ($allItems as $item) {
        $itemsByOrder[$item['order_id']][] = [
            "product_id" => (int)$item["product_id"],
            "nome" => $item["product_name"] ?? $item["name"] ?? "",
            "quantidade" => (int)$item["quantity"],
            "preco" => (float)$item["price"],
            "imagem" => $item["product_image"],
            "unidade" => $item["unit"] ?? "un"
        ];
    }

    $pedidos = [];
    foreach ($orders as $o) {
        $pedidos[] = [
            "order_id" => (int)$o["order_id"],
            "partner_id" => (int)$o["partner_id"],
            "parceiro_nome" => $o["parceiro_nome"] ?? $o["partner_name"] ?? "",
            "parceiro_logo" => $o["parceiro_logo"],
            "total" => (float)$o["total"],
            "taxa_entrega" => (float)($o["delivery_fee"] ?? 0),
            "status" => $o["status"],
            "data" => $o["date_added"],
            "itens" => $itemsByOrder[$o["order_id"]] ?? []
        ];
    }

    response(true, ["pedidos" => $pedidos]);

} catch (Exception $e) {
    error_log("[API Historico] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar historico", 500);
}
