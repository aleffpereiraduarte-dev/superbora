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
 * Notifica cliente via Push (mobile + web) + Email (quando disponivel)
 */
function notifyCustomer(PDO $db, int $customer_id, string $title, string $body, string $url = '/mercado/', array $extra = []): int {
    $sent = 0;

    // 1. Expo Push (mobile app) via NotificationSender
    $sent += _sendExpoPush($db, $customer_id, 'customer', $title, $body, $extra);

    // 2. Web Push (browser)
    $sent += _sendWebPushToUser($db, $customer_id, 'customer', $title, $body, $url, $extra);

    // 3. Email (for order-related notifications only)
    if (!empty($extra['send_email']) && !empty($extra['email'])) {
        try {
            require_once __DIR__ . '/email.php';
            $customerName = $extra['customer_name'] ?? 'Cliente';
            $sent += sendEmail($extra['email'], $title, "<p>{$body}</p>", $db, $customer_id, $extra['email_template'] ?? 'notification') ? 1 : 0;
        } catch (Exception $e) {
            error_log("[notify] Email erro: " . $e->getMessage());
        }
    }

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
 * Usa minishlink/web-push para VAPID signing + payload encryption.
 * @return int numero de notificacoes enviadas
 */
function _sendWebPushToUser(PDO $db, int $user_id, string $user_type, string $title, string $body, string $url, array $extra = []): int {
    if (!$user_id) return 0;

    try {
        // Validate user_type
        $allowedTypes = ['partner', 'customer', 'shopper'];
        if (!in_array($user_type, $allowedTypes, true)) {
            error_log("[notify] Invalid user_type for web push: " . $user_type);
            return 0;
        }

        // The table uses customer_id as a generic user ID for all user types,
        // discriminated by user_type column
        $stmt = $db->prepare("
            SELECT id, endpoint, p256dh, auth
            FROM om_push_subscriptions
            WHERE customer_id = ? AND user_type = ? AND is_active = 1
        ");
        $stmt->execute([$user_id, $user_type]);
        $subscriptions = $stmt->fetchAll();

        if (empty($subscriptions)) return 0;

        $payload = json_encode(array_merge([
            'title' => $title,
            'body' => $body,
            'icon' => '/mercado/painel-next/icon-192.png',
            'badge' => '/mercado/painel-next/icon-192.png',
            'tag' => 'superbora-' . $user_type . '-' . time(),
            'data' => ['url' => $url, 'timestamp' => time()]
        ], $extra));

        $sent = 0;
        $toRemove = [];

        foreach ($subscriptions as $sub) {
            if (empty($sub['endpoint']) || empty($sub['p256dh']) || empty($sub['auth'])) {
                $toRemove[] = $sub['id'];
                continue;
            }

            $result = _sendWebPushViaLibrary($sub['endpoint'], $sub['p256dh'], $sub['auth'], $payload);

            if ($result['success']) {
                $sent++;
                $updateStmt = $db->prepare("UPDATE om_push_subscriptions SET last_used = NOW() WHERE id = ?");
                $updateStmt->execute([$sub['id']]);
            } elseif (in_array($result['code'], [401, 403, 404, 410])) {
                // 401/403 = VAPID issue or expired, 404/410 = endpoint gone
                $toRemove[] = $sub['id'];
            }
        }

        // Mark invalid subscriptions as inactive
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
 * Singleton WebPush instance (cached per request to avoid re-parsing VAPID keys)
 */
function _getWebPushInstance(): ?\Minishlink\WebPush\WebPush {
    static $webPush = null;
    static $initialized = false;

    if ($initialized) return $webPush;
    $initialized = true;

    try {
        // Load .env VAPID keys
        $envFile = dirname(__DIR__, 3) . '/.env';
        $vapidPublic = null;
        $vapidPrivate = null;
        $vapidSubject = 'mailto:contato@superbora.com.br';

        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (str_starts_with($line, '#')) continue;
                if (str_starts_with($line, 'VAPID_PUBLIC_KEY=')) {
                    $vapidPublic = trim(substr($line, strlen('VAPID_PUBLIC_KEY=')));
                } elseif (str_starts_with($line, 'VAPID_PRIVATE_KEY=')) {
                    $vapidPrivate = trim(substr($line, strlen('VAPID_PRIVATE_KEY=')));
                } elseif (str_starts_with($line, 'VAPID_SUBJECT=')) {
                    $vapidSubject = trim(substr($line, strlen('VAPID_SUBJECT=')));
                }
            }
        }

        if (!$vapidPublic || !$vapidPrivate || $vapidPublic === 'CHANGE_ME' || $vapidPrivate === 'CHANGE_ME') {
            error_log("[WebPush] VAPID keys not configured in .env");
            return null;
        }

        $auth = [
            'VAPID' => [
                'subject' => $vapidSubject,
                'publicKey' => $vapidPublic,
                'privateKey' => $vapidPrivate,
            ],
        ];

        $defaultOptions = [
            'topic' => 'superbora',
            'urgency' => 'high',
            'TTL' => 86400,
        ];

        $webPush = new \Minishlink\WebPush\WebPush($auth, $defaultOptions, 30);
        // Do not automatically flush — we send one at a time
        return $webPush;

    } catch (Exception $e) {
        error_log("[WebPush] Failed to initialize: " . $e->getMessage());
        return null;
    }
}

/**
 * Envia Web Push usando minishlink/web-push (VAPID + payload encryption)
 * @return array ['success' => bool, 'code' => int, 'response' => string]
 */
function _sendWebPushViaLibrary(string $endpoint, string $p256dh, string $authKey, string $payload): array {
    try {
        // Autoload the library
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        if (!file_exists($autoload)) {
            error_log("[WebPush] vendor/autoload.php not found");
            return ['success' => false, 'code' => 0, 'response' => 'Autoloader not found'];
        }
        require_once $autoload;

        $webPush = _getWebPushInstance();
        if (!$webPush) {
            return ['success' => false, 'code' => 0, 'response' => 'VAPID not configured'];
        }

        $subscription = \Minishlink\WebPush\Subscription::create([
            'endpoint' => $endpoint,
            'publicKey' => $p256dh,
            'authToken' => $authKey,
        ]);

        $report = $webPush->sendOneNotification($subscription, $payload);

        $httpCode = 0;
        $response = '';
        if ($report) {
            $httpResponse = $report->getResponse();
            $httpCode = $httpResponse ? $httpResponse->getStatusCode() : 0;
            $response = $report->getReason() ?? '';
        }

        $success = $report && $report->isSuccess();

        if (!$success) {
            error_log("[WebPush] Send failed to $endpoint: code=$httpCode reason=$response");
        }

        return [
            'success' => $success,
            'code' => $httpCode,
            'response' => $response,
        ];

    } catch (Exception $e) {
        error_log("[WebPush] Exception sending to $endpoint: " . $e->getMessage());
        return ['success' => false, 'code' => 0, 'response' => $e->getMessage()];
    }
}
