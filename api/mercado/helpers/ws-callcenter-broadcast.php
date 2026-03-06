<?php
/**
 * Call Center WebSocket Broadcast Helper
 * Sends events to the WebSocket server for real-time updates
 */

function callcenterBroadcast(string $channel, string $event, array $data = []): bool {
    $wsUrl = 'http://127.0.0.1:8080/broadcast';
    $apiKey = getenv('WS_API_KEY') ?: getenv('WS_API_SECRET') ?: 'superbora-ws-key-2024';

    $payload = json_encode([
        'channel' => $channel,
        'message' => [
            'type' => $event,
            'data' => $data,
            'ts' => date('c'),
        ],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($wsUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-API-Key: ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_NOSIGNAL => 1,
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("[ws-callcenter] Broadcast failed: channel={$channel} event={$event} http={$httpCode} body={$result}");
        return false;
    }
    return true;
}

/**
 * Broadcast to the shared call center dashboard channel
 */
function ccBroadcastDashboard(string $event, array $data = []): bool {
    return callcenterBroadcast('callcenter', $event, $data);
}

/**
 * Broadcast to a specific agent's channel
 */
function ccBroadcastAgent(int $agentId, string $event, array $data = []): bool {
    return callcenterBroadcast("callcenter_agent_{$agentId}", $event, $data);
}
