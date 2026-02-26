<?php
/**
 * POST /api/auth/recuperar-senha.php
 *
 * Envia código de recuperação por SMS (Twilio) ou email
 * { "telefone": "11999999999" } ou { "email": "user@example.com" }
 */
require_once __DIR__ . "/config.php";

try {
    $input = getInput();

    $email = trim($input["email"] ?? "");
    $telefone = preg_replace('/\D/', '', $input["telefone"] ?? "");

    if (empty($email) && empty($telefone)) {
        response(false, null, "Informe email ou telefone", 400);
    }

    $db = getDB();

    // Buscar usuário nas tabelas possíveis
    $user = null;
    $tabelas = ['boraum_passageiros', 'boraum_motoristas'];

    foreach ($tabelas as $tabela) {
        if (!empty($telefone)) {
            $stmt = $db->prepare("SELECT id, nome, telefone, email FROM {$tabela} WHERE telefone = ? LIMIT 1");
            $stmt->execute([$telefone]);
        } else {
            $stmt = $db->prepare("SELECT id, nome, telefone, email FROM {$tabela} WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
        }
        $user = $stmt->fetch();
        if ($user) {
            $user['tabela'] = $tabela;
            break;
        }
    }

    // Sempre retornar sucesso por segurança (não revelar se usuário existe)
    if (!$user) {
        response(true, null, "Se o email/telefone existir, enviaremos instruções de recuperação.");
    }

    // Gerar código de recuperação
    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

    // Criar tabela se não existir
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_password_reset (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            user_table VARCHAR(50) NOT NULL,
            codigo VARCHAR(10) NOT NULL,
            usado TINYINT DEFAULT 0,
            expires_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id, user_table)
        )
    ");

    // Invalidar resets anteriores
    $stmt = $db->prepare("UPDATE om_password_reset SET usado = -1 WHERE user_id = ? AND user_table = ? AND usado = 0");
    $stmt->execute([$user['id'], $user['tabela']]);

    // Salvar reset
    $stmt = $db->prepare("
        INSERT INTO om_password_reset (user_id, user_table, codigo, expires_at, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$user['id'], $user['tabela'], $code, $expiresAt]);

    // Enviar por SMS se tiver telefone
    $sent = false;
    if (!empty($user['telefone'])) {
        $twilioSid = defined('TWILIO_SID') ? TWILIO_SID : env('TWILIO_SID', '');
        $twilioToken = defined('TWILIO_TOKEN') ? TWILIO_TOKEN : env('TWILIO_TOKEN', '');
        $twilioPhone = defined('TWILIO_PHONE') ? TWILIO_PHONE : env('TWILIO_PHONE', '');

        if ($twilioSid && $twilioToken && $twilioPhone) {
            $phoneFormatted = '+55' . preg_replace('/\D/', '', $user['telefone']);
            $url = "https://api.twilio.com/2010-04-01/Accounts/{$twilioSid}/Messages.json";
            $smsBody = "OneMundo: Seu codigo de recuperacao de senha e {$code}. Valido por 15 min.";

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => "{$twilioSid}:{$twilioToken}",
                CURLOPT_POSTFIELDS => http_build_query([
                    'From' => $twilioPhone,
                    'To' => $phoneFormatted,
                    'Body' => $smsBody
                ])
            ]);

            $smsResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                $sent = true;
                error_log("SMS recuperação enviado para {$phoneFormatted}");
            } else {
                error_log("Erro SMS recuperação: HTTP {$httpCode}");
            }
        }
    }

    // Enviar por email se tiver email (fallback ou adicional)
    if (!empty($user['email'])) {
        $nome = $user['nome'] ?? 'Usuário';
        $subject = "OneMundo - Código de Recuperação de Senha";
        $message = "Olá, {$nome}!\n\nSeu código de recuperação é: {$code}\n\nVálido por 15 minutos.\n\nSe você não solicitou, ignore esta mensagem.";
        $headers = "From: OneMundo <noreply@onemundo.com>\r\nContent-Type: text/plain; charset=UTF-8";
        @mail($user['email'], $subject, $message, $headers);
        $sent = true;
    }

    if (!$sent) {
        error_log("recuperar-senha: Não foi possível enviar código para user_id={$user['id']}");
    }

    response(true, null, "Se o email/telefone existir, enviaremos instruções de recuperação.");

} catch (Exception $e) {
    error_log("recuperar-senha Error: " . $e->getMessage());
    response(false, null, "Erro interno. Tente novamente.", 500);
}
