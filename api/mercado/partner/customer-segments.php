<?php
/**
 * GET /api/mercado/partner/customer-segments.php
 * Customer Segmentation with RFM Analysis
 *
 * Params:
 *   periodo: 90d|180d|365d (default 90d)
 *   action: (empty)|segment_detail|overview
 *   segmento: (for segment_detail) champions|loyal|potential|new|at_risk|lost
 *
 * Returns RFM-based customer segments with actionable data
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];

    $action = $_GET['action'] ?? '';
    $periodo = $_GET['periodo'] ?? '90d';

    // Validate period
    $validPeriods = ['90d' => 90, '180d' => 180, '365d' => 365];
    $days = $validPeriods[$periodo] ?? 90;

    $startDate = date('Y-m-d', strtotime("-{$days} days"));
    $endDate = date('Y-m-d');

    switch ($action) {
        case 'segment_detail':
            handleSegmentDetail($db, $partner_id, $startDate, $endDate, $days);
            break;
        case 'overview':
            handleOverview($db, $partner_id, $startDate, $endDate, $days);
            break;
        default:
            handleRFMAnalysis($db, $partner_id, $startDate, $endDate, $days);
            break;
    }

} catch (Exception $e) {
    error_log("[partner/customer-segments] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar segmentacao de clientes", 500);
}

/**
 * Main RFM Analysis
 */
function handleRFMAnalysis($db, $partner_id, $startDate, $endDate, $days) {
    // Get customer order data for the period
    $stmtCustomers = $db->prepare("
        SELECT
            o.customer_id,
            c.name as nome,
            c.email,
            c.phone as telefone,
            COUNT(o.order_id) as total_pedidos,
            SUM(o.total) as total_gasto,
            MAX(o.date_added) as ultimo_pedido,
            MIN(o.date_added) as primeiro_pedido,
            AVG(o.total) as ticket_medio
        FROM om_market_orders o
        INNER JOIN om_customers c ON c.customer_id = o.customer_id
        WHERE o.partner_id = ?
          AND DATE(o.date_added) >= DATE(NOW() - INTERVAL '1 day' * ?)
          AND o.status NOT IN ('cancelado', 'cancelled')
        GROUP BY o.customer_id, c.name, c.email, c.phone
        ORDER BY total_gasto DESC
    ");
    $stmtCustomers->execute([$partner_id, (int)$days]);
    $customers = $stmtCustomers->fetchAll();

    if (empty($customers)) {
        response(true, [
            'clientes' => [],
            'segmentos' => buildEmptySegments(),
            'periodo' => ['dias' => $days, 'inicio' => $startDate, 'fim' => $endDate]
        ], "Sem clientes no periodo");
        return;
    }

    // Calculate RFM boundaries using quintiles
    $recencies = [];
    $frequencies = [];
    $monetaries = [];

    foreach ($customers as $c) {
        $daysSinceOrder = (int)((time() - strtotime($c['ultimo_pedido'])) / 86400);
        $recencies[] = $daysSinceOrder;
        $frequencies[] = (int)$c['total_pedidos'];
        $monetaries[] = (float)$c['total_gasto'];
    }

    // Sort for quintile calculation
    $sortedR = $recencies;
    $sortedF = $frequencies;
    $sortedM = $monetaries;
    sort($sortedR);
    sort($sortedF);
    sort($sortedM);

    $count = count($customers);

    // Calculate quintile boundaries
    $rQuintiles = getQuintiles($sortedR);
    $fQuintiles = getQuintiles($sortedF);
    $mQuintiles = getQuintiles($sortedM);

    // Score each customer
    $scoredCustomers = [];
    $segmentCounts = [
        'champions' => ['count' => 0, 'total_value' => 0, 'clientes' => []],
        'loyal' => ['count' => 0, 'total_value' => 0, 'clientes' => []],
        'potential' => ['count' => 0, 'total_value' => 0, 'clientes' => []],
        'new' => ['count' => 0, 'total_value' => 0, 'clientes' => []],
        'at_risk' => ['count' => 0, 'total_value' => 0, 'clientes' => []],
        'lost' => ['count' => 0, 'total_value' => 0, 'clientes' => []],
    ];

    foreach ($customers as $idx => $c) {
        $daysSinceOrder = $recencies[$idx];
        $frequency = (int)$c['total_pedidos'];
        $monetary = (float)$c['total_gasto'];

        // Recency: LOWER is better (inverted scoring — 5 = most recent)
        $rScore = scoreRecency($daysSinceOrder, $rQuintiles);
        // Frequency: HIGHER is better
        $fScore = scoreValue($frequency, $fQuintiles);
        // Monetary: HIGHER is better
        $mScore = scoreValue($monetary, $mQuintiles);

        $rfmScore = "{$rScore}{$fScore}{$mScore}";
        $segmento = classifyRFM($rScore, $fScore, $mScore);

        $customerData = [
            'customer_id' => (int)$c['customer_id'],
            'nome' => $c['nome'] ?: 'Cliente',
            'email' => $c['email'],
            'telefone' => $c['telefone'],
            'total_pedidos' => $frequency,
            'total_gasto' => round($monetary, 2),
            'ticket_medio' => round((float)$c['ticket_medio'], 2),
            'ultimo_pedido' => $c['ultimo_pedido'],
            'primeiro_pedido' => $c['primeiro_pedido'],
            'dias_desde_ultimo' => $daysSinceOrder,
            'recencia' => $rScore,
            'frequencia' => $fScore,
            'monetario' => $mScore,
            'rfm_score' => $rfmScore,
            'segmento' => $segmento
        ];

        $scoredCustomers[] = $customerData;
        $segmentCounts[$segmento]['count']++;
        $segmentCounts[$segmento]['total_value'] += $monetary;
    }

    // Build segment summary
    $totalCustomers = count($scoredCustomers);
    $segments = [];
    $segmentLabels = [
        'champions' => 'Champions',
        'loyal' => 'Leais',
        'potential' => 'Potenciais',
        'new' => 'Novos',
        'at_risk' => 'Em Risco',
        'lost' => 'Perdidos'
    ];

    foreach ($segmentCounts as $key => $data) {
        $segments[] = [
            'segmento' => $key,
            'label' => $segmentLabels[$key],
            'count' => $data['count'],
            'percentual' => $totalCustomers > 0 ? round(($data['count'] / $totalCustomers) * 100, 1) : 0,
            'total_valor' => round($data['total_value'], 2),
            'ticket_medio' => $data['count'] > 0 ? round($data['total_value'] / $data['count'], 2) : 0
        ];
    }

    response(true, [
        'clientes' => $scoredCustomers,
        'segmentos' => $segments,
        'total_clientes' => $totalCustomers,
        'periodo' => [
            'dias' => $days,
            'inicio' => $startDate,
            'fim' => $endDate
        ]
    ], "Segmentacao RFM");
}

/**
 * Segment Detail: List customers in a specific segment
 */
function handleSegmentDetail($db, $partner_id, $startDate, $endDate, $days) {
    $segmento = $_GET['segmento'] ?? '';
    $validSegments = ['champions', 'loyal', 'potential', 'new', 'at_risk', 'lost'];

    if (!in_array($segmento, $validSegments, true)) {
        response(false, null, "Segmento invalido. Use: " . implode(', ', $validSegments), 400);
        return;
    }

    // Get all customers, calculate RFM, filter by segment
    $stmtCustomers = $db->prepare("
        SELECT
            o.customer_id,
            c.name as nome,
            c.email,
            c.phone as telefone,
            COUNT(o.order_id) as total_pedidos,
            SUM(o.total) as total_gasto,
            MAX(o.date_added) as ultimo_pedido,
            MIN(o.date_added) as primeiro_pedido,
            AVG(o.total) as ticket_medio
        FROM om_market_orders o
        INNER JOIN om_customers c ON c.customer_id = o.customer_id
        WHERE o.partner_id = ?
          AND DATE(o.date_added) >= DATE(NOW() - INTERVAL '1 day' * ?)
          AND o.status NOT IN ('cancelado', 'cancelled')
        GROUP BY o.customer_id, c.name, c.email, c.phone
    ");
    $stmtCustomers->execute([$partner_id, (int)$days]);
    $customers = $stmtCustomers->fetchAll();

    // Calculate quintiles
    $recencies = [];
    $frequencies = [];
    $monetaries = [];
    foreach ($customers as $c) {
        $recencies[] = (int)((time() - strtotime($c['ultimo_pedido'])) / 86400);
        $frequencies[] = (int)$c['total_pedidos'];
        $monetaries[] = (float)$c['total_gasto'];
    }
    $sortedR = $recencies; sort($sortedR);
    $sortedF = $frequencies; sort($sortedF);
    $sortedM = $monetaries; sort($sortedM);

    $rQ = getQuintiles($sortedR);
    $fQ = getQuintiles($sortedF);
    $mQ = getQuintiles($sortedM);

    // Filter by segment
    $filtered = [];
    foreach ($customers as $idx => $c) {
        $r = scoreRecency($recencies[$idx], $rQ);
        $f = scoreValue($frequencies[$idx], $fQ);
        $m = scoreValue($monetaries[$idx], $mQ);
        $seg = classifyRFM($r, $f, $m);

        if ($seg === $segmento) {
            $filtered[] = [
                'customer_id' => (int)$c['customer_id'],
                'nome' => $c['nome'] ?: 'Cliente',
                'email' => $c['email'],
                'telefone' => $c['telefone'],
                'total_pedidos' => (int)$c['total_pedidos'],
                'total_gasto' => round((float)$c['total_gasto'], 2),
                'ticket_medio' => round((float)$c['ticket_medio'], 2),
                'ultimo_pedido' => $c['ultimo_pedido'],
                'primeiro_pedido' => $c['primeiro_pedido'],
                'dias_desde_ultimo' => $recencies[$idx],
                'rfm_score' => "{$r}{$f}{$m}"
            ];
        }
    }

    // Sort by total_gasto descending
    usort($filtered, fn($a, $b) => $b['total_gasto'] - $a['total_gasto']);

    $segmentLabels = [
        'champions' => 'Champions',
        'loyal' => 'Leais',
        'potential' => 'Potenciais',
        'new' => 'Novos',
        'at_risk' => 'Em Risco',
        'lost' => 'Perdidos'
    ];

    response(true, [
        'segmento' => $segmento,
        'label' => $segmentLabels[$segmento],
        'clientes' => $filtered,
        'total' => count($filtered),
        'periodo' => ['dias' => $days, 'inicio' => $startDate, 'fim' => $endDate]
    ], "Detalhe do segmento");
}

/**
 * Overview: High-level customer metrics
 */
function handleOverview($db, $partner_id, $startDate, $endDate, $days) {
    // Total unique customers (all time for this partner)
    $stmtTotal = $db->prepare("
        SELECT COUNT(DISTINCT customer_id) as total
        FROM om_market_orders
        WHERE partner_id = ?
          AND status NOT IN ('cancelado', 'cancelled')
    ");
    $stmtTotal->execute([$partner_id]);
    $totalCustomers = (int)$stmtTotal->fetchColumn();

    // New customers this month (first order from this partner in current month)
    $monthStart = date('Y-m-01');
    $stmtNew = $db->prepare("
        SELECT COUNT(*) as total FROM (
            SELECT customer_id
            FROM om_market_orders
            WHERE partner_id = ?
              AND status NOT IN ('cancelado', 'cancelled')
            GROUP BY customer_id
            HAVING MIN(DATE(date_added)) >= ?
        ) as new_customers
    ");
    $stmtNew->execute([$partner_id, $monthStart]);
    $newThisMonth = (int)$stmtNew->fetchColumn();

    // Customers in period
    $stmtPeriod = $db->prepare("
        SELECT COUNT(DISTINCT customer_id) as total
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status NOT IN ('cancelado', 'cancelled')
    ");
    $stmtPeriod->execute([$partner_id, $startDate, $endDate]);
    $periodCustomers = (int)$stmtPeriod->fetchColumn();

    // Returning customers (>1 order ever from this partner)
    $stmtReturning = $db->prepare("
        SELECT COUNT(*) as total FROM (
            SELECT customer_id
            FROM om_market_orders
            WHERE partner_id = ?
              AND status NOT IN ('cancelado', 'cancelled')
            GROUP BY customer_id
            HAVING COUNT(*) > 1
        ) as returning
    ");
    $stmtReturning->execute([$partner_id]);
    $returningCustomers = (int)$stmtReturning->fetchColumn();

    $newCustomers = $totalCustomers - $returningCustomers;

    // Average CLV (Customer Lifetime Value)
    $stmtCLV = $db->prepare("
        SELECT AVG(customer_total) as avg_clv FROM (
            SELECT SUM(total) as customer_total
            FROM om_market_orders
            WHERE partner_id = ?
              AND status NOT IN ('cancelado', 'cancelled')
            GROUP BY customer_id
        ) as clv
    ");
    $stmtCLV->execute([$partner_id]);
    $avgCLV = round((float)($stmtCLV->fetchColumn() ?: 0), 2);

    // Churn rate: customers who ordered before last 60 days but not in last 60 days
    $churnCutoff = date('Y-m-d', strtotime('-60 days'));
    $stmtChurned = $db->prepare("
        SELECT COUNT(*) as total FROM (
            SELECT customer_id
            FROM om_market_orders
            WHERE partner_id = ?
              AND status NOT IN ('cancelado', 'cancelled')
            GROUP BY customer_id
            HAVING MAX(DATE(date_added)) < ?
               AND MIN(DATE(date_added)) < ?
        ) as churned
    ");
    $stmtChurned->execute([$partner_id, $churnCutoff, $churnCutoff]);
    $churnedCustomers = (int)$stmtChurned->fetchColumn();

    $churnRate = $totalCustomers > 0 ? round(($churnedCustomers / $totalCustomers) * 100, 1) : 0;

    // Retention curve: % of customers retained after 1, 2, 3, 6 months
    $retentionCurve = [];
    $retentionPeriods = [
        ['label' => '1 mes', 'months' => 1],
        ['label' => '2 meses', 'months' => 2],
        ['label' => '3 meses', 'months' => 3],
        ['label' => '6 meses', 'months' => 6],
    ];

    foreach ($retentionPeriods as $rp) {
        $monthsAgo = $rp['months'];
        $cohortStart = date('Y-m-d', strtotime("-{$monthsAgo} months -30 days"));
        $cohortEnd = date('Y-m-d', strtotime("-{$monthsAgo} months"));
        $retainedAfter = date('Y-m-d', strtotime("-{$monthsAgo} months +1 day"));

        // Customers who first ordered in cohort period
        $stmtCohort = $db->prepare("
            SELECT COUNT(*) as total FROM (
                SELECT customer_id
                FROM om_market_orders
                WHERE partner_id = ?
                  AND status NOT IN ('cancelado', 'cancelled')
                GROUP BY customer_id
                HAVING MIN(DATE(date_added)) BETWEEN ? AND ?
            ) as cohort
        ");
        $stmtCohort->execute([$partner_id, $cohortStart, $cohortEnd]);
        $cohortSize = (int)$stmtCohort->fetchColumn();

        // Of those, how many ordered again after the cohort period?
        $stmtRetained = $db->prepare("
            SELECT COUNT(*) as total FROM (
                SELECT customer_id
                FROM om_market_orders
                WHERE partner_id = ?
                  AND status NOT IN ('cancelado', 'cancelled')
                GROUP BY customer_id
                HAVING MIN(DATE(date_added)) BETWEEN ? AND ?
                   AND MAX(DATE(date_added)) >= ?
            ) as retained
        ");
        $stmtRetained->execute([$partner_id, $cohortStart, $cohortEnd, $retainedAfter]);
        $retainedCount = (int)$stmtRetained->fetchColumn();

        $retentionCurve[] = [
            'label' => $rp['label'],
            'months' => $monthsAgo,
            'cohort_size' => $cohortSize,
            'retained' => $retainedCount,
            'retention_rate' => $cohortSize > 0 ? round(($retainedCount / $cohortSize) * 100, 1) : 0
        ];
    }

    // Orders per customer distribution
    $stmtDist = $db->prepare("
        SELECT
            CASE
                WHEN cnt = 1 THEN '1 pedido'
                WHEN cnt BETWEEN 2 AND 3 THEN '2-3 pedidos'
                WHEN cnt BETWEEN 4 AND 6 THEN '4-6 pedidos'
                WHEN cnt BETWEEN 7 AND 10 THEN '7-10 pedidos'
                ELSE '10+ pedidos'
            END as faixa,
            COUNT(*) as clientes
        FROM (
            SELECT customer_id, COUNT(*) as cnt
            FROM om_market_orders
            WHERE partner_id = ?
              AND status NOT IN ('cancelado', 'cancelled')
            GROUP BY customer_id
        ) as dist
        GROUP BY
            CASE
                WHEN cnt = 1 THEN '1 pedido'
                WHEN cnt BETWEEN 2 AND 3 THEN '2-3 pedidos'
                WHEN cnt BETWEEN 4 AND 6 THEN '4-6 pedidos'
                WHEN cnt BETWEEN 7 AND 10 THEN '7-10 pedidos'
                ELSE '10+ pedidos'
            END
        ORDER BY MIN(cnt)
    ");
    $stmtDist->execute([$partner_id]);
    $orderDistribution = $stmtDist->fetchAll();

    response(true, [
        'total_clientes' => $totalCustomers,
        'clientes_periodo' => $periodCustomers,
        'novos_mes' => $newThisMonth,
        'novos_total' => $newCustomers,
        'recorrentes' => $returningCustomers,
        'ratio_novos_recorrentes' => [
            'novos' => $newCustomers,
            'recorrentes' => $returningCustomers,
            'percent_novos' => $totalCustomers > 0 ? round(($newCustomers / $totalCustomers) * 100, 1) : 0,
            'percent_recorrentes' => $totalCustomers > 0 ? round(($returningCustomers / $totalCustomers) * 100, 1) : 0,
        ],
        'clv_medio' => $avgCLV,
        'churn_rate' => $churnRate,
        'churned_customers' => $churnedCustomers,
        'retention_curve' => $retentionCurve,
        'distribuicao_pedidos' => $orderDistribution,
        'periodo' => ['dias' => $days, 'inicio' => $startDate, 'fim' => $endDate]
    ], "Visao geral de clientes");
}

// ──────────────────────────────────────────────
// RFM Helper Functions
// ──────────────────────────────────────────────

function getQuintiles(array $sorted): array {
    $count = count($sorted);
    if ($count === 0) return [0, 0, 0, 0];
    if ($count === 1) return [$sorted[0], $sorted[0], $sorted[0], $sorted[0]];

    return [
        $sorted[(int)floor($count * 0.2)] ?? $sorted[0],
        $sorted[(int)floor($count * 0.4)] ?? $sorted[0],
        $sorted[(int)floor($count * 0.6)] ?? $sorted[0],
        $sorted[(int)floor($count * 0.8)] ?? $sorted[0],
    ];
}

/**
 * Score recency (inverted: lower days = higher score)
 */
function scoreRecency(int $value, array $quintiles): int {
    if ($value <= $quintiles[0]) return 5;
    if ($value <= $quintiles[1]) return 4;
    if ($value <= $quintiles[2]) return 3;
    if ($value <= $quintiles[3]) return 2;
    return 1;
}

/**
 * Score frequency/monetary (higher value = higher score)
 */
function scoreValue($value, array $quintiles): int {
    if ($value >= $quintiles[3]) return 5;
    if ($value >= $quintiles[2]) return 4;
    if ($value >= $quintiles[1]) return 3;
    if ($value >= $quintiles[0]) return 2;
    return 1;
}

/**
 * Classify customer based on RFM scores into segments
 */
function classifyRFM(int $r, int $f, int $m): string {
    // Champions: R>=4, F>=4, M>=4
    if ($r >= 4 && $f >= 4 && $m >= 4) return 'champions';

    // Loyal: F>=4 or (R>=3 and F>=3)
    if ($f >= 4 || ($r >= 3 && $f >= 3 && $m >= 3)) return 'loyal';

    // At Risk: R<=2 and (F>=3 or M>=3) — used to be good
    if ($r <= 2 && ($f >= 3 || $m >= 3)) return 'at_risk';

    // New: R>=4 and F<=2 — recent but infrequent
    if ($r >= 4 && $f <= 2) return 'new';

    // Potential: R>=3 and (F==2 or F==3) — could become loyal
    if ($r >= 3 && $f >= 2 && $f <= 3) return 'potential';

    // Lost: R<=2 and F<=2
    if ($r <= 2 && $f <= 2) return 'lost';

    // Default: potential
    return 'potential';
}

function buildEmptySegments(): array {
    $labels = [
        'champions' => 'Champions',
        'loyal' => 'Leais',
        'potential' => 'Potenciais',
        'new' => 'Novos',
        'at_risk' => 'Em Risco',
        'lost' => 'Perdidos'
    ];
    $result = [];
    foreach ($labels as $key => $label) {
        $result[] = [
            'segmento' => $key,
            'label' => $label,
            'count' => 0,
            'percentual' => 0,
            'total_valor' => 0,
            'ticket_medio' => 0
        ];
    }
    return $result;
}
