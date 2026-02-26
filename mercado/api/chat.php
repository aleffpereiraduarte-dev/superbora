<?php
/**
 * GET/POST /mercado/api/chat.php
 * Chat do pedido com proteção XSS
 */
require_once __DIR__ . "/config.php";

try {
    $db = getDB();
    
    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $order_id = intval($_GET["order_id"] ?? 0);
        
        if (!$order_id) {
            response(false, null, "order_id obrigatório", 400);
        }
        
        $mensagens = $db->query("SELECT * FROM om_market_order_chat WHERE order_id = $order_id ORDER BY created_at ASC")->fetchAll();
        
        // Sanitizar mensagens para exibição
        foreach ($mensagens as &$msg) {
            $msg["message"] = htmlspecialchars($msg["message"] ?? "", ENT_QUOTES, "UTF-8");
        }
        
        response(true, ["mensagens" => $mensagens, "total" => count($mensagens)]);
    }
    
    // POST - Enviar mensagem
    $input = json_decode(file_get_contents("php://input"), true) ?: $_POST;
    
    $order_id = intval($input["order_id"] ?? 0);
    $remetente_tipo = $input["remetente_tipo"] ?? "";
    $remetente_id = intval($input["remetente_id"] ?? 0);
    $mensagem = trim($input["mensagem"] ?? "");
    
    // Validações
    if (!$order_id) {
        response(false, null, "order_id obrigatório", 400);
    }
    
    if (empty($mensagem)) {
        response(false, null, "Mensagem não pode estar vazia", 400);
    }
    
    if (strlen($mensagem) > 1000) {
        response(false, null, "Mensagem muito longa (máximo 1000 caracteres)", 400);
    }
    
    // Sanitizar mensagem antes de salvar (proteção XSS)
    $mensagem = htmlspecialchars($mensagem, ENT_QUOTES, "UTF-8");
    
    // Salvar
    $stmt = $db->prepare("INSERT INTO om_market_order_chat (order_id, sender_type, sender_id, message, created_at) 
                          VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$order_id, $remetente_tipo, $remetente_id, $mensagem]);
    
    $chat_id = $db->lastInsertId();
    
    response(true, [
        "chat_id" => $chat_id,
        "order_id" => $order_id,
        "mensagem" => $mensagem
    ], "Mensagem enviada!");
    
} catch (Exception $e) {
    response(false, null, $e->getMessage(), 500);
}
