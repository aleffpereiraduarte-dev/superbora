<?php
/**
 * GET /api/mercado/partner/benchmark.php
 * Competitor Benchmark (iFood competitive analysis)
 *
 * Compares this partner against others in the same city and category.
 * Returns rating, avg ticket, delivery fee, prep time, total orders,
 * city/category rank, percentile, strengths and weaknesses.
 *
 * Auth: Bearer token (partner type)
 * Cache: Redis 1 hour
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
        response(false, null, "Metodo nao permitido. Use GET.", 405);
    }

    // ── Redis cache check (1 hour) ──
    $redis = RedisService::getInstance();
    $cacheKey = "benchmark:{$partnerId}";
    if ($redis->isAvailable()) {
        $cached = $redis->get($cacheKey);
        if ($cached && is_array($cached)) {
            response(true, $cached, "Benchmark competitivo (cache).");
        }
    }

    // ── Step 1: Get this partner's info ──
    $stmtPartner = $db->prepare("
        SELECT partner_id, COALESCE(trade_name, name) as business_name,
               city, cidade, categoria, rating, delivery_fee
        FROM om_market_partners
        WHERE partner_id = ?
    ");
    $stmtPartner->execute([$partnerId]);
    $partner = $stmtPartner->fetch(PDO::FETCH_ASSOC);

    if (!$partner) {
        response(false, null, "Parceiro nao encontrado", 404);
    }

    $myCity = strtolower(trim($partner['city'] ?? $partner['cidade'] ?? ''));
    $myCategory = strtolower(trim($partner['categoria'] ?? ''));

    if (empty($myCity)) {
        response(false, null, "Cidade do parceiro nao configurada", 400);
    }

    // ── Step 2: Get this partner's metrics (last 30 days) ──
    $stmtMyMetrics = $db->prepare("
        SELECT
            COUNT(*) as total_orders,
            COUNT(CASE WHEN status IN ('entregue', 'finalizado') THEN 1 END) as completed_orders,
            COALESCE(AVG(CASE WHEN status IN ('entregue', 'finalizado') THEN total END), 0) as avg_ticket,
            AVG(CASE WHEN accepted_at IS NOT NULL AND ready_at IS NOT NULL
                THEN EXTRACT(EPOCH FROM (ready_at - accepted_at)) / 60 END) as avg_prep_time
        FROM om_market_orders
        WHERE partner_id = ?
          AND date_added >= NOW() - INTERVAL '30 days'
    ");
    $stmtMyMetrics->execute([$partnerId]);
    $myMetrics = $stmtMyMetrics->fetch(PDO::FETCH_ASSOC);

    $myRating = (float)($partner['rating'] ?? 0);
    $myAvgTicket = round((float)($myMetrics['avg_ticket'] ?? 0), 2);
    $myDeliveryFee = (float)($partner['delivery_fee'] ?? 0);
    $myPrepTime = round((float)($myMetrics['avg_prep_time'] ?? 0), 1);
    $myTotalOrders = (int)($myMetrics['completed_orders'] ?? 0);

    // ── Step 3: Get city averages (same city, active partners only) ──
    $stmtCityAvg = $db->prepare("
        SELECT
            AVG(p.rating) as avg_rating,
            AVG(p.delivery_fee) as avg_delivery_fee,
            COUNT(DISTINCT p.partner_id) as partner_count
        FROM om_market_partners p
        WHERE (LOWER(p.city) = ? OR LOWER(p.cidade) = ?)
          AND p.status::text = '1'
          AND p.partner_id != ?
    ");
    $stmtCityAvg->execute([$myCity, $myCity, $partnerId]);
    $cityAvg = $stmtCityAvg->fetch(PDO::FETCH_ASSOC);

    // City-wide order averages
    $stmtCityOrders = $db->prepare("
        SELECT
            AVG(sub.avg_ticket) as avg_ticket,
            AVG(sub.total_orders) as avg_orders,
            AVG(sub.avg_prep_time) as avg_prep_time
        FROM (
            SELECT
                o.partner_id,
                AVG(CASE WHEN o.status IN ('entregue', 'finalizado') THEN o.total END) as avg_ticket,
                COUNT(CASE WHEN o.status IN ('entregue', 'finalizado') THEN 1 END) as total_orders,
                AVG(CASE WHEN o.accepted_at IS NOT NULL AND o.ready_at IS NOT NULL
                    THEN EXTRACT(EPOCH FROM (o.ready_at - o.accepted_at)) / 60 END) as avg_prep_time
            FROM om_market_orders o
            JOIN om_market_partners p ON p.partner_id = o.partner_id
            WHERE (LOWER(p.city) = ? OR LOWER(p.cidade) = ?)
              AND p.status::text = '1'
              AND o.date_added >= NOW() - INTERVAL '30 days'
              AND o.partner_id != ?
            GROUP BY o.partner_id
        ) sub
    ");
    $stmtCityOrders->execute([$myCity, $myCity, $partnerId]);
    $cityOrderAvg = $stmtCityOrders->fetch(PDO::FETCH_ASSOC);

    $cityAvgRating = round((float)($cityAvg['avg_rating'] ?? 0), 2);
    $cityAvgTicket = round((float)($cityOrderAvg['avg_ticket'] ?? 0), 2);
    $cityAvgDeliveryFee = round((float)($cityAvg['avg_delivery_fee'] ?? 0), 2);
    $cityAvgPrepTime = round((float)($cityOrderAvg['avg_prep_time'] ?? 0), 1);
    $cityAvgOrders = round((float)($cityOrderAvg['avg_orders'] ?? 0), 1);
    $cityPartnerCount = (int)($cityAvg['partner_count'] ?? 0);

    // ── Step 4: City rank by completed orders ──
    $stmtRankCity = $db->prepare("
        SELECT COUNT(*) + 1 as rank
        FROM (
            SELECT o.partner_id,
                   COUNT(CASE WHEN o.status IN ('entregue', 'finalizado') THEN 1 END) as completed
            FROM om_market_orders o
            JOIN om_market_partners p ON p.partner_id = o.partner_id
            WHERE (LOWER(p.city) = ? OR LOWER(p.cidade) = ?)
              AND p.status::text = '1'
              AND o.date_added >= NOW() - INTERVAL '30 days'
              AND o.partner_id != ?
            GROUP BY o.partner_id
            HAVING COUNT(CASE WHEN o.status IN ('entregue', 'finalizado') THEN 1 END) > ?
        ) better
    ");
    $stmtRankCity->execute([$myCity, $myCity, $partnerId, $myTotalOrders]);
    $rankCity = (int)$stmtRankCity->fetchColumn();

    // ── Step 5: Category rank (same city AND category) ──
    $rankCategory = 1;
    $categoryPartnerCount = 0;
    if (!empty($myCategory)) {
        $stmtCatCount = $db->prepare("
            SELECT COUNT(*) FROM om_market_partners
            WHERE (LOWER(city) = ? OR LOWER(cidade) = ?)
              AND LOWER(categoria) = ?
              AND status::text = '1'
              AND partner_id != ?
        ");
        $stmtCatCount->execute([$myCity, $myCity, $myCategory, $partnerId]);
        $categoryPartnerCount = (int)$stmtCatCount->fetchColumn();

        $stmtRankCat = $db->prepare("
            SELECT COUNT(*) + 1 as rank
            FROM (
                SELECT o.partner_id,
                       COUNT(CASE WHEN o.status IN ('entregue', 'finalizado') THEN 1 END) as completed
                FROM om_market_orders o
                JOIN om_market_partners p ON p.partner_id = o.partner_id
                WHERE (LOWER(p.city) = ? OR LOWER(p.cidade) = ?)
                  AND LOWER(p.categoria) = ?
                  AND p.status::text = '1'
                  AND o.date_added >= NOW() - INTERVAL '30 days'
                  AND o.partner_id != ?
                GROUP BY o.partner_id
                HAVING COUNT(CASE WHEN o.status IN ('entregue', 'finalizado') THEN 1 END) > ?
            ) better
        ");
        $stmtRankCat->execute([$myCity, $myCity, $myCategory, $partnerId, $myTotalOrders]);
        $rankCategory = (int)$stmtRankCat->fetchColumn();
    }

    // ── Step 6: Calculate percentile ──
    $totalInCity = $cityPartnerCount + 1; // include self
    $percentile = $totalInCity > 1
        ? round((1 - ($rankCity - 1) / $totalInCity) * 100, 0)
        : 100;

    $percentileLabel = 'Top 50%';
    if ($percentile >= 95) $percentileLabel = 'Top 5%';
    elseif ($percentile >= 90) $percentileLabel = 'Top 10%';
    elseif ($percentile >= 75) $percentileLabel = 'Top 25%';
    elseif ($percentile >= 50) $percentileLabel = 'Top 50%';
    else $percentileLabel = 'Abaixo da media';

    // ── Step 7: Strengths and weaknesses ──
    $strengths = [];
    $weaknesses = [];

    // Rating comparison
    if ($myRating > 0 && $cityAvgRating > 0) {
        if ($myRating > $cityAvgRating + 0.3) {
            $strengths[] = [
                'metric' => 'Avaliacao',
                'yours' => $myRating,
                'city_avg' => $cityAvgRating,
                'message' => "Sua nota ({$myRating}) esta acima da media da cidade ({$cityAvgRating})",
            ];
        } elseif ($myRating < $cityAvgRating - 0.3) {
            $weaknesses[] = [
                'metric' => 'Avaliacao',
                'yours' => $myRating,
                'city_avg' => $cityAvgRating,
                'message' => "Sua nota ({$myRating}) esta abaixo da media da cidade ({$cityAvgRating}). Responda avaliacoes negativas.",
            ];
        }
    }

    // Avg ticket
    if ($myAvgTicket > 0 && $cityAvgTicket > 0) {
        if ($myAvgTicket > $cityAvgTicket * 1.15) {
            $strengths[] = [
                'metric' => 'Ticket medio',
                'yours' => $myAvgTicket,
                'city_avg' => $cityAvgTicket,
                'message' => "Seu ticket medio (R$ " . number_format($myAvgTicket, 2, ',', '.') . ") e superior a media (R$ " . number_format($cityAvgTicket, 2, ',', '.') . ")",
            ];
        } elseif ($myAvgTicket < $cityAvgTicket * 0.85) {
            $weaknesses[] = [
                'metric' => 'Ticket medio',
                'yours' => $myAvgTicket,
                'city_avg' => $cityAvgTicket,
                'message' => "Seu ticket medio esta abaixo da media. Considere combos ou upsells.",
            ];
        }
    }

    // Delivery fee
    if ($myDeliveryFee > 0 && $cityAvgDeliveryFee > 0) {
        if ($myDeliveryFee < $cityAvgDeliveryFee * 0.85) {
            $strengths[] = [
                'metric' => 'Taxa de entrega',
                'yours' => $myDeliveryFee,
                'city_avg' => $cityAvgDeliveryFee,
                'message' => "Sua taxa de entrega e mais competitiva que a media da cidade",
            ];
        } elseif ($myDeliveryFee > $cityAvgDeliveryFee * 1.25) {
            $weaknesses[] = [
                'metric' => 'Taxa de entrega',
                'yours' => $myDeliveryFee,
                'city_avg' => $cityAvgDeliveryFee,
                'message' => "Sua taxa de entrega (R$ " . number_format($myDeliveryFee, 2, ',', '.') . ") esta acima da media (R$ " . number_format($cityAvgDeliveryFee, 2, ',', '.') . ")",
            ];
        }
    }

    // Prep time (lower is better)
    if ($myPrepTime > 0 && $cityAvgPrepTime > 0) {
        if ($myPrepTime < $cityAvgPrepTime * 0.85) {
            $strengths[] = [
                'metric' => 'Tempo de preparo',
                'yours' => $myPrepTime,
                'city_avg' => $cityAvgPrepTime,
                'message' => "Seu preparo ({$myPrepTime} min) e mais rapido que a media ({$cityAvgPrepTime} min)",
            ];
        } elseif ($myPrepTime > $cityAvgPrepTime * 1.25) {
            $weaknesses[] = [
                'metric' => 'Tempo de preparo',
                'yours' => $myPrepTime,
                'city_avg' => $cityAvgPrepTime,
                'message' => "Seu preparo ({$myPrepTime} min) esta acima da media ({$cityAvgPrepTime} min). Revise processos.",
            ];
        }
    }

    // Order volume
    if ($myTotalOrders > 0 && $cityAvgOrders > 0) {
        if ($myTotalOrders > $cityAvgOrders * 1.5) {
            $strengths[] = [
                'metric' => 'Volume de pedidos',
                'yours' => $myTotalOrders,
                'city_avg' => $cityAvgOrders,
                'message' => "Seu volume de pedidos esta muito acima da media da cidade",
            ];
        } elseif ($myTotalOrders < $cityAvgOrders * 0.5) {
            $weaknesses[] = [
                'metric' => 'Volume de pedidos',
                'yours' => $myTotalOrders,
                'city_avg' => $cityAvgOrders,
                'message' => "Seu volume de pedidos esta abaixo da media. Invista em promocoes e visibilidade.",
            ];
        }
    }

    $result = [
        'your_metrics' => [
            'rating' => $myRating,
            'avg_ticket' => $myAvgTicket,
            'delivery_fee' => $myDeliveryFee,
            'prep_time' => $myPrepTime,
            'total_orders' => $myTotalOrders,
        ],
        'city_averages' => [
            'rating' => $cityAvgRating,
            'avg_ticket' => $cityAvgTicket,
            'delivery_fee' => $cityAvgDeliveryFee,
            'prep_time' => $cityAvgPrepTime,
            'avg_orders' => $cityAvgOrders,
            'partner_count' => $cityPartnerCount,
        ],
        'ranking' => [
            'rank_in_city' => $rankCity,
            'city_total' => $totalInCity,
            'rank_in_category' => $rankCategory,
            'category_total' => $categoryPartnerCount + 1,
            'percentile' => $percentile,
            'percentile_label' => $percentileLabel,
        ],
        'city' => $partner['city'] ?? $partner['cidade'] ?? '',
        'category' => $partner['categoria'] ?? '',
        'strengths' => $strengths,
        'weaknesses' => $weaknesses,
    ];

    // ── Cache result in Redis for 1 hour ──
    if ($redis->isAvailable()) {
        $redis->set($cacheKey, $result, 3600);
    }

    response(true, $result, "Benchmark competitivo calculado com sucesso.");

} catch (Exception $e) {
    error_log("[partner/benchmark] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
