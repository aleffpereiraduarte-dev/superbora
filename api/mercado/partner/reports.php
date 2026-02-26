<?php
/**
 * GET /api/mercado/partner/reports.php
 * Monthly analytics report
 * Params: month (1-12), year (YYYY)
 * Returns: total_vendas, total_pedidos, ticket_medio, top_produtos, receita_por_dia, comparacao_mes_anterior
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

    $customRange = false;
    $customStartDate = $_GET['start_date'] ?? '';
    $customEndDate = $_GET['end_date'] ?? '';

    if (!empty($customStartDate) && !empty($customEndDate)
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customStartDate)
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customEndDate)) {
        $customRange = true;
        $startDate = $customStartDate;
        $endDate = $customEndDate;
        // For label
        $month = (int)date('n', strtotime($startDate));
        $year = (int)date('Y', strtotime($startDate));
        $monthPadded = str_pad($month, 2, '0', STR_PAD_LEFT);
    } else {
        $month = (int)($_GET['month'] ?? date('n'));
        $year = (int)($_GET['year'] ?? date('Y'));

        if ($month < 1 || $month > 12) $month = (int)date('n');
        if ($year < 2020 || $year > 2030) $year = (int)date('Y');

        $monthPadded = str_pad($month, 2, '0', STR_PAD_LEFT);
        $startDate = "{$year}-{$monthPadded}-01";
        $endDate = date('Y-m-t', strtotime($startDate));
    }

    // Mes/periodo anterior para comparacao
    if ($customRange) {
        $daysDiff = (strtotime($endDate) - strtotime($startDate)) / 86400;
        $prevEndDate = date('Y-m-d', strtotime($startDate . ' -1 day'));
        $prevStartDate = date('Y-m-d', strtotime($prevEndDate . " -{$daysDiff} days"));
    } else {
        $prevMonth = $month - 1;
        $prevYear = $year;
        if ($prevMonth < 1) {
            $prevMonth = 12;
            $prevYear = $year - 1;
        }
        $prevMonthPadded = str_pad($prevMonth, 2, '0', STR_PAD_LEFT);
        $prevStartDate = "{$prevYear}-{$prevMonthPadded}-01";
        $prevEndDate = date('Y-m-t', strtotime($prevStartDate));
    }

    // Total vendas e pedidos do mes
    $stmtMonth = $db->prepare("
        SELECT
            COALESCE(SUM(total), 0) as total_vendas,
            COUNT(*) as total_pedidos,
            COALESCE(AVG(total), 0) as ticket_medio
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status NOT IN ('cancelado', 'cancelled')
    ");
    $stmtMonth->execute([$partner_id, $startDate, $endDate]);
    $monthStats = $stmtMonth->fetch();

    // Mes anterior
    $stmtPrev = $db->prepare("
        SELECT
            COALESCE(SUM(total), 0) as total_vendas,
            COUNT(*) as total_pedidos,
            COALESCE(AVG(total), 0) as ticket_medio
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status NOT IN ('cancelado', 'cancelled')
    ");
    $stmtPrev->execute([$partner_id, $prevStartDate, $prevEndDate]);
    $prevStats = $stmtPrev->fetch();

    // Comparacao percentual
    $prevVendas = (float)$prevStats['total_vendas'];
    $currentVendas = (float)$monthStats['total_vendas'];
    $variacaoVendas = $prevVendas > 0
        ? round((($currentVendas - $prevVendas) / $prevVendas) * 100, 1)
        : ($currentVendas > 0 ? 100 : 0);

    $prevPedidos = (int)$prevStats['total_pedidos'];
    $currentPedidos = (int)$monthStats['total_pedidos'];
    $variacaoPedidos = $prevPedidos > 0
        ? round((($currentPedidos - $prevPedidos) / $prevPedidos) * 100, 1)
        : ($currentPedidos > 0 ? 100 : 0);

    // Top 10 produtos por quantidade vendida
    $stmtTop = $db->prepare("
        SELECT
            oi.product_id,
            oi.name,
            SUM(oi.quantity) as total_quantity,
            SUM(oi.price * oi.quantity) as total_revenue,
            COUNT(DISTINCT oi.order_id) as orders_count
        FROM om_market_order_items oi
        INNER JOIN om_market_orders o ON o.order_id = oi.order_id
        WHERE o.partner_id = ?
          AND DATE(o.date_added) BETWEEN ? AND ?
          AND o.status NOT IN ('cancelado', 'cancelled')
        GROUP BY oi.product_id, oi.name
        ORDER BY total_quantity DESC
        LIMIT 10
    ");
    $stmtTop->execute([$partner_id, $startDate, $endDate]);
    $topProducts = $stmtTop->fetchAll();

    $topProdutos = [];
    foreach ($topProducts as $tp) {
        $topProdutos[] = [
            "product_id" => (int)$tp['product_id'],
            "name" => $tp['name'],
            "quantity" => (int)$tp['total_quantity'],
            "revenue" => round((float)$tp['total_revenue'], 2),
            "orders" => (int)$tp['orders_count']
        ];
    }

    // Receita por dia
    $stmtDaily = $db->prepare("
        SELECT
            DATE(date_added) as date,
            COALESCE(SUM(total), 0) as total,
            COUNT(*) as pedidos
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status NOT IN ('cancelado', 'cancelled')
        GROUP BY DATE(date_added)
        ORDER BY DATE(date_added) ASC
    ");
    $stmtDaily->execute([$partner_id, $startDate, $endDate]);
    $dailyResults = $stmtDaily->fetchAll();

    // Preencher todos os dias do mes
    $dailyMap = [];
    foreach ($dailyResults as $row) {
        $dailyMap[$row['date']] = [
            "date" => $row['date'],
            "total" => round((float)$row['total'], 2),
            "pedidos" => (int)$row['pedidos']
        ];
    }

    $receitaPorDia = [];
    $currentDate = $startDate;
    $today = date('Y-m-d');
    while ($currentDate <= $endDate && $currentDate <= $today) {
        if (isset($dailyMap[$currentDate])) {
            $receitaPorDia[] = $dailyMap[$currentDate];
        } else {
            $receitaPorDia[] = [
                "date" => $currentDate,
                "total" => 0,
                "pedidos" => 0
            ];
        }
        $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
    }

    response(true, [
        "periodo" => [
            "month" => $month,
            "year" => $year,
            "label" => $customRange ? ($startDate . ' a ' . $endDate) : ($monthPadded . "/" . $year),
            "custom_range" => $customRange,
            "start_date" => $startDate,
            "end_date" => $endDate,
        ],
        "total_vendas" => round($currentVendas, 2),
        "total_pedidos" => $currentPedidos,
        "ticket_medio" => round((float)$monthStats['ticket_medio'], 2),
        "top_produtos" => $topProdutos,
        "receita_por_dia" => $receitaPorDia,
        "comparacao_mes_anterior" => [
            "vendas_anterior" => round($prevVendas, 2),
            "pedidos_anterior" => $prevPedidos,
            "variacao_vendas_percent" => $variacaoVendas,
            "variacao_pedidos_percent" => $variacaoPedidos
        ]
    ], "Relatorio mensal");

} catch (Exception $e) {
    error_log("[partner/reports] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
