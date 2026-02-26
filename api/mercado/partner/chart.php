<?php
/**
 * GET /api/mercado/partner/chart.php
 * Revenue chart data for last 7 days
 * Returns: array of {date, total, pedidos} per day
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];

    $days = (int)($_GET['days'] ?? 7);
    if ($days < 1 || $days > 90) $days = 7;

    // Buscar dados agrupados por dia
    $stmt = $db->prepare("
        SELECT
            DATE(date_added) as date,
            COALESCE(SUM(total), 0) as total,
            COUNT(*) as pedidos
        FROM om_market_orders
        WHERE partner_id = ?
          AND date_added >= CURRENT_DATE - (? || ' days')::INTERVAL
          AND status NOT IN ('cancelado', 'cancelled')
        GROUP BY DATE(date_added)
        ORDER BY DATE(date_added) ASC
    ");
    $stmt->execute([$partner_id, $days]);
    $results = $stmt->fetchAll();

    // Preencher dias sem pedidos com zeros
    $dataMap = [];
    foreach ($results as $row) {
        $dataMap[$row['date']] = [
            "date" => $row['date'],
            "total" => round((float)$row['total'], 2),
            "pedidos" => (int)$row['pedidos']
        ];
    }

    $chart = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        if (isset($dataMap[$date])) {
            $chart[] = $dataMap[$date];
        } else {
            $chart[] = [
                "date" => $date,
                "total" => 0,
                "pedidos" => 0
            ];
        }
    }

    response(true, [
        "chart" => $chart,
        "periodo" => $days . " dias"
    ], "Dados do grafico");

} catch (Exception $e) {
    error_log("[partner/chart] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
