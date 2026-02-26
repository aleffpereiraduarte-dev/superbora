<?php
/**
 * GET /api/mercado/produtos/trending.php?limit=10&cep=X
 * C3: "Em alta na sua regiao" - produtos mais pedidos nos ultimos 7 dias
 * Cache 300s
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

header('Cache-Control: public, max-age=300');

try {
    $limit = min(20, max(1, (int)($_GET["limit"] ?? 10)));
    $cep = preg_replace('/[^0-9]/', '', $_GET["cep"] ?? "");

    $cacheKey = "trending_" . md5($cep . "_" . $limit);

    $data = CacheHelper::remember($cacheKey, 300, function() use ($limit, $cep) {
        $db = getDB();

        // Produtos mais pedidos nos ultimos 7 dias
        $stmt = $db->prepare("
            SELECT p.product_id, p.name, p.price, p.special_price, p.image, p.unit,
                   mp.partner_id, mp.name as partner_name, mp.logo as partner_logo,
                   mp.rating as partner_rating,
                   COUNT(oi.id) as vezes_pedido
            FROM om_market_order_items oi
            INNER JOIN om_market_orders o ON oi.order_id = o.order_id
            INNER JOIN om_market_products p ON oi.product_id = p.product_id
            INNER JOIN om_market_partners mp ON p.partner_id = mp.partner_id
            WHERE o.date_added >= NOW() - INTERVAL '7 days'
              AND o.status NOT IN ('cancelado')
              AND p.status::text = '1'
              AND (p.available::text = '1' OR p.available IS NULL)
              AND mp.status::text = '1'
            GROUP BY p.product_id, p.name, p.price, p.special_price, p.image, p.unit,
                     mp.partner_id, mp.name, mp.logo, mp.rating
            ORDER BY vezes_pedido DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $produtos = $stmt->fetchAll();

        // Se nao tem pedidos recentes suficientes, completar com bem avaliados
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
                       mp.rating as partner_rating,
                       0 as vezes_pedido
                FROM om_market_products p
                INNER JOIN om_market_partners mp ON p.partner_id = mp.partner_id
                WHERE p.status::text = '1'
                  AND (p.available::text = '1' OR p.available IS NULL)
                  AND mp.status::text = '1'
                  AND p.special_price IS NOT NULL AND p.special_price > 0 AND p.special_price < p.price
                  $excludeSQL
                ORDER BY mp.rating DESC
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
                "vezes_pedido" => (int)($p['vezes_pedido'] ?? 0)
            ];
        }

        return ["produtos" => $result];
    });

    response(true, $data);

} catch (Exception $e) {
    error_log("[API Trending] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar tendencias", 500);
}
