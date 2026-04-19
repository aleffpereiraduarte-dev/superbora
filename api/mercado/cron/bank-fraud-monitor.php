<?php
/**
 * ============================================================================
 * CRON: bank-fraud-monitor.php  (runs every 15 minutes)
 * ============================================================================
 * Schedule:  * /15 * * * *    (every 15 min)
 *
 * Scans recent credit card transactions for fraud patterns:
 *   - > 5 transactions > R$300 in 1 hour
 *   - transactions while card is marked "blocked"
 *   - sudden velocity spike (>5x 6m average)
 *   - multiple failed payments
 *
 * When a signal fires, the card is auto-blocked and the customer is notified.
 * Also flags the evaluation for admin review in om_credit_evaluations.
 * ============================================================================
 */
ini_set('memory_limit', '256M');

$secret = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: '';
if (empty($secret)) { http_response_code(503); die('no cron secret'); }
if (php_sapi_name() !== 'cli' && (!isset($_SERVER['HTTP_X_CRON_KEY']) || !hash_equals($secret, $_SERVER['HTTP_X_CRON_KEY']))) {
    http_response_code(403); die('denied');
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/helpers/bank-brain.php';

$lockFile = sys_get_temp_dir() . '/superbora_bank_fraud.lock';
$fp = fopen($lockFile, 'w');
if (!flock($fp, LOCK_EX | LOCK_NB)) exit(0);

function ll(string $m): void { fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] [fraud] ' . $m . "\n"); }

$db = getDB();
$brain = new SuperBoraBankBrain($db);
$policy = $brain->getPolicy();
if (empty($policy['fraud_detection_enabled'])) { ll('detection disabled, skipping'); exit(0); }

ll('scanning for fraud signals');
$blocked = 0; $checked = 0;

try {
    $stmt = $db->query("
        SELECT DISTINCT cc.id AS card_id, cc.customer_id
        FROM om_credit_cards cc
        WHERE cc.status = 'active'
          AND EXISTS (
              SELECT 1 FROM om_credit_card_transactions t
              WHERE t.card_id = cc.id
                AND t.transaction_date >= NOW() - INTERVAL '2 hours'
          )
    ");
    $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($cards as $c) {
        $checked++;
        try {
            $flags = $brain->detectFraudSignals((int)$c['customer_id']);
            if (!empty($flags)) {
                $reason = 'Fraude: ' . implode(', ', array_slice($flags, 0, 3));
                if ($brain->autoBlockCard((int)$c['card_id'], $reason, 'fraud')) {
                    $blocked++;
                    ll("auto-blocked card {$c['card_id']} (customer {$c['customer_id']}): {$reason}");
                }
            }
        } catch (Exception $e) {
            ll("error checking card {$c['card_id']}: " . $e->getMessage());
        }
    }
} catch (Exception $e) {
    ll('fraud scan failed: ' . $e->getMessage());
}

ll("done: checked={$checked} blocked={$blocked}");
flock($fp, LOCK_UN);
fclose($fp);
@unlink($lockFile);
exit(0);
