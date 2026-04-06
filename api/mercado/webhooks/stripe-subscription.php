<?php
/**
 * POST /api/mercado/webhooks/stripe-subscription.php
 * Stripe webhook for subscription lifecycle events
 *
 * Events handled:
 * - invoice.paid → Renew membership period
 * - invoice.payment_failed → Mark as past_due
 * - customer.subscription.deleted → Mark as expired
 * - customer.subscription.updated → Update plan/status
 */

// Load database config (includes getDB(), response(), etc.)
require_once __DIR__ . "/../config/database.php";

// Stripe sends JSON
header("Content-Type: application/json");

// Read raw body for signature verification
$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Load Stripe webhook secret
$webhookSecret = '';
$envFile = dirname(dirname(__DIR__)) . '/.env.stripe';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        if (trim($k) === 'STRIPE_WEBHOOK_SECRET_SUBSCRIPTION') {
            $webhookSecret = trim($v);
        }
    }
}

// Verify webhook signature if secret is configured
if ($webhookSecret && $sigHeader) {
    $elements = [];
    foreach (explode(',', $sigHeader) as $part) {
        [$k, $v] = explode('=', trim($part), 2);
        $elements[$k] = $v;
    }

    $timestamp = $elements['t'] ?? '';
    $signature = $elements['v1'] ?? '';

    if (!$timestamp || !$signature) {
        error_log("[stripe-sub-webhook] Missing signature components");
        http_response_code(400);
        echo json_encode(['error' => 'Missing signature']);
        exit;
    }

    // Replay protection: reject events older than 5 minutes
    if (abs(time() - (int)$timestamp) > 300) {
        error_log("[stripe-sub-webhook] Timestamp too old");
        http_response_code(400);
        echo json_encode(['error' => 'Timestamp expired']);
        exit;
    }

    $expectedSig = hash_hmac('sha256', "{$timestamp}.{$payload}", $webhookSecret);
    if (!hash_equals($expectedSig, $signature)) {
        error_log("[stripe-sub-webhook] Invalid signature");
        http_response_code(400);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
}

$event = json_decode($payload, true);
if (!$event || empty($event['type'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

$eventType = $event['type'];
$data = $event['data']['object'] ?? [];

error_log("[stripe-sub-webhook] Event: {$eventType}, ID: " . ($data['id'] ?? 'n/a'));

try {
    $db = getDB();

    switch ($eventType) {

        // ═══════════════════════════════════════════════════════
        // Invoice paid → Renew membership
        // ═══════════════════════════════════════════════════════
        case 'invoice.paid':
            $subscriptionId = $data['subscription'] ?? '';
            $invoiceId = $data['id'] ?? '';
            $amountPaid = ($data['amount_paid'] ?? 0) / 100; // cents → BRL
            $periodEnd = $data['lines']['data'][0]['period']['end'] ?? null;

            if (!$subscriptionId) break;

            // Find membership by stripe_subscription_id
            $stmt = $db->prepare("
                SELECT id, customer_id, plan, status FROM om_customer_memberships
                WHERE stripe_subscription_id = ?
                LIMIT 1
            ");
            $stmt->execute([$subscriptionId]);
            $membership = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$membership) {
                error_log("[stripe-sub-webhook] No membership for sub {$subscriptionId}");
                break;
            }

            // Update period
            $newPeriodEnd = $periodEnd ? date('Y-m-d H:i:s', $periodEnd) : date('Y-m-d H:i:s', strtotime('+1 month'));

            $db->prepare("
                UPDATE om_customer_memberships
                SET status = 'active',
                    current_period_start = NOW(),
                    current_period_end = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$newPeriodEnd, (int)$membership['id']]);

            // Record payment (idempotent check on invoice_id)
            $stmtCheck = $db->prepare("SELECT 1 FROM om_membership_payments WHERE stripe_invoice_id = ? LIMIT 1");
            $stmtCheck->execute([$invoiceId]);
            if (!$stmtCheck->fetch()) {
                $piId = $data['payment_intent'] ?? '';
                $db->prepare("
                    INSERT INTO om_membership_payments (membership_id, amount, status, stripe_invoice_id, stripe_payment_intent_id, paid_at, created_at)
                    VALUES (?, ?, 'paid', ?, ?, NOW(), NOW())
                ")->execute([(int)$membership['id'], $amountPaid, $invoiceId, $piId ?: null]);
            }

            error_log("[stripe-sub-webhook] Renewed membership {$membership['id']} until {$newPeriodEnd}");
            break;

        // ═══════════════════════════════════════════════════════
        // Invoice payment failed → Mark as past_due
        // ═══════════════════════════════════════════════════════
        case 'invoice.payment_failed':
            $subscriptionId = $data['subscription'] ?? '';
            $invoiceId = $data['id'] ?? '';

            if (!$subscriptionId) break;

            $db->prepare("
                UPDATE om_customer_memberships
                SET status = 'past_due', updated_at = NOW()
                WHERE stripe_subscription_id = ?
                AND status IN ('active', 'trialing')
            ")->execute([$subscriptionId]);

            // Record failed payment
            $amountDue = ($data['amount_due'] ?? 0) / 100;
            $stmtCheck = $db->prepare("SELECT 1 FROM om_membership_payments WHERE stripe_invoice_id = ? LIMIT 1");
            $stmtCheck->execute([$invoiceId]);
            if (!$stmtCheck->fetch()) {
                $stmt = $db->prepare("SELECT id FROM om_customer_memberships WHERE stripe_subscription_id = ? LIMIT 1");
                $stmt->execute([$subscriptionId]);
                $mid = $stmt->fetchColumn();
                if ($mid) {
                    $db->prepare("
                        INSERT INTO om_membership_payments (membership_id, amount, status, stripe_invoice_id, created_at)
                        VALUES (?, ?, 'failed', ?, NOW())
                    ")->execute([(int)$mid, $amountDue, $invoiceId]);
                }
            }

            error_log("[stripe-sub-webhook] Payment failed for sub {$subscriptionId}");
            break;

        // ═══════════════════════════════════════════════════════
        // Subscription deleted → Mark as expired
        // ═══════════════════════════════════════════════════════
        case 'customer.subscription.deleted':
            $subscriptionId = $data['id'] ?? '';

            if (!$subscriptionId) break;

            $db->prepare("
                UPDATE om_customer_memberships
                SET status = 'expired', updated_at = NOW()
                WHERE stripe_subscription_id = ?
                AND status NOT IN ('expired')
            ")->execute([$subscriptionId]);

            error_log("[stripe-sub-webhook] Subscription {$subscriptionId} deleted/expired");
            break;

        // ═══════════════════════════════════════════════════════
        // Subscription updated → Update plan details
        // ═══════════════════════════════════════════════════════
        case 'customer.subscription.updated':
            $subscriptionId = $data['id'] ?? '';
            $stripeStatus = $data['status'] ?? '';  // active, past_due, canceled, trialing
            $cancelAtPeriodEnd = $data['cancel_at_period_end'] ?? false;
            $currentPeriodEnd = $data['current_period_end'] ?? null;

            if (!$subscriptionId) break;

            $statusMap = [
                'active' => 'active',
                'trialing' => 'trialing',
                'past_due' => 'past_due',
                'canceled' => 'expired',
                'incomplete' => 'past_due',
                'incomplete_expired' => 'expired',
                'unpaid' => 'past_due',
            ];

            $newStatus = $statusMap[$stripeStatus] ?? $stripeStatus;
            $newPeriodEnd = $currentPeriodEnd ? date('Y-m-d H:i:s', $currentPeriodEnd) : null;

            $updates = ["status = ?", "cancel_at_period_end = ?", "updated_at = NOW()"];
            $params = [$newStatus, $cancelAtPeriodEnd ? 'true' : 'false'];

            if ($newPeriodEnd) {
                $updates[] = "current_period_end = ?";
                $params[] = $newPeriodEnd;
            }

            if ($cancelAtPeriodEnd) {
                $updates[] = "cancelled_at = COALESCE(cancelled_at, NOW())";
            }

            $params[] = $subscriptionId;
            $sql = "UPDATE om_customer_memberships SET " . implode(', ', $updates) . " WHERE stripe_subscription_id = ?";
            $db->prepare($sql)->execute($params);

            error_log("[stripe-sub-webhook] Subscription {$subscriptionId} updated: status={$newStatus}, cancel_at_end={$cancelAtPeriodEnd}");
            break;

        default:
            error_log("[stripe-sub-webhook] Unhandled event: {$eventType}");
    }

    // Always return 200 to acknowledge receipt
    http_response_code(200);
    echo json_encode(['received' => true]);

} catch (Exception $e) {
    error_log("[stripe-sub-webhook] Error: " . $e->getMessage());
    // Still return 200 to prevent Stripe retries on our DB errors
    http_response_code(200);
    echo json_encode(['received' => true, 'error' => 'Internal processing error']);
}
