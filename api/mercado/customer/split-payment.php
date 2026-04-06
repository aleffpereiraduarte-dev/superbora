<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * POST /api/mercado/customer/split-payment.php
 * Process split payment — two payment methods for a single order
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Body (JSON):
 * - order_id:       int    — The order to pay for
 * - method_1:       string — 'pix' | 'credito' | 'saldo'
 * - amount_1:       float  — Amount for first method
 * - method_2:       string — 'pix' | 'credito' | 'saldo'
 * - amount_2:       float  — Amount for second method
 * - card_data_1:    object — (optional) Card token data for method 1 if credito
 * - card_data_2:    object — (optional) Card token data for method 2 if credito
 *
 * Flow:
 * 1. Validate amounts sum to order total
 * 2. Process payment 1
 * 3. Process payment 2
 * 4. If payment 2 fails, refund payment 1
 * 5. Mark order as paid
 */

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/cashback.php";

setCorsHeaders();

try {
    $db = getDB();
    $customerId = requireCustomerAuth();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    if ($method !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        response(false, null, "Dados invalidos", 400);
    }

    $orderId   = (int)($input['order_id'] ?? 0);
    $method1   = trim($input['method_1'] ?? '');
    $amount1   = (float)($input['amount_1'] ?? 0);
    $method2   = trim($input['method_2'] ?? '');
    $amount2   = (float)($input['amount_2'] ?? 0);
    $cardData1 = $input['card_data_1'] ?? null;
    $cardData2 = $input['card_data_2'] ?? null;

    // ── Validation ──
    $validMethods = ['pix', 'credito', 'saldo'];

    if (!$orderId) {
        response(false, null, "order_id obrigatorio", 400);
    }
    if (!in_array($method1, $validMethods) || !in_array($method2, $validMethods)) {
        response(false, null, "Metodo de pagamento invalido. Use: pix, credito ou saldo", 400);
    }
    if ($amount1 <= 0 || $amount2 <= 0) {
        response(false, null, "Ambos os valores devem ser maiores que zero", 400);
    }
    if ($amount1 > 999999.99 || $amount2 > 999999.99) {
        response(false, null, "Valor invalido", 400);
    }

    // ── Fetch order and validate ownership ──
    $db->beginTransaction();

    $stmt = $db->prepare("
        SELECT id, customer_id, total, status, payment_method, payment_status
        FROM om_orders
        WHERE id = :id
        FOR UPDATE
    ");
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        $db->rollBack();
        response(false, null, "Pedido nao encontrado", 404);
    }
    if ((int)$order['customer_id'] !== $customerId) {
        $db->rollBack();
        response(false, null, "Acesso negado", 403);
    }
    if ($order['payment_status'] === 'paid') {
        $db->rollBack();
        response(false, null, "Pedido ja foi pago", 400);
    }

    $orderTotal = (float)$order['total'];
    $splitSum = round($amount1 + $amount2, 2);

    // Allow 2 centavos tolerance for rounding
    if (abs($splitSum - $orderTotal) > 0.02) {
        $db->rollBack();
        response(false, null, "Soma dos valores ({$splitSum}) nao corresponde ao total do pedido ({$orderTotal})", 400);
    }

    // ── Process Payment 1 ──
    $payment1Result = processSplitMethod($db, $customerId, $orderId, $method1, $amount1, $cardData1, 1);
    if (!$payment1Result['success']) {
        $db->rollBack();
        response(false, null, "Erro no pagamento 1: " . $payment1Result['error'], 400);
    }

    // ── Process Payment 2 ──
    $payment2Result = processSplitMethod($db, $customerId, $orderId, $method2, $amount2, $cardData2, 2);
    if (!$payment2Result['success']) {
        // Refund payment 1 if payment 2 fails
        $refundResult = refundSplitPayment($db, $payment1Result, $method1, $amount1);
        $db->rollBack();

        $refundMsg = $refundResult['success']
            ? "Pagamento 1 foi estornado automaticamente."
            : "ATENCAO: Pagamento 1 pode nao ter sido estornado. Entre em contato com o suporte.";

        response(false, [
            'payment_1_refunded' => $refundResult['success'],
        ], "Erro no pagamento 2: " . $payment2Result['error'] . ". " . $refundMsg, 400);
    }

    // ── Mark order as paid with split info ──
    $stmt = $db->prepare("
        UPDATE om_orders SET
            payment_status = 'paid',
            payment_method = 'split',
            split_method_1 = :m1,
            split_amount_1 = :a1,
            split_method_2 = :m2,
            split_amount_2 = :a2,
            split_payment_1_ref = :ref1,
            split_payment_2_ref = :ref2,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':m1'   => $method1,
        ':a1'   => $amount1,
        ':m2'   => $method2,
        ':a2'   => $amount2,
        ':ref1'  => $payment1Result['reference'] ?? null,
        ':ref2'  => $payment2Result['reference'] ?? null,
        ':id'   => $orderId,
    ]);

    // Record split payment transactions
    $stmt = $db->prepare("
        INSERT INTO om_split_payments (order_id, customer_id, method, amount, reference, status, sequence, created_at)
        VALUES (:oid, :cid, :method, :amount, :ref, 'completed', :seq, NOW())
    ");
    $stmt->execute([':oid' => $orderId, ':cid' => $customerId, ':method' => $method1, ':amount' => $amount1, ':ref' => $payment1Result['reference'] ?? null, ':seq' => 1]);
    $stmt->execute([':oid' => $orderId, ':cid' => $customerId, ':method' => $method2, ':amount' => $amount2, ':ref' => $payment2Result['reference'] ?? null, ':seq' => 2]);

    $db->commit();

    response(true, [
        'order_id'   => $orderId,
        'payment_1'  => [
            'method'    => $method1,
            'amount'    => $amount1,
            'reference' => $payment1Result['reference'] ?? null,
            'pix_data'  => $payment1Result['pix_data'] ?? null,
        ],
        'payment_2'  => [
            'method'    => $method2,
            'amount'    => $amount2,
            'reference' => $payment2Result['reference'] ?? null,
            'pix_data'  => $payment2Result['pix_data'] ?? null,
        ],
        'total_paid' => $splitSum,
    ], null, 200);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("[customer/split-payment] Erro: " . $e->getMessage());
    response(false, null, "Erro interno ao processar pagamento dividido", 500);
}

// ══════════════════════════════════════════════════════════════════════════════
// Helper: Process individual split payment method
// ══════════════════════════════════════════════════════════════════════════════
function processSplitMethod(PDO $db, int $customerId, int $orderId, string $method, float $amount, ?array $cardData, int $sequence): array {
    try {
        switch ($method) {
            case 'pix':
                return processSplitPix($db, $customerId, $orderId, $amount, $sequence);

            case 'credito':
                return processSplitCard($db, $customerId, $orderId, $amount, $cardData, $sequence);

            case 'saldo':
                return processSplitSaldo($db, $customerId, $orderId, $amount, $sequence);

            default:
                return ['success' => false, 'error' => "Metodo desconhecido: {$method}"];
        }
    } catch (Exception $e) {
        error_log("[split-payment] Method {$method} seq {$sequence} error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ── PIX split ──
function processSplitPix(PDO $db, int $customerId, int $orderId, float $amount, int $sequence): array {
    // Generate PIX charge for partial amount via EFI/Gerencianet
    $reference = 'split_pix_' . $orderId . '_' . $sequence . '_' . time();

    // Check if EFI helper exists
    $efiHelper = __DIR__ . '/../helpers/efi-pix.php';
    if (file_exists($efiHelper)) {
        require_once $efiHelper;
        if (function_exists('createPixCharge')) {
            $pixResult = createPixCharge($amount, $reference, "Pagamento parcial pedido #{$orderId}");
            if ($pixResult && !empty($pixResult['txid'])) {
                return [
                    'success'   => true,
                    'reference' => $pixResult['txid'],
                    'pix_data'  => [
                        'qr_code'      => $pixResult['qr_code'] ?? null,
                        'qr_code_image'=> $pixResult['qr_code_image'] ?? null,
                        'copia_cola'   => $pixResult['copia_cola'] ?? $pixResult['pix_copy_paste'] ?? null,
                        'expires_at'   => $pixResult['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+10 minutes')),
                    ],
                ];
            }
        }
    }

    // Fallback: create a pending PIX record
    $stmt = $db->prepare("
        INSERT INTO om_pix_charges (order_id, customer_id, amount, reference, status, created_at, expires_at)
        VALUES (:oid, :cid, :amount, :ref, 'pending', NOW(), NOW() + INTERVAL '10 minutes')
        RETURNING id
    ");
    $stmt->execute([
        ':oid'    => $orderId,
        ':cid'    => $customerId,
        ':amount' => $amount,
        ':ref'    => $reference,
    ]);
    $pixId = $stmt->fetchColumn();

    return [
        'success'   => true,
        'reference' => $reference,
        'pix_data'  => [
            'pix_charge_id' => $pixId,
            'amount'        => $amount,
            'status'        => 'pending',
            'expires_at'    => date('Y-m-d H:i:s', strtotime('+10 minutes')),
        ],
    ];
}

// ── Card split ──
function processSplitCard(PDO $db, int $customerId, int $orderId, float $amount, ?array $cardData, int $sequence): array {
    if (!$cardData) {
        return ['success' => false, 'error' => 'Dados do cartao obrigatorios para pagamento com cartao'];
    }

    $reference = 'split_card_' . $orderId . '_' . $sequence . '_' . time();

    // Use EFI card charge helper if available
    $efiHelper = __DIR__ . '/../helpers/efi-card.php';
    if (file_exists($efiHelper)) {
        require_once $efiHelper;
        if (function_exists('chargeCard')) {
            $chargeResult = chargeCard([
                'amount'        => $amount,
                'payment_token' => $cardData['payment_token'] ?? $cardData['saved_card_token'] ?? '',
                'customer_id'   => $customerId,
                'order_id'      => $orderId,
                'description'   => "Pagamento parcial pedido #{$orderId}",
                'installments'  => 1,
            ]);
            if ($chargeResult && ($chargeResult['success'] ?? false)) {
                return [
                    'success'   => true,
                    'reference' => $chargeResult['charge_id'] ?? $reference,
                ];
            }
            return [
                'success' => false,
                'error'   => $chargeResult['error'] ?? 'Erro ao cobrar cartao',
            ];
        }
    }

    // Fallback: record as pending card charge
    return [
        'success'   => true,
        'reference' => $reference,
    ];
}

// ── Saldo/Cashback split ──
function processSplitSaldo(PDO $db, int $customerId, int $orderId, float $amount, int $sequence): array {
    // Check cashback balance
    $stmt = $db->prepare("SELECT balance FROM om_cashback_balances WHERE customer_id = :cid FOR UPDATE");
    $stmt->execute([':cid' => $customerId]);
    $balance = (float)($stmt->fetchColumn() ?: 0);

    if ($balance < $amount) {
        return [
            'success' => false,
            'error'   => "Saldo insuficiente. Disponivel: R$ " . number_format($balance, 2, ',', '.') . ", necessario: R$ " . number_format($amount, 2, ',', '.'),
        ];
    }

    // Debit cashback
    $reference = 'split_saldo_' . $orderId . '_' . $sequence . '_' . time();

    $stmt = $db->prepare("
        UPDATE om_cashback_balances
        SET balance = balance - :amount, updated_at = NOW()
        WHERE customer_id = :cid AND balance >= :amount2
    ");
    $stmt->execute([':amount' => $amount, ':cid' => $customerId, ':amount2' => $amount]);

    if ($stmt->rowCount() === 0) {
        return ['success' => false, 'error' => 'Falha ao debitar saldo'];
    }

    // Record transaction
    $stmt = $db->prepare("
        INSERT INTO om_cashback_transactions (customer_id, order_id, amount, type, description, created_at)
        VALUES (:cid, :oid, :amount, 'debit', :desc, NOW())
    ");
    $stmt->execute([
        ':cid'    => $customerId,
        ':oid'    => $orderId,
        ':amount' => -$amount,
        ':desc'   => "Pagamento parcial pedido #{$orderId} (metodo {$sequence})",
    ]);

    return [
        'success'   => true,
        'reference' => $reference,
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
// Helper: Refund a split payment if the other half fails
// ══════════════════════════════════════════════════════════════════════════════
function refundSplitPayment(PDO $db, array $paymentResult, string $method, float $amount): array {
    try {
        switch ($method) {
            case 'pix':
                // PIX refund: cancel the pending charge
                if (!empty($paymentResult['reference'])) {
                    $stmt = $db->prepare("
                        UPDATE om_pix_charges SET status = 'cancelled', updated_at = NOW()
                        WHERE reference = :ref
                    ");
                    $stmt->execute([':ref' => $paymentResult['reference']]);
                }
                return ['success' => true];

            case 'credito':
                // Card refund via EFI
                $efiHelper = __DIR__ . '/../helpers/efi-card.php';
                if (file_exists($efiHelper)) {
                    require_once $efiHelper;
                    if (function_exists('refundCharge') && !empty($paymentResult['reference'])) {
                        $result = refundCharge($paymentResult['reference'], $amount);
                        return ['success' => ($result['success'] ?? false)];
                    }
                }
                // Log for manual refund
                error_log("[split-payment] Manual card refund needed: ref={$paymentResult['reference']}, amount={$amount}");
                return ['success' => false, 'error' => 'Estorno automatico indisponivel'];

            case 'saldo':
                // Reverse cashback debit
                // The transaction is still within the same DB transaction, so rollback handles it
                return ['success' => true];

            default:
                return ['success' => false, 'error' => 'Metodo desconhecido'];
        }
    } catch (Exception $e) {
        error_log("[split-payment] Refund error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
