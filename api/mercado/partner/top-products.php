<?php
/**
 * GET /api/mercado/partner/top-products.php
 * Top selling products for today and this week
 * Returns: [{product_id, name, image, price, total_sold, revenue}]
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partnerId = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, "Metodo nao permitido", 405);
    }

    // Top products TODAY
    $stmtToday = dbQuery($db, "
        SELECT
            oi.product_id,
            COALESCE(p.name, pb.name, 'Produto') as name,
            COALESCE(p.image, pb.image, '') as image,
            COALESCE(p.price, pp.price, oi.price) as price,
            SUM(oi.quantity) as total_sold,
            SUM(oi.price * oi.quantity) as revenue
        FROM om_market_order_items oi
        INNER JOIN om_market_orders o ON o.order_id = oi.order_id
        LEFT JOIN om_market_products p ON p.product_id = oi.product_id AND p.partner_id = ?
        LEFT JOIN om_market_products_base pb ON pb.product_id = oi.product_id
        LEFT JOIN om_market_products_price pp ON pp.product_id = oi.product_id AND pp.partner_id = ?
        WHERE o.partner_id = ?
          AND DATE(o.date_added) = CURRENT_DATE
          AND o.status NOT IN ('cancelado', 'recusado')
        GROUP BY oi.product_id, p.name, pb.name, p.image, pb.image, p.price, pp.price, oi.price
        ORDER BY total_sold DESC
        LIMIT 10
    ", [$partnerId, $partnerId, $partnerId]);
    $todayProducts = $stmtToday->fetchAll();

    $todayList = [];
    foreach ($todayProducts as $row) {
        $todayList[] = [
            'product_id' => (int)$row['product_id'],
            'name' => $row['name'],
            'image' => $row['image'],
            'price' => round((float)$row['price'], 2),
            'total_sold' => (int)$row['total_sold'],
            'revenue' => round((float)$row['revenue'], 2),
        ];
    }

    // Top products THIS WEEK (last 7 days)
    $stmtWeek = dbQuery($db, "
        SELECT
            oi.product_id,
            COALESCE(p.name, pb.name, 'Produto') as name,
            COALESCE(p.image, pb.image, '') as image,
            COALESCE(p.price, pp.price, oi.price) as price,
            SUM(oi.quantity) as total_sold,
            SUM(oi.price * oi.quantity) as revenue
        FROM om_market_order_items oi
        INNER JOIN om_market_orders o ON o.order_id = oi.order_id
        LEFT JOIN om_market_products p ON p.product_id = oi.product_id AND p.partner_id = ?
        LEFT JOIN om_market_products_base pb ON pb.product_id = oi.product_id
        LEFT JOIN om_market_products_price pp ON pp.product_id = oi.product_id AND pp.partner_id = ?
        WHERE o.partner_id = ?
          AND o.date_added >= CURRENT_DATE - INTERVAL '7 days'
          AND o.status NOT IN ('cancelado', 'recusado')
        GROUP BY oi.product_id, p.name, pb.name, p.image, pb.image, p.price, pp.price, oi.price
        ORDER BY total_sold DESC
        LIMIT 10
    ", [$partnerId, $partnerId, $partnerId]);
    $weekProducts = $stmtWeek->fetchAll();

    $weekList = [];
    foreach ($weekProducts as $row) {
        $weekList[] = [
            'product_id' => (int)$row['product_id'],
            'name' => $row['name'],
            'image' => $row['image'],
            'price' => round((float)$row['price'], 2),
            'total_sold' => (int)$row['total_sold'],
            'revenue' => round((float)$row['revenue'], 2),
        ];
    }

    response(true, [
        'today' => $todayList,
        'week' => $weekList,
    ], "Produtos mais vendidos");

} catch (Exception $e) {
    error_log("[partner/top-products] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
