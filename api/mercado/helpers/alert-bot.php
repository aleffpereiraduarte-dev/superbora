<?php
/**
 * ============================================================================
 * Slack / Discord Alert Bot
 * ============================================================================
 *
 * Sends webhook alerts to Slack and Discord channels.
 *
 * Usage:
 *   require_once __DIR__ . '/alert-bot.php';
 *
 *   // Slack
 *   sendSlackAlert($webhookUrl, '#alerts', 'Order cancelled', 'warning', [
 *       'Order ID' => '12345',
 *       'Customer' => 'Joao Silva',
 *   ]);
 *
 *   // Discord
 *   sendDiscordAlert($webhookUrl, 'Payment failed', 'danger', [
 *       'Order ID' => '12345',
 *       'Amount'   => 'R$ 150.00',
 *   ]);
 *
 *   // Auto-dispatch to all configured webhooks for an event
 *   dispatchAlert('order_cancelled', 'Order #12345 was cancelled by customer', 'warning', [
 *       'order_id' => '12345',
 *       'reason'   => 'Customer requested',
 *   ]);
 *
 * ============================================================================
 */

// Level -> color mapping for Slack (hex) and Discord (decimal)
define('ALERT_COLORS', [
    'info'    => ['slack' => '#2196F3', 'discord' => 2201331],   // blue
    'warning' => ['slack' => '#FFC107', 'discord' => 16761095],  // yellow
    'danger'  => ['slack' => '#F44336', 'discord' => 15994678],  // red
    'success' => ['slack' => '#4CAF50', 'discord' => 4895567],   // green
]);

// Level -> emoji for message headers
define('ALERT_EMOJI', [
    'info'    => ':information_source:',
    'warning' => ':warning:',
    'danger'  => ':rotating_light:',
    'success' => ':white_check_mark:',
]);

/**
 * Get the server identifier for alert messages.
 */
function getServerName(): string {
    static $name = null;
    if ($name === null) {
        $name = gethostname() ?: 'unknown';
        // Try to get a friendly name from env
        $envName = $_ENV['SERVER_NAME'] ?? getenv('SERVER_NAME') ?: '';
        if ($envName) $name = $envName;
    }
    return $name;
}

/**
 * Send an alert to a Slack webhook.
 *
 * @param string $webhookUrl  Slack Incoming Webhook URL
 * @param string $message     Main message text
 * @param string $level       info|warning|danger|success
 * @param array  $details     Key-value pairs for attachment fields
 * @param string $channel     Optional channel override (e.g. '#alerts')
 * @return bool True if sent successfully
 */
function sendSlackAlert(string $webhookUrl, string $message, string $level = 'info', array $details = [], string $channel = ''): bool {
    if (empty($webhookUrl)) return false;

    $color = ALERT_COLORS[$level]['slack'] ?? ALERT_COLORS['info']['slack'];
    $emoji = ALERT_EMOJI[$level] ?? ALERT_EMOJI['info'];
    $server = getServerName();
    $timestamp = date('Y-m-d H:i:s T');
    $levelBadge = strtoupper($level);

    // Build attachment fields
    $fields = [
        [
            'title' => 'Server',
            'value' => $server,
            'short' => true,
        ],
        [
            'title' => 'Level',
            'value' => $levelBadge,
            'short' => true,
        ],
        [
            'title' => 'Time',
            'value' => $timestamp,
            'short' => true,
        ],
    ];

    foreach ($details as $key => $value) {
        $fields[] = [
            'title' => $key,
            'value' => (string)$value,
            'short' => strlen((string)$value) < 40,
        ];
    }

    $payload = [
        'text' => "{$emoji} *[{$levelBadge}]* {$message}",
        'attachments' => [
            [
                'color'  => $color,
                'fields' => $fields,
                'footer' => "SuperBora Alert Bot | {$server}",
                'ts'     => time(),
            ],
        ],
    ];

    if ($channel) {
        $payload['channel'] = $channel;
    }

    return sendWebhook($webhookUrl, $payload);
}

/**
 * Send an alert to a Discord webhook.
 *
 * @param string $webhookUrl  Discord Webhook URL
 * @param string $message     Main message text
 * @param string $level       info|warning|danger|success
 * @param array  $details     Key-value pairs for embed fields
 * @return bool True if sent successfully
 */
function sendDiscordAlert(string $webhookUrl, string $message, string $level = 'info', array $details = []): bool {
    if (empty($webhookUrl)) return false;

    $color = ALERT_COLORS[$level]['discord'] ?? ALERT_COLORS['info']['discord'];
    $server = getServerName();
    $timestamp = date('c');
    $levelBadge = strtoupper($level);

    $emojiMap = [
        'info'    => 'ℹ️',
        'warning' => '⚠️',
        'danger'  => '🚨',
        'success' => '✅',
    ];
    $emoji = $emojiMap[$level] ?? 'ℹ️';

    // Build embed fields
    $fields = [
        [
            'name'   => 'Server',
            'value'  => $server,
            'inline' => true,
        ],
        [
            'name'   => 'Level',
            'value'  => $levelBadge,
            'inline' => true,
        ],
    ];

    foreach ($details as $key => $value) {
        $fields[] = [
            'name'   => $key,
            'value'  => (string)$value,
            'inline' => strlen((string)$value) < 40,
        ];
    }

    $payload = [
        'content' => "{$emoji} **[{$levelBadge}]** {$message}",
        'embeds'  => [
            [
                'title'       => "SuperBora Alert",
                'description' => $message,
                'color'       => $color,
                'fields'      => $fields,
                'footer'      => [
                    'text' => "SuperBora Alert Bot | {$server}",
                ],
                'timestamp'   => $timestamp,
            ],
        ],
        'username'   => 'SuperBora Alerts',
        'avatar_url' => 'https://superbora.com.br/assets/logo-icon.png',
    ];

    return sendWebhook($webhookUrl, $payload);
}

/**
 * Dispatch an alert to all configured webhooks that subscribe to the given event.
 *
 * @param string $event   Event name (e.g. 'order_cancelled', 'payment_failed')
 * @param string $message Alert message
 * @param string $level   info|warning|danger|success
 * @param array  $details Key-value details
 * @return int Number of webhooks notified
 */
function dispatchAlert(string $event, string $message, string $level = 'info', array $details = []): int {
    try {
        // Lazy-load database only when dispatching
        if (!function_exists('getDB')) {
            require_once __DIR__ . '/../config/database.php';
        }
        $db = getDB();

        // Find all active webhooks subscribed to this event
        $stmt = $db->prepare("
            SELECT id, type, webhook_url
            FROM sb_alert_webhooks
            WHERE is_active = true
              AND ? = ANY(events)
        ");
        $stmt->execute([$event]);
        $webhooks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sent = 0;
        foreach ($webhooks as $wh) {
            $ok = false;
            if ($wh['type'] === 'slack') {
                $ok = sendSlackAlert($wh['webhook_url'], $message, $level, $details);
            } elseif ($wh['type'] === 'discord') {
                $ok = sendDiscordAlert($wh['webhook_url'], $message, $level, $details);
            }
            if ($ok) $sent++;
        }

        return $sent;
    } catch (Exception $e) {
        error_log("[alert-bot] dispatchAlert failed: " . $e->getMessage());
        return 0;
    }
}

/**
 * Low-level: send a JSON payload to a webhook URL via cURL.
 * Timeout is short (5s) to avoid blocking the caller.
 *
 * @param string $url     Webhook URL
 * @param array  $payload Data to JSON-encode and POST
 * @return bool
 */
function sendWebhook(string $url, array $payload): bool {
    try {
        $ch = curl_init($url);
        if (!$ch) return false;

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Content-Length: ' . strlen($json)],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("[alert-bot] cURL error to {$url}: {$error}");
            return false;
        }

        // Slack returns 200, Discord returns 204 on success
        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }

        error_log("[alert-bot] Webhook returned HTTP {$httpCode} for {$url}: " . substr($response, 0, 200));
        return false;

    } catch (Exception $e) {
        error_log("[alert-bot] Exception sending webhook: " . $e->getMessage());
        return false;
    }
}
