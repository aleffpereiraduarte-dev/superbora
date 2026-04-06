<?php
/**
 * Email Transacional Helper - SuperBora
 *
 * Professional email system with:
 * - Retry logic (3 attempts with exponential backoff)
 * - DB-backed queue for async sending
 * - Delivery tracking in om_email_logs
 * - Consistent branded HTML templates
 * - Dual send path: direct (msmtp) or queued (DB + cron)
 *
 * Usage:
 *   require_once __DIR__ . '/email.php';
 *
 *   // Direct send (with retry):
 *   sendEmail($to, $subject, $html, $db, $customerId, 'template_name');
 *
 *   // Queue for async send (preferred for non-critical emails):
 *   queueMarketEmail($to, $subject, $html, $db, $customerId, 'template_name');
 *
 *   // Template helpers:
 *   sendOrderConfirmEmail($db, $customerId, $email, $name, $order);
 *   sendOrderStatusEmail($db, $customerId, $email, $name, $orderId, $status, $label);
 *   sendWelcomeEmail($db, $customerId, $email, $name);
 */

// ═══════════════════════════════════════════
// CONFIGURATION
// ═══════════════════════════════════════════

define('EMAIL_MAX_RETRIES', 3);
define('EMAIL_RETRY_BASE_DELAY_MS', 500); // 500ms, 1s, 2s exponential backoff
define('EMAIL_FROM_ADDRESS', 'contato@superbora.com.br');
define('EMAIL_FROM_NAME', 'SuperBora');

// ═══════════════════════════════════════════
// CORE SEND FUNCTION (with retry)
// ═══════════════════════════════════════════

/**
 * Envia email com retry automatico via msmtp
 *
 * @param string $to Destinatario
 * @param string $subject Assunto
 * @param string $htmlBody Corpo HTML
 * @param PDO|null $db Conexao para logging
 * @param int|null $customerId Para logging
 * @param string $template Nome do template para logging
 * @param int|null $queueId ID do item na fila (se enviado via cron)
 * @return bool
 */
function sendEmail(string $to, string $subject, string $htmlBody, ?PDO $db = null, ?int $customerId = null, string $template = 'generic', ?int $queueId = null): bool {
    // SECURITY: Validate email address to prevent command injection via proc_open args
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("[Email] Invalid recipient address: " . substr($to, 0, 100));
        _logEmail($db, $customerId, $to, $template, $subject, 'failed', ['error' => 'invalid email address'], $queueId);
        return false;
    }

    $from = EMAIL_FROM_ADDRESS;
    $fromName = EMAIL_FROM_NAME;

    // Build MIME message
    $boundary = bin2hex(random_bytes(16));
    $headers = "From: {$fromName} <{$from}>\r\n";
    $headers .= "Reply-To: {$from}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "X-Mailer: SuperBora/2.0\r\n";
    $headers .= "X-Priority: 3\r\n";

    // Plain text fallback
    $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $htmlBody));
    $plainText = html_entity_decode($plainText, ENT_QUOTES, 'UTF-8');
    $plainText = preg_replace('/\n{3,}/', "\n\n", $plainText);
    $plainText = trim($plainText);

    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($plainText)) . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
    $body .= "--{$boundary}--\r\n";

    // Subject encoding for UTF-8
    $subjectEncoded = "=?UTF-8?B?" . base64_encode($subject) . "?=";
    $message = "To: {$to}\r\nSubject: {$subjectEncoded}\r\n{$headers}\r\n{$body}";

    // Retry loop with exponential backoff
    $lastError = '';
    $attempt = 0;
    $success = false;

    for ($attempt = 1; $attempt <= EMAIL_MAX_RETRIES; $attempt++) {
        $result = _sendViaMsmtp($to, $message);

        if ($result['success']) {
            $success = true;
            if ($attempt > 1) {
                error_log("[Email] Sent to {$to} on attempt {$attempt}");
            }
            break;
        }

        $lastError = $result['error'];
        error_log("[Email] Attempt {$attempt}/{$attempt} failed for {$to}: {$lastError}");

        // Don't sleep after last attempt
        if ($attempt < EMAIL_MAX_RETRIES) {
            $delayMs = EMAIL_RETRY_BASE_DELAY_MS * pow(2, $attempt - 1);
            usleep($delayMs * 1000);
        }
    }

    // Log result
    $sendMethod = $queueId ? 'queued' : 'direct';
    _logEmail(
        $db,
        $customerId,
        $to,
        $template,
        $subject,
        $success ? 'sent' : 'failed',
        $success ? ['attempts' => $attempt, 'method' => $sendMethod] : ['error' => $lastError, 'attempts' => $attempt, 'method' => $sendMethod],
        $queueId,
        $attempt
    );

    return $success;
}

/**
 * Send via msmtp subprocess (single attempt)
 */
function _sendViaMsmtp(string $to, string $message): array {
    $proc = proc_open(
        ['msmtp', '-a', 'superbora', $to],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    if (!is_resource($proc)) {
        return ['success' => false, 'error' => 'msmtp process failed to start'];
    }

    fwrite($pipes[0], $message);
    fclose($pipes[0]);

    // Read with timeout to prevent hanging
    stream_set_timeout($pipes[1], 30);
    stream_set_timeout($pipes[2], 30);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    if ($exitCode === 0) {
        return ['success' => true, 'error' => ''];
    }

    return ['success' => false, 'error' => "exit_code={$exitCode} stderr=" . trim($stderr)];
}

// ═══════════════════════════════════════════
// EMAIL QUEUE (DB-backed async)
// ═══════════════════════════════════════════

/**
 * Queue an email for async delivery via cron.
 * Use this for non-time-critical emails (campaigns, digests, follow-ups).
 * Time-critical emails (OTP, order confirm) should use sendEmail() directly.
 *
 * @param string $to Recipient email
 * @param string $subject Subject line
 * @param string $htmlBody Full HTML body
 * @param PDO|null $db Database connection (will create one if null)
 * @param int|null $customerId Customer ID for tracking
 * @param string $template Template name for tracking
 * @param string|null $toName Recipient name
 * @param int $priority 0=normal, 1=high, -1=low
 * @param string|null $scheduledAt ISO datetime for scheduled send (default: NOW)
 * @return int|false Queue ID on success, false on failure
 */
function queueMarketEmail(
    string $to,
    string $subject,
    string $htmlBody,
    ?PDO $db = null,
    ?int $customerId = null,
    string $template = 'generic',
    ?string $toName = null,
    int $priority = 0,
    ?string $scheduledAt = null
): int|false {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("[EmailQueue] Invalid email: " . substr($to, 0, 100));
        return false;
    }

    try {
        if (!$db) {
            // Require database.php if not already loaded
            if (!function_exists('getDB')) {
                require_once dirname(__DIR__) . '/config/database.php';
            }
            $db = getDB();
        }

        // Plain text fallback
        $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $htmlBody));
        $plainText = html_entity_decode($plainText, ENT_QUOTES, 'UTF-8');
        $plainText = preg_replace('/\n{3,}/', "\n\n", trim($plainText));

        $stmt = $db->prepare("
            INSERT INTO om_market_email_queue
                (to_email, to_name, subject, body_html, body_text, template, customer_id, priority, status, scheduled_at, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, 'pending', COALESCE(?::timestamp, NOW()), NOW(), NOW())
            RETURNING id
        ");
        $stmt->execute([
            $to,
            $toName,
            $subject,
            $htmlBody,
            $plainText,
            $template,
            $customerId,
            $priority,
            $scheduledAt,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $queueId = $row ? (int)$row['id'] : false;

        if ($queueId) {
            error_log("[EmailQueue] Queued email #{$queueId} to {$to}: {$subject}");
        }

        return $queueId;

    } catch (Exception $e) {
        error_log("[EmailQueue] Failed to queue email: " . $e->getMessage());
        // Fallback: try to send directly if queue insert fails
        error_log("[EmailQueue] Falling back to direct send for {$to}");
        $sent = sendEmail($to, $subject, $htmlBody, $db, $customerId, $template);
        return $sent ? -1 : false; // -1 signals "sent directly, not queued"
    }
}

/**
 * Queue a templated email using one of the built-in template functions.
 * Convenience wrapper that builds HTML then queues.
 */
function queueOrderConfirmEmail(PDO $db, int $customerId, string $email, string $customerName, array $order): int|false {
    $orderId = $order['order_id'] ?? $order['id'] ?? '?';
    $total = number_format($order['total'] ?? 0, 2, ',', '.');
    $payment = $order['payment_method'] ?? $order['metodo_pagamento'] ?? 'N/A';
    $partnerName = $order['partner_name'] ?? $order['parceiro_nome'] ?? 'Loja';

    $itemsHtml = '';
    foreach (($order['items'] ?? $order['itens'] ?? []) as $item) {
        $qty = $item['quantity'] ?? $item['quantidade'] ?? 1;
        $name = htmlspecialchars($item['name'] ?? $item['nome'] ?? 'Produto', ENT_QUOTES, 'UTF-8');
        $price = number_format(($item['price'] ?? $item['preco'] ?? 0) * $qty, 2, ',', '.');
        $itemsHtml .= "<div class='item-row'><span>{$qty}x {$name}</span><span>R$ {$price}</span></div>";
    }

    $content = <<<HTML
<h2 style="margin:0 0 8px;color:#1e293b;">Pedido confirmado!</h2>
<p style="color:#64748b;margin:0 0 20px;">Ola {$customerName}, seu pedido #{$orderId} foi recebido.</p>

<div class="order-box">
  <p style="margin:0 0 8px;font-weight:600;color:#334155;">{$partnerName}</p>
  {$itemsHtml}
  <div class="total-row">Total: R$ {$total}</div>
  <p style="margin:8px 0 0;color:#64748b;font-size:13px;">Pagamento: {$payment}</p>
</div>

<p style="text-align:center;margin:24px 0;">
  <a href="https://superbora.com.br" class="btn">Acompanhar Pedido</a>
</p>
HTML;

    $html = _emailLayout($content, "Pedido #{$orderId} confirmado - R$ {$total}");
    return queueMarketEmail($email, "Pedido #{$orderId} confirmado - SuperBora", $html, $db, $customerId, 'order_confirmed', $customerName, 1);
}

// ═══════════════════════════════════════════
// LOGGING
// ═══════════════════════════════════════════

/**
 * Log email na tabela om_email_logs
 */
function _logEmail(?PDO $db, ?int $customerId, string $to, string $template, string $subject, string $status, array $meta = [], ?int $queueId = null, int $attempts = 1): void {
    if (!$db) return;
    try {
        $stmt = $db->prepare("
            INSERT INTO om_email_logs (customer_id, email_to, template, subject, status, metadata, queue_id, attempts, send_method, sent_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($status === 'sent' ? 'NOW()' : 'NULL') . ", NOW())
        ");
        $stmt->execute([
            $customerId,
            $to,
            $template,
            $subject,
            $status,
            json_encode($meta) ?: '{}',
            $queueId,
            $attempts,
            $meta['method'] ?? 'direct',
        ]);
    } catch (Exception $e) {
        error_log("[Email] Log failed: " . $e->getMessage());
    }
}

// ═══════════════════════════════════════════
// EMAIL TEMPLATES
// ═══════════════════════════════════════════

function _emailLayout(string $content, string $preheader = ''): string {
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>SuperBora</title>
<!--[if mso]>
<noscript>
<xml>
<o:OfficeDocumentSettings>
<o:PixelsPerInch>96</o:PixelsPerInch>
</o:OfficeDocumentSettings>
</xml>
</noscript>
<![endif]-->
</head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;background:#f1f5f9;color:#1e293b;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
<span style="display:none!important;visibility:hidden;opacity:0;color:transparent;height:0;width:0;max-height:0;max-width:0;overflow:hidden;mso-hide:all;">{$preheader}</span>
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#f1f5f9;">
<tr><td align="center" style="padding:20px 10px;">
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;">
  <!-- Header -->
  <tr>
    <td style="background:linear-gradient(135deg,#16a34a,#15803d);padding:28px 24px;text-align:center;">
      <h1 style="margin:0;color:#fff;font-size:28px;font-weight:700;letter-spacing:-0.5px;">SuperBora</h1>
      <p style="margin:4px 0 0;color:rgba(255,255,255,0.85);font-size:14px;">Mercado na sua porta</p>
    </td>
  </tr>
  <!-- Body -->
  <tr>
    <td style="padding:28px 24px;">
      {$content}
    </td>
  </tr>
  <!-- Footer -->
  <tr>
    <td style="padding:20px 24px;text-align:center;color:#94a3b8;font-size:12px;border-top:1px solid #e2e8f0;">
      <p style="margin:0 0 4px;">SuperBora &mdash; Mercado na sua porta</p>
      <p style="margin:0 0 4px;">Este email foi enviado automaticamente. Nao responda.</p>
      <p style="margin:8px 0 0;color:#cbd5e1;font-size:11px;">&copy; {$year} SuperBora. Todos os direitos reservados.</p>
    </td>
  </tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
}

/**
 * Email de pedido confirmado (envio direto)
 */
function sendOrderConfirmEmail(PDO $db, int $customerId, string $email, string $customerName, array $order): bool {
    $orderId = $order['order_id'] ?? $order['id'] ?? '?';
    $total = number_format($order['total'] ?? 0, 2, ',', '.');
    $payment = $order['payment_method'] ?? $order['metodo_pagamento'] ?? 'N/A';
    $partnerName = $order['partner_name'] ?? $order['parceiro_nome'] ?? 'Loja';

    $itemsHtml = '';
    foreach (($order['items'] ?? $order['itens'] ?? []) as $item) {
        $qty = $item['quantity'] ?? $item['quantidade'] ?? 1;
        $name = htmlspecialchars($item['name'] ?? $item['nome'] ?? 'Produto', ENT_QUOTES, 'UTF-8');
        $price = number_format(($item['price'] ?? $item['preco'] ?? 0) * $qty, 2, ',', '.');
        $itemsHtml .= "<div style='display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9;'><span>{$qty}x {$name}</span><span>R$ {$price}</span></div>";
    }

    $content = <<<HTML
<h2 style="margin:0 0 8px;color:#1e293b;">Pedido confirmado!</h2>
<p style="color:#64748b;margin:0 0 20px;">Ola {$customerName}, seu pedido #{$orderId} foi recebido.</p>

<div style="background:#f8fafc;border-radius:12px;padding:16px;margin:16px 0;border:1px solid #e2e8f0;">
  <p style="margin:0 0 8px;font-weight:600;color:#334155;">{$partnerName}</p>
  {$itemsHtml}
  <div style="font-size:20px;font-weight:700;color:#16a34a;padding-top:12px;">Total: R$ {$total}</div>
  <p style="margin:8px 0 0;color:#64748b;font-size:13px;">Pagamento: {$payment}</p>
</div>

<p style="text-align:center;margin:24px 0;">
  <a href="https://superbora.com.br" style="display:inline-block;background:#16a34a;color:#fff;padding:14px 32px;border-radius:12px;text-decoration:none;font-weight:600;font-size:16px;">Acompanhar Pedido</a>
</p>
HTML;

    $html = _emailLayout($content, "Pedido #{$orderId} confirmado - R$ {$total}");
    return sendEmail($email, "Pedido #{$orderId} confirmado - SuperBora", $html, $db, $customerId, 'order_confirmed');
}

/**
 * Email de status do pedido atualizado
 */
function sendOrderStatusEmail(PDO $db, int $customerId, string $email, string $customerName, int $orderId, string $status, string $statusLabel): bool {
    $statusColors = [
        'preparando' => '#f59e0b',
        'pronto' => '#3b82f6',
        'em_entrega' => '#8b5cf6',
        'entregue' => '#16a34a',
        'cancelado' => '#ef4444',
    ];
    $color = $statusColors[$status] ?? '#64748b';

    $content = <<<HTML
<h2 style="margin:0 0 8px;color:#1e293b;">Atualizacao do pedido #{$orderId}</h2>
<p style="color:#64748b;margin:0 0 20px;">Ola {$customerName},</p>

<div style="text-align:center;margin:24px 0;">
  <span style="display:inline-block;padding:6px 16px;border-radius:20px;font-weight:600;font-size:14px;background:{$color}20;color:{$color};">{$statusLabel}</span>
</div>

<p style="text-align:center;margin:24px 0;">
  <a href="https://superbora.com.br" style="display:inline-block;background:#16a34a;color:#fff;padding:14px 32px;border-radius:12px;text-decoration:none;font-weight:600;font-size:16px;">Ver Detalhes</a>
</p>
HTML;

    $html = _emailLayout($content, "Pedido #{$orderId} - {$statusLabel}");
    return sendEmail($email, "Pedido #{$orderId} - {$statusLabel}", $html, $db, $customerId, 'status_update');
}

/**
 * Email de boas-vindas
 */
function sendWelcomeEmail(PDO $db, int $customerId, string $email, string $name): bool {
    $content = <<<HTML
<h2 style="margin:0 0 8px;color:#1e293b;">Bem-vindo ao SuperBora!</h2>
<p style="color:#64748b;margin:0 0 20px;">Ola {$name}, estamos felizes em ter voce!</p>

<p>Com o SuperBora voce tem:</p>
<ul style="color:#334155;line-height:1.8;">
  <li>Entrega rapida do mercado na sua porta</li>
  <li>Precos competitivos</li>
  <li>Cashback em todas as compras</li>
  <li>Rastreamento em tempo real</li>
</ul>

<p style="text-align:center;margin:24px 0;">
  <a href="https://superbora.com.br" style="display:inline-block;background:#16a34a;color:#fff;padding:14px 32px;border-radius:12px;text-decoration:none;font-weight:600;font-size:16px;">Comecar a Comprar</a>
</p>
HTML;

    $html = _emailLayout($content, "Bem-vindo ao SuperBora, {$name}!");
    return sendEmail($email, "Bem-vindo ao SuperBora!", $html, $db, $customerId, 'welcome');
}

/**
 * Email de recuperacao de senha
 */
function sendPasswordResetEmail(PDO $db, int $customerId, string $email, string $name, string $code): bool {
    $codeEsc = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

    $content = <<<HTML
<h2 style="margin:0 0 8px;color:#1e293b;">Recuperacao de Senha</h2>
<p style="color:#64748b;margin:0 0 20px;">Ola {$name},</p>
<p>Use o codigo abaixo para redefinir sua senha:</p>

<div style="background:#f8fafc;border:2px dashed #16a34a;border-radius:12px;padding:24px;text-align:center;margin:24px 0;">
  <span style="font-size:32px;font-weight:bold;letter-spacing:8px;color:#16a34a;">{$codeEsc}</span>
</div>

<p style="color:#64748b;font-size:13px;">Este codigo e valido por <strong>5 minutos</strong>.</p>
<p style="color:#94a3b8;font-size:12px;">Se voce nao solicitou a recuperacao de senha, ignore este email.</p>
HTML;

    $html = _emailLayout($content, "Codigo de recuperacao: {$codeEsc}");
    return sendEmail($email, "Recuperacao de Senha - SuperBora", $html, $db, $customerId, 'password_reset');
}

// ═══════════════════════════════════════════
// UTILITY
// ═══════════════════════════════════════════

/**
 * Get email queue statistics
 */
function getEmailQueueStats(?PDO $db = null): array {
    try {
        if (!$db) {
            if (!function_exists('getDB')) {
                require_once dirname(__DIR__) . '/config/database.php';
            }
            $db = getDB();
        }

        $stmt = $db->query("
            SELECT
                COUNT(*) FILTER (WHERE status = 'pending') as pending,
                COUNT(*) FILTER (WHERE status = 'sending') as sending,
                COUNT(*) FILTER (WHERE status = 'sent') as sent,
                COUNT(*) FILTER (WHERE status = 'failed') as failed,
                COUNT(*) FILTER (WHERE status = 'pending' AND scheduled_at <= NOW()) as ready_to_send,
                COUNT(*) as total
            FROM om_market_email_queue
            WHERE created_at >= NOW() - INTERVAL '24 hours'
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        error_log("[EmailQueue] Stats query failed: " . $e->getMessage());
        return [];
    }
}
