<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * GET /api/mercado/vitrine/boosted-stores.php
 *
 * Public endpoint (no auth required) — returns boosted/sponsored stores
 * for the customer vitrine homepage.
 *
 * Params:
 *   - city (optional): filter by city
 *   - category (optional): filter by category
 *   - limit (optional): max results (default 6)
 *
 * Returns stores sorted by bid_amount DESC (highest bidder first).
 * Increments impression count for each returned boost.
 * ══════════════════════════════════════════════════════════════════════════════
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

try {
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $city = trim($_GET['city'] ?? '');
    $category = trim($_GET['category'] ?? '');
    $limit = min(20, max(1, (int)($_GET['limit'] ?? 6)));

    $today = date('Y-m-d');

    // ═══════════════════════════════════════════════════════════════════
    // Fetch active boosts with partner store info
    // ═══════════════════════════════════════════════════════════════════
    $stmt = $db->prepare("
        SELECT
            b.boost_id,
            b.partner_id,
            b.boost_type,
            b.bid_amount,
            b.budget_daily,
            b.budget_spent,
            p.name,
            p.trade_name,
            p.logo,
            p.banner,
            p.city,
            p.state,
            p.categoria,
            p.rating,
            p.delivery_fee,
            p.delivery_time_min,
            p.delivery_time_max,
            p.min_order_value,
            p.is_open,
            p.free_delivery_above
        FROM om_partner_boosts b
        INNER JOIN om_market_partners p ON p.partner_id = b.partner_id
        WHERE b.status = 'active'
          AND b.start_date <= :today
          AND (b.end_date IS NULL OR b.end_date >= :today2)
          AND b.budget_spent < b.budget_daily
          AND p.status::text = '1'
        ORDER BY b.bid_amount DESC, b.created_at ASC
        LIMIT :lim
    ");
    $stmt->bindValue(':today', $today, PDO::PARAM_STR);
    $stmt->bindValue(':today2', $today, PDO::PARAM_STR);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $stores = [];
    $boost_ids = [];

    foreach ($rows as $r) {
        // Optional city filter (post-query since target_cities is JSON text)
        // We filter here because JSON in TEXT column cannot be efficiently indexed
        // Also filter by category if requested

        $boost_ids[] = (int)$r['boost_id'];

        $store = [
            "partner_id" => (int)$r['partner_id'],
            "boost_id" => (int)$r['boost_id'],
            "boost_type" => $r['boost_type'],
            "name" => $r['trade_name'] ?: $r['name'],
            "logo" => $r['logo'],
            "banner" => $r['banner'],
            "city" => $r['city'],
            "state" => $r['state'],
            "categoria" => $r['categoria'],
            "rating" => $r['rating'] ? (float)$r['rating'] : null,
            "delivery_fee" => (float)($r['delivery_fee'] ?? 0),
            "delivery_time_min" => (int)($r['delivery_time_min'] ?? 0),
            "delivery_time_max" => (int)($r['delivery_time_max'] ?? 0),
            "min_order_value" => (float)($r['min_order_value'] ?? 0),
            "is_open" => (bool)$r['is_open'],
            "free_delivery_above" => $r['free_delivery_above'] ? (float)$r['free_delivery_above'] : null,
            "sponsored" => true,
        ];

        // Apply optional filters
        if ($city && stripos($r['city'] ?? '', $city) === false) {
            array_pop($boost_ids);
            continue;
        }
        if ($category && stripos($r['categoria'] ?? '', $category) === false) {
            array_pop($boost_ids);
            continue;
        }

        $stores[] = $store;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Increment impression counts for all returned boosts
    // ═══════════════════════════════════════════════════════════════════
    if (!empty($boost_ids)) {
        $placeholders = implode(',', array_fill(0, count($boost_ids), '?'));
        $stmtImpressions = $db->prepare("
            UPDATE om_partner_boosts
            SET impressions = impressions + 1
            WHERE boost_id IN ({$placeholders})
        ");
        $stmtImpressions->execute($boost_ids);
    }

    response(true, [
        "stores" => $stores,
        "total" => count($stores),
    ], "Lojas impulsionadas");

} catch (Exception $e) {
    error_log("[vitrine/boosted-stores] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar lojas impulsionadas", 500);
}
