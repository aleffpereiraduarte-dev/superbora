<?php
/**
 * GET /api/mercado/intelligence/flash-deals.php?limite=10[&city=Guarulhos]
 *
 * Retorna produtos com desconto >= 20% (flash deals). Cache 60s.
 * Pensado pra home mostrar banner "⚡ FLASH" rotativo.
 *
 * Resposta: { success: true, data: { deals: [{ product_id, name, image,
 *   price, preco_promo, discount, partner_id, partner_name }] } }
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/cache.php";
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { response(false, null, 'Method not allowed', 405); }

try {
    $limite = max(1, min(30, (int)($_GET['limite'] ?? 10)));
    $city = trim($_GET['city'] ?? '');

    $cacheKey = "flash_deals:v1:" . md5(strtolower($city) . "|$limite");
    $cached = function_exists('cacheGet') ? cacheGet($cacheKey) : null;
    if ($cached !== null && $cached !== false) {
        response(true, $cached);
    }

    $db = getDB();

    // Produtos com preco_promo < preco * 0.8 (desconto >= 20%). Parceiro ativo
    // e aberto. Ordenado por desconto desc + random leve pra não repetir
    // sempre os mesmos.
    $cityFilter = '';
    $params = [];
    if ($city !== '') {
        $cityFilter = 'AND (p.city IS NULL OR p.city = \'\' OR LOWER(p.city) = LOWER(?))';
        $params[] = $city;
    }

    $sql = "
        SELECT
          prod.product_id,
          prod.name,
          prod.image,
          prod.preco AS price,
          prod.preco_promo,
          CASE WHEN prod.preco > 0
               THEN ROUND(((prod.preco - prod.preco_promo) * 100.0) / prod.preco)
               ELSE 0 END AS discount,
          prod.partner_id,
          p.name AS partner_name,
          p.logo AS partner_logo
        FROM om_market_products prod
        INNER JOIN om_market_partners p ON p.partner_id = prod.partner_id
        WHERE prod.status = 1
          AND prod.preco_promo IS NOT NULL
          AND prod.preco_promo > 0
          AND prod.preco_promo < prod.preco * 0.8
          AND p.status::text = '1'
          $cityFilter
        ORDER BY discount DESC, random()
        LIMIT ?
    ";
    $params[] = $limite;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $deals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normaliza campos numéricos
    foreach ($deals as &$d) {
        $d['price'] = (float)$d['price'];
        $d['preco_promo'] = (float)$d['preco_promo'];
        $d['discount'] = (int)$d['discount'];
        $d['product_id'] = (int)$d['product_id'];
        $d['partner_id'] = (int)$d['partner_id'];
    }

    $out = ['deals' => $deals, 'total' => count($deals)];

    if (function_exists('cacheSet')) cacheSet($cacheKey, $out, 60);

    response(true, $out);

} catch (Exception $e) {
    error_log('[flash-deals] ' . $e->getMessage());
    // Fail-open: retorna vazio em vez de 500 — a home faz fallback pra produtos
    // com desconto do array local dela, então não vale 500 pro user.
    response(true, ['deals' => [], 'total' => 0]);
}
