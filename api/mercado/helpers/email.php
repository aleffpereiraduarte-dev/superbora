<?php
/**
 * Email Transacional Helper
 * Envia emails via msmtp (SMTP Hostinger) com templates HTML
 *
 * Uso: require_once e chamar sendEmail() ou sendOrderEmail()
 */

/**
 * Envia email generico
 * @param string $to Destinatario
 * @param string $subject Assunto
 * @param string $htmlBody Corpo HTML
 * @param PDO|null $db Conexao para logging
 * @param int|null $customerId Para logging
 * @param string $template Nome do template para logging
 * @return bool
 */
function sendEmail(string $to, string $subject, string $htmlBody, ?PDO $db = null, ?int $customerId = null, string $template = 'generic'): bool {
    $from = 'contato@superbora.com.br';
    $fromName = 'SuperBora';

    $boundary = md5(time());
    $headers = "From: {$fromName} <{$from}>\r\n";
    $headers .= "Reply-To: {$from}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "X-Mailer: SuperBora/1.0\r\n";

    // Plain text fallback
    $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $htmlBody));
    $plainText = html_entity_decode($plainText, ENT_QUOTES, 'UTF-8');

    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $body .= $plainText . "\r\n\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $body .= $htmlBody . "\r\n\r\n";
    $body .= "--{$boundary}--\r\n";

    $message = "To: {$to}\r\nSubject: {$subject}\r\n{$headers}\r\n{$body}";

    // Send via msmtp
    $proc = proc_open(
        ['msmtp', '-a', 'superbora', $to],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    if (!is_resource($proc)) {
        error_log("[Email] Failed to open msmtp process");
        _logEmail($db, $customerId, $to, $template, $subject, 'failed', ['error' => 'msmtp process failed']);
        return false;
    }

    fwrite($pipes[0], $message);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    $success = $exitCode === 0;

    if (!$success) {
        error_log("[Email] msmtp failed (code {$exitCode}): {$stderr}");
    }

    _logEmail($db, $customerId, $to, $template, $subject, $success ? 'sent' : 'failed',
        $success ? [] : ['error' => $stderr, 'exit_code' => $exitCode]);

    return $success;
}

/**
 * Log email na tabela om_email_logs
 */
function _logEmail(?PDO $db, ?int $customerId, string $to, string $template, string $subject, string $status, array $meta = []): void {
    if (!$db) return;
    try {
        $stmt = $db->prepare("
            INSERT INTO om_email_logs (customer_id, email_to, template, subject, status, metadata, sent_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, " . ($status === 'sent' ? 'NOW()' : 'NULL') . ", NOW())
        ");
        $stmt->execute([
            $customerId,
            $to,
            $template,
            $subject,
            $status,
            json_encode($meta) ?: '{}',
        ]);
    } catch (Exception $e) {
        error_log("[Email] Log failed: " . $e->getMessage());
    }
}

// ═══════════════════════════════════════════
// EMAIL TEMPLATES
// ═══════════════════════════════════════════

function _emailLayout(string $content, string $preheader = ''): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SuperBora</title>
<style>
  body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; color: #1e293b; }
  .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; margin-top: 20px; margin-bottom: 20px; }
  .header { background: linear-gradient(135deg, #16a34a, #15803d); padding: 28px 24px; text-align: center; }
  .header h1 { margin: 0; color: #fff; font-size: 28px; font-weight: 700; letter-spacing: -0.5px; }
  .header p { margin: 4px 0 0; color: rgba(255,255,255,0.85); font-size: 14px; }
  .body { padding: 28px 24px; }
  .footer { padding: 20px 24px; text-align: center; color: #94a3b8; font-size: 12px; border-top: 1px solid #e2e8f0; }
  .btn { display: inline-block; background: #16a34a; color: #fff !important; padding: 14px 32px; border-radius: 12px; text-decoration: none; font-weight: 600; font-size: 16px; }
  .order-box { background: #f8fafc; border-radius: 12px; padding: 16px; margin: 16px 0; border: 1px solid #e2e8f0; }
  .item-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
  .total-row { font-size: 20px; font-weight: 700; color: #16a34a; padding-top: 12px; }
  .status-badge { display: inline-block; padding: 6px 16px; border-radius: 20px; font-weight: 600; font-size: 14px; }
  .preheader { display: none !important; visibility: hidden; opacity: 0; max-height: 0; overflow: hidden; }
</style>
</head>
<body>
<span class="preheader">{$preheader}</span>
<div class="container">
  <div class="header">
    <h1>SuperBora</h1>
    <p>Mercado na sua porta</p>
  </div>
  <div class="body">
    {$content}
  </div>
  <div class="footer">
    <p>SuperBora &mdash; Mercado na sua porta</p>
    <p>Este email foi enviado automaticamente. Nao responda.</p>
  </div>
</div>
</body>
</html>
HTML;
}

/**
 * Email de pedido confirmado
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
  <span class="status-badge" style="background:{$color}20;color:{$color};">{$statusLabel}</span>
</div>

<p style="text-align:center;margin:24px 0;">
  <a href="https://superbora.com.br" class="btn">Ver Detalhes</a>
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
  <a href="https://superbora.com.br" class="btn">Comecar a Comprar</a>
</p>
HTML;

    $html = _emailLayout($content, "Bem-vindo ao SuperBora, {$name}!");
    return sendEmail($email, "Bem-vindo ao SuperBora!", $html, $db, $customerId, 'welcome');
}
