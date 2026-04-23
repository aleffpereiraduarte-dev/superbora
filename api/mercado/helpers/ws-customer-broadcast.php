<?php
/**
 * WebSocket broadcast helpers for customer-facing events.
 * Sends via HTTP POST to the local WebSocket server's /broadcast endpoint.
 * Non-blocking: failures are logged but never interrupt the main flow.
 *
 * Usage in pedido/*.php:
 *   require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';
 *   wsBroadcastToCustomer($customer_id, 'order_update', ['order_id' => $oid, 'status' => 'aceito']);
 */

// Lazy-load WS_API_KEY from /var/www/html/.env if PHP-FPM didn't inject it.
function loadWsApiKey(): string {
    static $cached = null;
    if ($cached !== null) return $cached;
    $paths = ['/var/www/html/.env', dirname(__DIR__, 3) . '/.env'];
    foreach ($paths as $p) {
        if (!is_readable($p)) continue;
        foreach (file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos(ltrim($line), '#') === 0) continue;
            if (strpos($line, 'WS_API_KEY=') === 0) {
                $cached = trim(substr($line, strlen('WS_API_KEY=')), "\"' ");
                return $cached;
            }
        }
    }
    $cached = 'superbora-ws-key-2024';
    return $cached;
}

function wsBroadcastToCustomer(int $customerId, string $type, array $data): void {
    wsBroadcastToChannel("user_{$customerId}", $type, $data);
}

function wsBroadcastToOrder(int $orderId, string $type, array $data): void {
    wsBroadcastToChannel("order_{$orderId}", $type, $data);
}

/**
 * Grant a user access to subscribe to an order channel.
 * Must be called before the user can subscribe to order_{orderId}.
 */
function wsGrantOrderAccess(int $userId, int $orderId): void {
    try {
        $payload = json_encode([
            'user_id' => $userId,
            'channel' => "order_{$orderId}",
        ], JSON_UNESCAPED_UNICODE);

        $wsApiKey = $_ENV['WS_API_KEY'] ?? getenv('WS_API_KEY') ?: ($_ENV['WS_API_SECRET'] ?? getenv('WS_API_SECRET')) ?: loadWsApiKey();

        $ch = curl_init('http://127.0.0.1:8080/grant-order');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $wsApiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_NOSIGNAL => 1,
        ]);
        curl_exec($ch);
        curl_close($ch);
    } catch (\Throwable $e) {
        error_log("[ws-broadcast] grant-order error: " . $e->getMessage());
    }
}

function wsBroadcastToGroup(string $shareCode, string $type, array $data): void {
    wsBroadcastToChannel("group_{$shareCode}", $type, $data);
}

/**
 * Broadcast to ALL clients subscribed to the partner's channel (painel do
 * parceiro abre a conexão e assina `partner_{id}` — cada evento cai no
 * dashboard em tempo real, sem precisar polling).
 *
 * Events: new_order, order_update, order_cancel, review_new, chat_message,
 *         store_open, store_close, payout_confirmed.
 */
function wsBroadcastToPartner(int $partnerId, string $type, array $data): void {
    wsBroadcastToChannel("partner_{$partnerId}", $type, $data);
}

/**
 * Broadcast to the `admin_all` channel subscribed by the suporte dashboard
 * web app. One channel keeps things simple (ACL is enforced on the server
 * by only accepting subscribe from tokens with user_type='admin').
 *
 * Events: new_order, order_update, order_cancel, review_new, ticket_new,
 *         ticket_update, user_signup, dispute_new, fraud_flagged, store_toggled.
 */
function wsBroadcastToAdmin(string $type, array $data): void {
    wsBroadcastToChannel("admin_all", $type, $data);
}

/**
 * Convenience: fan out the same event to the customer, the order channel,
 * the partner channel, AND the admin channel. Use sparingly (each call is
 * a separate HTTP POST to the ws-server, but they're ~1ms on localhost).
 */
function wsFanout(array $channels, string $type, array $data): void {
    foreach ($channels as $ch) wsBroadcastToChannel($ch, $type, $data);
}

/**
 * One-shot helper for order state changes — broadcasts to customer, order,
 * partner AND admin channels. Used by pedido/{aceitar,preparando,pronto,
 * recusar,cancelar,confirmar-entrega}.php to keep every dashboard in sync
 * without each endpoint having to remember 4 calls.
 *
 * @param int    $orderId     Order being updated
 * @param int    $customerId  Customer who owns the order
 * @param int    $partnerId   Partner/store the order belongs to
 * @param string $type        Event type: 'order_update', 'order_cancel', ...
 * @param array  $data        Payload (order_id/status/etc)
 */
function wsBroadcastOrderChange(int $orderId, int $customerId, int $partnerId, string $type, array $data): void {
    // The payload has the order_id so subscribers can reason about it.
    $payload = array_merge(['order_id' => $orderId], $data);
    wsBroadcastToChannel("user_{$customerId}",  $type, $payload);
    wsBroadcastToChannel("order_{$orderId}",    $type, $payload);
    wsBroadcastToChannel("partner_{$partnerId}", $type, $payload);
    wsBroadcastToChannel("admin_all",            $type, $payload);
}

/**
 * Broadcast a courier (driver/shopper) live position update to all customers
 * subscribed to the order channel. Used by GPS streaming endpoints + the
 * BoraUm webhook when the driver location updates.
 *
 * @param int   $orderId    Order being delivered
 * @param float $lat        Driver latitude
 * @param float $lng        Driver longitude
 * @param array $extra      Optional: heading, speed_kmh, accuracy_m, eta_minutes, distance_m
 */
function wsBroadcastDriverLocation(int $orderId, float $lat, float $lng, array $extra = []): void {
    wsBroadcastToOrder($orderId, 'driver_location', array_merge([
        'order_id' => $orderId,
        'lat' => $lat,
        'lng' => $lng,
        'heading' => $extra['heading'] ?? null,
        'speed_kmh' => $extra['speed_kmh'] ?? null,
        'accuracy_m' => $extra['accuracy_m'] ?? null,
        'eta_minutes' => $extra['eta_minutes'] ?? null,
        'distance_m' => $extra['distance_m'] ?? null,
    ], $extra));
}

function wsBroadcastToChannel(string $channel, string $type, array $data): void {
    try {
        $payload = json_encode([
            'channel' => $channel,
            'message' => [
                'type' => $type,
                'data' => $data,
                'ts' => date('c'),
            ],
        ], JSON_UNESCAPED_UNICODE);

        $wsApiKey = $_ENV['WS_API_KEY'] ?? getenv('WS_API_KEY') ?: ($_ENV['WS_API_SECRET'] ?? getenv('WS_API_SECRET')) ?: loadWsApiKey();

        $ch = curl_init('http://127.0.0.1:8080/broadcast');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $wsApiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_NOSIGNAL => 1,
        ]);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("[ws-broadcast] HTTP {$httpCode} for {$channel}/{$type}: {$result}");
        }
    } catch (\Throwable $e) {
        error_log("[ws-broadcast] Error: " . $e->getMessage());
    }
}
