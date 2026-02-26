<?php
/**
 * GET /api/mercado/partner/store-metrics.php
 * Comprehensive store metrics & score system
 *
 * Params:
 *   periodo   = 7d | 30d | 90d (default: 30d)
 *   action    = (empty) | trends
 *
 * Default: Returns full metrics for the period
 * action=trends: Returns comparison vs previous period (% change)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];

    $periodo = $_GET['periodo'] ?? '30d';
    $action = $_GET['action'] ?? '';

    // Validate and calculate date ranges
    $validPeriods = ['7d' => 7, '30d' => 30, '90d' => 90];
    $days = $validPeriods[$periodo] ?? 30;
    $periodo = array_search($days, $validPeriods) ?: '30d';

    $now = new DateTime();
    $endDate = $now->format('Y-m-d');
    $startDate = (clone $now)->modify("-{$days} days")->format('Y-m-d');

    // Previous period for comparison
    $prevEndDate = (clone $now)->modify("-" . ($days + 1) . " days")->format('Y-m-d');
    $prevStartDate = (clone $now)->modify("-" . ($days * 2) . " days")->format('Y-m-d');

    // Status history table already exists with columns: status_from, status_to, created_at

    if ($action === 'trends') {
        handleTrends($db, $partner_id, $startDate, $endDate, $prevStartDate, $prevEndDate, $periodo);
    } else {
        handleMetrics($db, $partner_id, $startDate, $endDate, $prevStartDate, $prevEndDate, $days, $periodo);
    }

} catch (Exception $e) {
    error_log("[partner/store-metrics] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

/**
 * Calculate metrics for a given date range
 */
function getBaseMetrics(PDO $db, int $partner_id, string $startDate, string $endDate): array {
    // 1. Total orders in period
    $stmt = $db->prepare("
        SELECT COUNT(*) as total
        FROM om_market_orders
        WHERE partner_id = ? AND DATE(date_added) BETWEEN ? AND ?
    ");
    $stmt->execute([$partner_id, $startDate, $endDate]);
    $totalPedidos = (int)$stmt->fetchColumn();

    // 2. Cancelled orders
    $stmt = $db->prepare("
        SELECT COUNT(*) as total
        FROM om_market_orders
        WHERE partner_id = ? AND DATE(date_added) BETWEEN ? AND ?
          AND status IN ('cancelado', 'cancelled')
    ");
    $stmt->execute([$partner_id, $startDate, $endDate]);
    $cancelledOrders = (int)$stmt->fetchColumn();

    $taxaCancelamento = $totalPedidos > 0 ? round(($cancelledOrders / $totalPedidos) * 100, 1) : 0;

    // 3. Average preparation time
    // First try om_market_order_events (aceito -> pronto)
    $stmt = $db->prepare("
        SELECT AVG(EXTRACT(EPOCH FROM (e.created_at - o.accepted_at)) / 60) as avg_minutes
        FROM om_market_orders o
        INNER JOIN om_market_order_events e ON e.order_id = o.order_id AND e.event_type = 'partner_pronto'
        WHERE o.partner_id = ?
          AND DATE(o.date_added) BETWEEN ? AND ?
          AND o.accepted_at IS NOT NULL
    ");
    $stmt->execute([$partner_id, $startDate, $endDate]);
    $tempoMedioPreparo = (float)($stmt->fetchColumn() ?: 0);

    // Fallback: try status history
    if ($tempoMedioPreparo == 0) {
        $stmt = $db->prepare("
            SELECT AVG(EXTRACT(EPOCH FROM (h2.created_at - h1.created_at)) / 60) as avg_minutes
            FROM om_market_order_status_history h1
            INNER JOIN om_market_order_status_history h2 ON h1.order_id = h2.order_id
            INNER JOIN om_market_orders o ON o.order_id = h1.order_id
            WHERE o.partner_id = ?
              AND DATE(o.date_added) BETWEEN ? AND ?
              AND h1.status_to IN ('aceito', 'accepted', 'preparando')
              AND h2.status_to IN ('pronto', 'ready')
        ");
        $stmt->execute([$partner_id, $startDate, $endDate]);
        $tempoMedioPreparo = (float)($stmt->fetchColumn() ?: 0);
    }

    // Second fallback: estimate from accepted_at to updated_at for completed orders
    if ($tempoMedioPreparo == 0) {
        $stmt = $db->prepare("
            SELECT AVG(EXTRACT(EPOCH FROM (date_modified - accepted_at)) / 60) as avg_minutes
            FROM om_market_orders
            WHERE partner_id = ?
              AND DATE(date_added) BETWEEN ? AND ?
              AND accepted_at IS NOT NULL
              AND status IN ('entregue', 'pronto', 'ready', 'em_entrega', 'saiu_entrega')
        ");
        $stmt->execute([$partner_id, $startDate, $endDate]);
        $tempoMedioPreparo = (float)($stmt->fetchColumn() ?: 0);
    }

    $tempoMedioPreparo = round($tempoMedioPreparo, 1);

    // 4. Average rating - try om_market_reviews first, then om_market_order_reviews
    $stmt = $db->prepare("
        SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews
        FROM om_market_reviews
        WHERE partner_id = ?
          AND DATE(created_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$partner_id, $startDate, $endDate]);
    $ratingData = $stmt->fetch();
    $notaMedia = round((float)($ratingData['avg_rating'] ?? 0), 2);
    $totalAvaliacoes = (int)($ratingData['total_reviews'] ?? 0);

    // Fallback to om_market_order_reviews
    if ($totalAvaliacoes === 0) {
        $stmt = $db->prepare("
            SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews
            FROM om_market_order_reviews
            WHERE partner_id = ?
              AND DATE(created_at) BETWEEN ? AND ?
        ");
        $stmt->execute([$partner_id, $startDate, $endDate]);
        $ratingData = $stmt->fetch();
        $notaMedia = round((float)($ratingData['avg_rating'] ?? 0), 2);
        $totalAvaliacoes = (int)($ratingData['total_reviews'] ?? 0);
    }

    // 5. Rating distribution (1-5 stars)
    $distribuicaoNotas = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    $stmt = $db->prepare("
        SELECT rating, COUNT(*) as cnt
        FROM om_market_reviews
        WHERE partner_id = ?
          AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY rating
    ");
    $stmt->execute([$partner_id, $startDate, $endDate]);
    $rows = $stmt->fetchAll();
    foreach ($rows as $row) {
        $r = (int)$row['rating'];
        if ($r >= 1 && $r <= 5) {
            $distribuicaoNotas[$r] = (int)$row['cnt'];
        }
    }

    // Also try om_market_order_reviews if no data
    if (array_sum($distribuicaoNotas) === 0) {
        $stmt = $db->prepare("
            SELECT rating, COUNT(*) as cnt
            FROM om_market_order_reviews
            WHERE partner_id = ?
              AND DATE(created_at) BETWEEN ? AND ?
            GROUP BY rating
        ");
        $stmt->execute([$partner_id, $startDate, $endDate]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $r = (int)$row['rating'];
            if ($r >= 1 && $r <= 5) {
                $distribuicaoNotas[$r] = (int)$row['cnt'];
            }
        }
    }

    // 6. Late delivery rate - estimate from orders where delivery exceeded expected time
    $stmt = $db->prepare("
        SELECT
            COUNT(*) FILTER (WHERE delivered_at IS NOT NULL AND EXTRACT(EPOCH FROM (delivered_at - date_added)) / 60 > COALESCE(
                (SELECT mp.delivery_time_max FROM om_market_partners mp WHERE mp.partner_id = om_market_orders.partner_id LIMIT 1),
                60
            )) as late_count,
            COUNT(*) as total_delivered
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status = 'entregue'
    ");
    $stmt->execute([$partner_id, $startDate, $endDate]);
    $deliveryData = $stmt->fetch();
    $lateCount = (int)($deliveryData['late_count'] ?? 0);
    $totalDelivered = (int)($deliveryData['total_delivered'] ?? 0);
    $taxaAtraso = $totalDelivered > 0 ? round(($lateCount / $totalDelivered) * 100, 1) : 0;

    // 7. Average ticket
    $stmt = $db->prepare("
        SELECT COALESCE(AVG(total), 0) as ticket_medio
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status NOT IN ('cancelado', 'cancelled')
    ");
    $stmt->execute([$partner_id, $startDate, $endDate]);
    $ticketMedio = round((float)$stmt->fetchColumn(), 2);

    // 8. Total revenue
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(total), 0) as faturamento
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status NOT IN ('cancelado', 'cancelled')
    ");
    $stmt->execute([$partner_id, $startDate, $endDate]);
    $faturamento = round((float)$stmt->fetchColumn(), 2);

    return [
        'total_pedidos' => $totalPedidos,
        'taxa_cancelamento' => $taxaCancelamento,
        'tempo_medio_preparo' => $tempoMedioPreparo,
        'nota_media' => $notaMedia,
        'total_avaliacoes' => $totalAvaliacoes,
        'distribuicao_notas' => $distribuicaoNotas,
        'taxa_atraso' => $taxaAtraso,
        'ticket_medio' => $ticketMedio,
        'faturamento' => $faturamento,
        'cancelled_orders' => $cancelledOrders,
    ];
}

/**
 * Calculate the store score (0-100)
 */
function calculateScore(array $metrics): array {
    // nota_media: 30% weight (scale 1-5 to 0-100)
    $notaScore = 0;
    if ($metrics['nota_media'] > 0) {
        $notaScore = min(100, max(0, (($metrics['nota_media'] - 1) / 4) * 100));
    }

    // taxa_cancelamento: 25% weight (inverse: 0% = 100, 20%+ = 0)
    $cancelScore = max(0, min(100, 100 - ($metrics['taxa_cancelamento'] / 20) * 100));

    // tempo_preparo: 20% weight (faster = higher; benchmark 30min = 50 score, 0min = 100, 60min+ = 0)
    $tempoScore = 100;
    if ($metrics['tempo_medio_preparo'] > 0) {
        $tempoScore = max(0, min(100, 100 - (($metrics['tempo_medio_preparo'] / 60) * 100)));
    }

    // taxa_atraso: 15% weight (inverse: 0% = 100, 30%+ = 0)
    $atrasoScore = max(0, min(100, 100 - ($metrics['taxa_atraso'] / 30) * 100));

    // volume: 10% weight (more orders = higher; cap: 100 orders/period = 100 score)
    $volumeScore = min(100, ($metrics['total_pedidos'] / 100) * 100);

    $score = round(
        ($notaScore * 0.30) +
        ($cancelScore * 0.25) +
        ($tempoScore * 0.20) +
        ($atrasoScore * 0.15) +
        ($volumeScore * 0.10)
    );

    $score = max(0, min(100, $score));

    // Determine badge
    if ($score >= 85) {
        $badge = 'Super Parceiro';
    } elseif ($score >= 70) {
        $badge = 'Bom';
    } elseif ($score >= 50) {
        $badge = 'Regular';
    } else {
        $badge = 'Precisa Melhorar';
    }

    return [
        'score' => $score,
        'ranking_badge' => $badge,
        'score_breakdown' => [
            'nota_media' => [
                'score' => round($notaScore, 1),
                'weight' => 30,
                'label' => 'Avaliacao dos Clientes',
            ],
            'taxa_cancelamento' => [
                'score' => round($cancelScore, 1),
                'weight' => 25,
                'label' => 'Taxa de Cancelamento',
            ],
            'tempo_preparo' => [
                'score' => round($tempoScore, 1),
                'weight' => 20,
                'label' => 'Tempo de Preparo',
            ],
            'taxa_atraso' => [
                'score' => round($atrasoScore, 1),
                'weight' => 15,
                'label' => 'Pontualidade',
            ],
            'volume' => [
                'score' => round($volumeScore, 1),
                'weight' => 10,
                'label' => 'Volume de Pedidos',
            ],
        ],
    ];
}

/**
 * Generate contextual tips based on lowest-scoring metrics
 */
function generateTips(array $scoreBreakdown, array $metrics): array {
    $tips = [];

    // Sort breakdown by score ascending
    $sorted = $scoreBreakdown;
    uasort($sorted, fn($a, $b) => $a['score'] <=> $b['score']);

    foreach ($sorted as $key => $item) {
        if ($item['score'] >= 80) continue; // No tip needed for good scores
        if (count($tips) >= 3) break; // Max 3 tips

        switch ($key) {
            case 'nota_media':
                if ($metrics['nota_media'] < 3) {
                    $tips[] = [
                        'metrica' => $key,
                        'titulo' => 'Melhore suas avaliacoes',
                        'texto' => 'Responda aos feedbacks dos clientes, melhore a apresentacao dos produtos e garanta que os itens correspondam a descricao. Considere incluir brindes ou bilhetes de agradecimento.',
                    ];
                } elseif ($metrics['nota_media'] < 4) {
                    $tips[] = [
                        'metrica' => $key,
                        'titulo' => 'Quase la nas avaliacoes!',
                        'texto' => 'Sua nota esta boa, mas pode melhorar. Foque na qualidade da embalagem e na consistencia dos pratos. Clientes adoram quando recebem exatamente o que esperavam.',
                    ];
                } else {
                    $tips[] = [
                        'metrica' => $key,
                        'titulo' => 'Continue o bom trabalho nas avaliacoes',
                        'texto' => 'Mantenha a qualidade e considere responder avaliacoes positivas para fidelizar clientes.',
                    ];
                }
                break;

            case 'taxa_cancelamento':
                $tips[] = [
                    'metrica' => $key,
                    'titulo' => 'Reduza os cancelamentos',
                    'texto' => 'Mantenha o cardapio atualizado, desative itens indisponiveis rapidamente e aceite pedidos apenas quando puder atende-los. Taxa atual: ' . $metrics['taxa_cancelamento'] . '%.',
                ];
                break;

            case 'tempo_preparo':
                $tips[] = [
                    'metrica' => $key,
                    'titulo' => 'Acelere o preparo dos pedidos',
                    'texto' => 'Organize a cozinha para os itens mais vendidos, pre-prepare ingredientes nos horarios de pico e considere ter mais funcionarios em horarios movimentados. Tempo medio atual: ' . round($metrics['tempo_medio_preparo']) . ' min.',
                ];
                break;

            case 'taxa_atraso':
                $tips[] = [
                    'metrica' => $key,
                    'titulo' => 'Melhore a pontualidade',
                    'texto' => 'Ajuste os tempos estimados de entrega para serem mais realistas. E melhor prometer mais tempo e entregar antes do que atrasar. Taxa de atraso atual: ' . $metrics['taxa_atraso'] . '%.',
                ];
                break;

            case 'volume':
                $tips[] = [
                    'metrica' => $key,
                    'titulo' => 'Aumente seu volume de pedidos',
                    'texto' => 'Crie promocoes atrativas, mantenha precos competitivos e adicione fotos de qualidade aos produtos. Considere participar de campanhas da plataforma.',
                ];
                break;
        }
    }

    return $tips;
}

/**
 * Handle main metrics request
 */
function handleMetrics(PDO $db, int $partner_id, string $startDate, string $endDate, string $prevStartDate, string $prevEndDate, int $days, string $periodo): void {
    $metrics = getBaseMetrics($db, $partner_id, $startDate, $endDate);

    // Calculate score
    $scoreData = calculateScore($metrics);

    // Generate tips
    $tips = generateTips($scoreData['score_breakdown'], $metrics);

    // Pedidos por dia
    $stmt = $db->prepare("
        SELECT
            DATE(date_added) as date,
            COUNT(*) as count
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
        GROUP BY DATE(date_added)
        ORDER BY DATE(date_added) ASC
    ");
    $stmt->execute([$partner_id, $startDate, $endDate]);
    $dailyOrders = $stmt->fetchAll();

    $dailyMap = [];
    foreach ($dailyOrders as $row) {
        $dailyMap[$row['date']] = (int)$row['count'];
    }

    $pedidosPorDia = [];
    $currentDate = $startDate;
    $today = date('Y-m-d');
    while ($currentDate <= $endDate && $currentDate <= $today) {
        $pedidosPorDia[] = [
            'date' => $currentDate,
            'count' => $dailyMap[$currentDate] ?? 0,
        ];
        $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
    }

    // Faturamento por dia
    $stmt = $db->prepare("
        SELECT
            DATE(date_added) as date,
            COALESCE(SUM(total), 0) as total
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status NOT IN ('cancelado', 'cancelled')
        GROUP BY DATE(date_added)
        ORDER BY DATE(date_added) ASC
    ");
    $stmt->execute([$partner_id, $startDate, $endDate]);
    $dailyRevenue = $stmt->fetchAll();

    $revenueMap = [];
    foreach ($dailyRevenue as $row) {
        $revenueMap[$row['date']] = round((float)$row['total'], 2);
    }

    $faturamentoPorDia = [];
    $currentDate = $startDate;
    while ($currentDate <= $endDate && $currentDate <= $today) {
        $faturamentoPorDia[] = [
            'date' => $currentDate,
            'total' => $revenueMap[$currentDate] ?? 0,
        ];
        $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
    }

    // Top 10 produtos
    $stmt = $db->prepare("
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
    $stmt->execute([$partner_id, $startDate, $endDate]);
    $topProducts = $stmt->fetchAll();

    $topProdutos = [];
    foreach ($topProducts as $tp) {
        $topProdutos[] = [
            'product_id' => (int)$tp['product_id'],
            'name' => $tp['name'],
            'quantity' => (int)$tp['total_quantity'],
            'revenue' => round((float)$tp['total_revenue'], 2),
            'orders' => (int)$tp['orders_count'],
        ];
    }

    // Horarios de pico (orders by hour)
    $stmt = $db->prepare("
        SELECT
            EXTRACT(HOUR FROM date_added)::int as hour,
            COUNT(*) as order_count
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status NOT IN ('cancelado', 'cancelled')
        GROUP BY EXTRACT(HOUR FROM date_added)::int
        ORDER BY EXTRACT(HOUR FROM date_added)::int
    ");
    $stmt->execute([$partner_id, $startDate, $endDate]);
    $peakHoursRaw = $stmt->fetchAll();

    $peakMap = [];
    foreach ($peakHoursRaw as $row) {
        $peakMap[(int)$row['hour']] = (int)$row['order_count'];
    }

    $horariosPico = [];
    for ($h = 0; $h < 24; $h++) {
        $horariosPico[] = [
            'hour' => $h,
            'label' => str_pad($h, 2, '0', STR_PAD_LEFT) . ':00',
            'order_count' => $peakMap[$h] ?? 0,
        ];
    }

    // Trends comparison
    $prevMetrics = getBaseMetrics($db, $partner_id, $prevStartDate, $prevEndDate);
    $trends = calculateTrends($metrics, $prevMetrics);

    response(true, [
        'periodo' => $periodo,
        'date_range' => [
            'start' => $startDate,
            'end' => $endDate,
        ],
        'total_pedidos' => $metrics['total_pedidos'],
        'faturamento' => $metrics['faturamento'],
        'taxa_cancelamento' => $metrics['taxa_cancelamento'],
        'tempo_medio_preparo' => $metrics['tempo_medio_preparo'],
        'nota_media' => $metrics['nota_media'],
        'total_avaliacoes' => $metrics['total_avaliacoes'],
        'distribuicao_notas' => $metrics['distribuicao_notas'],
        'taxa_atraso' => $metrics['taxa_atraso'],
        'ticket_medio' => $metrics['ticket_medio'],
        'pedidos_por_dia' => $pedidosPorDia,
        'faturamento_por_dia' => $faturamentoPorDia,
        'top_produtos' => $topProdutos,
        'horarios_pico' => $horariosPico,
        'score' => $scoreData['score'],
        'ranking_badge' => $scoreData['ranking_badge'],
        'score_breakdown' => $scoreData['score_breakdown'],
        'trends' => $trends,
        'tips' => $tips,
    ], "Metricas da loja");
}

/**
 * Calculate percentage changes between two periods
 */
function calculateTrends(array $current, array $prev): array {
    $calcChange = function ($cur, $old) {
        if ($old == 0) return $cur > 0 ? 100.0 : 0.0;
        return round((($cur - $old) / $old) * 100, 1);
    };

    return [
        'total_pedidos' => [
            'atual' => $current['total_pedidos'],
            'anterior' => $prev['total_pedidos'],
            'variacao' => $calcChange($current['total_pedidos'], $prev['total_pedidos']),
        ],
        'faturamento' => [
            'atual' => $current['faturamento'],
            'anterior' => $prev['faturamento'],
            'variacao' => $calcChange($current['faturamento'], $prev['faturamento']),
        ],
        'taxa_cancelamento' => [
            'atual' => $current['taxa_cancelamento'],
            'anterior' => $prev['taxa_cancelamento'],
            'variacao' => $calcChange($current['taxa_cancelamento'], $prev['taxa_cancelamento']),
        ],
        'tempo_medio_preparo' => [
            'atual' => $current['tempo_medio_preparo'],
            'anterior' => $prev['tempo_medio_preparo'],
            'variacao' => $calcChange($current['tempo_medio_preparo'], $prev['tempo_medio_preparo']),
        ],
        'nota_media' => [
            'atual' => $current['nota_media'],
            'anterior' => $prev['nota_media'],
            'variacao' => $calcChange($current['nota_media'], $prev['nota_media']),
        ],
        'ticket_medio' => [
            'atual' => $current['ticket_medio'],
            'anterior' => $prev['ticket_medio'],
            'variacao' => $calcChange($current['ticket_medio'], $prev['ticket_medio']),
        ],
        'taxa_atraso' => [
            'atual' => $current['taxa_atraso'],
            'anterior' => $prev['taxa_atraso'],
            'variacao' => $calcChange($current['taxa_atraso'], $prev['taxa_atraso']),
        ],
    ];
}

/**
 * Handle trends-only request
 */
function handleTrends(PDO $db, int $partner_id, string $startDate, string $endDate, string $prevStartDate, string $prevEndDate, string $periodo): void {
    $currentMetrics = getBaseMetrics($db, $partner_id, $startDate, $endDate);
    $prevMetrics = getBaseMetrics($db, $partner_id, $prevStartDate, $prevEndDate);

    $currentScore = calculateScore($currentMetrics);
    $prevScore = calculateScore($prevMetrics);

    $trends = calculateTrends($currentMetrics, $prevMetrics);

    $scoreVariacao = $prevScore['score'] > 0
        ? round((($currentScore['score'] - $prevScore['score']) / $prevScore['score']) * 100, 1)
        : ($currentScore['score'] > 0 ? 100.0 : 0.0);

    $trends['score'] = [
        'atual' => $currentScore['score'],
        'anterior' => $prevScore['score'],
        'variacao' => $scoreVariacao,
    ];

    response(true, [
        'periodo' => $periodo,
        'trends' => $trends,
    ], "Tendencias calculadas");
}
