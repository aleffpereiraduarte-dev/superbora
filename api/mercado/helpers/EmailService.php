<?php
/**
 * EmailService - Professional transactional email service
 *
 * Features:
 * - PHPMailer SMTP with retry logic (3 attempts, exponential backoff)
 * - DB-backed queue for async delivery
 * - Delivery tracking in om_email_logs
 * - Consistent branded HTML templates
 * - Graceful fallback to msmtp if PHPMailer unavailable
 *
 * Usage:
 *   require_once __DIR__ . '/EmailService.php';
 *   $mailer = new EmailService($db);
 *
 *   // Direct send (with retry):
 *   $mailer->sendOrderConfirmation($customerId, $orderId);
 *
 *   // Queue for async send:
 *   $mailer->queueEmail('user@example.com', 'Subject', '<h1>Body</h1>');
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// Auto-load PHPMailer from vendor
$autoload = __DIR__ . '/../../../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

class EmailService {
    private PDO $db;
    private string $fromEmail;
    private string $fromName;
    private string $smtpHost;
    private int $smtpPort;
    private string $smtpUser;
    private string $smtpPass;
    private bool $enabled;
    private bool $phpMailerAvailable;

    private const MAX_RETRIES = 3;
    private const RETRY_BASE_DELAY_MS = 500;
    private const QUEUE_TABLE = 'om_market_email_queue';

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->fromEmail = $_ENV['SUPERBORA_MAIL_FROM'] ?? $_ENV['SMTP_FROM_EMAIL'] ?? 'contato@superbora.com.br';
        $this->fromName = $_ENV['SUPERBORA_MAIL_FROM_NAME'] ?? $_ENV['SMTP_FROM_NAME'] ?? 'SuperBora';
        $this->smtpHost = $_ENV['SUPERBORA_MAIL_HOST'] ?? $_ENV['SMTP_HOST'] ?? '';
        $this->smtpPort = (int)($_ENV['SUPERBORA_MAIL_PORT'] ?? $_ENV['SMTP_PORT'] ?? 465);
        $this->smtpUser = $_ENV['SUPERBORA_MAIL_USERNAME'] ?? $_ENV['SMTP_USER'] ?? '';
        $this->smtpPass = $_ENV['SUPERBORA_MAIL_PASSWORD'] ?? $_ENV['SMTP_PASS'] ?? '';
        $this->enabled = !empty($this->smtpHost);
        $this->phpMailerAvailable = class_exists('PHPMailer\\PHPMailer\\PHPMailer');
    }

    public function isEnabled(): bool {
        return $this->enabled;
    }

    // =========================================================================
    // PUBLIC API - Direct send methods
    // =========================================================================

    /**
     * Send order confirmation email
     */
    public function sendOrderConfirmation(int $customerId, int $orderId): bool {
        $customer = $this->getCustomer($customerId);
        if (!$customer || empty($customer['email'])) return false;

        $order = $this->getOrder($orderId);
        if (!$order) return false;

        $subject = "Pedido #{$orderId} confirmado!";
        $body = $this->renderTemplate('order_confirmation', [
            'customer_name' => $customer['name'] ?? 'Cliente',
            'order_id' => $orderId,
            'partner_name' => $order['partner_name'] ?? 'Loja',
            'total' => 'R$ ' . number_format((float)$order['total'], 2, ',', '.'),
            'payment_method' => $this->getPaymentLabel($order['payment_method'] ?? ''),
            'status' => $this->getStatusLabel($order['status']),
            'created_at' => date('d/m/Y H:i', strtotime($order['created_at'])),
        ]);

        return $this->send($customer['email'], $subject, $body, $customerId, 'order_confirmation');
    }

    /**
     * Send delivery completed email
     */
    public function sendDeliveryComplete(int $customerId, int $orderId): bool {
        $customer = $this->getCustomer($customerId);
        if (!$customer || empty($customer['email'])) return false;

        $order = $this->getOrder($orderId);
        if (!$order) return false;

        $subject = "Pedido #{$orderId} entregue!";
        $body = $this->renderTemplate('delivery_complete', [
            'customer_name' => $customer['name'] ?? 'Cliente',
            'order_id' => $orderId,
            'partner_name' => $order['partner_name'] ?? 'Loja',
            'total' => 'R$ ' . number_format((float)$order['total'], 2, ',', '.'),
        ]);

        return $this->send($customer['email'], $subject, $body, $customerId, 'delivery_complete');
    }

    /**
     * Send order cancellation email
     */
    public function sendOrderCancelled(int $customerId, int $orderId, string $reason = ''): bool {
        $customer = $this->getCustomer($customerId);
        if (!$customer || empty($customer['email'])) return false;

        $order = $this->getOrder($orderId);
        if (!$order) return false;

        $subject = "Pedido #{$orderId} cancelado";
        $body = $this->renderTemplate('order_cancelled', [
            'customer_name' => $customer['name'] ?? 'Cliente',
            'order_id' => $orderId,
            'partner_name' => $order['partner_name'] ?? 'Loja',
            'total' => 'R$ ' . number_format((float)$order['total'], 2, ',', '.'),
            'reason' => $reason ?: 'Nao informado',
        ]);

        return $this->send($customer['email'], $subject, $body, $customerId, 'order_cancelled');
    }

    /**
     * Send welcome email on registration
     */
    public function sendWelcome(int $customerId): bool {
        $customer = $this->getCustomer($customerId);
        if (!$customer || empty($customer['email'])) return false;

        $subject = 'Bem-vindo ao SuperBora!';
        $body = $this->renderTemplate('welcome', [
            'customer_name' => $customer['name'] ?? 'Cliente',
        ]);

        return $this->send($customer['email'], $subject, $body, $customerId, 'welcome');
    }

    /**
     * Send account deletion confirmation
     */
    public function sendAccountDeleted(string $email, string $name): bool {
        if (empty($email)) return false;

        $subject = 'Sua conta foi excluida - SuperBora';
        $body = $this->renderTemplate('account_deleted', [
            'customer_name' => $name ?: 'Cliente',
        ]);

        return $this->send($email, $subject, $body, null, 'account_deleted');
    }

    /**
     * Send generic email with custom HTML body
     */
    public function sendGeneric(string $to, string $subject, string $htmlBody, ?int $customerId = null): bool {
        return $this->send($to, $subject, $htmlBody, $customerId, 'generic');
    }

    // =========================================================================
    // PUBLIC API - Queue methods
    // =========================================================================

    /**
     * Queue an email for async delivery via cron
     *
     * @param string $to Recipient email
     * @param string $subject Subject line
     * @param string $htmlBody Full HTML body
     * @param int|null $customerId Customer ID
     * @param string $template Template name
     * @param int $priority 0=normal, 1=high, -1=low
     * @param string|null $scheduledAt When to send (default: now)
     * @return int|false Queue ID or false
     */
    public function queueEmail(
        string $to,
        string $subject,
        string $htmlBody,
        ?int $customerId = null,
        string $template = 'generic',
        int $priority = 0,
        ?string $scheduledAt = null
    ): int|false {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log("[EmailService] Invalid email for queue: " . substr($to, 0, 100));
            return false;
        }

        try {
            $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody));
            $plainText = html_entity_decode($plainText, ENT_QUOTES, 'UTF-8');
            $plainText = preg_replace('/\n{3,}/', "\n\n", trim($plainText));

            $stmt = $this->db->prepare("
                INSERT INTO " . self::QUEUE_TABLE . "
                    (to_email, subject, body_html, body_text, template, customer_id, priority, status, scheduled_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', COALESCE(?::timestamp, NOW()), NOW(), NOW())
                RETURNING id
            ");
            $stmt->execute([$to, $subject, $htmlBody, $plainText, $template, $customerId, $priority, $scheduledAt]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $queueId = $row ? (int)$row['id'] : false;

            if ($queueId) {
                error_log("[EmailService] Queued #{$queueId} to " . self::maskEmail($to) . ": {$subject}");
            }

            return $queueId;

        } catch (Exception $e) {
            error_log("[EmailService] Queue insert failed: " . $e->getMessage());
            // Fallback: try direct send
            error_log("[EmailService] Falling back to direct send");
            return $this->send($to, $subject, $htmlBody, $customerId, $template) ? -1 : false;
        }
    }

    /**
     * Queue order confirmation for async delivery
     */
    public function queueOrderConfirmation(int $customerId, int $orderId): int|false {
        $customer = $this->getCustomer($customerId);
        if (!$customer || empty($customer['email'])) return false;

        $order = $this->getOrder($orderId);
        if (!$order) return false;

        $subject = "Pedido #{$orderId} confirmado!";
        $body = $this->renderTemplate('order_confirmation', [
            'customer_name' => $customer['name'] ?? 'Cliente',
            'order_id' => $orderId,
            'partner_name' => $order['partner_name'] ?? 'Loja',
            'total' => 'R$ ' . number_format((float)$order['total'], 2, ',', '.'),
            'payment_method' => $this->getPaymentLabel($order['payment_method'] ?? ''),
            'status' => $this->getStatusLabel($order['status']),
            'created_at' => date('d/m/Y H:i', strtotime($order['created_at'])),
        ]);

        return $this->queueEmail($customer['email'], $subject, $body, $customerId, 'order_confirmation', 1);
    }

    // =========================================================================
    // CORE SEND (with retry)
    // =========================================================================

    private function send(string $to, string $subject, string $htmlBody, ?int $customerId = null, string $template = 'generic', ?int $queueId = null): bool {
        if (!$this->enabled) {
            error_log("[EmailService] SMTP not configured, skipping email to " . self::maskEmail($to) . ": {$subject}");
            return false;
        }

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log("[EmailService] Invalid email: " . substr($to, 0, 100));
            return false;
        }

        $lastError = '';
        $success = false;
        $attempt = 0;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                if ($this->phpMailerAvailable) {
                    $this->sendViaPhpMailer($to, $subject, $htmlBody);
                } else {
                    $this->sendViaMsmtp($to, $subject, $htmlBody);
                }

                $success = true;
                if ($attempt > 1) {
                    error_log("[EmailService] Sent on attempt {$attempt} to " . self::maskEmail($to));
                } else {
                    error_log("[EmailService] Sent '{$subject}' to " . self::maskEmail($to));
                }
                break;

            } catch (Exception $e) {
                $lastError = $e->getMessage();
                error_log("[EmailService] Attempt {$attempt}/" . self::MAX_RETRIES . " failed for " . self::maskEmail($to) . ": {$lastError}");

                // Don't retry on permanent failures (invalid recipient, auth error)
                if ($this->isPermanentFailure($lastError)) {
                    error_log("[EmailService] Permanent failure detected, not retrying");
                    break;
                }

                // Exponential backoff between retries
                if ($attempt < self::MAX_RETRIES) {
                    $delayMs = self::RETRY_BASE_DELAY_MS * pow(2, $attempt - 1);
                    usleep($delayMs * 1000);
                }
            }
        }

        // Log result
        $this->logEmail($to, $subject, $template, $success ? 'sent' : 'failed',
            $success ? '' : $lastError, $customerId, $queueId, $attempt);

        return $success;
    }

    /**
     * Send via PHPMailer (preferred)
     */
    private function sendViaPhpMailer(string $to, string $subject, string $htmlBody): void {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = $this->smtpHost;
        $mail->Port = $this->smtpPort;
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 30;

        // Port 25 = local relay (no auth/encryption needed)
        if ($this->smtpPort === 25) {
            $mail->SMTPAuth = false;
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        } else {
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtpUser;
            $mail->Password = $this->smtpPass;
            $mail->SMTPSecure = $this->smtpPort === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            // Self-hosted mail server - skip peer verification when connecting via IP
            $mail->SMTPOptions = [
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]
            ];
        }

        $mail->setFrom($this->fromEmail, $this->fromName);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody));

        $mail->send();
    }

    /**
     * Fallback: send via msmtp subprocess
     */
    private function sendViaMsmtp(string $to, string $subject, string $htmlBody): void {
        $from = $this->fromEmail;
        $fromName = $this->fromName;
        $boundary = bin2hex(random_bytes(16));

        $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody));
        $plainText = html_entity_decode($plainText, ENT_QUOTES, 'UTF-8');

        $subjectEncoded = "=?UTF-8?B?" . base64_encode($subject) . "?=";
        $headers = "From: {$fromName} <{$from}>\r\n";
        $headers .= "Reply-To: {$from}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $headers .= "X-Mailer: SuperBora/2.0\r\n";

        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($plainText)) . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $message = "To: {$to}\r\nSubject: {$subjectEncoded}\r\n{$headers}\r\n{$body}";

        $proc = proc_open(
            ['msmtp', '-a', 'superbora', $to],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (!is_resource($proc)) {
            throw new \RuntimeException('msmtp process failed to start');
        }

        fwrite($pipes[0], $message);
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 30);
        stream_set_timeout($pipes[2], 30);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0) {
            throw new \RuntimeException("msmtp exit_code={$exitCode}: " . trim($stderr));
        }
    }

    /**
     * Detect permanent SMTP failures that should not be retried
     */
    private function isPermanentFailure(string $error): bool {
        $permanentPatterns = [
            'invalid email',
            'mailbox not found',
            'user unknown',
            'relay not permitted',
            'authentication failed',
            'sender rejected',
            '550 ',
            '551 ',
            '552 ',
            '553 ',
            '554 ',
        ];

        $errorLower = strtolower($error);
        foreach ($permanentPatterns as $pattern) {
            if (str_contains($errorLower, $pattern)) {
                return true;
            }
        }
        return false;
    }

    // =========================================================================
    // PROCESS QUEUE (called by cron)
    // =========================================================================

    /**
     * Process pending emails from the queue.
     * Called by cron/process-email-queue.php
     *
     * @param int $batchSize Max emails to process per run
     * @return array Stats: ['processed' => int, 'sent' => int, 'failed' => int]
     */
    public function processQueue(int $batchSize = 50): array {
        $stats = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

        try {
            // Lock and fetch pending emails (priority DESC = high priority first)
            $stmt = $this->db->prepare("
                UPDATE " . self::QUEUE_TABLE . "
                SET status = 'sending', updated_at = NOW()
                WHERE id IN (
                    SELECT id FROM " . self::QUEUE_TABLE . "
                    WHERE status = 'pending'
                      AND scheduled_at <= NOW()
                      AND attempts < max_attempts
                    ORDER BY priority DESC, scheduled_at ASC
                    LIMIT ?
                    FOR UPDATE SKIP LOCKED
                )
                RETURNING id, to_email, to_name, subject, body_html, body_text, template, customer_id, attempts, max_attempts
            ");
            $stmt->execute([$batchSize]);
            $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($emails)) {
                return $stats;
            }

            foreach ($emails as $email) {
                $stats['processed']++;

                $sent = $this->send(
                    $email['to_email'],
                    $email['subject'],
                    $email['body_html'],
                    $email['customer_id'] ? (int)$email['customer_id'] : null,
                    $email['template'] ?? 'generic',
                    (int)$email['id']
                );

                if ($sent) {
                    // Mark as sent
                    $updateStmt = $this->db->prepare("
                        UPDATE " . self::QUEUE_TABLE . "
                        SET status = 'sent', sent_at = NOW(), attempts = attempts + 1, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$email['id']]);
                    $stats['sent']++;
                } else {
                    // Increment attempt count, mark as failed or back to pending for retry
                    $newAttempts = (int)$email['attempts'] + 1;
                    $newStatus = $newAttempts >= (int)$email['max_attempts'] ? 'failed' : 'pending';

                    $updateStmt = $this->db->prepare("
                        UPDATE " . self::QUEUE_TABLE . "
                        SET status = ?, attempts = ?, last_error = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$newStatus, $newAttempts, 'Send failed after retry', $email['id']]);
                    $stats['failed']++;
                }
            }

        } catch (Exception $e) {
            error_log("[EmailService] Queue processing error: " . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Clean up old queue entries (call from cleanup cron)
     * Removes sent emails older than 30 days and failed older than 90 days
     */
    public function cleanupQueue(int $sentDays = 30, int $failedDays = 90): int {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM " . self::QUEUE_TABLE . "
                WHERE (status = 'sent' AND sent_at < NOW() - INTERVAL '1 day' * ?)
                   OR (status = 'failed' AND updated_at < NOW() - INTERVAL '1 day' * ?)
            ");
            $stmt->execute([$sentDays, $failedDays]);
            return $stmt->rowCount();
        } catch (Exception $e) {
            error_log("[EmailService] Queue cleanup failed: " . $e->getMessage());
            return 0;
        }
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private static function maskEmail(string $email): string {
        $parts = explode('@', $email);
        if (count($parts) !== 2) return '***@***';
        $local = $parts[0];
        $domain = $parts[1];
        $masked = strlen($local) <= 2
            ? str_repeat('*', strlen($local))
            : $local[0] . str_repeat('*', strlen($local) - 2) . $local[strlen($local) - 1];
        return $masked . '@' . $domain;
    }

    private function logEmail(string $to, string $subject, string $template, string $status, string $error = '', ?int $customerId = null, ?int $queueId = null, int $attempts = 1): void {
        try {
            $sendMethod = $this->phpMailerAvailable ? 'phpmailer' : 'msmtp';
            if ($queueId) $sendMethod = 'queued_' . $sendMethod;

            $stmt = $this->db->prepare("
                INSERT INTO om_email_logs (customer_id, email_to, template, subject, status, metadata, queue_id, attempts, send_method, sent_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($status === 'sent' ? 'NOW()' : 'NULL') . ", NOW())
            ");
            $stmt->execute([
                $customerId,
                $to,
                $template,
                $subject,
                $status,
                json_encode($error ? ['error' => $error, 'attempts' => $attempts] : ['attempts' => $attempts]),
                $queueId,
                $attempts,
                $sendMethod,
            ]);
        } catch (Exception $e) {
            // Silent - logging failure should not break email flow
            error_log("[EmailService] Log insert failed: " . $e->getMessage());
        }
    }

    private function getCustomer(int $customerId): ?array {
        $stmt = $this->db->prepare("SELECT name, email FROM om_customers WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getOrder(int $orderId): ?array {
        $stmt = $this->db->prepare("
            SELECT o.*, p.name as partner_name
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
            WHERE o.order_id = ?
        ");
        $stmt->execute([$orderId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getPaymentLabel(string $method): string {
        return match($method) {
            'pix' => 'PIX',
            'stripe', 'cartao' => 'Cartao de Credito',
            'cash', 'dinheiro' => 'Dinheiro na entrega',
            'card_on_delivery' => 'Cartao na entrega',
            default => $method ?: 'Nao informado',
        };
    }

    private function getStatusLabel(string $status): string {
        return match($status) {
            'pendente', 'pending' => 'Pendente',
            'confirmado', 'confirmed' => 'Confirmado',
            'preparando', 'preparing' => 'Em preparo',
            'pronto', 'ready' => 'Pronto para retirada',
            'em_entrega', 'delivering' => 'Saiu para entrega',
            'entregue', 'delivered' => 'Entregue',
            'cancelado', 'cancelled' => 'Cancelado',
            default => $status,
        };
    }

    /**
     * Render HTML email template with consistent branding
     */
    private function renderTemplate(string $template, array $vars): string {
        // Escape ALL variables to prevent HTML injection in email templates
        $vars = array_map(function($v) {
            return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        }, $vars);
        $name = $vars['customer_name'] ?? 'Cliente';
        $year = date('Y');

        $wrap = function(string $content) use ($year): string {
            return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="X-UA-Compatible" content="IE=edge"><!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]--></head>
            <body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;background:#f1f5f9;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#f1f5f9;">
            <tr><td align="center" style="padding:20px 10px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" style="max-width:600px;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
            <tr><td style="background:linear-gradient(135deg,#00A868,#00C853);padding:24px;text-align:center;">
                <h1 style="color:#fff;margin:0;font-size:24px;font-weight:700;">SuperBora</h1>
                <p style="margin:4px 0 0;color:rgba(255,255,255,0.85);font-size:13px;">Mercado na sua porta</p>
            </td></tr>
            <tr><td style="padding:24px;">' . $content . '</td></tr>
            <tr><td style="padding:16px 24px;text-align:center;color:#94a3b8;font-size:12px;border-top:1px solid #e2e8f0;">
                <p style="margin:0 0 4px;">SuperBora &mdash; Seu mercado online</p>
                <p style="margin:0 0 4px;">Este email foi enviado automaticamente. Nao responda.</p>
                <p style="margin:8px 0 0;color:#cbd5e1;font-size:11px;">&copy; ' . $year . ' SuperBora. Todos os direitos reservados.</p>
            </td></tr>
            </table>
            </td></tr></table>
            </body></html>';
        };

        switch ($template) {
            case 'order_confirmation':
                return $wrap("
                    <h2 style='color:#333;margin:0 0 16px;'>Pedido confirmado!</h2>
                    <p>Ola, <strong>{$name}</strong>!</p>
                    <p>Seu pedido <strong>#{$vars['order_id']}</strong> na <strong>{$vars['partner_name']}</strong> foi confirmado.</p>
                    <table role='presentation' cellspacing='0' cellpadding='0' border='0' width='100%' style='background:#f8f9fa;border-radius:8px;margin:16px 0;'>
                    <tr><td style='padding:16px;'>
                        <p style='margin:4px 0;'><strong>Valor total:</strong> {$vars['total']}</p>
                        <p style='margin:4px 0;'><strong>Pagamento:</strong> {$vars['payment_method']}</p>
                        <p style='margin:4px 0;'><strong>Status:</strong> {$vars['status']}</p>
                        <p style='margin:4px 0;'><strong>Data:</strong> {$vars['created_at']}</p>
                    </td></tr></table>
                    <p>Acompanhe o status do seu pedido pelo app SuperBora.</p>
                ");

            case 'delivery_complete':
                return $wrap("
                    <h2 style='color:#00A868;margin:0 0 16px;'>Pedido entregue!</h2>
                    <p>Ola, <strong>{$name}</strong>!</p>
                    <p>Seu pedido <strong>#{$vars['order_id']}</strong> da <strong>{$vars['partner_name']}</strong> foi entregue com sucesso.</p>
                    <table role='presentation' cellspacing='0' cellpadding='0' border='0' width='100%' style='background:#f0fdf4;border-radius:8px;margin:16px 0;border-left:4px solid #00A868;'>
                    <tr><td style='padding:16px;'>
                        <p style='margin:4px 0;'><strong>Total:</strong> {$vars['total']}</p>
                    </td></tr></table>
                    <p>Obrigado por comprar com a SuperBora! Avalie seu pedido no app.</p>
                ");

            case 'order_cancelled':
                return $wrap("
                    <h2 style='color:#dc2626;margin:0 0 16px;'>Pedido cancelado</h2>
                    <p>Ola, <strong>{$name}</strong>.</p>
                    <p>Seu pedido <strong>#{$vars['order_id']}</strong> da <strong>{$vars['partner_name']}</strong> foi cancelado.</p>
                    <table role='presentation' cellspacing='0' cellpadding='0' border='0' width='100%' style='background:#fef2f2;border-radius:8px;margin:16px 0;border-left:4px solid #dc2626;'>
                    <tr><td style='padding:16px;'>
                        <p style='margin:4px 0;'><strong>Valor:</strong> {$vars['total']}</p>
                        <p style='margin:4px 0;'><strong>Motivo:</strong> {$vars['reason']}</p>
                    </td></tr></table>
                    <p>Se o pagamento foi via PIX ou cartao, o estorno sera processado automaticamente.</p>
                ");

            case 'welcome':
                return $wrap("
                    <h2 style='color:#00A868;margin:0 0 16px;'>Bem-vindo ao SuperBora!</h2>
                    <p>Ola, <strong>{$name}</strong>!</p>
                    <p>Sua conta foi criada com sucesso. Agora voce pode:</p>
                    <ul style='color:#555;line-height:1.8;'>
                        <li>Fazer compras no mercado mais perto de voce</li>
                        <li>Acompanhar entregas em tempo real</li>
                        <li>Acumular cashback em cada pedido</li>
                        <li>Usar cupons exclusivos</li>
                    </ul>
                    <p>Abra o app e comece suas compras!</p>
                ");

            case 'account_deleted':
                return $wrap("
                    <h2 style='color:#333;margin:0 0 16px;'>Conta excluida</h2>
                    <p>Ola, <strong>{$name}</strong>.</p>
                    <p>Sua conta no SuperBora foi excluida com sucesso conforme solicitado.</p>
                    <p>Seus dados pessoais foram anonimizados de acordo com a LGPD.</p>
                    <p style='color:#888;font-size:13px;margin-top:24px;'>Se voce nao solicitou a exclusao, entre em contato conosco imediatamente.</p>
                ");

            default:
                return $wrap("<p>{$name}, voce tem uma atualizacao do SuperBora.</p>");
        }
    }
}
