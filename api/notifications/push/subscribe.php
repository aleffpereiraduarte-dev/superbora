<?php
/**
 * Push Notifications Subscribe - OneMundo
 * Registra subscription para Web Push
 *
 * POST /api/notifications/push/subscribe.php
 * - endpoint: URL do push service
 * - keys: {p256dh, auth}
 * - customer_id: (opcional) ID do cliente logado
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

try {
    require_once __DIR__ . '/../../../config.php';
    require_once __DIR__ . '/../../../database.php';

    $input = json_decode(file_get_contents('php://input'), true);

    $endpoint = $input['endpoint'] ?? '';
    $keys = $input['keys'] ?? [];
    $customerId = (int)($input['customer_id'] ?? 0);

    if (empty($endpoint)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Endpoint é obrigatório']);
        exit;
    }

    if (empty($keys['p256dh']) || empty($keys['auth'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Keys são obrigatórias']);
        exit;
    }

    $pdo = getConnection();

    // Criar tabela se não existir
    $pdo->exec("CREATE TABLE IF NOT EXISTS om_push_subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT DEFAULT NULL,
        endpoint VARCHAR(500) NOT NULL,
        p256dh VARCHAR(255) NOT NULL,
        auth VARCHAR(255) NOT NULL,
        user_agent VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_used TIMESTAMP NULL,
        UNIQUE KEY unique_endpoint (endpoint(255)),
        INDEX idx_customer (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Inserir ou atualizar subscription
    $stmt = $pdo->prepare("
        INSERT INTO om_push_subscriptions (customer_id, endpoint, p256dh, auth, user_agent)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            customer_id = COALESCE(?, customer_id),
            p256dh = ?,
            auth = ?,
            user_agent = ?
    ");

    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    $stmt->execute([
        $customerId ?: null,
        $endpoint,
        $keys['p256dh'],
        $keys['auth'],
        $userAgent,
        $customerId ?: null,
        $keys['p256dh'],
        $keys['auth'],
        $userAgent
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Notificações ativadas com sucesso',
        'subscribed' => true
    ]);

} catch (Exception $e) {
    error_log("Push subscribe error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao ativar notificações']);
}
