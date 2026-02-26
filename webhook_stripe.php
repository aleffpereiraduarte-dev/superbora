<?php
/**
 * WEBHOOK STRIPE - OneMundo
 * Recebe notificações de pagamentos do Stripe
 *
 * Eventos tratados:
 * - payment_intent.succeeded (pagamento aprovado)
 * - payment_intent.payment_failed (pagamento falhou)
 * - charge.refunded (reembolso)
 * - charge.dispute.created (disputa/chargeback)
 */

// Headers
header('Content-Type: application/json');

// Carregar variáveis de ambiente
$envFile = __DIR__ . '/.env.stripe';
$WEBHOOK_SECRET = '';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, 'STRIPE_WEBHOOK_SECRET=') === 0) {
            $WEBHOOK_SECRET = trim(str_replace('STRIPE_WEBHOOK_SECRET=', '', $line));
        }
    }
}

// Receber payload
$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Log de debug
$logFile = __DIR__ . '/logs/stripe_webhook.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

function logWebhook($message, $data = null) {
    global $logFile;
    $log = date('Y-m-d H:i:s') . ' - ' . $message;
    if ($data) {
        $log .= ' - ' . json_encode($data);
    }
    file_put_contents($logFile, $log . PHP_EOL, FILE_APPEND);
}

// Função para enviar alertas por email
function sendAlertEmail($subject, $body, $priority = 'normal') {
    $adminEmail = 'admin@onemundo.store'; // Ajustar conforme necessário

    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=utf-8',
        'From: OneMundo Alertas <noreply@onemundo.store>',
        'Reply-To: noreply@onemundo.store',
        'X-Mailer: PHP/' . phpversion()
    ];

    if ($priority === 'high') {
        $headers[] = 'X-Priority: 1 (Highest)';
        $headers[] = 'X-MSMail-Priority: High';
        $headers[] = 'Importance: High';
    }

    $htmlBody = "
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #1a1a2e; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background: #f9f9f9; }
            .alert-high { border-left: 4px solid #e74c3c; }
            .alert-normal { border-left: 4px solid #3498db; }
            .footer { padding: 10px; text-align: center; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>🔔 OneMundo - Alerta Stripe</h2>
            </div>
            <div class='content " . ($priority === 'high' ? 'alert-high' : 'alert-normal') . "'>
                {$body}
            </div>
            <div class='footer'>
                Este é um email automático do sistema de pagamentos OneMundo.<br>
                " . date('d/m/Y H:i:s') . "
            </div>
        </div>
    </body>
    </html>";

    $result = mail($adminEmail, $subject, $htmlBody, implode("\r\n", $headers));
    logWebhook('Email enviado: ' . ($result ? 'OK' : 'FALHOU'), ['subject' => $subject]);
    return $result;
}

// Verificar assinatura (se webhook secret configurado)
if (!empty($WEBHOOK_SECRET) && !empty($sigHeader)) {
    $elements = explode(',', $sigHeader);
    $timestamp = null;
    $signature = null;

    foreach ($elements as $element) {
        $parts = explode('=', $element, 2);
        if ($parts[0] === 't') {
            $timestamp = $parts[1];
        } elseif ($parts[0] === 'v1') {
            $signature = $parts[1];
        }
    }

    if ($timestamp && $signature) {
        $signedPayload = $timestamp . '.' . $payload;
        $expectedSignature = hash_hmac('sha256', $signedPayload, $WEBHOOK_SECRET);

        if (!hash_equals($expectedSignature, $signature)) {
            logWebhook('ERRO: Assinatura inválida');
            http_response_code(400);
            echo json_encode(['error' => 'Assinatura inválida']);
            exit;
        }

        // Verificar timestamp (tolerância de 5 minutos)
        if (abs(time() - intval($timestamp)) > 300) {
            logWebhook('ERRO: Timestamp expirado');
            http_response_code(400);
            echo json_encode(['error' => 'Timestamp expirado']);
            exit;
        }
    }
}

// Decodificar evento
$event = json_decode($payload, true);

if (!$event || !isset($event['type'])) {
    logWebhook('ERRO: Payload inválido');
    http_response_code(400);
    echo json_encode(['error' => 'Payload inválido']);
    exit;
}

logWebhook('Evento recebido: ' . $event['type'], ['id' => $event['id'] ?? null]);

// Conexão com banco de dados (ajustar conforme necessário)
function getDB() {
    static $db = null;
    if ($db === null) {
        $configFile = __DIR__ . '/includes/om_bootstrap.php';
        if (file_exists($configFile)) {
            require_once $configFile;
            $db = om_db();
        }
    }
    return $db;
}

// Criar tabela de log se não existir
function ensureLogTable($db) {
    if (!$db) return;
    $db->query("
        CREATE TABLE IF NOT EXISTS stripe_webhook_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id VARCHAR(100) UNIQUE,
            event_type VARCHAR(100),
            payment_intent_id VARCHAR(100),
            amount INT,
            currency VARCHAR(10),
            status VARCHAR(50),
            customer_email VARCHAR(255),
            metadata JSON,
            processed TINYINT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event_type (event_type),
            INDEX idx_payment_intent (payment_intent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// Processar eventos
try {
    $db = getDB();
    if ($db) {
        ensureLogTable($db);
    }

    switch ($event['type']) {

        // ══════════════════════════════════════════════════════════════
        // PAGAMENTO APROVADO
        // ══════════════════════════════════════════════════════════════
        case 'payment_intent.succeeded':
            $paymentIntent = $event['data']['object'];
            $paymentIntentId = $paymentIntent['id'];
            $amount = $paymentIntent['amount'];
            $currency = $paymentIntent['currency'];
            $customerEmail = $paymentIntent['receipt_email'] ?? '';
            $metadata = $paymentIntent['metadata'] ?? [];

            logWebhook('Pagamento aprovado', [
                'id' => $paymentIntentId,
                'amount' => $amount,
                'currency' => $currency
            ]);

            if ($db) {
                // Registrar no log
                $stmt = $db->prepare("
                    INSERT INTO stripe_webhook_log
                    (event_id, event_type, payment_intent_id, amount, currency, status, customer_email, metadata, processed)
                    VALUES (?, ?, ?, ?, ?, 'succeeded', ?, ?, 1)
                    ON DUPLICATE KEY UPDATE processed = 1
                ");
                $metadataJson = json_encode($metadata);
                $stmt->bind_param("sssssss",
                    $event['id'],
                    $event['type'],
                    $paymentIntentId,
                    $amount,
                    $currency,
                    $customerEmail,
                    $metadataJson
                );
                $stmt->execute();

                // Atualizar pedido se tiver order_id no metadata
                if (!empty($metadata['order_id'])) {
                    $orderId = intval($metadata['order_id']);
                    $updateStmt = $db->prepare("
                        UPDATE `order` SET
                            payment_intent_id = ?,
                            order_status_id = 2,
                            date_modified = NOW()
                        WHERE order_id = ?
                    ");
                    $updateStmt->bind_param("si", $paymentIntentId, $orderId);
                    $updateStmt->execute();
                    logWebhook('Pedido atualizado', ['order_id' => $orderId]);
                }
            }

            echo json_encode(['success' => true, 'message' => 'Pagamento processado']);
            break;

        // ══════════════════════════════════════════════════════════════
        // PAGAMENTO FALHOU
        // ══════════════════════════════════════════════════════════════
        case 'payment_intent.payment_failed':
            $paymentIntent = $event['data']['object'];
            $paymentIntentId = $paymentIntent['id'];
            $errorMessage = $paymentIntent['last_payment_error']['message'] ?? 'Erro desconhecido';

            logWebhook('Pagamento falhou', [
                'id' => $paymentIntentId,
                'error' => $errorMessage
            ]);

            if ($db) {
                $stmt = $db->prepare("
                    INSERT INTO stripe_webhook_log
                    (event_id, event_type, payment_intent_id, status, metadata, processed)
                    VALUES (?, ?, ?, 'failed', ?, 1)
                    ON DUPLICATE KEY UPDATE processed = 1
                ");
                $errorJson = json_encode(['error' => $errorMessage]);
                $stmt->bind_param("ssss", $event['id'], $event['type'], $paymentIntentId, $errorJson);
                $stmt->execute();
            }

            echo json_encode(['success' => true, 'message' => 'Falha registrada']);
            break;

        // ══════════════════════════════════════════════════════════════
        // REEMBOLSO
        // ══════════════════════════════════════════════════════════════
        case 'charge.refunded':
            $charge = $event['data']['object'];
            $paymentIntentId = $charge['payment_intent'];
            $amountRefunded = $charge['amount_refunded'];

            logWebhook('Reembolso processado', [
                'payment_intent' => $paymentIntentId,
                'amount_refunded' => $amountRefunded
            ]);

            if ($db) {
                $stmt = $db->prepare("
                    INSERT INTO stripe_webhook_log
                    (event_id, event_type, payment_intent_id, amount, status, processed)
                    VALUES (?, ?, ?, ?, 'refunded', 1)
                    ON DUPLICATE KEY UPDATE processed = 1
                ");
                $stmt->bind_param("sssi", $event['id'], $event['type'], $paymentIntentId, $amountRefunded);
                $stmt->execute();

                // Atualizar status do pedido
                $updateStmt = $db->prepare("
                    UPDATE `order` SET
                        order_status_id = 11,
                        date_modified = NOW()
                    WHERE payment_intent_id = ?
                ");
                $updateStmt->bind_param("s", $paymentIntentId);
                $updateStmt->execute();
            }

            // Notificar reembolso
            $amountFormatted = number_format($amountRefunded / 100, 2, ',', '.');
            $emailBody = "
                <h3>💰 Reembolso Processado</h3>
                <table style='width:100%; border-collapse:collapse;'>
                    <tr><td style='padding:8px; border:1px solid #ddd;'><strong>Payment Intent:</strong></td><td style='padding:8px; border:1px solid #ddd;'>{$paymentIntentId}</td></tr>
                    <tr><td style='padding:8px; border:1px solid #ddd;'><strong>Valor Reembolsado:</strong></td><td style='padding:8px; border:1px solid #ddd;'>R$ {$amountFormatted}</td></tr>
                    <tr><td style='padding:8px; border:1px solid #ddd;'><strong>Data:</strong></td><td style='padding:8px; border:1px solid #ddd;'>" . date('d/m/Y H:i:s') . "</td></tr>
                </table>
            ";
            sendAlertEmail('💰 Reembolso Stripe - R$ ' . $amountFormatted, $emailBody);

            echo json_encode(['success' => true, 'message' => 'Reembolso registrado']);
            break;

        // ══════════════════════════════════════════════════════════════
        // DISPUTA / CHARGEBACK
        // ══════════════════════════════════════════════════════════════
        case 'charge.dispute.created':
            $dispute = $event['data']['object'];
            $chargeId = $dispute['charge'];
            $amount = $dispute['amount'];
            $reason = $dispute['reason'];

            logWebhook('DISPUTA CRIADA!', [
                'charge' => $chargeId,
                'amount' => $amount,
                'reason' => $reason
            ]);

            if ($db) {
                $stmt = $db->prepare("
                    INSERT INTO stripe_webhook_log
                    (event_id, event_type, payment_intent_id, amount, status, metadata, processed)
                    VALUES (?, ?, ?, ?, 'disputed', ?, 1)
                    ON DUPLICATE KEY UPDATE processed = 1
                ");
                $disputeJson = json_encode(['charge' => $chargeId, 'reason' => $reason]);
                $stmt->bind_param("sssis", $event['id'], $event['type'], $chargeId, $amount, $disputeJson);
                $stmt->execute();
            }

            // Enviar alerta URGENTE por email
            $amountFormatted = number_format($amount / 100, 2, ',', '.');
            $reasonTranslated = [
                'fraudulent' => 'Transação fraudulenta',
                'duplicate' => 'Cobrança duplicada',
                'product_not_received' => 'Produto não recebido',
                'product_unacceptable' => 'Produto inaceitável',
                'subscription_canceled' => 'Assinatura cancelada',
                'unrecognized' => 'Não reconhecido',
                'credit_not_processed' => 'Crédito não processado',
                'general' => 'Motivo geral'
            ][$reason] ?? $reason;

            $emailBody = "
                <h3>⚠️ ALERTA: Nova Disputa/Chargeback</h3>
                <p><strong>Uma disputa foi aberta por um cliente!</strong></p>
                <table style='width:100%; border-collapse:collapse;'>
                    <tr><td style='padding:8px; border:1px solid #ddd;'><strong>Charge ID:</strong></td><td style='padding:8px; border:1px solid #ddd;'>{$chargeId}</td></tr>
                    <tr><td style='padding:8px; border:1px solid #ddd;'><strong>Valor:</strong></td><td style='padding:8px; border:1px solid #ddd;'>R$ {$amountFormatted}</td></tr>
                    <tr><td style='padding:8px; border:1px solid #ddd;'><strong>Motivo:</strong></td><td style='padding:8px; border:1px solid #ddd;'>{$reasonTranslated}</td></tr>
                    <tr><td style='padding:8px; border:1px solid #ddd;'><strong>Data:</strong></td><td style='padding:8px; border:1px solid #ddd;'>" . date('d/m/Y H:i:s') . "</td></tr>
                </table>
                <p style='margin-top:20px; padding:15px; background:#fff3cd; border:1px solid #ffc107; border-radius:5px;'>
                    <strong>⏰ AÇÃO NECESSÁRIA:</strong> Você tem um prazo limitado para responder a esta disputa no painel do Stripe.
                    Acesse: <a href='https://dashboard.stripe.com/disputes'>Dashboard Stripe - Disputas</a>
                </p>
            ";

            sendAlertEmail('🚨 URGENTE: Nova Disputa Stripe - R$ ' . $amountFormatted, $emailBody, 'high');

            echo json_encode(['success' => true, 'message' => 'Disputa registrada']);
            break;

        // ══════════════════════════════════════════════════════════════
        // CHECKOUT SESSION COMPLETADO (para Apple Pay / Google Pay)
        // ══════════════════════════════════════════════════════════════
        case 'checkout.session.completed':
            $session = $event['data']['object'];
            $paymentIntentId = $session['payment_intent'];
            $customerEmail = $session['customer_email'] ?? '';
            $amountTotal = $session['amount_total'];

            logWebhook('Checkout session completado', [
                'payment_intent' => $paymentIntentId,
                'amount' => $amountTotal
            ]);

            if ($db) {
                $stmt = $db->prepare("
                    INSERT INTO stripe_webhook_log
                    (event_id, event_type, payment_intent_id, amount, status, customer_email, processed)
                    VALUES (?, ?, ?, ?, 'completed', ?, 1)
                    ON DUPLICATE KEY UPDATE processed = 1
                ");
                $stmt->bind_param("sssis", $event['id'], $event['type'], $paymentIntentId, $amountTotal, $customerEmail);
                $stmt->execute();
            }

            echo json_encode(['success' => true, 'message' => 'Checkout processado']);
            break;

        // ══════════════════════════════════════════════════════════════
        // OUTROS EVENTOS
        // ══════════════════════════════════════════════════════════════
        default:
            logWebhook('Evento não tratado: ' . $event['type']);
            echo json_encode(['success' => true, 'message' => 'Evento recebido (não tratado)']);
    }

} catch (Exception $e) {
    logWebhook('ERRO: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
