<?php
/**
 * GET /api/mercado/partner/order-stats.php
 * Lightweight order metrics for the partner dashboard header
 * Auth: Bearer token
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];

    $today = date('Y-m-d');

    // Today's orders count and revenue
    $stmt = $db->prepare("
        SELECT
            COUNT(*) as total_today,
            COALESCE(SUM(CASE WHEN status NOT IN ('cancelado','cancelled') THEN total ELSE 0 END), 0) as revenue_today,
            SUM(CASE WHEN status IN ('pendente','pending') THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN status IN ('aceito','confirmed','confirmado','preparando') THEN 1 ELSE 0 END) as preparing_count,
            SUM(CASE WHEN status = 'entregue' THEN 1 ELSE 0 END) as delivered_count,
            SUM(CASE WHEN status IN ('pronto') THEN 1 ELSE 0 END) as ready_count
        FROM om_market_orders
        WHERE partner_id = ? AND DATE(date_added) = ?
    ");
    $stmt->execute([$partner_id, $today]);
    $stats = $stmt->fetch();

    // Average time from pendente to pronto (for today, completed orders)
    $stmt = $db->prepare("
        SELECT AVG(EXTRACT(EPOCH FROM (date_modified - date_added)) / 60) as avg_time
        FROM om_market_orders
        WHERE partner_id = ? AND DATE(date_added) = ?
        AND status IN ('pronto', 'em_entrega', 'entregue')
    ");
    $stmt->execute([$partner_id, $today]);
    $timeRow = $stmt->fetch();
    $avgTime = $timeRow['avg_time'] ? round((float)$timeRow['avg_time']) : null;

    response(true, [
        'total_today' => (int)$stats['total_today'],
        'revenue_today' => (float)$stats['revenue_today'],
        'pending_count' => (int)$stats['pending_count'],
        'preparing_count' => (int)$stats['preparing_count'],
        'delivered_count' => (int)$stats['delivered_count'],
        'ready_count' => (int)$stats['ready_count'],
        'avg_time_minutes' => $avgTime,
    ], "Metricas do dia");

} catch (Exception $e) {
    error_log("[order-stats] Erro: " . $e->getMessage());
    response(false, null, "Erro ao obter metricas", 500);
}
