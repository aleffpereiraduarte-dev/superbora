<?php
/**
 * Webhook Dispatcher
 *
 * Dispatches events to registered external webhooks with HMAC signing.
 * Logs all delivery attempts for debugging and retry.
 *
 * Usage:
 *   require_once __DIR__ . '/../helpers/webhook-dispatcher.php';
 *   dispatchWebhook($db, 'order.created', ['order_id' => 123, 'total' => 49.90]);
 */

/**
 * Dispatch an event to all active webhooks subscribed to the event type.
 * Uses curl_multi for non-blocking parallel delivery with 5s timeout.
 *
 * @param PDO    $db        Database connection
 * @param string $eventType Event type (e.g., 'order.created', 'payment.confirmed')
 * @param array  $payload   Event payload data
 * @return int Number of webhooks notified
 */
function dispatchWebhook(PDO $db, string $eventType, array $payload): int {
    try {
        // Find all active webhooks subscribed to this event
        $stmt = $db->prepare("
            SELECT id, url, secret, events
            FROM sb_webhooks
            WHERE is_active = true AND ? = ANY(events)
        ");
        $stmt->execute([$eventType]);
        $webhooks = $stmt->fetchAll();

        if (empty($webhooks)) {
            return 0;
        }

        $envelope = [
            'event' => $eventType,
            'timestamp' => date('c'),
            'delivery_id' => bin2hex(random_bytes(16)),
            'data' => $payload,
        ];
        $jsonPayload = json_encode($envelope, JSON_UNESCAPED_UNICODE);

        // Use curl_multi for parallel, non-blocking delivery
        $multiHandle = curl_multi_init();
        $handles = [];

        foreach ($webhooks as $webhook) {
            $signature = hash_hmac('sha256', $jsonPayload, $webhook['secret']);

            $ch = curl_init($webhook['url']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $jsonPayload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json; charset=utf-8',
                    'X-SuperBora-Signature: sha256=' . $signature,
                    'X-SuperBora-Event: ' . $eventType,
                    'X-SuperBora-Delivery: ' . $envelope['delivery_id'],
                    'User-Agent: SuperBora-Webhook/1.0',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            curl_multi_add_handle($multiHandle, $ch);
            $handles[] = [
                'ch' => $ch,
                'webhook_id' => $webhook['id'],
                'start_time' => microtime(true),
            ];
        }

        // Execute all requests
        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            if ($running > 0) {
                curl_multi_select($multiHandle, 0.5);
            }
        } while ($running > 0);

        // Process results and log deliveries
        $sent = 0;
        foreach ($handles as $h) {
            $ch = $h['ch'];
            $info = curl_getinfo($ch);
            $responseBody = curl_multi_getcontent($ch);
            $error = curl_error($ch);
            $durationMs = (int)((microtime(true) - $h['start_time']) * 1000);
            $httpCode = (int)$info['http_code'];

            $status = ($httpCode >= 200 && $httpCode < 300) ? 'success' : 'failed';
            if ($error) {
                $status = 'failed';
                $responseBody = 'curl_error: ' . $error;
            }

            // Log delivery
            try {
                $stmt = $db->prepare("
                    INSERT INTO sb_webhook_deliveries
                        (webhook_id, event_type, payload, response_code, response_body, duration_ms, status, attempts, created_at)
                    VALUES (?, ?, ?::jsonb, ?, ?, ?, ?, 1, NOW())
                ");
                $stmt->execute([
                    $h['webhook_id'],
                    $eventType,
                    $jsonPayload,
                    $httpCode,
                    substr($responseBody ?? '', 0, 2000), // Truncate response
                    $durationMs,
                    $status,
                ]);
            } catch (Exception $logErr) {
                error_log("[webhook-dispatcher] Failed to log delivery: " . $logErr->getMessage());
            }

            if ($status === 'success') {
                $sent++;
            }

            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);

        return $sent;
    } catch (Exception $e) {
        error_log("[webhook-dispatcher] Error dispatching '{$eventType}': " . $e->getMessage());
        return 0;
    }
}

/**
 * Retry a single failed delivery.
 *
 * @param PDO $db         Database connection
 * @param int $deliveryId Delivery ID to retry
 * @return array Result with status and response_code
 */
function retryWebhookDelivery(PDO $db, int $deliveryId): array {
    $stmt = $db->prepare("
        SELECT d.id, d.webhook_id, d.event_type, d.payload, d.attempts,
               w.url, w.secret, w.is_active
        FROM sb_webhook_deliveries d
        JOIN sb_webhooks w ON w.id = d.webhook_id
        WHERE d.id = ?
    ");
    $stmt->execute([$deliveryId]);
    $delivery = $stmt->fetch();

    if (!$delivery) {
        return ['success' => false, 'message' => 'Entrega nao encontrada'];
    }
    if (!$delivery['is_active']) {
        return ['success' => false, 'message' => 'Webhook desativado'];
    }

    $payload = $delivery['payload'];
    $signature = hash_hmac('sha256', $payload, $delivery['secret']);

    $ch = curl_init($delivery['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'X-SuperBora-Signature: sha256=' . $signature,
            'X-SuperBora-Event: ' . $delivery['event_type'],
            'X-SuperBora-Delivery: retry-' . $deliveryId,
            'User-Agent: SuperBora-Webhook/1.0',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $start = microtime(true);
    $responseBody = curl_exec($ch);
    $durationMs = (int)((microtime(true) - $start) * 1000);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $status = ($httpCode >= 200 && $httpCode < 300) ? 'success' : 'failed';
    if ($error) {
        $status = 'failed';
        $responseBody = 'curl_error: ' . $error;
    }

    // Update delivery record
    $stmt = $db->prepare("
        UPDATE sb_webhook_deliveries
        SET response_code = ?, response_body = ?, duration_ms = ?, status = ?, attempts = attempts + 1
        WHERE id = ?
    ");
    $stmt->execute([
        $httpCode,
        substr($responseBody ?? '', 0, 2000),
        $durationMs,
        $status,
        $deliveryId,
    ]);

    return [
        'success' => $status === 'success',
        'status' => $status,
        'response_code' => $httpCode,
        'duration_ms' => $durationMs,
        'attempts' => (int)$delivery['attempts'] + 1,
    ];
}

/**
 * Send a test event to a specific webhook.
 *
 * @param PDO $db        Database connection
 * @param int $webhookId Webhook ID
 * @return array Result with status and details
 */
function sendTestWebhook(PDO $db, int $webhookId): array {
    $stmt = $db->prepare("SELECT id, url, secret, events FROM sb_webhooks WHERE id = ? AND is_active = true");
    $stmt->execute([$webhookId]);
    $webhook = $stmt->fetch();

    if (!$webhook) {
        return ['success' => false, 'message' => 'Webhook nao encontrado ou inativo'];
    }

    $testPayload = [
        'event' => 'test',
        'timestamp' => date('c'),
        'delivery_id' => 'test-' . bin2hex(random_bytes(8)),
        'data' => [
            'message' => 'Este e um evento de teste do SuperBora',
            'webhook_id' => $webhookId,
        ],
    ];
    $jsonPayload = json_encode($testPayload, JSON_UNESCAPED_UNICODE);
    $signature = hash_hmac('sha256', $jsonPayload, $webhook['secret']);

    $ch = curl_init($webhook['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'X-SuperBora-Signature: sha256=' . $signature,
            'X-SuperBora-Event: test',
            'X-SuperBora-Delivery: ' . $testPayload['delivery_id'],
            'User-Agent: SuperBora-Webhook/1.0',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $start = microtime(true);
    $responseBody = curl_exec($ch);
    $durationMs = (int)((microtime(true) - $start) * 1000);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $status = ($httpCode >= 200 && $httpCode < 300) ? 'success' : 'failed';

    // Log the test delivery
    try {
        $stmt = $db->prepare("
            INSERT INTO sb_webhook_deliveries
                (webhook_id, event_type, payload, response_code, response_body, duration_ms, status, attempts, created_at)
            VALUES (?, 'test', ?::jsonb, ?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([
            $webhookId,
            $jsonPayload,
            $httpCode,
            substr(($error ?: $responseBody) ?? '', 0, 2000),
            $durationMs,
            $status,
        ]);
    } catch (Exception $e) {
        error_log("[webhook-dispatcher] Failed to log test delivery: " . $e->getMessage());
    }

    return [
        'success' => $status === 'success',
        'status' => $status,
        'response_code' => $httpCode,
        'duration_ms' => $durationMs,
        'error' => $error ?: null,
    ];
}
