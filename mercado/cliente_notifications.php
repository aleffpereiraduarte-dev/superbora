<?php
/**
 * 🔔 HELPER DE NOTIFICAÇÕES PARA CLIENTE
 */

function notificarCliente($pdo, $order_id, $tipo, $dados_extra = array()) {
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
    $stmt->execute(array($order_id));
    $pedido = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$pedido) return false;
    
    $customer_id = isset($pedido["customer_id"]) ? $pedido["customer_id"] : 0;
    
    $notificacoes = array(
        "shopper_aceito" => array("icon" => "🛒", "title" => "Shopper a caminho!", "body" => "{shopper_name} está indo separar seu pedido", "priority" => "high"),
        "separando" => array("icon" => "📦", "title" => "Separando seu pedido", "body" => "Seus produtos estão sendo selecionados", "priority" => "normal"),
        "pedido_pronto" => array("icon" => "✅", "title" => "Pedido pronto!", "body" => "Aguardando entregador", "priority" => "high"),
        "delivery_aceito" => array("icon" => "🚴", "title" => "Entregador a caminho!", "body" => "{delivery_name} pegou seu pedido", "priority" => "urgent"),
        "saiu_entrega" => array("icon" => "🛵", "title" => "Saiu para entrega!", "body" => "Seu pedido está a caminho", "priority" => "urgent"),
        "chegando" => array("icon" => "📍", "title" => "Entregador chegando!", "body" => "O entregador está muito próximo", "priority" => "urgent"),
        "entregue" => array("icon" => "🎉", "title" => "Pedido entregue!", "body" => "Obrigado! Avalie sua experiência", "priority" => "high"),
        "ajuste_valor" => array("icon" => "💵", "title" => "Ajuste no pedido", "body" => "{reason}: R$ {amount}", "priority" => "high"),
        "reembolso" => array("icon" => "💰", "title" => "Reembolso processado!", "body" => "R$ {amount} foi adicionado ao seu saldo", "priority" => "high"),
        "mensagem_chat" => array("icon" => "💬", "title" => "Nova mensagem", "body" => "Você tem uma nova mensagem", "priority" => "normal")
    );
    
    if (!isset($notificacoes[$tipo])) return false;
    
    $notif = $notificacoes[$tipo];
    $body = $notif["body"];
    
    if (isset($dados_extra["shopper_name"])) {
        $body = str_replace("{shopper_name}", $dados_extra["shopper_name"], $body);
    }
    if (isset($dados_extra["delivery_name"])) {
        $body = str_replace("{delivery_name}", $dados_extra["delivery_name"], $body);
    }
    
    $push_data = array("type" => "order_update", "order_id" => $order_id, "status_type" => $tipo, "url" => "/mercado/pedido.php?id=" . $order_id);
    
    try {
        $stmt = $pdo->prepare("INSERT INTO om_notifications_queue (user_type, user_id, title, body, icon, data, priority, sound, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute(array("customer", $customer_id, $notif["title"], $body, $notif["icon"], json_encode($push_data), $notif["priority"], $notif["priority"] === "urgent" ? "alert" : "default", "pending"));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function enviarMensagemSistema($pdo, $order_id, $mensagem) {
    try {
        $stmt = $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)");
        $stmt->execute(array($order_id, "system", 0, $mensagem));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function notificarShopperAceitou($pdo, $order_id, $shopper_id) {
    $stmt = $pdo->prepare("SELECT name FROM om_market_shoppers WHERE shopper_id = ?");
    $stmt->execute(array($shopper_id));
    $shopper = $stmt->fetch();
    $shopper_name = $shopper ? $shopper["name"] : "Shopper";
    $first_name = explode(" ", $shopper_name)[0];
    
    notificarCliente($pdo, $order_id, "shopper_aceito", array("shopper_name" => $first_name));
    enviarMensagemSistema($pdo, $order_id, "🛒 $first_name está indo separar seu pedido!");
}

function notificarPedidoPronto($pdo, $order_id) {
    notificarCliente($pdo, $order_id, "pedido_pronto");
    enviarMensagemSistema($pdo, $order_id, "✅ Seu pedido está pronto! Aguardando entregador...");
}

function notificarDeliveryAceitou($pdo, $order_id, $delivery_id) {
    $stmt = $pdo->prepare("SELECT name FROM om_market_deliveries WHERE delivery_id = ?");
    $stmt->execute(array($delivery_id));
    $delivery = $stmt->fetch();
    $delivery_name = $delivery ? $delivery["name"] : "Entregador";
    $first_name = explode(" ", $delivery_name)[0];
    
    notificarCliente($pdo, $order_id, "delivery_aceito", array("delivery_name" => $first_name));
    enviarMensagemSistema($pdo, $order_id, "🚴 $first_name está a caminho para buscar seu pedido!");
}

function notificarSaiuEntrega($pdo, $order_id) {
    notificarCliente($pdo, $order_id, "saiu_entrega");
    enviarMensagemSistema($pdo, $order_id, "🛵 Seu pedido saiu para entrega!");
}

function notificarEntregue($pdo, $order_id) {
    notificarCliente($pdo, $order_id, "entregue");
    enviarMensagemSistema($pdo, $order_id, "🎉 Pedido entregue! Obrigado por comprar com OneMundo!");
}
