<?php
/**
 * 🔑 FUNÇÕES AUXILIARES DO SISTEMA DE PEDIDOS
 */

/**
 * Gera código de entrega único
 */
function gerarCodigoEntrega($order_id) {
    $palavras = [
        "BANANA", "LARANJA", "MORANGO", "ABACAXI", "MELANCIA", 
        "UVA", "MANGA", "LIMAO", "COCO", "PERA",
        "GOIABA", "KIWI", "MELAO", "PESSEGO", "AMORA"
    ];
    $numeros = str_pad($order_id % 1000, 3, "0", STR_PAD_LEFT);
    $palavra = $palavras[($order_id * 7) % count($palavras)];
    return $palavra . "-" . $numeros;
}

/**
 * Atribui shopper ao pedido
 */
function atribuirShopperPedido($pdo, $order_id, $partner_id) {
    // Buscar shopper disponível
    $sql = "SELECT shopper_id, name FROM om_order_shoppers 
            WHERE partner_id = :partner_id AND status = \"available\" 
            ORDER BY RANDOM() LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":partner_id" => $partner_id]);
    $shopper = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$shopper) {
        // Pegar qualquer um se não tiver disponível
        $sql = "SELECT shopper_id, name FROM om_order_shoppers 
                WHERE partner_id = :partner_id LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([":partner_id" => $partner_id]);
        $shopper = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$shopper) return false;
    
    $delivery_code = gerarCodigoEntrega($order_id);
    
    // Criar assignment
    $sql = "INSERT INTO om_order_assignments (order_id, shopper_id, delivery_code, status) 
            VALUES (:order_id, :shopper_id, :delivery_code, \"active\")
            ON DUPLICATE KEY UPDATE shopper_id = :shopper_id, delivery_code = :delivery_code";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ":order_id" => $order_id,
        ":shopper_id" => $shopper["shopper_id"],
        ":delivery_code" => $delivery_code
    ]);
    
    // Atualizar pedido
    $sql = "UPDATE om_market_orders SET shopper_id = :shopper_id, delivery_code = :delivery_code WHERE order_id = :order_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ":shopper_id" => $shopper["shopper_id"],
        ":delivery_code" => $delivery_code,
        ":order_id" => $order_id
    ]);
    
    // Enviar mensagem automática de boas-vindas
    enviarMensagemAutomatica($pdo, $order_id, $shopper["shopper_id"], $shopper["name"]);
    
    return [
        "shopper_id" => $shopper["shopper_id"],
        "shopper_name" => $shopper["name"],
        "delivery_code" => $delivery_code
    ];
}

/**
 * Envia mensagem automática de boas-vindas
 */
function enviarMensagemAutomatica($pdo, $order_id, $shopper_id, $shopper_name) {
    // Buscar nome do cliente
    $sql = "SELECT c.firstname FROM om_market_orders o 
            JOIN oc_customer c ON o.customer_id = c.customer_id 
            WHERE o.order_id = :order_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":order_id" => $order_id]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    $customer_name = $cliente["firstname"] ?? "Cliente";
    
    $mensagem = "Oi {$customer_name}! 👋\n\n";
    $mensagem .= "Sou a {$shopper_name} e vou cuidar da sua compra!\n";
    $mensagem .= "Já já ela será entregue, qualquer coisa pode me chamar tá?\n\n";
    $mensagem .= "Se eu precisar falar com você, usarei esse mesmo chat.\n";
    $mensagem .= "Fica de olho nas notificações! 😊";
    
    $sql = "INSERT INTO om_order_chat (order_id, sender_type, sender_id, sender_name, message, message_type) 
            VALUES (:order_id, \"shopper\", :shopper_id, :shopper_name, :message, \"text\")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ":order_id" => $order_id,
        ":shopper_id" => $shopper_id,
        ":shopper_name" => $shopper_name,
        ":message" => $mensagem
    ]);
    
    return true;
}

/**
 * Marcar pedido como entregue
 */
function marcarPedidoEntregue($pdo, $order_id) {
    $chat_expires = date("Y-m-d H:i:s", strtotime("+60 minutes"));
    
    $sql = "UPDATE om_order_assignments 
            SET status = \"completed\", delivered_at = NOW(), chat_expires_at = :expires 
            WHERE order_id = :order_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":order_id" => $order_id, ":expires" => $chat_expires]);
    
    $sql = "UPDATE om_market_orders SET status = \"delivered\" WHERE order_id = :order_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":order_id" => $order_id]);
    
    return true;
}

/**
 * Validar código de entrega
 */
function validarCodigoEntrega($pdo, $order_id, $codigo) {
    $sql = "SELECT delivery_code FROM om_order_assignments WHERE order_id = :order_id AND status = \"active\"";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":order_id" => $order_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && strtoupper($result["delivery_code"]) === strtoupper($codigo)) {
        marcarPedidoEntregue($pdo, $order_id);
        return true;
    }
    return false;
}
