<?php
/**
 * POST /api/mercado/webhooks/mercadopago.php
 *
 * Webhook receiver for Mercado Pago payment notifications.
 *
 * Mercado Pago always sends webhooks in the same shape:
 *   { "action": "payment.updated", "type": "payment", "data": { "id": "1234567890" } }
 *   plus query params: ?topic=payment&id=1234567890
 *
 * Flow:
 *   1. Validate the x-signature header (HMAC SHA256 over id+request-id+ts).
 *   2. Pull the payment id from body OR query.
 *   3. Call MP /v1/payments/{id} to verify status (NEVER trust webhook payload alone).
 *   4. Look up the matching om_pix_intents row by provider_payment_id.
 *   5. If status=approved AND intent is still pending, hand off to the existing
 *      EFI webhook order-creation routine by reusing the same DB transaction
 *      logic. To avoid duplicating ~300 lines, this file forks into a tiny
 *      shared helper at the bottom (intent_to_order). The helper mirrors what
 *      efi.php already does, minimised to the essentials.
 */

require_once __DIR__ . '/../config/database.php';
require_once dirname(__DIR__, 3) . '/includes/classes/MercadoPagoClient.php';
require_once dirname(__DIR__, 3) . '/includes/classes/OmPricing.php';
require_once dirname(__DIR__) . '/helpers/notify.php';
require_once dirname(__DIR__) . '/helpers/ws-customer-broadcast.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'GET')     { http_response_code(200); echo json_encode(['status' => 'active']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody ?: '[]', true) ?: [];

// Validate signature (HMAC SHA256). Skips when MP_WEBHOOK_SECRET is empty.
$mp = new MercadoPagoClient();
$headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
if (!$mp->validateWebhookSignature($headers, $rawBody)) {
    error_log('[mp-webhook] Signature validation failed');
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

// Extract payment id (MP sends it in multiple shapes)
$paymentId = $payload['data']['id']
    ?? $payload['id']
    ?? $_GET['data.id']
    ?? $_GET['id']
    ?? '';

// Filter: only react to payment.* topics; ignore plan/subscription noise.
$topic = $payload['type'] ?? $payload['topic'] ?? $_GET['topic'] ?? $_GET['type'] ?? '';
if ($topic && stripos($topic, 'payment') === false) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => 'not a payment topic']);
    exit;
}

if (!$paymentId) {
    error_log('[mp-webhook] Empty payment id, raw=' . substr($rawBody, 0, 300));
    http_response_code(400);
    echo json_encode(['error' => 'Missing payment id']);
    exit;
}

// Verify the payment status server-side (never trust the body alone)
$status = $mp->checkChargeStatus((string)$paymentId);
if (!$status['success']) {
    error_log("[mp-webhook] checkChargeStatus failed for {$paymentId}: " . ($status['error'] ?? '?'));
    http_response_code(500);
    echo json_encode(['error' => 'status check failed']);
    exit;
}

if (!$status['paid']) {
    // Not yet paid (could be pending/rejected/refunded). Acknowledge but skip.
    http_response_code(200);
    echo json_encode(['ok' => true, 'mp_status' => $status['mp_status']]);
    exit;
}

$verifiedAmount = (float)($status['amount'] ?? 0);

// Look up the intent by provider_payment_id
$db = getDB();
$intentStmt = $db->prepare("
    SELECT intent_id, customer_id, amount_cents, cart_snapshot, correlation_id, status,
           provider, provider_payment_id, expires_at
    FROM om_pix_intents
    WHERE provider_payment_id = ? AND provider = 'mp'
    LIMIT 1
");
$intentStmt->execute([(string)$paymentId]);
$intent = $intentStmt->fetch(PDO::FETCH_ASSOC);

if (!$intent) {
    // Could be a wallet deposit or unrelated MP payment — log but ack so MP stops retrying
    error_log("[mp-webhook] No intent for payment_id={$paymentId} (might be deposit/other)");
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => 'no matching intent']);
    exit;
}

if ($intent['status'] !== 'pending') {
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => 'already processed', 'intent_id' => $intent['intent_id']]);
    exit;
}

// Validate amount matches what we billed (5 centavos tolerance)
$expectedAmount = (float)$intent['amount_cents'] / 100;
if ($expectedAmount > 0 && abs($verifiedAmount - $expectedAmount) > 0.05) {
    error_log("[mp-webhook] SECURITY: amount mismatch payment_id={$paymentId} paid={$verifiedAmount} expected={$expectedAmount}");
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => 'amount mismatch']);
    exit;
}

// Hand off to the shared intent-to-order helper. Returns an order_id on
// success, throws on failure (caught below).
try {
    require_once __DIR__ . '/_intent-to-order.php';
    $orderId = processPaidPixIntent($db, $intent, $verifiedAmount, (string)$paymentId);
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'intent_id' => (int)$intent['intent_id'],
        'order_id' => $orderId,
        'mp_payment_id' => (string)$paymentId,
    ]);
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[mp-webhook] processPaidPixIntent failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'order creation failed']);
}
