<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * SSE Real-Time Stream - Acompanhamento de Pedido em Tempo Real
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * GET /api/realtime/stream.php?order_id=123&token=JWT_TOKEN
 *
 * Server-Sent Events endpoint que envia atualizacoes em tempo real sobre
 * o status do pedido, progresso da coleta, localizacao do shopper, etc.
 *
 * O cliente reconecta automaticamente apos 5 minutos (limite do loop).
 */

// SSE headers - devem ser definidos ANTES de qualquer output
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Para nginx - desabilita buffering do proxy
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Apenas GET permitido
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo "event: error\ndata: " . json_encode(['error' => 'Method not allowed. Use GET.']) . "\n\n";
    exit;
}

// Carregar dependencias
require_once dirname(__DIR__) . '/mercado/config/database.php';
require_once dirname(__DIR__, 2) . '/includes/classes/OmAuth.php';

// ══════════════════════════════════════════════════════════════════════════════
// VALIDACAO DE PARAMETROS
// ══════════════════════════════════════════════════════════════════════════════

$token = $_GET['token'] ?? '';
$order_id = (int)($_GET['order_id'] ?? 0);

if (!$token || !$order_id) {
    echo "event: error\ndata: " . json_encode(['error' => 'token and order_id required']) . "\n\n";
    ob_flush();
    flush();
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// VALIDACAO DO TOKEN
// ══════════════════════════════════════════════════════════════════════════════

try {
    $db = getDB();
    $auth = OmAuth::getInstance();
    $auth->setDb($db);

    $payload = $auth->validateToken($token);
    if (!$payload) {
        echo "event: error\ndata: " . json_encode(['error' => 'invalid or expired token']) . "\n\n";
        ob_flush();
        flush();
        exit;
    }
} catch (Exception $e) {
    error_log("[SSE Stream] Auth error: " . $e->getMessage());
    echo "event: error\ndata: " . json_encode(['error' => 'authentication failed']) . "\n\n";
    ob_flush();
    flush();
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// FUNCOES AUXILIARES
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Calcula o percentual de progresso do pedido com base no status atual.
 * Durante a coleta, interpola entre 10% e 50% conforme itens coletados.
 */
function calculateProgress(array $order): int {
    switch ($order['status']) {
        case 'pendente':
            return 0;
        case 'aceito':
            return 10;
        case 'coletando':
            $total = max((int)$order['items_total'], 1);
            $collected = (int)$order['items_collected'];
            return 10 + (int)(($collected / $total) * 40); // 10-50%
        case 'coleta_finalizada':
        case 'purchased':
            return 55;
        case 'aguardando_motorista':
            return 60;
        case 'delivering':
        case 'em_entrega':
            return 70;
        case 'out_for_delivery':
            return 80;
        case 'delivered':
            return 100;
        case 'cancelado':
            return 0;
        default:
            return 0;
    }
}

/**
 * Retorna o label amigavel para o status do pedido (em portugues).
 */
function getStatusLabel(string $status): string {
    $labels = [
        'pendente'              => 'Procurando shopper...',
        'aceito'                => 'Shopper a caminho do mercado',
        'coletando'             => 'Comprando seus produtos',
        'coleta_finalizada'     => 'Compras finalizadas',
        'purchased'             => 'Preparando para entrega',
        'aguardando_motorista'  => 'Buscando entregador...',
        'delivering'            => 'Entregador a caminho do mercado',
        'em_entrega'            => 'Saiu para entrega',
        'out_for_delivery'      => 'A caminho do seu endereco',
        'delivered'             => 'Entregue!',
        'cancelado'             => 'Pedido cancelado',
    ];
    return $labels[$status] ?? $status;
}

/**
 * Envia um evento SSE formatado.
 */
function sendSSE(string $event, array $data): void {
    echo "event: {$event}\n";
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

/**
 * Envia um comentario de heartbeat SSE (mantém a conexao viva).
 */
function sendHeartbeat(): void {
    echo ": heartbeat\n\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

// ══════════════════════════════════════════════════════════════════════════════
// VERIFICACAO INICIAL DO PEDIDO
// ══════════════════════════════════════════════════════════════════════════════

try {
    $checkStmt = $db->prepare("SELECT order_id, customer_id, shopper_id FROM om_market_orders WHERE order_id = ?");
    $checkStmt->execute([$order_id]);
    $checkOrder = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$checkOrder) {
        sendSSE('error', ['error' => 'Order not found']);
        exit;
    }

    // Verificar permissao: usuario deve ser o customer, o shopper designado, ou admin
    $userType = $payload['type'] ?? '';
    $userId = $payload['uid'] ?? 0;
    $hasAccess = false;

    if (in_array($userType, ['admin', 'rh'])) {
        $hasAccess = true;
    } elseif ($userType === 'shopper' && (int)$checkOrder['shopper_id'] === $userId) {
        $hasAccess = true;
    } elseif ((int)$checkOrder['customer_id'] === $userId) {
        // Customer associado ao pedido
        $hasAccess = true;
    }

    if (!$hasAccess) {
        sendSSE('error', ['error' => 'Access denied to this order']);
        exit;
    }
} catch (Exception $e) {
    error_log("[SSE Stream] Initial check error: " . $e->getMessage());
    sendSSE('error', ['error' => 'Failed to verify order access']);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// LOOP PRINCIPAL SSE
// ══════════════════════════════════════════════════════════════════════════════

// Desabilitar limite de tempo do PHP para conexoes longas
set_time_limit(0);

// Desabilitar output buffering implicito se possivel
if (ob_get_level() > 0) {
    ob_end_flush();
}

// Enviar evento de conexao estabelecida
sendSSE('connected', [
    'message' => 'Stream connected',
    'order_id' => $order_id,
    'timestamp' => date('c')
]);

$lastUpdate = '';
$lastHeartbeat = time();
$startTime = time();
$maxDuration = 300; // 5 minutos, depois o cliente reconecta
$pollInterval = 3;  // Verificar a cada 3 segundos

$orderQuery = "SELECT o.order_id, o.status, o.shopper_id, o.delivery_id,
    o.accepted_at, o.started_collecting_at, o.finished_collecting_at,
    o.started_delivery_at, o.delivered_at, o.handoff_scanned_at,
    o.pronto_coleta_em, o.staging_area, o.updated_at,
    s.name as shopper_name, s.phone as shopper_phone,
    s.latitude as shopper_lat, s.longitude as shopper_lng,
    (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id AND collected = 1) as items_collected,
    (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as items_total
    FROM om_market_orders o
    LEFT JOIN om_market_shoppers s ON o.shopper_id = s.shopper_id
    WHERE o.order_id = ?";

try {
    while (time() - $startTime < $maxDuration) {
        // Verificar se o cliente desconectou
        if (connection_aborted()) {
            break;
        }

        // Buscar estado atual do pedido
        $stmt = $db->prepare($orderQuery);
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            sendSSE('error', ['error' => 'Order not found']);
            break;
        }

        // Gerar fingerprint do estado atual para detectar mudancas
        $currentUpdate = $order['updated_at'] . '_' . $order['status'] . '_' . $order['items_collected'];

        if ($currentUpdate !== $lastUpdate) {
            $lastUpdate = $currentUpdate;

            // Calcular progresso
            $progress = calculateProgress($order);

            // Montar dados do evento
            $eventData = [
                'order_id'        => (int)$order['order_id'],
                'status'          => $order['status'],
                'status_label'    => getStatusLabel($order['status']),
                'progress'        => $progress,
                'items_collected' => (int)$order['items_collected'],
                'items_total'     => (int)$order['items_total'],
                'shopper'         => $order['shopper_id'] ? [
                    'name' => $order['shopper_name'],
                    'lat'  => $order['shopper_lat'] !== null ? (float)$order['shopper_lat'] : null,
                    'lng'  => $order['shopper_lng'] !== null ? (float)$order['shopper_lng'] : null,
                ] : null,
                'staging_area'    => $order['staging_area'],
                'timeline'        => [
                    'accepted_at'   => $order['accepted_at'],
                    'collecting_at' => $order['started_collecting_at'],
                    'collected_at'  => $order['finished_collecting_at'],
                    'delivering_at' => $order['started_delivery_at'],
                    'delivered_at'  => $order['delivered_at'],
                ],
                'timestamp'       => date('c'),
            ];

            sendSSE('update', $eventData);

            // Se o pedido chegou a um estado terminal, encerrar o stream
            if (in_array($order['status'], ['delivered', 'cancelado'])) {
                sendSSE('complete', [
                    'message' => 'Order reached terminal state',
                    'status'  => $order['status'],
                ]);
                break;
            }
        }

        // Enviar heartbeat a cada 30 segundos para manter a conexao viva
        $now = time();
        if ($now - $lastHeartbeat >= 30) {
            sendHeartbeat();
            $lastHeartbeat = $now;
        }

        sleep($pollInterval);
    }

    // Se saiu do loop por timeout, informar o cliente para reconectar
    if (time() - $startTime >= $maxDuration) {
        sendSSE('timeout', [
            'message'  => 'Stream timeout. Please reconnect.',
            'duration' => $maxDuration,
        ]);
    }

} catch (Exception $e) {
    error_log("[SSE Stream] Loop error for order {$order_id}: " . $e->getMessage());
    sendSSE('error', ['error' => 'Internal server error. Please reconnect.']);
}
