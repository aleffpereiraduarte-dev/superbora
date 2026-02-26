<?php
/**
 * Smart Auth - Reenviar Código de Verificação
 *
 * POST /api/smart-auth/resend-code.php
 * {
 *   "customer_id": 123,
 *   "type": "email" | "phone"
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

    $input = json_decode(file_get_contents('php://input'), true);

    $customerId = (int)($input['customer_id'] ?? 0);
    $type = $input['type'] ?? '';
    $verifyMethod = $input['verify_method'] ?? 'whatsapp'; // whatsapp ou sms

    if (!$customerId || !in_array($type, ['email', 'phone'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
        exit;
    }

    $pdo = getDB();

    // Verificar rate limit (máximo 3 por hora)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM om_verification_codes
        WHERE customer_id = ? AND type = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute([$customerId, $type]);
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    if ($count >= 3) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Limite de códigos atingido. Aguarde 1 hora.']);
        exit;
    }

    // Buscar dados do cliente
    $stmt = $pdo->prepare("SELECT firstname, email, telephone FROM oc_customer WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Cliente não encontrado']);
        exit;
    }

    // Gerar novo código
    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = $type === 'email'
        ? date('Y-m-d H:i:s', strtotime('+30 minutes'))
        : date('Y-m-d H:i:s', strtotime('+10 minutes'));

    // Salvar código
    $stmt = $pdo->prepare("
        INSERT INTO om_verification_codes (customer_id, type, code, expires_at, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$customerId, $type, $code, $expiresAt]);

    // Enviar código
    if ($type === 'email') {
        sendVerificationEmail($customer['email'], $customer['firstname'], $code);
        $destination = maskEmail($customer['email']);
        $via = 'email';
    } else {
        if ($verifyMethod === 'whatsapp') {
            sendVerificationWhatsApp($customer['telephone'], $customer['firstname'], $code);
            $via = 'WhatsApp';
        } else {
            sendVerificationSMS($customer['telephone'], $code);
            $via = 'SMS';
        }
        $destination = maskPhone($customer['telephone']);
    }

    echo json_encode([
        'success' => true,
        'message' => "Código enviado via {$via} para {$destination}",
        'expires_in' => $type === 'email' ? 1800 : 600 // segundos
    ]);

} catch (Exception $e) {
    error_log("Resend Code Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}

function maskEmail($email) {
    $parts = explode('@', $email);
    $name = $parts[0];
    $domain = $parts[1];
    $masked = substr($name, 0, 2) . str_repeat('*', max(0, strlen($name) - 4)) . substr($name, -2);
    return $masked . '@' . $domain;
}

function maskPhone($phone) {
    $clean = preg_replace('/\D/', '', $phone);
    return '(' . substr($clean, 0, 2) . ') *****-' . substr($clean, -4);
}

function sendVerificationEmail($email, $name, $code) {
    $subject = "OneMundo - Novo Código de Verificação";
    $message = "
    <html>
    <body style='font-family: Arial, sans-serif; padding: 20px;'>
        <div style='max-width: 500px; margin: 0 auto; background: #f5f5f5; padding: 30px; border-radius: 10px;'>
            <h2 style='color: #1a1a2e;'>Olá, {$name}!</h2>
            <p>Seu novo código de verificação é:</p>
            <div style='background: #1a1a2e; color: white; font-size: 32px; letter-spacing: 8px; padding: 20px; text-align: center; border-radius: 8px; margin: 20px 0;'>
                {$code}
            </div>
            <p style='color: #666;'>Este código expira em 30 minutos.</p>
        </div>
    </body>
    </html>
    ";

    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: OneMundo <noreply@onemundo.com>'
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
        error_log("[smart-auth] Erro WhatsApp para " . substr($phone, 0, 2) . "****" . substr($phone, -2) . ": " . ($result['message'] ?? 'Erro desconhecido'));
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
