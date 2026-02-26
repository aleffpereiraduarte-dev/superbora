<?php
/**
 * CRON: Partner Health Monitor
 * Run every 6 hours: 0 0,6,12,18 * * * php /var/www/html/mercado/cron/cron_partner_health.php
 *
 * Monitors partner performance over last 30 days:
 * 1. Cancellation rate (partner-cancelled orders / total)
 * 2. Complaint rate (disputes / total orders)
 * 3. Average rating (from reviews if table exists)
 * 4. Order volume
 *
 * Auto-actions:
 * - Cancellation rate >40% (min 5 orders) → auto-pause partner
 * - Complaint rate >30% (min 5 orders) → auto-pause + notify admin
 * - Average rating <2.0 (min 10 reviews) → flag for review
 */

$isCli = php_sapi_name() === 'cli';

function cron_log($msg) {
    global $isCli;
    $line = "[" . date('Y-m-d H:i:s') . "] [partner-health] {$msg}";
    if ($isCli) echo $line . "\n";
    error_log($line);
}

try {
    $db = new PDO(
        "pgsql:host=147.93.12.236;port=5432;dbname=love1",
        'love1', 'Aleff2009@',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // Ensure om_partner_health_scores table exists
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_partner_health_scores (
            score_id SERIAL PRIMARY KEY,
            partner_id INT NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            total_orders INT DEFAULT 0,
            cancelled_orders INT DEFAULT 0,
            cancellation_rate DECIMAL(5,2) DEFAULT 0,
            complaint_count INT DEFAULT 0,
            complaint_rate DECIMAL(5,2) DEFAULT 0,
            avg_rating DECIMAL(3,2) DEFAULT 0,
            total_reviews INT DEFAULT 0,
            auto_action VARCHAR(30),
            created_at TIMESTAMP DEFAULT NOW()
        )
    ");

    $stats = [
        'partners_checked' => 0,
        'scores_created' => 0,
        'auto_paused_cancellation' => 0,
        'auto_paused_complaints' => 0,
        'flagged_low_rating' => 0,
        'notifications_sent' => 0,
        'errors' => 0,
    ];

    // Check if om_order_disputes table exists
    $disputesTableExists = (bool)$db->query("
        SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_name = 'om_order_disputes')
    ")->fetchColumn();
    cron_log("Tabela om_order_disputes: " . ($disputesTableExists ? "existe" : "nao existe"));

    // Check if om_market_reviews table exists
    $reviewsTableExists = (bool)$db->query("
        SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_name = 'om_market_reviews')
    ")->fetchColumn();
    cron_log("Tabela om_market_reviews: " . ($reviewsTableExists ? "existe" : "nao existe"));

    // Period: last 30 days
    $periodEnd = date('Y-m-d');
    $periodStart = date('Y-m-d', strtotime('-30 days'));

    // ============================================================
    // FETCH ALL ACTIVE PARTNERS
    // ============================================================
    cron_log("--- Fetching active partners ---");

    $stmtPartners = $db->query("
        SELECT partner_id, name, is_open
        FROM om_market_partners
        WHERE status = '1'
        ORDER BY partner_id ASC
    ");
    $partners = $stmtPartners->fetchAll();
    $stats['partners_checked'] = count($partners);
    cron_log("Parceiros ativos encontrados: " . count($partners));

    foreach ($partners as $partner) {
        try {
            $partnerId = (int)$partner['partner_id'];
            $partnerName = $partner['name'] ?? "Parceiro #{$partnerId}";

            // --- Total orders in period ---
            $stmtTotal = $db->prepare("
                SELECT COUNT(*) AS total
                FROM om_market_orders
                WHERE partner_id = ?
                  AND created_at >= ?::date
                  AND created_at < (?::date + INTERVAL '1 day')
            ");
            $stmtTotal->execute([$partnerId, $periodStart, $periodEnd]);
            $totalOrders = (int)$stmtTotal->fetch()['total'];

            // --- Cancelled orders by partner ---
            $stmtCancelled = $db->prepare("
                SELECT COUNT(*) AS cancelled
                FROM om_market_orders
                WHERE partner_id = ?
                  AND status IN ('cancelado', 'cancelled')
                  AND created_at >= ?::date
                  AND created_at < (?::date + INTERVAL '1 day')
            ");
            $stmtCancelled->execute([$partnerId, $periodStart, $periodEnd]);
            $cancelledOrders = (int)$stmtCancelled->fetch()['cancelled'];

            $cancellationRate = $totalOrders > 0 ? round(($cancelledOrders / $totalOrders) * 100, 2) : 0;

            // --- Complaint count (disputes) ---
            $complaintCount = 0;
            if ($disputesTableExists) {
                $stmtDisputes = $db->prepare("
                    SELECT COUNT(*) AS disputes
                    FROM om_order_disputes
                    WHERE partner_id = ?
                      AND created_at >= ?::date
                      AND created_at < (?::date + INTERVAL '1 day')
                ");
                $stmtDisputes->execute([$partnerId, $periodStart, $periodEnd]);
                $complaintCount = (int)$stmtDisputes->fetch()['disputes'];
            }
            $complaintRate = $totalOrders > 0 ? round(($complaintCount / $totalOrders) * 100, 2) : 0;

            // --- Average rating ---
            $avgRating = 0;
            $totalReviews = 0;
            if ($reviewsTableExists) {
                $stmtRating = $db->prepare("
                    SELECT COALESCE(AVG(rating), 0) AS avg_rating,
                           COUNT(*) AS total_reviews
                    FROM om_market_reviews
                    WHERE partner_id = ?
                      AND created_at >= ?::date
                      AND created_at < (?::date + INTERVAL '1 day')
                ");
                $stmtRating->execute([$partnerId, $periodStart, $periodEnd]);
                $ratingRow = $stmtRating->fetch();
                $avgRating = round((float)$ratingRow['avg_rating'], 2);
                $totalReviews = (int)$ratingRow['total_reviews'];
            }

            // --- Determine auto-action ---
            $autoAction = null;

            // Check cancellation rate threshold
            if ($cancellationRate > 40 && $totalOrders > 5) {
                $autoAction = 'paused_cancellation';
            }

            // Check complaint rate threshold (overrides cancellation if both)
            if ($complaintRate > 30 && $totalOrders > 5) {
                $autoAction = 'paused_complaints';
            }

            // Check low rating (only flag, does not pause)
            if ($avgRating > 0 && $avgRating < 2.0 && $totalReviews > 10) {
                if (!$autoAction) {
                    $autoAction = 'flagged_low_rating';
                }
            }

            // --- Insert health score record ---
            $db->prepare("
                INSERT INTO om_partner_health_scores
                    (partner_id, period_start, period_end, total_orders, cancelled_orders,
                     cancellation_rate, complaint_count, complaint_rate, avg_rating,
                     total_reviews, auto_action, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $partnerId, $periodStart, $periodEnd, $totalOrders, $cancelledOrders,
                $cancellationRate, $complaintCount, $complaintRate, $avgRating,
                $totalReviews, $autoAction
            ]);
            $stats['scores_created']++;

            // --- Execute auto-actions ---
            if ($autoAction === 'paused_cancellation') {
                // Auto-pause partner
                $db->prepare("UPDATE om_market_partners SET is_open = 0 WHERE partner_id = ?")->execute([$partnerId]);

                // Notify partner
                $partnerBody = "Sua loja foi pausada devido a alta taxa de cancelamento (" . number_format($cancellationRate, 1, '.', '') . "%) nos ultimos 30 dias ({$cancelledOrders} de {$totalOrders} pedidos). Resolva os problemas e reative pelo painel.";
                $db->prepare("
                    INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                    VALUES (?, 'partner', 'Loja pausada automaticamente', ?, 'partner_auto_paused', ?::jsonb, NOW())
                ")->execute([
                    $partnerId,
                    $partnerBody,
                    json_encode(['action' => 'paused_cancellation', 'partner_id' => $partnerId, 'cancellation_rate' => $cancellationRate])
                ]);

                // Notify admin
                $adminBody = "Parceiro #{$partnerId} ({$partnerName}) pausado automaticamente. Taxa cancelamento: " . number_format($cancellationRate, 1, '.', '') . "% ({$cancelledOrders}/{$totalOrders} pedidos nos ultimos 30 dias).";
                $db->prepare("
                    INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                    VALUES (1, 'admin', 'Parceiro pausado: alta taxa cancelamento', ?, 'partner_health_alert', ?::jsonb, NOW())
                ")->execute([
                    $adminBody,
                    json_encode(['action' => 'paused_cancellation', 'partner_id' => $partnerId, 'cancellation_rate' => $cancellationRate])
                ]);

                $stats['auto_paused_cancellation']++;
                $stats['notifications_sent'] += 2;
                cron_log("AUTO-PAUSE parceiro #{$partnerId} ({$partnerName}): taxa cancelamento {$cancellationRate}% ({$cancelledOrders}/{$totalOrders})");

            } elseif ($autoAction === 'paused_complaints') {
                // Auto-pause partner
                $db->prepare("UPDATE om_market_partners SET is_open = 0 WHERE partner_id = ?")->execute([$partnerId]);

                // Notify partner
                $partnerBody2 = "Sua loja foi pausada devido a alta taxa de reclamacoes (" . number_format($complaintRate, 1, '.', '') . "%) nos ultimos 30 dias ({$complaintCount} disputas em {$totalOrders} pedidos). Entre em contato com o suporte.";
                $db->prepare("
                    INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                    VALUES (?, 'partner', 'Loja pausada: muitas reclamacoes', ?, 'partner_auto_paused', ?::jsonb, NOW())
                ")->execute([
                    $partnerId,
                    $partnerBody2,
                    json_encode(['action' => 'paused_complaints', 'partner_id' => $partnerId, 'complaint_rate' => $complaintRate])
                ]);

                // Notify admin
                $adminBody2 = "Parceiro #{$partnerId} ({$partnerName}) pausado automaticamente. Taxa reclamacoes: " . number_format($complaintRate, 1, '.', '') . "% ({$complaintCount} disputas em {$totalOrders} pedidos). Requer atencao.";
                $db->prepare("
                    INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                    VALUES (1, 'admin', 'ALERTA: Parceiro pausado por reclamacoes', ?, 'partner_health_critical', ?::jsonb, NOW())
                ")->execute([
                    $adminBody2,
                    json_encode(['action' => 'paused_complaints', 'partner_id' => $partnerId, 'complaint_rate' => $complaintRate])
                ]);

                $stats['auto_paused_complaints']++;
                $stats['notifications_sent'] += 2;
                cron_log("AUTO-PAUSE parceiro #{$partnerId} ({$partnerName}): taxa reclamacoes {$complaintRate}% ({$complaintCount}/{$totalOrders})");

            } elseif ($autoAction === 'flagged_low_rating') {
                // Notify admin only (no pause)
                $adminBody3 = "Parceiro #{$partnerId} ({$partnerName}) possui avaliacao media de " . number_format($avgRating, 2, '.', '') . " ({$totalReviews} avaliacoes nos ultimos 30 dias). Considerar revisao.";
                $db->prepare("
                    INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                    VALUES (1, 'admin', 'Parceiro com avaliacao baixa', ?, 'partner_health_alert', ?::jsonb, NOW())
                ")->execute([
                    $adminBody3,
                    json_encode(['action' => 'flagged_low_rating', 'partner_id' => $partnerId, 'avg_rating' => $avgRating, 'total_reviews' => $totalReviews])
                ]);

                $stats['flagged_low_rating']++;
                $stats['notifications_sent']++;
                cron_log("FLAG low_rating parceiro #{$partnerId} ({$partnerName}): media {$avgRating} ({$totalReviews} avaliacoes)");
            }

            if ($totalOrders > 0) {
                cron_log("SCORE parceiro #{$partnerId}: pedidos={$totalOrders} cancel={$cancellationRate}% disputas={$complaintRate}% rating={$avgRating} action=" . ($autoAction ?? 'none'));
            }

        } catch (Exception $e) {
            $stats['errors']++;
            cron_log("ERRO processando parceiro #{$partner['partner_id']}: " . $e->getMessage());
        }
    }

    // ============================================================
    // SUMMARY
    // ============================================================
    cron_log("=== RESUMO ===");
    cron_log("Parceiros verificados: {$stats['partners_checked']}");
    cron_log("Scores criados: {$stats['scores_created']}");
    cron_log("Auto-pausados (cancelamento): {$stats['auto_paused_cancellation']}");
    cron_log("Auto-pausados (reclamacoes): {$stats['auto_paused_complaints']}");
    cron_log("Flagged (avaliacao baixa): {$stats['flagged_low_rating']}");
    cron_log("Notificacoes enviadas: {$stats['notifications_sent']}");
    cron_log("Erros: {$stats['errors']}");

    if (!$isCli) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'stats' => $stats, 'timestamp' => date('c')]);
    }

} catch (Exception $e) {
    cron_log("ERRO FATAL: " . $e->getMessage());
    if (!$isCli) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit(1);
}
