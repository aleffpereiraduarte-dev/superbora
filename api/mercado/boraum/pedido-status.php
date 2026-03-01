<?php
/**
 * GET /api/mercado/boraum/pedido-status.php?order_id=X&token=XXX
 * Server-Sent Events (SSE) para tracking do pedido em tempo real
 *
 * Autentica via token de passageiro (query param ou header).
 * Streams: order_update, location_update, stream_end
 */

// SSE - skip rate limiting, load env manually
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

// CORS - exact match with strict comparison
$allowedOrigins = array_map('trim', explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? 'https://superbora.com.br,https://www.superbora.com.br,https://onemundo.com.br,https://www.onemundo.com.br'));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $origin);
    header("Vary: Origin");
} elseif (empty($origin)) {
    header("Access-Control-Allow-Origin: https://superbora.com.br");
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
header("X-Accel-Buffering: no");

set_time_limit(35);
ignore_user_abort(false);

while (ob_get_level()) ob_end_clean();

// Auth: token via query or header
$token = $_GET['token'] ?? null;
if (!$token) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
        $token = $m[1];
    }
}

if (!$token) {
    echo "event: error\ndata: " . json_encode(["message" => "Token necessario"]) . "\n\n";
    flush();
    exit;
}

// Validacao de formato do token (deve ser hexadecimal, 32-128 caracteres)
$tokenLen = strlen($token);
if ($tokenLen < 32 || $tokenLen > 128 || !ctype_xdigit($token)) {
    echo "event: error\ndata: " . json_encode(["message" => "Formato de token invalido"]) . "\n\n";
    flush();
    exit;
}

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if (!$orderId) {
    echo "event: error\ndata: " . json_encode(["message" => "order_id necessario"]) . "\n\n";
    flush();
    exit;
}

try {
    $db = new PDO(
        "pgsql:host=" . DB_HOST . ";dbname=" . DB_NAME,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // Validate BoraUm passenger token
    $stmtAuth = $db->prepare("SELECT id FROM boraum_passageiros WHERE token = ? AND status = 'ativo'");
    $stmtAuth->execute([$token]);
    $passageiro = $stmtAuth->fetch();

    if (!$passageiro) {
        echo "event: error\ndata: " . json_encode(["message" => "Token invalido"]) . "\n\n";
        flush();
        exit;
    }

    $passageiroId = (int)$passageiro['id'];

    // Get linked customer_id
    $stmtLink = $db->prepare("SELECT customer_id FROM om_boraum_customer_link WHERE passageiro_id = ?");
    $stmtLink->execute([$passageiroId]);
    $link = $stmtLink->fetch();

    if (!$link) {
        echo "event: error\ndata: " . json_encode(["message" => "Conta nao configurada"]) . "\n\n";
        flush();
        exit;
    }

    $customerId = (int)$link['customer_id'];

    // Verify order belongs to customer
    $stmtOrder = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
    $stmtOrder->execute([$orderId, $customerId]);
    if (!$stmtOrder->fetch()) {
        echo "event: error\ndata: " . json_encode(["message" => "Pedido nao encontrado"]) . "\n\n";
        flush();
        exit;
    }

} catch (Exception $e) {
    echo "event: error\ndata: " . json_encode(["message" => "Erro de autenticacao"]) . "\n\n";
    flush();
    exit;
}

// Connected
echo "event: connected\ndata: " . json_encode(["status" => "connected", "order_id" => $orderId]) . "\n\n";
flush();

$lastCheck = date('Y-m-d H:i:s', time() - 5);
$lastChatId = 0;
$startTime = time();
$maxDuration = 30;
$pollInterval = 3;

// Buscar ultimo chat_id pra nao enviar mensagens antigas
try {
    $stmtLastChat = $db->prepare("SELECT MAX(message_id) FROM om_order_chat WHERE order_id = ?");
    $stmtLastChat->execute([$orderId]);
    $lastChatId = (int)$stmtLastChat->fetchColumn();
} catch (Exception $e) {}

$statusLabels = [
    'pending' => 'Pendente', 'pendente' => 'Pendente',
    'aceito' => 'Aceito', 'confirmed' => 'Confirmado', 'confirmado' => 'Confirmado',
    'preparando' => 'Preparando', 'pronto' => 'Pronto',
    'saiu_entrega' => 'Saiu para entrega', 'em_entrega' => 'Em entrega',
    'entregue' => 'Entregue', 'delivered' => 'Entregue',
    'cancelado' => 'Cancelado', 'cancelled' => 'Cancelado',
];

while (true) {
    if (connection_aborted()) break;
    if ((time() - $startTime) >= $maxDuration) break;

    try {
        $stmt = $db->prepare("
            SELECT o.order_id, o.order_number, o.status,
                   GREATEST(COALESCE(o.date_modified, '1970-01-01'), COALESCE(o.updated_at, '1970-01-01')) AS last_modified,
                   o.delivering_at, o.delivered_at, o.accepted_at,
                   o.partner_name, o.driver_name, o.driver_phone, o.driver_photo
            FROM om_market_orders o
            WHERE o.order_id = ? AND o.customer_id = ?
              AND GREATEST(COALESCE(o.date_modified, '1970-01-01'), COALESCE(o.updated_at, '1970-01-01')) >= ?
        ");
        $stmt->execute([$orderId, $customerId, $lastCheck]);
        $order = $stmt->fetch();

        $now = date('Y-m-d H:i:s');

        if ($order) {
            $eventData = json_encode([
                'order_id' => (int)$order['order_id'],
                'order_number' => $order['order_number'],
                'status' => $order['status'],
                'status_label' => $statusLabels[$order['status']] ?? $order['status'],
                'partner_name' => $order['partner_name'],
                'driver_name' => $order['driver_name'] ?? null,
                'driver_phone' => $order['driver_phone'] ?? null,
                'updated_at' => $order['last_modified'],
            ], JSON_UNESCAPED_UNICODE);

            echo "event: order_update\ndata: {$eventData}\n\n";

            // Location update for delivery statuses
            if (in_array($order['status'], ['saiu_entrega', 'em_entrega'])) {
                try {
                    $locStmt = $db->prepare("
                        SELECT sl.latitude, sl.longitude, sl.updated_at
                        FROM om_market_shopper_locations sl
                        INNER JOIN om_market_orders o ON o.shopper_id = sl.shopper_id
                        WHERE o.order_id = ?
                        ORDER BY sl.updated_at DESC LIMIT 1
                    ");
                    $locStmt->execute([$orderId]);
                    $location = $locStmt->fetch();

                    if ($location && $location['latitude'] && $location['longitude']) {
                        $locData = json_encode([
                            'order_id' => $orderId,
                            'lat' => (float)$location['latitude'],
                            'lng' => (float)$location['longitude'],
                            'location_updated_at' => $location['updated_at'],
                        ]);
                        echo "event: location_update\ndata: {$locData}\n\n";
                    }
                } catch (Exception $e) {}
            }

            // If order is terminal, no need to keep streaming
            if (in_array($order['status'], ['entregue', 'cancelado', 'cancelled'])) {
                echo "event: order_final\ndata: " . json_encode(["status" => $order['status'], "message" => "Pedido finalizado"]) . "\n\n";
                flush();
                break;
            }
        }

        // Check for new chat messages
        try {
            $stmtChat = $db->prepare("
                SELECT message_id, sender_type, sender_name, message, message_type, image_url, created_at
                FROM om_order_chat
                WHERE order_id = ? AND message_id > ? AND sender_type != 'customer'
                ORDER BY message_id ASC
                LIMIT 10
            ");
            $stmtChat->execute([$orderId, $lastChatId]);
            $newMessages = $stmtChat->fetchAll();

            foreach ($newMessages as $msg) {
                $chatData = json_encode([
                    'message_id'  => (int)$msg['message_id'],
                    'sender_type' => $msg['sender_type'],
                    'sender_name' => $msg['sender_name'] ?: $msg['sender_type'],
                    'message'     => $msg['message'],
                    'message_type' => $msg['message_type'] ?? 'text',
                    'image_url'   => $msg['image_url'] ?: null,
                    'created_at'  => $msg['created_at'],
                ], JSON_UNESCAPED_UNICODE);
                echo "event: chat_message\ndata: {$chatData}\n\n";
                $lastChatId = (int)$msg['message_id'];
            }
        } catch (Exception $e) {}

        $lastCheck = $now;

    } catch (Exception $e) {
        error_log("[BoraUm SSE] Error: " . $e->getMessage());
        echo "event: error\ndata: " . json_encode(["message" => "Erro interno"]) . "\n\n";
        flush();
        break;
    }

    flush();
    sleep($pollInterval);
}

echo "event: stream_end\ndata: " . json_encode(["reason" => "timeout"]) . "\n\n";
flush();
