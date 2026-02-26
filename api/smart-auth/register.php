<?php
/**
 * Smart Auth - Registro Inteligente com Claude AI
 *
 * POST /api/smart-auth/register.php
 * {
 *   "name": "Nome Completo",
 *   "email": "email@exemplo.com",
 *   "telephone": "11999999999",
 *   "cpf": "12345678900",
 *   "password": "senha123"
 * }
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
    require_once __DIR__ . '/../mercado/config/database.php';
    require_once __DIR__ . '/../mercado/helpers/zapi-whatsapp.php';

    // ClaudeAI é opcional - se não existir, usa validação básica
    $claudeAvailable = false;
    if (file_exists(__DIR__ . '/../../system/library/ClaudeAI.php')) {
        require_once __DIR__ . '/../../system/library/ClaudeAI.php';
        $claudeAvailable = true;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    // Dados obrigatórios
    $name = trim($input['name'] ?? '');
    $email = trim($input['email'] ?? '');
    $telephone = preg_replace('/\D/', '', $input['telephone'] ?? '');
    $cpf = preg_replace('/\D/', '', $input['cpf'] ?? '');
    $password = $input['password'] ?? '';
    $verifyMethod = $input['verify_method'] ?? 'whatsapp'; // whatsapp ou sms

    // Validações básicas
    $errors = [];

    if (strlen($name) < 3) {
        $errors[] = 'Nome deve ter pelo menos 3 caracteres';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email inválido';
    }

    if (strlen($telephone) < 10 || strlen($telephone) > 11) {
        $errors[] = 'Telefone inválido';
    }

    if (strlen($cpf) !== 11) {
        $errors[] = 'CPF deve ter 11 dígitos';
    }

    if (strlen($password) < 6) {
        $errors[] = 'Senha deve ter pelo menos 6 caracteres';
    }

    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit;
    }

    // Validar CPF (algoritmo)
    if (!validateCPF($cpf)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'errors' => ['CPF inválido']]);
        exit;
    }

    $pdo = getDB();

    // Verificar se email já existe
    $stmt = $pdo->prepare("SELECT customer_id FROM oc_customer WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'errors' => ['Email já cadastrado']]);
        exit;
    }

    // Verificar se CPF já existe
    $stmt = $pdo->prepare("SELECT customer_id FROM oc_customer WHERE cpf = ?");
    $stmt->execute([$cpf]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'errors' => ['CPF já cadastrado']]);
        exit;
    }

    // Usar Claude AI para validação inteligente (se disponível)
    $aiValidation = ['valid' => true, 'risk_score' => 0];
    $riskScore = 0;

    if ($claudeAvailable) {
        try {
            $claude = new ClaudeAI();
            $validation = $claude->validateRegistration([
                'name' => $name,
                'email' => $email,
                'telephone' => $telephone,
                'cpf' => $cpf
            ]);
            $aiValidation = $validation['validation'] ?? ['valid' => true];
            $riskScore = $aiValidation['risk_score'] ?? 0;
        } catch (Exception $e) {
            error_log("[smart-auth] ClaudeAI error: " . $e->getMessage());
            // Continue sem AI validation
        }
    }

    // Se risco muito alto, bloquear
    if ($riskScore > 80) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'errors' => ['Não foi possível completar o cadastro. Entre em contato com o suporte.'],
            'risk_detected' => true
        ]);
        exit;
    }

    // Aplicar correções sugeridas pela IA
    if (!empty($aiValidation['corrections'])) {
        if (!empty($aiValidation['corrections']['name'])) {
            $name = $aiValidation['corrections']['name'];
        }
    }

    // Separar primeiro nome e sobrenome
    $nameParts = explode(' ', $name, 2);
    $firstname = $nameParts[0];
    $lastname = $nameParts[1] ?? '';

    // Formatar telefone
    $telephoneFormatted = formatPhone($telephone);

    // Formatar CPF
    $cpfFormatted = formatCPF($cpf);

    // Gerar código de verificação de email
    $emailCode = generateCode(6);
    $emailCodeExpiry = date('Y-m-d H:i:s', strtotime('+30 minutes'));

    // Gerar código de verificação de telefone
    $phoneCode = generateCode(6);
    $phoneCodeExpiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    // Hash da senha com BCRYPT (SEGURO - substitui SHA1)
    $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    // Criar cliente (inativo até verificar email)
    $stmt = $pdo->prepare("
        INSERT INTO oc_customer (
            customer_group_id, store_id, language_id,
            firstname, lastname, email, telephone, cpf,
            password, salt, newsletter,
            status, safe, date_added
        ) VALUES (
            1, 0, 1,
            ?, ?, ?, ?, ?,
            ?, '', 0,
            0, 0, NOW()
        )
    ");
    $stmt->execute([
        $firstname, $lastname, $email, $telephoneFormatted, $cpfFormatted,
        $passwordHash
    ]);

    $customerId = $pdo->lastInsertId();

    // Salvar códigos de verificação
    $stmt = $pdo->prepare("
        INSERT INTO om_verification_codes (
            customer_id, type, code, expires_at, created_at
        ) VALUES
        (?, 'email', ?, ?, NOW()),
        (?, 'phone', ?, ?, NOW())
    ");
    $stmt->execute([
        $customerId, $emailCode, $emailCodeExpiry,
        $customerId, $phoneCode, $phoneCodeExpiry
    ]);

    // Salvar análise de risco da IA
    $stmt = $pdo->prepare("
        INSERT INTO om_customer_risk (
            customer_id, risk_score, risk_reasons, ai_analysis, created_at
        ) VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $customerId,
        $riskScore,
        json_encode($aiValidation['risk_reasons'] ?? []),
        json_encode($aiValidation)
    ]);

    // Enviar email de verificação
    sendVerificationEmail($email, $firstname, $emailCode);

    // Enviar código por WhatsApp ou SMS
    if ($verifyMethod === 'whatsapp') {
        $phoneResult = sendVerificationWhatsApp($telephone, $firstname, $phoneCode);
    } else {
        $phoneResult = sendVerificationSMS($telephone, $phoneCode);
    }

    // Retornar sucesso
    echo json_encode([
        'success' => true,
        'message' => 'Cadastro iniciado! Verifique seu email.',
        'customer_id' => $customerId,
        'verification_required' => [
            'email' => true,
            'phone' => true
        ],
        'ai_suggestions' => $aiValidation['suggestions'] ?? [],
        'next_step' => 'verify_email'
    ]);

} catch (Exception $e) {
    error_log("SmartAuth Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno. Tente novamente.']);
}

// Funções auxiliares

function validateCPF($cpf) {
    if (strlen($cpf) !== 11) return false;
    if (preg_match('/^(\d)\1+$/', $cpf)) return false;

    // Validar primeiro dígito
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += $cpf[$i] * (10 - $i);
    }
    $remainder = $sum % 11;
    $digit1 = ($remainder < 2) ? 0 : (11 - $remainder);

    if ($cpf[9] !== (string)$digit1) return false;

    // Validar segundo dígito
    $sum = 0;
    for ($i = 0; $i < 10; $i++) {
        $sum += $cpf[$i] * (11 - $i);
    }
    $remainder = $sum % 11;
    $digit2 = ($remainder < 2) ? 0 : (11 - $remainder);

    return $cpf[10] === (string)$digit2;
}

function formatPhone($phone) {
    $phone = preg_replace('/\D/', '', $phone);
    if (strlen($phone) === 11) {
        return '(' . substr($phone, 0, 2) . ') ' . substr($phone, 2, 5) . '-' . substr($phone, 7);
    } elseif (strlen($phone) === 10) {
        return '(' . substr($phone, 0, 2) . ') ' . substr($phone, 2, 4) . '-' . substr($phone, 6);
    }
    return $phone;
}

function formatCPF($cpf) {
    return substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
}

function generateCode($length = 6) {
    return str_pad(random_int(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
}

function token($length) {
    return bin2hex(random_bytes($length / 2));
}

function sendVerificationEmail($email, $name, $code) {
    // Em produção, usar PHPMailer ou serviço de email
    $subject = "OneMundo - Código de Verificação";
    $message = "
    <html>
    <body style='font-family: Arial, sans-serif; padding: 20px;'>
        <div style='max-width: 500px; margin: 0 auto; background: #f5f5f5; padding: 30px; border-radius: 10px;'>
            <h2 style='color: #1a1a2e;'>Olá, {$name}!</h2>
            <p>Seu código de verificação é:</p>
            <div style='background: #1a1a2e; color: white; font-size: 32px; letter-spacing: 8px; padding: 20px; text-align: center; border-radius: 8px; margin: 20px 0;'>
                {$code}
            </div>
            <p style='color: #666;'>Este código expira em 30 minutos.</p>
            <p style='color: #666; font-size: 12px;'>Se você não solicitou este código, ignore este email.</p>
        </div>
    </body>
    </html>
    ";

    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: OneMundo <noreply@onemundo.com>',
        'X-Mailer: PHP/' . phpversion()
    ];

    @mail($email, $subject, $message, implode("\r\n", $headers));
}

function sendVerificationWhatsApp($phone, $name, $code) {
    $mensagem = "🔐 *SuperBora - Código de Verificação*\n\n";
    $mensagem .= "Olá, *{$name}*!\n\n";
    $mensagem .= "Seu código de verificação é:\n\n";
    $mensagem .= "👉 *{$code}*\n\n";
    $mensagem .= "⏰ Válido por *10 minutos*.\n\n";
    $mensagem .= "_Não compartilhe este código com ninguém._";

    // Usa Z-API helper
    $result = sendWhatsApp($phone, $mensagem);

    if ($result['success']) {
        error_log("[smart-auth] WhatsApp OTP enviado para " . substr($phone, 0, 2) . "****" . substr($phone, -2));
    } else {
        error_log("[smart-auth] Erro WhatsApp: " . ($result['message'] ?? 'Erro desconhecido'));
    }

    return $result;
}

function sendVerificationSMS($phone, $code) {
    // Integração com Twilio para SMS
    $twilioSid = defined('TWILIO_SID') ? TWILIO_SID : getenv('TWILIO_SID');
    $twilioToken = defined('TWILIO_TOKEN') ? TWILIO_TOKEN : getenv('TWILIO_TOKEN');
    $twilioPhone = defined('TWILIO_PHONE') ? TWILIO_PHONE : getenv('TWILIO_PHONE');

    if (!$twilioSid || !$twilioToken || !$twilioPhone) {
        error_log("SMS: Credenciais Twilio não configuradas. Código para {$phone}: {$code}");
        return ['success' => false, 'error' => 'SMS não configurado'];
    }

    $phone = preg_replace('/\D/', '', $phone);
    if (strlen($phone) == 10 || strlen($phone) == 11) {
        $phone = '+55' . $phone;
    } elseif (strlen($phone) >= 12 && substr($phone, 0, 2) === '55') {
        $phone = '+' . $phone;
    }

    $url = "https://api.twilio.com/2010-04-01/Accounts/{$twilioSid}/Messages.json";
    $message = "OneMundo: Seu codigo de verificacao e {$code}. Valido por 10 min.";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => "{$twilioSid}:{$twilioToken}",
        CURLOPT_POSTFIELDS => http_build_query([
            'From' => $twilioPhone,
            'To' => $phone,
            'Body' => $message
        ])
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("SMS Twilio cURL Error: {$curlError}");
        return ['success' => false, 'error' => 'Erro de conexão: ' . $curlError];
    }

    $result = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        error_log("SMS Twilio enviado para {$phone} - SID: " . ($result['sid'] ?? 'N/A'));
        return ['success' => true, 'message_id' => $result['sid'] ?? null];
    } else {
        $errorMsg = $result['message'] ?? 'Erro ao enviar SMS';
        error_log("Erro SMS Twilio para {$phone}: {$errorMsg} (HTTP {$httpCode})");
        return ['success' => false, 'error' => $errorMsg];
    }
}
