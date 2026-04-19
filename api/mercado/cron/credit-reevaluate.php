<?php
/**
 * ============================================================================
 * Cron: Credit Re-evaluation (daily)
 * ============================================================================
 * Re-runs the CreditScoringEngine for:
 *   (a) customers whose last evaluation is more than 30 days old, OR
 *   (b) customers with an active credit card whose next_eval_at has passed.
 *
 * If the score changes materially (+/- 30 points OR +/- R$ 100 in limit),
 * the existing credit card is updated and the customer receives a push
 * notification. This lets the system reward good behavior ("Seu limite
 * aumentou!") and react to deteriorating behavior ("Seu limite foi ajustado").
 *
 * Schedule: 0 4 * * *  (4 AM daily, off-peak)
 * Batches up to 500 customers per run to avoid long-running jobs.
 * ============================================================================
 */

ini_set('memory_limit', '512M');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/credit-scoring.php';
require_once __DIR__ . '/../helpers/NotificationSender.php';

$BATCH_LIMIT   = 500;
$MATERIAL_SCORE_DELTA = 30;   // only act on +/- 30 pts changes
$MATERIAL_LIMIT_DELTA = 100;  // or +/- R$ 100

$db = getDB();
$engine = new CreditScoringEngine($db);
$sender = NotificationSender::getInstance($db);

function log_line(string $msg): void
{
    $stamp = date('Y-m-d H:i:s');
    fwrite(STDOUT, "[{$stamp}] [credit-reevaluate] {$msg}\n");
}

log_line("starting batch (limit={$BATCH_LIMIT})");

try {
    // Candidates: anyone whose next_eval_at is past, OR who has an active
    // card but no evaluation in the last 30 days.
    $stmt = $db->prepare("
        WITH due_evals AS (
            SELECT DISTINCT ON (customer_id) customer_id, evaluated_at, next_eval_at
            FROM om_credit_evaluations
            ORDER BY customer_id, evaluated_at DESC
        )
        SELECT DISTINCT cc.customer_id
        FROM om_credit_cards cc
        LEFT JOIN due_evals de ON de.customer_id = cc.customer_id
        WHERE cc.status IN ('active','pending_approval')
          AND (
              de.customer_id IS NULL
              OR de.next_eval_at IS NULL
              OR de.next_eval_at <= NOW()
              OR de.evaluated_at <= NOW() - INTERVAL '30 days'
          )
        LIMIT ?
    ");
    $stmt->execute([$BATCH_LIMIT]);
    $candidates = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    log_line('candidate lookup failed: ' . $e->getMessage());
    exit(1);
}

if (empty($candidates)) {
    log_line('no candidates due');
    exit(0);
}

log_line('processing ' . count($candidates) . ' customers');

$processed = 0;
$upgraded  = 0;
$downgraded = 0;
$unchanged = 0;
$errors    = 0;

foreach ($candidates as $customerId) {
    $customerId = (int)$customerId;
    if ($customerId <= 0) continue;

    try {
        // Pull previous evaluation for comparison
        $prev = $engine->latestEvaluation($customerId);
        $prevScore = $prev ? (int)$prev['overall_score'] : null;
        $prevLimit = $prev ? (float)$prev['final_limit']  : null;
        $prevDecision = $prev ? (string)$prev['final_decision'] : null;

        // Run new evaluation (AI enabled so the reasoning is fresh too)
        $eval = $engine->evaluate($customerId, [
            'use_ai'  => true,
            'persist' => true,
        ]);

        $scoreDelta = $prevScore !== null ? ((int)$eval['overall_score']) - $prevScore : null;
        $limitDelta = $prevLimit !== null ? ((float)$eval['final_limit']) - $prevLimit : null;
        $decisionChanged = $prevDecision !== $eval['final_decision'];

        $material = $decisionChanged
            || ($scoreDelta !== null && abs($scoreDelta) >= $MATERIAL_SCORE_DELTA)
            || ($limitDelta !== null && abs($limitDelta) >= $MATERIAL_LIMIT_DELTA);

        if (!$material) {
            $unchanged++;
            $processed++;
            continue;
        }

        // Sync the active credit card
        $card = $db->prepare("
            SELECT id, status, credit_limit
            FROM om_credit_cards WHERE customer_id = ?
            ORDER BY created_at DESC LIMIT 1
        ");
        $card->execute([$customerId]);
        $c = $card->fetch(PDO::FETCH_ASSOC);

        if ($c) {
            if ($eval['final_decision'] === 'negado') {
                $db->prepare("
                    UPDATE om_credit_cards
                       SET status = 'blocked',
                           block_reason = 'Re-avaliacao automatica',
                           blocked_at = NOW()
                     WHERE id = ?
                ")->execute([$c['id']]);
            } elseif ($eval['final_decision'] === 'aprovado' && $c['status'] === 'pending_approval') {
                $db->prepare("
                    UPDATE om_credit_cards
                       SET status = 'active',
                           credit_limit = ?,
                           approved_at = COALESCE(approved_at, NOW()),
                           activated_at = COALESCE(activated_at, NOW())
                     WHERE id = ?
                ")->execute([$eval['final_limit'], $c['id']]);
            } else {
                $db->prepare("UPDATE om_credit_cards SET credit_limit = ? WHERE id = ?")
                   ->execute([$eval['final_limit'], $c['id']]);
            }
        }

        // Notify customer of material change
        try {
            if ($limitDelta !== null && $limitDelta >= $MATERIAL_LIMIT_DELTA) {
                $sender->notifyCustomer($customerId,
                    'Seu limite aumentou!',
                    'Bom trabalho! Seu novo limite de credito e de R$ '
                        . number_format($eval['final_limit'], 2, ',', '.') . '.',
                    ['type' => 'credit_reeval', 'delta' => $limitDelta, 'new_limit' => $eval['final_limit']]
                );
                $upgraded++;
            } elseif ($limitDelta !== null && $limitDelta <= -$MATERIAL_LIMIT_DELTA) {
                $sender->notifyCustomer($customerId,
                    'Seu limite foi ajustado',
                    'Seu novo limite de credito e de R$ '
                        . number_format($eval['final_limit'], 2, ',', '.') . '. Dicas para melhorar no app.',
                    ['type' => 'credit_reeval', 'delta' => $limitDelta, 'new_limit' => $eval['final_limit']]
                );
                $downgraded++;
            } elseif ($decisionChanged && $eval['final_decision'] === 'aprovado') {
                $sender->notifyCustomer($customerId,
                    'Credito aprovado!',
                    'Sua avaliação foi atualizada e seu crédito foi liberado.',
                    ['type' => 'credit_reeval', 'decision' => 'aprovado']
                );
                $upgraded++;
            } elseif ($decisionChanged && $eval['final_decision'] === 'negado') {
                $sender->notifyCustomer($customerId,
                    'Credito temporariamente suspenso',
                    'Sua avaliação foi atualizada. Consulte o app para mais informações.',
                    ['type' => 'credit_reeval', 'decision' => 'negado']
                );
                $downgraded++;
            }
        } catch (Exception $e) {
            error_log('[credit-reevaluate] notify failed customer=' . $customerId . ': ' . $e->getMessage());
        }

        $processed++;
    } catch (Exception $e) {
        log_line("customer {$customerId} failed: " . $e->getMessage());
        $errors++;
    }
}

log_line(sprintf(
    'done: processed=%d upgraded=%d downgraded=%d unchanged=%d errors=%d',
    $processed, $upgraded, $downgraded, $unchanged, $errors
));

exit(0);
