<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = $payload['uid'];

    $period = $_GET['period'] ?? '7d';
    $days = $period === '30d' ? 30 : 7;

    $stmt = $db->prepare("
        SELECT DATE(created_at) as date,
               COUNT(*) as pedidos,
               COALESCE(SUM(CASE WHEN status NOT IN ('cancelled', 'refunded') THEN total ELSE 0 END), 0) as receita
        FROM om_market_orders
        WHERE created_at >= CURRENT_DATE - (? || ' days')::INTERVAL
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ");
    $stmt->execute([$days]);
    $rows = $stmt->fetchAll();

    // Build date map
    $date_map = [];
    foreach ($rows as $row) {
        $date_map[$row['date']] = $row;
    }

    // Fill missing dates with zeros
    $chart_data = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $chart_data[] = [
            'date' => $date,
            'pedidos' => (int)($date_map[$date]['pedidos'] ?? 0),
            'receita' => (float)($date_map[$date]['receita'] ?? 0)
        ];
    }

    response(true, ['chart' => $chart_data, 'period' => $period], "Dados do grafico");
} catch (Exception $e) {
    error_log("[admin/dashboard-chart] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
