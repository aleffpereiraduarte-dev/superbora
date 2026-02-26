<?php
/**
 * POST /api/mercado/webhooks/boraum.php
 * Webhook receiver para atualizacoes de status do BoraUm
 *
 * Eventos recebidos:
 *   - driver_assigned: motorista aceitou a corrida
 *   - picked_up: motorista coletou o pedido
 *   - in_transit: motorista em transito (equivale a picked_up em alguns fluxos)
 *   - delivered: pedido entregue
 *   - cancelled: entrega cancelada pelo BoraUm/motorista
 *   - expired: nenhum motorista aceitou a tempo
 *
 * Seguranca: HMAC-SHA256 no header X-Webhook-Signature
 */

require_once __DIR__ . '/../config/database.php';

// Permitir POST de qualquer origem (webhook externo)
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Ler body raw (necessario para verificacao de assinatura)
$rawBody = file_get_contents('php://input');
if (!$rawBody) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty body']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!$payload) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Verificar assinatura HMAC-SHA256
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
$webhookSecret = getWebhookSecret();

// MANDATORY: Webhook secret must be configured
if (!$webhookSecret) {
    error_log("[boraum-webhook] REJECTED: BORAUM_WEBHOOK_SECRET not configured");
    http_response_code(500);
    echo json_encode(['error' => 'Webhook secret not configured']);
    exit;
}

// MANDATORY: Signature validation
if (!$signature) {
    error_log("[boraum-webhook] REJECTED: Missing X-Webhook-Signature header");
    http_response_code(401);
    echo json_encode(['error' => 'Missing signature']);
    exit;
}

$expectedSig = hash_hmac('sha256', $rawBody, $webhookSecret);
if (!hash_equals($expectedSig, $signature)) {
    error_log("[boraum-webhook] REJECTED: Invalid signature. Received: $signature");
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

// Extrair dados do webhook
$event = $payload['event'] ?? '';
$deliveryId = (int)($payload['delivery_id'] ?? 0);
$externalId = $payload['external_id'] ?? '';
$status = $payload['status'] ?? $event;
$driver = $payload['driver'] ?? null;
$timestamp = $payload['timestamp'] ?? date('c');

error_log("[boraum-webhook] Evento: $event | Delivery: $deliveryId | External: $externalId | Status: $status");

if (!$event || !$deliveryId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing event or delivery_id']);
    exit;
}

try {
    $db = getDB();

    // Buscar entrega local pelo boraum_delivery_id
    $stmt = $db->prepare("
        SELECT e.id AS entrega_id, e.referencia_id AS order_id, e.status AS entrega_status,
               e.boraum_delivery_id, e.boraum_status
        FROM om_entregas e
        WHERE e.boraum_delivery_id = ?
        LIMIT 1
    ");
    $stmt->execute([$deliveryId]);
    $entrega = $stmt->fetch();

    // Fallback: buscar por external_id (order_number)
    if (!$entrega && $externalId) {
        $stmt = $db->prepare("
            SELECT e.id AS entrega_id, e.referencia_id AS order_id, e.status AS entrega_status,
                   e.boraum_delivery_id, e.boraum_status
            FROM om_entregas e
            JOIN om_market_orders o ON o.order_id = e.referencia_id
            WHERE o.order_number = ?
            LIMIT 1
        ");
        $stmt->execute([$externalId]);
        $entrega = $stmt->fetch();
    }

    if (!$entrega) {
        error_log("[boraum-webhook] Entrega nao encontrada: delivery_id=$deliveryId, external_id=$externalId");
        http_response_code(404);
        echo json_encode(['error' => 'Delivery not found']);
        exit;
    }

    $entregaId = (int)$entrega['entrega_id'];
    $orderId = (int)$entrega['order_id'];

    // Mapear status BoraUm → status local
    // IMPORTANTE: order_status deve usar valores do ENUM de om_market_orders
    // ENUM: pending,confirmed,shopping,purchased,delivering,out_for_delivery,delivered,
    //       cancelled,pendente,aceito,coletando,coleta_finalizada,em_entrega,
    //       aguardando_motorista,problema_entrega,cancelado,aguardando_retirada,
    //       retirado,ready_for_delivery,preparando,pronto,aguardando_entregador
    $statusMap = [
        'driver_assigned' => [
            'entrega_status' => 'motorista_aceito',
            'order_status'   => 'em_entrega',
            'event_type'     => 'driver_assigned',
            'event_msg'      => 'Motorista aceitou a corrida'
        ],
        'picked_up' => [
            'entrega_status' => 'em_transito',
            'order_status'   => 'out_for_delivery',
            'event_type'     => 'driver_picked_up',
            'event_msg'      => 'Motorista coletou o pedido'
        ],
        'in_transit' => [
            'entrega_status' => 'em_transito',
            'order_status'   => 'out_for_delivery',
            'event_type'     => 'driver_in_transit',
            'event_msg'      => 'Pedido em transito'
        ],
        'delivered' => [
            'entrega_status' => 'entregue',
            'order_status'   => 'entregue',
            'event_type'     => 'delivery_completed',
            'event_msg'      => 'Pedido entregue com sucesso'
        ],
        'cancelled' => [
            'entrega_status' => 'cancelado',
            'order_status'   => 'pronto', // volta pra pronto, precisa re-despachar
            'event_type'     => 'delivery_cancelled',
            'event_msg'      => 'Entrega cancelada pelo BoraUm/motorista'
        ],
        'expired' => [
            'entrega_status' => 'expirado',
            'order_status'   => 'pronto', // volta pra pronto, nenhum motorista aceitou
            'event_type'     => 'delivery_expired',
            'event_msg'      => 'Nenhum motorista aceitou a entrega'
        ]
    ];

    if (!isset($statusMap[$event])) {
        error_log("[boraum-webhook] Evento desconhecido: $event");
        http_response_code(200); // ACK mesmo assim
        echo json_encode(['received' => true, 'warning' => 'Unknown event']);
        exit;
    }

    $mapping = $statusMap[$event];

    // Idempotency: prevent duplicate processing of the same delivery_id + event
    $idempotencyKey = "boraum_{$deliveryId}_{$event}";
    // Table om_webhook_idempotency created via migration
    $stmtIdem = $db->prepare("SELECT 1 FROM om_webhook_idempotency WHERE idempotency_key = ?");
    $stmtIdem->execute([$idempotencyKey]);
    if ($stmtIdem->fetch()) {
        error_log("[boraum-webhook] Duplicate event ignored: $idempotencyKey");
        http_response_code(200);
        echo json_encode(['received' => true, 'message' => 'Already processed']);
        exit;
    }

    // Garantir colunas antes da transacao (ALTER TABLE causa implicit commit)
    if ($driver) {
        ensureDriverColumns($db);
        ensureOrderDriverColumns($db);
    }

    // Iniciar transacao
    $db->beginTransaction();

    try {
        // 1. Atualizar om_entregas
        $entregaUpdate = "UPDATE om_entregas SET boraum_status = ?, status = ?, updated_at = NOW()";
        $entregaParams = [$status, $mapping['entrega_status']];

        // Salvar dados do motorista se disponivel
        if ($driver) {
            $entregaUpdate .= ", motorista_nome = ?, motorista_telefone = ?, motorista_foto = ?, motorista_veiculo = ?";
            $entregaParams[] = $driver['name'] ?? '';
            $entregaParams[] = $driver['phone'] ?? '';
            $entregaParams[] = $driver['photo_url'] ?? '';
            $entregaParams[] = ($driver['vehicle_type'] ?? '') . ' ' . ($driver['vehicle_plate'] ?? '');
        }

        // Timestamps especificos por evento
        switch ($event) {
            case 'picked_up':
            case 'in_transit':
                $entregaUpdate .= ", coletado_em = COALESCE(coletado_em, NOW())";
                break;
            case 'delivered':
                $entregaUpdate .= ", entregue_em = NOW()";
                break;
        }

        $entregaUpdate .= " WHERE id = ?";
        $entregaParams[] = $entregaId;

        $db->prepare($entregaUpdate)->execute($entregaParams);

        // 2. Atualizar om_market_orders
        $orderUpdate = "UPDATE om_market_orders SET status = ?, updated_at = NOW(), date_modified = NOW()";
        $orderParams = [$mapping['order_status']];

        // Salvar dados do motorista no pedido tambem
        if ($driver) {
            $orderUpdate .= ", driver_name = ?, driver_phone = ?, driver_photo = ?";
            $orderParams[] = $driver['name'] ?? '';
            $orderParams[] = $driver['phone'] ?? '';
            $orderParams[] = $driver['photo_url'] ?? '';
        }

        // Timestamps do pedido
        switch ($event) {
            case 'picked_up':
            case 'in_transit':
                $orderUpdate .= ", picked_up_at = COALESCE(picked_up_at, NOW())";
                break;
            case 'delivered':
                $orderUpdate .= ", delivered_at = NOW(), completed_at = NOW()";
                break;
        }

        $orderUpdate .= " WHERE order_id = ?";
        $orderParams[] = $orderId;

        $db->prepare($orderUpdate)->execute($orderParams);

        // 3. Registrar evento
        $db->prepare("
            INSERT INTO om_market_order_events (order_id, event_type, message, created_by, created_at)
            VALUES (?, ?, ?, 'boraum_webhook', NOW())
        ")->execute([$orderId, $mapping['event_type'], $mapping['event_msg']]);

        // 4. Salvar payload completo do webhook para auditoria
        $db->prepare("
            INSERT INTO om_market_order_events (order_id, event_type, message, created_by, created_at)
            VALUES (?, 'boraum_webhook_raw', ?, 'system', NOW())
        ")->execute([$orderId, json_encode($payload, JSON_UNESCAPED_UNICODE)]);

        // 5. Record idempotency key inside transaction
        $db->prepare("INSERT INTO om_webhook_idempotency (idempotency_key) VALUES (?)")
           ->execute([$idempotencyKey]);

        $db->commit();

        // 6. Notificar cliente (fora da transacao para nao bloquear)
        notifyCustomerAboutDelivery($db, $orderId, $event, $driver);

        error_log("[boraum-webhook] OK: Entrega #$entregaId | Pedido #$orderId | $event → {$mapping['order_status']}");

        http_response_code(200);
        echo json_encode([
            'received' => true,
            'entrega_id' => $entregaId,
            'order_id' => $orderId,
            'new_status' => $mapping['order_status']
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("[boraum-webhook] ERRO: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

// --- Funcoes auxiliares ---

/**
 * Busca BORAUM_WEBHOOK_SECRET do .env
 */
function getWebhookSecret(): string {
    static $secret = null;
    if ($secret !== null) return $secret;

    foreach (['/var/www/html/.env', '/root/.env'] as $envFile) {
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                if (strpos($line, 'BORAUM_WEBHOOK_SECRET=') === 0) {
                    $secret = trim(substr($line, 22));
                    return $secret;
                }
            }
        }
    }

    $secret = '';
    return $secret;
}

/**
 * Garante que colunas do motorista existem em om_entregas
 */
function ensureDriverColumns(PDO $db): void {
    // Columns motorista_nome, motorista_telefone, motorista_foto, motorista_veiculo, coletado_em, entregue_em on om_entregas created via migration
    return;
}

/**
 * Garante que colunas do motorista existem em om_market_orders
 */
function ensureOrderDriverColumns(PDO $db): void {
    // Columns driver_name, driver_phone, driver_photo, picked_up_at, delivered_at on om_market_orders created via migration
    return;
}

/**
 * Notifica cliente sobre atualizacao de entrega via in-app + WhatsApp
 */
function notifyCustomerAboutDelivery(PDO $db, int $orderId, string $event, ?array $driver): void {
    try {
        // Buscar dados do pedido e cliente
        $stmt = $db->prepare("
            SELECT o.customer_id, o.customer_phone, o.order_number, o.customer_name
            FROM om_market_orders o
            WHERE o.order_id = ?
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order || !$order['customer_id']) return;

        $orderNumber = $order['order_number'];
        $customerId = (int)$order['customer_id'];
        $customerPhone = $order['customer_phone'] ?? '';

        // Titulo e mensagem por evento
        $notifications = [
            'driver_assigned' => [
                'title' => 'Motorista a caminho!',
                'body'  => "Um motorista aceitou seu pedido #{$orderNumber}" . ($driver ? " - {$driver['name']}" : '')
            ],
            'picked_up' => [
                'title' => 'Pedido coletado!',
                'body'  => "Seu pedido #{$orderNumber} foi coletado e esta a caminho"
            ],
            'in_transit' => [
                'title' => 'Pedido a caminho!',
                'body'  => "Seu pedido #{$orderNumber} esta a caminho"
            ],
            'delivered' => [
                'title' => 'Pedido entregue!',
                'body'  => "Seu pedido #{$orderNumber} foi entregue. Obrigado por usar o SuperBora!"
            ],
            'cancelled' => [
                'title' => 'Entrega cancelada',
                'body'  => "A entrega do pedido #{$orderNumber} foi cancelada. Estamos buscando outro motorista."
            ],
            'expired' => [
                'title' => 'Buscando motorista',
                'body'  => "Ainda estamos buscando um motorista para seu pedido #{$orderNumber}. Aguarde."
            ]
        ];

        if (!isset($notifications[$event])) return;

        $notif = $notifications[$event];

        // In-app + FCM
        require_once __DIR__ . '/../config/notify.php';
        sendNotification($db, $customerId, 'customer', $notif['title'], $notif['body'], [
            'order_id' => $orderId,
            'status' => $event,
            'url' => '/pedidos?id=' . $orderId
        ]);

        // WhatsApp com templates especificos
        if ($customerPhone) {
            try {
                require_once __DIR__ . '/../helpers/zapi-whatsapp.php';

                switch ($event) {
                    case 'driver_assigned':
                        $driverName = $driver['name'] ?? 'Motorista';
                        $driverPhone = $driver['phone'] ?? '';
                        $msg = "🏍️ *Motorista a caminho!*\n\n"
                             . "Pedido *#{$orderNumber}*\n"
                             . "Motorista: *{$driverName}*\n";
                        if ($driverPhone) $msg .= "Tel: {$driverPhone}\n";
                        $msg .= "\nEle esta indo buscar seu pedido!";
                        sendWhatsApp($customerPhone, $msg);
                        break;

                    case 'picked_up':
                    case 'in_transit':
                        whatsappOrderOnTheWay($customerPhone, $orderNumber);
                        break;

                    case 'delivered':
                        whatsappOrderDelivered($customerPhone, $orderNumber);
                        break;

                    case 'cancelled':
                        $msg = "⚠️ *Motorista indisponivel*\n\n"
                             . "Pedido *#{$orderNumber}*\n"
                             . "Estamos buscando outro motorista para voce. Aguarde!";
                        sendWhatsApp($customerPhone, $msg);
                        break;

                    case 'expired':
                        $msg = "⏳ *Buscando motorista...*\n\n"
                             . "Pedido *#{$orderNumber}*\n"
                             . "Ainda estamos procurando um motorista disponivel. Voce sera notificado assim que encontrarmos.";
                        sendWhatsApp($customerPhone, $msg);
                        break;
                }
            } catch (Exception $we) {
                error_log("[boraum-webhook] WhatsApp erro: " . $we->getMessage());
            }
        }

    } catch (Exception $e) {
        error_log("[boraum-webhook] Erro notificacao: " . $e->getMessage());
    }
}
