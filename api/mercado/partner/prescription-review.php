<?php
/**
 * POST /api/mercado/partner/prescription-review.php
 *
 * Farmacêutico aprova ou rejeita a receita de um pedido.
 *
 * Body:
 *   {
 *     order_id: 123,
 *     action:   "approve" | "reject",
 *     reason:   "string" (obrigatório se action=reject)
 *   }
 *
 * Approve:
 *   - prescription_status='approved', prescription_reviewed_at=NOW(),
 *     prescription_reviewed_by=<partner_id>
 *   - status='confirmado' (continua fluxo normal de aceito → preparando)
 *   - Broadcast `prescription_approved` pro customer via WS
 *
 * Reject:
 *   - prescription_status='rejected',
 *     prescription_rejection_reason=<reason>
 *   - status='cancelado', cancelled_at=NOW(), cancel_reason=<reason>,
 *     cancelled_by='farmacia'
 *   - Estornar pagamento: tenta Stripe refund inline, senão marca
 *     refund_status='pending' pra cron processar
 *   - Broadcast `prescription_rejected` com motivo
 *
 * Auth: Bearer token (partner).
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/ws-customer-broadcast.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload    = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        response(false, null, "Método não permitido", 405);
    }

    $input    = getInput();
    $order_id = (int)($input['order_id'] ?? 0);
    $action   = trim($input['action'] ?? '');
    $reason   = trim($input['reason'] ?? '');

    if ($order_id <= 0) {
        response(false, null, "order_id obrigatório", 400);
    }
    if (!in_array($action, ['approve', 'reject'], true)) {
        response(false, null, "action deve ser 'approve' ou 'reject'", 400);
    }
    if ($action === 'reject' && $reason === '') {
        response(false, null, "Informe o motivo da rejeição", 400);
    }
    // Limita tamanho pra evitar payload gigante
    $reason = mb_substr($reason, 0, 500);

    // Lock order + validar que pertence a esse partner e tem receita pendente
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            SELECT order_id, partner_id, customer_id, customer_name, total,
                   status, prescription_status, payment_method,
                   stripe_payment_intent_id, payment_id
            FROM om_market_orders
            WHERE order_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();

        if (!$order) {
            $db->rollBack();
            response(false, null, "Pedido não encontrado", 404);
        }
        if ((int)$order['partner_id'] !== $partner_id) {
            $db->rollBack();
            response(false, null, "Pedido não pertence a este parceiro", 403);
        }
        if ($order['prescription_status'] !== 'pending') {
            $db->rollBack();
            response(false, null, "Esta receita já foi revisada", 409);
        }

        $customer_id = (int)$order['customer_id'];
        $needsStripeRefund = false;
        $stripePi = $order['stripe_payment_intent_id'] ?: null;

        if ($action === 'approve') {
            $stmtU = $db->prepare("
                UPDATE om_market_orders
                SET prescription_status     = 'approved',
                    prescription_reviewed_at = NOW(),
                    prescription_reviewed_by = ?,
                    status                   = 'confirmado',
                    confirmed_at             = COALESCE(confirmed_at, NOW()),
                    date_modified            = NOW()
                WHERE order_id = ?
            ");
            $stmtU->execute([$partner_id, $order_id]);
        } else {
            // Reject
            $stmtU = $db->prepare("
                UPDATE om_market_orders
                SET prescription_status        = 'rejected',
                    prescription_rejection_reason = ?,
                    prescription_reviewed_at   = NOW(),
                    prescription_reviewed_by   = ?,
                    status                     = 'cancelado',
                    cancelled_at               = NOW(),
                    cancelled_by               = 'farmacia',
                    cancel_reason              = ?,
                    cancellation_reason        = ?,
                    date_modified              = NOW()
                WHERE order_id = ?
            ");
            $cancelMsg = "Receita rejeitada: {$reason}";
            $stmtU->execute([$reason, $partner_id, $cancelMsg, $cancelMsg, $order_id]);

            // Re-estoque: devolve os itens pro inventário
            $stmtItems = $db->prepare("SELECT product_id, quantity FROM om_market_order_items WHERE order_id = ?");
            $stmtItems->execute([$order_id]);
            $stmtRestock = $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?");
            foreach ($stmtItems->fetchAll() as $it) {
                $stmtRestock->execute([(int)$it['quantity'], (int)$it['product_id']]);
            }

            // Decidir se precisa refund Stripe
            $pm = $order['payment_method'] ?? '';
            $isPaidCard = in_array($pm, ['stripe_card', 'credito', 'credit_card'], true);
            if ($isPaidCard && $stripePi) {
                $needsStripeRefund = true;
            } else {
                // Sem Stripe inline — marca refund_pending pra cron processar
                // (cobre PIX/EFI/dinheiro que não temos contexto de refund aqui)
                $db->prepare("UPDATE om_market_orders SET refund_status = 'pending', refund_reason = ? WHERE order_id = ?")
                   ->execute(['Receita rejeitada', $order_id]);
            }
        }

        $db->commit();
    } catch (Exception $txEx) {
        $db->rollBack();
        throw $txEx;
    }

    // Stripe refund APOS commit (external call outside transaction)
    if ($needsStripeRefund && !empty($stripePi)) {
        try {
            $stripeEnv = dirname(__DIR__, 3) . '/.env.stripe';
            $STRIPE_SK = '';
            if (file_exists($stripeEnv)) {
                foreach (file($stripeEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    if (strpos(trim($line), '#') === 0) continue;
                    if (strpos($line, '=') !== false) {
                        [$k, $v] = explode('=', $line, 2);
                        if (trim($k) === 'STRIPE_SECRET_KEY') $STRIPE_SK = trim($v);
                    }
                }
            }
            if ($STRIPE_SK) {
                $idempotencyKey = "refund_rx_reject_{$order_id}_{$stripePi}";
                $ch = curl_init("https://api.stripe.com/v1/refunds");
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query([
                        'payment_intent'     => $stripePi,
                        'reason'             => 'requested_by_customer',
                        'metadata[order_id]' => $order_id,
                        'metadata[source]'   => 'superbora_prescription_reject',
                    ]),
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $STRIPE_SK,
                        'Content-Type: application/x-www-form-urlencoded',
                        'Stripe-Version: 2023-10-16',
                        'Idempotency-Key: ' . $idempotencyKey,
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15,
                ]);
                $refundResult = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $refundData = json_decode($refundResult, true);
                $refundOk = $httpCode >= 200 && $httpCode < 300 && !empty($refundData['id']);
                if ($refundOk) {
                    error_log("[prescription-review] Stripe refund OK pedido #{$order_id} refund_id={$refundData['id']}");
                    $db->prepare("UPDATE om_market_orders SET refund_status = 'refunded', refunded_at = NOW(), refund_amount = total WHERE order_id = ?")
                       ->execute([$order_id]);
                } else {
                    error_log("[prescription-review] FALHA refund Stripe PI={$stripePi} code={$httpCode}");
                    $db->prepare("UPDATE om_market_orders SET refund_status = 'pending', refund_reason = 'Receita rejeitada - stripe refund failed' WHERE order_id = ?")
                       ->execute([$order_id]);
                }
            } else {
                // Sem chave — marca pending
                $db->prepare("UPDATE om_market_orders SET refund_status = 'pending', refund_reason = 'Receita rejeitada' WHERE order_id = ?")
                   ->execute([$order_id]);
            }
        } catch (Exception $e) {
            error_log("[prescription-review] Erro refund: " . $e->getMessage());
        }
    }

    // WebSocket broadcast (fanout pra customer/order/partner/admin)
    try {
        if ($action === 'approve') {
            wsBroadcastOrderChange(
                $order_id,
                $customer_id,
                $partner_id,
                'prescription_approved',
                [
                    'order_id' => $order_id,
                    'status'   => 'confirmado',
                ]
            );
        } else {
            wsBroadcastOrderChange(
                $order_id,
                $customer_id,
                $partner_id,
                'prescription_rejected',
                [
                    'order_id' => $order_id,
                    'status'   => 'cancelado',
                    'reason'   => $reason,
                ]
            );
        }
    } catch (\Throwable $e) {
        error_log("[prescription-review] WS broadcast error: " . $e->getMessage());
    }

    // Notificação push in-app (best effort)
    try {
        require_once __DIR__ . "/../helpers/NotificationSender.php";
        if (class_exists('NotificationSender')) {
            $notifyTitle = $action === 'approve' ? 'Receita aprovada' : 'Receita rejeitada';
            $notifyBody  = $action === 'approve'
                ? 'Seu pedido foi confirmado e já está sendo preparado.'
                : "Sua receita foi rejeitada: {$reason}";
            $ns = new NotificationSender($db);
            $ns->sendToCustomer($customer_id, $notifyTitle, $notifyBody, [
                'type'     => $action === 'approve' ? 'prescription_approved' : 'prescription_rejected',
                'order_id' => $order_id,
            ]);
        }
    } catch (\Throwable $e) {
        // Push é best effort
    }

    response(true, [
        'order_id'            => $order_id,
        'action'              => $action,
        'prescription_status' => $action === 'approve' ? 'approved' : 'rejected',
        'order_status'        => $action === 'approve' ? 'confirmado' : 'cancelado',
    ], $action === 'approve' ? 'Receita aprovada' : 'Receita rejeitada');

} catch (Exception $e) {
    error_log("[partner/prescription-review] Erro: " . $e->getMessage());
    response(false, null, "Erro ao revisar receita", 500);
}
