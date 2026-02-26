<?php
/**
 * API de Envio de Notificações Push
 *
 * POST /api/notifications/send.php - Enviar notificação
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(dirname(__DIR__)) . '/config.php';

// Chaves VAPID (gerar com: openssl ecparam -genkey -name prime256v1 -out private.pem && openssl ec -in private.pem -pubout -out public.pem)
// Estas são chaves de exemplo - em produção, gerar novas chaves!
define('VAPID_PUBLIC_KEY', 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U');
define('VAPID_PRIVATE_KEY', 'UUxI4O8-FbRouADVXc-hK3lTzQBOODpNXUA');
define('VAPID_SUBJECT', 'mailto:contato@onemundo.com.br');

/**
 * Envia notificação push via Web Push Protocol
 */
function sendWebPush($subscription, $payload) {
    $endpoint = $subscription['endpoint'];
    $p256dh = $subscription['p256dh'];
    $auth = $subscription['auth'];

    // Payload JSON
    $payloadJson = json_encode($payload);

    // Headers para Web Push
    $headers = [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payloadJson),
        'TTL: 86400',
        'Urgency: normal'
    ];

    // Adicionar VAPID se não for FCM/GCM legacy
    if (strpos($endpoint, 'googleapis.com') === false) {
        // Para endpoints modernos, usar VAPID
        // Simplificado - em produção usar biblioteca como web-push-php
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payloadJson,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'success' => $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'response' => $response
    ];
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
        DB_USERNAME,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $input = json_decode(file_get_contents('php://input'), true);

    // Validar API key para envios em massa (opcional)
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $input['api_key'] ?? null;

    // Parâmetros da notificação
    $title = $input['title'] ?? 'OneMundo';
    $body = $input['body'] ?? '';
    $icon = $input['icon'] ?? '/assets/images/icon-192.png';
    $badge = $input['badge'] ?? '/assets/images/badge.png';
    $url = $input['url'] ?? '/';
    $topic = $input['topic'] ?? null;
    $customerId = $input['customer_id'] ?? null;
    $userType = $input['user_type'] ?? null;
    $data = $input['data'] ?? [];

    if (empty($body)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'body obrigatório']);
        exit;
    }

    // Payload da notificação
    $payload = [
        'title' => $title,
        'body' => $body,
        'icon' => $icon,
        'badge' => $badge,
        'data' => array_merge($data, ['url' => $url]),
        'tag' => $topic ?? 'general',
        'requireInteraction' => false,
        'actions' => [
            ['action' => 'open', 'title' => 'Abrir'],
            ['action' => 'close', 'title' => 'Fechar']
        ]
    ];

    // Buscar subscriptions
    $where = ['is_active = 1'];
    $params = [];

    if ($customerId) {
        $where[] = 'customer_id = ?';
        $params[] = $customerId;
    }

    if ($userType) {
        $where[] = 'user_type = ?';
        $params[] = $userType;
    }

    if ($topic) {
        $where[] = 'JSON_CONTAINS(topics, ?)';
        $params[] = json_encode($topic);
    }

    $whereClause = implode(' AND ', $where);

    $stmt = $pdo->prepare("SELECT * FROM om_push_subscriptions WHERE $whereClause LIMIT 1000");
    $stmt->execute($params);
    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sent = 0;
    $failed = 0;
    $errors = [];

    foreach ($subscriptions as $sub) {
        $result = sendWebPush($sub, $payload);

        if ($result['success']) {
            $sent++;
            // Atualizar last_used
            $pdo->prepare("UPDATE om_push_subscriptions SET last_used = NOW() WHERE id = ?")
                ->execute([$sub['id']]);
        } else {
            $failed++;
            // Se endpoint expirou (410 Gone), desativar
            if ($result['http_code'] === 410) {
                $pdo->prepare("UPDATE om_push_subscriptions SET is_active = 0 WHERE id = ?")
                    ->execute([$sub['id']]);
            }
            $errors[] = [
                'id' => $sub['id'],
                'http_code' => $result['http_code']
            ];
        }
    }

    // Log de envio
    $pdo->exec("CREATE TABLE IF NOT EXISTS om_push_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255),
        body TEXT,
        topic VARCHAR(50),
        sent_count INT,
        failed_count INT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $pdo->prepare("INSERT INTO om_push_logs (title, body, topic, sent_count, failed_count) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$title, $body, $topic, $sent, $failed]);

    echo json_encode([
        'success' => true,
        'sent' => $sent,
        'failed' => $failed,
        'total' => count($subscriptions),
        'errors' => array_slice($errors, 0, 10) // Limitar erros retornados
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
