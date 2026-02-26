<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * 🔔 WEBHOOK PAGAR.ME v2.0 - OneMundo
 * URL: https://onemundo.com.br/api/pagarme/webhook.php
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Eventos suportados:
 * - charge.paid          : PIX/Boleto/Cartão pago
 * - charge.payment_failed: Pagamento falhou
 * - charge.refunded      : Estorno realizado
 * - charge.pending       : Aguardando pagamento
 * - order.paid           : Pedido totalmente pago
 * - order.canceled       : Pedido cancelado
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 */

header('Content-Type: application/json; charset=utf-8');

// Apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

require_once dirname(dirname(__DIR__)) . '/includes/env_loader.php';

// Configurações
$config = [
    'db_host' => env('DB_HOSTNAME', 'localhost'),
    'db_name' => env('DB_DATABASE', ''),
    'db_user' => env('DB_USERNAME', ''),
    'db_pass' => env('DB_PASSWORD', ''),
    'log_path' => __DIR__ . '/../../logs/pagarme_webhook/',
    'opencart_path' => __DIR__ . '/../../'
];

// Cria diretório de logs se não existir
if (!is_dir($config['log_path'])) {
    @mkdir($config['log_path'], 0755, true);
}

// Lê payload
$payload = file_get_contents('php://input');
$data = json_decode($payload, true);

// Log do webhook
$logFile = $config['log_path'] . date('Y-m-d') . '.log';
$logEntry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'data' => $data
];
file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND);

// Valida payload
if (empty($data) || empty($data['type'])) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid payload', 'received' => $data]));
}

try {
    // Conexão com banco
    $pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Cria tabela de log se não existir
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS om_pagarme_webhook_log (
            id SERIAL PRIMARY KEY,
            event_type VARCHAR(100),
            charge_id VARCHAR(100),
            order_id VARCHAR(100),
            status VARCHAR(50),
            amount DECIMAL(10,2),
            payment_method VARCHAR(50),
            payload JSON,
            processed SMALLINT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pagarme_log_charge ON om_pagarme_webhook_log (charge_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pagarme_log_order ON om_pagarme_webhook_log (order_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pagarme_log_status ON om_pagarme_webhook_log (status)");

    // Extrai dados do evento
    $eventType = $data['type'] ?? '';
    $eventData = $data['data'] ?? [];

    // Dados da charge
    $chargeId = $eventData['id'] ?? null;
    $status = $eventData['status'] ?? null;
    $amount = isset($eventData['amount']) ? $eventData['amount'] / 100 : 0;
    $paymentMethod = $eventData['payment_method'] ?? null;

    // Tenta extrair order_id dos metadados
    $orderId = null;
    if (isset($eventData['metadata']['order_id'])) {
        $orderId = $eventData['metadata']['order_id'];
    } elseif (isset($eventData['code'])) {
        // Código pode conter order_id
        preg_match('/(\d+)/', $eventData['code'], $matches);
        if (!empty($matches[1])) {
            $orderId = $matches[1];
        }
    }

    // Registra evento no log
    $stmt = $pdo->prepare("
        INSERT INTO om_pagarme_webhook_log
        (event_type, charge_id, order_id, status, amount, payment_method, payload)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $eventType,
        $chargeId,
        $orderId,
        $status,
        $amount,
        $paymentMethod,
        json_encode($data)
    ]);

    // Processa evento
    $result = processWebhookEvent($pdo, $eventType, $eventData, $chargeId, $orderId, $config);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'event' => $eventType,
        'charge_id' => $chargeId,
        'order_id' => $orderId,
        'result' => $result
    ]);

} catch (Exception $e) {
    // Log do erro
    file_put_contents($logFile, "[ERROR] " . $e->getMessage() . "\n", FILE_APPEND);

    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
}

/**
 * Processa evento do webhook
 */
function processWebhookEvent($pdo, $eventType, $eventData, $chargeId, $orderId, $config) {
    $result = ['processed' => false];

    switch ($eventType) {
        // ═══════════════════════════════════════════════════════════════════
        // PAGAMENTO CONFIRMADO
        // ═══════════════════════════════════════════════════════════════════
        case 'charge.paid':
        case 'order.paid':
            $result = handlePaymentPaid($pdo, $chargeId, $orderId, $eventData, $config);
            break;

        // ═══════════════════════════════════════════════════════════════════
        // PAGAMENTO PENDENTE
        // ═══════════════════════════════════════════════════════════════════
        case 'charge.pending':
        case 'charge.waiting_payment':
            $result = handlePaymentPending($pdo, $chargeId, $orderId, $eventData);
            break;

        // ═══════════════════════════════════════════════════════════════════
        // PAGAMENTO FALHOU
        // ═══════════════════════════════════════════════════════════════════
        case 'charge.payment_failed':
        case 'charge.declined':
            $result = handlePaymentFailed($pdo, $chargeId, $orderId, $eventData);
            break;

        // ═══════════════════════════════════════════════════════════════════
        // ESTORNO
        // ═══════════════════════════════════════════════════════════════════
        case 'charge.refunded':
        case 'charge.chargedback':
            $result = handleRefund($pdo, $chargeId, $orderId, $eventData);
            break;

        // ═══════════════════════════════════════════════════════════════════
        // CANCELAMENTO
        // ═══════════════════════════════════════════════════════════════════
        case 'order.canceled':
        case 'charge.canceled':
            $result = handleCancellation($pdo, $chargeId, $orderId, $eventData);
            break;

        default:
            $result = ['processed' => false, 'reason' => 'Event type not handled'];
    }

    return $result;
}

/**
 * Pagamento Confirmado
 */
function handlePaymentPaid($pdo, $chargeId, $orderId, $eventData, $config) {
    // Atualiza tabela de transações Pagar.me
    $stmt = $pdo->prepare("
        UPDATE om_pagarme_transacoes
        SET status = 'paid', paid_at = NOW(), updated_at = NOW()
        WHERE charge_id = ?
    ");
    $stmt->execute([$chargeId]);

    // Busca order_id se não tiver
    if (!$orderId) {
        $stmt = $pdo->prepare("SELECT pedido_id FROM om_pagarme_transacoes WHERE charge_id = ?");
        $stmt->execute([$chargeId]);
        if ($row = $stmt->fetch()) {
            $orderId = $row['pedido_id'];
        }
    }

    // Atualiza pedido do marketplace
    if ($orderId) {
        $pdo->prepare("
            UPDATE om_market_orders
            SET payment_status = 'paid', status = 'awaiting_shopper', updated_at = NOW()
            WHERE id = ? OR external_id = ?
        ")->execute([$orderId, $orderId]);

        // Notificar cliente e parceiro via Web Push
        try {
            $notifyHelper = dirname(__DIR__) . '/mercado/helpers/notify.php';
            if (file_exists($notifyHelper)) {
                require_once $notifyHelper;

                // Buscar dados do pedido
                $stmt = $pdo->prepare("
                    SELECT o.customer_id, o.partner_id, o.total, p.name as mercado_nome
                    FROM om_market_orders o
                    LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
                    WHERE o.id = ? OR o.external_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$orderId, $orderId]);
                $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($pedido) {
                    $customerId = (int)($pedido['customer_id'] ?? 0);
                    $partnerId = (int)($pedido['partner_id'] ?? 0);
                    $total = (float)($pedido['total'] ?? 0);

                    if ($customerId) {
                        notifyCustomer(
                            $pdo,
                            $customerId,
                            'Pagamento confirmado!',
                            sprintf('Seu pagamento de R$ %.2f foi aprovado. Pedido #%s em preparo!', $total, $orderId),
                            '/mercado/vitrine/pedidos/' . $orderId
                        );
                    }

                    if ($partnerId) {
                        notifyPartner(
                            $pdo,
                            $partnerId,
                            'Pagamento confirmado!',
                            sprintf('Pedido #%s - R$ %.2f pago. Prepare o pedido!', $orderId, $total),
                            '/painel/mercado/pedidos.php'
                        );
                    }
                }
            }
        } catch (Exception $pushErr) {
            // Log but don't fail the webhook
            $logFile = ($config['log_path'] ?? __DIR__ . '/../../logs/pagarme_webhook/') . date('Y-m-d') . '.log';
            file_put_contents($logFile, "[PUSH ERROR] " . $pushErr->getMessage() . "\n", FILE_APPEND);
        }
    }

    // Atualiza pedido do OpenCart
    if ($orderId) {
        updateOpenCartOrder($pdo, $orderId, 15, 'Pagamento confirmado via Pagar.me'); // 15 = Processando
    }

    return ['processed' => true, 'action' => 'payment_confirmed', 'order_id' => $orderId];
}

/**
 * Pagamento Pendente
 */
function handlePaymentPending($pdo, $chargeId, $orderId, $eventData) {
    $stmt = $pdo->prepare("
        UPDATE om_pagarme_transacoes
        SET status = 'pending', updated_at = NOW()
        WHERE charge_id = ?
    ");
    $stmt->execute([$chargeId]);

    return ['processed' => true, 'action' => 'payment_pending'];
}

/**
 * Pagamento Falhou
 */
function handlePaymentFailed($pdo, $chargeId, $orderId, $eventData) {
    $failReason = $eventData['last_transaction']['acquirer_message'] ?? 'Pagamento não aprovado';

    $stmt = $pdo->prepare("
        UPDATE om_pagarme_transacoes
        SET status = 'failed', fail_reason = ?, updated_at = NOW()
        WHERE charge_id = ?
    ");
    $stmt->execute([$failReason, $chargeId]);

    // Busca order_id
    if (!$orderId) {
        $stmt = $pdo->prepare("SELECT pedido_id FROM om_pagarme_transacoes WHERE charge_id = ?");
        $stmt->execute([$chargeId]);
        if ($row = $stmt->fetch()) {
            $orderId = $row['pedido_id'];
        }
    }

    // Atualiza OpenCart
    if ($orderId) {
        updateOpenCartOrder($pdo, $orderId, 10, 'Pagamento falhou: ' . $failReason); // 10 = Falhou
    }

    // Notificar cliente sobre falha
    if ($orderId) {
        try {
            $notifyHelper = dirname(__DIR__) . '/mercado/helpers/notify.php';
            if (file_exists($notifyHelper)) {
                require_once $notifyHelper;
                $stmt = $pdo->prepare("SELECT customer_id FROM om_market_orders WHERE id = ? OR external_id = ? LIMIT 1");
                $stmt->execute([$orderId, $orderId]);
                $pedido = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($pedido && (int)$pedido['customer_id']) {
                    notifyCustomer($pdo, (int)$pedido['customer_id'],
                        'Pagamento nao aprovado',
                        sprintf('O pagamento do pedido #%s nao foi aprovado. Tente outro metodo.', $orderId),
                        '/mercado/vitrine/checkout'
                    );
                }
            }
        } catch (Exception $e) { /* log but don't fail */ }
    }

    return ['processed' => true, 'action' => 'payment_failed', 'reason' => $failReason];
}

/**
 * Estorno
 */
function handleRefund($pdo, $chargeId, $orderId, $eventData) {
    $stmt = $pdo->prepare("
        UPDATE om_pagarme_transacoes
        SET status = 'refunded', updated_at = NOW()
        WHERE charge_id = ?
    ");
    $stmt->execute([$chargeId]);

    // Busca order_id
    if (!$orderId) {
        $stmt = $pdo->prepare("SELECT pedido_id FROM om_pagarme_transacoes WHERE charge_id = ?");
        $stmt->execute([$chargeId]);
        if ($row = $stmt->fetch()) {
            $orderId = $row['pedido_id'];
        }
    }

    // Atualiza OpenCart
    if ($orderId) {
        updateOpenCartOrder($pdo, $orderId, 11, 'Pagamento estornado'); // 11 = Estornado
    }

    // Notificar cliente sobre estorno
    if ($orderId) {
        try {
            $notifyHelper = dirname(__DIR__) . '/mercado/helpers/notify.php';
            if (file_exists($notifyHelper)) {
                require_once $notifyHelper;
                $stmt = $pdo->prepare("SELECT customer_id, total FROM om_market_orders WHERE id = ? OR external_id = ? LIMIT 1");
                $stmt->execute([$orderId, $orderId]);
                $pedido = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($pedido && (int)$pedido['customer_id']) {
                    $total = (float)($pedido['total'] ?? 0);
                    notifyCustomer($pdo, (int)$pedido['customer_id'],
                        'Estorno realizado',
                        sprintf('O valor de R$ %.2f do pedido #%s foi estornado.', $total, $orderId),
                        '/mercado/vitrine/pedidos/' . $orderId
                    );
                }
            }
        } catch (Exception $e) { /* log but don't fail */ }
    }

    return ['processed' => true, 'action' => 'refunded'];
}

/**
 * Cancelamento
 */
function handleCancellation($pdo, $chargeId, $orderId, $eventData) {
    $stmt = $pdo->prepare("
        UPDATE om_pagarme_transacoes
        SET status = 'canceled', updated_at = NOW()
        WHERE charge_id = ?
    ");
    $stmt->execute([$chargeId]);

    // Busca order_id
    if (!$orderId) {
        $stmt = $pdo->prepare("SELECT pedido_id FROM om_pagarme_transacoes WHERE charge_id = ?");
        $stmt->execute([$chargeId]);
        if ($row = $stmt->fetch()) {
            $orderId = $row['pedido_id'];
        }
    }

    // Atualiza OpenCart
    if ($orderId) {
        updateOpenCartOrder($pdo, $orderId, 7, 'Pedido cancelado'); // 7 = Cancelado
    }

    return ['processed' => true, 'action' => 'canceled'];
}

/**
 * Atualiza pedido no OpenCart
 */
function updateOpenCartOrder($pdo, $orderId, $orderStatusId, $comment = '') {
    try {
        // Verifica se pedido existe
        $stmt = $pdo->prepare("SELECT order_id FROM oc_order WHERE order_id = ?");
        $stmt->execute([$orderId]);

        if (!$stmt->fetch()) {
            return false;
        }

        // Atualiza status do pedido
        $pdo->prepare("
            UPDATE oc_order
            SET order_status_id = ?, date_modified = NOW()
            WHERE order_id = ?
        ")->execute([$orderStatusId, $orderId]);

        // Adiciona histórico
        $pdo->prepare("
            INSERT INTO oc_order_history (order_id, order_status_id, notify, comment, date_added)
            VALUES (?, ?, 1, ?, NOW())
        ")->execute([$orderId, $orderStatusId, $comment]);

        return true;
    } catch (Exception $e) {
        return false;
    }
}
