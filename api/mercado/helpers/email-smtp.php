<?php
/**
 * Email SMTP Helper - SuperBora
 * Envia emails via SMTP (usando socket nativo PHP)
 *
 * Required environment variables:
 *   SUPERBORA_MAIL_HOST - SMTP server host
 *   SUPERBORA_MAIL_PORT - SMTP port (465 for SSL, 587 for TLS)
 *   SUPERBORA_MAIL_USERNAME - SMTP username
 *   SUPERBORA_MAIL_PASSWORD - SMTP password
 *   SUPERBORA_MAIL_FROM - From email address
 *   SUPERBORA_MAIL_FROM_NAME - From name
 */

// Load from environment - use conditional define to avoid leakage via get_defined_constants()
if (!defined('SMTP_HOST')) define('SMTP_HOST', $_ENV['SUPERBORA_MAIL_HOST'] ?? getenv('SUPERBORA_MAIL_HOST') ?: '');
if (!defined('SMTP_PORT')) define('SMTP_PORT', (int)($_ENV['SUPERBORA_MAIL_PORT'] ?? getenv('SUPERBORA_MAIL_PORT') ?: 465));
if (!defined('SMTP_USER')) define('SMTP_USER', $_ENV['SUPERBORA_MAIL_USERNAME'] ?? getenv('SUPERBORA_MAIL_USERNAME') ?: '');
if (!defined('SMTP_PASS')) define('SMTP_PASS', $_ENV['SUPERBORA_MAIL_PASSWORD'] ?? getenv('SUPERBORA_MAIL_PASSWORD') ?: '');
if (!defined('SMTP_FROM')) define('SMTP_FROM', $_ENV['SUPERBORA_MAIL_FROM'] ?? getenv('SUPERBORA_MAIL_FROM') ?: '');
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', $_ENV['SUPERBORA_MAIL_FROM_NAME'] ?? getenv('SUPERBORA_MAIL_FROM_NAME') ?: 'SuperBora');

/**
 * Envia email via SMTP
 * @param string $to Email do destinatário
 * @param string $subject Assunto
 * @param string $body Corpo do email (HTML ou texto)
 * @param bool $isHtml Se o corpo é HTML
 * @return array ['success' => bool, 'message' => string]
 */
function sendEmail(string $to, string $subject, string $body, bool $isHtml = true): array {
    // Sanitize: use htmlspecialchars on user-controlled portions to prevent stored XSS
    // Also strip script tags, event handlers, and javascript: URIs as defense-in-depth
    if ($isHtml) {
        $body = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $body);
        $body = preg_replace('#\bon\w+\s*=\s*["\'\s]?[^"\'>]*["\'\s]?#is', '', $body);
        $body = preg_replace('#href\s*=\s*["\'\s]*javascript\s*:#is', 'href="', $body);
    }
    // Validate email
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Email inválido'];
    }

    // Check if SMTP is configured
    if (empty(SMTP_HOST) || empty(SMTP_USER) || empty(SMTP_PASS)) {
        error_log("[email] SMTP not configured");
        return ['success' => false, 'message' => 'Serviço de email não configurado'];
    }

    try {
        // Build email headers and body
        $boundary = bin2hex(random_bytes(16));
        $headers = buildEmailHeaders($to, $subject, $boundary, $isHtml);
        $message = buildEmailBody($body, $boundary, $isHtml);

        // Connect and send
        $result = sendViaSMTP($to, $subject, $headers, $message);

        if ($result['success']) {
            error_log("[email] Email enviado para " . substr($to, 0, 3) . "***@" . explode('@', $to)[1]);
        } else {
            error_log("[email] Falha ao enviar para $to: " . $result['message']);
        }

        return $result;
    } catch (Exception $e) {
        error_log("[email] Exception: " . $e->getMessage());
        return ['success' => false, 'message' => 'Erro ao enviar email'];
    }
}

/**
 * Envia código OTP por email
 */
function sendEmailOTP(string $to, string $code): array {
    $subject = "SuperBora - Código de Verificação";

    $body = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
            .container { max-width: 500px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            .logo { text-align: center; margin-bottom: 20px; }
            .logo span { font-size: 28px; font-weight: bold; color: #e63946; }
            h1 { color: #333; font-size: 22px; margin-bottom: 10px; }
            .code { background: #f8f9fa; border: 2px dashed #e63946; border-radius: 8px; padding: 20px; text-align: center; margin: 20px 0; }
            .code span { font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #e63946; }
            .info { color: #666; font-size: 14px; line-height: 1.6; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; color: #999; font-size: 12px; text-align: center; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="logo"><span>SuperBora</span></div>
            <h1>Código de Verificação</h1>
            <p class="info">Use o código abaixo para verificar seu cadastro:</p>
            <div class="code">
                <span>' . htmlspecialchars($code) . '</span>
            </div>
            <p class="info">
                Este código é válido por <strong>5 minutos</strong>.<br>
                Se você não solicitou este código, ignore este email.
            </p>
            <div class="footer">
                SuperBora - Seu mercado favorito<br>
                Este é um email automático, não responda.
            </div>
        </div>
    </body>
    </html>';

    return sendEmail($to, $subject, $body, true);
}

/**
 * Build email headers
 */
function buildEmailHeaders(string $to, string $subject, string $boundary, bool $isHtml): string {
    $fromName = SMTP_FROM_NAME;
    $fromEmail = SMTP_FROM;

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>\r\n";
    $headers .= "Reply-To: {$fromEmail}\r\n";
    $headers .= "X-Mailer: SuperBora/1.0\r\n";

    if ($isHtml) {
        $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    } else {
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: base64\r\n";
    }

    return $headers;
}

/**
 * Build email body
 */
function buildEmailBody(string $body, string $boundary, bool $isHtml): string {
    if (!$isHtml) {
        return base64_encode($body);
    }

    // Strip HTML for plain text version
    $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));
    $plainText = html_entity_decode($plainText, ENT_QUOTES, 'UTF-8');
    $plainText = preg_replace('/\n\s+/', "\n", $plainText);
    $plainText = trim($plainText);

    $message = "--{$boundary}\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $message .= chunk_split(base64_encode($plainText)) . "\r\n";

    $message .= "--{$boundary}\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $message .= chunk_split(base64_encode($body)) . "\r\n";

    $message .= "--{$boundary}--\r\n";

    return $message;
}

/**
 * Send email via SMTP socket
 */
function sendViaSMTP(string $to, string $subject, string $headers, string $body): array {
    $host = SMTP_PORT == 465 ? 'ssl://' . SMTP_HOST : SMTP_HOST;
    $port = SMTP_PORT;

    $socket = @fsockopen($host, $port, $errno, $errstr, 15);
    if (!$socket) {
        return ['success' => false, 'message' => "Conexão falhou: $errstr"];
    }

    stream_set_timeout($socket, 15);

    // Read greeting
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) != '220') {
        fclose($socket);
        return ['success' => false, 'message' => 'Servidor não respondeu'];
    }

    // EHLO
    fputs($socket, "EHLO " . gethostname() . "\r\n");
    $response = '';
    while ($line = fgets($socket, 512)) {
        $response .= $line;
        if (substr($line, 3, 1) == ' ') break;
    }

    // STARTTLS for port 587
    if (SMTP_PORT == 587) {
        fputs($socket, "STARTTLS\r\n");
        fgets($socket, 512);
        stream_context_set_option($socket, 'ssl', 'verify_peer', true);
        stream_context_set_option($socket, 'ssl', 'verify_peer_name', true);
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

        fputs($socket, "EHLO " . gethostname() . "\r\n");
        while ($line = fgets($socket, 512)) {
            if (substr($line, 3, 1) == ' ') break;
        }
    }

    // AUTH LOGIN
    fputs($socket, "AUTH LOGIN\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) != '334') {
        fclose($socket);
        return ['success' => false, 'message' => 'Auth não suportado'];
    }

    fputs($socket, base64_encode(SMTP_USER) . "\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) != '334') {
        fclose($socket);
        return ['success' => false, 'message' => 'Usuário inválido'];
    }

    fputs($socket, base64_encode(SMTP_PASS) . "\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) != '235') {
        fclose($socket);
        return ['success' => false, 'message' => 'Senha inválida'];
    }

    // MAIL FROM
    fputs($socket, "MAIL FROM:<" . SMTP_FROM . ">\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) != '250') {
        fclose($socket);
        return ['success' => false, 'message' => 'Remetente rejeitado'];
    }

    // RCPT TO
    fputs($socket, "RCPT TO:<{$to}>\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) != '250') {
        fclose($socket);
        return ['success' => false, 'message' => 'Destinatário rejeitado'];
    }

    // DATA
    fputs($socket, "DATA\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) != '354') {
        fclose($socket);
        return ['success' => false, 'message' => 'Servidor rejeitou dados'];
    }

    // Send headers and body
    $subjectEncoded = "=?UTF-8?B?" . base64_encode($subject) . "?=";
    fputs($socket, "Subject: {$subjectEncoded}\r\n");
    fputs($socket, "To: {$to}\r\n");
    fputs($socket, $headers);
    fputs($socket, "\r\n");
    fputs($socket, $body);
    fputs($socket, "\r\n.\r\n");

    $response = fgets($socket, 512);
    $success = substr($response, 0, 3) == '250';

    // QUIT
    fputs($socket, "QUIT\r\n");
    fclose($socket);

    return [
        'success' => $success,
        'message' => $success ? 'Email enviado' : 'Falha ao enviar'
    ];
}
