<?php
/**
 * 🛒 HELPER DE COMPRAS PARA ONE
 */

/**
 * Processar pedido de compra via chat
 */
function processShoppingRequest($pdo, $customer_id, $message, $conversation_id) {
    // Validar parâmetros
    if (!is_numeric($customer_id) || $customer_id <= 0) {
        return array('error' => 'Invalid customer ID');
    }
    if (empty($message) || strlen($message) > 1000) {
        return array('error' => 'Invalid message');
    }
    
    // Extrair produto da mensagem
    $product_name = extractProductFromMessage($message);
    
    if (!$product_name) {
        return array(
            "text" => "O que você quer comprar? 🛒\n\nMe fala o produto que eu busco pra você!",
            "type" => "text"
        );
    }
    
    // Buscar produto
    $result = searchProductForOne($pdo, $customer_id, $product_name);
    
    if (!$result["found"]) {
        $safe_product_name = htmlspecialchars($product_name, ENT_QUOTES, 'UTF-8');
        return array(
            "text" => "Hmm, não encontrei \"$safe_product_name\" disponível no momento. 😕\n\nQuer que eu busque algo parecido?",
            "type" => "text"
        );
    }
    
    $product = $result["product"];
    $is_preferred = $result["is_preferred"];
    
    // Verificar se tem quick buy configurado
    $settings = getQuickBuySettingsForOne($pdo, $customer_id);
    $ready = ($settings["default_card_id"] && $settings["default_address_id"]);
    
    $text = "";
    
    if ($is_preferred) {
        $text = "Achei! 🎯 " . $product["name"] . "\n";
        $text .= "O de sempre, né? 😉\n\n";
    } else {
        $text = "Encontrei! 🛒 **" . $product["name"] . "**\n\n";
    }
    
    $text .= "💰 R$ " . number_format($product["price"], 2, ",", ".");
    
    if ($ready) {
        $text .= "\n\n✨ Quer que eu peça agora?";
        
        return array(
            "text" => $text,
            "type" => "product_card",
            "product" => $product,
            "quick_replies" => array(
                array("id" => "buy_now_" . $product["product_id"], "label" => "✅ Comprar agora!"),
                array("id" => "add_cart_" . $product["product_id"], "label" => "🛒 Adicionar ao carrinho"),
                array("id" => "search_more", "label" => "🔍 Ver outros")
            )
        );
    } else {
        $text .= "\n\n📝 Adiciono ao carrinho?";
        
        return array(
            "text" => $text,
            "type" => "product_card",
            "product" => $product,
            "quick_replies" => array(
                array("id" => "add_cart_" . $product["product_id"], "label" => "🛒 Adicionar"),
                array("id" => "setup_quickbuy", "label" => "⚡ Configurar compra rápida"),
                array("id" => "search_more", "label" => "🔍 Ver outros")
            )
        );
    }
}

/**
 * Extrair produto da mensagem
 */
function extractProductFromMessage($message) {
    static $patterns = array(
        "/(?:compra|pede|quero|preciso de?|tô sem|to sem|acabou o?)\s+(.+)/i",
        "/(?:me )?(?:manda|traz|pega)\s+(.+)/i"
    );
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $message, $matches)) {
            return trim($matches[1]);
        }
    }
    
    return null;
}

/**
 * Buscar produto com preferências
 */
function searchProductForOne($pdo, $customer_id, $query) {
    $query_lower = mb_strtolower($query);
    
    // Primeiro, verificar se tem preferência
    $stmt = $pdo->prepare("
        SELECT f.product_id, f.product_name, p.price, p.image
        FROM om_one_favorite_products f
        JOIN oc_product p ON f.product_id = p.product_id
        WHERE f.customer_id = ? AND (LOWER(f.product_name) LIKE ? OR LOWER(f.category) LIKE ?)
        AND p.status = '1' AND p.quantity > 0
        ORDER BY f.purchase_count DESC
        LIMIT 1
    ");
    $query_param = '%' . $query_lower . '%';
    $stmt->execute(array($customer_id, $query_param, $query_param));
    $preferred = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($preferred) {
        return array(
            "found" => true,
            "product" => array(
                "product_id" => $preferred["product_id"],
                "name" => $preferred["product_name"],
                "price" => $preferred["price"],
                "image" => $preferred["image"]
            ),
            "is_preferred" => true
        );
    }
    
    // Buscar no catálogo
    $stmt = $pdo->prepare("
        SELECT p.product_id, pd.name, p.price, p.image
        FROM oc_product p
        JOIN oc_product_description pd ON p.product_id = pd.product_id
        WHERE pd.name LIKE ? AND p.status = '1' AND p.quantity > 0
        ORDER BY 
            CASE WHEN pd.name = ? THEN 1 ELSE 2 END,
            p.sort_order ASC
        LIMIT 1
    ");
    $query_param = '%' . $query_lower . '%';
    $stmt->execute(array($query_param, $query));
    $stmt->execute(array("%$query_lower%"));
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($product) {
        return array(
            "found" => true,
            "product" => $product,
            "is_preferred" => false
        );
    }
    
    return array("found" => false);
}

/**
 * Obter configurações de quick buy
 */
function getQuickBuySettingsForOne($pdo, $customer_id) {
    $stmt = $pdo->prepare("SELECT * FROM om_one_quick_buy_settings WHERE customer_id = ?");
    $stmt->execute(array($customer_id));
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$settings) {
        return array(
            "default_card_id" => null,
            "default_address_id" => null
        );
    }
    
    return $settings;
}

/**
 * Executar compra rápida
 */
function executeQuickBuy($pdo, $customer_id, $product_id, $quantity = 1, $conversation_id = null) {
    // Chamar API de quick-buy
    $data = array(
        "action" => "quick_buy_product",
        "customer_id" => $customer_id,
        "product_id" => $product_id,
        "quantity" => $quantity,
        "conversation_id" => $conversation_id
    );
    
    // Validar host
    $allowed_host = 'localhost'; // ou domínio específico
    if ($_SERVER["HTTP_HOST"] !== $allowed_host) {
        return array('error' => 'Invalid host');
    }
    
    // Fazer request interno
    $ch = curl_init("http://" . $allowed_host . "/mercado/api/quick-buy.php");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response === false || !empty($curl_error)) {
        return array('error' => 'Request failed: ' . $curl_error);
    }
    
    if ($http_code !== 200) {
        return array('error' => 'HTTP error: ' . $http_code);
    }
    
    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return array('error' => 'Invalid JSON response');
    }
    
    return $decoded;
}

/**
 * Formatar resposta de compra confirmada
 */
function formatOrderConfirmation($order) {
    $text = "✅ **Pedido confirmado!** 🎉\n\n";
    $text .= "📦 Pedido #" . $order["market_order_id"] . "\n\n";
    
    $text .= "💳 Cartão " . strtoupper($order["card"]["card_brand"]) . " ••••" . $order["card"]["card_last4"] . "\n";
    $text .= "📍 " . $order["address"]["street"] . ", " . $order["address"]["number"] . "\n\n";
    
    if ($order["credits_used"] > 0) {
        $text .= "💰 Subtotal: R$ " . number_format($order["subtotal"], 2, ",", ".") . "\n";
        $text .= "🚚 Entrega: R$ " . number_format($order["delivery_fee"], 2, ",", ".") . "\n";
        $text .= "✨ Créditos: -R$ " . number_format($order["credits_used"], 2, ",", ".") . "\n";
        $text .= "━━━━━━━━━━━━━━━━\n";
    }
    
    $text .= "💵 **Total: R$ " . number_format($order["total"], 2, ",", ".") . "**\n\n";
    $text .= "⏱️ Chega em ~" . $order["estimated_time"];
    
    return array(
        "text" => $text,
        "type" => "order_confirm",
        "order" => $order,
        "quick_replies" => array(
            array("id" => "track_order_" . $order["market_order_id"], "label" => "📦 Acompanhar"),
            array("id" => "continue", "label" => "👍 Valeu!")
        )
    );
}
