<?php
/**
 * GET /api/mercado/admin/products-search.php
 *
 * Busca de produtos para o painel administrativo.
 *
 * Query: ?q=search_term&partner_id=456
 *
 * Retorna ate 20 produtos com: id, name, price, image, category_name, stock, status.
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') response(false, null, "Metodo nao permitido", 405);

    $q = trim($_GET['q'] ?? '');
    $partner_id = (int)($_GET['partner_id'] ?? 0);

    if (strlen($q) < 1) response(false, null, "Parametro 'q' obrigatorio (termo de busca)", 400);

    // Escape ILIKE special characters
    $q_escaped = str_replace(['%', '_'], ['\\%', '\\_'], $q);
    $search = "%{$q_escaped}%";

    $where = ["p.name ILIKE ?"];
    $params = [$search];

    if ($partner_id > 0) {
        $where[] = "p.partner_id = ?";
        $params[] = $partner_id;
    }

    $where_sql = implode(' AND ', $where);

    try {
        $stmt = $db->prepare("
            SELECT p.product_id as id, p.name, p.price, p.image,
                   p.quantity as stock, p.status,
                   c.name as category_name,
                   pa.name as partner_name
            FROM om_market_products p
            LEFT JOIN om_market_categories c ON p.category_id = c.category_id
            LEFT JOIN om_market_partners pa ON p.partner_id = pa.partner_id
            WHERE {$where_sql}
            ORDER BY p.name ASC
            LIMIT 20
        ");
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        // Cast numeric fields
        foreach ($products as &$product) {
            $product['id'] = (int)$product['id'];
            $product['price'] = (float)$product['price'];
            $product['stock'] = (int)$product['stock'];
        }
        unset($product);

        response(true, [
            'products' => $products,
            'query' => $q,
            'partner_id' => $partner_id ?: null,
            'count' => count($products),
        ], "Busca de produtos realizada");

    } catch (Exception $e) {
        // Table may not exist
        if (strpos($e->getMessage(), 'om_market_products') !== false
            || strpos($e->getMessage(), 'relation') !== false
            || strpos($e->getMessage(), 'does not exist') !== false) {
            response(true, [
                'products' => [],
                'query' => $q,
                'partner_id' => $partner_id ?: null,
                'count' => 0,
            ], "Tabela de produtos nao encontrada");
        }
        throw $e;
    }

} catch (Exception $e) {
    error_log("[admin/products-search] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
