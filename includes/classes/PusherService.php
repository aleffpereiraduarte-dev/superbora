<?php
/**
 * PusherService - Envia eventos Pusher via HTTP API (sem SDK)
 *
 * O painel-app escuta canal `partner-{id}` via Pusher JS client.
 * Este servico envia eventos server-side via Pusher REST API.
 *
 * Uso:
 *   PusherService::newOrder($partnerId, $orderData);
 *   PusherService::trigger('partner-5', 'new-order', $data);
 */
class PusherService {

    private static $appId;
    private static $key;
    private static $secret;
    private static $cluster;
    private static $initialized = false;

    private static function init(): void {
        if (self::$initialized) return;

        // Carregar do .env se nao estiver no $_ENV
        if (empty($_ENV['PUSHER_APP_ID']) && file_exists(__DIR__ . '/../../.env')) {
            $lines = file(__DIR__ . '/../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                if (strpos($line, '=') !== false) {
                    list($k, $v) = explode('=', $line, 2);
                    $k = trim($k);
                    $v = trim($v);
                    if (strpos($k, 'PUSHER_') === 0 && !isset($_ENV[$k])) {
                        $_ENV[$k] = $v;
                    }
                }
            }
        }

        self::$appId = $_ENV['PUSHER_APP_ID'] ?? '';
        self::$key = $_ENV['PUSHER_KEY'] ?? '';
        self::$secret = $_ENV['PUSHER_SECRET'] ?? '';
        self::$cluster = $_ENV['PUSHER_CLUSTER'] ?? 'sa1';
        self::$initialized = true;
    }

    /**
     * Verifica se Pusher esta configurado
     */
    public static function isConfigured(): bool {
        self::init();
        return !empty(self::$appId) && !empty(self::$key) && !empty(self::$secret);
    }

    /**
     * Envia evento para um canal Pusher
     *
     * @param string $channel Canal (ex: "partner-5")
     * @param string $event Nome do evento (ex: "new-order")
     * @param array $data Dados do evento
     * @return bool Success
     */
    public static function trigger(string $channel, string $event, array $data): bool {
        self::init();

        if (!self::isConfigured()) {
            error_log("[PusherService] Nao configurado (PUSHER_APP_ID/SECRET vazios). Fallback OmRealtime.");
            return false;
        }

        try {
            $body = json_encode([
                'name' => $event,
                'channel' => $channel,
                'data' => json_encode($data)
            ]);

            $path = '/apps/' . self::$appId . '/events';
            $timestamp = time();

            // Pusher auth: MD5 body + HMAC-SHA256 signature
            $bodyMd5 = md5($body);

            $queryParams = [
                'auth_key' => self::$key,
                'auth_timestamp' => $timestamp,
                'auth_version' => '1.0',
                'body_md5' => $bodyMd5
            ];

            ksort($queryParams);
            $queryString = http_build_query($queryParams);

            $stringToSign = "POST\n{$path}\n{$queryString}";
            $signature = hash_hmac('sha256', $stringToSign, self::$secret);

            $url = 'https://api-' . self::$cluster . '.pusher.com' . $path
                 . '?' . $queryString
                 . '&auth_signature=' . $signature;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                error_log("[PusherService] cURL error: $curlErr");
                return false;
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                return true;
            }

            error_log("[PusherService] HTTP $httpCode: $response (channel=$channel, event=$event)");
            return false;

        } catch (Exception $e) {
            error_log("[PusherService] Erro: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notifica parceiro sobre novo pedido
     */
    public static function newOrder(int $partnerId, array $orderData): bool {
        return self::trigger("partner-{$partnerId}", 'new-order', $orderData);
    }

    /**
     * Notifica atualizacao de pedido
     */
    public static function orderUpdate(int $partnerId, array $orderData): bool {
        return self::trigger("partner-{$partnerId}", 'order-update', $orderData);
    }

    /**
     * Notifica atualizacao de status
     */
    public static function orderStatus(int $partnerId, array $statusData): bool {
        return self::trigger("partner-{$partnerId}", 'order-status', $statusData);
    }

    /**
     * Notifica atualizacao de wallet
     */
    public static function walletUpdate(int $partnerId, array $walletData): bool {
        return self::trigger("partner-{$partnerId}", 'wallet-update', $walletData);
    }

    /**
     * Broadcast para todos os parceiros
     */
    public static function broadcast(string $event, array $data): bool {
        return self::trigger('all-partners', $event, $data);
    }
}
