<?php
/**
 * GET /api/mercado/pedido/produtos-mercado.php?order_id=X&busca=frango
 * Lists products from the partner of an existing order.
 * Used by adicionar-itens.jsx to let customers add items to an active order.
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";

if (!RateLimiter::check(30, 60)) {
    exit;
}

try {
    $db = getDB();
    $customer_id = requireCustomerAuth();

    $order_id = (int)($_GET['order_id'] ?? 0);
    if (!$order_id) {
        response(false, null, "order_id obrigatorio", 400);
    }

    $busca = trim(substr($_GET['busca'] ?? '', 0, 100));

    // Validate order belongs to customer and get partner_id
    $orderStmt = $db->prepare("
        SELECT partner_id, status FROM om_market_orders
        WHERE order_id = ? AND customer_id = ?
    ");
    $orderStmt->execute([$order_id, $customer_id]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        response(false, null, "Pedido nao encontrado", 404);
    }

    $partner_id = (int)$order['partner_id'];

    // Build product query
    $sql = "SELECT p.product_id, p.name AS nome, p.price AS preco,
                   p.special_price AS preco_promocional,
                   p.image AS imagem, p.quantity AS estoque,
                   c.name AS categoria
            FROM om_market_products p
            LEFT JOIN om_market_categories c ON p.category_id = c.category_id
            WHERE p.partner_id = ? AND p.status = 1 AND p.quantity > 0";

    $params = [$partner_id];

    if ($busca !== '') {
        $sql .= " AND (p.name ILIKE ? OR p.description ILIKE ?)";
        $searchTerm = '%' . $busca . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    $sql .= " ORDER BY c.sort_order ASC, p.sort_order ASC, p.name ASC LIMIT 100";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format output
    $result = [];
    foreach ($products as $p) {
        $result[] = [
            'product_id' => (int)$p['product_id'],
            'nome' => $p['nome'],
            'preco' => round((float)$p['preco'], 2),
            'preco_promocional' => $p['preco_promocional'] ? round((float)$p['preco_promocional'], 2) : null,
            'imagem' => $p['imagem'] ?: null,
            'estoque' => (int)$p['estoque'],
            'categoria' => $p['categoria'] ?: 'Geral',
        ];
    }

    response(true, ['produtos' => $result]);

} catch (Exception $e) {
    error_log("[produtos-mercado] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar produtos", 500);
}
