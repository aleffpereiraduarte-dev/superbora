<?php
/**
 * API de Notificação de Disponibilidade
 *
 * POST /api/mercado/produtos/notificar-disponibilidade.php
 * - Cadastrar para receber notificação quando produto voltar ao estoque
 *
 * GET /api/mercado/produtos/notificar-disponibilidade.php?product_id=X
 * - Verificar se usuário já está cadastrado
 *
 * DELETE /api/mercado/produtos/notificar-disponibilidade.php
 * - Cancelar notificação
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/rate-limit.php';

header('Content-Type: application/json; charset=utf-8');
setCorsHeaders();

require_once dirname(dirname(dirname(__DIR__))) . '/config.php';

try {
    $pdo = new PDO(
        "pgsql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE,
        DB_USERNAME,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Table om_product_stock_alerts created via migration

    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    // Rate limiting: 10 stock alerts per hour per customer/IP (POST only)
    if ($method === 'POST') {
        $rateLimitKey = isset($input['customer_id']) && $input['customer_id']
            ? "stock_alert_c{$input['customer_id']}"
            : "stock_alert_" . getRateLimitIP();
        if (!checkRateLimit($rateLimitKey, 10, 60)) {
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'Muitas requisicoes. Tente novamente em 1 hora.']);
            exit;
        }
    }

    // ========== POST - Cadastrar alerta ==========
    if ($method === 'POST') {
        $productId = (int)($input['product_id'] ?? 0);
        $email = trim($input['email'] ?? '');
        $customerId = isset($input['customer_id']) ? (int)$input['customer_id'] : null;
        $phone = trim($input['phone'] ?? '');
        $notifyPush = !empty($input['notify_push']) ? 1 : 0;

        // Validações
        if (!$productId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'product_id obrigatório']);
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Email inválido']);
            exit;
        }

        // Verificar se produto existe
        $stmt = $pdo->prepare("SELECT product_id, quantity FROM oc_product WHERE product_id = ?");
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Produto não encontrado']);
            exit;
        }

        // Se produto já tem estoque, informar
        if ($product['quantity'] > 0) {
            echo json_encode([
                'success' => true,
                'message' => 'Boa notícia! Este produto já está disponível.',
                'in_stock' => true
            ]);
            exit;
        }

        // Inserir ou atualizar alerta
        $stmt = $pdo->prepare("
            INSERT INTO om_product_stock_alerts (product_id, customer_id, email, phone, notify_push)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT (product_id, email) DO UPDATE SET
                customer_id = COALESCE(EXCLUDED.customer_id, om_product_stock_alerts.customer_id),
                phone = COALESCE(EXCLUDED.phone, om_product_stock_alerts.phone),
                notify_push = EXCLUDED.notify_push,
                notified = 0,
                notified_at = NULL
        ");
        $stmt->execute([$productId, $customerId, $email, $phone ?: null, $notifyPush]);

        // Buscar nome do produto
        $stmt = $pdo->prepare("SELECT name FROM oc_product_description WHERE product_id = ? AND language_id = 2");
        $stmt->execute([$productId]);
        $productName = $stmt->fetchColumn() ?: 'Produto';

        echo json_encode([
            'success' => true,
            'message' => "Você será notificado quando \"$productName\" estiver disponível!",
            'product_name' => $productName
        ]);
    }

    // ========== GET - Verificar se está cadastrado ==========
    if ($method === 'GET') {
        $productId = (int)($_GET['product_id'] ?? 0);
        $email = trim($_GET['email'] ?? '');

        if (!$productId || !$email) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'product_id e email obrigatórios']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT id, created_at FROM om_product_stock_alerts
            WHERE product_id = ? AND email = ? AND notified = 0
        ");
        $stmt->execute([$productId, $email]);
        $alert = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'registered' => !empty($alert),
            'registered_at' => $alert['created_at'] ?? null
        ]);
    }

    // ========== DELETE - Cancelar alerta ==========
    if ($method === 'DELETE') {
        $productId = (int)($input['product_id'] ?? 0);
        $email = trim($input['email'] ?? '');

        if (!$productId || !$email) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'product_id e email obrigatórios']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM om_product_stock_alerts WHERE product_id = ? AND email = ?");
        $stmt->execute([$productId, $email]);

        echo json_encode([
            'success' => true,
            'message' => 'Notificação cancelada'
        ]);
    }

} catch (Exception $e) {
    error_log("[notificar-disponibilidade] Erro: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor']);
}
