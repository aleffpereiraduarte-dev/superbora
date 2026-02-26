<?php
/**
 * API de Inscrição para Notificações Push
 *
 * POST /api/notifications/subscribe.php - Inscrever para push
 * DELETE /api/notifications/subscribe.php - Cancelar inscrição
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(dirname(__DIR__)) . '/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
        DB_USERNAME,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Verificar se tabela existe e tem estrutura correta
    $tableExists = $pdo->query("SHOW TABLES LIKE 'om_push_subscriptions'")->rowCount() > 0;

    if (!$tableExists) {
        $pdo->exec("CREATE TABLE om_push_subscriptions (
            id SERIAL PRIMARY KEY,
            customer_id INT DEFAULT NULL,
            user_type VARCHAR(20) DEFAULT 'guest',
            endpoint VARCHAR(500) NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth VARCHAR(100) NOT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            topics JSON DEFAULT NULL,
            is_active SMALLINT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_used TIMESTAMP DEFAULT NULL,
            UNIQUE (endpoint)
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_push_sub_customer ON om_push_subscriptions(customer_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_push_sub_active ON om_push_subscriptions(is_active)");
    } else {
        // Adicionar colunas faltantes se necessário
        $columns = $pdo->query("SHOW COLUMNS FROM om_push_subscriptions")->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('user_type', $columns)) {
            $pdo->exec("ALTER TABLE om_push_subscriptions ADD COLUMN user_type ENUM('customer', 'motorista', 'shopper', 'parceiro', 'admin', 'guest') DEFAULT 'guest' AFTER customer_id");
        }
        if (!in_array('p256dh', $columns)) {
            $pdo->exec("ALTER TABLE om_push_subscriptions ADD COLUMN p256dh VARCHAR(255) NOT NULL DEFAULT '' AFTER endpoint");
        }
        if (!in_array('auth', $columns)) {
            $pdo->exec("ALTER TABLE om_push_subscriptions ADD COLUMN auth VARCHAR(100) NOT NULL DEFAULT '' AFTER p256dh");
        }
        if (!in_array('user_agent', $columns)) {
            $pdo->exec("ALTER TABLE om_push_subscriptions ADD COLUMN user_agent VARCHAR(255) DEFAULT NULL AFTER auth");
        }
        if (!in_array('topics', $columns)) {
            $pdo->exec("ALTER TABLE om_push_subscriptions ADD COLUMN topics JSON DEFAULT NULL AFTER user_agent");
        }
        if (!in_array('is_active', $columns)) {
            $pdo->exec("ALTER TABLE om_push_subscriptions ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER topics");
        }
        if (!in_array('last_used', $columns)) {
            $pdo->exec("ALTER TABLE om_push_subscriptions ADD COLUMN last_used DATETIME DEFAULT NULL AFTER created_at");
        }
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $method = $_SERVER['REQUEST_METHOD'];

    // ========== POST - Inscrever ==========
    if ($method === 'POST') {
        $subscription = $input['subscription'] ?? null;
        $customerId = $input['customer_id'] ?? null;
        $userType = $input['user_type'] ?? 'guest';
        $topics = $input['topics'] ?? ['promotions', 'orders', 'news'];

        if (!$subscription || !isset($subscription['endpoint'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Subscription inválida']);
            exit;
        }

        $endpoint = $subscription['endpoint'];
        $keys = $subscription['keys'] ?? [];
        $p256dh = $keys['p256dh'] ?? '';
        $auth = $keys['auth'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        // Upsert subscription
        $stmt = $pdo->prepare("
            INSERT INTO om_push_subscriptions (customer_id, user_type, endpoint, p256dh, auth, user_agent, topics, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ON CONFLICT (endpoint) DO UPDATE SET
                customer_id = COALESCE(EXCLUDED.customer_id, om_push_subscriptions.customer_id),
                user_type = EXCLUDED.user_type,
                p256dh = EXCLUDED.p256dh,
                auth = EXCLUDED.auth,
                topics = EXCLUDED.topics,
                is_active = 1
        ");
        $stmt->execute([
            $customerId,
            $userType,
            $endpoint,
            $p256dh,
            $auth,
            $userAgent,
            json_encode($topics)
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Inscrito para notificações push'
        ]);
    }

    // ========== DELETE - Cancelar inscrição ==========
    if ($method === 'DELETE') {
        $endpoint = $input['endpoint'] ?? null;

        if (!$endpoint) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Endpoint obrigatório']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE om_push_subscriptions SET is_active = 0 WHERE endpoint = ?");
        $stmt->execute([$endpoint]);

        echo json_encode([
            'success' => true,
            'message' => 'Inscrição cancelada'
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    error_log("[notifications/subscribe] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor']);
}
