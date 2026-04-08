<?php
/**
 * GET/POST /api/mercado/customer/wallet-pay-boleto.php
 *
 * Pay a Brazilian boleto/conta using the SuperBora wallet balance.
 *
 * Flow (mobile-friendly two-step confirmation):
 *   1. POST action=consult { barcode } — Returns boleto details (amount, due
 *      date, beneficiary). The mobile screen shows this for the user to
 *      review BEFORE charging the wallet.
 *   2. POST action=pay { barcode, amount, transfer_id } — Locks wallet,
 *      debits, calls EFI to pay, marks status. Best-effort push notify.
 *
 * Provider routing (PIX_PROVIDER env, defaults 'efi'):
 *   - efi:   uses EfiClient::payBarcode (requires Pagamento de Contas product)
 *   - asaas: uses AsaasClient (TODO when terms accepted)
 *
 * Auth: customer JWT.
 * Rate limit: 5 boleto payments per customer per day.
 */
require_once __DIR__ . '/../config/database.php';
require_once dirname(__DIR__, 3) . '/includes/classes/EfiClient.php';
require_once __DIR__ . '/../helpers/notify.php';
require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';

setCorsHeaders();

// Limits to prevent abuse
const BOLETO_MIN = 1.00;
const BOLETO_MAX = 5000.00;
const BOLETO_MAX_PER_DAY = 5;

try {
    $db = getDB();
    $customerId = requireCustomerAuth();
    ensureBoletoTables($db);

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        // Return wallet balance + recent boleto payments
        $balStmt = $db->prepare("SELECT balance FROM om_cashback_wallet WHERE customer_id = ?");
        $balStmt->execute([$customerId]);
        $balance = round((float)($balStmt->fetchColumn() ?: 0), 2);

        $histStmt = $db->prepare("
            SELECT id, barcode_masked, amount, beneficiary_name, status, created_at, paid_at
            FROM om_wallet_boleto_payments
            WHERE customer_id = ?
            ORDER BY id DESC LIMIT 20
        ");
        $histStmt->execute([$customerId]);
        $history = $histStmt->fetchAll(PDO::FETCH_ASSOC);

        // Today's count (for rate limit display)
        $todayStmt = $db->prepare("
            SELECT COUNT(*) FROM om_wallet_boleto_payments
            WHERE customer_id = ? AND created_at >= CURRENT_DATE
        ");
        $todayStmt->execute([$customerId]);
        $todayCount = (int)$todayStmt->fetchColumn();

        response(true, [
            'balance'         => $balance,
            'min'             => BOLETO_MIN,
            'max'             => BOLETO_MAX,
            'max_per_day'     => BOLETO_MAX_PER_DAY,
            'paid_today'      => $todayCount,
            'remaining_today' => max(0, BOLETO_MAX_PER_DAY - $todayCount),
            'history'         => $history,
        ]);
    }

    if ($method !== 'POST') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    // ─────────────────────────────────────────────────────────────
    // ACTION: consult — preview boleto info before charging
    // ─────────────────────────────────────────────────────────────
    if ($action === 'consult') {
        $barcode = preg_replace('/\D/', '', (string)($input['barcode'] ?? ''));
        if (!in_array(strlen($barcode), [44, 47, 48], true)) {
            response(false, null, 'Codigo de barras invalido (44 ou 47 digitos)', 400);
        }

        if (function_exists('checkRateLimit')) {
            checkRateLimit("boleto_consult:{$customerId}", 20, 60);
        }

        $efi = new EfiClient();
        $r = $efi->consultBarcode($barcode);
        if (!$r['success']) {
            response(false, null, $r['error'] ?? 'Falha ao consultar boleto', 503);
        }

        // Mask the barcode for the response (show only first 4 + last 4 digits)
        $masked = substr($barcode, 0, 4) . str_repeat('*', strlen($barcode) - 8) . substr($barcode, -4);

        response(true, [
            'type'              => $r['type'],
            'amount'            => $r['amount'],
            'due_date'          => $r['due_date'],
            'limit_date'        => $r['limit_date'],
            'beneficiary_name'  => $r['beneficiary_name'],
            'beneficiary_doc'   => $r['beneficiary_doc'],
            'allow_change'      => $r['allow_change'],
            'barcode_masked'    => $masked,
            'barcode'           => $barcode, // returned so the client passes it back unchanged
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // ACTION: pay — debit wallet + send to EFI
    // ─────────────────────────────────────────────────────────────
    if ($action === 'pay') {
        $barcode = preg_replace('/\D/', '', (string)($input['barcode'] ?? ''));
        $amount  = round((float)($input['amount'] ?? 0), 2);

        if (!in_array(strlen($barcode), [44, 47, 48], true)) {
            response(false, null, 'Codigo de barras invalido', 400);
        }
        if ($amount < BOLETO_MIN || $amount > BOLETO_MAX) {
            response(false, null, 'Valor fora dos limites permitidos', 400);
        }

        if (function_exists('checkRateLimit')) {
            checkRateLimit("boleto_pay:{$customerId}", BOLETO_MAX_PER_DAY, 86400);
        }

        // Daily limit (defense in depth on top of rate limiter)
        $todayStmt = $db->prepare("
            SELECT COUNT(*) FROM om_wallet_boleto_payments
            WHERE customer_id = ? AND created_at >= CURRENT_DATE
        ");
        $todayStmt->execute([$customerId]);
        if ((int)$todayStmt->fetchColumn() >= BOLETO_MAX_PER_DAY) {
            response(false, null, 'Limite de ' . BOLETO_MAX_PER_DAY . ' pagamentos por dia atingido', 429);
        }

        // 1. Debit wallet inside a transaction
        $db->beginTransaction();
        try {
            $stmtLock = $db->prepare("SELECT balance FROM om_cashback_wallet WHERE customer_id = ? FOR UPDATE");
            $stmtLock->execute([$customerId]);
            $balance = round((float)($stmtLock->fetchColumn() ?: 0), 2);

            if ($amount > $balance) {
                $db->rollBack();
                response(false, null, 'Saldo insuficiente. Disponivel: R$ ' . number_format($balance, 2, ',', '.'), 400);
            }

            $stmtDebit = $db->prepare("
                UPDATE om_cashback_wallet
                SET balance = balance - ?, total_used = total_used + ?, updated_at = NOW()
                WHERE customer_id = ? AND balance >= ?
            ");
            $stmtDebit->execute([$amount, $amount, $customerId, $amount]);
            if ($stmtDebit->rowCount() === 0) {
                $db->rollBack();
                response(false, null, 'Saldo insuficiente', 400);
            }

            // New balance for the response
            $newBalStmt = $db->prepare("SELECT balance FROM om_cashback_wallet WHERE customer_id = ?");
            $newBalStmt->execute([$customerId]);
            $newBalance = round((float)$newBalStmt->fetchColumn(), 2);

            // Insert pending payment record + cashback transaction
            $masked = substr($barcode, 0, 4) . str_repeat('*', strlen($barcode) - 8) . substr($barcode, -4);
            $payStmt = $db->prepare("
                INSERT INTO om_wallet_boleto_payments
                    (customer_id, barcode, barcode_masked, amount, status, created_at)
                VALUES (?, ?, ?, ?, 'pending', NOW())
                RETURNING id
            ");
            $payStmt->execute([$customerId, $barcode, $masked, $amount]);
            $paymentId = (int)$payStmt->fetchColumn();

            $db->prepare("
                INSERT INTO om_cashback_transactions (customer_id, type, amount, balance_after, description)
                VALUES (?, 'debit', ?, ?, ?)
            ")->execute([$customerId, $amount, $newBalance, "Pagamento de boleto - {$masked}"]);

            $db->commit();
        } catch (\Exception $dbErr) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[wallet-pay-boleto] DB error: ' . $dbErr->getMessage());
            response(false, null, 'Erro ao reservar saldo', 500);
        }

        // 2. Try EFI barcode payment OUTSIDE transaction
        $payResult = null;
        $errorMsg  = null;
        $providerPaymentId = null;
        $finalStatus = 'failed';

        try {
            $efi = new EfiClient();
            $r = $efi->payBarcode($barcode, $amount, "SuperBora #{$paymentId}");
            if ($r['success']) {
                $payResult = $r;
                $providerPaymentId = $r['payment_id'];
                $finalStatus = strtolower($r['status'] ?? 'em_processamento') === 'confirmado'
                    ? 'paid'
                    : 'processing';
            } else {
                $errorMsg = $r['error'] ?? 'Falha desconhecida';
            }
        } catch (\Exception $efiErr) {
            $errorMsg = $efiErr->getMessage();
        }

        // 3. Update payment record with the EFI outcome
        if ($payResult) {
            $db->prepare("
                UPDATE om_wallet_boleto_payments
                SET status = ?, provider_payment_id = ?, processed_at = NOW(), beneficiary_name = COALESCE(beneficiary_name, '')
                WHERE id = ?
            ")->execute([$finalStatus, $providerPaymentId, $paymentId]);

            // Push success notification (best-effort)
            $amountStr = 'R$ ' . number_format($amount, 2, ',', '.');
            try {
                notifyCustomer($db, $customerId, 'Boleto enviado pra pagamento ✅',
                    "{$amountStr} debitado da carteira. Boleto sera quitado em alguns minutos.",
                    '/carteira',
                    [
                        'type'         => 'boleto_paid',
                        'amount'       => $amount,
                        'payment_id'   => $paymentId,
                        'route'        => '/carteira',
                    ]
                );
            } catch (\Exception $e) { error_log('[wallet-pay-boleto] push: ' . $e->getMessage()); }

            try {
                if (function_exists('wsBroadcastToCustomer')) {
                    wsBroadcastToCustomer($customerId, 'boleto_paid', [
                        'payment_id' => $paymentId,
                        'amount'     => $amount,
                        'status'     => $finalStatus,
                        'new_balance' => $newBalance,
                    ]);
                }
            } catch (\Exception $e) {}

            response(true, [
                'payment_id'  => $paymentId,
                'status'      => $finalStatus,
                'amount'      => $amount,
                'new_balance' => $newBalance,
                'provider_payment_id' => $providerPaymentId,
                'estimated_time'      => 'Alguns minutos',
            ]);
        }

        // EFI failed → refund wallet
        try {
            $db->beginTransaction();
            $db->prepare("
                UPDATE om_cashback_wallet
                SET balance = balance + ?, total_used = GREATEST(total_used - ?, 0), updated_at = NOW()
                WHERE customer_id = ?
            ")->execute([$amount, $amount, $customerId]);
            $refBal = $db->prepare("SELECT balance FROM om_cashback_wallet WHERE customer_id = ?");
            $refBal->execute([$customerId]);
            $refBalance = round((float)$refBal->fetchColumn(), 2);
            $db->prepare("
                INSERT INTO om_cashback_transactions (customer_id, type, amount, balance_after, description)
                VALUES (?, 'credit', ?, ?, ?)
            ")->execute([$customerId, $amount, $refBalance, "Estorno - boleto nao pago"]);
            $db->prepare("
                UPDATE om_wallet_boleto_payments
                SET status = 'failed', error_message = ?, processed_at = NOW()
                WHERE id = ?
            ")->execute([substr($errorMsg ?? 'Erro desconhecido', 0, 250), $paymentId]);
            $db->commit();
        } catch (\Exception $refundErr) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[wallet-pay-boleto] CRITICAL refund failed: ' . $refundErr->getMessage());
        }

        response(false, null, $errorMsg ?? 'Falha ao pagar boleto', 503);
    }

    response(false, null, 'Acao desconhecida', 400);

} catch (\Exception $e) {
    error_log('[wallet-pay-boleto] ' . $e->getMessage());
    response(false, null, 'Erro ao processar', 500);
}

// ────────────────────────────────────────────────────────────────────────────

function ensureBoletoTables(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_wallet_boleto_payments (
            id SERIAL PRIMARY KEY,
            customer_id INTEGER NOT NULL,
            barcode VARCHAR(48) NOT NULL,
            barcode_masked VARCHAR(64),
            amount NUMERIC(10,2) NOT NULL,
            beneficiary_name VARCHAR(255),
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            provider VARCHAR(20) DEFAULT 'efi',
            provider_payment_id VARCHAR(100),
            error_message TEXT,
            created_at TIMESTAMP DEFAULT NOW(),
            processed_at TIMESTAMP,
            paid_at TIMESTAMP
        )
    ");
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_boleto_pay_customer
        ON om_wallet_boleto_payments(customer_id, created_at DESC)
    ");
}
