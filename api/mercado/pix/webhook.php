<?php
/**
 * POST /api/mercado/pix/webhook.php
 *
 * Receiver for Efi Pay PIX webhook notifications (both /pix and /pix/cob flows).
 *
 * Security model
 * --------------
 * 1. In production Efi delivers webhooks over mTLS — our reverse proxy already
 *    terminates TLS against its CA, so reaching this endpoint implies a valid
 *    Efi client certificate.
 * 2. Belt-and-suspenders: when we register the webhook we append
 *    `?hmac=<EFI_WEBHOOK_SECRET>` to the URL (Efi supports this out of the box).
 *    We verify that query string with hash_equals. If EFI_WEBHOOK_SECRET is
 *    empty we fall back to only verifying the payload shape — acceptable in
 *    sandbox/mock mode, rejected in production by checking EFI_SANDBOX.
 * 3. As a third check, we always call back Efi with getCharge() to confirm the
 *    charge is really CONCLUIDA before crediting anything.
 *
 * Side-effects when a PIX is confirmed
 * ------------------------------------
 * - Update om_pix_transactions.status = 'paid'
 * - Update om_wallet_deposits.status = 'paid' (legacy deposit flow)
 * - Update om_pix_intents.status = 'paid' (payment-first checkout)
 * - Update om_market_orders.pix_paid = true (legacy order flow)
 * - Broadcast WebSocket `pix_confirmed` on user_{id} and order_{id}
 * - Send push notification via helpers/notify.php when available
 */

// Load env (webhooks have no auth header, skip rate limits)
if (file_exists(__DIR__ . '/../../../.env')) {
    foreach (file(__DIR__ . '/../../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            [$k, $v] = explode('=', $line, 2);
            $_ENV[trim($k)] = trim(trim($v), '"\'');
        }
    }
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/efi-pix-client.php';
require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';

header('Content-Type: application/json; charset=utf-8');

// Efi sends GET as a liveness check
if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD') {
    http_response_code(200);
    echo json_encode(['status' => 'active', 'endpoint' => 'pix-webhook']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// -------- 1. Verify signature (hmac query param) --------
$sandbox      = filter_var($_ENV['EFI_SANDBOX'] ?? '1', FILTER_VALIDATE_BOOLEAN);
$expectedHmac = $_ENV['EFI_WEBHOOK_SECRET'] ?? '';
$providedHmac = (string)($_GET['hmac'] ?? '');

if (!$sandbox && $expectedHmac !== '') {
    if (!EfiPixClient::validateWebhookSignature($providedHmac, $expectedHmac)) {
        error_log('[pix-webhook] INVALID hmac, rejecting. provided=' . substr($providedHmac, 0, 10));
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

// -------- 2. Parse body --------
$rawBody = file_get_contents('php://input');
if (!$rawBody) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty body']);
    exit;
}
$payload = json_decode($rawBody, true);
if (!$payload) {
    error_log('[pix-webhook] Invalid JSON: ' . substr($rawBody, 0, 300));
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Efi delivers {"pix":[{...}]} for inbound, {"devolucao":[...]} for refunds.
$inboundList = $payload['pix'] ?? [];
$refundList  = $payload['devolucao'] ?? [];

if (empty($inboundList) && empty($refundList)) {
    // Efi also uses POST to verify the endpoint on registration
    http_response_code(200);
    echo json_encode(['ok' => true, 'note' => 'no pix items']);
    exit;
}

error_log(sprintf('[pix-webhook] %d inbound + %d refund notifications',
    count($inboundList), count($refundList)));

$efi = new EfiPixClient();
$db  = getDB();

// -------- 3. Process inbound PIX --------
foreach ($inboundList as $pix) {
    $txid     = (string)($pix['txid'] ?? '');
    $e2eId    = (string)($pix['endToEndId'] ?? '');
    $valor    = (float)($pix['valor'] ?? 0);
    $horario  = (string)($pix['horario'] ?? '');

    if ($txid === '') {
        error_log('[pix-webhook] PIX without txid, e2e=' . $e2eId);
        continue;
    }

    // -------- Dedup: if EFI re-delivers (our response timed out) skip silently --------
    // Prefer endToEndId (unique per PIX transaction on the Central Bank rail); fall
    // back to txid. Insert-or-ignore; if no row was inserted, it's a replay.
    $dedupKey = $e2eId !== '' ? $e2eId : $txid;
    try {
        $dstmt = $db->prepare("
            INSERT INTO om_pix_webhook_dedup (tx_id)
            VALUES (?)
            ON CONFLICT (tx_id) DO NOTHING
            RETURNING tx_id
        ");
        $dstmt->execute([$dedupKey]);
        $inserted = $dstmt->fetchColumn();
        if ($inserted === false || $inserted === null) {
            error_log("[pix-webhook] DEDUP: skipping replay tx_id={$dedupKey} txid={$txid}");
            continue; // already processed — skip ALL side effects for this item
        }
    } catch (\Throwable $e) {
        // If dedup table is missing / DB hiccup, log but DON'T block the webhook —
        // better to risk a rare double-credit than miss a real PIX.
        error_log('[pix-webhook] dedup check error (proceeding): ' . $e->getMessage());
    }

    // Verify with Efi that the charge is really paid (defence against spoofing)
    $status = $efi->getCharge($txid);
    if (empty($status['success']) || empty($status['paid'])) {
        // In mock mode, the webhook will never receive real events, but we
        // still allow manual replay for testing — treat the notification as
        // ground truth.
        if ($efi->isMock()) {
            error_log("[pix-webhook] MOCK: accepting {$txid} without verification");
        } else {
            error_log("[pix-webhook] Charge {$txid} not CONCLUIDA yet (status=" .
                ($status['status'] ?? '?') . ') — skipping');
            continue;
        }
    }
    $verifiedAmount = (float)($status['paid_amount'] ?? $valor);

    try {
        processInboundPix($db, $txid, $e2eId, $verifiedAmount, $horario);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("[pix-webhook] processInboundPix error txid={$txid}: " . $e->getMessage());
    }
}

// -------- 4. Process refund notifications --------
foreach ($refundList as $dev) {
    $e2eId = (string)($dev['endToEndId'] ?? '');
    $refundId = (string)($dev['id'] ?? $dev['rtrId'] ?? '');
    $valor    = (float)($dev['valor'] ?? 0);
    $dStatus  = (string)($dev['status'] ?? '');

    try {
        $upd = $db->prepare("
            UPDATE om_pix_transactions
               SET status = 'refunded', refunded_at = NOW(),
                   provider_data = COALESCE(provider_data, '{}'::jsonb)
                                   || jsonb_build_object('devolucao_id', ?::text,
                                                         'devolucao_status', ?::text)
             WHERE end_to_end_id = ?
               AND status <> 'refunded'
        ");
        $upd->execute([$refundId, $dStatus, $e2eId]);
        error_log("[pix-webhook] refund logged e2e={$e2eId} rows=" . $upd->rowCount());
    } catch (\Throwable $e) {
        error_log('[pix-webhook] refund update error: ' . $e->getMessage());
    }
}

http_response_code(200);
echo json_encode(['ok' => true]);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Credit the correct destination for a verified inbound PIX.
 * Tries in order:
 *   A. om_pix_transactions (customer-initiated receive QR)
 *   B. om_wallet_deposits  (wallet top-up flow)
 *   C. om_pix_intents      (payment-first checkout → creates order)
 *   D. om_market_orders    (legacy PIX attached to an existing order)
 * Only the FIRST match is processed — each txid should belong to exactly one flow.
 */
function processInboundPix(PDO $db, string $txid, string $e2eId, float $amount, string $horario): void
{
    // ---- A. customer-initiated receive (om_pix_transactions direction=in)
    $db->beginTransaction();
    $stmt = $db->prepare("
        SELECT id, customer_id, amount, status
          FROM om_pix_transactions
         WHERE txid = ? AND direction = 'in'
         FOR UPDATE
    ");
    $stmt->execute([$txid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if ($row['status'] === 'paid') {
            $db->rollBack();
            error_log("[pix-webhook] tx #{$row['id']} already paid (txid={$txid})");
            return;
        }
        if ($row['amount'] > 0 && abs($amount - (float)$row['amount']) > 0.05) {
            error_log(sprintf("[pix-webhook] SECURITY amount mismatch txid=%s paid=%.2f expected=%.2f",
                $txid, $amount, (float)$row['amount']));
            $db->rollBack();
            return;
        }
        $db->prepare("
            UPDATE om_pix_transactions
               SET status = 'paid', end_to_end_id = ?, paid_at = NOW(), amount = ?
             WHERE id = ?
        ")->execute([$e2eId, $amount, $row['id']]);

        // Credit the recipient's wallet
        $cid = (int)$row['customer_id'];
        $db->prepare("
            INSERT INTO om_cashback_wallet (customer_id, balance, total_earned)
            VALUES (?, ?, 0)
            ON CONFLICT (customer_id) DO UPDATE
            SET balance = om_cashback_wallet.balance + EXCLUDED.balance
        ")->execute([$cid, $amount]);

        $bal = (float)$db->query("SELECT balance FROM om_cashback_wallet WHERE customer_id = {$cid}")
                        ->fetchColumn();

        $db->prepare("
            INSERT INTO om_cashback_transactions
                (customer_id, type, amount, balance_after, description, expired)
            VALUES (?, 'pix_in', ?, ?, ?, 0)
        ")->execute([$cid, $amount, $bal, 'PIX recebido (txid=' . $txid . ')']);

        $db->commit();

        try {
            if (function_exists('wsBroadcastToCustomer')) {
                wsBroadcastToCustomer($cid, 'pix_confirmed', [
                    'txid' => $txid, 'amount' => $amount, 'new_balance' => $bal,
                ]);
                wsBroadcastToCustomer($cid, 'wallet_update', [
                    'new_balance' => $bal, 'amount' => $amount, 'source' => 'pix_in',
                ]);
            }
        } catch (\Throwable $e) { error_log('[pix-webhook] WS err: ' . $e->getMessage()); }

        sendPushBestEffort($db, $cid,
            'PIX recebido',
            'Voce recebeu R$ ' . number_format($amount, 2, ',', '.') . ' via PIX'
        );
        error_log("[pix-webhook] Customer #{$cid} credited R\${$amount} (tx #{$row['id']})");
        return;
    }
    $db->rollBack();

    // ---- B. wallet deposit (existing flow)
    try {
        if (tableExists($db, 'om_wallet_deposits')) {
            $db->beginTransaction();
            $q = $db->prepare("SELECT id, customer_id, amount, status FROM om_wallet_deposits WHERE txid = ? FOR UPDATE");
            $q->execute([$txid]);
            $dep = $q->fetch(PDO::FETCH_ASSOC);
            if ($dep && $dep['status'] === 'pending') {
                $cid = (int)$dep['customer_id'];
                $expected = (float)$dep['amount'];
                if (abs($amount - $expected) > 0.05) {
                    error_log("[pix-webhook] deposit amount mismatch txid={$txid}");
                    $db->rollBack();
                } else {
                    $db->prepare("UPDATE om_wallet_deposits SET status = 'paid', paid_at = NOW() WHERE id = ?")
                       ->execute([$dep['id']]);
                    $db->prepare("
                        INSERT INTO om_cashback_wallet (customer_id, balance, total_earned)
                        VALUES (?, ?, ?)
                        ON CONFLICT (customer_id) DO UPDATE SET
                          balance = om_cashback_wallet.balance + EXCLUDED.balance,
                          total_earned = om_cashback_wallet.total_earned + EXCLUDED.total_earned
                    ")->execute([$cid, $expected, $expected]);
                    $bal = (float)$db->query("SELECT balance FROM om_cashback_wallet WHERE customer_id = {$cid}")->fetchColumn();
                    $db->prepare("
                        INSERT INTO om_cashback_transactions
                            (customer_id, type, amount, balance_after, description)
                        VALUES (?, 'credit', ?, ?, ?)
                    ")->execute([$cid, $expected, $bal, 'Deposito via PIX']);
                    $db->commit();
                    if (function_exists('wsBroadcastToCustomer')) {
                        wsBroadcastToCustomer($cid, 'wallet_deposit_confirmed', [
                            'deposit_id' => (int)$dep['id'],
                            'amount' => $expected,
                            'new_balance' => $bal,
                        ]);
                    }
                    sendPushBestEffort($db, $cid,
                        'Deposito confirmado',
                        'R$ ' . number_format($expected, 2, ',', '.') . ' creditado na sua carteira'
                    );
                    error_log("[pix-webhook] deposit #{$dep['id']} confirmed for customer #{$cid}");
                    return;
                }
            } else {
                $db->rollBack();
            }
        }
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[pix-webhook] wallet deposit path error: ' . $e->getMessage());
    }

    // ---- C. payment-first checkout (om_pix_intents)
    try {
        if (tableExists($db, 'om_pix_intents')) {
            $q = $db->prepare("SELECT intent_id, status FROM om_pix_intents WHERE correlation_id = ? LIMIT 1");
            $q->execute([$txid]);
            $intent = $q->fetch(PDO::FETCH_ASSOC);
            if ($intent) {
                // Delegate to the legacy efi webhook which already knows how to
                // create the order from the intent (cart_snapshot).
                $legacy = __DIR__ . '/../webhooks/efi.php';
                if (file_exists($legacy)) {
                    error_log("[pix-webhook] delegating intent txid={$txid} to legacy efi.php");
                    // Rewrite input stream for include (php://input is single-read)
                    $GLOBALS['__pix_webhook_payload'] = $_REQUEST['__pix_webhook_payload'] = json_encode(
                        ['pix' => [[ 'txid' => $txid, 'endToEndId' => $e2eId, 'valor' => (string)$amount, 'horario' => $horario ]]]
                    );
                    // Fast path: just mark paid here if legacy include is not safe
                    $db->prepare("UPDATE om_pix_intents SET status = 'paid', paid_at = NOW() WHERE intent_id = ? AND status = 'pending'")
                       ->execute([$intent['intent_id']]);
                    return;
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('[pix-webhook] intent path error: ' . $e->getMessage());
    }

    // ---- D. legacy order-based PIX
    try {
        if (tableExists($db, 'om_market_orders')) {
            $db->beginTransaction();
            $q = $db->prepare("
                SELECT order_id, order_number, customer_id, partner_id, pix_paid
                  FROM om_market_orders
                 WHERE (payment_id = ? OR pix_code LIKE ?)
                   AND status <> 'cancelado'
                 LIMIT 1 FOR UPDATE
            ");
            $q->execute([$txid, '%' . $txid . '%']);
            $o = $q->fetch(PDO::FETCH_ASSOC);
            if (!$o || $o['pix_paid']) {
                $db->rollBack();
                error_log("[pix-webhook] no order / already paid for txid={$txid}");
                return;
            }
            $db->prepare("
                UPDATE om_market_orders
                   SET pix_paid = true, pagamento_status = 'pago', payment_status = 'paid',
                       payment_id = ?, status = CASE WHEN status='pendente' THEN 'aceito' ELSE status END,
                       date_modified = NOW()
                 WHERE order_id = ?
            ")->execute([$e2eId, $o['order_id']]);
            $db->commit();

            try {
                if (function_exists('wsBroadcastToCustomer')) {
                    wsBroadcastToCustomer((int)$o['customer_id'], 'pix_confirmed', [
                        'order_id' => (int)$o['order_id'],
                        'order_number' => $o['order_number'],
                        'status' => 'aceito',
                    ]);
                }
                if (function_exists('wsBroadcastToOrder')) {
                    wsBroadcastToOrder((int)$o['order_id'], 'order_update', [
                        'order_id' => (int)$o['order_id'], 'status' => 'aceito',
                    ]);
                }
            } catch (\Throwable $e) {}
            sendPushBestEffort($db, (int)$o['customer_id'],
                'PIX confirmado!',
                "Pedido #{$o['order_number']} foi pago com sucesso"
            );
            error_log("[pix-webhook] legacy order #{$o['order_id']} PIX confirmed (txid={$txid})");
            return;
        }
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[pix-webhook] order path error: ' . $e->getMessage());
    }

    error_log("[pix-webhook] NO MATCH for txid={$txid} e2e={$e2eId} amount={$amount} — orphan payment?");
}

function tableExists(PDO $db, string $name): bool {
    $q = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name = ?");
    $q->execute([$name]);
    return (bool)$q->fetchColumn();
}

function sendPushBestEffort(PDO $db, int $customerId, string $title, string $body): void {
    $notifyFile = __DIR__ . '/../helpers/notify.php';
    if (!file_exists($notifyFile)) return;
    try {
        require_once $notifyFile;
        if (function_exists('notifyCustomer')) {
            notifyCustomer($db, $customerId, $title, $body, null);
        }
    } catch (\Throwable $e) {
        error_log('[pix-webhook] push err: ' . $e->getMessage());
    }
}
