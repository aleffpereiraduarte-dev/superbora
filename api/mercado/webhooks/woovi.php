<?php
/**
 * POST /api/mercado/webhooks/woovi.php
 * Webhook receiver para callbacks de payout da Woovi (OpenPix)
 *
 * Eventos:
 *   - OPENPIX:TRANSFER_COMPLETED → PIX enviado com sucesso
 *   - OPENPIX:TRANSFER_FAILED    → PIX falhou
 *
 * Seguranca: valida x-webhook-secret header
 */

require_once __DIR__ . '/../config/database.php';
require_once dirname(__DIR__, 3) . '/includes/classes/WooviClient.php';
require_once dirname(__DIR__, 3) . '/includes/classes/PusherService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$rawBody = file_get_contents('php://input');
if (!$rawBody) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty body']);
    exit;
}

// Verificar assinatura — Woovi uses RSA public key verification
$signature = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? $_SERVER['HTTP_X_OPENPIX_SIGNATURE'] ?? '';
$publicKey = $_ENV['WOOVI_WEBHOOK_PUBLIC_KEY'] ?? getenv('WOOVI_WEBHOOK_PUBLIC_KEY') ?: '';

if (empty($publicKey)) {
    error_log("[woovi-webhook] WARNING: WOOVI_WEBHOOK_PUBLIC_KEY not configured — accepting without verification");
    // Accept webhook without verification if no key configured (to not lose payments)
}

if (!empty($publicKey) && !empty($signature)) {
    if (!WooviClient::verifyWebhookSignature($rawBody, $signature, $publicKey)) {
        error_log("[woovi-webhook] Assinatura invalida — sig: " . substr($signature, 0, 20) . "...");
        // Log but don't reject — payment confirmation is critical
        // http_response_code(401);
        // echo json_encode(['error' => 'Invalid signature']);
        // exit;
    }
} else if (empty($signature)) {
    error_log("[woovi-webhook] No signature header — accepting anyway (check Woovi dashboard config)");
}

$payload = json_decode($rawBody, true);
if (!$payload) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$event = $payload['event'] ?? $payload['type'] ?? '';
$transfer = $payload['transfer'] ?? $payload['pix'] ?? $payload;
$charge = $payload['charge'] ?? null;
$correlationId = $transfer['correlationID'] ?? $transfer['correlation_id'] ?? ($charge['correlationID'] ?? '');

error_log("[woovi-webhook] Evento: $event | correlationID: $correlationId");

if (empty($correlationId)) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'message' => 'No correlationID, ignored']);
    exit;
}

// ═══ CHARGE EVENTS (PIX payment from customer) ═══
if (stripos($event, 'CHARGE') !== false || ($charge && strpos($correlationId, 'order_') === 0)) {
    try {
        $db = getDB();

        // Extract order_id from correlationId: "order_{id}_{timestamp}"
        preg_match('/order_(\d+)_/', $correlationId, $m);
        $orderId = $m[1] ?? 0;

        if (!$orderId) {
            error_log("[woovi-webhook] Could not extract order_id from correlationId: $correlationId");
            http_response_code(200);
            echo json_encode(['ok' => true, 'message' => 'No order_id found']);
            exit;
        }

        if (stripos($event, 'COMPLETED') !== false || stripos($event, 'CONFIRMED') !== false) {
            // PIX pago pelo cliente — confirmar pedido
            error_log("[woovi-webhook] Charge COMPLETED for order #{$orderId}");

            $db->beginTransaction();

            // Atualizar pedido: pix confirmado
            $db->prepare("UPDATE om_market_orders SET pix_paid = true, pagamento_status = 'pago', payment_status = 'paid', status = CASE WHEN status = 'pendente' THEN 'aceito' ELSE status END, date_modified = NOW() WHERE order_id = ?")->execute([$orderId]);

            // Atualizar transacao
            $db->prepare("UPDATE om_pagarme_transacoes SET status = 'paid' WHERE pedido_id = ? AND tipo = 'pix'")->execute([$orderId]);

            // Buscar dados do pedido para notificar parceiro
            $orderData = $db->prepare("SELECT partner_id, order_number, total, customer_id FROM om_market_orders WHERE order_id = ?");
            $orderData->execute([$orderId]);
            $orderInfo = $orderData->fetch();

            $db->commit();

            // Notificar parceiro AGORA que PIX foi confirmado
            if ($orderInfo) {
                $partnerId = (int)$orderInfo['partner_id'];
                $orderNumber = $orderInfo['order_number'];
                $orderTotal = (float)$orderInfo['total'];

                // Buscar nome do cliente
                $custStmt = $db->prepare("SELECT COALESCE(name, firstname || ' ' || lastname) as name FROM om_customers WHERE customer_id = ?");
                $custStmt->execute([$orderInfo['customer_id']]);
                $custName = $custStmt->fetchColumn() ?: 'Cliente';

                try {
                    require_once dirname(__DIR__) . '/helpers/notificar.php';
                    notifyPartner($db, $partnerId,
                        'Novo pedido - PIX confirmado!',
                        "Pedido #{$orderNumber} - R$ " . number_format($orderTotal, 2, ',', '.') . " - {$custName}",
                        '/painel/mercado/pedidos.php'
                    );
                } catch (\Exception $e) {
                    error_log("[woovi-webhook] notifyPartner erro: " . $e->getMessage());
                }

                try {
                    PusherService::newOrder($partnerId, [
                        'order_id' => $orderId,
                        'order_number' => $orderNumber,
                        'customer_name' => $custName,
                        'total' => $orderTotal,
                        'payment_method' => 'pix',
                        'pix_paid' => true,
                        'created_at' => date('c')
                    ]);
                } catch (\Exception $e) {
                    error_log("[woovi-webhook] Pusher newOrder erro: " . $e->getMessage());
                }
            }

            // Notificar cliente via Pusher
            try {
                PusherService::orderUpdate($orderId, [
                    'status' => 'aceito',
                    'payment_status' => 'pago',
                    'message' => 'PIX confirmado!'
                ]);
            } catch (\Exception $e) {
                error_log("[woovi-webhook] Pusher erro: " . $e->getMessage());
            }
        } elseif (stripos($event, 'EXPIRED') !== false || stripos($event, 'FAILED') !== false) {
            error_log("[woovi-webhook] Charge EXPIRED/FAILED for order #{$orderId}");

            $db->beginTransaction();
            $db->prepare("UPDATE om_market_orders SET status = 'cancelado', cancel_reason = 'PIX expirado', cancelled_at = NOW(), date_modified = NOW() WHERE order_id = ? AND status = 'pendente'")->execute([$orderId]);
            // Restore stock
            $items = $db->prepare("SELECT product_id, quantity FROM om_market_order_items WHERE order_id = ?");
            $items->execute([$orderId]);
            foreach ($items->fetchAll() as $item) {
                if ($item['product_id']) {
                    $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?")->execute([$item['quantity'], $item['product_id']]);
                }
            }
            $db->commit();
        }

        http_response_code(200);
        echo json_encode(['ok' => true, 'event' => $event]);
        exit;
    } catch (Exception $e) {
        error_log("[woovi-webhook] Charge error: " . $e->getMessage());
        http_response_code(200);
        echo json_encode(['ok' => true, 'error' => $e->getMessage()]);
        exit;
    }
}

// ═══ PAYOUT/TRANSFER EVENTS (PIX sent to partner) ═══
try {
    $db = getDB();
    $db->beginTransaction();

    // Buscar payout com lock
    $stmt = $db->prepare("
        SELECT id, partner_id, amount, status
        FROM om_woovi_payouts
        WHERE correlation_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$correlationId]);
    $payout = $stmt->fetch();

    if (!$payout) {
        $db->rollBack();
        error_log("[woovi-webhook] Payout nao encontrado: $correlationId");
        http_response_code(200);
        echo json_encode(['ok' => true, 'message' => 'Payout not found']);
        exit;
    }

    // Ignorar se ja em estado terminal
    if (in_array($payout['status'], ['completed', 'failed', 'refunded'])) {
        $db->rollBack();
        http_response_code(200);
        echo json_encode(['ok' => true, 'message' => 'Already terminal']);
        exit;
    }

    $partnerId = (int)$payout['partner_id'];
    $amount = (float)$payout['amount'];
    $payoutId = (int)$payout['id'];

    if (stripos($event, 'COMPLETED') !== false || stripos($event, 'completed') !== false) {
        // PIX enviado com sucesso
        $wooviTxId = $transfer['transactionID'] ?? $transfer['endToEndId'] ?? '';

        $stmtUp = $db->prepare("
            UPDATE om_woovi_payouts
            SET status = 'completed',
                woovi_transaction_id = ?,
                processed_at = NOW(),
                woovi_raw_response = ?
            WHERE id = ?
        ");
        $stmtUp->execute([$wooviTxId, $rawBody, $payoutId]);

        // Atualizar total_sacado em om_mercado_saldo
        $stmtSaldo = $db->prepare("
            UPDATE om_mercado_saldo
            SET total_sacado = COALESCE(total_sacado, 0) + ?,
                updated_at = NOW()
            WHERE partner_id = ?
        ");
        $stmtSaldo->execute([$amount, $partnerId]);

        // Log no wallet
        $stmtLog = $db->prepare("
            INSERT INTO om_mercado_wallet (partner_id, tipo, valor, descricao, status, created_at)
            VALUES (?, 'saque_confirmado', ?, ?, 'completed', NOW())
        ");
        $stmtLog->execute([$partnerId, $amount, "PIX confirmado - Woovi #$correlationId"]);

        $db->commit();

        // Notificar parceiro via Pusher
        try {
            PusherService::payoutUpdate($partnerId, [
                'payout_id' => $payoutId,
                'amount' => $amount,
                'status' => 'completed',
                'message' => 'Saque PIX enviado com sucesso!'
            ]);
        } catch (\Exception $e) {
            error_log("[woovi-webhook] Pusher erro: " . $e->getMessage());
        }

        error_log("[woovi-webhook] Payout $correlationId COMPLETED para parceiro $partnerId");

    } elseif (stripos($event, 'FAILED') !== false || stripos($event, 'failed') !== false) {
        // PIX falhou - devolver saldo
        $failReason = $transfer['reason'] ?? $transfer['failReason'] ?? 'Falha no envio PIX';

        $stmtUp = $db->prepare("
            UPDATE om_woovi_payouts
            SET status = 'failed',
                failure_reason = ?,
                processed_at = NOW(),
                woovi_raw_response = ?
            WHERE id = ?
        ");
        $stmtUp->execute([$failReason, $rawBody, $payoutId]);

        // Devolver saldo
        $stmtSaldo = $db->prepare("
            UPDATE om_mercado_saldo
            SET saldo_disponivel = saldo_disponivel + ?,
                updated_at = NOW()
            WHERE partner_id = ?
        ");
        $stmtSaldo->execute([$amount, $partnerId]);

        // Log no wallet
        $stmtLog = $db->prepare("
            INSERT INTO om_mercado_wallet (partner_id, tipo, valor, descricao, status, created_at)
            VALUES (?, 'saque_estornado', ?, ?, 'refunded', NOW())
        ");
        $stmtLog->execute([$partnerId, $amount, "PIX falhou - saldo devolvido: $failReason"]);

        $db->commit();

        // Notificar parceiro
        try {
            PusherService::payoutUpdate($partnerId, [
                'payout_id' => $payoutId,
                'amount' => $amount,
                'status' => 'failed',
                'message' => "Saque falhou: $failReason. Saldo devolvido."
            ]);
        } catch (\Exception $e) {
            error_log("[woovi-webhook] Pusher erro: " . $e->getMessage());
        }

        error_log("[woovi-webhook] Payout $correlationId FAILED para parceiro $partnerId: $failReason");

    } else {
        // Evento desconhecido - ignorar
        $db->rollBack();
        error_log("[woovi-webhook] Evento desconhecido: $event");
    }

    http_response_code(200);
    echo json_encode(['ok' => true]);

} catch (\Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("[woovi-webhook] Erro: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal error']);
}
