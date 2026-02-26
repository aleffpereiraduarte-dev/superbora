<?php
require_once __DIR__ . "/_debug_log.php";
/**
 * POST /api/auth/verificar-telefone.php
 *
 * Verificação de telefone via WhatsApp (Z-API) + SMS (Twilio)
 *
 * Enviar código:
 *   POST { "telefone": "33999652818", "action": "enviar" }
 *
 * Verificar código:
 *   POST { "telefone": "33999652818", "codigo": "123456", "action": "verificar" }
 */
require_once dirname(__DIR__) . '/includes/cors.php';
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database.php';
require_once dirname(__DIR__, 2) . '/includes/env_loader.php';
require_once dirname(__DIR__) . '/mercado/helpers/zapi-whatsapp.php';

// JWT support
$jwtSecret = env('JWT_SECRET', '');
if (empty($jwtSecret) || $jwtSecret === 'CHANGE_ME_IN_PRODUCTION_USE_RANDOM_64_CHAR_STRING') {
    $jwtSecret = hash('sha256', DB_PASSWORD . DB_DATABASE . 'onemundo_fallback_2024');
}
if (!defined("JWT_SECRET")) define("JWT_SECRET", $jwtSecret);
if (!defined("TOKEN_EXPIRY")) define("TOKEN_EXPIRY", 86400 * 7);

function jsonResponse($success, $data = null, $message = "", $code = 200) {
    http_response_code($code);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(["success" => $success, "message" => $message, "data" => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $input = json_decode(file_get_contents("php://input"), true) ?: $_POST;

    $telefone = preg_replace('/\D/', '', $input["telefone"] ?? "");
    $codigo = $input["codigo"] ?? "";
    $action = $input["action"] ?? (empty($codigo) ? "enviar" : "verificar");

    if (empty($telefone) || strlen($telefone) < 10 || strlen($telefone) > 11) {
        jsonResponse(false, null, "Telefone inválido", 400);
    }

    $db = getConnection();

    if ($action === "enviar") {
        // Criar tabela se não existir (PostgreSQL)
        $db->exec("
            CREATE TABLE IF NOT EXISTS om_phone_verification (
                id SERIAL PRIMARY KEY,
                telefone VARCHAR(20) NOT NULL,
                codigo VARCHAR(10) NOT NULL,
                tentativas INT DEFAULT 0,
                verificado SMALLINT DEFAULT 0,
                expires_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        try { $db->exec("CREATE INDEX IF NOT EXISTS idx_phone_ver_telefone ON om_phone_verification(telefone)"); } catch (Exception $e) {}
        try { $db->exec("CREATE INDEX IF NOT EXISTS idx_phone_ver_expires ON om_phone_verification(expires_at)"); } catch (Exception $e) {}

        // Rate limiting: max 5 códigos por hora por telefone
        $stmt = $db->prepare("
            SELECT COUNT(*) as total FROM om_phone_verification
            WHERE telefone = ? AND created_at > NOW() - INTERVAL '1 hour'
        ");
        $stmt->execute([$telefone]);
        $count = $stmt->fetch()['total'] ?? 0;

        if ($count >= 5) {
            jsonResponse(false, null, "Limite de códigos atingido. Aguarde 1 hora.", 429);
        }

        // Gerar código de 6 dígitos
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        // Invalidar códigos anteriores para este telefone
        $stmt = $db->prepare("
            UPDATE om_phone_verification SET verificado = -1
            WHERE telefone = ? AND verificado = 0
        ");
        $stmt->execute([$telefone]);

        // Inserir novo código
        $stmt = $db->prepare("
            INSERT INTO om_phone_verification (telefone, codigo, expires_at, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$telefone, $code, $expiresAt]);

        // Enviar via WhatsApp (Z-API) - tentativa principal
        $whatsappSent = false;
        $telefoneComDDI = '55' . $telefone; // Z-API precisa do código do país
        $result = whatsappOTP($telefoneComDDI, $code);
        if ($result['success']) {
            $whatsappSent = true;
            error_log("[verificar-telefone] WhatsApp OTP enviado para {$telefone}");
        } else {
            error_log("[verificar-telefone] WhatsApp falhou para {$telefone}: " . ($result['message'] ?? 'Erro'));
        }

        // Enviar via SMS (Twilio) como backup
        $smsSent = false;
        $twilioSid = env('TWILIO_SID', '');
        $twilioToken = env('TWILIO_TOKEN', '');
        $twilioPhone = env('TWILIO_PHONE', '');
        if (!empty($twilioSid) && !empty($twilioToken) && !empty($twilioPhone)) {
            $telefoneE164 = '+55' . $telefone;
            $smsBody = "SuperBora: Seu codigo de verificacao e {$code}. Valido por 10 min.";
            $url = "https://api.twilio.com/2010-04-01/Accounts/{$twilioSid}/Messages.json";

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => "{$twilioSid}:{$twilioToken}",
                CURLOPT_POSTFIELDS => http_build_query([
                    'From' => $twilioPhone,
                    'To' => $telefoneE164,
                    'Body' => $smsBody
                ])
            ]);
            $smsResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                $smsSent = true;
                error_log("[verificar-telefone] SMS Twilio enviado para {$telefoneE164}");
            } else {
                error_log("[verificar-telefone] SMS falhou para {$telefoneE164} (HTTP {$httpCode})");
            }
        }

        if (!$whatsappSent && !$smsSent) {
            jsonResponse(false, null, "Erro ao enviar código. Tente novamente.", 500);
        }

        // Mascarar telefone para resposta
        $maskedPhone = '(' . substr($telefone, 0, 2) . ') *****-' . substr($telefone, -4);
        $channels = [];
        if ($whatsappSent) $channels[] = 'WhatsApp';
        if ($smsSent) $channels[] = 'SMS';
        $channelText = implode(' e ', $channels);

        jsonResponse(true, [
            "telefone_masked" => $maskedPhone,
            "channel" => $whatsappSent ? "whatsapp" : "sms",
            "whatsapp_sent" => $whatsappSent,
            "sms_sent" => $smsSent,
            "expires_in" => 600
        ], "Código enviado via {$channelText} para {$maskedPhone}");

    } elseif ($action === "verificar") {
        $codigo = preg_replace('/\D/', '', $codigo);

        if (strlen($codigo) !== 6) {
            jsonResponse(false, null, "Código deve ter 6 dígitos", 400);
        }

        $stmt = $db->prepare("
            SELECT id, codigo, tentativas, expires_at
            FROM om_phone_verification
            WHERE telefone = ? AND verificado = 0
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$telefone]);
        $verification = $stmt->fetch();

        if (!$verification) {
            jsonResponse(false, null, "Nenhum código pendente. Solicite um novo código.", 400);
        }

        if (strtotime($verification['expires_at']) < time()) {
            $stmt = $db->prepare("UPDATE om_phone_verification SET verificado = -1 WHERE id = ?");
            $stmt->execute([$verification['id']]);
            jsonResponse(false, null, "Código expirado. Solicite um novo código.", 400);
        }

        if ($verification['tentativas'] >= 5) {
            $stmt = $db->prepare("UPDATE om_phone_verification SET verificado = -1 WHERE id = ?");
            $stmt->execute([$verification['id']]);
            jsonResponse(false, null, "Muitas tentativas. Solicite um novo código.", 429);
        }

        $stmt = $db->prepare("UPDATE om_phone_verification SET tentativas = tentativas + 1 WHERE id = ?");
        $stmt->execute([$verification['id']]);

        if ($verification['codigo'] === $codigo) {
            $stmt = $db->prepare("UPDATE om_phone_verification SET verificado = 1 WHERE id = ?");
            $stmt->execute([$verification['id']]);
            jsonResponse(true, ["verificado" => true], "Telefone verificado com sucesso!");
        } else {
            $remaining = 5 - ($verification['tentativas'] + 1);
            jsonResponse(false, [
                "verificado" => false,
                "tentativas_restantes" => max(0, $remaining)
            ], "Código incorreto. {$remaining} tentativa(s) restante(s).", 400);
        }
    } else {
        jsonResponse(false, null, "Ação inválida. Use 'enviar' ou 'verificar'.", 400);
    }

} catch (Exception $e) {
    error_log("verificar-telefone Error: " . $e->getMessage() . " | " . $e->getFile() . ":" . $e->getLine());
    jsonResponse(false, null, "Erro interno. Tente novamente.", 500);
}
