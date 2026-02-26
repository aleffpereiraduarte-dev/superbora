<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
/**
 * API: Cadastro de Worker
 * POST /api/register.php
 */
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["success" => false, "error" => "Método não permitido"]);
    exit;
}

try {
    $pdo = getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data) $data = $_POST;
    
    // Validações
    $required = ["name", "email", "phone", "cpf", "worker_type"];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            throw new Exception("Campo obrigatório: $field");
        }
    }
    
    // Validar tipo
    $validTypes = ["shopper", "driver", "full_service"];
    if (!in_array($data["worker_type"], $validTypes)) {
        throw new Exception("Tipo de worker inválido");
    }
    
    // Validar CPF único
    $cpf = preg_replace("/[^0-9]/", "", $data["cpf"]);
    $stmt = $pdo->prepare("SELECT worker_id FROM om_market_workers WHERE cpf = ?");
    $stmt->execute([$cpf]);
    if ($stmt->fetch()) {
        throw new Exception("CPF já cadastrado");
    }
    
    // Validar email único
    $stmt = $pdo->prepare("SELECT worker_id FROM om_market_workers WHERE email = ?");
    $stmt->execute([$data["email"]]);
    if ($stmt->fetch()) {
        throw new Exception("E-mail já cadastrado");
    }
    
    // Gerar código de verificação
    $verificationCode = str_pad(rand(0, 999999), 6, "0", STR_PAD_LEFT);
    $codeExpires = date("Y-m-d H:i:s", strtotime("+10 minutes"));
    
    // Hash da senha (se fornecida)
    $passwordHash = null;
    if (!empty($data["password"])) {
        $passwordHash = password_hash($data["password"], PASSWORD_DEFAULT);
    }
    
    // Inserir worker
    $stmt = $pdo->prepare("INSERT INTO om_market_workers 
        (name, email, phone, cpf, worker_type, birth_date, password_hash,
         address, address_number, neighborhood, city, state, cep,
         verification_code, verification_code_expires,
         application_status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \"submitted\", NOW())");
    
    $stmt->execute([
        $data["name"],
        $data["email"],
        preg_replace("/[^0-9]/", "", $data["phone"]),
        $cpf,
        $data["worker_type"],
        $data["birth_date"] ?? null,
        $passwordHash,
        $data["address"] ?? null,
        $data["address_number"] ?? null,
        $data["neighborhood"] ?? null,
        $data["city"] ?? null,
        $data["state"] ?? null,
        $data["cep"] ?? null,
        $verificationCode,
        $codeExpires
    ]);
    
    $workerId = $pdo->lastInsertId();
    
    // Enviar SMS com código de verificação via Twilio
    $twilioSid = defined('TWILIO_SID') ? TWILIO_SID : getenv('TWILIO_SID');
    $twilioToken = defined('TWILIO_TOKEN') ? TWILIO_TOKEN : getenv('TWILIO_TOKEN');
    $twilioPhone = defined('TWILIO_PHONE') ? TWILIO_PHONE : getenv('TWILIO_PHONE');

    if ($twilioSid && $twilioToken && $twilioPhone) {
        $phoneClean = preg_replace('/\D/', '', $data["phone"]);
        $phoneTo = '+55' . $phoneClean;
        $smsUrl = "https://api.twilio.com/2010-04-01/Accounts/{$twilioSid}/Messages.json";

        $ch = curl_init($smsUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => "{$twilioSid}:{$twilioToken}",
            CURLOPT_POSTFIELDS => http_build_query([
                'From' => $twilioPhone,
                'To' => $phoneTo,
                'Body' => "OneMundo: Seu codigo de verificacao e {$verificationCode}. Valido por 10 min."
            ])
        ]);
        $smsResp = curl_exec($ch);
        $smsCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($smsCode >= 200 && $smsCode < 300) {
            error_log("SMS verificacao worker enviado para {$phoneTo}");
        } else {
            error_log("Erro SMS worker para {$phoneTo}: HTTP {$smsCode}");
        }
    } else {
        error_log("SMS: Credenciais Twilio nao configuradas. Codigo para {$data['phone']}: {$verificationCode}");
    }
    
    // Salvar na sessão para verificação
    session_start();
    $_SESSION["pending_worker_id"] = $workerId;
    $_SESSION["pending_phone"] = $data["phone"];
    $_SESSION["pending_email"] = $data["email"];
    $_SESSION["debug_code"] = $verificationCode; // Remover em produção
    
    echo json_encode([
        "success" => true,
        "message" => "Cadastro realizado! Verifique seu telefone.",
        "worker_id" => $workerId,
        "redirect" => "verificar.php"
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}