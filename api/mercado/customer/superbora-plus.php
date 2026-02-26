<?php
/**
 * /api/mercado/customer/superbora-plus.php
 * SuperBora+ — Plano unico R$4,90/mes
 *
 * GET  — Status da assinatura + beneficios ativos
 * POST — Assinar / Cancelar
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/rate-limit.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmPricing.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmDailyBudget.php";
setCorsHeaders();

try {
    $db = getDB();
    $customer_id = requireCustomerAuth();

    // Rate limiting: 5 subscribe actions per hour per customer (POST only)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!checkRateLimit("superbora_plus_c{$customer_id}", 5, 60)) {
            response(false, null, "Muitas requisicoes. Tente novamente em 1 hora.", 429);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // ═══════════════════════════════════════════════════════
        // GET — Status da assinatura
        // ═══════════════════════════════════════════════════════
        $stmt = $db->prepare("
            SELECT id, customer_id, status, started_at, expires_at, payment_method, payment_ref, cancelled_at FROM om_superbora_plus WHERE customer_id = ? LIMIT 1
        ");
        $stmt->execute([$customer_id]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);

        $isMember = $sub && $sub['status'] === 'active' && strtotime($sub['expires_at']) > time();

        // Beneficios fixos
        $fixedBenefits = [
            '10% desconto no frete BoraUm',
            '5% desconto em retirada',
            '1.5x pontos em todas as compras',
            'Acesso antecipado a promocoes',
            'Ofertas exclusivas',
        ];

        // Beneficios variaveis ativos agora
        $variableBenefits = [];
        if ($isMember) {
            $budget = OmDailyBudget::getInstance()->setDb($db);
            $modo = $budget->getModo();

            if ($modo === 'agressivo') {
                $variableBenefits[] = [
                    'type' => 'frete_gratis',
                    'description' => 'Frete gratis disponivel agora!',
                ];
            }
            if (in_array($modo, ['agressivo', 'normal'])) {
                $variableBenefits[] = [
                    'type' => 'frete_50',
                    'description' => '50% de desconto no frete!',
                ];
            }
        }

        // Economia do mes
        $savingsThisMonth = 0;
        if ($isMember) {
            try {
                $stmt = $db->prepare("
                    SELECT COALESCE(SUM(
                        CASE WHEN delivery_fee = 0 THEN (SELECT delivery_fee FROM om_market_partners WHERE partner_id = o.partner_id)
                        ELSE 0 END
                    ), 0) as savings
                    FROM om_market_orders o
                    WHERE customer_id = ?
                    AND date_added >= DATE_TRUNC('month', CURRENT_DATE)
                    AND status IN ('entregue','confirmado','em_entrega','coletando','aceito')
                ");
                $stmt->execute([$customer_id]);
                $savingsThisMonth = round((float)$stmt->fetchColumn(), 2);
            } catch (Exception $e) {
                // ignore
            }
        }

        response(true, [
            'is_member' => $isMember,
            'plan_price' => OmPricing::SUPERBORA_PLUS_PRECO,
            'status' => $sub['status'] ?? 'none',
            'started_at' => $sub['started_at'] ?? null,
            'expires_at' => $sub['expires_at'] ?? null,
            'fixed_benefits' => $fixedBenefits,
            'active_variable_benefits' => $variableBenefits,
            'savings_this_month' => $savingsThisMonth,
        ]);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // ═══════════════════════════════════════════════════════
        // POST — Assinar ou Cancelar
        // ═══════════════════════════════════════════════════════
        $input = json_decode(file_get_contents("php://input"), true) ?: $_POST;
        $action = $input['action'] ?? 'subscribe'; // subscribe, cancel

        if ($action === 'subscribe') {
            // Checar se ja tem assinatura ativa
            $stmt = $db->prepare("SELECT id, customer_id, status, started_at, expires_at, payment_method, payment_ref, cancelled_at FROM om_superbora_plus WHERE customer_id = ? LIMIT 1");
            $stmt->execute([$customer_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing && $existing['status'] === 'active' && strtotime($existing['expires_at']) > time()) {
                response(false, null, "Voce ja e membro SuperBora+!", 400);
            }

            $paymentMethod = $input['payment_method'] ?? 'pix';

            // ── PAYMENT VERIFICATION ─────────────────────────────────
            // Require proof of payment before activating subscription.
            // Accepted methods: stripe (payment_intent), pix (confirmed webhook),
            // or cashback (sufficient balance deducted atomically).
            // ──────────────────────────────────────────────────────────

            // Track whether we already have an open transaction (cashback path)
            $inTransaction = false;

            if ($paymentMethod === 'stripe') {
                // Verify Stripe PaymentIntent
                $paymentIntentId = trim($input['payment_intent_id'] ?? '');
                if (empty($paymentIntentId) || !preg_match('/^pi_/', $paymentIntentId)) {
                    response(false, null, "payment_intent_id invalido", 400);
                }

                $stripeSecret = getenv('STRIPE_SECRET_KEY') ?: ($_ENV['STRIPE_SECRET_KEY'] ?? '');
                if (empty($stripeSecret)) {
                    error_log("[superbora-plus] STRIPE_SECRET_KEY nao configurada");
                    response(false, null, "Erro de configuracao de pagamento", 500);
                }

                $ch = curl_init("https://api.stripe.com/v1/payment_intents/" . urlencode($paymentIntentId));
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_USERPWD => $stripeSecret . ':',
                    CURLOPT_TIMEOUT => 10,
                ]);
                $stripeResp = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 200) {
                    response(false, null, "Falha ao verificar pagamento Stripe", 400);
                }

                $pi = json_decode($stripeResp, true);
                if (($pi['status'] ?? '') !== 'succeeded') {
                    response(false, null, "Pagamento nao confirmado (status: " . ($pi['status'] ?? 'unknown') . ")", 400);
                }

                // Verify amount matches plan price (in centavos)
                $expectedAmount = (int)round(OmPricing::SUPERBORA_PLUS_PRECO * 100);
                if ((int)($pi['amount'] ?? 0) < $expectedAmount) {
                    response(false, null, "Valor do pagamento incorreto", 400);
                }

                // FIX 1a: Verify Stripe PI metadata has matching customer_id (ownership check)
                $piCustomerId = (int)($pi['metadata']['customer_id'] ?? 0);
                if ($piCustomerId !== $customer_id) {
                    error_log("[superbora-plus] Stripe PI customer_id mismatch: PI metadata={$piCustomerId}, auth={$customer_id}");
                    response(false, null, "Pagamento nao pertence a este cliente", 403);
                }

                // FIX 1b: Idempotency check inside transaction with FOR UPDATE
                $db->beginTransaction();
                $inTransaction = true;

                // Lock existing row to prevent race condition
                $stmtLock = $db->prepare("SELECT id FROM om_superbora_plus WHERE customer_id = ? FOR UPDATE");
                $stmtLock->execute([$customer_id]);
                $existing = $stmtLock->fetch(PDO::FETCH_ASSOC); // refresh $existing inside lock

                $stmtUsed = $db->prepare("SELECT 1 FROM om_superbora_plus WHERE payment_ref = ? FOR UPDATE");
                $stmtUsed->execute([$paymentIntentId]);
                if ($stmtUsed->fetch()) {
                    $db->rollBack();
                    response(false, null, "Este pagamento ja foi utilizado", 400);
                }

                $paymentRef = $paymentIntentId;

            } elseif ($paymentMethod === 'pix') {
                // Verify PIX payment was confirmed via webhook
                $pixTxId = trim($input['pix_tx_id'] ?? $input['payment_ref'] ?? '');
                if (empty($pixTxId)) {
                    response(false, null, "pix_tx_id obrigatorio para pagamento PIX", 400);
                }

                // Check PIX was confirmed in our payments table
                $stmtPix = $db->prepare("
                    SELECT status, amount FROM om_pix_payments
                    WHERE tx_id = ? AND customer_id = ? AND status = 'confirmed'
                ");
                $stmtPix->execute([$pixTxId, $customer_id]);
                $pixPayment = $stmtPix->fetch(PDO::FETCH_ASSOC);

                if (!$pixPayment) {
                    response(false, null, "Pagamento PIX nao confirmado", 400);
                }

                $expectedAmount = OmPricing::SUPERBORA_PLUS_PRECO;
                if ((float)$pixPayment['amount'] < $expectedAmount) {
                    response(false, null, "Valor do pagamento PIX incorreto", 400);
                }

                // FIX 1b: Idempotency check inside transaction with FOR UPDATE
                $db->beginTransaction();
                $inTransaction = true;

                // Lock existing row to prevent race condition
                $stmtLock = $db->prepare("SELECT id FROM om_superbora_plus WHERE customer_id = ? FOR UPDATE");
                $stmtLock->execute([$customer_id]);
                $existing = $stmtLock->fetch(PDO::FETCH_ASSOC); // refresh $existing inside lock

                $stmtUsed = $db->prepare("SELECT 1 FROM om_superbora_plus WHERE payment_ref = ? FOR UPDATE");
                $stmtUsed->execute([$pixTxId]);
                if ($stmtUsed->fetch()) {
                    $db->rollBack();
                    response(false, null, "Este pagamento ja foi utilizado", 400);
                }

                $paymentRef = $pixTxId;

            } elseif ($paymentMethod === 'cashback') {
                // FIX 1c: Deduct cashback AND activate membership in same transaction
                $db->beginTransaction();
                $inTransaction = true;

                try {
                    // Lock existing membership row first
                    $stmtLock = $db->prepare("SELECT id FROM om_superbora_plus WHERE customer_id = ? FOR UPDATE");
                    $stmtLock->execute([$customer_id]);
                    $existing = $stmtLock->fetch(PDO::FETCH_ASSOC); // refresh $existing inside lock

                    $stmtBal = $db->prepare("
                        SELECT COALESCE(SUM(amount), 0) FROM om_cashback
                        WHERE customer_id = ? AND type IN ('earned','bonus') AND status = 'available'
                        AND (expires_at IS NULL OR expires_at > NOW())
                        FOR UPDATE
                    ");
                    $stmtBal->execute([$customer_id]);
                    $cbBalance = (float)$stmtBal->fetchColumn();

                    if ($cbBalance < OmPricing::SUPERBORA_PLUS_PRECO) {
                        $db->rollBack();
                        response(false, null, "Saldo de cashback insuficiente (R$" . number_format($cbBalance, 2, ',', '.') . ")", 400);
                    }

                    // Consume cashback (FIFO by expiry)
                    $remaining = OmPricing::SUPERBORA_PLUS_PRECO;
                    $stmtCbList = $db->prepare("
                        SELECT id, amount FROM om_cashback
                        WHERE customer_id = ? AND type IN ('earned','bonus') AND status = 'available'
                        AND (expires_at IS NULL OR expires_at > NOW())
                        ORDER BY expires_at ASC NULLS LAST
                        FOR UPDATE
                    ");
                    $stmtCbList->execute([$customer_id]);
                    foreach ($stmtCbList->fetchAll() as $cb) {
                        if ($remaining <= 0) break;
                        $use = min($remaining, (float)$cb['amount']);
                        if (round($use, 2) >= round((float)$cb['amount'], 2)) {
                            $db->prepare("UPDATE om_cashback SET status = 'used' WHERE id = ?")->execute([$cb['id']]);
                        } else {
                            $db->prepare("UPDATE om_cashback SET amount = amount - ? WHERE id = ?")->execute([$use, $cb['id']]);
                        }
                        $remaining -= $use;
                    }

                    $paymentRef = 'cashback_' . $customer_id . '_' . time();
                    // NOTE: Do NOT commit here — activation happens below inside this same transaction
                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }

            } else {
                response(false, null, "Metodo de pagamento invalido. Use: stripe, pix ou cashback", 400);
            }

            // ── ACTIVATE SUBSCRIPTION (inside open transaction) ──────
            try {
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 month'));

                if ($existing) {
                    $db->prepare("
                        UPDATE om_superbora_plus
                        SET status = 'active', started_at = NOW(), expires_at = ?, payment_method = ?, payment_ref = ?, cancelled_at = NULL
                        WHERE customer_id = ?
                    ")->execute([$expiresAt, $paymentMethod, $paymentRef, $customer_id]);
                } else {
                    $db->prepare("
                        INSERT INTO om_superbora_plus (customer_id, status, started_at, expires_at, payment_method, payment_ref, created_at)
                        VALUES (?, 'active', NOW(), ?, ?, ?, NOW())
                    ")->execute([$customer_id, $expiresAt, $paymentMethod, $paymentRef]);
                }

                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }

            response(true, [
                'is_member' => true,
                'expires_at' => $expiresAt,
                'price' => OmPricing::SUPERBORA_PLUS_PRECO,
                'message' => 'Bem-vindo ao SuperBora+! Seus beneficios ja estao ativos.',
            ]);

        } elseif ($action === 'cancel') {
            $stmt = $db->prepare("
                UPDATE om_superbora_plus
                SET status = 'cancelled', cancelled_at = NOW()
                WHERE customer_id = ? AND status = 'active'
            ");
            $stmt->execute([$customer_id]);

            if ($stmt->rowCount() === 0) {
                response(false, null, "Nenhuma assinatura ativa encontrada", 404);
            }

            response(true, [
                'is_member' => false,
                'message' => 'Assinatura cancelada. Seus beneficios permanecem ate o fim do periodo.',
            ]);

        } else {
            response(false, null, "Acao invalida", 400);
        }

    } else {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[superbora-plus] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar solicitacao", 500);
}
