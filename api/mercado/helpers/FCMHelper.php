<?php
/**
 * ====================================================================
 * FCMHelper - Push Notification Sender via Expo Push API
 * ====================================================================
 *
 * Sends push notifications via Expo Push Service.
 * The mobile app generates Expo Push Tokens (ExponentPushToken[xxx])
 * and stores them in om_market_push_tokens. This helper sends to
 * Expo's push API which handles FCM/APNs delivery.
 *
 * No server key needed - Expo Push uses the project's access token
 * (optional) or works without auth for basic usage.
 *
 * Usage:
 *   require_once __DIR__ . '/FCMHelper.php';
 *   $fcm = FCMHelper::getInstance($db);
 *   $fcm->sendToToken($token, 'Title', 'Body', ['order_id' => 123]);
 *   $fcm->sendToTokens([$t1, $t2], 'Title', 'Body');
 */

class FCMHelper
{
    private const EXPO_PUSH_ENDPOINT = 'https://exp.host/--/api/v2/push/send';
    private const LOG_PREFIX = '[ExpoPush]';
    private const MAX_BATCH_SIZE = 100; // Expo recommends max 100 per request

    // Circuit breaker state (APCu keys)
    private const CB_FAILURES_KEY   = 'expo_push_failures';
    private const CB_LAST_FAIL_KEY  = 'expo_push_last_failure';
    private const CB_OPEN_LOG_KEY   = 'expo_push_circuit_open_logged';
    private const CB_THRESHOLD      = 3;   // failures before opening
    private const CB_COOLDOWN_SEC   = 60;  // skip sends for 60s after threshold
    private const CB_TTL_SEC        = 300; // APCu TTL for failure counters

    private PDO $db;
    private string $accessToken;
    private static ?self $instance = null;

    private function __construct(PDO $db)
    {
        $this->db = $db;
        $this->accessToken = $this->loadAccessToken();
    }

    public static function getInstance(PDO $db): self
    {
        if (self::$instance === null) {
            self::$instance = new self($db);
        } else {
            // Always update PDO to avoid stale connection issues
            self::$instance->db = $db;
        }
        return self::$instance;
    }

    /**
     * Always configured - Expo Push works without auth for basic usage
     */
    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * Send push notification to a single Expo Push Token
     */
    public function sendToToken(string $token, string $title, string $body, array $data = []): array
    {
        if (empty($token)) {
            return ['success' => false, 'error' => 'Empty token'];
        }

        $messages = [$this->buildMessage($token, $title, $body, $data)];
        $result = $this->sendRequest($messages);

        if ($result['success'] && !empty($result['tickets'])) {
            $ticket = $result['tickets'][0];
            if (($ticket['status'] ?? '') === 'error') {
                $error = $ticket['message'] ?? ($ticket['details']['error'] ?? 'Unknown');
                if ($this->isInvalidTokenError($ticket)) {
                    $this->removeInvalidToken($token);
                    $this->log('INFO', "Removed invalid token: " . substr($token, 0, 30) . '...');
                }
                return ['success' => false, 'error' => $error];
            }
            return ['success' => true, 'message_id' => $ticket['id'] ?? null];
        }

        return $result;
    }

    /**
     * Send push notification to multiple Expo Push Tokens
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
    {
        $tokens = array_values(array_unique(array_filter($tokens)));

        if (empty($tokens)) {
            return ['success' => true, 'sent' => 0, 'failed' => 0, 'invalid_tokens' => []];
        }

        $totalSent = 0;
        $totalFailed = 0;
        $invalidTokens = [];

        $batches = array_chunk($tokens, self::MAX_BATCH_SIZE);

        foreach ($batches as $batch) {
            $messages = [];
            foreach ($batch as $token) {
                $messages[] = $this->buildMessage($token, $title, $body, $data);
            }

            $result = $this->sendRequest($messages);

            if ($result['success'] && !empty($result['tickets'])) {
                foreach ($result['tickets'] as $idx => $ticket) {
                    if (($ticket['status'] ?? '') === 'ok') {
                        $totalSent++;
                    } else {
                        $totalFailed++;
                        if ($this->isInvalidTokenError($ticket)) {
                            $invalidTokens[] = $batch[$idx];
                        }
                    }
                }
            } else {
                $totalFailed += count($batch);
            }
        }

        if (!empty($invalidTokens)) {
            $this->removeInvalidTokens($invalidTokens);
            $this->log('INFO', 'Removed ' . count($invalidTokens) . ' invalid tokens from DB');
        }

        $this->log('INFO', "Multicast: sent=$totalSent, failed=$totalFailed, invalid=" . count($invalidTokens));

        return [
            'success' => $totalSent > 0,
            'sent' => $totalSent,
            'failed' => $totalFailed,
            'invalid_tokens' => $invalidTokens,
        ];
    }

    /**
     * Send push notification to a topic (broadcasts to all tokens of a user_type)
     */
    public function sendToTopic(string $topic, string $title, string $body, array $data = []): array
    {
        // Expo doesn't have topics - fetch all tokens of the type and send
        $userType = str_replace(['_all', '_available', 's_'], ['', '', '_'], $topic);
        // Map topic to user_type
        $typeMap = [
            'shopper' => 'worker',
            'shoppers' => 'worker',
            'customer' => 'customer',
            'customers' => 'customer',
            'partner' => 'partner',
            'partners' => 'partner',
        ];
        $dbType = $typeMap[$userType] ?? $userType;

        try {
            $stmt = $this->db->prepare("
                SELECT DISTINCT token FROM om_market_push_tokens
                WHERE user_type = ? AND token LIKE 'Exponent%'
                ORDER BY updated_at DESC LIMIT 500
            ");
            $stmt->execute([$dbType]);
            $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($tokens)) {
                $this->log('INFO', "No tokens for topic '$topic'. Skipping.");
                return ['success' => true, 'sent' => 0];
            }

            return $this->sendToTokens($tokens, $title, $body, $data);
        } catch (\Exception $e) {
            $this->log('ERROR', "Topic send failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ─── Private Helpers ──────────────────────────────────────────────

    /**
     * Build a single Expo Push message
     */
    private function buildMessage(string $token, string $title, string $body, array $data = []): array
    {
        $msg = [
            'to' => $token,
            'title' => $title,
            'body' => $body,
            'sound' => 'default',
            'priority' => 'high',
            'channelId' => 'default',
        ];

        if (!empty($data)) {
            $msg['data'] = $data;
        }

        // Badge count
        if (isset($data['badge'])) {
            $msg['badge'] = (int)$data['badge'];
        }

        // Rich media: image URL (works on both iOS via attachment + Android via bigPictureUrl)
        // Pass via $data['image_url'] or $data['richContent']['image']
        $imageUrl = $data['image_url'] ?? $data['richContent']['image'] ?? null;
        if ($imageUrl && is_string($imageUrl) && (str_starts_with($imageUrl, 'http://') || str_starts_with($imageUrl, 'https://'))) {
            // Expo push API supports `richContent.image` for both platforms (handled by expo-notifications)
            $msg['richContent'] = ['image' => $imageUrl];
        }

        // Category (iOS) — used by Live Activities, action buttons, etc.
        if (!empty($data['category'])) {
            $msg['categoryId'] = (string)$data['category'];
        }

        // Channel id override for grouped notifications (e.g. order updates vs marketing)
        if (!empty($data['channel'])) {
            $msg['channelId'] = (string)$data['channel'];
        }

        // TTL — promo pushes can be ephemeral
        if (isset($data['ttl'])) {
            $msg['ttl'] = (int)$data['ttl'];
        }

        // Mutable content for iOS notification service extension (image attachment)
        if ($imageUrl) {
            $msg['mutableContent'] = true;
        }

        return $msg;
    }

    /**
     * Circuit breaker: return false if circuit is OPEN (skip send).
     * Opens after CB_THRESHOLD consecutive failures within CB_COOLDOWN_SEC.
     */
    private function checkCircuit(): bool
    {
        if (!function_exists('apcu_fetch')) return true; // no APCu, always allow
        $failures    = (int)(apcu_fetch(self::CB_FAILURES_KEY) ?: 0);
        $lastFailure = (int)(apcu_fetch(self::CB_LAST_FAIL_KEY) ?: 0);
        if ($failures >= self::CB_THRESHOLD && (time() - $lastFailure) < self::CB_COOLDOWN_SEC) {
            return false; // circuit OPEN
        }
        return true;
    }

    private function recordSuccess(): void
    {
        if (!function_exists('apcu_delete')) return;
        $wasOpen = (int)(apcu_fetch(self::CB_FAILURES_KEY) ?: 0) >= self::CB_THRESHOLD;
        apcu_delete(self::CB_FAILURES_KEY);
        apcu_delete(self::CB_LAST_FAIL_KEY);
        if ($wasOpen) {
            apcu_delete(self::CB_OPEN_LOG_KEY);
            $this->log('INFO', 'Circuit CLOSED — Expo Push recovered');
        }
    }

    private function recordFailure(string $reason = ''): void
    {
        if (!function_exists('apcu_inc')) return;
        $success = false;
        apcu_inc(self::CB_FAILURES_KEY, 1, $success, self::CB_TTL_SEC);
        apcu_store(self::CB_LAST_FAIL_KEY, time(), self::CB_TTL_SEC);
        $failures = (int)(apcu_fetch(self::CB_FAILURES_KEY) ?: 0);
        if ($failures >= self::CB_THRESHOLD) {
            // Log "circuit opened" only once per opening event
            if (!apcu_fetch(self::CB_OPEN_LOG_KEY)) {
                apcu_store(self::CB_OPEN_LOG_KEY, 1, self::CB_COOLDOWN_SEC);
                $this->log('ERROR', "Circuit OPEN — skipping Expo Push for " . self::CB_COOLDOWN_SEC . "s (last reason: $reason)");
            }
        }
    }

    /**
     * Send HTTP request to Expo Push API
     */
    private function sendRequest(array $messages): array
    {
        // Circuit breaker: short-circuit fast when Expo is known-down
        if (!$this->checkCircuit()) {
            return [
                'success' => false,
                'reason'  => 'circuit_open',
                'skipped' => count($messages),
            ];
        }

        $jsonPayload = json_encode($messages, JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            $this->log('ERROR', "json_encode failed: " . json_last_error_msg());
            return ['success' => false, 'error' => 'JSON encoding failed: ' . json_last_error_msg()];
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Accept-Encoding: gzip, deflate',
        ];

        // Optional: Expo access token for higher rate limits
        if (!empty($this->accessToken)) {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        }

        $ch = curl_init(self::EXPO_PUSH_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,   // reduced from 30s — do not block checkout
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->log('ERROR', "cURL error: $curlError");
            $this->recordFailure('curl: ' . $curlError);
            return ['success' => false, 'error' => "cURL: $curlError"];
        }

        $decoded = json_decode($response, true);

        if ($httpCode !== 200) {
            $errorMsg = $decoded['errors'][0]['message'] ?? ($decoded['error'] ?? $response);
            $this->log('ERROR', "HTTP $httpCode: $errorMsg");
            // Only count 5xx as infrastructure failures for the breaker; 4xx is
            // usually a client-side payload/token issue that won't recover via retry.
            if ($httpCode >= 500) {
                $this->recordFailure("http $httpCode");
            }
            return ['success' => false, 'error' => $errorMsg, 'http_code' => $httpCode];
        }

        $tickets = $decoded['data'] ?? [];
        $okCount = 0;
        foreach ($tickets as $t) {
            if (($t['status'] ?? '') === 'ok') $okCount++;
        }

        $this->log('INFO', "Push sent: " . count($tickets) . " tickets, $okCount ok");

        $this->recordSuccess();

        return [
            'success' => true,
            'tickets' => $tickets,
            'raw_response' => $decoded,
        ];
    }

    /**
     * Check if ticket indicates an invalid/expired token
     */
    private function isInvalidTokenError(array $ticket): bool
    {
        $details = $ticket['details'] ?? [];
        $error = $details['error'] ?? '';
        // Only DeviceNotRegistered means the token is truly invalid
        // InvalidCredentials = server auth issue, MessageTooBig = payload issue
        return $error === 'DeviceNotRegistered';
    }

    /**
     * Remove a single invalid token from the database
     */
    private function removeInvalidToken(string $token): void
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM om_market_push_tokens WHERE token = ?");
            $stmt->execute([$token]);
        } catch (\Exception $e) {
            $this->log('ERROR', "Failed to remove invalid token: " . $e->getMessage());
        }
    }

    /**
     * Remove multiple invalid tokens from the database
     */
    private function removeInvalidTokens(array $tokens): void
    {
        if (empty($tokens)) return;
        try {
            $placeholders = implode(',', array_fill(0, count($tokens), '?'));
            $stmt = $this->db->prepare("DELETE FROM om_market_push_tokens WHERE token IN ($placeholders)");
            $stmt->execute($tokens);
        } catch (\Exception $e) {
            $this->log('ERROR', "Failed to remove invalid tokens: " . $e->getMessage());
        }
    }

    /**
     * Load optional Expo access token from .env (EXPO_ACCESS_TOKEN)
     */
    private function loadAccessToken(): string
    {
        // SECURITY: Rely on $_ENV (already parsed by database.php) instead of re-reading .env file
        if (!empty($_ENV['EXPO_ACCESS_TOKEN'])) {
            return $_ENV['EXPO_ACCESS_TOKEN'];
        }
        if (!empty(getenv('EXPO_ACCESS_TOKEN'))) {
            return getenv('EXPO_ACCESS_TOKEN');
        }

        return '';
    }

    private function log(string $level, string $message): void
    {
        error_log(self::LOG_PREFIX . " [$level] $message");
    }
}
