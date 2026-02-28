<?php
/**
 * GET /api/mercado/partner/insights.php - Listar insights e analytics
 * POST /api/mercado/partner/insights.php - Marcar como lido ou gerar novos insights
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = $payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        // Get unread insights
        $stmt = $db->prepare("
            SELECT id, partner_id, insight_type, title, message, action_url, priority, is_read, expires_at, created_at
            FROM om_partner_insights
            WHERE partner_id = ? AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY priority DESC, created_at DESC
            LIMIT 20
        ");
        $stmt->execute([$partnerId]);
        $insights = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get daily analytics
        $stmt = $db->prepare("
            SELECT id, partner_id, date, total_orders, total_revenue, avg_order_value, new_customers, returning_customers, cancellation_rate
            FROM om_partner_analytics_daily
            WHERE partner_id = ? AND date >= CURRENT_DATE - INTERVAL '30 days'
            ORDER BY date DESC
        ");
        $stmt->execute([$partnerId]);
        $dailyAnalytics = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get benchmark data
        $stmt = $db->prepare("
            SELECT id, partner_id, metric, partner_value as value, percentile, period, created_at
            FROM om_partner_benchmark
            WHERE partner_id = ? AND period = 'last_30_days'
        ");
        $stmt->execute([$partnerId]);
        $benchmark = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate summary stats
        $stmt = $db->prepare("
            SELECT
                COUNT(*) as total_orders,
                SUM(total) as total_revenue,
                AVG(total) as avg_order_value,
                SUM(CASE WHEN status IN ('cancelado','cancelled') THEN 1 ELSE 0 END) as cancelled_orders
            FROM om_market_orders
            WHERE partner_id = ? AND created_at >= NOW() - INTERVAL '30 days'
        ");
        $stmt->execute([$partnerId]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get hourly distribution
        $stmt = $db->prepare("
            SELECT EXTRACT(HOUR FROM created_at)::int as hour, COUNT(*) as count
            FROM om_market_orders
            WHERE partner_id = ? AND created_at >= NOW() - INTERVAL '7 days'
            GROUP BY EXTRACT(HOUR FROM created_at)::int
            ORDER BY count DESC
        ");
        $stmt->execute([$partnerId]);
        $hourlyDist = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get top products
        $stmt = $db->prepare("
            SELECT p.product_id, p.name, COUNT(oi.id) as order_count, SUM(oi.quantity) as total_qty
            FROM om_market_order_items oi
            JOIN om_market_products p ON p.product_id = oi.product_id
            JOIN om_market_orders o ON o.order_id = oi.order_id
            WHERE o.partner_id = ? AND o.created_at >= NOW() - INTERVAL '30 days'
            GROUP BY p.product_id, p.name
            ORDER BY order_count DESC
            LIMIT 10
        ");
        $stmt->execute([$partnerId]);
        $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Peak hour
        $peakHour = !empty($hourlyDist) ? $hourlyDist[0]['hour'] : 12;

        response(true, [
            'insights' => $insights,
            'daily_analytics' => $dailyAnalytics,
            'benchmark' => $benchmark,
            'summary' => [
                'total_orders_30d' => (int)$summary['total_orders'],
                'total_revenue_30d' => (float)$summary['total_revenue'],
                'avg_order_value' => round((float)$summary['avg_order_value'], 2),
                'cancellation_rate' => $summary['total_orders'] > 0
                    ? round(($summary['cancelled_orders'] / $summary['total_orders']) * 100, 1)
                    : 0,
            ],
            'peak_hour' => (int)$peakHour,
            'hourly_distribution' => $hourlyDist,
            'top_products' => $topProducts,
        ]);
    }

    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? 'mark_read';

        if ($action === 'mark_read') {
            $insightId = intval($input['insight_id'] ?? 0);
            if ($insightId > 0) {
                $stmt = $db->prepare("UPDATE om_partner_insights SET is_read = 1 WHERE id = ? AND partner_id = ?");
                $stmt->execute([$insightId, $partnerId]);
            } else {
                // Mark all as read
                $stmt = $db->prepare("UPDATE om_partner_insights SET is_read = 1 WHERE partner_id = ?");
                $stmt->execute([$partnerId]);
            }
            response(true, null, "Insights marcados como lidos");
        }

        if ($action === 'generate') {
            // Generate new insights based on data analysis
            $newInsights = generateInsights($db, $partnerId);
            response(true, ['generated' => count($newInsights)], "Insights gerados!");
        }

        response(false, null, "Acao invalida", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/insights] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

function generateInsights($db, $partnerId) {
    $insights = [];

    // Check for partner average rating (reviews are per order, not per product)
    $stmt = $db->prepare("
        SELECT AVG(r.rating) as avg_rating, COUNT(r.id) as review_count
        FROM om_market_reviews r
        WHERE r.partner_id = ? AND r.created_at >= NOW() - INTERVAL '30 days'
    ");
    $stmt->execute([$partnerId]);
    $avgRating = $stmt->fetch();

    if ($avgRating && $avgRating['review_count'] >= 3 && $avgRating['avg_rating'] < 3.5) {
        $insights[] = insertInsight($db, $partnerId, 'alert',
            "Avaliacoes baixas recentes",
            "Sua media de avaliacoes nos ultimos 30 dias e de " . round($avgRating['avg_rating'], 1) . " estrelas. Confira os comentarios e melhore a experiencia!",
            '/avaliacoes', 2);
    }

    // Check for peak hour opportunity
    $stmt = $db->prepare("
        SELECT EXTRACT(HOUR FROM created_at)::int as hour, COUNT(*) as cnt
        FROM om_market_orders WHERE partner_id = ? AND created_at >= NOW() - INTERVAL '7 days'
        GROUP BY EXTRACT(HOUR FROM created_at)::int ORDER BY cnt ASC LIMIT 3
    ");
    $stmt->execute([$partnerId]);
    $slowHours = $stmt->fetchAll();

    if (!empty($slowHours)) {
        $hours = array_map(fn($h) => $h['hour'] . 'h', $slowHours);
        $insights[] = insertInsight($db, $partnerId, 'opportunity',
            "Horarios com poucas vendas",
            "Os horarios " . implode(', ', $hours) . " tem poucos pedidos. Crie promocoes Happy Hour para atrair clientes!",
            '/marketing', 1);
    }

    // Check stock alerts
    $stmt = $db->prepare("
        SELECT COUNT(*) as cnt FROM om_market_products
        WHERE partner_id = ? AND status = '1' AND (stock IS NOT NULL AND stock <= 5 AND stock > 0)
    ");
    $stmt->execute([$partnerId]);
    $lowStock = $stmt->fetch()['cnt'];

    if ($lowStock > 0) {
        $insights[] = insertInsight($db, $partnerId, 'alert',
            "Produtos com estoque baixo",
            "{$lowStock} produto(s) com estoque igual ou menor que 5 unidades. Reponha para evitar perdas de vendas.",
            '/produtos', 3);
    }

    return $insights;
}

function insertInsight($db, $partnerId, $type, $title, $message, $url = null, $priority = 0) {
    $stmt = $db->prepare("
        INSERT INTO om_partner_insights (partner_id, insight_type, title, message, action_url, priority, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW() + INTERVAL '7 days')
    ");
    $stmt->execute([$partnerId, $type, $title, $message, $url, $priority]);
    return $db->lastInsertId();
}
