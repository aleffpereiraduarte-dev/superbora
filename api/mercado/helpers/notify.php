<?php
/**
 * Helpers de notificacao push para parceiros e clientes
 *
 * Despacha via DOIS canais:
 * 1. Expo Push API (mobile app) - via NotificationSender/FCMHelper
 * 2. Web Push (painel web) - via om_push_subscriptions (quando VAPID disponivel)
 *
 * Uso: require_once e chamar notifyPartner() ou notifyCustomer()
 */

/**
 * Notifica parceiro via Push (mobile + web)
 */
function notifyPartner(PDO $db, int $partner_id, string $title, string $body, string $url = '/painel/mercado/pedidos.php', array $extra = []): int {
    $sent = 0;

    // 1. Expo Push (mobile app) via NotificationSender
    $sent += _sendExpoPush($db, $partner_id, 'partner', $title, $body, $extra);

    // 2. Web Push (painel do parceiro) — DB stores 'partner' not 'parceiro'
    $sent += _sendWebPushToUser($db, $partner_id, 'partner', $title, $body, $url, $extra);

    return $sent;
}

/**
 * Notifica cliente via Push (mobile + web)
 */
function notifyCustomer(PDO $db, int $customer_id, string $title, string $body, string $url = '/mercado/', array $extra = []): int {
    $sent = 0;

    // 1. Expo Push (mobile app) via NotificationSender
    $sent += _sendExpoPush($db, $customer_id, 'customer', $title, $body, $extra);

    // 2. Web Push (browser)
    $sent += _sendWebPushToUser($db, $customer_id, 'customer', $title, $body, $url, $extra);

    return $sent;
}

/**
 * Envia push via Expo Push API (mobile app)
 * @return int numero de notificacoes enviadas
 */
function _sendExpoPush(PDO $db, int $user_id, string $user_type, string $title, string $body, array $data = []): int {
    if (!$user_id) return 0;

    try {
        // Lazy-load NotificationSender (may not exist in all environments)
        $senderFile = __DIR__ . '/NotificationSender.php';
        if (!file_exists($senderFile)) return 0;

        require_once $senderFile;
        $sender = NotificationSender::getInstance($db);

        $result = match ($user_type) {
            'customer' => $sender->notifyCustomer($user_id, $title, $body, $data),
            'partner'  => $sender->notifyPartner($user_id, $title, $body, $data),
            'shopper'  => $sender->notifyShopper($user_id, $title, $body, $data),
            default    => ['success' => false, 'sent' => 0],
        };

        return (int)($result['sent'] ?? 0);
    } catch (Exception $e) {
        error_log("[notify] Expo push erro: " . $e->getMessage());
        return 0;
    }
}

/**
 * Envia Web Push para um tipo de usuario (painel web/browser)
 * @return int numero de notificacoes enviadas
 */
function _sendWebPushToUser(PDO $db, int $user_id, string $user_type, string $title, string $body, string $url, array $extra = []): int {
    if (!$user_id) return 0;

    try {
        // Map user_type to the correct ID column in om_push_subscriptions
        // SECURITY: Whitelist allowed column names to prevent SQL injection
        $allowedColumns = ['partner' => 'partner_id', 'customer' => 'customer_id', 'shopper' => 'shopper_id'];
        $idColumn = $allowedColumns[$user_type] ?? null;
        if ($idColumn === null) {
            error_log("[notify] Invalid user_type for web push: " . $user_type);
            return 0;
        }
        $stmt = $db->prepare("
            SELECT id, endpoint, p256dh, auth
            FROM om_push_subscriptions
            WHERE \"$idColumn\" = ? AND user_type = ? AND is_active = '1'
        ");
        $stmt->execute([$user_id, $user_type]);
        $subscriptions = $stmt->fetchAll();

        if (empty($subscriptions)) return 0;

        $payload = json_encode(array_merge([
            'title' => $title,
            'body' => $body,
            'icon' => '/mercado/assets/img/icon-192.png',
            'badge' => '/mercado/assets/img/badge-72.png',
            'tag' => 'superbora-' . $user_type . '-' . time(),
            'data' => ['url' => $url, 'timestamp' => time()]
        ], $extra));

        $sent = 0;
        $toRemove = [];

        foreach ($subscriptions as $sub) {
            $result = sendWebPush($sub['endpoint'], $sub['p256dh'], $sub['auth'], $payload);

            if ($result['success']) {
                $sent++;
                $stmt = $db->prepare("UPDATE om_push_subscriptions SET last_used = NOW() WHERE id = ?");
                $stmt->execute([$sub['id']]);
            } elseif (in_array($result['code'], [401, 403, 404, 410])) {
                // 401/403 = VAPID issue or expired, 404/410 = endpoint gone
                $toRemove[] = $sub['id'];
            }
        }

        // Marcar subscriptions invalidas
        if (!empty($toRemove)) {
            $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
            $stmt = $db->prepare("UPDATE om_push_subscriptions SET is_active = 0 WHERE id IN ($placeholders)");
            $stmt->execute($toRemove);
        }

        return $sent;

    } catch (Exception $e) {
        error_log("[notify] Web push erro: " . $e->getMessage());
        return 0;
    }
}

/**
 * Envia Web Push via cURL
 * Nota: Sem VAPID, a maioria dos endpoints modernos retorna 401/403.
 * Para funcionar com FCM/Mozilla, precisa do pacote web-push-php com VAPID.
 * Mantido como fallback — notificacoes mobile funcionam via Expo Push acima.
 */
function sendWebPush(string $endpoint, string $p256dh, string $auth, string $payload): array {
    // SECURITY: Validate endpoint against known push service domains to prevent SSRF
    $allowedDomains = ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'push.apple.com', 'notify.windows.com', 'web.push.apple.com'];
    $host = parse_url($endpoint, PHP_URL_HOST);
    $isAllowed = false;
    foreach ($allowedDomains as $domain) {
        if ($host === $domain || str_ends_with($host, '.' . $domain)) { $isAllowed = true; break; }
    }
    if (!$isAllowed) {
        error_log("[WebPush] SECURITY: Blocked SSRF attempt to endpoint: $endpoint");
        return ['success' => false, 'code' => 0, 'response' => 'Invalid push endpoint domain'];
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'TTL: 86400',
            'Urgency: high'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'success' => $httpCode >= 200 && $httpCode < 300,
        'code' => $httpCode,
        'response' => $response
    ];
}
