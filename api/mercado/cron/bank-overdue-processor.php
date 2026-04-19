<?php
/**
 * ============================================================================
 * CRON: bank-overdue-processor.php (runs daily at 8 AM)
 * ============================================================================
 * Schedule: 0 8 * * *
 *
 * Processes overdue credit card bills:
 *   - day 1 after due:  send reminder notification, apply policy late_fee_pct
 *                       ONCE, start accruing daily interest
 *   - day 5, 15, 30:    escalating notifications
 *   - day 60:           auto-block the card
 *   - day 90:           mock-report to credit bureau (om_credit_blocklist entry)
 *
 * Idempotency: each reminder/action is logged via om_bank_triggers_log so it
 * fires only once per bill per milestone.
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

$lockFile = sys_get_temp_dir() . '/superbora_bank_overdue.lock';
$fp = fopen($lockFile, 'w');
if (!flock($fp, LOCK_EX | LOCK_NB)) exit(0);

function ll(string $m): void { fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] [overdue] ' . $m . "\n"); }

$db = getDB();
$brain = new SuperBoraBankBrain($db);
$policy = $brain->getPolicy();
$sender = NotificationSender::getInstance($db);

$lateFeePct = (float)$policy['late_fee_pct'] / 100.0;
$dailyRate  = (float)$policy['daily_late_interest_pct'] / 100.0;
$autoBlockDays = (int)$policy['auto_block_overdue_days'];
$bureauDays    = (int)$policy['credit_bureau_days'];

ll('processing overdue bills');

$stmt = $db->query("
    SELECT b.*, c.status AS card_status, c.customer_id
    FROM om_credit_card_bills b
    JOIN om_credit_cards c ON c.id = b.card_id
    WHERE b.status IN ('open','partial')
      AND b.due_date < CURRENT_DATE
");
$bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
ll('found ' . count($bills) . ' overdue bills');

$notifications = 0; $lateFees = 0; $interestCharges = 0; $autoBlocked = 0; $bureauReports = 0;

foreach ($bills as $b) {
    try {
        $billId = (int)$b['id'];
        $cardId = (int)$b['card_id'];
        $custId = (int)$b['customer_id'];
        $dueDate = new DateTime($b['due_date']);
        $today = new DateTime('today');
        $daysOverdue = (int)$dueDate->diff($today)->days;
        $outstanding = round((float)$b['total_amount'] - (float)$b['paid_amount'], 2);
        if ($outstanding <= 0) continue;

        // Apply late fee ONCE per bill
        $feeKey = 'late_fee_' . $billId;
        if (bankTriggers_once($db, $custId, $feeKey, ['bill_id' => $billId])) {
            $fee = round($outstanding * $lateFeePct, 2);
            if ($fee > 0) {
                $db->prepare("
                    UPDATE om_credit_card_bills SET total_amount = total_amount + ? WHERE id = ?
                ")->execute([$fee, $billId]);
                $db->prepare("
                    INSERT INTO om_credit_card_revenue
                        (card_id, bill_id, customer_id, type, amount, description)
                    VALUES (?, ?, ?, 'late_fee', ?, 'Multa por atraso')
                ")->execute([$cardId, $billId, $custId, $fee]);
                $lateFees++;
            }
        }

        // Daily interest (once per day per bill)
        $intKey = 'interest_' . $billId . '_' . date('Y_m_d');
        if (bankTriggers_once($db, $custId, $intKey, ['bill_id' => $billId])) {
            $interest = round($outstanding * $dailyRate, 2);
            if ($interest > 0) {
                $db->prepare("
                    UPDATE om_credit_card_bills SET total_amount = total_amount + ? WHERE id = ?
                ")->execute([$interest, $billId]);
                $db->prepare("
                    INSERT INTO om_credit_card_revenue
                        (card_id, bill_id, customer_id, type, amount, description)
                    VALUES (?, ?, ?, 'interest', ?, 'Juros diarios')
                ")->execute([$cardId, $billId, $custId, $interest]);
                $interestCharges++;
            }
        }

        // Escalating reminders (day 1, 5, 15, 30)
        $milestones = [1, 5, 15, 30];
        foreach ($milestones as $m) {
            if ($daysOverdue !== $m) continue;
            $key = 'overdue_notify_' . $billId . '_d' . $m;
            if (!bankTriggers_once($db, $custId, $key, ['bill_id' => $billId])) continue;

            $title = 'Fatura em atraso';
            $msg = sprintf('Sua fatura de R$ %s esta atrasada ha %d dia%s. Evite juros maiores.',
                number_format($outstanding, 2, ',', '.'), $m, $m > 1 ? 's' : '');
            if ($m >= 15) $msg .= ' Seu limite pode ser reduzido.';
            if ($m >= 30) {
                $title = 'URGENTE: Fatura atrasada 30 dias';
                $msg = sprintf('Fatura de R$ %s atrasada ha 30 dias. Se nao pagar em 30 dias, o cartao sera bloqueado.',
                    number_format($outstanding, 2, ',', '.'));
            }
            try {
                $sender->notifyCustomer($custId, $title, $msg, [
                    'type' => 'bill_overdue', 'bill_id' => $billId, 'days_overdue' => $m,
                ]);
                $notifications++;
            } catch (Exception $e) { /* ignore */ }
        }

        // Auto-block at 60 days
        if ($daysOverdue >= $autoBlockDays && $b['card_status'] === 'active') {
            if ($brain->autoBlockCard($cardId, "Fatura atrasada {$daysOverdue} dias", 'default_risk')) {
                $autoBlocked++;
            }
        }

        // Mock credit bureau report at 90 days
        if ($daysOverdue >= $bureauDays) {
            $key = 'bureau_report_' . $billId;
            if (bankTriggers_once($db, $custId, $key, ['bill_id' => $billId])) {
                try {
                    $cpfRow = $db->prepare("SELECT cpf FROM om_customers WHERE customer_id = ?");
                    $cpfRow->execute([$custId]);
                    $cpf = preg_replace('/\D/', '', (string)($cpfRow->fetchColumn() ?? ''));
                    if (strlen($cpf) === 11) {
                        $db->prepare("
                            INSERT INTO om_credit_blocklist (cpf, reason, added_at)
                            VALUES (?, ?, NOW())
                            ON CONFLICT (cpf) DO UPDATE SET reason = EXCLUDED.reason
                        ")->execute([$cpf, "Inadimplencia fatura {$billId} ({$daysOverdue} dias)"]);
                        $bureauReports++;
                        ll("bureau reported customer {$custId} (cpf {$cpf})");
                    }
                } catch (Exception $e) { ll('bureau report failed: ' . $e->getMessage()); }
            }
        }
    } catch (Exception $e) {
        ll('error on bill: ' . $e->getMessage());
    }
}

ll(sprintf('done: notify=%d late_fees=%d interest=%d auto_blocked=%d bureau=%d',
    $notifications, $lateFees, $interestCharges, $autoBlocked, $bureauReports));

flock($fp, LOCK_UN);
fclose($fp);
@unlink($lockFile);
exit(0);
