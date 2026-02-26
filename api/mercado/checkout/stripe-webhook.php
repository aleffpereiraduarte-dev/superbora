<?php
/**
 * POST /api/mercado/checkout/stripe-webhook.php
 * Webhook do Stripe - confirma pagamento e atualiza pedido
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();

// Carregar webhook secret
$stripeEnv = dirname(__DIR__, 3) . '/.env.stripe';
$WEBHOOK_SECRET = '';
if (file_exists($stripeEnv)) {
    $lines = file($stripeEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            if (trim($key) === 'STRIPE_WEBHOOK_SECRET') $WEBHOOK_SECRET = trim($value);
        }
    }
}

$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// MANDATORY: Webhook secret must be configured
if (!$WEBHOOK_SECRET) {
    error_log("[stripe-webhook] REJECTED: STRIPE_WEBHOOK_SECRET not configured");
    http_response_code(500);
    echo json_encode(['error' => 'Webhook secret not configured']);
    exit;
}

// MANDATORY: Signature validation
$timestamp = '';
$signature = '';
foreach (explode(',', $sigHeader) as $part) {
    $part = trim($part);
    if (strpos($part, 't=') === 0) $timestamp = substr($part, 2);
    if (strpos($part, 'v1=') === 0) $signature = substr($part, 3);
}

if (!$timestamp || !$signature) {
    error_log("[stripe-webhook] REJECTED: Missing timestamp or signature in header");
    http_response_code(400);
    echo json_encode(['error' => 'Missing signature components']);
    exit;
}

$signedPayload = $timestamp . '.' . $payload;
$expected = hash_hmac('sha256', $signedPayload, $WEBHOOK_SECRET);
if (!hash_equals($expected, $signature)) {
    error_log("[stripe-webhook] REJECTED: Invalid signature");
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

// Tolerar 5 minutos
if (abs(time() - (int)$timestamp) > 300) {
    error_log("[stripe-webhook] REJECTED: Timestamp too old");
    http_response_code(400);
    echo json_encode(['error' => 'Timestamp too old']);
    exit;
}

$event = json_decode($payload, true);
if (!$event || empty($event['type']) || empty($event['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

try {
    $db = getDB();

    // Table om_stripe_webhook_events created via migration

    // Idempotency check: skip only if this event was already PROCESSED successfully
    $eventId = $event['id'];
    try {
        $db->prepare("
            INSERT INTO om_stripe_webhook_events (event_id, event_type, status) VALUES (?, ?, 'received')
        ")->execute([$eventId, $event['type']]);
    } catch (Exception $dupEx) {
        // Duplicate event_id — check if it was successfully processed
        $stmtCheck = $db->prepare("SELECT status FROM om_stripe_webhook_events WHERE event_id = ?");
        $stmtCheck->execute([$eventId]);
        $existing = $stmtCheck->fetch();
        if ($existing && $existing['status'] === 'processed') {
            error_log("[stripe-webhook] Duplicate event skipped (already processed): $eventId");
            http_response_code(200);
            echo json_encode(['received' => true, 'duplicate' => true]);
            exit;
        }
        // If status is 'received' or 'failed', allow retry by continuing
        error_log("[stripe-webhook] Retrying event $eventId (previous status: " . ($existing['status'] ?? 'unknown') . ")");
    }

    $eventType = $event['type'];
    $object = $event['data']['object'] ?? [];

    error_log("[stripe-webhook] Evento: $eventType | ID: " . ($object['id'] ?? ''));

    switch ($eventType) {
        case 'payment_intent.succeeded':
            $piId = $object['id'];
            $orderId = (int)($object['metadata']['order_id'] ?? 0);

            // Fallback: look up order by payment_id if not in metadata
            if (!$orderId && $piId) {
                $stmtLookup = $db->prepare("SELECT order_id FROM om_market_orders WHERE payment_id = ? LIMIT 1");
                $stmtLookup->execute([$piId]);
                $orderId = (int)$stmtLookup->fetchColumn();
            }

            if ($orderId) {
                $db->prepare("
                    UPDATE om_market_orders
                    SET payment_status = 'paid', paid_at = NOW(), payment_id = ?, pagamento_status = 'pago'
                    WHERE order_id = ? AND payment_status != 'paid'
                ")->execute([$piId, $orderId]);

                $db->prepare("
                    INSERT INTO om_market_order_events (order_id, event_type, message, created_by, created_at)
                    VALUES (?, 'payment_confirmed', 'Pagamento confirmado via Stripe', 'stripe', NOW())
                ")->execute([$orderId]);

                // Notificar cliente e parceiro
                require_once __DIR__ . '/../config/notify.php';
                $stmt = $db->prepare("SELECT customer_id, partner_id, order_number, total FROM om_market_orders WHERE order_id = ?");
                $stmt->execute([$orderId]);
                $order = $stmt->fetch();
                if ($order) {
                    sendNotification($db, (int)$order['customer_id'], 'customer',
                        'Pagamento confirmado!',
                        "Pagamento do pedido #{$order['order_number']} confirmado!",
                        ['order_id' => $orderId, 'url' => '/pedidos?id=' . $orderId]
                    );
                    sendNotification($db, (int)$order['partner_id'], 'partner',
                        'Pagamento recebido!',
                        "Pedido #{$order['order_number']} - Pagamento de R$ " . number_format($order['total'], 2, ',', '.') . " confirmado",
                        ['order_id' => $orderId, 'url' => '/pedidos']
                    );
                }
                error_log("[stripe-webhook] Pedido #$orderId pago via Stripe PI=$piId");
            }
            break;

        case 'payment_intent.payment_failed':
            $piId = $object['id'];
            $orderId = (int)($object['metadata']['order_id'] ?? 0);
            $failMsg = $object['last_payment_error']['message'] ?? 'Pagamento recusado';

            if ($orderId) {
                // Só marca como failed se ainda não foi pago (evita reverter pagamento já confirmado)
                $db->prepare("
                    UPDATE om_market_orders SET payment_status = 'failed', pagarme_status = 'failed'
                    WHERE order_id = ? AND payment_status NOT IN ('paid', 'refunded')
                ")->execute([$orderId]);

                $db->prepare("
                    INSERT INTO om_market_order_events (order_id, event_type, message, created_by, created_at)
                    VALUES (?, 'payment_failed', ?, 'stripe', NOW())
                ")->execute([$orderId, "Pagamento falhou: $failMsg"]);

                error_log("[stripe-webhook] Pagamento falhou pedido #$orderId: $failMsg");
            }
            break;

        case 'charge.refunded':
            $piId = $object['payment_intent'] ?? '';
            if ($piId) {
                $stmt = $db->prepare("SELECT order_id FROM om_market_orders WHERE payment_id = ?");
                $stmt->execute([$piId]);
                $orderId = (int)$stmt->fetchColumn();
                if ($orderId) {
                    $refundAmount = ($object['amount_refunded'] ?? 0) / 100;
                    $db->prepare("
                        UPDATE om_market_orders SET refund_status = 'completed', refund_amount = ?, refunded_at = NOW()
                        WHERE order_id = ?
                    ")->execute([$refundAmount, $orderId]);

                    error_log("[stripe-webhook] Reembolso pedido #$orderId: R$" . number_format($refundAmount, 2));
                }
            }
            break;
    }

    // Mark event as successfully processed
    $db->prepare("
        UPDATE om_stripe_webhook_events SET status = 'processed', processed_at = NOW() WHERE event_id = ?
    ")->execute([$eventId]);

    http_response_code(200);
    echo json_encode(['received' => true]);

} catch (Exception $e) {
    error_log("[stripe-webhook] Erro: " . $e->getMessage());
    // Mark event as failed so retries are allowed
    if (isset($db) && isset($eventId)) {
        try {
            $db->prepare("
                UPDATE om_stripe_webhook_events SET status = 'failed', error_message = ? WHERE event_id = ?
            ")->execute([substr($e->getMessage(), 0, 500), $eventId]);
        } catch (Exception $updateErr) {
            error_log("[stripe-webhook] Failed to update event status: " . $updateErr->getMessage());
        }
    }
    http_response_code(500);
    echo json_encode(['error' => 'Internal error']);
}
