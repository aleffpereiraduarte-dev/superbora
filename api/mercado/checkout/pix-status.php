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

    // Fallback: se webhook nao atualizou, consultar EFI API diretamente
    if (!$pix_paid && $order['forma_pagamento'] === 'pix') {
        try {
            // Check payment_id (stores the EFI txid)
            $efiTxid = $order['payment_id'] ?? '';

            // Also check om_pix_intents for the txid
            if (empty($efiTxid)) {
                $intentStmt = $db->prepare("SELECT correlation_id FROM om_pix_intents WHERE order_id = ? AND status = 'paid' LIMIT 1");
                $intentStmt->execute([$order_id]);
                $efiTxid = $intentStmt->fetchColumn() ?: '';
            }

            if (!empty($efiTxid)) {
                try {
                    require_once dirname(__DIR__, 3) . '/includes/classes/EfiClient.php';
                    $efi = new EfiClient();
                    $result = $efi->checkChargeStatus($efiTxid);

                    if ($result['success'] && $result['paid']) {
                        $e2eId = $result['e2e_id'] ?? '';
                        // Atomic update with FOR UPDATE to prevent race conditions
                        $db->beginTransaction();
                        $lockStmt = $db->prepare("SELECT pagamento_status FROM om_market_orders WHERE order_id = ? FOR UPDATE");
                        $lockStmt->execute([$order_id]);
                        $currentPayStatus = $lockStmt->fetchColumn();
                        if ($currentPayStatus !== 'pago') {
                            $db->prepare("UPDATE om_market_orders SET pagamento_status = 'pago', payment_status = 'paid', pix_paid = true, payment_id = COALESCE(NULLIF(payment_id,''), ?), status = CASE WHEN status = 'pendente' THEN 'aceito' ELSE status END, date_modified = NOW() WHERE order_id = ?")
                               ->execute([$e2eId ?: $efiTxid, $order_id]);
                            error_log("[PIX-Status] Fallback: pedido #$order_id confirmado via polling EFI txid={$efiTxid}");
                        }
                        $db->commit();
                        $pix_paid = true;
                        $order['status'] = ($order['status'] === 'pendente') ? 'aceito' : $order['status'];
                    }
                } catch (Exception $efiErr) {
                    error_log("[PIX-Status] EFI fallback error: " . $efiErr->getMessage());
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
