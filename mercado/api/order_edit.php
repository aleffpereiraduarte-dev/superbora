<?php
/**
 * API de Edição de Pedido em Tempo Real
 * 
 * Endpoints:
 * POST add_item - Adicionar item ao pedido
 * POST remove_item - Remover item do pedido
 * POST update_qty - Atualizar quantidade
 * GET can_edit - Verificar se pode editar
 * GET pending_notifications - Notificações para shopper
 */

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") exit;

require_once __DIR__ . "/../config/database.php";

$action = $_GET["action"] ?? $_POST["action"] ?? "";
$response = ["success" => false];

// Configurações de gamificação
$SHOPPER_FEES = [
    ["min" => 1, "max" => 10, "base" => 8, "bonus" => 0],
    ["min" => 11, "max" => 20, "base" => 8, "bonus" => 4],
    ["min" => 21, "max" => 30, "base" => 8, "bonus" => 8],
    ["min" => 31, "max" => 50, "base" => 8, "bonus" => 15],
    ["min" => 51, "max" => 9999, "base" => 8, "bonus" => 25]
];

function calculateShopperFee($totalItems) {
    global $SHOPPER_FEES;
    foreach ($SHOPPER_FEES as $tier) {
        if ($totalItems >= $tier["min"] && $totalItems <= $tier["max"]) {
            return ["base" => $tier["base"], "bonus" => $tier["bonus"], "total" => $tier["base"] + $tier["bonus"]];
        }
    }
    return ["base" => 8, "bonus" => 0, "total" => 8];
}

function canEditOrder($pdo, $orderId) {
    $stmt = $pdo->prepare("
        SELECT o.*, 
               COALESCE(o.items_collected, 0) as collected,
               COALESCE(o.items_total, (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.id)) as total
        FROM om_market_orders o 
        WHERE o.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) return ["can" => false, "reason" => "Pedido não encontrado"];
    
    // Status que permitem edição
    $editableStatuses = ["pending", "accepted", "shopping"];
    if (!in_array($order["status"], $editableStatuses)) {
        return ["can" => false, "reason" => "Pedido já em entrega"];
    }
    
    // Verificar porcentagem de coleta
    if ($order["total"] > 0) {
        $percent = ($order["collected"] / $order["total"]) * 100;
        if ($percent >= 80) {
            return ["can" => false, "reason" => "Shopper quase finalizando (80% coletado)", "percent" => $percent];
        }
    }
    
    // Verificar flag can_edit
    if (isset($order["can_edit"]) && $order["can_edit"] == 0) {
        return ["can" => false, "reason" => $order["edit_lock_reason"] ?? "Pedido bloqueado para edição"];
    }
    
    return ["can" => true, "percent" => $percent ?? 0, "status" => $order["status"]];
}

function notifyShopper($pdo, $orderId, $action, $productName, $qty = 1) {
    // Buscar shopper do pedido
    $stmt = $pdo->prepare("SELECT shopper_id FROM om_market_orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    
    if (!$order || !$order["shopper_id"]) return;
    
    $messages = [
        "add" => "🛒 Cliente adicionou: $productName" . ($qty > 1 ? " (x$qty)" : ""),
        "remove" => "❌ Cliente removeu: $productName",
        "update_qty" => "🔄 Cliente alterou quantidade: $productName para $qty"
    ];
    
    $message = $messages[$action] ?? "📝 Pedido atualizado";
    
    // Inserir notificação
    try {
        $stmt = $pdo->prepare("
            INSERT INTO om_order_notifications (order_id, user_type, user_id, title, message, created_at)
            VALUES (?, 'shopper', ?, 'Pedido Atualizado', ?, NOW())
        ");
        $stmt->execute([$orderId, $order["shopper_id"], $message]);
    } catch (Exception $e) {}
    
    // Atualizar ganho do shopper
    updateShopperFee($pdo, $orderId);
}

function updateShopperFee($pdo, $orderId) {
    // Contar itens totais
    $stmt = $pdo->prepare("SELECT SUM(quantity) as total FROM om_market_order_items WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $result = $stmt->fetch();
    $totalItems = (int)($result["total"] ?? 0);
    
    $fee = calculateShopperFee($totalItems);
    
    $stmt = $pdo->prepare("
        UPDATE om_market_orders 
        SET items_total = ?, shopper_base_fee = ?, shopper_bonus = ?, shopper_total_fee = ?
        WHERE id = ?
    ");
    $stmt->execute([$totalItems, $fee["base"], $fee["bonus"], $fee["total"], $orderId]);
    
    return $fee;
}

function recalculateOrderTotal($pdo, $orderId) {
    $stmt = $pdo->prepare("
        SELECT SUM(
            CASE WHEN price_promo > 0 AND price_promo < price THEN price_promo * quantity
            ELSE price * quantity END
        ) as subtotal
        FROM om_market_order_items 
        WHERE order_id = ?
    ");
    $stmt->execute([$orderId]);
    $result = $stmt->fetch();
    $subtotal = (float)($result["subtotal"] ?? 0);
    
    // Buscar taxa de entrega
    $stmt = $pdo->prepare("SELECT delivery_fee FROM om_market_orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    $deliveryFee = (float)($order["delivery_fee"] ?? 0);
    
    $total = $subtotal + $deliveryFee;
    
    $stmt = $pdo->prepare("UPDATE om_market_orders SET subtotal = ?, total = ? WHERE id = ?");
    $stmt->execute([$subtotal, $total, $orderId]);
    
    return ["subtotal" => $subtotal, "delivery_fee" => $deliveryFee, "total" => $total];
}

try {
    switch ($action) {
        
        // ═══════════════════════════════════════════════════════════════════════
        // VERIFICAR SE PODE EDITAR
        // ═══════════════════════════════════════════════════════════════════════
        case "can_edit":
            $orderId = $_GET["order_id"] ?? 0;
            $result = canEditOrder($pdo, $orderId);
            $response = array_merge(["success" => true], $result);
            break;
            
        // ═══════════════════════════════════════════════════════════════════════
        // ADICIONAR ITEM
        // ═══════════════════════════════════════════════════════════════════════
        case "add_item":
            $input = json_decode(file_get_contents("php://input"), true);
            $orderId = $input["order_id"] ?? 0;
            $productId = $input["product_id"] ?? 0;
            $qty = max(1, (int)($input["quantity"] ?? 1));
            $customerId = $input["customer_id"] ?? 0;
            
            // Verificar se pode editar
            $canEdit = canEditOrder($pdo, $orderId);
            if (!$canEdit["can"]) {
                throw new Exception($canEdit["reason"]);
            }
            
            // Buscar produto
            $stmt = $pdo->prepare("
                SELECT pb.*, pp.price, pp.price_promo
                FROM om_market_products_base pb
                JOIN om_market_partner_products pp ON pb.id = pp.product_id
                WHERE pb.id = ?
                LIMIT 1
            ");
            $stmt->execute([$productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                throw new Exception("Produto não encontrado");
            }
            
            $price = $product["price_promo"] > 0 ? $product["price_promo"] : $product["price"];
            
            // Verificar se já existe no pedido
            $stmt = $pdo->prepare("SELECT id, quantity FROM om_market_order_items WHERE order_id = ? AND product_id = ?");
            $stmt->execute([$orderId, $productId]);
            $existing = $stmt->fetch();
            
            // Pegar total antigo
            $stmt = $pdo->prepare("SELECT total FROM om_market_orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $oldTotal = $stmt->fetchColumn();
            
            if ($existing) {
                // Atualizar quantidade
                $newQty = $existing["quantity"] + $qty;
                $stmt = $pdo->prepare("UPDATE om_market_order_items SET quantity = ? WHERE id = ?");
                $stmt->execute([$newQty, $existing["id"]]);
            } else {
                // Inserir novo item
                $stmt = $pdo->prepare("
                    INSERT INTO om_market_order_items (order_id, product_id, name, price, price_promo, quantity, image)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $orderId, $productId, $product["name"], 
                    $product["price"], $product["price_promo"] ?? 0, 
                    $qty, $product["image"] ?? ""
                ]);
            }
            
            // Recalcular total
            $totals = recalculateOrderTotal($pdo, $orderId);
            
            // Registrar edição
            $stmt = $pdo->prepare("
                INSERT INTO om_order_edits (order_id, customer_id, action, product_id, product_name, quantity, price, old_total, new_total)
                VALUES (?, ?, 'add', ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$orderId, $customerId, $productId, $product["name"], $qty, $price, $oldTotal, $totals["total"]]);
            
            // Notificar shopper
            notifyShopper($pdo, $orderId, "add", $product["name"], $qty);
            
            // Calcular nova taxa do shopper
            $shopperFee = updateShopperFee($pdo, $orderId);
            
            $response = [
                "success" => true,
                "message" => "Item adicionado!",
                "item" => ["product_id" => $productId, "name" => $product["name"], "quantity" => $qty, "price" => $price],
                "totals" => $totals,
                "shopper_fee" => $shopperFee
            ];
            break;
            
        // ═══════════════════════════════════════════════════════════════════════
        // REMOVER ITEM
        // ═══════════════════════════════════════════════════════════════════════
        case "remove_item":
            $input = json_decode(file_get_contents("php://input"), true);
            $orderId = $input["order_id"] ?? 0;
            $productId = $input["product_id"] ?? 0;
            $customerId = $input["customer_id"] ?? 0;
            
            $canEdit = canEditOrder($pdo, $orderId);
            if (!$canEdit["can"]) {
                throw new Exception($canEdit["reason"]);
            }
            
            // Buscar item
            $stmt = $pdo->prepare("SELECT * FROM om_market_order_items WHERE order_id = ? AND product_id = ?");
            $stmt->execute([$orderId, $productId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$item) {
                throw new Exception("Item não encontrado no pedido");
            }
            
            // Verificar se item já foi coletado
            if (isset($item["collected"]) && $item["collected"]) {
                throw new Exception("Item já foi coletado pelo shopper");
            }
            
            $stmt = $pdo->prepare("SELECT total FROM om_market_orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $oldTotal = $stmt->fetchColumn();
            
            // Remover item
            $stmt = $pdo->prepare("DELETE FROM om_market_order_items WHERE order_id = ? AND product_id = ?");
            $stmt->execute([$orderId, $productId]);
            
            // Recalcular
            $totals = recalculateOrderTotal($pdo, $orderId);
            
            // Registrar
            $stmt = $pdo->prepare("
                INSERT INTO om_order_edits (order_id, customer_id, action, product_id, product_name, quantity, price, old_total, new_total)
                VALUES (?, ?, 'remove', ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$orderId, $customerId, $productId, $item["name"], $item["quantity"], $item["price"], $oldTotal, $totals["total"]]);
            
            notifyShopper($pdo, $orderId, "remove", $item["name"]);
            $shopperFee = updateShopperFee($pdo, $orderId);
            
            $response = [
                "success" => true,
                "message" => "Item removido",
                "totals" => $totals,
                "shopper_fee" => $shopperFee
            ];
            break;
            
        // ═══════════════════════════════════════════════════════════════════════
        // ATUALIZAR QUANTIDADE
        // ═══════════════════════════════════════════════════════════════════════
        case "update_qty":
            $input = json_decode(file_get_contents("php://input"), true);
            $orderId = $input["order_id"] ?? 0;
            $productId = $input["product_id"] ?? 0;
            $newQty = max(0, (int)($input["quantity"] ?? 0));
            $customerId = $input["customer_id"] ?? 0;
            
            $canEdit = canEditOrder($pdo, $orderId);
            if (!$canEdit["can"]) {
                throw new Exception($canEdit["reason"]);
            }
            
            if ($newQty === 0) {
                // Redirecionar para remover
                $_POST["action"] = "remove_item";
                // ... ou chamar a lógica de remoção
            }
            
            $stmt = $pdo->prepare("SELECT * FROM om_market_order_items WHERE order_id = ? AND product_id = ?");
            $stmt->execute([$orderId, $productId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$item) throw new Exception("Item não encontrado");
            
            $stmt = $pdo->prepare("SELECT total FROM om_market_orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $oldTotal = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("UPDATE om_market_order_items SET quantity = ? WHERE order_id = ? AND product_id = ?");
            $stmt->execute([$newQty, $orderId, $productId]);
            
            $totals = recalculateOrderTotal($pdo, $orderId);
            
            $stmt = $pdo->prepare("
                INSERT INTO om_order_edits (order_id, customer_id, action, product_id, product_name, quantity, price, old_total, new_total)
                VALUES (?, ?, 'update_qty', ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$orderId, $customerId, $productId, $item["name"], $newQty, $item["price"], $oldTotal, $totals["total"]]);
            
            notifyShopper($pdo, $orderId, "update_qty", $item["name"], $newQty);
            $shopperFee = updateShopperFee($pdo, $orderId);
            
            $response = [
                "success" => true,
                "message" => "Quantidade atualizada",
                "totals" => $totals,
                "shopper_fee" => $shopperFee
            ];
            break;
            
        // ═══════════════════════════════════════════════════════════════════════
        // NOTIFICAÇÕES PENDENTES PARA SHOPPER
        // ═══════════════════════════════════════════════════════════════════════
        case "pending_notifications":
            $orderId = $_GET["order_id"] ?? 0;
            $shopperId = $_GET["shopper_id"] ?? 0;
            
            $stmt = $pdo->prepare("
                SELECT * FROM om_order_edits 
                WHERE order_id = ? AND notified_shopper = 0
                ORDER BY created_at ASC
            ");
            $stmt->execute([$orderId]);
            $edits = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Marcar como notificadas
            if (!empty($edits)) {
                $ids = array_column($edits, "id");
                $pdo->exec("UPDATE om_order_edits SET notified_shopper = 1 WHERE id IN (" . implode(",", $ids) . ")");
            }
            
            // Buscar taxa atualizada
            $stmt = $pdo->prepare("SELECT shopper_base_fee, shopper_bonus, shopper_total_fee, items_total FROM om_market_orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $response = [
                "success" => true,
                "edits" => $edits,
                "shopper_fee" => [
                    "base" => (float)$order["shopper_base_fee"],
                    "bonus" => (float)$order["shopper_bonus"],
                    "total" => (float)$order["shopper_total_fee"]
                ],
                "total_items" => (int)$order["items_total"]
            ];
            break;
            
        // ═══════════════════════════════════════════════════════════════════════
        // LOCK DO PEDIDO (chamado pelo shopper quando chega em 80%)
        // ═══════════════════════════════════════════════════════════════════════
        case "lock_edit":
            $input = json_decode(file_get_contents("php://input"), true);
            $orderId = $input["order_id"] ?? 0;
            $reason = $input["reason"] ?? "Shopper finalizando compras";
            
            $stmt = $pdo->prepare("
                UPDATE om_market_orders 
                SET can_edit = 0, edit_locked_at = NOW(), edit_lock_reason = ?
                WHERE id = ?
            ");
            $stmt->execute([$reason, $orderId]);
            
            $response = ["success" => true, "message" => "Pedido bloqueado para edição"];
            break;
            
        // ═══════════════════════════════════════════════════════════════════════
        // ATUALIZAR PROGRESSO DE COLETA (shopper chama ao coletar item)
        // ═══════════════════════════════════════════════════════════════════════
        case "update_collection":
            $input = json_decode(file_get_contents("php://input"), true);
            $orderId = $input["order_id"] ?? 0;
            $collected = (int)($input["items_collected"] ?? 0);
            
            // Buscar total de itens
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_market_order_items WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $total = (int)$stmt->fetchColumn();
            
            $percent = $total > 0 ? round(($collected / $total) * 100) : 0;
            
            // Atualizar
            $stmt = $pdo->prepare("
                UPDATE om_market_orders 
                SET items_collected = ?, items_total = ?, collection_percent = ?
                WHERE id = ?
            ");
            $stmt->execute([$collected, $total, $percent, $orderId]);
            
            // Auto-lock se >= 80%
            $locked = false;
            if ($percent >= 80) {
                $stmt = $pdo->prepare("
                    UPDATE om_market_orders 
                    SET can_edit = 0, edit_locked_at = NOW(), edit_lock_reason = 'Shopper coletou 80% dos itens'
                    WHERE id = ? AND can_edit = 1
                ");
                $stmt->execute([$orderId]);
                $locked = $stmt->rowCount() > 0;
            }
            
            $response = [
                "success" => true,
                "collected" => $collected,
                "total" => $total,
                "percent" => $percent,
                "edit_locked" => $locked
            ];
            break;
            
        default:
            throw new Exception("Ação inválida");
    }
    
} catch (Exception $e) {
    $response = ["success" => false, "message" => $e->getMessage()];
}

echo json_encode($response);
