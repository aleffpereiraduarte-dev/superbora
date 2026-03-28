<?php
/**
 * POST /api/mercado/pedido/gorjeta.php
 * Post-delivery tip with Stripe payment
 *
 * Two flows:
 *   1. Create tip (returns Stripe PI client_secret for frontend confirmation)
 *      Body: { order_id, amount, payment_method: "stripe_card" }
 *
 *   2. Confirm tip (after frontend Stripe confirmation)
 *      Body: { action: "confirm", order_id, payment_intent_id }
 *
 * Also accepts legacy flow without Stripe (amount only, no payment_method)
 *   Body: { order_id, valor|amount, message? }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Nao autorizado", 401);
    $customerId = (int)$payload['uid'];

    $input = getInput();
    $action = trim($input['action'] ?? '');

    // ═══════════════════════════════════════════════════════
    // FLOW 2: Confirm Stripe payment after frontend confirmation
    // ═══════════════════════════════════════════════════════
    if ($action === 'confirm') {
        $orderId = (int)($input['order_id'] ?? 0);
        $paymentIntentId = trim($input['payment_intent_id'] ?? '');

        if (!$orderId) response(false, null, "order_id obrigatorio", 400);
        if (!$paymentIntentId || !preg_match('/^pi_[a-zA-Z0-9_]+$/', $paymentIntentId)) {
            response(false, null, "payment_intent_id invalido", 400);
        }

        $db->beginTransaction();
        try {
            // Lock order and verify ownership
            $stmt = $db->prepare("
                SELECT order_id, customer_id, status, tip_paid, tip_payment_id, post_tip_amount
                FROM om_market_orders
                WHERE order_id = ? AND customer_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$orderId, $customerId]);
            $order = $stmt->fetch();

            if (!$order) {
                $db->rollBack();
                response(false, null, "Pedido nao encontrado", 404);
            }

            // Already confirmed? Idempotent success
            if ($order['tip_paid'] && $order['tip_payment_id'] === $paymentIntentId) {
                $db->rollBack();
                $tipAmount = (float)($order['post_tip_amount'] ?? 0);
                response(true, [
                    'order_id' => $orderId,
                    'tip_amount' => $tipAmount,
                    'tip_formatted' => 'R$ ' . number_format($tipAmount, 2, ',', '.'),
                    'already_confirmed' => true,
                ], "Gorjeta ja confirmada");
            }

            // Verify Stripe PI is actually succeeded
            $stripeKeys = loadStripeKeysForTip();
            $secretKey = $stripeKeys['br'] ?: $stripeKeys['us'];
            if (!$secretKey) {
                $db->rollBack();
                response(false, null, "Stripe nao configurado", 503);
            }

            $piResult = checkStripePIForTip($paymentIntentId, $secretKey);
            if (!$piResult['found']) {
                // Try US key as fallback
                if (!empty($stripeKeys['us']) && $stripeKeys['us'] !== $secretKey) {
                    $piResult = checkStripePIForTip($paymentIntentId, $stripeKeys['us']);
                }
            }

            if (!$piResult['found'] || !$piResult['paid']) {
                $db->rollBack();
                $piStatus = $piResult['status'] ?? 'unknown';
                error_log("[Gorjeta] PI {$paymentIntentId} not succeeded: status={$piStatus}");
                response(false, null, "Pagamento ainda nao confirmado (status: {$piStatus}). Tente novamente.", 400);
            }

            $tipAmount = $piResult['amount']; // Amount in BRL from Stripe

            // Update order with confirmed tip
            $stmt = $db->prepare("
                UPDATE om_market_orders
                SET tip_paid = true,
                    tip_payment_id = ?,
                    post_tip_amount = ?,
                    post_tip_given = 1,
                    tip_amount = COALESCE(tip_amount, 0) + ?
                WHERE order_id = ?
            ");
            $stmt->execute([$paymentIntentId, $tipAmount, $tipAmount, $orderId]);

            // Log tip for BoraUm repasse if applicable
            logTipForBoraUm($db, $orderId, $customerId, $tipAmount, $paymentIntentId);

            // Also insert into om_post_delivery_tips if table exists
            try {
                $stmt = $db->prepare("
                    INSERT INTO om_post_delivery_tips
                    (order_id, customer_id, shopper_id, amount, message, status, paid_at)
                    SELECT ?, ?, shopper_id, ?, 'Gorjeta via Stripe', 'paid', NOW()
                    FROM om_market_orders WHERE order_id = ?
                ");
                $stmt->execute([$orderId, $customerId, $tipAmount, $orderId]);
            } catch (Exception $e) {
                // Table may not exist — tip already saved in order
                error_log("[gorjeta] om_post_delivery_tips insert error (ok): " . $e->getMessage());
            }

            $db->commit();

            response(true, [
                'order_id' => $orderId,
                'tip_amount' => $tipAmount,
                'tip_formatted' => 'R$ ' . number_format($tipAmount, 2, ',', '.'),
                'payment_confirmed' => true,
            ], "Gorjeta confirmada! 100% vai para o entregador.");
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        exit;
    }

    // ═══════════════════════════════════════════════════════
    // FLOW 1: Create tip (Stripe PI or legacy)
    // ═══════════════════════════════════════════════════════
    $orderId = (int)($input['order_id'] ?? 0);
    $amount = (float)($input['amount'] ?? $input['valor'] ?? 0);
    $message = strip_tags(trim($input['message'] ?? ''));
    $paymentMethod = trim($input['payment_method'] ?? '');

    if (!$orderId) response(false, null, "order_id obrigatorio", 400);
    if ($amount < 1) response(false, null, "Valor minimo de gorjeta: R$ 1,00", 400);
    if ($amount > 50) response(false, null, "Valor maximo de gorjeta: R$ 50,00", 400);

    $db->beginTransaction();
    try {
        // Lock order and verify ownership + status
        $stmt = $db->prepare("
            SELECT order_id, customer_id, status, tip_paid, post_tip_given, post_tip_amount, partner_id
            FROM om_market_orders
            WHERE order_id = ? AND customer_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$orderId, $customerId]);
        $order = $stmt->fetch();

        if (!$order) {
            $db->rollBack();
            response(false, null, "Pedido nao encontrado", 404);
        }

        // Only delivered orders can receive tips
        $allowedStatuses = ['entregue', 'delivered', 'retirado', 'concluido'];
        if (!in_array($order['status'], $allowedStatuses)) {
            $db->rollBack();
            response(false, null, "Gorjeta disponivel apenas apos a entrega", 400);
        }

        // Prevent duplicate tips
        if ($order['tip_paid'] || $order['post_tip_given']) {
            $db->rollBack();
            $existingAmount = (float)($order['post_tip_amount'] ?? 0);
            response(false, [
                'already_tipped' => true,
                'existing_amount' => $existingAmount,
                'existing_formatted' => 'R$ ' . number_format($existingAmount, 2, ',', '.'),
            ], "Gorjeta ja enviada para este pedido", 409);
        }

        // ── Stripe card payment flow ──
        if ($paymentMethod === 'stripe_card') {
            $stripeKeys = loadStripeKeysForTip();
            $secretKey = $stripeKeys['br'] ?: $stripeKeys['us'];
            if (!$secretKey) {
                $db->rollBack();
                response(false, null, "Stripe nao configurado", 503);
            }

            // Create PaymentIntent (requires frontend confirmation)
            $description = "Gorjeta pedido #{$orderId} - SuperBora";
            $piResult = createStripeTipPI($amount, $secretKey, $description);

            if (!$piResult || empty($piResult['client_secret'])) {
                $db->rollBack();
                $errMsg = $piResult['error']['message'] ?? 'Erro ao criar pagamento';
                error_log("[Gorjeta] Stripe PI create failed: " . json_encode($piResult));
                response(false, null, "Erro no pagamento: {$errMsg}", 502);
            }

            // Save pending tip state (not yet confirmed)
            $stmt = $db->prepare("
                UPDATE om_market_orders
                SET tip_message = ?
                WHERE order_id = ?
            ");
            $stmt->execute([$message, $orderId]);

            $db->commit();

            response(true, [
                'tip_amount' => $amount,
                'tip_formatted' => 'R$ ' . number_format($amount, 2, ',', '.'),
                'payment_intent_client_secret' => $piResult['client_secret'],
                'payment_intent_id' => $piResult['id'],
                'requires_payment' => true,
            ], "Confirme o pagamento no app");
            exit;
        }

        // ── Legacy flow (no Stripe, just record the tip) ──
        $stmt = $db->prepare("
            UPDATE om_market_orders
            SET tip_amount = COALESCE(tip_amount, 0) + ?,
                tip_message = ?,
                post_tip_amount = ?,
                post_tip_given = 1
            WHERE order_id = ?
        ");
        $stmt->execute([$amount, $message, $amount, $orderId]);

        // Insert into om_post_delivery_tips if table exists
        try {
            $stmt = $db->prepare("
                INSERT INTO om_post_delivery_tips
                (order_id, customer_id, shopper_id, amount, message, status, paid_at)
                SELECT ?, ?, shopper_id, ?, ?, 'paid', NOW()
                FROM om_market_orders WHERE order_id = ?
            ");
            $stmt->execute([$orderId, $customerId, $amount, $message ?: null, $orderId]);
        } catch (Exception $e) {
            error_log("[gorjeta] om_post_delivery_tips insert error (ok): " . $e->getMessage());
        }

        // Log for BoraUm repasse
        logTipForBoraUm($db, $orderId, $customerId, $amount, null);

        $db->commit();

        response(true, [
            'order_id' => $orderId,
            'tip_amount' => $amount,
            'tip_formatted' => 'R$ ' . number_format($amount, 2, ',', '.'),
            'requires_payment' => false,
        ], "Gorjeta enviada com sucesso!");

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[gorjeta] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar gorjeta", 500);
}

// ═══════════════════════════════════════════════════════
// Helper functions
// ═══════════════════════════════════════════════════════

/**
 * Load Stripe secret keys from .env.stripe
 */
function loadStripeKeysForTip(): array {
    $envFile = dirname(dirname(dirname(__DIR__))) . '/.env.stripe';
    $keys = ['br' => '', 'us' => ''];
    if (file_exists($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k); $v = trim($v);
                if ($k === 'STRIPE_SECRET_KEY') $keys['br'] = $v;
                if ($k === 'STRIPE_SECRET_KEY_US') $keys['us'] = $v;
            }
        }
    }
    return $keys;
}

/**
 * Create a Stripe PaymentIntent for the tip (requires frontend confirmation)
 */
function createStripeTipPI(float $amount, string $secretKey, string $description): ?array {
    $ch = curl_init("https://api.stripe.com/v1/payment_intents");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $secretKey],
        CURLOPT_POSTFIELDS => http_build_query([
            'amount' => (int)round($amount * 100),
            'currency' => 'brl',
            'description' => $description,
            'automatic_payment_methods[enabled]' => 'true',
            'automatic_payment_methods[allow_redirects]' => 'never',
        ]),
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 200 && $code < 300) {
        return json_decode($resp, true);
    }
    error_log("[Gorjeta] Stripe PI create HTTP {$code}: {$resp}");
    return json_decode($resp, true); // Return error body for message extraction
}

/**
 * Check a PaymentIntent status against a Stripe secret key
 */
function checkStripePIForTip(string $piId, string $secretKey): array {
    $piId = preg_replace('/[^a-zA-Z0-9_]/', '', $piId);
    $ch = curl_init("https://api.stripe.com/v1/payment_intents/{$piId}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $secretKey],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    $piStatus = $data['status'] ?? 'unknown';

    if ($httpCode === 200 && $piStatus === 'succeeded') {
        return [
            'found' => true,
            'paid' => true,
            'amount' => ($data['amount'] ?? 0) / 100,
            'status' => $piStatus,
        ];
    }
    if ($httpCode === 200) {
        return ['found' => true, 'paid' => false, 'status' => $piStatus];
    }
    return ['found' => false, 'paid' => false, 'status' => $piStatus];
}

/**
 * Log tip for BoraUm repasse if the order used BoraUm delivery
 */
function logTipForBoraUm(PDO $db, int $orderId, int $customerId, float $amount, ?string $paymentId): void {
    try {
        // Check if order has a BoraUm delivery
        $stmt = $db->prepare("
            SELECT boraum_delivery_id, driver_id
            FROM om_entregas
            WHERE referencia_id = ? AND origem_sistema = 'mercado'
            LIMIT 1
        ");
        $stmt->execute([$orderId]);
        $entrega = $stmt->fetch();

        if (!$entrega || empty($entrega['boraum_delivery_id'])) {
            return; // Not a BoraUm delivery
        }

        // Update om_entregas with tip info
        $stmt = $db->prepare("
            UPDATE om_entregas
            SET gorjeta = COALESCE(gorjeta, 0) + ?
            WHERE referencia_id = ? AND origem_sistema = 'mercado'
        ");
        $stmt->execute([$amount, $orderId]);

        error_log("[Gorjeta] BoraUm tip logged: order={$orderId}, amount={$amount}, delivery={$entrega['boraum_delivery_id']}, driver={$entrega['driver_id']}");
    } catch (Exception $e) {
        // Non-critical — log and continue
        error_log("[Gorjeta] BoraUm tip log error (non-critical): " . $e->getMessage());
    }
}
