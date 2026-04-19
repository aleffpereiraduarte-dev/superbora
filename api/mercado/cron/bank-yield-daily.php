<?php
/**
 * ============================================================================
 * CRON: bank-yield-daily.php  (runs 23:00 daily, AFTER wallet-yield.php)
 * ============================================================================
 * Schedule: 30 23 * * *   (30 min after wallet-yield.php)
 *
 * Autonomous bank-side yield actions, COMPLEMENTARY to wallet-yield.php
 * (which just credits CDI). This cron does the "auto-invest" piece:
 *   - For customers with idle wallet balance > policy.yield_auto_invest_min
 *     who have OPTED IN (via om_bank_auto_invest), moves the excess into
 *     a rendimento sub-account (om_bank_rendimento).
 *   - Tracks total system liquidity for the admin dashboard.
 *   - Sends one-time "you could be earning more" tip for users with idle
 *     balance but NOT opted in.
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
require_once dirname(__DIR__) . '/helpers/bank-triggers.php';
require_once dirname(__DIR__) . '/helpers/NotificationSender.php';

$lockFile = sys_get_temp_dir() . '/superbora_bank_yield.lock';
$fp = fopen($lockFile, 'w');
if (!flock($fp, LOCK_EX | LOCK_NB)) exit(0);

function ll(string $m): void { fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] [bank-yield] ' . $m . "\n"); }

$db = getDB();
$brain = new SuperBoraBankBrain($db);
$policy = $brain->getPolicy();
$threshold = (float)$policy['yield_auto_invest_min'];

// Ensure auto-invest opt-in table
$db->exec("
    CREATE TABLE IF NOT EXISTS om_bank_auto_invest (
        customer_id INTEGER PRIMARY KEY,
        enabled BOOLEAN NOT NULL DEFAULT true,
        threshold NUMERIC(12,2) NOT NULL DEFAULT 1000,
        opted_in_at TIMESTAMP DEFAULT NOW()
    )
");
$db->exec("
    CREATE TABLE IF NOT EXISTS om_bank_rendimento (
        id SERIAL PRIMARY KEY,
        customer_id INTEGER NOT NULL,
        amount NUMERIC(12,2) NOT NULL,
        direction VARCHAR(10) NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT NOW()
    )
");
$db->exec("CREATE INDEX IF NOT EXISTS idx_rendimento_customer ON om_bank_rendimento(customer_id, created_at DESC)");

ll('running bank-yield autonomous actions');

$moved = 0; $tipsSent = 0; $totalLiq = 0.0;

try {
    $stmt = $db->query("
        SELECT w.customer_id, w.balance, ai.enabled, ai.threshold
        FROM om_cashback_wallet w
        LEFT JOIN om_bank_auto_invest ai ON ai.customer_id = w.customer_id
        WHERE w.balance > 0
    ");
    foreach ($stmt as $w) {
        $bal = (float)$w['balance'];
        $totalLiq += $bal;
        $enabled = $w['enabled'] === true || $w['enabled'] === 't' || $w['enabled'] === '1';
        $thr = $w['threshold'] !== null ? (float)$w['threshold'] : $threshold;

        if ($enabled && $bal > $thr) {
            $move = round($bal - $thr, 2);
            if ($move >= 1.00) {
                try {
                    $db->beginTransaction();
                    $db->prepare("UPDATE om_cashback_wallet SET balance = balance - ? WHERE customer_id = ?")
                        ->execute([$move, $w['customer_id']]);
                    $db->prepare("
                        INSERT INTO om_bank_rendimento (customer_id, amount, direction, description)
                        VALUES (?, ?, 'in', 'Auto-invest diario')
                    ")->execute([$w['customer_id'], $move]);
                    $db->prepare("
                        INSERT INTO om_cashback_transactions (customer_id, type, amount, balance_after, description, expired)
                        VALUES (?, 'auto_invest', ?, ?, ?, 0)
                    ")->execute([$w['customer_id'], -$move, round($bal - $move, 2), 'Movido para rendimento']);
                    $db->commit();
                    $moved++;
                } catch (Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    ll("auto-invest failed for customer {$w['customer_id']}: " . $e->getMessage());
                }
            }
        } elseif (!$enabled && $bal > $thr) {
            // Opportunity tip — once per week
            $key = 'yield_tip_' . date('Y_W');
            if (bankTriggers_once($db, (int)$w['customer_id'], $key, ['balance' => $bal])) {
                try {
                    $sender = NotificationSender::getInstance($db);
                    $sender->notifyCustomer((int)$w['customer_id'],
                        'Voce esta deixando dinheiro parado!',
                        sprintf('Sua carteira tem R$ %s. Ative o auto-investimento e renda mais.',
                            number_format($bal, 2, ',', '.')),
                        ['type' => 'yield_tip', 'screen' => '/carteira']
                    );
                    $tipsSent++;
                } catch (Exception $e) { /* ignore */ }
            }
        }
    }
} catch (Exception $e) {
    ll('yield sweep failed: ' . $e->getMessage());
}

// Record system liquidity snapshot
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_bank_liquidity_snapshots (
            snap_date DATE PRIMARY KEY,
            total_wallet_balance NUMERIC(14,2) NOT NULL,
            total_rendimento NUMERIC(14,2) NOT NULL DEFAULT 0,
            total_credit_used NUMERIC(14,2) NOT NULL DEFAULT 0,
            total_credit_granted NUMERIC(14,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT NOW()
        )
    ");
    $rendRow = $db->query("SELECT COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE -amount END), 0) FROM om_bank_rendimento")
        ->fetchColumn();
    $cards = $db->query("
        SELECT COALESCE(SUM(used_limit), 0) AS used,
               COALESCE(SUM(credit_limit), 0) AS granted
        FROM om_credit_cards WHERE status = 'active'
    ")->fetch(PDO::FETCH_ASSOC);
    $db->prepare("
        INSERT INTO om_bank_liquidity_snapshots
            (snap_date, total_wallet_balance, total_rendimento, total_credit_used, total_credit_granted)
        VALUES (CURRENT_DATE, ?, ?, ?, ?)
        ON CONFLICT (snap_date) DO UPDATE SET
            total_wallet_balance = EXCLUDED.total_wallet_balance,
            total_rendimento     = EXCLUDED.total_rendimento,
            total_credit_used    = EXCLUDED.total_credit_used,
            total_credit_granted = EXCLUDED.total_credit_granted
    ")->execute([$totalLiq, (float)$rendRow, (float)$cards['used'], (float)$cards['granted']]);
} catch (Exception $e) { ll('liquidity snapshot failed: ' . $e->getMessage()); }

ll("done: moved={$moved} tips={$tipsSent} total_liq=" . number_format($totalLiq, 2));

flock($fp, LOCK_UN);
fclose($fp);
@unlink($lockFile);
exit(0);
