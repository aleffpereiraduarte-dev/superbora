<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
/**
 * API: Finalizar Compras
 * POST /api/complete-shopping.php
 */
header("Content-Type: application/json");

session_start();

$workerId = $_SESSION["worker_id"] ?? 0;
if (!$workerId) {
    echo json_encode(["success" => false, "error" => "Não autenticado"]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["success" => false, "error" => "Método não permitido"]);
    exit;
}

try {
    $pdo = getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $data = json_decode(file_get_contents("php://input"), true);
    $orderId = $data["order_id"] ?? 0;
    
    if (!$orderId) {
        throw new Exception("ID do pedido não informado");
    }
    
    // Verificar pedido
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND worker_id = ?");
    $stmt->execute([$orderId, $workerId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        throw new Exception("Pedido não encontrado");
    }
    
    if (!in_array($order["status"], ["paid", "shopping"])) {
        throw new Exception("Pedido não está em status de compras");
    }
    
    // Verificar se todos os itens foram escaneados
    $stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN scanned_at IS NOT NULL THEN 1 ELSE 0 END) as scanned 
                           FROM om_market_order_items WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Permitir finalizar mesmo sem todos os itens (pode ter item indisponível)
    // if ($items["scanned"] < $items["total"]) {
    //     throw new Exception("Escaneie todos os itens antes de finalizar");
    // }
    
    // Gerar código de handoff
    $handoffCode = strtoupper(substr(md5($orderId . time()), 0, 8));
    
    // Buscar worker para ver se é Full Service
    $stmt = $pdo->prepare("SELECT worker_type, work_mode FROM om_market_workers WHERE worker_id = ?");
    $stmt->execute([$workerId]);
    $worker = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $isFullBoth = ($worker["worker_type"] === "full_service" && $worker["work_mode"] === "both");
    
    // Atualizar status
    $newStatus = "ready_for_pickup"; // Aguardando driver por padrão
    
    $stmt = $pdo->prepare("UPDATE om_market_orders SET 
        status = ?, 
        handoff_code = ?,
        shopping_completed_at = NOW()
        WHERE order_id = ?");
    $stmt->execute([$newStatus, $handoffCode, $orderId]);
    
    // Se for Full Service com modo "both", perguntar se quer entregar
    $redirect = "handoff.php?order=$orderId";
    if ($isFullBoth) {
        $redirect = "delivery-choice.php?order=$orderId";
    }
    
    echo json_encode([
        "success" => true,
        "message" => "Compras finalizadas!",
        "handoff_code" => $handoffCode,
        "redirect" => $redirect,
        "is_full_service" => $isFullBoth
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}