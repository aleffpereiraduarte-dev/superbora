<?php
/**
 * 🛒 API DE COMPRA RÁPIDA - ONE
 */
header("Content-Type: application/json; charset=utf-8");
session_start();

require_once dirname(__DIR__) . '/config/database.php';

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die(json_encode(array("success" => false, "error" => "DB Error")));
}

$input = json_decode(file_get_contents("php://input"), true);
$action = isset($input["action"]) ? $input["action"] : (isset($_GET["action"]) ? $_GET["action"] : "");
$customer_id = isset($input["customer_id"]) ? intval($input["customer_id"]) : (isset($_SESSION["customer_id"]) ? intval($_SESSION["customer_id"]) : 0);

switch ($action) {
    
    // ══════════════════════════════════════════════════════════════════════════
    // BUSCAR PRODUTO
    // ══════════════════════════════════════════════════════════════════════════
    case "search_product":
        $query = isset($input["query"]) ? trim($input["query"]) : "";
        $limit = isset($input["limit"]) ? intval($input["limit"]) : 5;
        
        if (strlen($query) < 2) {
            echo json_encode(array("success" => false, "error" => "Busca muito curta"));
            exit;
        }
        
        // Verificar preferências do cliente
        $preferred = getPreferredProduct($pdo, $customer_id, $query);
        
        // Buscar produtos
        $stmt = $pdo->prepare("
            SELECT p.product_id, pd.name, p.price, p.image, p.quantity as stock
            FROM oc_product p
            JOIN oc_product_description pd ON p.product_id = pd.product_id
            WHERE pd.name LIKE ? AND p.status = '1' AND p.quantity > 0
            ORDER BY p.sort_order ASC, pd.name ASC
            LIMIT ?
        ");
        $stmt->execute(array("%$query%", $limit));
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Se tiver preferência, destacar
        if ($preferred) {
            foreach ($products as &$prod) {
                $prod["is_preferred"] = ($prod["product_id"] == $preferred["product_id"]);
            }
            // Mover preferido para o topo
            usort($products, function($a, $b) {
                return ($b["is_preferred"] ?? 0) - ($a["is_preferred"] ?? 0);
            });
        }
        
        echo json_encode(array(
            "success" => true,
            "products" => $products,
            "preferred" => $preferred
        ));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // ADICIONAR AO CARRINHO ONE
    // ══════════════════════════════════════════════════════════════════════════
    case "add_to_cart":
        $product_id = isset($input["product_id"]) ? intval($input["product_id"]) : 0;
        $quantity = isset($input["quantity"]) ? intval($input["quantity"]) : 1;
        $reason = isset($input["reason"]) ? $input["reason"] : null;
        $recipe_id = isset($input["recipe_id"]) ? intval($input["recipe_id"]) : null;
        $conversation_id = isset($input["conversation_id"]) ? intval($input["conversation_id"]) : null;
        
        if (!$product_id) {
            echo json_encode(array("success" => false, "error" => "Produto inválido"));
            exit;
        }
        
        // Buscar produto
        $stmt = $pdo->prepare("
            SELECT p.product_id, pd.name, p.price, p.image
            FROM oc_product p
            JOIN oc_product_description pd ON p.product_id = pd.product_id
            WHERE p.product_id = ?
        ");
        $stmt->execute(array($product_id));
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            echo json_encode(array("success" => false, "error" => "Produto não encontrado"));
            exit;
        }
        
        // Obter ou criar carrinho
        $cart_id = getOrCreateCart($pdo, $customer_id, $conversation_id);
        
        // Verificar se já existe no carrinho
        $stmt = $pdo->prepare("SELECT item_id, quantity FROM om_one_cart_items WHERE cart_id = ? AND product_id = ?");
        $stmt->execute(array($cart_id, $product_id));
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Atualizar quantidade
            $new_qty = $existing["quantity"] + $quantity;
            $stmt = $pdo->prepare("UPDATE om_one_cart_items SET quantity = ?, total_price = ? WHERE item_id = ?");
            $stmt->execute(array($new_qty, $product["price"] * $new_qty, $existing["item_id"]));
        } else {
            // Inserir novo
            $stmt = $pdo->prepare("
                INSERT INTO om_one_cart_items (cart_id, product_id, product_name, product_image, quantity, unit_price, total_price, added_reason, recipe_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute(array(
                $cart_id, $product_id, $product["name"], $product["image"],
                $quantity, $product["price"], $product["price"] * $quantity,
                $reason, $recipe_id
            ));
        }
        
        // Atualizar preferência
        learnProductPreference($pdo, $customer_id, $product_id, $product["name"]);
        
        // Retornar carrinho atualizado
        $cart = getCartSummary($pdo, $cart_id);
        
        echo json_encode(array(
            "success" => true,
            "cart" => $cart,
            "added" => array(
                "name" => $product["name"],
                "quantity" => $quantity,
                "price" => $product["price"]
            )
        ));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // VER CARRINHO
    // ══════════════════════════════════════════════════════════════════════════
    case "get_cart":
        $cart_id = getActiveCart($pdo, $customer_id);
        
        if (!$cart_id) {
            echo json_encode(array("success" => true, "cart" => null, "empty" => true));
            exit;
        }
        
        $cart = getCartSummary($pdo, $cart_id);
        echo json_encode(array("success" => true, "cart" => $cart));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // REMOVER DO CARRINHO
    // ══════════════════════════════════════════════════════════════════════════
    case "remove_from_cart":
        $item_id = isset($input["item_id"]) ? intval($input["item_id"]) : 0;
        
        $stmt = $pdo->prepare("DELETE FROM om_one_cart_items WHERE item_id = ?");
        $stmt->execute(array($item_id));
        
        $cart_id = getActiveCart($pdo, $customer_id);
        $cart = $cart_id ? getCartSummary($pdo, $cart_id) : null;
        
        echo json_encode(array("success" => true, "cart" => $cart));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // LIMPAR CARRINHO
    // ══════════════════════════════════════════════════════════════════════════
    case "clear_cart":
        $cart_id = getActiveCart($pdo, $customer_id);
        if ($cart_id) {
            $pdo->prepare("DELETE FROM om_one_cart_items WHERE cart_id = ?")->execute(array($cart_id));
            $pdo->prepare("UPDATE om_one_cart SET status = \"abandoned\" WHERE cart_id = ?")->execute(array($cart_id));
        }
        echo json_encode(array("success" => true));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // OBTER CONFIGURAÇÕES DE COMPRA RÁPIDA
    // ══════════════════════════════════════════════════════════════════════════
    case "get_quick_buy_settings":
        $settings = getQuickBuySettings($pdo, $customer_id);
        
        // Buscar cartão e endereço padrão
        $card = null;
        $address = null;
        
        if ($settings["default_card_id"]) {
            $stmt = $pdo->prepare("SELECT card_id, card_brand, card_last4, card_holder FROM om_one_saved_cards WHERE card_id = ? AND is_active = 1");
            $stmt->execute(array($settings["default_card_id"]));
            $card = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if ($settings["default_address_id"]) {
            $stmt = $pdo->prepare("SELECT * FROM om_one_saved_addresses WHERE address_id = ? AND is_active = 1");
            $stmt->execute(array($settings["default_address_id"]));
            $address = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Créditos disponíveis
        $stmt = $pdo->prepare("SELECT credit_balance FROM om_market_customers WHERE customer_id = ?");
        $stmt->execute(array($customer_id));
        $credits = floatval($stmt->fetchColumn() ?: 0);
        
        echo json_encode(array(
            "success" => true,
            "settings" => $settings,
            "default_card" => $card,
            "default_address" => $address,
            "credits_available" => $credits,
            "ready_for_one_click" => ($card && $address && $settings["one_click_enabled"])
        ));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // COMPRA 1-CLICK! 🚀
    // ══════════════════════════════════════════════════════════════════════════
    case "one_click_buy":
        $cart_id = getActiveCart($pdo, $customer_id);
        $pin = isset($input["pin"]) ? $input["pin"] : null;
        $conversation_id = isset($input["conversation_id"]) ? intval($input["conversation_id"]) : null;
        
        if (!$cart_id) {
            echo json_encode(array("success" => false, "error" => "Carrinho vazio"));
            exit;
        }
        
        $cart = getCartSummary($pdo, $cart_id);
        $settings = getQuickBuySettings($pdo, $customer_id);
        
        // Verificar se está configurado
        if (!$settings["default_card_id"] || !$settings["default_address_id"]) {
            echo json_encode(array(
                "success" => false, 
                "error" => "Configure seu cartão e endereço primeiro",
                "needs_setup" => true
            ));
            exit;
        }
        
        // Verificar limite diário
        $daily_remaining = $settings["daily_limit"] - $settings["daily_spent"];
        if ($cart["total"] > $daily_remaining) {
            echo json_encode(array(
                "success" => false,
                "error" => "Limite diário excedido. Disponível: R$ " . number_format($daily_remaining, 2, ",", "."),
                "limit_exceeded" => true
            ));
            exit;
        }
        
        // Verificar PIN se necessário
        if ($settings["require_pin"] && $cart["total"] >= $settings["pin_threshold"]) {
            if (!$pin) {
                echo json_encode(array(
                    "success" => false,
                    "needs_pin" => true,
                    "message" => "Digite seu PIN para confirmar"
                ));
                exit;
            }
            
            if (!password_verify($pin, $settings["pin_hash"])) {
                echo json_encode(array("success" => false, "error" => "PIN incorreto"));
                exit;
            }
        }
        
        // Aplicar créditos automaticamente se configurado
        $credits_used = 0;
        if ($settings["auto_apply_credits"]) {
            $stmt = $pdo->prepare("SELECT credit_balance FROM om_market_customers WHERE customer_id = ?");
            $stmt->execute(array($customer_id));
            $credits_available = floatval($stmt->fetchColumn() ?: 0);
            
            if ($credits_available > 0) {
                $credits_used = min($credits_available, $cart["total"]);
            }
        }
        
        // Calcular total final
        $delivery_fee = 5.00; // Taxa fixa por enquanto
        $final_total = $cart["total"] + $delivery_fee - $credits_used;
        
        $pdo->beginTransaction();
        
        try {
            // Criar pedido ONE
            $stmt = $pdo->prepare("
                INSERT INTO om_one_orders 
                (customer_id, conversation_id, card_id, address_id, subtotal, delivery_fee, credits_used, total, order_type, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute(array(
                $customer_id, $conversation_id,
                $settings["default_card_id"], $settings["default_address_id"],
                $cart["total"], $delivery_fee, $credits_used, $final_total,
                "quick_buy", "processing"
            ));
            $one_order_id = $pdo->lastInsertId();
            
            // Criar pedido no sistema de mercado
            $market_order_id = createMarketOrder($pdo, $customer_id, $cart, $settings, $delivery_fee, $credits_used);
            
            // Vincular pedidos
            $pdo->prepare("UPDATE om_one_orders SET market_order_id = ?, status = \"confirmed\" WHERE one_order_id = ?")
                ->execute(array($market_order_id, $one_order_id));
            
            // Atualizar carrinho
            $pdo->prepare("UPDATE om_one_cart SET status = \"converted\" WHERE cart_id = ?")->execute(array($cart_id));
            
            // Atualizar créditos usados
            if ($credits_used > 0) {
                $pdo->prepare("UPDATE om_market_customers SET credit_balance = credit_balance - ? WHERE customer_id = ?")
                    ->execute(array($credits_used, $customer_id));
            }
            
            // Atualizar gasto diário
            $pdo->prepare("UPDATE om_one_quick_buy_settings SET daily_spent = daily_spent + ? WHERE customer_id = ?")
                ->execute(array($final_total, $customer_id));
            
            // Simular pagamento (em produção, chamar gateway)
            $pdo->prepare("UPDATE om_one_orders SET payment_status = \"captured\", payment_reference = ? WHERE one_order_id = ?")
                ->execute(array("pay_" . uniqid(), $one_order_id));
            
            $pdo->commit();
            
            // Buscar dados para resposta
            $stmt = $pdo->prepare("SELECT card_brand, card_last4 FROM om_one_saved_cards WHERE card_id = ?");
            $stmt->execute(array($settings["default_card_id"]));
            $card = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $pdo->prepare("SELECT street, number, neighborhood FROM om_one_saved_addresses WHERE address_id = ?");
            $stmt->execute(array($settings["default_address_id"]));
            $address = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode(array(
                "success" => true,
                "order" => array(
                    "one_order_id" => $one_order_id,
                    "market_order_id" => $market_order_id,
                    "subtotal" => $cart["total"],
                    "delivery_fee" => $delivery_fee,
                    "credits_used" => $credits_used,
                    "total" => $final_total,
                    "items_count" => $cart["items_count"],
                    "card" => $card,
                    "address" => $address,
                    "estimated_time" => "40-50 min"
                ),
                "message" => "Pedido confirmado! 🎉"
            ));
            
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(array("success" => false, "error" => "Erro ao processar: " . $e->getMessage()));
        }
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // COMPRA DIRETA (sem carrinho)
    // ══════════════════════════════════════════════════════════════════════════
    case "quick_buy_product":
        $product_id = isset($input["product_id"]) ? intval($input["product_id"]) : 0;
        $quantity = isset($input["quantity"]) ? intval($input["quantity"]) : 1;
        $conversation_id = isset($input["conversation_id"]) ? intval($input["conversation_id"]) : null;
        
        if (!$product_id) {
            echo json_encode(array("success" => false, "error" => "Produto inválido"));
            exit;
        }
        
        // Criar carrinho, adicionar produto e comprar
        $cart_id = getOrCreateCart($pdo, $customer_id, $conversation_id);
        
        // Limpar carrinho anterior
        $pdo->prepare("DELETE FROM om_one_cart_items WHERE cart_id = ?")->execute(array($cart_id));
        
        // Buscar produto
        $stmt = $pdo->prepare("
            SELECT p.product_id, pd.name, p.price, p.image
            FROM oc_product p
            JOIN oc_product_description pd ON p.product_id = pd.product_id
            WHERE p.product_id = ?
        ");
        $stmt->execute(array($product_id));
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            echo json_encode(array("success" => false, "error" => "Produto não encontrado"));
            exit;
        }
        
        // Adicionar ao carrinho
        $stmt = $pdo->prepare("
            INSERT INTO om_one_cart_items (cart_id, product_id, product_name, product_image, quantity, unit_price, total_price, added_reason)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute(array(
            $cart_id, $product_id, $product["name"], $product["image"],
            $quantity, $product["price"], $product["price"] * $quantity,
            "quick_buy"
        ));
        
        // Executar compra
        $input["action"] = "one_click_buy";
        $input["conversation_id"] = $conversation_id;
        
        // Redirecionar para one_click_buy (simulação)
        $_POST = $input;
        
        // Por simplicidade, retornar preview primeiro
        $settings = getQuickBuySettings($pdo, $customer_id);
        
        if (!$settings["default_card_id"] || !$settings["default_address_id"]) {
            echo json_encode(array(
                "success" => false,
                "error" => "Configure seu cartão e endereço primeiro",
                "needs_setup" => true,
                "product" => $product
            ));
            exit;
        }
        
        // Buscar dados para preview
        $stmt = $pdo->prepare("SELECT card_brand, card_last4 FROM om_one_saved_cards WHERE card_id = ?");
        $stmt->execute(array($settings["default_card_id"]));
        $card = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("SELECT street, number, neighborhood FROM om_one_saved_addresses WHERE address_id = ?");
        $stmt->execute(array($settings["default_address_id"]));
        $address = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Créditos
        $stmt = $pdo->prepare("SELECT credit_balance FROM om_market_customers WHERE customer_id = ?");
        $stmt->execute(array($customer_id));
        $credits = floatval($stmt->fetchColumn() ?: 0);
        
        $delivery_fee = 5.00;
        $subtotal = $product["price"] * $quantity;
        $credits_used = $settings["auto_apply_credits"] ? min($credits, $subtotal + $delivery_fee) : 0;
        $total = $subtotal + $delivery_fee - $credits_used;
        
        echo json_encode(array(
            "success" => true,
            "ready_to_buy" => true,
            "preview" => array(
                "product" => $product,
                "quantity" => $quantity,
                "subtotal" => $subtotal,
                "delivery_fee" => $delivery_fee,
                "credits_used" => $credits_used,
                "total" => $total,
                "card" => $card,
                "address" => $address
            )
        ));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // CONFIRMAR COMPRA DIRETA
    // ══════════════════════════════════════════════════════════════════════════
    case "confirm_quick_buy":
        // Executar compra 1-click
        $cart_id = getActiveCart($pdo, $customer_id);
        if (!$cart_id) {
            echo json_encode(array("success" => false, "error" => "Carrinho não encontrado"));
            exit;
        }
        
        // Reutilizar lógica de one_click_buy
        $input["action"] = "one_click_buy";
        
        // Chamar recursivamente (em produção, refatorar para função)
        include(__FILE__);
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // CONFIGURAR QUICK BUY
    // ══════════════════════════════════════════════════════════════════════════
    case "setup_quick_buy":
        $card_id = isset($input["card_id"]) ? intval($input["card_id"]) : null;
        $address_id = isset($input["address_id"]) ? intval($input["address_id"]) : null;
        $daily_limit = isset($input["daily_limit"]) ? floatval($input["daily_limit"]) : 500;
        $require_pin = isset($input["require_pin"]) ? intval($input["require_pin"]) : 0;
        $pin = isset($input["pin"]) ? $input["pin"] : null;
        $auto_credits = isset($input["auto_apply_credits"]) ? intval($input["auto_apply_credits"]) : 1;
        
        $pin_hash = $pin ? password_hash($pin, PASSWORD_DEFAULT) : null;
        
        $stmt = $pdo->prepare("
            INSERT INTO om_one_quick_buy_settings 
            (customer_id, default_card_id, default_address_id, daily_limit, require_pin, pin_hash, auto_apply_credits)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            default_card_id = VALUES(default_card_id),
            default_address_id = VALUES(default_address_id),
            daily_limit = VALUES(daily_limit),
            require_pin = VALUES(require_pin),
            pin_hash = COALESCE(VALUES(pin_hash), pin_hash),
            auto_apply_credits = VALUES(auto_apply_credits)
        ");
        $stmt->execute(array($customer_id, $card_id, $address_id, $daily_limit, $require_pin, $pin_hash, $auto_credits));
        
        echo json_encode(array("success" => true, "message" => "Configurações salvas!"));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // HISTÓRICO DE PEDIDOS ONE
    // ══════════════════════════════════════════════════════════════════════════
    case "order_history":
        $limit = isset($_GET["limit"]) ? intval($_GET["limit"]) : 10;
        
        $stmt = $pdo->prepare("
            SELECT o.*, 
                   c.card_brand, c.card_last4,
                   a.street, a.number, a.neighborhood
            FROM om_one_orders o
            LEFT JOIN om_one_saved_cards c ON o.card_id = c.card_id
            LEFT JOIN om_one_saved_addresses a ON o.address_id = a.address_id
            WHERE o.customer_id = ?
            ORDER BY o.created_at DESC
            LIMIT ?
        ");
        $stmt->execute(array($customer_id, $limit));
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(array("success" => true, "orders" => $orders));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // REPETIR PEDIDO
    // ══════════════════════════════════════════════════════════════════════════
    case "reorder":
        $market_order_id = isset($input["order_id"]) ? intval($input["order_id"]) : 0;
        
        // Buscar itens do pedido anterior
        $stmt = $pdo->prepare("
            SELECT product_id, name, quantity, price
            FROM om_market_order_items
            WHERE order_id = ?
        ");
        $stmt->execute(array($market_order_id));
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($items)) {
            echo json_encode(array("success" => false, "error" => "Pedido não encontrado"));
            exit;
        }
        
        // Criar novo carrinho
        $cart_id = getOrCreateCart($pdo, $customer_id, null);
        $pdo->prepare("DELETE FROM om_one_cart_items WHERE cart_id = ?")->execute(array($cart_id));
        
        // Adicionar itens
        foreach ($items as $item) {
            $stmt = $pdo->prepare("
                INSERT INTO om_one_cart_items (cart_id, product_id, product_name, quantity, unit_price, total_price, added_reason)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute(array(
                $cart_id, $item["product_id"], $item["name"],
                $item["quantity"], $item["price"], $item["price"] * $item["quantity"],
                "reorder"
            ));
        }
        
        $cart = getCartSummary($pdo, $cart_id);
        
        echo json_encode(array(
            "success" => true,
            "cart" => $cart,
            "message" => "Itens adicionados ao carrinho!"
        ));
        break;
    
    default:
        echo json_encode(array("success" => false, "error" => "Invalid action"));
}

// ══════════════════════════════════════════════════════════════════════════════
// FUNÇÕES AUXILIARES
// ══════════════════════════════════════════════════════════════════════════════

function getOrCreateCart($pdo, $customer_id, $conversation_id = null) {
    $cart_id = getActiveCart($pdo, $customer_id);
    
    if ($cart_id) return $cart_id;
    
    $expires = date("Y-m-d H:i:s", strtotime("+24 hours"));
    $stmt = $pdo->prepare("INSERT INTO om_one_cart (customer_id, conversation_id, expires_at) VALUES (?, ?, ?)");
    $stmt->execute(array($customer_id, $conversation_id, $expires));
    
    return $pdo->lastInsertId();
}

function getActiveCart($pdo, $customer_id) {
    $stmt = $pdo->prepare("SELECT cart_id FROM om_one_cart WHERE customer_id = ? AND status = \"active\" ORDER BY created_at DESC LIMIT 1");
    $stmt->execute(array($customer_id));
    return $stmt->fetchColumn();
}

function getCartSummary($pdo, $cart_id) {
    $stmt = $pdo->prepare("SELECT * FROM om_one_cart_items WHERE cart_id = ?");
    $stmt->execute(array($cart_id));
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total = 0;
    $count = 0;
    foreach ($items as $item) {
        $total += floatval($item["total_price"]);
        $count += intval($item["quantity"]);
    }
    
    return array(
        "cart_id" => $cart_id,
        "items" => $items,
        "items_count" => $count,
        "total" => $total
    );
}

function getQuickBuySettings($pdo, $customer_id) {
    $stmt = $pdo->prepare("SELECT * FROM om_one_quick_buy_settings WHERE customer_id = ?");
    $stmt->execute(array($customer_id));
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$settings) {
        // Criar padrão
        $pdo->prepare("INSERT INTO om_one_quick_buy_settings (customer_id) VALUES (?)")->execute(array($customer_id));
        return array(
            "customer_id" => $customer_id,
            "one_click_enabled" => 1,
            "default_card_id" => null,
            "default_address_id" => null,
            "daily_limit" => 500,
            "daily_spent" => 0,
            "require_pin" => 0,
            "auto_apply_credits" => 1
        );
    }
    
    // Reset diário
    $today = date("Y-m-d");
    if ($settings["daily_reset_at"] != $today) {
        $pdo->prepare("UPDATE om_one_quick_buy_settings SET daily_spent = 0, daily_reset_at = ? WHERE customer_id = ?")
            ->execute(array($today, $customer_id));
        $settings["daily_spent"] = 0;
    }
    
    return $settings;
}

function getPreferredProduct($pdo, $customer_id, $query) {
    // Buscar nas preferências
    $query_lower = mb_strtolower($query);
    
    $stmt = $pdo->prepare("
        SELECT f.product_id, f.product_name, p.price
        FROM om_one_favorite_products f
        JOIN oc_product p ON f.product_id = p.product_id
        WHERE f.customer_id = ? AND LOWER(f.product_name) LIKE ?
        ORDER BY f.purchase_count DESC
        LIMIT 1
    ");
    $stmt->execute(array($customer_id, "%$query_lower%"));
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function learnProductPreference($pdo, $customer_id, $product_id, $product_name) {
    // Extrair categoria do nome
    $category = "geral";
    $name_lower = mb_strtolower($product_name);
    
    if (strpos($name_lower, "coca") !== false || strpos($name_lower, "refrigerante") !== false || strpos($name_lower, "pepsi") !== false) {
        $category = "bebida";
    } elseif (strpos($name_lower, "leite") !== false) {
        $category = "laticinio";
    } elseif (strpos($name_lower, "pão") !== false || strpos($name_lower, "pao") !== false) {
        $category = "padaria";
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO om_one_favorite_products (customer_id, product_id, product_name, category, purchase_count, last_purchased_at)
        VALUES (?, ?, ?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE
        purchase_count = purchase_count + 1,
        last_purchased_at = NOW()
    ");
    $stmt->execute(array($customer_id, $product_id, $product_name, $category));
}

function createMarketOrder($pdo, $customer_id, $cart, $settings, $delivery_fee, $credits_used) {
    // Buscar endereço
    $stmt = $pdo->prepare("SELECT * FROM om_one_saved_addresses WHERE address_id = ?");
    $stmt->execute(array($settings["default_address_id"]));
    $address = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Buscar partner_id do mercado mais próximo (simplificado)
    $partner_id = 1; // Por padrão
    
    $total = $cart["total"] + $delivery_fee - $credits_used;
    
    // Criar pedido
    $stmt = $pdo->prepare("
        INSERT INTO om_market_orders 
        (partner_id, customer_id, status, subtotal, delivery_fee, credits_used, total,
         delivery_address, delivery_number, delivery_complement, delivery_neighborhood, 
         delivery_city, delivery_state, delivery_zipcode,
         payment_method, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute(array(
        $partner_id, $customer_id, "pending",
        $cart["total"], $delivery_fee, $credits_used, $total,
        $address["street"], $address["number"], $address["complement"],
        $address["neighborhood"], $address["city"], $address["state"], $address["zipcode"],
        "card"
    ));
    
    $order_id = $pdo->lastInsertId();
    
    // Adicionar produtos
    foreach ($cart["items"] as $item) {
        $stmt = $pdo->prepare("
            INSERT INTO om_market_order_items (order_id, product_id, name, quantity, price, total)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute(array(
            $order_id, $item["product_id"], $item["product_name"],
            $item["quantity"], $item["unit_price"], $item["total_price"]
        ));
    }
    
    return $order_id;
}
