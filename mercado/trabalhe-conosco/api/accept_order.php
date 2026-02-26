<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
/**
 * API: Shopper aceita pedido
 * POST /api/accept_order.php
 */

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

session_start();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit(json_encode(["success" => false, "error" => "Método não permitido"]));
}

// Verificar login
$workerId = $_SESSION["worker_id"] ?? null;
if (!$workerId) {
    http_response_code(401);
    exit(json_encode(["success" => false, "error" => "Não autenticado"]));
}

// Pegar dados
$input = json_decode(file_get_contents("php://input"), true);
$orderId = $input["order_id"] ?? $_POST["order_id"] ?? null;
$notificationId = $input["notification_id"] ?? $_POST["notification_id"] ?? null;

if (!$orderId) {
    http_response_code(400);
    exit(json_encode(["success" => false, "error" => "order_id obrigatório"]));
}

try {
    $db = new PDO("mysql:host=localhost;dbname=love1;charset=utf8mb4",
        "love1",
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Iniciar transação
    $db->beginTransaction();
    
    // Verificar se pedido ainda está disponível
    $stmt = $db->prepare("
        SELECT * FROM om_order_queue 
        WHERE order_id = ? AND status = 'notificando'
        FOR UPDATE
    ");
    $stmt->execute([$orderId]);
    $queue = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$queue) {
        $db->rollBack();
        exit(json_encode(["success" => false, "error" => "Pedido não está mais disponível"]));
    }
    
    // Atualizar fila
    $db->prepare("
        UPDATE om_order_queue 
        SET status = 'aceito', accepted_by = ?, accepted_at = NOW()
        WHERE queue_id = ?
    ")->execute([$workerId, $queue["queue_id"]]);
    
    // Atualizar pedido
    $db->prepare("
        UPDATE om_market_orders 
        SET shopper_id = ?, 
            matching_status = 'atribuido',
            order_status_id = 2,
            timer_started = NOW()
        WHERE order_id = ?
    ")->execute([$workerId, $orderId]);
    
    // Marcar notificação como aceita
    if ($notificationId) {
        $db->prepare("
            UPDATE om_shopper_notifications 
            SET status = 'aceita', responded_at = NOW()
            WHERE notification_id = ?
        ")->execute([$notificationId]);
    }
    
    // Marcar outras notificações do mesmo pedido como expiradas
    $db->prepare("
        UPDATE om_shopper_notifications 
        SET status = 'expirada'
        WHERE order_id = ? AND worker_id != ? AND status = 'pendente'
    ")->execute([$orderId, $workerId]);
    
    $db->commit();
    
    echo json_encode([
        "success" => true, 
        "message" => "Pedido aceito com sucesso!",
        "order_id" => $orderId,
        "redirect" => "shopping.php?order_id=" . $orderId
    ]);
    
} catch (Exception $e) {
    if (isset($db)) $db->rollBack();
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
