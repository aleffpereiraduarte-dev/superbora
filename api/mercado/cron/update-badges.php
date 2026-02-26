<?php
/**
 * CRON: Atualização diária de badges dos parceiros
 * Executar 1x/dia às 03:00
 *
 * Crontab: 0 3 * * * php /var/www/html/api/mercado/cron/update-badges.php
 *
 * Este script:
 * 1. Calcula métricas de cada parceiro ativo
 * 2. Verifica elegibilidade para badges
 * 3. Adiciona novos badges
 * 4. Remove badges expirados ou que não atendem mais critérios
 * 5. Notifica parceiros sobre novos badges
 */

// ── ACCESS CONTROL: CLI or authenticated cron header only ────
$secret = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: '';
if (empty($secret)) { http_response_code(503); echo json_encode(['error' => 'Cron secret not configured']); exit; }
if (php_sapi_name() !== 'cli' && (!isset($_SERVER['HTTP_X_CRON_KEY']) || !hash_equals($secret, $_SERVER['HTTP_X_CRON_KEY']))) {
    http_response_code(403);
    die('Acesso negado');
}

require_once __DIR__ . "/../config/database.php";

echo "=== Iniciando atualizacao de badges: " . date('Y-m-d H:i:s') . " ===\n";

try {
    $db = getDB();

    // Check required tables exist
    $requiredTables = ['om_partner_badges', 'om_partner_score_history'];
    foreach ($requiredTables as $table) {
        $exists = $db->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = '{$table}')")->fetchColumn();
        if (!$exists) {
            echo "SKIP: Tabela '{$table}' nao existe. Crie as tabelas antes de executar.\n";
            exit(0);
        }
    }

    // Período de análise
    $endDate = date('Y-m-d');
    $startDate30 = date('Y-m-d', strtotime('-30 days'));
    $startDate14 = date('Y-m-d', strtotime('-14 days'));

    // Buscar todos os parceiros ativos
    $stmtPartners = $db->query("
        SELECT partner_id AS id, name AS nome, email
        FROM om_market_partners
        WHERE status::text = '1'
    ");
    $partners = $stmtPartners->fetchAll();

    echo "Processando " . count($partners) . " parceiros...\n\n";

    $badgesAdded = 0;
    $badgesRemoved = 0;
    $notifications = [];

    foreach ($partners as $partner) {
        $partnerId = $partner['id'];
        $partnerName = $partner['nome'] ?? 'Parceiro #' . $partnerId;

        echo "Processando: {$partnerName} (ID: {$partnerId})\n";

        // ========== CALCULAR MÉTRICAS ==========

        // 1. Score médio nos últimos 30 dias
        $stmtScore = $db->prepare("
            SELECT AVG(score) as avg_score
            FROM om_partner_score_history
            WHERE partner_id = ?
              AND DATE(recorded_at) BETWEEN ? AND ?
        ");
        $stmtScore->execute([$partnerId, $startDate30, $endDate]);
        $avgScore = (float)($stmtScore->fetchColumn() ?: 0);

        // Se não tem histórico, calcular score atual
        if ($avgScore == 0) {
            $metrics = calculatePartnerMetrics($db, $partnerId, $startDate30, $endDate);
            $avgScore = $metrics['score'];
        }

        // 2. Tempo médio de entrega nos últimos 14 dias
        $stmtDelivery = $db->prepare("
            SELECT AVG(delivery_time_minutes) as avg_minutes
            FROM om_market_orders
            WHERE partner_id = ?
              AND DATE(date_added) BETWEEN ? AND ?
              AND delivery_time_minutes IS NOT NULL
        ");
        $stmtDelivery->execute([$partnerId, $startDate14, $endDate]);
        $avgDeliveryTime = (float)($stmtDelivery->fetchColumn() ?: 0);

        // 3. Rating e contagem
        $stmtRating = $db->prepare("
            SELECT AVG(rating) as avg_rating, COUNT(*) as total
            FROM om_market_reviews
            WHERE partner_id = ?
        ");
        $stmtRating->execute([$partnerId]);
        $ratingData = $stmtRating->fetch();
        $avgRating = (float)($ratingData['avg_rating'] ?? 0);
        $totalReviews = (int)($ratingData['total'] ?? 0);

        // 4. Crescimento de pedidos (mês atual vs anterior)
        $stmtGrowth = $db->prepare("
            SELECT
                (SELECT COUNT(*) FROM om_market_orders WHERE partner_id = ? AND DATE(date_added) BETWEEN (?::date - INTERVAL '30 days') AND ?::date) as current_month,
                (SELECT COUNT(*) FROM om_market_orders WHERE partner_id = ? AND DATE(date_added) BETWEEN (?::date - INTERVAL '60 days') AND (?::date - INTERVAL '31 days')) as previous_month
        ");
        $stmtGrowth->execute([$partnerId, $endDate, $endDate, $partnerId, $endDate, $endDate]);
        $growthData = $stmtGrowth->fetch();
        $currentMonth = (int)($growthData['current_month'] ?? 0);
        $previousMonth = (int)($growthData['previous_month'] ?? 0);
        $growthRate = $previousMonth > 0 ? (($currentMonth - $previousMonth) / $previousMonth) : 0;

        // 5. Taxa eco-friendly
        $stmtEco = $db->prepare("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN eco_friendly = 1 THEN 1 ELSE 0 END) as eco_count
            FROM om_market_orders
            WHERE partner_id = ?
              AND DATE(date_added) BETWEEN ? AND ?
        ");
        try {
            $stmtEco->execute([$partnerId, $startDate30, $endDate]);
            $ecoData = $stmtEco->fetch();
            $ecoTotal = (int)($ecoData['total'] ?? 0);
            $ecoCount = (int)($ecoData['eco_count'] ?? 0);
            $ecoRate = $ecoTotal > 0 ? ($ecoCount / $ecoTotal) : 0;
        } catch (PDOException $e) {
            $ecoRate = 0;
        }

        // ========== VERIFICAR ELEGIBILIDADE ==========

        $eligibleBadges = [];

        // super_restaurant: score > 85 por 30 dias
        if ($avgScore > 85) {
            $eligibleBadges['super_restaurant'] = [
                'expires_at' => null // Permanente enquanto manter
            ];
        }

        // fast_delivery: tempo médio < 30min por 14 dias
        if ($avgDeliveryTime > 0 && $avgDeliveryTime < 30) {
            $eligibleBadges['fast_delivery'] = [
                'expires_at' => date('Y-m-d', strtotime('+14 days'))
            ];
        }

        // top_rated: rating > 4.5 com >50 avaliações
        if ($avgRating > 4.5 && $totalReviews >= 50) {
            $eligibleBadges['top_rated'] = [
                'expires_at' => null
            ];
        }

        // new_favorite: crescimento >20% no mês
        if ($growthRate > 0.20) {
            $eligibleBadges['new_favorite'] = [
                'expires_at' => date('Y-m-d', strtotime('+30 days'))
            ];
        }

        // eco_friendly: >80% pedidos sem talheres
        if ($ecoRate > 0.80) {
            $eligibleBadges['eco_friendly'] = [
                'expires_at' => date('Y-m-d', strtotime('+30 days'))
            ];
        }

        // ========== ATUALIZAR BADGES ==========

        // Buscar badges atuais
        $stmtCurrentBadges = $db->prepare("
            SELECT badge_type, expires_at
            FROM om_partner_badges
            WHERE partner_id = ?
        ");
        $stmtCurrentBadges->execute([$partnerId]);
        $currentBadges = [];
        while ($row = $stmtCurrentBadges->fetch()) {
            $currentBadges[$row['badge_type']] = $row['expires_at'];
        }

        // Adicionar novos badges
        foreach ($eligibleBadges as $badgeType => $badgeData) {
            if (!isset($currentBadges[$badgeType])) {
                // Badge novo
                $stmtInsert = $db->prepare("
                    INSERT INTO om_partner_badges (partner_id, badge_type, expires_at, notified)
                    VALUES (?, ?, ?, 0)
                ");
                $stmtInsert->execute([$partnerId, $badgeType, $badgeData['expires_at']]);
                $badgesAdded++;

                // Agendar notificação
                $notifications[] = [
                    'partner_id' => $partnerId,
                    'partner_name' => $partnerName,
                    'email' => $partner['email'],
                    'badge_type' => $badgeType
                ];

                echo "  + Adicionado badge: {$badgeType}\n";
            } else {
                // Atualizar expiração se necessário
                if ($badgeData['expires_at'] !== $currentBadges[$badgeType]) {
                    $stmtUpdate = $db->prepare("
                        UPDATE om_partner_badges
                        SET expires_at = ?
                        WHERE partner_id = ? AND badge_type = ?
                    ");
                    $stmtUpdate->execute([$badgeData['expires_at'], $partnerId, $badgeType]);
                }
            }
        }

        // Remover badges que não qualificam mais (exceto se ainda não expiraram)
        foreach ($currentBadges as $badgeType => $expiresAt) {
            if (!isset($eligibleBadges[$badgeType])) {
                // Verificar se expirou
                if ($expiresAt !== null && strtotime($expiresAt) <= time()) {
                    $stmtDelete = $db->prepare("
                        DELETE FROM om_partner_badges
                        WHERE partner_id = ? AND badge_type = ?
                    ");
                    $stmtDelete->execute([$partnerId, $badgeType]);
                    $badgesRemoved++;
                    echo "  - Removido badge expirado: {$badgeType}\n";
                }
            }
        }

        echo "  Score medio: {$avgScore}, Rating: {$avgRating}, Delivery: {$avgDeliveryTime}min\n\n";
    }

    // ========== ENVIAR NOTIFICAÇÕES ==========

    if (count($notifications) > 0) {
        echo "\n=== Enviando notificacoes ===\n";

        $badgeNames = [
            'super_restaurant' => 'Super Restaurante',
            'fast_delivery' => 'Entrega Relampago',
            'top_rated' => 'Mais Bem Avaliado',
            'new_favorite' => 'Novo Favorito',
            'eco_friendly' => 'Eco-Friendly'
        ];

        foreach ($notifications as $notif) {
            $badgeName = $badgeNames[$notif['badge_type']] ?? $notif['badge_type'];

            // Marcar como notificado
            $stmtNotified = $db->prepare("
                UPDATE om_partner_badges
                SET notified = 1
                WHERE partner_id = ? AND badge_type = ?
            ");
            $stmtNotified->execute([$notif['partner_id'], $notif['badge_type']]);

            // Criar notificação no sistema
            try {
                $stmtNotification = $db->prepare("
                    INSERT INTO om_notifications (user_type, user_id, title, body, type, created_at)
                    VALUES ('partner', ?, ?, ?, 'badge', NOW())
                ");
                $stmtNotification->execute([
                    $notif['partner_id'],
                    "Parabens! Voce ganhou um selo!",
                    "Seu estabelecimento conquistou o selo '{$badgeName}'. Continue assim!"
                ]);
            } catch (PDOException $e) {
                // Tabela de notificações pode não existir
            }

            echo "Notificacao enviada para {$notif['partner_name']}: {$badgeName}\n";
        }
    }

    // ========== RESUMO ==========

    echo "\n=== RESUMO ===\n";
    echo "Parceiros processados: " . count($partners) . "\n";
    echo "Badges adicionados: {$badgesAdded}\n";
    echo "Badges removidos: {$badgesRemoved}\n";
    echo "Notificacoes enviadas: " . count($notifications) . "\n";
    echo "Concluido em: " . date('Y-m-d H:i:s') . "\n";

} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    error_log("[cron/update-badges] Erro: " . $e->getMessage());
    exit(1);
}

/**
 * Calcula métricas do parceiro
 */
function calculatePartnerMetrics(PDO $db, int $partnerId, string $startDate, string $endDate): array {
    // Total de pedidos
    $stmtTotal = $db->prepare("
        SELECT COUNT(*) as total
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
    ");
    $stmtTotal->execute([$partnerId, $startDate, $endDate]);
    $totalOrders = (int)$stmtTotal->fetchColumn();

    // Pedidos aceitos
    $stmtAccepted = $db->prepare("
        SELECT COUNT(*) as total
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status NOT IN ('pendente', 'cancelado', 'cancelled')
    ");
    $stmtAccepted->execute([$partnerId, $startDate, $endDate]);
    $acceptedOrders = (int)$stmtAccepted->fetchColumn();

    // Cancelados
    $stmtCancelled = $db->prepare("
        SELECT COUNT(*) as total
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status IN ('cancelado', 'cancelled')
    ");
    $stmtCancelled->execute([$partnerId, $startDate, $endDate]);
    $cancelledOrders = (int)$stmtCancelled->fetchColumn();

    // Tempo preparo
    $stmtPrepTime = $db->prepare("
        SELECT AVG(EXTRACT(EPOCH FROM (e.created_at - o.accepted_at)) / 60) as avg_minutes
        FROM om_market_orders o
        INNER JOIN om_market_order_events e ON e.order_id = o.order_id AND e.event_type = 'partner_pronto'
        WHERE o.partner_id = ?
          AND DATE(o.date_added) BETWEEN ? AND ?
          AND o.accepted_at IS NOT NULL
    ");
    $stmtPrepTime->execute([$partnerId, $startDate, $endDate]);
    $prepTimeAvg = (float)($stmtPrepTime->fetchColumn() ?: 20);

    // Rating
    $stmtRating = $db->prepare("
        SELECT AVG(rating) as avg_rating
        FROM om_market_reviews
        WHERE partner_id = ?
    ");
    $stmtRating->execute([$partnerId]);
    $ratingAvg = (float)($stmtRating->fetchColumn() ?: 4.0);

    // Calcular taxas
    $acceptanceRate = $totalOrders > 0 ? ($acceptedOrders / $totalOrders) * 100 : 95;
    $cancellationRate = $totalOrders > 0 ? ($cancelledOrders / $totalOrders) * 100 : 0;

    // Calcular score
    $scoreAcceptance = $acceptanceRate >= 95 ? 100 : ($acceptanceRate / 95) * 100;
    $scorePrepTime = 100;
    if ($prepTimeAvg >= 40) $scorePrepTime = 0;
    elseif ($prepTimeAvg > 15) $scorePrepTime = 100 - (($prepTimeAvg - 15) / 25) * 100;
    $scoreRating = max(0, min(100, ($ratingAvg - 3) * 50));
    $scoreCancellation = 100;
    if ($cancellationRate >= 10) $scoreCancellation = 0;
    elseif ($cancellationRate > 2) $scoreCancellation = 100 - (($cancellationRate - 2) / 8) * 100;

    $score = round(
        ($scoreAcceptance * 0.25) +
        ($scorePrepTime * 0.20) +
        ($scoreRating * 0.25) +
        ($scoreCancellation * 0.15) +
        (100 * 0.15) // Sem reclamações assumido
    );

    return [
        'score' => $score,
        'acceptance_rate' => $acceptanceRate,
        'prep_time_avg' => $prepTimeAvg,
        'rating_avg' => $ratingAvg,
        'cancellation_rate' => $cancellationRate
    ];
}
