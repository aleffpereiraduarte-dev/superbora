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

function wsBroadcastToCustomer(int $customerId, string $type, array $data): void {
    wsBroadcastToChannel("user_{$customerId}", $type, $data);
}

function wsBroadcastToOrder(int $orderId, string $type, array $data): void {
    wsBroadcastToChannel("order_{$orderId}", $type, $data);
}

function wsBroadcastToGroup(string $shareCode, string $type, array $data): void {
    wsBroadcastToChannel("group_{$shareCode}", $type, $data);
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

        $wsApiKey = getenv('WS_API_KEY') ?: getenv('WS_API_SECRET') ?: 'superbora-ws-key-2024';

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
