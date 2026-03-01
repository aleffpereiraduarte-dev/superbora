<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__DIR__, 2) . '/logs/pagarme_superbora_errors.log');
/**
 * POST /api/mercado/webhook/pagarme.php
 * Webhook Pagar.me v5 — SuperBora Mercado
 *
 * Recebe eventos do Pagar.me e atualiza pedidos no PostgreSQL.
 * Eventos: charge.paid, charge.payment_failed, charge.refunded, order.paid, order.canceled
 */

// No auth required — webhook from Pagar.me
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

// Load env
$envPath = dirname(__DIR__, 3) . '/.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

// Logging
$logDir = dirname(__DIR__, 2) . '/logs/pagarme_superbora/';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
$logFile = $logDir . date('Y-m-d') . '.log';

function wlog($msg, $data = null) {
    global $logFile;
    $entry = date('Y-m-d H:i:s') . " - {$msg}";
    if ($data) $entry .= ' — ' . json_encode($data, JSON_UNESCAPED_UNICODE);
    file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND);
}

// Read payload
$raw = file_get_contents('php://input');

// Validate Pagar.me webhook signature (HMAC)
$pagarmeWebhookSecret = $_ENV['PAGARME_WEBHOOK_SECRET'] ?? '';
if (!$pagarmeWebhookSecret) {
    wlog('REJECTED: PAGARME_WEBHOOK_SECRET not configured', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
    http_response_code(401);
    exit(json_encode(['error' => 'Webhook secret not configured']));
}

$signature = $_SERVER['HTTP_X_HUB_SIGNATURE'] ?? '';
if (!$signature) {
    wlog('Webhook sem assinatura - rejeitado', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
    http_response_code(401);
    exit(json_encode(['error' => 'Missing signature']));
}

$expectedSig = 'sha1=' . hash_hmac('sha1', $raw, $pagarmeWebhookSecret);
if (!hash_equals($expectedSig, $signature)) {
    wlog('Assinatura invalida', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown', 'sig' => substr($signature, 0, 20)]);
    http_response_code(401);
    exit(json_encode(['error' => 'Invalid signature']));
}

$payload = json_decode($raw, true);

wlog("Webhook recebido", [
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'type' => $payload['type'] ?? 'unknown'
]);

if (empty($payload) || empty($payload['type'])) {
    wlog("Payload invalido");
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid payload']));
}

try {
    // Connect to PostgreSQL
    $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s",
        $_ENV['DB_HOSTNAME'] ?? 'localhost',
        $_ENV['DB_PORT'] ?? '5432',
        $_ENV['DB_NAME'] ?? 'love1'
    );
    $db = new PDO($dsn, $_ENV['DB_USERNAME'] ?? '', $_ENV['DB_PASSWORD'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 10
    ]);

    // Extract event data
    $eventType = $payload['type'];
    $eventData = $payload['data'] ?? [];

    // For charge events, data is the charge itself
    // For order events, data is the order with charges inside
    $chargeId = $eventData['id'] ?? null;
    $status = $eventData['status'] ?? null;
    $amount = isset($eventData['amount']) ? $eventData['amount'] / 100 : 0;
    $paymentMethod = $eventData['payment_method'] ?? null;

    // Find order_id from metadata or om_pagarme_transacoes
    $orderId = null;

    // 1. Check metadata
    if (isset($eventData['metadata']['order_id'])) {
        $orderId = (int)$eventData['metadata']['order_id'];
    }

    // 2. Check charges array (for order events)
    if (!$orderId && isset($eventData['charges'][0])) {
        $charge = $eventData['charges'][0];
        $chargeId = $charge['id'] ?? $chargeId;
        $paymentMethod = $charge['payment_method'] ?? $paymentMethod;
        if (isset($charge['metadata']['order_id'])) {
            $orderId = (int)$charge['metadata']['order_id'];
        }
    }

    // 3. Look up in om_pagarme_transacoes by charge_id
    if (!$orderId && $chargeId) {
        $stmt = $db->prepare("SELECT pedido_id FROM om_pagarme_transacoes WHERE charge_id = ? LIMIT 1");
        $stmt->execute([$chargeId]);
        $row = $stmt->fetch();
        if ($row) $orderId = (int)$row['pedido_id'];
    }

    // 4. Look up by pagarme_order_id
    if (!$orderId && isset($eventData['id']) && str_starts_with($eventData['id'], 'or_')) {
        $stmt = $db->prepare("SELECT pedido_id FROM om_pagarme_transacoes WHERE pagarme_order_id = ? LIMIT 1");
        $stmt->execute([$eventData['id']]);
        $row = $stmt->fetch();
        if ($row) $orderId = (int)$row['pedido_id'];
    }

    // SECURITY: Idempotency — check if this event was already processed (prevent replay attacks)
    $eventFingerprint = hash('sha256', $raw);
    $stmtDedup = $db->prepare("SELECT id FROM om_pagarme_webhook_log WHERE payload_hash = ? LIMIT 1");
    $stmtDedup->execute([$eventFingerprint]);
    if ($stmtDedup->fetch()) {
        wlog("Evento duplicado ignorado", ['type' => $eventType, 'hash' => substr($eventFingerprint, 0, 16)]);
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Already processed']);
        exit;
    }

    // Log the event
    $db->prepare("
        INSERT INTO om_pagarme_webhook_log (event_type, charge_id, order_id, status, amount, payload, payload_hash)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([$eventType, $chargeId, $orderId, $status, $amount, json_encode($payload), $eventFingerprint]);

    wlog("Evento processando", ['type' => $eventType, 'charge' => $chargeId, 'order' => $orderId, 'status' => $status]);

    // Process event
    switch ($eventType) {
        case 'charge.paid':
        case 'order.paid':
            handlePaid($db, $chargeId, $orderId, $eventData, $amount, $paymentMethod);
            break;

        case 'charge.payment_failed':
        case 'charge.declined':
            handleFailed($db, $chargeId, $orderId, $eventData);
            break;

        case 'charge.refunded':
        case 'charge.chargedback':
            handleRefunded($db, $chargeId, $orderId, $eventData, $amount);
            break;

        case 'order.canceled':
        case 'charge.canceled':
            handleCanceled($db, $chargeId, $orderId);
            break;

        default:
            wlog("Evento ignorado: {$eventType}");
    }

    http_response_code(200);
    echo json_encode(['success' => true, 'event' => $eventType, 'order_id' => $orderId]);

} catch (Exception $e) {
    wlog("ERRO: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

// ═══════════════════════════════════════════════════════════════
// HANDLERS
// ═══════════════════════════════════════════════════════════════

function handlePaid($db, $chargeId, $orderId, $eventData, $amount, $paymentMethod) {
    // Update om_pagarme_transacoes
    if ($chargeId) {
        $db->prepare("UPDATE om_pagarme_transacoes SET status = 'paid', updated_at = NOW() WHERE charge_id = ?")
           ->execute([$chargeId]);
    }

    if (!$orderId) {
        wlog("PAID sem order_id — charge: {$chargeId}");
        return;
    }

    // Update order payment status
    $db->prepare("
        UPDATE om_market_orders
        SET pagamento_status = 'pago', payment_status = 'paid', date_modified = NOW()
        WHERE order_id = ?
    ")->execute([$orderId]);

    // Also promote order status to 'confirmado' if still 'pendente'
    // (avoid reverting later statuses like 'em_preparo', 'saiu_entrega', etc.)
    $db->prepare("
        UPDATE om_market_orders
        SET status = 'confirmado', date_modified = NOW()
        WHERE order_id = ? AND status = 'pendente'
    ")->execute([$orderId]);

    wlog("PAGO — pedido #{$orderId}, charge {$chargeId}, R$ " . number_format($amount, 2));

    // Send notifications
    try {
        $stmt = $db->prepare("
            SELECT o.customer_id, o.partner_id, o.total, p.name as mercado_nome
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.order_id = ?
        ");
        $stmt->execute([$orderId]);
        $pedido = $stmt->fetch();

        if ($pedido) {
            $notifyPath = dirname(__DIR__) . '/helpers/notify.php';
            if (file_exists($notifyPath)) {
                require_once $notifyPath;

                $total = (float)($pedido['total'] ?? $amount);
                $customerId = (int)$pedido['customer_id'];
                $partnerId = (int)$pedido['partner_id'];

                if ($customerId) {
                    notifyCustomer($db, $customerId,
                        'Pagamento confirmado!',
                        sprintf('Seu pagamento de R$ %.2f foi aprovado. Pedido #%d em preparo!', $total, $orderId),
                        '/mercado/vitrine/pedidos/' . $orderId
                    );
                }

                if ($partnerId) {
                    notifyPartner($db, $partnerId,
                        'Novo pedido pago!',
                        sprintf('Pedido #%d - R$ %.2f pago via %s. Prepare o pedido!', $orderId, $total, $paymentMethod ?? 'pix'),
                        '/painel/mercado/pedidos.php'
                    );
                }
            }
        }
    } catch (Exception $e) {
        wlog("Erro notificacao: " . $e->getMessage());
    }

    // Cashback (2% base)
    try {
        $stmt = $db->prepare("SELECT customer_id, total FROM om_market_orders WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if ($order && (int)$order['customer_id'] && (float)$order['total'] > 0) {
            $customerId = (int)$order['customer_id'];
            $total = (float)$order['total'];
            $cashback = round($total * 0.02, 2);
            if ($cashback >= 0.01) {
                // Table om_cashback created via migration
                // Idempotency: check if cashback already exists for this order_id + type
                $stmtCheck = $db->prepare("SELECT id FROM om_cashback WHERE order_id = ? AND type = 'earned' LIMIT 1");
                $stmtCheck->execute([$orderId]);
                if (!$stmtCheck->fetch()) {
                    $db->prepare("
                        INSERT INTO om_cashback (customer_id, order_id, type, amount, description, status, expires_at)
                        VALUES (?, ?, 'earned', ?, ?, 'pending', NOW() + INTERVAL '90 days')
                    ")->execute([$customerId, $orderId, $cashback, "Cashback pedido #{$orderId}"]);
                } else {
                    wlog("Cashback ja existente para pedido #{$orderId} — ignorado");
                }
                wlog("Cashback R$ {$cashback} — cliente {$customerId}");
            }
        }
    } catch (Exception $e) {
        wlog("Erro cashback: " . $e->getMessage());
    }
}

function handleFailed($db, $chargeId, $orderId, $eventData) {
    $reason = $eventData['last_transaction']['acquirer_message'] ?? 'Pagamento recusado';

    if ($chargeId) {
        $db->prepare("UPDATE om_pagarme_transacoes SET status = 'failed', updated_at = NOW() WHERE charge_id = ?")
           ->execute([$chargeId]);
    }

    if ($orderId) {
        $db->prepare("UPDATE om_market_orders SET pagamento_status = 'falhou', date_modified = NOW() WHERE order_id = ?")
           ->execute([$orderId]);
    }

    wlog("FALHOU — pedido #{$orderId}, motivo: {$reason}");
}

function handleRefunded($db, $chargeId, $orderId, $eventData, $amount) {
    if ($chargeId) {
        $db->prepare("UPDATE om_pagarme_transacoes SET status = 'refunded', updated_at = NOW() WHERE charge_id = ?")
           ->execute([$chargeId]);
    }

    if ($orderId) {
        // Only update payment status if not already refunded (idempotency)
        $db->prepare("UPDATE om_market_orders SET pagamento_status = 'estornado', date_modified = NOW() WHERE order_id = ? AND pagamento_status != 'estornado'")
           ->execute([$orderId]);
    }

    wlog("ESTORNADO — pedido #{$orderId}, R$ " . number_format($amount, 2));
}

function handleCanceled($db, $chargeId, $orderId) {
    if ($chargeId) {
        $db->prepare("UPDATE om_pagarme_transacoes SET status = 'canceled', updated_at = NOW() WHERE charge_id = ?")
           ->execute([$chargeId]);
    }

    if ($orderId) {
        // Only cancel orders in early states — never revert delivered/completed orders
        $db->prepare("UPDATE om_market_orders SET pagamento_status = 'cancelado', status = 'cancelled', date_modified = NOW() WHERE order_id = ? AND status IN ('pendente', 'confirmado')")
           ->execute([$orderId]);
    }

    wlog("CANCELADO — pedido #{$orderId}");
}
