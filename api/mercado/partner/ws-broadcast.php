<?php
/**
 * WebSocket Broadcast API
 * Send notifications to partners via WebSocket server
 *
 * POST /api/mercado/partner/ws-broadcast.php
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Configuration
define('WS_HOST', '127.0.0.1');
define('WS_PORT', 8080);
$wsApiSecret = getenv('WS_API_SECRET');
if (empty($wsApiSecret)) {
    http_response_code(500);
    echo json_encode(['error' => 'WS_API_SECRET environment variable not configured']);
    exit;
}
define('API_SECRET', $wsApiSecret);

/**
 * Verify API authorization — Bearer token or X-API-Key header only.
 * No Referer-based auth. No blanket localhost bypass.
 */
function verifyAuth(): bool
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    // Check Bearer token
    if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
        return hash_equals(API_SECRET, $matches[1]);
    }

    // Check X-API-Key header
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!empty($apiKey)) {
        return hash_equals(API_SECRET, $apiKey);
    }

    return false;
}

/**
 * Send message to WebSocket server via socket
 */
function sendToWebSocket(array $message): array
{
    $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

    if (!$socket) {
        return [
            'success' => false,
            'error' => 'Failed to create socket'
        ];
    }

    // Try to connect to WebSocket server's internal port
    // We'll use a simple TCP connection to send broadcast commands
    $connected = @socket_connect($socket, WS_HOST, WS_PORT);

    if (!$connected) {
        socket_close($socket);

        // Alternative: Write to a message queue file
        return sendViaMessageQueue($message);
    }

    // Send as WebSocket frame
    $json = json_encode($message);
    $frame = createWebSocketFrame($json);

    socket_write($socket, $frame, strlen($frame));
    socket_close($socket);

    return ['success' => true];
}

/**
 * Create WebSocket frame
 */
function createWebSocketFrame(string $data): string
{
    $length = strlen($data);

    if ($length <= 125) {
        return chr(0x81) . chr($length) . $data;
    } elseif ($length <= 65535) {
        return chr(0x81) . chr(126) . pack('n', $length) . $data;
    } else {
        return chr(0x81) . chr(127) . pack('NN', 0, $length) . $data;
    }
}

/**
 * Alternative: Send via message queue (file-based)
 */
function sendViaMessageQueue(array $message): array
{
    // SECURITY: Use application-owned directory with restrictive permissions (not world-readable /tmp)
    $queueDir = '/var/www/html/api/mercado/storage/ws_queue';

    if (!is_dir($queueDir)) {
        mkdir($queueDir, 0700, true);
    }

    // Use cryptographically secure random for filename to prevent prediction
    $filename = $queueDir . '/' . bin2hex(random_bytes(16)) . '.json';
    // Add HMAC for message integrity verification
    $wsKey = getenv('WS_API_SECRET') ?: '';
    $message['_integrity'] = hash_hmac('sha256', json_encode($message), $wsKey);
    $written = file_put_contents($filename, json_encode($message), LOCK_EX);

    if ($written === false) {
        return [
            'success' => false,
            'error' => 'Failed to queue message'
        ];
    }

    return [
        'success' => true,
        'queued' => true,
        'queue_file' => basename($filename)
    ];
}

/**
 * Send notification to partner
 */
function notifyPartner(int $partnerId, string $type, array $data): array
{
    $message = [
        'type' => 'broadcast',
        'target' => [
            'partner_id' => $partnerId
        ],
        'payload' => [
            'type' => $type,
            'data' => $data,
            'timestamp' => time(),
            'id' => uniqid('notif_', true)
        ]
    ];

    return sendToWebSocket($message);
}

/**
 * Send notification to channel
 */
function notifyChannel(string $channel, string $type, array $data): array
{
    $message = [
        'type' => 'broadcast',
        'target' => [
            'channel' => $channel
        ],
        'payload' => [
            'type' => $type,
            'data' => $data,
            'timestamp' => time(),
            'id' => uniqid('notif_', true)
        ]
    ];

    return sendToWebSocket($message);
}

/**
 * Broadcast to all partners
 */
function notifyAll(string $type, array $data): array
{
    $message = [
        'type' => 'broadcast',
        'target' => [
            'all' => true
        ],
        'payload' => [
            'type' => $type,
            'data' => $data,
            'timestamp' => time(),
            'id' => uniqid('notif_', true)
        ]
    ];

    return sendToWebSocket($message);
}

// Main request handling
try {
    // Verify authorization
    if (!verifyAuth()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    // Parse request body
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        exit;
    }

    // Validate required fields
    if (!isset($data['type'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing notification type']);
        exit;
    }

    $type = $data['type'];
    $payload = $data['data'] ?? [];
    $result = null;

    // Route based on target
    if (isset($data['partner_id'])) {
        // Send to specific partner
        $result = notifyPartner((int) $data['partner_id'], $type, $payload);
    } elseif (isset($data['channel'])) {
        // Send to channel
        $result = notifyChannel($data['channel'], $type, $payload);
    } elseif (isset($data['broadcast']) && $data['broadcast'] === true) {
        // Broadcast to all
        $result = notifyAll($type, $payload);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Missing target (partner_id, channel, or broadcast)']);
        exit;
    }

    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => 'Notification sent',
            'queued' => $result['queued'] ?? false
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $result['error'] ?? 'Failed to send notification'
        ]);
    }

} catch (Exception $e) {
    error_log("[partner/ws-broadcast] Erro: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Erro interno'
    ]);
}

/**
 * Helper functions for common notification types
 * These can be called from other PHP files
 */

/**
 * Notify partner of new order
 */
function wsNotifyNewOrder(int $partnerId, array $orderData): array
{
    return notifyPartner($partnerId, 'new_order', [
        'order_id' => $orderData['id'] ?? null,
        'order_number' => $orderData['numero_pedido'] ?? null,
        'customer_name' => $orderData['cliente_nome'] ?? 'Cliente',
        'total' => $orderData['total'] ?? 0,
        'items_count' => $orderData['items_count'] ?? 0,
        'delivery_type' => $orderData['tipo_entrega'] ?? 'delivery',
        'sound' => 'new_order'
    ]);
}

/**
 * Notify partner of order status change
 */
function wsNotifyOrderUpdate(int $partnerId, array $orderData): array
{
    return notifyPartner($partnerId, 'order_update', [
        'order_id' => $orderData['id'] ?? null,
        'order_number' => $orderData['numero_pedido'] ?? null,
        'status' => $orderData['status'] ?? null,
        'previous_status' => $orderData['previous_status'] ?? null,
        'updated_by' => $orderData['updated_by'] ?? 'system'
    ]);
}

/**
 * Notify partner of new chat message
 */
function wsNotifyChatMessage(int $partnerId, array $messageData): array
{
    return notifyPartner($partnerId, 'chat_message', [
        'order_id' => $messageData['order_id'] ?? null,
        'from' => $messageData['from'] ?? 'customer',
        'message' => $messageData['message'] ?? '',
        'timestamp' => $messageData['timestamp'] ?? time()
    ]);
}

/**
 * Send system notification to partner
 */
function wsNotifySystem(int $partnerId, string $title, string $message, string $level = 'info'): array
{
    return notifyPartner($partnerId, 'notification', [
        'title' => $title,
        'message' => $message,
        'level' => $level, // info, warning, error, success
        'dismissible' => true
    ]);
}

/**
 * Broadcast store status change via HTTP to WebSocket server
 * Use this when a partner opens/closes/goes busy
 */
function wsNotifyStoreStatus(int $partnerId, string $city, array $statusData): array
{
    $wsKey = getenv('WS_API_SECRET');
    if (empty($wsKey)) {
        return ['success' => false, 'error' => 'WS_API_SECRET not configured'];
    }

    $payload = json_encode([
        'partner_id' => $partnerId,
        'city' => $city,
        'is_open' => $statusData['is_open'] ?? null,
        'busy_mode' => $statusData['busy_mode'] ?? null,
        'nome' => $statusData['nome'] ?? null,
    ]);

    $ch = curl_init('http://127.0.0.1:8080/store-status');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-API-Key: ' . $wsKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($result, true);
        return ['success' => true, 'delivered' => $data['delivered'] ?? 0];
    }
    return ['success' => false, 'error' => "HTTP $httpCode"];
}
