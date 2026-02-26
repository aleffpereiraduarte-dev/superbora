<?php
/**
 * GET /api/mercado/partner/quality-score.php
 * Retorna o score de qualidade do parceiro com métricas detalhadas,
 * badges, dicas de melhoria e comparativo regional.
 *
 * Response:
 * {
 *   "score": 85,
 *   "grade": "A",
 *   "metrics": { ... },
 *   "badges": ["super_restaurant", "fast_delivery"],
 *   "tips": ["Dica 1", "Dica 2"],
 *   "comparison": { ... }
 * }
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

    $partnerId = (int)$payload['uid'];

    // Período de análise: últimos 30 dias
    $endDate = date('Y-m-d');
    $startDate = date('Y-m-d', strtotime('-30 days'));

    // Obter categoria do parceiro para comparação
    $stmtPartner = $db->prepare("SELECT categoria, cidade FROM om_market_partners WHERE partner_id = ?");
    $stmtPartner->execute([$partnerId]);
    $partnerData = $stmtPartner->fetch();
    $categoriaId = $partnerData['categoria'] ?? null;
    $cidade = $partnerData['cidade'] ?? null;

    // ========== MÉTRICAS ==========

    // 1. Total de pedidos
    $stmtTotal = $db->prepare("
        SELECT COUNT(*) as total
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
    ");
    $stmtTotal->execute([$partnerId, $startDate, $endDate]);
    $totalOrders = (int)$stmtTotal->fetchColumn();

    // 2. Pedidos aceitos (não cancelados/pendentes)
    $stmtAccepted = $db->prepare("
        SELECT COUNT(*) as total
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status NOT IN ('pendente', 'cancelado', 'cancelled')
    ");
    $stmtAccepted->execute([$partnerId, $startDate, $endDate]);
    $acceptedOrders = (int)$stmtAccepted->fetchColumn();

    // 3. Pedidos cancelados
    $stmtCancelled = $db->prepare("
        SELECT COUNT(*) as total
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status IN ('cancelado', 'cancelled')
    ");
    $stmtCancelled->execute([$partnerId, $startDate, $endDate]);
    $cancelledOrders = (int)$stmtCancelled->fetchColumn();

    // Taxa de aceitação e cancelamento
    $acceptanceRate = $totalOrders > 0 ? round(($acceptedOrders / $totalOrders) * 100, 1) : 95;
    $cancellationRate = $totalOrders > 0 ? round(($cancelledOrders / $totalOrders) * 100, 1) : 0;

    // 4. Tempo médio de preparo (minutos)
    $stmtPrepTime = $db->prepare("
        SELECT AVG(EXTRACT(EPOCH FROM (e.created_at - o.accepted_at)) / 60) as avg_minutes
        FROM om_market_orders o
        INNER JOIN om_market_order_events e ON e.order_id = o.order_id AND e.event_type = 'partner_pronto'
        WHERE o.partner_id = ?
          AND DATE(o.date_added) BETWEEN ? AND ?
          AND o.accepted_at IS NOT NULL
    ");
    $stmtPrepTime->execute([$partnerId, $startDate, $endDate]);
    $prepTimeAvg = round((float)($stmtPrepTime->fetchColumn() ?: 18)); // Default 18 min

    // Target de preparo (configurável por categoria, default 25 min)
    $prepTimeTarget = 25;

    // 5. Tempo médio de entrega (total: aceito até entregue)
    $stmtDeliveryTime = $db->prepare("
        SELECT AVG(delivery_time_minutes) as avg_minutes
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND delivery_time_minutes IS NOT NULL
    ");
    $stmtDeliveryTime->execute([$partnerId, $startDate, $endDate]);
    $deliveryTimeAvg = round((float)($stmtDeliveryTime->fetchColumn() ?: 35));

    // 6. Avaliação média e contagem
    $stmtRating = $db->prepare("
        SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews
        FROM om_market_reviews
        WHERE partner_id = ?
    ");
    $stmtRating->execute([$partnerId]);
    $ratingData = $stmtRating->fetch();
    $ratingAvg = round((float)($ratingData['avg_rating'] ?? 4.5), 1);
    $ratingCount = (int)($ratingData['total_reviews'] ?? 0);

    // 7. Taxa de reclamação
    $stmtComplaints = $db->prepare("
        SELECT COUNT(*) as total
        FROM om_order_complaints
        WHERE partner_id = ?
          AND DATE(created_at) BETWEEN ? AND ?
    ");
    try {
        $stmtComplaints->execute([$partnerId, $startDate, $endDate]);
        $totalComplaints = (int)$stmtComplaints->fetchColumn();
    } catch (PDOException $e) {
        // Tabela pode não existir ainda
        $totalComplaints = 0;
    }
    $complaintRate = $totalOrders > 0 ? round(($totalComplaints / $totalOrders) * 100, 1) : 0;

    // ========== CÁLCULO DO SCORE ==========

    // Taxa de Aceitação (25%): 100 se >95%, proporcional abaixo
    $scoreAcceptance = $acceptanceRate >= 95 ? 100 : ($acceptanceRate / 95) * 100;

    // Tempo de Preparo (20%): 100 se <15min, 0 se >40min, linear entre
    $scorePrepTime = 100;
    if ($prepTimeAvg >= 40) {
        $scorePrepTime = 0;
    } elseif ($prepTimeAvg > 15) {
        $scorePrepTime = 100 - (($prepTimeAvg - 15) / 25) * 100;
    }

    // Avaliação Média (25%): (rating - 3) * 50, min 0, max 100
    $scoreRating = max(0, min(100, ($ratingAvg - 3) * 50));

    // Taxa Cancelamento (15%): 100 se <2%, 0 se >10%, linear
    $scoreCancellation = 100;
    if ($cancellationRate >= 10) {
        $scoreCancellation = 0;
    } elseif ($cancellationRate > 2) {
        $scoreCancellation = 100 - (($cancellationRate - 2) / 8) * 100;
    }

    // Taxa Reclamação (15%): 100 se <1%, 0 se >5%, linear
    $scoreComplaint = 100;
    if ($complaintRate >= 5) {
        $scoreComplaint = 0;
    } elseif ($complaintRate > 1) {
        $scoreComplaint = 100 - (($complaintRate - 1) / 4) * 100;
    }

    // Score final (0-100)
    $score = round(
        ($scoreAcceptance * 0.25) +
        ($scorePrepTime * 0.20) +
        ($scoreRating * 0.25) +
        ($scoreCancellation * 0.15) +
        ($scoreComplaint * 0.15)
    );

    // Grade: A (85+), B (70-84), C (55-69), D (40-54), F (<40)
    if ($score >= 85) $grade = 'A';
    elseif ($score >= 70) $grade = 'B';
    elseif ($score >= 55) $grade = 'C';
    elseif ($score >= 40) $grade = 'D';
    else $grade = 'F';

    // ========== BADGES ==========

    $badges = [];

    // Buscar badges existentes
    $stmtBadges = $db->prepare("
        SELECT badge_type
        FROM om_partner_badges
        WHERE partner_id = ?
          AND (expires_at IS NULL OR expires_at >= CURRENT_DATE)
    ");
    try {
        $stmtBadges->execute([$partnerId]);
        while ($row = $stmtBadges->fetch()) {
            $badges[] = $row['badge_type'];
        }
    } catch (PDOException $e) {
        // Tabela pode não existir ainda
    }

    // Verificar elegibilidade para badges (mesmo sem tabela)
    $eligibleBadges = [];

    // super_restaurant: score > 85
    if ($score > 85) $eligibleBadges[] = 'super_restaurant';

    // fast_delivery: tempo médio < 30min
    if ($deliveryTimeAvg < 30) $eligibleBadges[] = 'fast_delivery';

    // top_rated: rating > 4.5 com >50 avaliações
    if ($ratingAvg > 4.5 && $ratingCount >= 50) $eligibleBadges[] = 'top_rated';

    // Verificar crescimento para new_favorite
    $stmtGrowth = $db->prepare("
        SELECT
            (SELECT COUNT(*) FROM om_market_orders WHERE partner_id = ? AND DATE(date_added) BETWEEN (?::date - INTERVAL '30 days') AND ?::date) as current_month,
            (SELECT COUNT(*) FROM om_market_orders WHERE partner_id = ? AND DATE(date_added) BETWEEN (?::date - INTERVAL '60 days') AND (?::date - INTERVAL '31 days')) as previous_month
    ");
    $stmtGrowth->execute([$partnerId, $endDate, $endDate, $partnerId, $endDate, $endDate]);
    $growthData = $stmtGrowth->fetch();
    $currentMonth = (int)($growthData['current_month'] ?? 0);
    $previousMonth = (int)($growthData['previous_month'] ?? 0);

    if ($previousMonth > 0 && (($currentMonth - $previousMonth) / $previousMonth) > 0.20) {
        $eligibleBadges[] = 'new_favorite';
    }

    // eco_friendly: verificar se temos dados
    $stmtEco = $db->prepare("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN eco_friendly = 1 THEN 1 ELSE 0 END) as eco_count
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
    ");
    try {
        $stmtEco->execute([$partnerId, $startDate, $endDate]);
        $ecoData = $stmtEco->fetch();
        $ecoTotal = (int)($ecoData['total'] ?? 0);
        $ecoCount = (int)($ecoData['eco_count'] ?? 0);
        if ($ecoTotal > 0 && ($ecoCount / $ecoTotal) > 0.80) {
            $eligibleBadges[] = 'eco_friendly';
        }
    } catch (PDOException $e) {
        // Coluna pode não existir
    }

    // Mesclar badges existentes com elegíveis
    $badges = array_unique(array_merge($badges, $eligibleBadges));

    // ========== DICAS DE MELHORIA ==========

    $tips = [];

    if ($score < 80) {
        if ($acceptanceRate < 95) {
            $tips[] = "Aceite mais pedidos para melhorar sua taxa de aceitacao (atual: {$acceptanceRate}%)";
        }
        if ($prepTimeAvg > $prepTimeTarget) {
            $diff = $prepTimeAvg - $prepTimeTarget;
            $tips[] = "Reduza o tempo de preparo em {$diff} minutos para ganhar o selo Relampago";
        }
        if ($ratingAvg < 4.5) {
            $tips[] = "Responda mais avaliacoes para melhorar o engajamento e sua nota";
        }
        if ($cancellationRate > 5) {
            $tips[] = "Reduza cancelamentos mantendo o cardapio atualizado e estoque em dia";
        }
        if ($complaintRate > 2) {
            $tips[] = "Foque na qualidade para reduzir reclamacoes de clientes";
        }
        if ($deliveryTimeAvg > 40) {
            $tips[] = "Prepare os pedidos mais rapido para melhorar o tempo de entrega";
        }
    }

    // Limitar a 3 dicas
    $tips = array_slice($tips, 0, 3);

    // ========== COMPARATIVO REGIONAL ==========

    // Média da categoria na região
    $stmtCategoryAvg = $db->prepare("
        SELECT AVG(r.rating) as avg_rating
        FROM om_market_reviews r
        INNER JOIN om_market_partners p ON p.partner_id = r.partner_id
        WHERE p.categoria = ?
          AND p.cidade = ?
    ");
    $stmtCategoryAvg->execute([$categoriaId, $cidade]);
    $categoryAvgRating = (float)($stmtCategoryAvg->fetchColumn() ?: 4.0);

    // Diferença percentual
    $vsCategoryAvg = $categoryAvgRating > 0
        ? round((($ratingAvg - $categoryAvgRating) / $categoryAvgRating) * 100)
        : 0;
    $vsCategoryAvgStr = ($vsCategoryAvg >= 0 ? '+' : '') . $vsCategoryAvg . '%';

    // Ranking na região (baseado em rating)
    $stmtRank = $db->prepare("
        SELECT COUNT(*) + 1 as rank_position
        FROM om_market_partners p
        LEFT JOIN (
            SELECT partner_id, AVG(rating) as avg_rating
            FROM om_market_reviews
            GROUP BY partner_id
        ) r ON r.partner_id = p.partner_id
        WHERE p.categoria = ?
          AND p.cidade = ?
          AND p.partner_id != ?
          AND COALESCE(r.avg_rating, 0) > ?
    ");
    $stmtRank->execute([$categoriaId, $cidade, $partnerId, $ratingAvg]);
    $rankInRegion = (int)$stmtRank->fetchColumn();

    // Total na região
    $stmtTotalRegion = $db->prepare("
        SELECT COUNT(*)
        FROM om_market_partners
        WHERE categoria = ? AND cidade = ?
    ");
    $stmtTotalRegion->execute([$categoriaId, $cidade]);
    $totalInRegion = (int)$stmtTotalRegion->fetchColumn();

    // Percentual no top
    $topPercentage = $totalInRegion > 0
        ? round(($rankInRegion / $totalInRegion) * 100)
        : 0;

    // ========== SALVAR HISTÓRICO ==========

    try {
        $stmtHistory = $db->prepare("
            INSERT INTO om_partner_score_history
            (partner_id, score, grade, acceptance_rate, prep_time_avg, delivery_time_avg, rating_avg, cancellation_rate, complaint_rate)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (partner_id, DATE(recorded_at)) DO UPDATE SET
                score = EXCLUDED.score,
                grade = EXCLUDED.grade,
                recorded_at = NOW()
        ");
        $stmtHistory->execute([
            $partnerId, $score, $grade, $acceptanceRate, $prepTimeAvg,
            $deliveryTimeAvg, $ratingAvg, $cancellationRate, $complaintRate
        ]);
    } catch (PDOException $e) {
        // Tabela pode não existir
    }

    // ========== RESPOSTA ==========

    response(true, [
        'score' => $score,
        'grade' => $grade,
        'metrics' => [
            'acceptance_rate' => $acceptanceRate,
            'prep_time_avg' => $prepTimeAvg,
            'prep_time_target' => $prepTimeTarget,
            'delivery_time_avg' => $deliveryTimeAvg,
            'rating_avg' => $ratingAvg,
            'rating_count' => $ratingCount,
            'cancellation_rate' => $cancellationRate,
            'complaint_rate' => $complaintRate,
            'total_orders' => $totalOrders
        ],
        'badges' => array_values($badges),
        'tips' => $tips,
        'comparison' => [
            'vs_category_avg' => $vsCategoryAvgStr,
            'rank_in_region' => $rankInRegion,
            'total_in_region' => max(1, $totalInRegion),
            'top_percentage' => $topPercentage
        ]
    ]);

} catch (Exception $e) {
    error_log("[partner/quality-score] Erro: " . $e->getMessage());
    response(false, null, "Erro ao calcular score de qualidade", 500);
}
