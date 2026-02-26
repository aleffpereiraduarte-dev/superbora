<?php
/**
 * GET /api/mercado/orders/stream.php?order_id=X&token=XXX
 * Server-Sent Events (SSE) endpoint for real-time order updates
 *
 * Streams order status changes and shopper location to authenticated customers.
 * Client should reconnect after stream ends (30s timeout).
 */

// SSE must not be rate-limited or buffered
// Skip normal database.php to avoid rate limiting on long-lived connections
if (file_exists(__DIR__ . '/../../../.env')) {
    $envFile = file(__DIR__ . '/../../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envFile as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

define("DB_HOST", $_ENV['DB_HOSTNAME'] ?? "localhost");
define("DB_NAME", $_ENV['DB_DATABASE'] ?? "love1");
define("DB_USER", $_ENV['DB_USERNAME'] ?? "love1");
define("DB_PASS", $_ENV['DB_PASSWORD'] ?? "");

require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

// CORS - exact match with strict comparison
$allowedOrigins = array_map('trim', explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? 'https://onemundo.com.br,https://www.onemundo.com.br'));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $origin);
    header("Vary: Origin");
} elseif (empty($origin)) {
    header("Access-Control-Allow-Origin: https://onemundo.com.br");
}
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("X-Content-Type-Options: nosniff");
header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
header("Referrer-Policy: strict-origin-when-cross-origin");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") exit;

// SSE headers
header("Content-Type: text/event-stream");
header("Cache-Control: no-cache");
header("Connection: keep-alive");
header("X-Accel-Buffering: no"); // Disable nginx buffering

set_time_limit(35);
ignore_user_abort(false);

// Disable output buffering
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
    $token = $_GET['token'];
    $tokenSource = 'query';
    // SECURITY WARNING: Token passed via URL query string — leaks in server/proxy logs
    error_log("[SECURITY] orders/stream.php: token passed via URL query string from IP " . ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR']) . " — prefer Authorization header");
}

if (!$token) {
    echo "event: error\ndata: {\"message\":\"Token necessario\"}\n\n";
    flush();
    exit;
}

try {
    $db = new PDO(
        "pgsql:host=" . DB_HOST . ";port=" . ($_ENV['DB_PORT'] ?? '5432') . ";dbname=" . DB_NAME,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->validateToken($token);

    if (!$payload || $payload['type'] !== 'customer') {
        echo "event: error\ndata: {\"message\":\"Token invalido\"}\n\n";
        flush();
        exit;
    }

    $customerId = (int)$payload['uid'];
} catch (Exception $e) {
    echo "event: error\ndata: {\"message\":\"Erro de autenticacao\"}\n\n";
    flush();
    exit;
}

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : null;

// Send initial connection event
echo "event: connected\ndata: {\"status\":\"connected\",\"customer_id\":{$customerId}}\n\n";
flush();

$lastCheck = date('Y-m-d H:i:s', time() - 5); // Start 5 seconds in the past
$startTime = time();
$maxDuration = 30; // seconds
$pollInterval = 3; // seconds

while (true) {
    // Check if client disconnected
    if (connection_aborted()) break;

    // Check timeout
    if ((time() - $startTime) >= $maxDuration) break;

    try {
        // Query for order updates since last check
        $where = "o.customer_id = ? AND COALESCE(o.date_modified, o.date_added, '1970-01-01') >= ?";
        $params = [$customerId, $lastCheck];

        if ($orderId) {
            $where .= " AND o.order_id = ?";
            $params[] = $orderId;
        }

        $stmt = $db->prepare("
            SELECT o.order_id, o.order_number, o.status,
                   COALESCE(o.date_modified, o.date_added, '1970-01-01') AS last_modified,
                   o.delivering_at, o.delivered_at, o.accepted_at,
                   o.partner_id, o.partner_name,
                   o.driver_name, o.driver_phone, o.driver_photo
            FROM om_market_orders o
            WHERE {$where}
            ORDER BY last_modified DESC
            LIMIT 20
        ");
        $stmt->execute($params);
        $updatedOrders = $stmt->fetchAll();

        $now = date('Y-m-d H:i:s');

        foreach ($updatedOrders as $order) {
            $eventData = json_encode([
                'order_id' => (int)$order['order_id'],
                'order_number' => $order['order_number'],
                'status' => $order['status'],
                'partner_name' => $order['partner_name'],
                'driver_name' => $order['driver_name'] ?? null,
                'driver_phone' => $order['driver_phone'] ?? null,
                'driver_photo' => $order['driver_photo'] ?? null,
                'updated_at' => $order['last_modified'],
            ], JSON_UNESCAPED_UNICODE);

            echo "event: order_update\ndata: {$eventData}\n\n";

            // If order is in delivery, try to fetch shopper location
            if (in_array($order['status'], ['saiu_entrega', 'em_entrega'])) {
                try {
                    $locStmt = $db->prepare("
                        SELECT sl.latitude, sl.longitude, sl.updated_at
                        FROM om_market_shopper_locations sl
                        INNER JOIN om_market_orders o ON o.shopper_id = sl.shopper_id
                        WHERE o.order_id = ?
                        ORDER BY sl.updated_at DESC
                        LIMIT 1
                    ");
                    $locStmt->execute([(int)$order['order_id']]);
                    $location = $locStmt->fetch();

                    if ($location && $location['latitude'] && $location['longitude']) {
                        // Calculate simple ETA estimate (if we have distance info)
                        $etaMinutes = null;
                        try {
                            $etaStmt = $db->prepare("
                                SELECT eta_minutes FROM om_market_order_eta
                                WHERE order_id = ?
                                ORDER BY created_at DESC LIMIT 1
                            ");
                            $etaStmt->execute([(int)$order['order_id']]);
                            $etaRow = $etaStmt->fetch();
                            if ($etaRow) $etaMinutes = (int)$etaRow['eta_minutes'];
                        } catch (Exception $e) {
                            // ETA table may not exist
                        }

                        $locData = json_encode([
                            'order_id' => (int)$order['order_id'],
                            'lat' => (float)$location['latitude'],
                            'lng' => (float)$location['longitude'],
                            'eta_minutes' => $etaMinutes,
                            'location_updated_at' => $location['updated_at'],
                        ]);

                        echo "event: location_update\ndata: {$locData}\n\n";
                    }
                } catch (Exception $e) {
                    // Location tables may not exist, skip silently
                }
            }
        }

        $lastCheck = $now;

    } catch (Exception $e) {
        error_log("[SSE stream.php] Error: " . $e->getMessage());
        echo "event: error\ndata: {\"message\":\"Erro interno\"}\n\n";
        flush();
        break;
    }

    // Flush and sleep
    flush();
    sleep($pollInterval);
}

// Send stream-end event so client knows to reconnect
echo "event: stream_end\ndata: {\"reason\":\"timeout\"}\n\n";
flush();
