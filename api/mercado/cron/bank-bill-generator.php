<?php
/**
 * ============================================================================
 * CRON: bank-bill-generator.php  (runs on day 1 of each month at 6 AM)
 * ============================================================================
 * Schedule: 0 6 1 * *
 *
 * Generates monthly bills for all active cards with any transactions during
 * the closed billing period:
 *   - period_start = previous month first day after closing_day
 *   - period_end   = this month's closing_day
 *   - due_date     = this month's due_day (typically 10th or 15th)
 *   - minimum_amount = policy.min_payment_pct of total
 *
 * Annual fees (if any, for score < 700) are added as an om_credit_card_revenue
 * row and included in the bill total.
 *
 * Idempotent — UNIQUE(card_id, period_start) prevents duplicates.
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
require_once dirname(__DIR__) . '/helpers/NotificationSender.php';
require_once dirname(__DIR__) . '/customer/card/_common.php';

$lockFile = sys_get_temp_dir() . '/superbora_bank_bills.lock';
$fp = fopen($lockFile, 'w');
if (!flock($fp, LOCK_EX | LOCK_NB)) exit(0);

function ll(string $m): void { fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] [bills] ' . $m . "\n"); }

$db = getDB();
$brain = new SuperBoraBankBrain($db);
$policy = $brain->getPolicy();
$minPct = (float)$policy['min_payment_pct'] / 100.0;

ensureCardTables($db);

ll('generating monthly bills');

$stmt = $db->query("
    SELECT id, customer_id, credit_limit, closing_day, due_day, score
    FROM om_credit_cards
    WHERE status = 'active'
");
$cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
ll('scanning ' . count($cards) . ' active cards');

$generated = 0; $skipped = 0; $errors = 0;

foreach ($cards as $card) {
    try {
        [$ps, $pe, $due] = currentBillingPeriod($card);

        // If today <= closing_day of this month, we're still inside the current
        // period; skip — the bill will be generated next month. But this cron
        // runs on day 1, so we want the PREVIOUS period.
        $today = new DateTime();
        $periodEndDt = new DateTime($pe);
        if ($periodEndDt > $today) {
            // compute PREVIOUS period
            $prevEnd = (clone $today)->modify('-1 month');
            $ps = $prevEnd->format('Y-m-') . str_pad(((int)$card['closing_day']) + 1, 2, '0', STR_PAD_LEFT);
            $pe = $today->format('Y-m-') . str_pad((int)$card['closing_day'], 2, '0', STR_PAD_LEFT);
            $dueDt = (clone $today)->modify('+1 month');
            $due = $dueDt->format('Y-m-') . str_pad((int)$card['due_day'], 2, '0', STR_PAD_LEFT);
        }

        // Compute total from transactions
        $tx = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM om_credit_card_transactions
            WHERE card_id = ?
              AND status IN ('approved','pending')
              AND transaction_date >= ?
              AND transaction_date <= ?
        ");
        $tx->execute([(int)$card['id'], $ps . ' 00:00:00', $pe . ' 23:59:59']);
        $total = round((float)$tx->fetchColumn(), 2);

        // Add annual fee once per year (first bill in January)
        $score = (int)($card['score'] ?? 700);
        $annualFee = $brain->calculateAnnualFee($score);
        $addedFee = false;
        if ($annualFee > 0 && (int)date('m') === 1) {
            $feeCheck = $db->prepare("
                SELECT COUNT(*) FROM om_credit_card_revenue
                WHERE card_id = ? AND type = 'annual_fee'
                  AND created_at >= DATE_TRUNC('year', CURRENT_DATE)
            ");
            $feeCheck->execute([(int)$card['id']]);
            if ((int)$feeCheck->fetchColumn() === 0) {
                $total += $annualFee;
                $addedFee = true;
                $db->prepare("
                    INSERT INTO om_credit_card_revenue
                        (card_id, customer_id, type, amount, description)
                    VALUES (?, ?, 'annual_fee', ?, 'Anuidade')
                ")->execute([(int)$card['id'], (int)$card['customer_id'], $annualFee]);
            }
        }

        if ($total <= 0 && !$addedFee) { $skipped++; continue; }

        $minAmt = round($total * $minPct, 2);
        $ins = $db->prepare("
            INSERT INTO om_credit_card_bills
                (card_id, customer_id, period_start, period_end, due_date,
                 total_amount, minimum_amount, paid_amount, status, closed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'open', NOW())
            ON CONFLICT (card_id, period_start) DO UPDATE
                SET total_amount = EXCLUDED.total_amount,
                    minimum_amount = EXCLUDED.minimum_amount,
                    closed_at = COALESCE(om_credit_card_bills.closed_at, NOW())
            RETURNING id
        ");
        $ins->execute([
            (int)$card['id'], (int)$card['customer_id'], $ps, $pe, $due,
            $total, $minAmt,
        ]);
        $billId = (int)$ins->fetchColumn();
        $generated++;

        // Notify customer
        try {
            $sender = NotificationSender::getInstance($db);
            $sender->notifyCustomer((int)$card['customer_id'],
                'Sua fatura fechou!',
                sprintf('Fatura de R$ %s. Vence em %s. Pague pelo app.',
                    number_format($total, 2, ',', '.'),
                    date('d/m/Y', strtotime($due))),
                ['type' => 'bill_generated', 'bill_id' => $billId, 'total' => $total, 'due_date' => $due]
            );
        } catch (Exception $e) { /* ignore */ }
    } catch (Exception $e) {
        ll("error on card {$card['id']}: " . $e->getMessage());
        $errors++;
    }
}

ll("done: generated={$generated} skipped={$skipped} errors={$errors}");
flock($fp, LOCK_UN);
fclose($fp);
@unlink($lockFile);
exit(0);
