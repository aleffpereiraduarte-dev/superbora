<?php
/**
 * GET /api/mercado/admin/csat.php
 * Admin CSAT dashboard — view customer satisfaction ratings
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requireAdmin();

    $tableCheck = $db->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'om_support_csat')");
    if (!$tableCheck->fetchColumn()) {
        response(true, ['stats' => ['total' => 0, 'avg_rating' => 0, 'distribution' => []], 'ratings' => []]);
    }

    $view = $_GET['view'] ?? 'stats';

    if ($view === 'stats') {
        $stmt = $db->query("
            SELECT
                COUNT(*) as total,
                ROUND(AVG(rating)::numeric, 2) as avg_rating,
                SUM(CASE WHEN rating >= 4 THEN 1 ELSE 0 END) as satisfied,
                SUM(CASE WHEN rating <= 2 THEN 1 ELSE 0 END) as unsatisfied
            FROM om_support_csat
        ");
        $stats = $stmt->fetch();
        $total = (int)$stats['total'];
        $stats['satisfaction_pct'] = $total > 0 ? round(((int)$stats['satisfied'] / $total) * 100, 1) : 0;

        // Distribution (1-5 stars)
        $stmtDist = $db->query("
            SELECT rating, COUNT(*) as total
            FROM om_support_csat
            GROUP BY rating
            ORDER BY rating
        ");
        $stats['distribution'] = $stmtDist->fetchAll();

        // By type
        $stmtType = $db->query("
            SELECT referencia_tipo as type, COUNT(*) as total, ROUND(AVG(rating)::numeric, 2) as avg
            FROM om_support_csat
            GROUP BY referencia_tipo
        ");
        $stats['by_type'] = $stmtType->fetchAll();

        // Trend (last 14 days)
        $stmtChart = $db->query("
            SELECT DATE(created_at) as dia, COUNT(*) as total, ROUND(AVG(rating)::numeric, 2) as avg_rating
            FROM om_support_csat
            WHERE created_at > NOW() - INTERVAL '14 days'
            GROUP BY DATE(created_at)
            ORDER BY dia
        ");
        $stats['chart'] = $stmtChart->fetchAll();

        // Recent low ratings (1-2 stars) — FIX: c.id -> c.customer_id, c.nome -> c.name
        $stmtLow = $db->query("
            SELECT cs.*, c.name as customer_name
            FROM om_support_csat cs
            LEFT JOIN om_market_customers c ON c.customer_id = cs.customer_id
            WHERE cs.rating <= 2
            ORDER BY cs.created_at DESC
            LIMIT 10
        ");
        $stats['recent_low'] = $stmtLow->fetchAll();

        response(true, ['stats' => $stats]);
    }

    // List all ratings
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $stmtCount = $db->query("SELECT COUNT(*) FROM om_support_csat");
    $total = (int)$stmtCount->fetchColumn();

    // FIX: c.id -> c.customer_id, c.nome -> c.name, parameterize LIMIT/OFFSET
    $stmt = $db->prepare("
        SELECT cs.*, c.name as customer_name, c.email as customer_email
        FROM om_support_csat cs
        LEFT JOIN om_market_customers c ON c.customer_id = cs.customer_id
        ORDER BY cs.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$limit, $offset]);

    response(true, [
        'ratings' => $stmt->fetchAll(),
        'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => $total > 0 ? (int)ceil($total / $limit) : 0]
    ]);

} catch (Exception $e) {
    error_log("[admin/csat] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
