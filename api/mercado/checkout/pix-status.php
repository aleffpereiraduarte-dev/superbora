<?php
/**
 * GET /api/mercado/checkout/pix-status.php?order_id=X     (legacy: order-based PIX)
 * GET /api/mercado/checkout/pix-status.php?intent_id=X    (new: payment-first PIX)
 *
 * Poll PIX payment status.
 * - intent_id: polls om_pix_intents — returns status + order_id when paid
 * - order_id: polls om_market_orders — legacy flow (backward-compatible)
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";

if (!RateLimiter::check(60, 60)) {
    exit;
}

try {
    $db = getDB();
    $customerId = requireCustomerAuth();

    // ─── NEW: Payment-first intent polling ───
    $intent_id = (int)($_GET['intent_id'] ?? 0);
    if ($intent_id) {
        $stmt = $db->prepare("
            SELECT intent_id, status, order_id, amount_cents, expires_at, paid_at
            FROM om_pix_intents
            WHERE intent_id = ? AND customer_id = ?
        ");
        $stmt->execute([$intent_id, $customerId]);
        $intent = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$intent) {
            response(false, null, "Intent nao encontrado", 404);
        }

        // Auto-expire if past deadline and still pending
        if ($intent['status'] === 'pending' && strtotime($intent['expires_at']) < time()) {
            $upd = $db->prepare("UPDATE om_pix_intents SET status = 'expired' WHERE intent_id = ? AND status = 'pending'");
            $upd->execute([$intent_id]);
            $intent['status'] = 'expired';
        }

        $data = [
            'status' => $intent['status'],
            'intent_id' => (int)$intent['intent_id'],
            'amount' => round((int)$intent['amount_cents'] / 100, 2),
            'paid' => $intent['status'] === 'paid',
            'pix_paid' => $intent['status'] === 'paid',
        ];

        if ($intent['status'] === 'paid' && $intent['order_id']) {
            $data['order_id'] = (int)$intent['order_id'];
            $orderStmt = $db->prepare("SELECT order_number FROM om_market_orders WHERE order_id = ?");
            $orderStmt->execute([$intent['order_id']]);
            $orderNum = $orderStmt->fetchColumn();
            if ($orderNum) {
                $data['order_number'] = $orderNum;
            }
        }

        if ($intent['status'] === 'expired') {
            $data['message'] = 'PIX expirado. Gere um novo.';
        }

        // Remaining seconds
        $remaining = max(0, strtotime($intent['expires_at']) - time());
        $data['remaining_seconds'] = $remaining;
        $data['expires_at'] = date('c', strtotime($intent['expires_at']));

        response(true, $data);
    }

    // ─── LEGACY: Order-based PIX polling ───
    $order_id = (int)($_GET["order_id"] ?? 0);

    if (!$order_id) {
        response(false, null, "order_id ou intent_id obrigatorio", 400);
    }

    // SECURITY FIX: Always filter by authenticated customer_id to prevent IDOR
    $stmt = $db->prepare("
        SELECT order_id, order_number, status, forma_pagamento, total,
               date_added, timer_expires, pix_code, pix_qr_code,
               pagamento_status, payment_status
        FROM om_market_orders
        WHERE order_id = ? AND customer_id = ?
    ");
    $stmt->execute([$order_id, $customerId]);
    $order = $stmt->fetch();

    if (!$order) {
        response(false, null, "Pedido nao encontrado", 404);
    }

    // Derive paid status strictly from payment fields — not from order status
    // (order status like 'cancelado' should NOT count as paid)
    $pix_paid = ($order['pagamento_status'] ?? '') === 'pago'
        || ($order['payment_status'] ?? '') === 'paid';

    // Fallback: se webhook nao atualizou, consultar Woovi API diretamente
    if (!$pix_paid && $order['forma_pagamento'] === 'pix') {
        try {
            $stmt2 = $db->prepare("SELECT charge_id, pagarme_order_id FROM om_pagarme_transacoes WHERE pedido_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmt2->execute([$order_id]);
            $txRow = $stmt2->fetch();

            // Try Woovi first (correlationId starts with "order_")
            $wooviCorrelation = $txRow['pagarme_order_id'] ?? '';
            if (!empty($wooviCorrelation) && strpos($wooviCorrelation, 'order_') === 0) {
                try {
                    require_once dirname(__DIR__, 3) . '/includes/classes/WooviClient.php';
                    $woovi = new WooviClient();
                    $result = $woovi->getChargeStatus($wooviCorrelation);
                    $chargeData = $result['data']['charge'] ?? $result['data'] ?? [];
                    $chargeStatus = $chargeData['status'] ?? '';

                    if (in_array($chargeStatus, ['COMPLETED', 'CONFIRMED'])) {
                        $pix_paid = true;
                        $db->prepare("UPDATE om_market_orders SET pagamento_status = 'pago', payment_status = 'paid', pix_paid = true, status = CASE WHEN status = 'pendente' THEN 'aceito' ELSE status END, date_modified = NOW() WHERE order_id = ?")->execute([$order_id]);
                        $db->prepare("UPDATE om_pagarme_transacoes SET status = 'paid' WHERE pedido_id = ? AND status != 'paid'")->execute([$order_id]);
                        $order['status'] = ($order['status'] === 'pendente') ? 'aceito' : $order['status'];
                        error_log("[PIX-Status] Fallback: pedido #$order_id confirmado via polling Woovi");
                    }
                } catch (Exception $wooviErr) {
                    error_log("[PIX-Status] Woovi fallback error: " . $wooviErr->getMessage());
                }
            }
        } catch (Exception $fallbackErr) {
            error_log("[PIX-Status] Fallback error: " . $fallbackErr->getMessage());
        }
    }

    $timeSinceCreation = time() - strtotime($order['date_added']);

    // Calculate remaining seconds server-side to avoid timezone mismatch
    $expiresAt = $order['timer_expires'];
    $remaining_seconds = 0;
    if ($expiresAt) {
        $ts = strtotime($expiresAt);
        if ($ts) {
            $remaining_seconds = max(0, $ts - time());
            $expiresAt = date('c', $ts);
        }
    }

    // Generate QR code URL if we have the PIX string but no image URL
    $qrCodeUrl = $order['pix_qr_code'] ?? '';
    $pixCode = $order['pix_code'] ?? '';
    if (empty($qrCodeUrl) && !empty($pixCode)) {
        $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($pixCode);
    }

    response(true, [
        "order_id" => (int)$order['order_id'],
        "order_number" => $order['order_number'],
        "status" => $order['status'],
        "paid" => $pix_paid,
        "pix_paid" => $pix_paid,
        "payment_method" => $order['forma_pagamento'],
        "total" => (float)$order['total'],
        "created_at" => $order['date_added'],
        "expires_at" => $expiresAt,
        "remaining_seconds" => $remaining_seconds,
        "qr_code_text" => $pixCode,
        "qr_code_url" => $qrCodeUrl,
        "pix_code" => $pixCode,
        "copy_paste" => $pixCode
    ]);

} catch (Exception $e) {
    error_log("[PIX Status] Erro: " . $e->getMessage());
    response(false, null, "Erro ao verificar status", 500);
}
