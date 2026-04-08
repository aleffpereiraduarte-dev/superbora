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
require_once dirname(__DIR__, 3) . '/includes/classes/InterClient.php';
require_once dirname(__DIR__, 3) . '/includes/classes/AsaasClient.php';
require_once __DIR__ . '/../helpers/notify.php';
require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';

/**
 * Boleto payment provider cascade.
 * Inter is preferred (free for PJ correntistas), Asaas is the fallback
 * (paid but reliable), EFI is the legacy fallback.
 *
 * @return array {client, name} or null if no provider is configured
 */
function pickBoletoProvider() {
    $inter = new InterClient();
    if ($inter->isConfigured()) return ['client' => $inter, 'name' => 'inter'];

    $asaas = new AsaasClient();
    if ($asaas->isConfigured() && method_exists($asaas, 'consultBarcode')) return ['client' => $asaas, 'name' => 'asaas'];

    $efi = new EfiClient();
    if ($efi->isConfigured()) return ['client' => $efi, 'name' => 'efi'];

    return null;
}

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
        // Return wallet balance + recent boleto payments.
        // SOURCE OF TRUTH: om_cashback ledger (same as cashback.php).
        // Available = sum(earned/bonus available) - sum(used). Never read
        // om_cashback_wallet directly — that table is a denormalized aggregate
        // that drifts in production.
        $balance = readWalletBalance($db, $customerId);

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

        // Try providers in order: Inter → Asaas → EFI. First one that succeeds wins.
        $providers = [];
        $inter = new InterClient();
        if ($inter->isConfigured()) $providers[] = ['client' => $inter, 'name' => 'inter'];
        $asaas = new AsaasClient();
        if ($asaas->isConfigured() && method_exists($asaas, 'consultBarcode')) $providers[] = ['client' => $asaas, 'name' => 'asaas'];
        $efi = new EfiClient();
        if ($efi->isConfigured()) $providers[] = ['client' => $efi, 'name' => 'efi'];

        if (empty($providers)) {
            response(false, null, 'Nenhum provider de boleto configurado', 503);
        }

        $r = null; $usedProvider = null; $errors = [];
        foreach ($providers as $p) {
            $result = $p['client']->consultBarcode($barcode);
            if ($result['success']) {
                $r = $result;
                $usedProvider = $p['name'];
                break;
            }
            $errors[] = $p['name'] . ': ' . ($result['error'] ?? 'unknown');
        }

        if (!$r) {
            error_log('[wallet-pay-boleto] consult failed all providers: ' . implode(' | ', $errors));
            response(false, null, 'Nao conseguimos consultar esse boleto agora. Confira o codigo e tente em alguns instantes.', 503);
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
            'barcode'           => $barcode,
            'provider'          => $usedProvider,
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

        // 1. Debit wallet inside a transaction.
        // We use the om_cashback LEDGER as source of truth. Debiting means
        // inserting a row with type='used', which lowers the available
        // balance immediately on the next SELECT (same model as the rest of
        // the codebase: confirmar-entrega.php, processar.php).
        $db->beginTransaction();
        try {
            // Lock customer's cashback rows so a parallel transfer can't double-spend
            $db->prepare("SELECT id FROM om_cashback WHERE customer_id = ? FOR UPDATE")
                ->execute([$customerId]);

            // Recompute current balance under the lock
            $balance = readWalletBalance($db, $customerId);
            if ($amount > $balance) {
                $db->rollBack();
                response(false, null, 'Saldo insuficiente. Disponivel: R$ ' . number_format($balance, 2, ',', '.'), 400);
            }

            $masked = substr($barcode, 0, 4) . str_repeat('*', strlen($barcode) - 8) . substr($barcode, -4);

            // Insert the boleto payment record FIRST so we have an id for the ledger description
            $payStmt = $db->prepare("
                INSERT INTO om_wallet_boleto_payments
                    (customer_id, barcode, barcode_masked, amount, status, created_at)
                VALUES (?, ?, ?, ?, 'pending', NOW())
                RETURNING id
            ");
            $payStmt->execute([$customerId, $barcode, $masked, $amount]);
            $paymentId = (int)$payStmt->fetchColumn();

            // Debit by inserting a 'used' row in the cashback ledger
            $db->prepare("
                INSERT INTO om_cashback (customer_id, type, amount, status, description, order_id, created_at)
                VALUES (?, 'used', ?, 'used', ?, NULL, NOW())
            ")->execute([
                $customerId,
                $amount,
                "Pagamento de boleto #{$paymentId} - {$masked}",
            ]);

            // Recompute balance after the debit (for the response)
            $newBalance = readWalletBalance($db, $customerId);

            $db->commit();
        } catch (\Exception $dbErr) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[wallet-pay-boleto] DB error: ' . $dbErr->getMessage());
            response(false, null, 'Erro ao reservar saldo', 500);
        }

        // 2. Try providers in order: Inter → Asaas → EFI (OUTSIDE transaction)
        $payResult = null;
        $errorMsg  = null;
        $providerPaymentId = null;
        $providerUsed = null;
        $finalStatus = 'failed';

        $providers = [];
        $inter = new InterClient();
        if ($inter->isConfigured()) $providers[] = ['client' => $inter, 'name' => 'inter'];
        $asaas = new AsaasClient();
        if ($asaas->isConfigured() && method_exists($asaas, 'payBarcode')) $providers[] = ['client' => $asaas, 'name' => 'asaas'];
        $efi = new EfiClient();
        if ($efi->isConfigured()) $providers[] = ['client' => $efi, 'name' => 'efi'];

        $errors = [];
        foreach ($providers as $p) {
            try {
                $r = $p['client']->payBarcode($barcode, $amount, "SuperBora #{$paymentId}");
                if (!empty($r['success'])) {
                    $payResult = $r;
                    $providerUsed = $p['name'];
                    $providerPaymentId = $r['payment_id'] ?? null;
                    $statusUpper = strtoupper($r['status'] ?? '');
                    $finalStatus = in_array($statusUpper, ['CONFIRMADO', 'EFETUADO', 'CONCLUIDO', 'PAID', 'PAGO'], true)
                        ? 'paid'
                        : 'processing';
                    break;
                }
                $errors[] = $p['name'] . ': ' . ($r['error'] ?? 'unknown');
            } catch (\Exception $e) {
                $errors[] = $p['name'] . ': ' . $e->getMessage();
            }
        }

        if (!$payResult) {
            $errorMsg = 'Nenhum provider conseguiu pagar: ' . implode(' | ', $errors);
            error_log('[wallet-pay-boleto] ' . $errorMsg);
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

        // Provider failed → refund the wallet by inserting a 'bonus' credit
        // row in the cashback ledger that offsets the 'used' debit.
        try {
            $db->beginTransaction();
            $db->prepare("
                INSERT INTO om_cashback (customer_id, type, amount, status, description, order_id, created_at)
                VALUES (?, 'bonus', ?, 'available', ?, NULL, NOW())
            ")->execute([
                $customerId,
                $amount,
                "Estorno - boleto #{$paymentId} nao foi pago",
            ]);
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

/**
 * Read the customer's available wallet balance from the cashback ledger.
 * Mirrors what /customer/cashback.php does, so the carteira screen and the
 * boleto payment screen always agree on the balance.
 */
function readWalletBalance(PDO $db, int $customerId): float
{
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN type IN ('earned','bonus') AND status = 'available' THEN amount ELSE 0 END), 0)
              - COALESCE(SUM(CASE WHEN type = 'used' THEN amount ELSE 0 END), 0) AS available
        FROM om_cashback
        WHERE customer_id = ?
    ");
    $stmt->execute([$customerId]);
    return round(max(0, (float)($stmt->fetchColumn() ?: 0)), 2);
}

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
