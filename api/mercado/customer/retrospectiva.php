<?php
/**
 * GET /api/mercado/customer/retrospectiva.php
 * Retrospectiva SuperBora — Aggregated ordering stats for the last 12 months.
 * Similar to Spotify Wrapped / iFood Retrospectiva.
 * Requires customer authentication.
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $customer_id = requireCustomerAuth();
    $db = getDB();

    // Date range: last 12 months
    $since = date('Y-m-d H:i:s', strtotime('-12 months'));

    // ── 1. Basic aggregates ─────────────────────────────────────────────
    $stmt = dbQuery($db, "
        SELECT
            COUNT(*) AS total_orders,
            COALESCE(SUM(total), 0) AS total_spent,
            COALESCE(AVG(total), 0) AS avg_order_value,
            COUNT(DISTINCT partner_id) AS unique_stores
        FROM om_market_orders
        WHERE customer_id = ?
          AND status IN ('entregue', 'delivered')
          AND date_added >= ?
    ", [$customer_id, $since]);
    $basics = $stmt->fetch();

    $totalOrders = (int)$basics['total_orders'];

    // If user has no completed orders, return empty retrospectiva
    if ($totalOrders === 0) {
        response(true, [
            'has_data' => false,
            'message' => 'Voce ainda nao tem pedidos suficientes para uma retrospectiva.',
        ]);
    }

    $totalSpent = round((float)$basics['total_spent'], 2);
    $avgOrderValue = round((float)$basics['avg_order_value'], 2);
    $uniqueStores = (int)$basics['unique_stores'];

    // ── 2. Total items ordered ──────────────────────────────────────────
    $stmt = dbQuery($db, "
        SELECT COALESCE(SUM(i.quantity), 0) AS total_items
        FROM om_market_order_items i
        INNER JOIN om_market_orders o ON i.order_id = o.order_id
        WHERE o.customer_id = ?
          AND o.status IN ('entregue', 'delivered')
          AND o.date_added >= ?
    ", [$customer_id, $since]);
    $totalItems = (int)$stmt->fetchColumn();

    // ── 3. Top 3 products ───────────────────────────────────────────────
    $stmt = dbQuery($db, "
        SELECT
            i.product_name AS name,
            SUM(i.quantity) AS total_qty
        FROM om_market_order_items i
        INNER JOIN om_market_orders o ON i.order_id = o.order_id
        WHERE o.customer_id = ?
          AND o.status IN ('entregue', 'delivered')
          AND o.date_added >= ?
        GROUP BY i.product_name
        ORDER BY total_qty DESC
        LIMIT 3
    ", [$customer_id, $since]);
    $topProducts = $stmt->fetchAll();

    // Format top products
    $topProductsList = [];
    foreach ($topProducts as $p) {
        $topProductsList[] = [
            'name' => $p['name'],
            'count' => (int)$p['total_qty'],
        ];
    }

    $favoriteProduct = !empty($topProductsList) ? $topProductsList[0] : null;

    // ── 4. Top 3 stores ─────────────────────────────────────────────────
    $stmt = dbQuery($db, "
        SELECT
            p.name,
            p.logo,
            COUNT(o.order_id) AS order_count
        FROM om_market_orders o
        INNER JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.customer_id = ?
          AND o.status IN ('entregue', 'delivered')
          AND o.date_added >= ?
        GROUP BY p.partner_id, p.name, p.logo
        ORDER BY order_count DESC
        LIMIT 3
    ", [$customer_id, $since]);
    $topStores = $stmt->fetchAll();

    $topStoresList = [];
    foreach ($topStores as $s) {
        $topStoresList[] = [
            'name' => $s['name'],
            'logo' => $s['logo'],
            'count' => (int)$s['order_count'],
        ];
    }

    $favoriteStore = !empty($topStoresList) ? $topStoresList[0] : null;

    // ── 5. Favorite category ────────────────────────────────────────────
    $stmt = dbQuery($db, "
        SELECT
            o.partner_categoria AS category,
            COUNT(*) AS cnt
        FROM om_market_orders o
        WHERE o.customer_id = ?
          AND o.status IN ('entregue', 'delivered')
          AND o.date_added >= ?
          AND o.partner_categoria IS NOT NULL
          AND o.partner_categoria != ''
        GROUP BY o.partner_categoria
        ORDER BY cnt DESC
        LIMIT 1
    ", [$customer_id, $since]);
    $catRow = $stmt->fetch();
    $favoriteCategory = $catRow ? $catRow['category'] : null;

    // ── 6. Favorite day of week ─────────────────────────────────────────
    $dayNames = ['Domingo', 'Segunda', 'Terca', 'Quarta', 'Quinta', 'Sexta', 'Sabado'];
    $stmt = dbQuery($db, "
        SELECT
            EXTRACT(DOW FROM date_added) AS dow,
            COUNT(*) AS cnt
        FROM om_market_orders
        WHERE customer_id = ?
          AND status IN ('entregue', 'delivered')
          AND date_added >= ?
        GROUP BY dow
        ORDER BY cnt DESC
        LIMIT 1
    ", [$customer_id, $since]);
    $dowRow = $stmt->fetch();
    $favoriteDayIndex = $dowRow ? (int)$dowRow['dow'] : 0;
    $favoriteDay = $dayNames[$favoriteDayIndex] ?? 'Segunda';

    // ── 7. Favorite time of day ─────────────────────────────────────────
    $stmt = dbQuery($db, "
        SELECT
            CASE
                WHEN EXTRACT(HOUR FROM date_added) >= 6 AND EXTRACT(HOUR FROM date_added) < 12 THEN 'manha'
                WHEN EXTRACT(HOUR FROM date_added) >= 12 AND EXTRACT(HOUR FROM date_added) < 18 THEN 'tarde'
                WHEN EXTRACT(HOUR FROM date_added) >= 18 AND EXTRACT(HOUR FROM date_added) < 24 THEN 'noite'
                ELSE 'madrugada'
            END AS period,
            COUNT(*) AS cnt
        FROM om_market_orders
        WHERE customer_id = ?
          AND status IN ('entregue', 'delivered')
          AND date_added >= ?
        GROUP BY period
        ORDER BY cnt DESC
        LIMIT 1
    ", [$customer_id, $since]);
    $timeRow = $stmt->fetch();
    $favoriteTime = $timeRow ? $timeRow['period'] : 'tarde';

    // Map to display labels
    $timeLabels = [
        'manha' => 'manha',
        'tarde' => 'tarde',
        'noite' => 'noite',
        'madrugada' => 'madrugada',
    ];

    // ── 8. Month with most orders ───────────────────────────────────────
    $monthNames = ['', 'Janeiro', 'Fevereiro', 'Marco', 'Abril', 'Maio', 'Junho',
                   'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    $stmt = dbQuery($db, "
        SELECT
            EXTRACT(MONTH FROM date_added)::int AS month_num,
            EXTRACT(YEAR FROM date_added)::int AS year_num,
            COUNT(*) AS cnt
        FROM om_market_orders
        WHERE customer_id = ?
          AND status IN ('entregue', 'delivered')
          AND date_added >= ?
        GROUP BY month_num, year_num
        ORDER BY cnt DESC
        LIMIT 1
    ", [$customer_id, $since]);
    $monthRow = $stmt->fetch();
    $bestMonth = $monthRow ? [
        'name' => ($monthNames[(int)$monthRow['month_num']] ?? '') . ' ' . $monthRow['year_num'],
        'count' => (int)$monthRow['cnt'],
    ] : null;

    // ── 9. Longest ordering streak (consecutive days) ───────────────────
    $stmt = dbQuery($db, "
        SELECT DISTINCT DATE(date_added) AS order_date
        FROM om_market_orders
        WHERE customer_id = ?
          AND status IN ('entregue', 'delivered')
          AND date_added >= ?
        ORDER BY order_date
    ", [$customer_id, $since]);
    $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $maxStreak = 0;
    $currentStreak = 1;
    for ($i = 1; $i < count($dates); $i++) {
        $prev = new DateTime($dates[$i - 1]);
        $curr = new DateTime($dates[$i]);
        $diff = $prev->diff($curr)->days;
        if ($diff === 1) {
            $currentStreak++;
        } else {
            $maxStreak = max($maxStreak, $currentStreak);
            $currentStreak = 1;
        }
    }
    $maxStreak = max($maxStreak, $currentStreak);
    if (count($dates) === 0) $maxStreak = 0;

    // ── Build response ──────────────────────────────────────────────────
    $data = [
        'has_data' => true,
        'period' => [
            'from' => $since,
            'to' => date('Y-m-d H:i:s'),
        ],
        'total_orders' => $totalOrders,
        'total_spent' => $totalSpent,
        'avg_order_value' => $avgOrderValue,
        'total_items' => $totalItems,
        'unique_stores' => $uniqueStores,
        'favorite_product' => $favoriteProduct,
        'favorite_store' => $favoriteStore,
        'favorite_category' => $favoriteCategory,
        'favorite_day' => $favoriteDay,
        'favorite_time' => $favoriteTime,
        'best_month' => $bestMonth,
        'longest_streak' => $maxStreak,
        'top_products' => $topProductsList,
        'top_stores' => $topStoresList,
    ];

    response(true, $data);

} catch (Exception $e) {
    error_log("[customer/retrospectiva] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
