<?php
/**
 * GET /api/mercado/partner/orders-stream.php?token=XXX
 * Server-Sent Events (SSE) endpoint for real-time partner order updates
 *
 * Streams new orders, status changes, and stats updates for the partner's store.
 * Client should reconnect after stream ends (30s timeout).
 */

// Load database config (PostgreSQL) and auth — skip rate limiting for SSE
require_once __DIR__ . '/../config/database.php';
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

// CORS headers inline (fix: setCorsHeaders is not available without database.php)
$allowedOrigins = array_map('trim', explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? 'https://superbora.com.br,https://www.superbora.com.br,https://onemundo.com.br,https://www.onemundo.com.br'));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $origin);
    header("Vary: Origin");
} elseif (empty($origin)) {
    header("Access-Control-Allow-Origin: https://superbora.com.br");
}
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("X-Content-Type-Options: nosniff");
header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
header("Referrer-Policy: strict-origin-when-cross-origin");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// SSE headers
header("Content-Type: text/event-stream");
header("Cache-Control: no-cache");
header("Connection: keep-alive");
header("X-Accel-Buffering: no");

set_time_limit(35);
ignore_user_abort(false);

while (ob_get_level()) ob_end_clean();

// Authenticate: prefer Authorization header over query param (tokens in URLs leak in server logs)
$token = null;
$tokenSource = null;
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
    $token = $m[1];
    $tokenSource = 'header';
}
if (!$token && !empty($_GET['token'])) {
    // SECURITY: Reject tokens passed via URL query string — they leak in server/proxy logs and browser history
    error_log("[SECURITY] partner/orders-stream.php: token passed via URL query string REJECTED from IP " . ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR']) . " — use Authorization header");
    echo "event: error\ndata: {\"message\":\"Token via URL nao aceito. Use header Authorization.\"}\n\n";
    flush();
    exit;
}

if (!$token) {
    echo "event: error\ndata: {\"message\":\"Token necessario\"}\n\n";
    flush();
    exit;
}

try {
    $db = getDB();

    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->validateToken($token);

    if (!$payload || $payload['type'] !== 'partner') {
        echo "event: error\ndata: {\"message\":\"Token invalido\"}\n\n";
        flush();
        exit;
    }

    $partnerId = (int)$payload['uid'];
} catch (Exception $e) {
    echo "event: error\ndata: {\"message\":\"Erro de autenticacao\"}\n\n";
    flush();
    exit;
}

// Send initial connection event with server timestamp
$serverTime = date('c');
echo "event: connected\ndata: " . json_encode([
    "status" => "connected",
    "partner_id" => $partnerId,
    "server_time" => $serverTime
]) . "\n\n";
flush();

$lastCheck = date('Y-m-d H:i:s', time() - 5);
$startTime = time();
$maxDuration = 30;
$pollInterval = 3;
$statsInterval = 15; // send stats every 15s
$lastStatsSent = 0;

while (true) {
    if (connection_aborted()) break;
    if ((time() - $startTime) >= $maxDuration) break;

    $now = time();

    try {
        // Check for new or updated orders for this partner
        $stmt = $db->prepare("
            SELECT o.order_id, o.order_number, o.customer_name, o.customer_phone,
                   o.status, o.subtotal, o.delivery_fee, o.total,
                   o.forma_pagamento, o.delivery_address, o.notes,
                   o.is_pickup, o.date_added, o.date_modified,
                   o.shopper_id, s.name as shopper_name,
                   (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as total_items
            FROM om_market_orders o
            LEFT JOIN om_market_shoppers s ON o.shopper_id = s.shopper_id
            WHERE o.partner_id = ? AND o.date_modified >= ?
            ORDER BY o.date_modified DESC
            LIMIT 20
        ");
        $stmt->execute([$partnerId, $lastCheck]);
        $updatedOrders = $stmt->fetchAll();

        $nowStr = date('Y-m-d H:i:s');

        foreach ($updatedOrders as $order) {
            // Check if this is a new order (date_added == date_modified roughly)
            $isNew = (abs(strtotime($order['date_added']) - strtotime($order['date_modified'])) < 10)
                     && $order['status'] === 'pendente';

            $eventType = $isNew ? 'new_order' : 'order_update';

            $eventData = json_encode([
                'order_id' => (int)$order['order_id'],
                'order_number' => $order['order_number'],
                'customer_name' => $order['customer_name'],
                'customer_phone' => $order['customer_phone'],
                'status' => $order['status'],
                'total' => (float)$order['total'],
                'subtotal' => (float)$order['subtotal'],
                'delivery_fee' => (float)$order['delivery_fee'],
                'total_items' => (int)$order['total_items'],
                'is_pickup' => (bool)$order['is_pickup'],
                'payment_method' => $order['forma_pagamento'],
                'address' => $order['delivery_address'],
                'notes' => $order['notes'],
                'shopper_name' => $order['shopper_name'],
                'date' => $order['date_added'],
                'updated_at' => $order['date_modified'],
                'server_time' => date('c'),
            ], JSON_UNESCAPED_UNICODE);

            echo "event: {$eventType}\ndata: {$eventData}\n\n";
        }

        $lastCheck = $nowStr;

        // Send stats update periodically
        if (($now - $lastStatsSent) >= $statsInterval) {
            $today = date('Y-m-d');
            $stmtStats = $db->prepare("
                SELECT
                    COUNT(*) as total_today,
                    COALESCE(SUM(CASE WHEN status NOT IN ('cancelado','cancelled') THEN total ELSE 0 END), 0) as revenue_today,
                    SUM(CASE WHEN status IN ('pendente','pending') THEN 1 ELSE 0 END) as pending_count,
                    SUM(CASE WHEN status IN ('aceito','confirmed','confirmado','preparando') THEN 1 ELSE 0 END) as preparing_count,
                    SUM(CASE WHEN status = 'entregue' THEN 1 ELSE 0 END) as delivered_count
                FROM om_market_orders
                WHERE partner_id = ? AND DATE(date_added) = ?
            ");
            $stmtStats->execute([$partnerId, $today]);
            $stats = $stmtStats->fetch();

            echo "event: stats_update\ndata: " . json_encode([
                'total_today' => (int)$stats['total_today'],
                'revenue_today' => (float)$stats['revenue_today'],
                'pending_count' => (int)$stats['pending_count'],
                'preparing_count' => (int)$stats['preparing_count'],
                'delivered_count' => (int)$stats['delivered_count'],
                'server_time' => date('c'),
            ]) . "\n\n";

            $lastStatsSent = $now;
        }

    } catch (Exception $e) {
        error_log("[SSE partner/orders-stream.php] Error: " . $e->getMessage());
        echo "event: error\ndata: {\"message\":\"Erro interno\"}\n\n";
        flush();
        break;
    }

    flush();
    sleep($pollInterval);
}

echo "event: stream_end\ndata: {\"reason\":\"timeout\"}\n\n";
flush();
