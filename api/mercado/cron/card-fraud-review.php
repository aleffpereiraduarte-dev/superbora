<?php
/**
 * ============================================================================
 * CRON: card-fraud-review.php    (runs every 30 minutes)
 * ============================================================================
 * Schedule:   * /30 * * * *
 *
 * Responsibilities:
 *   1. Review "review" alerts older than 4 hours:
 *        - If no further suspicious activity on card -> auto-approve (mark resolved)
 *        - Otherwise -> escalate to admin (status=escalated)
 *   2. Execute pending auto-blocks (fraud_auto_block_pending events) after 10 minutes
 *      without a customer response.
 *
 * CLI or HTTP (with X-Cron-Key header).
 * ============================================================================
 */

ini_set('memory_limit', '256M');

$secret = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: '';
if (empty($secret)) {
    // Allow CLI without secret, but never HTTP
    if (php_sapi_name() !== 'cli') { http_response_code(503); die('no cron secret'); }
}
if (php_sapi_name() !== 'cli') {
    if (empty($_SERVER['HTTP_X_CRON_KEY']) || !hash_equals($secret, $_SERVER['HTTP_X_CRON_KEY'])) {
        http_response_code(403); die('denied');
    }
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/helpers/notify.php';
require_once dirname(__DIR__) . '/customer/card/_common.php';

$lockFile = sys_get_temp_dir() . '/superbora_card_fraud_review.lock';
$fp = fopen($lockFile, 'w');
if (!flock($fp, LOCK_EX | LOCK_NB)) { exit(0); }

function cfrLog(string $m): void {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] [card-fraud-review] ' . $m . "\n");
}

$db = getDB();
ensureCardTables($db);

$stats = ['reviewed' => 0, 'approved' => 0, 'escalated' => 0, 'auto_blocked' => 0, 'errors' => 0];

// ──────────────────────────────────────────────────────────────
// 1. Review pending "review" alerts older than 4h
// ──────────────────────────────────────────────────────────────
try {
    $stmt = $db->query("
        SELECT id, card_id, customer_id, risk_score, created_at
        FROM om_card_fraud_alerts
        WHERE action_taken = 'review'
          AND status = 'pending'
          AND created_at < NOW() - INTERVAL '4 hours'
        ORDER BY created_at ASC
        LIMIT 200
    ");
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($alerts as $a) {
        $stats['reviewed']++;
        try {
            // Pattern check: any new alert for this card/customer since this one
            $pat = $db->prepare("
                SELECT COUNT(*) FROM om_card_fraud_alerts
                WHERE (card_id = ? OR customer_id = ?)
                  AND created_at > ?
                  AND id <> ?
                  AND risk_score >= 30
            ");
            $pat->execute([$a['card_id'], $a['customer_id'], $a['created_at'], $a['id']]);
            $newer = (int)$pat->fetchColumn();

            if ($newer === 0) {
                $db->prepare("
                    UPDATE om_card_fraud_alerts
                    SET status = 'resolved', resolved_at = NOW(),
                        admin_decision = 'auto_approved',
                        admin_notes = COALESCE(admin_notes, '') || E'\\n[cron] Auto-aprovado: sem novos sinais em 4h'
                    WHERE id = ?
                ")->execute([$a['id']]);
                $stats['approved']++;
                cfrLog("alert {$a['id']} auto-approved");
            } else {
                $db->prepare("
                    UPDATE om_card_fraud_alerts
                    SET status = 'escalated',
                        admin_notes = COALESCE(admin_notes, '') || E'\\n[cron] Escalado: ' || ? || ' novos sinais detectados'
                    WHERE id = ?
                ")->execute([$newer, $a['id']]);
                $stats['escalated']++;
                cfrLog("alert {$a['id']} escalated ({$newer} new signals)");

                // Notify admin channel via card event
                try {
                    $db->prepare("
                        INSERT INTO om_credit_card_events
                            (card_id, customer_id, event_type, actor_type, actor_id, payload, notes)
                        VALUES (?, ?, 'fraud_alert_escalated', 'system', NULL, ?, ?)
                    ")->execute([
                        $a['card_id'], $a['customer_id'],
                        json_encode(['alert_id' => $a['id'], 'newer_signals' => $newer], JSON_UNESCAPED_UNICODE),
                        "Alerta escalado para revisao humana",
                    ]);
                } catch (Exception $e) { /* non-fatal */ }
            }
        } catch (Exception $e) {
            $stats['errors']++;
            cfrLog("error reviewing alert {$a['id']}: " . $e->getMessage());
        }
    }
} catch (Exception $e) {
    cfrLog('review scan failed: ' . $e->getMessage());
}

// ──────────────────────────────────────────────────────────────
// 2. Auto-block pending (no customer response in 10 min)
// ──────────────────────────────────────────────────────────────
try {
    $evStmt = $db->query("
        SELECT e.id, e.card_id, e.customer_id, e.payload, e.created_at
        FROM om_credit_card_events e
        WHERE e.event_type = 'fraud_auto_block_pending'
          AND e.created_at < NOW() - INTERVAL '10 minutes'
          AND NOT EXISTS (
              SELECT 1 FROM om_credit_card_events e2
              WHERE e2.card_id = e.card_id
                AND e2.event_type IN ('fraud_auto_block_done','fraud_customer_confirmed')
                AND e2.created_at > e.created_at
          )
        LIMIT 200
    ");
    $pending = $evStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pending as $p) {
        try {
            // Check if customer already responded via the alert
            $payload = json_decode($p['payload'] ?? '{}', true) ?: [];
            $alertId = (int)($payload['alert_id'] ?? 0);

            $customerResponded = false;
            if ($alertId) {
                $chk = $db->prepare("
                    SELECT customer_response FROM om_card_fraud_alerts
                    WHERE id = ? LIMIT 1
                ");
                $chk->execute([$alertId]);
                $resp = $chk->fetchColumn();
                if ($resp && $resp !== 'no_response') {
                    $customerResponded = true;
                }
            }

            if ($customerResponded) {
                // Mark pending as handled without blocking
                $db->prepare("
                    INSERT INTO om_credit_card_events
                      (card_id, customer_id, event_type, actor_type, actor_id, payload, notes)
                    VALUES (?, ?, 'fraud_auto_block_done', 'system', NULL, ?, 'Cliente respondeu — auto-block cancelado')
                ")->execute([
                    $p['card_id'], $p['customer_id'],
                    json_encode(['alert_id' => $alertId, 'cancelled' => true], JSON_UNESCAPED_UNICODE),
                ]);
                continue;
            }

            // Block the card
            $db->prepare("
                UPDATE om_credit_cards
                SET status = 'blocked',
                    blocked_at = NOW(),
                    block_reason = 'Fraude (sem resposta em 10 min)'
                WHERE id = ? AND status = 'active'
            ")->execute([$p['card_id']]);

            $db->prepare("
                INSERT INTO om_credit_card_events
                  (card_id, customer_id, event_type, actor_type, actor_id, payload, notes)
                VALUES (?, ?, 'fraud_auto_block_done', 'system', NULL, ?, 'Cartao auto-bloqueado por fraude')
            ")->execute([
                $p['card_id'], $p['customer_id'],
                json_encode(['alert_id' => $alertId], JSON_UNESCAPED_UNICODE),
            ]);

            if ($alertId) {
                $db->prepare("
                    UPDATE om_card_fraud_alerts
                    SET status = 'resolved', resolved_at = NOW(),
                        admin_decision = 'card_locked',
                        admin_notes = COALESCE(admin_notes, '') || E'\\n[cron] Cartao auto-bloqueado: cliente nao respondeu em 10 min'
                    WHERE id = ?
                ")->execute([$alertId]);
            }

            $stats['auto_blocked']++;
            cfrLog("card {$p['card_id']} auto-blocked (customer {$p['customer_id']})");

            // Push notification to customer
            try {
                notifyCustomer($db, (int)$p['customer_id'],
                    'Cartao bloqueado por seguranca',
                    'Seu cartao foi bloqueado automaticamente porque nao recebemos sua confirmacao. Entre em contato com o suporte.',
                    '/cartao',
                    ['type' => 'card_auto_blocked', 'card_id' => (int)$p['card_id']]
                );
            } catch (Exception $e) { /* non-fatal */ }
        } catch (Exception $e) {
            $stats['errors']++;
            cfrLog("error auto-blocking card {$p['card_id']}: " . $e->getMessage());
        }
    }
} catch (Exception $e) {
    cfrLog('auto-block scan failed: ' . $e->getMessage());
}

cfrLog(sprintf(
    'done reviewed=%d approved=%d escalated=%d auto_blocked=%d errors=%d',
    $stats['reviewed'], $stats['approved'], $stats['escalated'], $stats['auto_blocked'], $stats['errors']
));

flock($fp, LOCK_UN);
fclose($fp);
@unlink($lockFile);
exit(0);
