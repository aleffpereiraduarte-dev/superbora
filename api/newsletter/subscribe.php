<?php
/**
 * API Newsletter - OneMundo
 *
 * POST /api/newsletter/subscribe.php - Inscrever na newsletter
 *
 * Parâmetros:
 * - email: Email do usuário (obrigatório)
 * - name: Nome (opcional)
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

try {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../database.php';
    require_once __DIR__ . '/../rate-limit/RateLimiter.php';

    // Rate limit: 10 inscrições por hora por IP (mais tolerante)
    RateLimiter::check(10, 3600);

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $email = trim($input['email'] ?? '');
    $name = trim($input['name'] ?? '');

    // Validações
    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Email é obrigatório']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Email inválido']);
        exit;
    }

    // Sanitizar
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

    $pdo = getConnection();

    // Criar tabela se não existir
    $pdo->exec("CREATE TABLE IF NOT EXISTS om_newsletter (
        id SERIAL PRIMARY KEY,
        email VARCHAR(255) NOT NULL UNIQUE,
        name VARCHAR(255),
        status VARCHAR(50) DEFAULT 'active' CHECK (status IN ('active', 'unsubscribed')),
        ip_address VARCHAR(45),
        subscribed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        unsubscribed_at TIMESTAMP NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_om_newsletter_email ON om_newsletter (email)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_om_newsletter_status ON om_newsletter (status)");

    // Verificar se já está inscrito
    $stmt = $pdo->prepare("SELECT id, status FROM om_newsletter WHERE email = ?");
    $stmt->execute([$email]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if ($existing['status'] === 'active') {
            echo json_encode([
                'success' => true,
                'already_subscribed' => true,
                'message' => 'Você já está inscrito na nossa newsletter!'
            ]);
            exit;
        } else {
            // Reativar inscrição
            $stmt = $pdo->prepare("
                UPDATE om_newsletter
                SET status = 'active', unsubscribed_at = NULL, name = COALESCE(?, name)
                WHERE id = ?
            ");
            $stmt->execute([$name ?: null, $existing['id']]);

            echo json_encode([
                'success' => true,
                'reactivated' => true,
                'message' => 'Sua inscrição foi reativada com sucesso!'
            ]);
            exit;
        }
    }

    // Nova inscrição
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
    $ip = explode(',', $ip)[0];

    $stmt = $pdo->prepare("
        INSERT INTO om_newsletter (email, name, ip_address)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$email, $name ?: null, $ip]);

    echo json_encode([
        'success' => true,
        'message' => 'Inscrição realizada com sucesso! Você receberá nossas novidades e promoções.',
        'subscribed' => true
    ]);

} catch (PDOException $e) {
    error_log("Newsletter error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao processar inscrição']);
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Muitas requisições') !== false) {
        // Já tratado pelo RateLimiter
        exit;
    }
    http_response_code(500);
    error_log("[newsletter/subscribe] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}
