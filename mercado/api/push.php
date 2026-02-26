<?php
require_once __DIR__ . '/../config/database.php';
/**
 * 🔔 API DE PUSH NOTIFICATIONS
 */
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") exit(0);

session_start();

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die(json_encode(["success" => false, "error" => "DB Error"]));
}

$input = json_decode(file_get_contents("php://input"), true);
if (!$input) $input = $_POST;
$action = $input["action"] ?? $_GET["action"] ?? "";

// ══════════════════════════════════════════════════════════════════════════════
// REGISTRAR SUBSCRIPTION DO PUSH
// ══════════════════════════════════════════════════════════════════════════════
if ($action === "subscribe") {
    $user_type = $input["user_type"] ?? "";
    $user_id = intval($input["user_id"] ?? 0);
    $endpoint = $input["endpoint"] ?? "";
    $p256dh = $input["p256dh"] ?? "";
    $auth = $input["auth"] ?? "";
    $device_info = $input["device_info"] ?? "";
    
    if (!$user_type || !$user_id || !$endpoint) {
        echo json_encode(["success" => false, "error" => "Dados inválidos"]);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO om_push_subscriptions (user_type, user_id, endpoint, p256dh, auth, device_info)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE endpoint = VALUES(endpoint), p256dh = VALUES(p256dh), 
            auth = VALUES(auth), device_info = VALUES(device_info), is_active = 1, last_used = NOW()
        ");
        $stmt->execute([$user_type, $user_id, $endpoint, $p256dh, $auth, $device_info]);
        
        // Criar configurações padrão
        $pdo->prepare("INSERT IGNORE INTO om_notification_settings (user_type, user_id) VALUES (?, ?)")
            ->execute([$user_type, $user_id]);
        
        echo json_encode(["success" => true]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// BUSCAR NOTIFICAÇÕES PENDENTES (POLLING)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === "poll") {
    $user_type = $_GET["user_type"] ?? $input["user_type"] ?? "";
    $user_id = intval($_GET["user_id"] ?? $input["user_id"] ?? 0);
    $last_id = intval($_GET["last_id"] ?? $input["last_id"] ?? 0);
    
    if (!$user_type || !$user_id) {
        echo json_encode(["success" => false, "error" => "Dados inválidos"]);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT queue_id, title, body, icon, data, priority, sound, created_at
            FROM om_notifications_queue 
            WHERE user_type = ? AND user_id = ? AND status = \"pending\" AND queue_id > ?
            ORDER BY priority DESC, created_at ASC
            LIMIT 10
        ");
        $stmt->execute([$user_type, $user_id, $last_id]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Marcar como enviadas
        if (!empty($notifications)) {
            $ids = array_column($notifications, "queue_id");
            $pdo->exec("UPDATE om_notifications_queue SET status = \"sent\", sent_at = NOW() WHERE queue_id IN (" . implode(",", $ids) . ")");
        }
        
        // Decodificar JSON data
        foreach ($notifications as &$n) {
            $n["data"] = $n["data"] ? json_decode($n["data"], true) : null;
        }
        
        echo json_encode(["success" => true, "notifications" => $notifications]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// ENVIAR NOTIFICAÇÃO (INTERNO)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === "send") {
    $user_type = $input["user_type"] ?? "";
    $user_id = intval($input["user_id"] ?? 0);
    $title = $input["title"] ?? "";
    $body = $input["body"] ?? "";
    $icon = $input["icon"] ?? "🔔";
    $data = $input["data"] ?? null;
    $priority = $input["priority"] ?? "normal";
    $sound = $input["sound"] ?? "default";
    
    if (!$user_type || !$user_id || !$title) {
        echo json_encode(["success" => false, "error" => "Dados inválidos"]);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO om_notifications_queue (user_type, user_id, title, body, icon, data, priority, sound)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user_type, $user_id, $title, $body, $icon,
            $data ? json_encode($data) : null, $priority, $sound
        ]);
        
        echo json_encode(["success" => true, "queue_id" => $pdo->lastInsertId()]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// ENVIAR PARA MÚLTIPLOS USUÁRIOS
// ══════════════════════════════════════════════════════════════════════════════
if ($action === "broadcast") {
    $user_type = $input["user_type"] ?? "";
    $user_ids = $input["user_ids"] ?? [];
    $title = $input["title"] ?? "";
    $body = $input["body"] ?? "";
    $icon = $input["icon"] ?? "🔔";
    $data = $input["data"] ?? null;
    $priority = $input["priority"] ?? "normal";
    $sound = $input["sound"] ?? "default";
    
    if (!$user_type || empty($user_ids) || !$title) {
        echo json_encode(["success" => false, "error" => "Dados inválidos"]);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO om_notifications_queue (user_type, user_id, title, body, icon, data, priority, sound)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $sent = 0;
        $data_json = $data ? json_encode($data) : null;
        
        foreach ($user_ids as $uid) {
            $stmt->execute([$user_type, intval($uid), $title, $body, $icon, $data_json, $priority, $sound]);
            $sent++;
        }
        
        echo json_encode(["success" => true, "sent" => $sent]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// MARCAR COMO LIDA
// ══════════════════════════════════════════════════════════════════════════════
if ($action === "read") {
    $queue_id = intval($input["queue_id"] ?? 0);
    
    if (!$queue_id) {
        echo json_encode(["success" => false]);
        exit;
    }
    
    $pdo->prepare("UPDATE om_notifications_queue SET status = \"read\", read_at = NOW() WHERE queue_id = ?")
        ->execute([$queue_id]);
    
    echo json_encode(["success" => true]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// BUSCAR OFERTAS PENDENTES (DELIVERY)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === "get_pending_offers") {
    $delivery_id = intval($_GET["delivery_id"] ?? $input["delivery_id"] ?? 0);
    
    if (!$delivery_id) {
        echo json_encode(["success" => false, "error" => "Delivery ID obrigatório"]);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                o.offer_id, o.order_id, o.delivery_earning, o.expires_at, o.current_wave,
                ord.customer_name, ord.total, ord.vehicle_required,
                COALESCE(p.trade_name, p.name) as market_name,
                p.latitude as market_lat, p.longitude as market_lng,
                TIMESTAMPDIFF(SECOND, NOW(), o.expires_at) as seconds_remaining
            FROM om_delivery_notifications n
            JOIN om_delivery_offers o ON n.offer_id = o.offer_id
            JOIN om_market_orders ord ON o.order_id = ord.order_id
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE n.delivery_id = ? 
            AND o.status = \"pending\" 
            AND o.expires_at > NOW()
            AND n.is_responded = 0
            ORDER BY o.created_at DESC
        ");
        $stmt->execute([$delivery_id]);
        $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(["success" => true, "offers" => $offers, "count" => count($offers)]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// BUSCAR PEDIDOS PENDENTES (SHOPPER)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === "get_pending_orders") {
    $shopper_id = intval($_GET["shopper_id"] ?? $input["shopper_id"] ?? 0);
    $partner_id = intval($_GET["partner_id"] ?? $input["partner_id"] ?? 0);
    
    try {
        $sql = "
            SELECT 
                o.order_id, o.customer_name, o.total, o.created_at,
                (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as total_items,
                COALESCE(p.trade_name, p.name) as market_name
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.status = \"pending\" 
            AND (o.shopper_id IS NULL OR o.shopper_id = 0)
        ";
        
        if ($partner_id) {
            $sql .= " AND o.partner_id = $partner_id";
        }
        
        $sql .= " ORDER BY o.created_at ASC LIMIT 10";
        
        $offers = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(["success" => true, "orders" => $offers, "count" => count($offers)]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
    }
    exit;
}

echo json_encode(["success" => false, "error" => "Ação inválida"]);
