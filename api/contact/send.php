<?php
/**
 * API Formulário de Contato - OneMundo
 *
 * POST /api/contact/send.php - Enviar mensagem de contato
 *
 * Parâmetros obrigatórios:
 * - name: Nome do remetente
 * - email: Email do remetente
 * - message: Mensagem
 *
 * Parâmetros opcionais:
 * - phone: Telefone
 * - subject: Assunto
 * - order_id: ID do pedido (para reclamações)
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

    // Rate limit: 15 mensagens por hora por IP (mais tolerante)
    RateLimiter::check(15, 3600);

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    // Campos obrigatórios
    $name = trim($input['name'] ?? '');
    $email = trim($input['email'] ?? '');
    $message = trim($input['message'] ?? '');

    // Campos opcionais
    $phone = trim($input['phone'] ?? '');
    $subject = trim($input['subject'] ?? 'Contato via site');
    $orderId = (int)($input['order_id'] ?? 0);

    // Validações
    $errors = [];

    if (empty($name)) {
        $errors['name'] = 'Nome é obrigatório';
    } elseif (strlen($name) < 2) {
        $errors['name'] = 'Nome muito curto';
    } elseif (strlen($name) > 100) {
        $errors['name'] = 'Nome muito longo';
    }

    if (empty($email)) {
        $errors['email'] = 'Email é obrigatório';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Email inválido';
    }

    if (empty($message)) {
        $errors['message'] = 'Mensagem é obrigatória';
    } elseif (strlen($message) < 10) {
        $errors['message'] = 'Mensagem muito curta (mínimo 10 caracteres)';
    } elseif (strlen($message) > 5000) {
        $errors['message'] = 'Mensagem muito longa (máximo 5000 caracteres)';
    }

    if (!empty($phone)) {
        $phoneClean = preg_replace('/\D/', '', $phone);
        if (strlen($phoneClean) < 10 || strlen($phoneClean) > 11) {
            $errors['phone'] = 'Telefone inválido';
        }
    }

    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Dados inválidos',
            'errors' => $errors
        ]);
        exit;
    }

    // Sanitizar
    $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $subject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
    $phone = preg_replace('/\D/', '', $phone);

    // Verificar spam básico (honeypot)
    if (!empty($input['website']) || !empty($input['url'])) {
        // Campo honeypot preenchido = bot
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Mensagem enviada!']);
        exit;
    }

    // Verificar tempo mínimo de preenchimento (< 3 segundos = bot)
    if (isset($input['_timestamp'])) {
        $formTime = time() - (int)$input['_timestamp'];
        if ($formTime < 3) {
            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Mensagem enviada!']);
            exit;
        }
    }

    $pdo = getConnection();

    // Criar tabela se não existir
    $pdo->exec("CREATE TABLE IF NOT EXISTS om_contact_messages (
        id SERIAL PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(255) NOT NULL,
        phone VARCHAR(20),
        subject VARCHAR(255),
        message TEXT NOT NULL,
        order_id INT,
        ip_address VARCHAR(45),
        user_agent VARCHAR(255),
        status VARCHAR(20) DEFAULT 'new',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_contact_email ON om_contact_messages(email)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_contact_status ON om_contact_messages(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_contact_created ON om_contact_messages(created_at)");

    // Inserir mensagem
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
    $ip = explode(',', $ip)[0];
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    $stmt = $pdo->prepare("
        INSERT INTO om_contact_messages
        (name, email, phone, subject, message, order_id, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        RETURNING id
    ");
    $stmt->execute([
        $name,
        $email,
        $phone ?: null,
        $subject,
        $message,
        $orderId ?: null,
        $ip,
        $userAgent
    ]);

    $messageId = $stmt->fetchColumn();

    // Tentar enviar email de notificação (se configurado)
    $emailSent = false;
    if (defined('CONTACT_EMAIL') && CONTACT_EMAIL) {
        $to = CONTACT_EMAIL;
        $emailSubject = "[Contato Site] $subject";
        $body = "Nova mensagem de contato:\n\n";
        $body .= "Nome: $name\n";
        $body .= "Email: $email\n";
        $body .= "Telefone: " . ($phone ?: 'Não informado') . "\n";
        if ($orderId) {
            $body .= "Pedido: #$orderId\n";
        }
        $body .= "\nMensagem:\n$message\n";
        $body .= "\n---\nEnviado em: " . date('d/m/Y H:i');
        $body .= "\nIP: $ip";

        $headers = "From: noreply@onemundo.com.br\r\n";
        $headers .= "Reply-To: $email\r\n";

        $emailSent = @mail($to, $emailSubject, $body, $headers);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Mensagem enviada com sucesso! Responderemos em breve.',
        'ticket_id' => $messageId,
        'email_notification' => $emailSent
    ]);

} catch (PDOException $e) {
    error_log("Contact form error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao enviar mensagem']);
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Muitas requisições') !== false) {
        exit;
    }
    http_response_code(500);
    error_log("[contact/send] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}
