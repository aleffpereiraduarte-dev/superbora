<?php
/**
 * GET /api/mercado/produtos/recomendados.php?customer_id=X&limit=10
 * C2: Recomendacoes "Pra voce"
 * Baseado em categorias dos ultimos pedidos + produtos populares
 * Cache 300s
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

header('Cache-Control: public, max-age=300');

try {
    $db = getDB();

    // Require authentication - use authenticated customer_id instead of query string
    OmAuth::getInstance()->setDb($db);
    $customer_id = 0;
    $token = om_auth()->getTokenFromRequest();
    if ($token) {
        $payload = om_auth()->validateToken($token);
        if ($payload && $payload['type'] === 'customer') {
            $customer_id = (int)$payload['uid'];
        }
    }

    $limit = min(20, max(1, (int)($_GET["limit"] ?? 10)));

    $cacheKey = "recomendados_" . $customer_id . "_" . $limit;

    $data = CacheHelper::remember($cacheKey, 300, function() use ($customer_id, $limit) {
        $db = getDB();
        $produtos = [];

        // Se tem customer_id, buscar categorias dos ultimos pedidos
        if ($customer_id) {
            $stmt = $db->prepare("
                SELECT p.category_id
                FROM om_market_order_items oi
                INNER JOIN om_market_orders o ON oi.order_id = o.order_id
                INNER JOIN om_market_products p ON oi.product_id = p.product_id
                WHERE o.customer_id = ?
                GROUP BY p.category_id
                ORDER BY MAX(o.date_added) DESC
                LIMIT 20
            ");
            $stmt->execute([$customer_id]);
            $categoryIds = array_column($stmt->fetchAll(), 'category_id');

            if (!empty($categoryIds)) {
                $ph = implode(',', array_fill(0, count($categoryIds), '?'));
                $stmt = $db->prepare("
                    SELECT p.product_id, p.name, p.price, p.special_price, p.image, p.unit,
                           mp.partner_id, mp.name as partner_name, mp.logo as partner_logo,
                           mp.rating as partner_rating
                    FROM om_market_products p
                    INNER JOIN om_market_partners mp ON p.partner_id = mp.partner_id
                    WHERE p.status::text = '1'
                      AND (p.available::text = '1' OR p.available IS NULL)
                      AND mp.status::text = '1'
                      AND p.category_id IN ($ph)
                    ORDER BY RAND()
                    LIMIT ?
                ");
                $stmt->execute(array_merge($categoryIds, [$limit]));
                $produtos = $stmt->fetchAll();
            }
        }

        // Se nao tem recomendacoes suficientes, completar com populares
        $remaining = $limit - count($produtos);
        if ($remaining > 0) {
            $excludeIds = array_map(function($p) { return $p['product_id']; }, $produtos);
            $excludeSQL = "";
            $params = [];
            if (!empty($excludeIds)) {
                $ph = implode(',', array_fill(0, count($excludeIds), '?'));
                $excludeSQL = "AND p.product_id NOT IN ($ph)";
                $params = $excludeIds;
            }

            $stmt = $db->prepare("
                SELECT p.product_id, p.name, p.price, p.special_price, p.image, p.unit,
                       mp.partner_id, mp.name as partner_name, mp.logo as partner_logo,
                       mp.rating as partner_rating
                FROM om_market_products p
                INNER JOIN om_market_partners mp ON p.partner_id = mp.partner_id
                WHERE p.status::text = '1'
                  AND (p.available::text = '1' OR p.available IS NULL)
                  AND mp.status::text = '1'
                  $excludeSQL
                ORDER BY mp.rating DESC, RAND()
                LIMIT ?
            ");
            $stmt->execute(array_merge($params, [$remaining]));
            $produtos = array_merge($produtos, $stmt->fetchAll());
        }

        // Formatar
        $result = [];
        foreach ($produtos as $p) {
            $preco = (float)$p['price'];
            $promoPreco = $p['special_price'] ? (float)$p['special_price'] : null;
            $emPromocao = $promoPreco && $promoPreco > 0 && $promoPreco < $preco;

            $result[] = [
                "id" => (int)$p['product_id'],
                "nome" => $p['name'],
                "preco" => $preco,
                "preco_promo" => $emPromocao ? $promoPreco : null,
                "imagem" => $p['image'],
                "unidade" => $p['unit'] ?? "un",
                "parceiro_id" => (int)$p['partner_id'],
                "parceiro_nome" => $p['partner_name'],
                "parceiro_logo" => $p['partner_logo'],
                "parceiro_avaliacao" => (float)($p['partner_rating'] ?? 5.0)
            ];
        }

        return ["produtos" => $result];
    });

    response(true, $data);

} catch (Exception $e) {
    error_log("[API Recomendados] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar recomendacoes", 500);
}
