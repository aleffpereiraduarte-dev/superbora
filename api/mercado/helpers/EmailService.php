<?php
/**
 * EmailService - Transactional email service using PHPMailer
 *
 * Sends order confirmations, delivery updates, cancellations, and welcome emails.
 *
 * Usage:
 *   require_once __DIR__ . '/EmailService.php';
 *   $mailer = new EmailService($db);
 *   $mailer->sendOrderConfirmation($customerId, $orderId);
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

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->fromEmail = $_ENV['SUPERBORA_MAIL_FROM'] ?? $_ENV['SMTP_FROM_EMAIL'] ?? 'contato@superbora.com.br';
        $this->fromName = $_ENV['SUPERBORA_MAIL_FROM_NAME'] ?? $_ENV['SMTP_FROM_NAME'] ?? 'SuperBora';
        $this->smtpHost = $_ENV['SUPERBORA_MAIL_HOST'] ?? $_ENV['SMTP_HOST'] ?? '';
        $this->smtpPort = (int)($_ENV['SUPERBORA_MAIL_PORT'] ?? $_ENV['SMTP_PORT'] ?? 465);
        $this->smtpUser = $_ENV['SUPERBORA_MAIL_USERNAME'] ?? $_ENV['SMTP_USER'] ?? '';
        $this->smtpPass = $_ENV['SUPERBORA_MAIL_PASSWORD'] ?? $_ENV['SMTP_PASS'] ?? '';
        $this->enabled = !empty($this->smtpHost) && !empty($this->smtpUser);
    }

    public function isEnabled(): bool {
        return $this->enabled;
    }

    /**
     * Send order confirmation email
     */
    public function sendOrderConfirmation(int $customerId, int $orderId): bool {
        $customer = $this->getCustomer($customerId);
        if (!$customer || empty($customer['email'])) return false;

        $order = $this->getOrder($orderId);
        if (!$order) return false;

        $partnerName = $order['partner_name'] ?? 'Loja';
        $total = 'R$ ' . number_format((float)$order['total'], 2, ',', '.');
        $paymentLabel = $this->getPaymentLabel($order['payment_method'] ?? '');

        $subject = "Pedido #{$orderId} confirmado!";
        $body = $this->renderTemplate('order_confirmation', [
            'customer_name' => $customer['name'] ?? 'Cliente',
            'order_id' => $orderId,
            'partner_name' => $partnerName,
            'total' => $total,
            'payment_method' => $paymentLabel,
            'status' => $this->getStatusLabel($order['status']),
            'created_at' => date('d/m/Y H:i', strtotime($order['created_at'])),
        ]);

        return $this->send($customer['email'], $subject, $body);
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

        return $this->send($customer['email'], $subject, $body);
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

        return $this->send($customer['email'], $subject, $body);
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

        return $this->send($customer['email'], $subject, $body);
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

        return $this->send($email, $subject, $body);
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

    private function send(string $to, string $subject, string $htmlBody): bool {
        if (!$this->enabled) {
            error_log("[EmailService] SMTP not configured, skipping email to " . self::maskEmail($to) . ": {$subject}");
            return false;
        }

        try {
            $mail = new PHPMailer(true);

            $mail->isSMTP();
            $mail->Host = $this->smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtpUser;
            $mail->Password = $this->smtpPass;
            $mail->SMTPSecure = $this->smtpPort === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $this->smtpPort;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom($this->fromEmail, $this->fromName);
            $mail->addAddress($to);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody));

            $mail->send();
            error_log("[EmailService] Sent '{$subject}' to " . self::maskEmail($to));
            $this->logEmail($to, $subject, 'sent');
            return true;

        } catch (PHPMailerException $e) {
            error_log("[EmailService] Failed to send to " . self::maskEmail($to) . ": " . $e->getMessage());
            $this->logEmail($to, $subject, 'failed', $e->getMessage());
            return false;
        }
    }

    private function logEmail(string $to, string $subject, string $status, string $error = ''): void {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO om_email_logs (email_to, template, subject, status, metadata, sent_at, created_at)
                VALUES (?, 'transactional', ?, ?, ?, " . ($status === 'sent' ? 'NOW()' : 'NULL') . ", NOW())
            ");
            $stmt->execute([$to, $subject, $status, json_encode($error ? ['error' => $error] : [])]);
        } catch (Exception $e) {
            // Silent — logging failure should not break email flow
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
     * Render HTML email template
     */
    private function renderTemplate(string $template, array $vars): string {
        // Escape ALL variables to prevent HTML injection in email templates
        $vars = array_map(function($v) {
            return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        }, $vars);
        $name = $vars['customer_name'] ?? 'Cliente';

        $header = '
        <div style="background: linear-gradient(135deg, #00A868, #00C853); padding: 24px; text-align: center;">
            <h1 style="color: #fff; margin: 0; font-size: 24px; font-weight: 700;">SuperBora</h1>
        </div>';

        $footer = '
        <div style="padding: 16px 24px; text-align: center; color: #888; font-size: 12px; border-top: 1px solid #eee;">
            <p>SuperBora &mdash; Seu mercado online</p>
            <p>Este email foi enviado automaticamente. Nao responda.</p>
        </div>';

        $wrap = function(string $content) use ($header, $footer): string {
            return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
            <body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;background:#f5f5f5;">
            <div style="max-width:600px;margin:20px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
            ' . $header . '<div style="padding:24px;">' . $content . '</div>' . $footer . '
            </div></body></html>';
        };

        switch ($template) {
            case 'order_confirmation':
                return $wrap("
                    <h2 style='color:#333;margin:0 0 16px;'>Pedido confirmado!</h2>
                    <p>Ola, <strong>{$name}</strong>!</p>
                    <p>Seu pedido <strong>#{$vars['order_id']}</strong> na <strong>{$vars['partner_name']}</strong> foi confirmado.</p>
                    <div style='background:#f8f9fa;border-radius:8px;padding:16px;margin:16px 0;'>
                        <p style='margin:4px 0;'><strong>Valor total:</strong> {$vars['total']}</p>
                        <p style='margin:4px 0;'><strong>Pagamento:</strong> {$vars['payment_method']}</p>
                        <p style='margin:4px 0;'><strong>Status:</strong> {$vars['status']}</p>
                        <p style='margin:4px 0;'><strong>Data:</strong> {$vars['created_at']}</p>
                    </div>
                    <p>Acompanhe o status do seu pedido pelo app SuperBora.</p>
                ");

            case 'delivery_complete':
                return $wrap("
                    <h2 style='color:#00A868;margin:0 0 16px;'>Pedido entregue!</h2>
                    <p>Ola, <strong>{$name}</strong>!</p>
                    <p>Seu pedido <strong>#{$vars['order_id']}</strong> da <strong>{$vars['partner_name']}</strong> foi entregue com sucesso.</p>
                    <div style='background:#f0fdf4;border-radius:8px;padding:16px;margin:16px 0;border-left:4px solid #00A868;'>
                        <p style='margin:4px 0;'><strong>Total:</strong> {$vars['total']}</p>
                    </div>
                    <p>Obrigado por comprar com a SuperBora! Avalie seu pedido no app.</p>
                ");

            case 'order_cancelled':
                return $wrap("
                    <h2 style='color:#dc2626;margin:0 0 16px;'>Pedido cancelado</h2>
                    <p>Ola, <strong>{$name}</strong>.</p>
                    <p>Seu pedido <strong>#{$vars['order_id']}</strong> da <strong>{$vars['partner_name']}</strong> foi cancelado.</p>
                    <div style='background:#fef2f2;border-radius:8px;padding:16px;margin:16px 0;border-left:4px solid #dc2626;'>
                        <p style='margin:4px 0;'><strong>Valor:</strong> {$vars['total']}</p>
                        <p style='margin:4px 0;'><strong>Motivo:</strong> {$vars['reason']}</p>
                    </div>
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
