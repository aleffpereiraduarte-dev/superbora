<?php
/**
 * Sistema de notificacoes - insere em om_market_notifications
 * Envia push via FCM + WhatsApp via Z-API
 */

// Firebase config
define('FCM_SENDER_ID', '782929446226');

/**
 * Envia notificacao para um usuario
 * Canais: DB (polling) + FCM (push) + WhatsApp (Z-API, se tiver telefone)
 */
function sendNotification(PDO $db, int $userId, string $userType, string $title, string $body, array $data = []): bool {
    if (!$userId) return false;

    // Sanitize text inputs before DB storage
    $title = strip_tags($title);
    $body = strip_tags($body);

    try {
        // 1. Inserir na tabela de notificacoes (polling)
        $stmt = $db->prepare("
            INSERT INTO om_market_notifications (recipient_id, recipient_type, title, message, data, is_read, sent_at)
            VALUES (?, ?, ?, ?, ?, 0, NOW())
        ");
        $stmt->execute([$userId, $userType, $title, $body, json_encode($data, JSON_UNESCAPED_UNICODE)]);

        // 2. Enviar push via FCM
        $stmt = $db->prepare("
            SELECT id, token, device_type
            FROM om_market_push_tokens
            WHERE user_id = ? AND user_type = ?
        ");
        $stmt->execute([$userId, $userType]);
        $tokens = $stmt->fetchAll();

        foreach ($tokens as $t) {
            if (!empty($t['token'])) {
                sendFCMPush($t['token'], $title, $body, $data);
            }
        }

        // 3. WhatsApp via Z-API (para notificacoes de pedido)
        if (isset($data['order_id'])) {
            $phone = getPhoneForUser($db, $userId, $userType);
            if ($phone) {
                try {
                    require_once __DIR__ . '/../helpers/zapi-whatsapp.php';
                    // Limpa formatacao markdown pra WhatsApp
                    sendWhatsApp($phone, "📱 *{$title}*\n\n{$body}");
                } catch (Exception $we) {
                    error_log("[notify] WhatsApp falhou: " . $we->getMessage());
                }
            }
        }

        return true;
    } catch (Exception $e) {
        error_log("[notify] Erro: " . $e->getMessage());
        return false;
    }
}

/**
 * Envia push via Firebase Cloud Messaging (legacy HTTP API)
 * Usa FCMHelper para envio e limpeza automatica de tokens invalidos.
 * Fallback para implementacao inline se FCMHelper nao estiver disponivel.
 */
function sendFCMPush(string $token, string $title, string $body, array $data = []): bool {
    // Use FCMHelper if available (preferred - handles token cleanup)
    try {
        require_once dirname(__DIR__) . '/helpers/FCMHelper.php';
        // Need DB for FCMHelper - try to get from global scope
        $db = null;
        if (function_exists('getDB')) {
            $db = getDB();
        }
        if ($db) {
            $fcm = FCMHelper::getInstance($db);
            if ($fcm->isConfigured()) {
                $result = $fcm->sendToToken($token, $title, $body, $data);
                return $result['success'];
            }
        }
    } catch (Exception $e) {
        error_log("[fcm] FCMHelper fallback: " . $e->getMessage());
    }

    // Fallback: legacy inline implementation
    // WARNING: https://fcm.googleapis.com/fcm/send is deprecated.
    // TODO: Migrate to Firebase Cloud Messaging v1 API (https://fcm.googleapis.com/v1/projects/{project}/messages:send)
    error_log('[fcm] WARNING: Using deprecated legacy FCM HTTP API. Migrate to FCM v1.');
    $serverKey = getFCMServerKey();
    if (!$serverKey) return false;

    $payload = json_encode([
        'to' => $token,
        'notification' => [
            'title' => $title,
            'body' => $body,
            'icon' => '/mercado/vitrine/icons/icon-192.png',
            'badge' => '/mercado/vitrine/icons/icon-192.png',
            'click_action' => $data['url'] ?? '/',
            'sound' => 'default'
        ],
        'data' => $data,
        'priority' => 'high'
    ]);

    $ch = curl_init('https://fcm.googleapis.com/fcm/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: key=' . $serverKey,
            'Content-Type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $response = json_decode($result, true);
    $success = ($httpCode === 200 && ($response['success'] ?? 0) > 0);

    if (!$success) {
        error_log("[fcm] Push falhou: HTTP $httpCode | " . ($response['results'][0]['error'] ?? $result));
    }

    return $success;
}

/**
 * Busca server key do FCM
 * Pode estar em .env ou tabela de config
 */
function getFCMServerKey(): string {
    static $key = null;
    if ($key !== null) return $key;

    // Tentar .env
    $envFile = dirname(__DIR__, 3) . '/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, 'FCM_SERVER_KEY=') === 0) {
                $key = trim(substr($line, 15));
                return $key;
            }
        }
    }

    $key = '';
    return $key;
}

/**
 * Busca telefone de um usuario
 */
function getPhoneForUser(PDO $db, int $userId, string $userType): string {
    try {
        switch ($userType) {
            case 'customer':
                $stmt = $db->prepare("SELECT phone FROM om_customers WHERE customer_id = ?");
                break;
            case 'partner':
                $stmt = $db->prepare("SELECT phone FROM om_market_partners WHERE partner_id = ?");
                break;
            case 'shopper':
                $stmt = $db->prepare("SELECT phone FROM om_market_shoppers WHERE shopper_id = ?");
                break;
            default:
                return '';
        }
        $stmt->execute([$userId]);
        return $stmt->fetchColumn() ?: '';
    } catch (Exception $e) {
        return '';
    }
}
