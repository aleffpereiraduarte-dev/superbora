<?php
/**
 * ============================================================================
 * CRON: bank-daily-evaluate.php  (runs 3 AM daily)
 * ============================================================================
 * Schedule: 0 3 * * *
 *
 * The autonomous bank brain performs its daily sweep:
 *   (1) Re-evaluates every active card-holder whose evaluation is >30 days old
 *       (or whose next_eval_at has passed)
 *   (2) Dynamically adjusts limits for good payers (up) and risky ones (down)
 *   (3) Identifies new qualified candidates (no card yet, but score >= policy
 *       minimum, enough history) and creates pre-approved offers for them
 *   (4) Expires stale pre_approved offers (>offer_ttl_days old)
 *   (5) Sends inactivity re-engagement notifications to customers inactive 30d
 *
 * All decisions are logged in om_bank_decisions. Customer notifications are
 * sent automatically.  Idempotent — trigger log enforces uniqueness.
 * ============================================================================
 */
ini_set('memory_limit', '512M');

$secret = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: '';
if (empty($secret)) {
    http_response_code(503);
    echo json_encode(['error' => 'Cron secret not configured']);
    exit;
}
if (php_sapi_name() !== 'cli' && (!isset($_SERVER['HTTP_X_CRON_KEY']) || !hash_equals($secret, $_SERVER['HTTP_X_CRON_KEY']))) {
    http_response_code(403);
    die('Acesso negado');
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/helpers/bank-brain.php';
require_once dirname(__DIR__) . '/helpers/bank-triggers.php';

$lockFile = sys_get_temp_dir() . '/superbora_bank_daily.lock';
$fp = fopen($lockFile, 'w');
if (!flock($fp, LOCK_EX | LOCK_NB)) { echo "SKIP: another instance running\n"; exit(0); }

function ll(string $m): void { fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] [bank-daily] ' . $m . "\n"); }

ll('starting daily autonomous bank sweep');

$db = getDB();
$brain = new SuperBoraBankBrain($db);

$totalEvaluated = 0;
$totalAdjustments = 0;
$totalOffers = 0;
$totalExpired = 0;
$totalReengage = 0;
$errors = 0;

// -----------------------------------------------------------------
// (1 + 2) Re-evaluate existing card holders + adjust limits
// -----------------------------------------------------------------
try {
    $stmt = $db->query("
        SELECT DISTINCT c.customer_id, c.id AS card_id, c.credit_limit, c.status
        FROM om_credit_cards c
        LEFT JOIN LATERAL (
            SELECT evaluated_at, next_eval_at
            FROM om_credit_evaluations e
            WHERE e.customer_id = c.customer_id
            ORDER BY e.evaluated_at DESC LIMIT 1
        ) e ON true
        WHERE c.status IN ('active','pre_approved')
          AND (e.evaluated_at IS NULL
               OR e.next_eval_at IS NULL
               OR e.next_eval_at <= NOW()
               OR e.evaluated_at <= NOW() - INTERVAL '30 days')
        ORDER BY c.customer_id
        LIMIT 500
    ");
    $cardholders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ll('re-evaluating ' . count($cardholders) . ' cardholders');

    foreach ($cardholders as $ch) {
        try {
            $report = $brain->evaluateCustomerForOffer((int)$ch['customer_id']);
            $totalEvaluated++;

            // Auto-block if AI says "negado"
            if (($report['score'] ?? 1000) < 300) {
                $brain->autoBlockCard((int)$ch['card_id'],
                    'Score abaixo de 300 em re-avaliacao', 'default_risk');
                continue;
            }

            // Adjust limit
            $newLimit = $report['limit'];
            $adjust = $brain->adjustLimit((int)$ch['card_id'], $newLimit, 'Re-avaliacao diaria');
            if ($adjust['changed']) $totalAdjustments++;
        } catch (Exception $e) {
            ll("error re-evaluating customer {$ch['customer_id']}: " . $e->getMessage());
            $errors++;
        }
    }
} catch (Exception $e) {
    ll('cardholder scan failed: ' . $e->getMessage());
    $errors++;
}

// -----------------------------------------------------------------
// (3) Identify new qualified candidates (no card yet, eligible)
// -----------------------------------------------------------------
try {
    $stmt = $db->query("
        SELECT c.customer_id
        FROM om_customers c
        LEFT JOIN om_credit_cards cc ON cc.customer_id = c.customer_id
        LEFT JOIN LATERAL (
            SELECT overall_score FROM om_credit_evaluations e
            WHERE e.customer_id = c.customer_id
            ORDER BY e.evaluated_at DESC LIMIT 1
        ) lastEval ON true
        WHERE (cc.id IS NULL OR cc.status IN ('rejected','cancelled','offer_expired','declined_by_customer'))
          AND c.created_at <= NOW() - INTERVAL '14 days'
          AND EXISTS (
              SELECT 1 FROM om_market_orders o
              WHERE o.customer_id = c.customer_id
                AND o.status NOT IN ('cancelado','reembolsado')
              GROUP BY o.customer_id
              HAVING COUNT(*) >= 5
          )
          AND (lastEval.overall_score IS NULL OR lastEval.overall_score >= 650)
        LIMIT 200
    ");
    $candidates = $stmt->fetchAll(PDO::FETCH_COLUMN);
    ll('checking ' . count($candidates) . ' new candidates for pre-approval');

    foreach ($candidates as $cid) {
        try {
            $report = $brain->evaluateCustomerForOffer((int)$cid);
            $totalEvaluated++;
            if ($report['should_offer']) {
                $res = $brain->createPreApprovedOffer((int)$cid, $report);
                if ($res && empty($res['already_existed'])) $totalOffers++;
            }
        } catch (Exception $e) {
            ll("error evaluating candidate {$cid}: " . $e->getMessage());
            $errors++;
        }
    }
} catch (Exception $e) {
    ll('candidate scan failed: ' . $e->getMessage());
    $errors++;
}

// -----------------------------------------------------------------
// (4) Expire stale pre_approved offers
// -----------------------------------------------------------------
try {
    $upd = $db->prepare("
        UPDATE om_credit_cards
        SET status = 'offer_expired', cancelled_at = NOW()
        WHERE status = 'pre_approved'
          AND offer_expires_at IS NOT NULL
          AND offer_expires_at < NOW()
        RETURNING id, customer_id
    ");
    $upd->execute();
    $rows = $upd->fetchAll(PDO::FETCH_ASSOC);
    $totalExpired = count($rows);
    if ($totalExpired > 0) ll("expired {$totalExpired} stale offers");
} catch (Exception $e) {
    ll('offer expiry failed: ' . $e->getMessage());
    $errors++;
}

// -----------------------------------------------------------------
// (5) Inactivity re-engagement (customers inactive 30 days with a card)
// -----------------------------------------------------------------
try {
    $stmt = $db->query("
        SELECT DISTINCT cc.customer_id
        FROM om_credit_cards cc
        WHERE cc.status = 'active'
          AND NOT EXISTS (
              SELECT 1 FROM om_market_orders o
              WHERE o.customer_id = cc.customer_id
                AND o.created_at >= NOW() - INTERVAL '30 days'
          )
        LIMIT 200
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        bankTriggers_sendInactiveReengage((int)$cid);
        $totalReengage++;
    }
} catch (Exception $e) {
    ll('inactive reengage failed: ' . $e->getMessage());
    $errors++;
}

ll(sprintf(
    'done: evaluated=%d limit_changes=%d new_offers=%d offers_expired=%d reengage_sent=%d errors=%d',
    $totalEvaluated, $totalAdjustments, $totalOffers, $totalExpired, $totalReengage, $errors
));

flock($fp, LOCK_UN);
fclose($fp);
@unlink($lockFile);
exit(0);
