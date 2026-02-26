<?php
/**
 * CRON: Dispute SLA Enforcement
 * Run every 15 minutes via crontab
 *
 * 1. Disputes past SLA → mark sla_breached, notify admin
 * 2. Escalated disputes with no admin response >48h → auto-approve customer
 * 3. Partner hasn't responded to dispute >24h → auto-accept
 * 4. In-review disputes >72h → force resolution (favor customer)
 * 5. Customer notifications for status updates
 */

$isCli = php_sapi_name() === 'cli';

function cron_log($msg) {
    global $isCli;
    $line = "[" . date('Y-m-d H:i:s') . "] [dispute-sla] {$msg}";
    if ($isCli) echo $line . "\n";
    error_log($line);
}

try {
    $db = new PDO(
        "pgsql:host=147.93.12.236;port=5432;dbname=love1",
        'love1', 'Aleff2009@',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $stats = [
        'checked' => 0,
        'sla_breached' => 0,
        'auto_approved_no_partner' => 0,
        'auto_approved_stale_escalation' => 0,
        'force_resolved' => 0,
        'notifications_sent' => 0,
        'errors' => 0,
    ];

    // Verificar se a tabela de disputas existe (criada pelo dispute.php na primeira disputa)
    $tableExists = $db->query("SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_name = 'om_order_disputes')")->fetchColumn();
    if (!$tableExists) {
        cron_log("Tabela om_order_disputes nao existe ainda. Nenhuma disputa para processar.");
        if (!$isCli) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'stats' => $stats, 'message' => 'Tabela de disputas nao existe', 'timestamp' => date('c')]);
        }
        exit(0);
    }

    // ============================================================
    // 1. DISPUTES PAST SLA → MARK BREACHED + ALERT
    // ============================================================
    cron_log("--- Checking SLA breaches ---");

    $stmtSla = $db->query("
        SELECT d.dispute_id, d.order_id, d.customer_id, d.subcategory,
               d.sla_hours, d.created_at, d.status, d.severity
        FROM om_order_disputes d
        WHERE d.status IN ('in_review', 'awaiting_evidence', 'escalated')
          AND d.sla_breached IS NOT TRUE
          AND d.sla_hours IS NOT NULL
          AND d.created_at + (d.sla_hours || ' hours')::INTERVAL < NOW()
        ORDER BY d.created_at ASC
        LIMIT 100
    ");
    $breachedDisputes = $stmtSla->fetchAll();
    $stats['checked'] += count($breachedDisputes);

    foreach ($breachedDisputes as $dispute) {
        try {
            $disputeId = (int)$dispute['dispute_id'];

            $db->prepare("
                UPDATE om_order_disputes
                SET sla_breached = TRUE, updated_at = NOW()
                WHERE dispute_id = ?
            ")->execute([$disputeId]);

            // Timeline entry
            $timelineDesc = "SLA de {$dispute['sla_hours']}h excedido. Disputa requer atencao urgente.";
            $db->prepare("
                INSERT INTO om_dispute_timeline (dispute_id, actor_type, action, description, created_at)
                VALUES (?, 'system', 'sla_breached', ?, NOW())
            ")->execute([$disputeId, $timelineDesc]);

            // Admin alert
            $severity = $dispute['severity'] === 'critical' ? 'URGENTE: ' : '';
            $notifBody = "Disputa #{$disputeId} (pedido #{$dispute['order_id']}) ultrapassou SLA de {$dispute['sla_hours']}h. Status: {$dispute['status']}. Severidade: {$dispute['severity']}.";
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                VALUES (1, 'admin', ?, ?, 'sla_breach', ?::jsonb, NOW())
            ")->execute([
                $severity . 'SLA excedido - Disputa #' . $disputeId,
                $notifBody,
                json_encode(['reference_type' => 'dispute', 'reference_id' => $disputeId])
            ]);

            // Customer notification
            $customerBody = "Sua disputa #{$disputeId} esta sendo priorizada pela nossa equipe. Pedimos desculpas pelo atraso.";
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                VALUES (?, 'customer', 'Atualizacao da sua disputa', ?, 'dispute_update', ?::jsonb, NOW())
            ")->execute([$dispute['customer_id'], $customerBody, json_encode(['reference_type' => 'dispute', 'reference_id' => $disputeId])]);

            $stats['sla_breached']++;
            $stats['notifications_sent'] += 2;
            cron_log("SLA BREACHED disputa #{$disputeId} (SLA: {$dispute['sla_hours']}h, status: {$dispute['status']})");
        } catch (Exception $e) {
            $stats['errors']++;
            cron_log("ERRO SLA breach #{$disputeId}: " . $e->getMessage());
        }
    }

    // ============================================================
    // 2. PARTNER DIDN'T RESPOND >24H → AUTO-ACCEPT (FAVOR CUSTOMER)
    // ============================================================
    cron_log("--- Checking disputes without partner response ---");

    $stmtNoPartner = $db->query("
        SELECT d.dispute_id, d.order_id, d.customer_id, d.partner_id,
               d.subcategory, d.refund_amount, d.credit_amount, d.severity
        FROM om_order_disputes d
        WHERE d.status = 'in_review'
          AND d.partner_response IS NULL
          AND d.created_at < NOW() - INTERVAL '24 hours'
          AND d.auto_resolved IS NOT TRUE
        ORDER BY d.created_at ASC
        LIMIT 50
    ");
    $noPartnerDisputes = $stmtNoPartner->fetchAll();
    $stats['checked'] += count($noPartnerDisputes);

    foreach ($noPartnerDisputes as $dispute) {
        try {
            $db->beginTransaction();
            $disputeId = (int)$dispute['dispute_id'];
            $customerId = (int)$dispute['customer_id'];
            $refundAmount = (float)($dispute['refund_amount'] ?? 0);
            $creditAmount = (float)($dispute['credit_amount'] ?? 0);

            // Resolve in favor of customer
            $db->prepare("
                UPDATE om_order_disputes
                SET status = 'resolved',
                    resolution = 'auto_approved_no_partner_response',
                    approved_amount = ?,
                    resolved_at = NOW(),
                    updated_at = NOW()
                WHERE dispute_id = ?
            ")->execute([$refundAmount, $disputeId]);

            // Create refund if amount > 0
            if ($refundAmount > 0) {
                $db->prepare("
                    INSERT INTO om_market_refunds (order_id, amount, reason, status, created_at)
                    VALUES (?, ?, 'dispute_auto_approved_no_partner', 'approved', NOW())
                ")->execute([$dispute['order_id'], $refundAmount]);
            }

            // Add credit if applicable
            if ($creditAmount > 0) {
                $db->prepare("
                    UPDATE om_customer_wallet SET balance = COALESCE(balance, 0) + ? WHERE customer_id = ?
                ")->execute([$creditAmount, $customerId]);

                $stmtBal = $db->prepare("SELECT balance FROM om_customer_wallet WHERE customer_id = ?");
                $stmtBal->execute([$customerId]);
                $walletBalance = (float)($stmtBal->fetchColumn() ?: 0);
                $db->prepare("
                    INSERT INTO om_wallet_transactions (customer_id, type, amount, balance_before, balance_after, description, reference, created_at)
                    VALUES (?, 'credit', ?, ?, ?, ?, ?, NOW())
                ")->execute([$customerId, $creditAmount, $walletBalance - $creditAmount, $walletBalance, 'Credito automatico - disputa #' . $disputeId . ' (parceiro nao respondeu)', 'dispute_' . $disputeId]);
            }

            // Timeline
            $db->prepare("
                INSERT INTO om_dispute_timeline (dispute_id, actor_type, action, description, created_at)
                VALUES (?, 'system', 'auto_resolved', 'Resolvido automaticamente a favor do cliente - parceiro nao respondeu em 24h.', NOW())
            ")->execute([$disputeId]);

            // Notify customer
            $totalComp = $refundAmount + $creditAmount;
            $customerBody = "Sua disputa #{$disputeId} foi resolvida a seu favor. Compensacao: R\$" . number_format($totalComp, 2, ',', '.') . ".";
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                VALUES (?, 'customer', 'Disputa resolvida!', ?, 'dispute_resolved', ?::jsonb, NOW())
            ")->execute([$customerId, $customerBody, json_encode(['reference_type' => 'dispute', 'reference_id' => $disputeId])]);

            // Notify partner
            $partnerBody = "Disputa #{$disputeId} foi resolvida a favor do cliente pois nao houve resposta em 24h.";
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                VALUES (?, 'partner', 'Disputa resolvida automaticamente', ?, 'dispute_auto_resolved', ?::jsonb, NOW())
            ")->execute([$dispute['partner_id'], $partnerBody, json_encode(['reference_type' => 'dispute', 'reference_id' => $disputeId])]);

            $db->commit();
            $stats['auto_approved_no_partner']++;
            $stats['notifications_sent'] += 2;
            cron_log("AUTO-APPROVE disputa #{$disputeId}: parceiro nao respondeu em 24h. Compensacao R\${$totalComp}");
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $stats['errors']++;
            cron_log("ERRO auto-approve #{$disputeId}: " . $e->getMessage());
        }
    }

    // ============================================================
    // 3. ESCALATED >48H WITHOUT ADMIN REVIEW → AUTO-APPROVE
    // ============================================================
    cron_log("--- Checking stale escalated disputes ---");

    $stmtEscalated = $db->query("
        SELECT d.dispute_id, d.order_id, d.customer_id, d.partner_id,
               d.refund_amount, d.credit_amount
        FROM om_order_disputes d
        WHERE d.status = 'escalated'
          AND d.updated_at < NOW() - INTERVAL '48 hours'
        ORDER BY d.updated_at ASC
        LIMIT 30
    ");
    $staleEscalated = $stmtEscalated->fetchAll();
    $stats['checked'] += count($staleEscalated);

    foreach ($staleEscalated as $dispute) {
        try {
            $db->beginTransaction();
            $disputeId = (int)$dispute['dispute_id'];
            $customerId = (int)$dispute['customer_id'];
            $refundAmount = (float)($dispute['refund_amount'] ?? 0);
            $creditAmount = (float)($dispute['credit_amount'] ?? 0);

            $db->prepare("
                UPDATE om_order_disputes
                SET status = 'resolved',
                    resolution = 'auto_approved_stale_escalation',
                    approved_amount = ?,
                    resolved_at = NOW(), updated_at = NOW()
                WHERE dispute_id = ?
            ")->execute([$refundAmount, $disputeId]);

            if ($refundAmount > 0) {
                $db->prepare("
                    INSERT INTO om_market_refunds (order_id, amount, reason, status, created_at)
                    VALUES (?, ?, 'dispute_stale_escalation', 'approved', NOW())
                ")->execute([$dispute['order_id'], $refundAmount]);
            }

            if ($creditAmount > 0) {
                $db->prepare("UPDATE om_customer_wallet SET balance = COALESCE(balance, 0) + ? WHERE customer_id = ?")->execute([$creditAmount, $customerId]);
                $stmtBal2 = $db->prepare("SELECT balance FROM om_customer_wallet WHERE customer_id = ?");
                $stmtBal2->execute([$customerId]);
                $walletBalance2 = (float)($stmtBal2->fetchColumn() ?: 0);
                $db->prepare("
                    INSERT INTO om_wallet_transactions (customer_id, type, amount, balance_before, balance_after, description, reference, created_at)
                    VALUES (?, 'credit', ?, ?, ?, ?, ?, NOW())
                ")->execute([$customerId, $creditAmount, $walletBalance2 - $creditAmount, $walletBalance2, 'Credito auto - escalacao nao resolvida em 48h', 'dispute_esc_' . $disputeId]);
            }

            $db->prepare("
                INSERT INTO om_dispute_timeline (dispute_id, actor_type, action, description, created_at)
                VALUES (?, 'system', 'auto_resolved', 'Resolvido automaticamente - escalacao sem revisao administrativa em 48h.', NOW())
            ")->execute([$disputeId]);

            $customerBody3 = "Sua disputa #{$disputeId} foi resolvida a seu favor.";
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                VALUES (?, 'customer', 'Disputa resolvida!', ?, 'dispute_resolved', ?::jsonb, NOW())
            ")->execute([$customerId, $customerBody3, json_encode(['reference_type' => 'dispute', 'reference_id' => $disputeId])]);

            $adminBody3 = "Disputa #{$disputeId} foi auto-resolvida (escalacao >48h sem revisao).";
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                VALUES (1, 'admin', 'Disputa auto-resolvida por timeout', ?, 'dispute_auto_timeout', ?::jsonb, NOW())
            ")->execute([$adminBody3, json_encode(['reference_type' => 'dispute', 'reference_id' => $disputeId])]);

            $db->commit();
            $stats['auto_approved_stale_escalation']++;
            cron_log("AUTO-APPROVE escalacao #{$disputeId}: sem revisao em 48h");
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $stats['errors']++;
            cron_log("ERRO stale escalation #{$disputeId}: " . $e->getMessage());
        }
    }

    // ============================================================
    // SUMMARY
    // ============================================================
    cron_log("=== RESUMO ===");
    cron_log("Verificadas: {$stats['checked']}");
    cron_log("SLA violado: {$stats['sla_breached']}");
    cron_log("Auto-aprovadas (sem resposta parceiro): {$stats['auto_approved_no_partner']}");
    cron_log("Auto-aprovadas (escalacao >48h): {$stats['auto_approved_stale_escalation']}");
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
