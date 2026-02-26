<?php
/**
 * Push Notifications Send - OneMundo
 * Envia push notification para usuários
 *
 * POST /api/notifications/push/send.php
 * - title: Título da notificação
 * - body: Corpo da mensagem
 * - icon: URL do ícone (opcional)
 * - url: URL ao clicar (opcional)
 * - customer_id: Enviar para cliente específico (opcional)
 * - all: true para enviar para todos (requer auth admin)
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

// VAPID Keys (gerar suas próprias em produção)
// Usar: https://vapidkeys.com/
define('VAPID_PUBLIC_KEY', $_ENV['VAPID_PUBLIC_KEY'] ?? 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U');
define('VAPID_PRIVATE_KEY', $_ENV['VAPID_PRIVATE_KEY'] ?? 'UUxI4O8-FbRouADVXc-9jQpyLNGt3bLbUPa_1k5x3xo');
define('VAPID_SUBJECT', 'mailto:contato@onemundo.com.br');

try {
    require_once __DIR__ . '/../../../config.php';
    require_once __DIR__ . '/../../../database.php';

    $input = json_decode(file_get_contents('php://input'), true);

    $title = trim($input['title'] ?? '');
    $body = trim($input['body'] ?? '');
    $icon = $input['icon'] ?? '/assets/images/icon-192.png';
    $url = $input['url'] ?? '/';
    $customerId = (int)($input['customer_id'] ?? 0);
    $sendToAll = (bool)($input['all'] ?? false);

    if (empty($title) || empty($body)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Title e body são obrigatórios']);
        exit;
    }

    $pdo = getConnection();

    // Buscar subscriptions
    if ($customerId) {
        $stmt = $pdo->prepare("SELECT * FROM om_push_subscriptions WHERE customer_id = ?");
        $stmt->execute([$customerId]);
    } elseif ($sendToAll) {
        // Verificar autenticacao admin para envio em massa
        require_once __DIR__ . '/../../../includes/classes/OmAuth.php';
        OmAuth::getInstance()->setDb($pdo);
        $token = OmAuth::getInstance()->getTokenFromRequest();
        if (!$token) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Token de autenticacao obrigatorio para envio em massa']);
            exit;
        }
        $payload = OmAuth::getInstance()->validateToken($token);
        if (!$payload || ($payload['type'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Apenas administradores podem enviar para todos']);
            exit;
        }
        $stmt = $pdo->query("SELECT * FROM om_push_subscriptions LIMIT 1000");
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Especifique customer_id ou all=true']);
        exit;
    }

    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($subscriptions)) {
        echo json_encode([
            'success' => true,
            'message' => 'Nenhuma subscription encontrada',
            'sent' => 0
        ]);
        exit;
    }

    // Payload da notificação
    $payload = json_encode([
        'title' => $title,
        'body' => $body,
        'icon' => $icon,
        'badge' => '/assets/images/badge-72.png',
        'data' => [
            'url' => $url,
            'timestamp' => time()
        ],
        'actions' => [
            ['action' => 'open', 'title' => 'Abrir'],
            ['action' => 'close', 'title' => 'Fechar']
        ]
    ]);

    $sent = 0;
    $failed = 0;
    $toDelete = [];

    foreach ($subscriptions as $sub) {
        $result = sendPushNotification(
            $sub['endpoint'],
            $sub['p256dh'],
            $sub['auth'],
            $payload
        );

        if ($result['success']) {
            $sent++;
            // Atualizar last_used
            $stmt = $pdo->prepare("UPDATE om_push_subscriptions SET last_used = NOW() WHERE id = ?");
            $stmt->execute([$sub['id']]);
        } else {
            $failed++;
            // Se endpoint inválido, marcar para deletar
            if ($result['code'] == 404 || $result['code'] == 410) {
                $toDelete[] = $sub['id'];
            }
        }
    }

    // Remover subscriptions inválidas
    if (!empty($toDelete)) {
        $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
        $stmt = $pdo->prepare("DELETE FROM om_push_subscriptions WHERE id IN ($placeholders)");
        $stmt->execute($toDelete);
    }

    echo json_encode([
        'success' => true,
        'message' => "Notificações enviadas",
        'sent' => $sent,
        'failed' => $failed,
        'removed_invalid' => count($toDelete)
    ]);

} catch (Exception $e) {
    error_log("Push send error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao enviar notificações']);
}

/**
 * Envia push notification usando Web Push Protocol
 */
function sendPushNotification($endpoint, $p256dh, $auth, $payload) {
    // Simplificado - em produção usar biblioteca web-push-php
    // https://github.com/web-push-libs/web-push-php

    $ch = curl_init($endpoint);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'TTL: 86400',
            'Urgency: normal'
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
