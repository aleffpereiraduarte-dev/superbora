<?php
require_once __DIR__ . '/../config/database.php';
/**
 * 💵 API DE AJUSTES DE VALOR
 */
header("Content-Type: application/json");
session_start();

$pdo = getPDO();

// Helpers
if (file_exists(__DIR__ . "/../includes/cliente_notifications.php")) {
    require_once __DIR__ . "/../includes/cliente_notifications.php";
}

$input = json_decode(file_get_contents("php://input"), true);
$action = isset($input["action"]) ? $input["action"] : (isset($_GET["action"]) ? $_GET["action"] : "");

switch ($action) {
    
    // ══════════════════════════════════════════════════════════════════════════
    // PRODUTO NÃO ENCONTRADO - Reembolso parcial
    // ══════════════════════════════════════════════════════════════════════════
    case "product_not_found":
        $order_id = isset($input["order_id"]) ? intval($input["order_id"]) : 0;
        $product_id = isset($input["product_id"]) ? intval($input["product_id"]) : 0;
        $product_name = isset($input["product_name"]) ? trim($input["product_name"]) : "";
        $price = isset($input["price"]) ? floatval($input["price"]) : 0;
        $quantity = isset($input["quantity"]) ? intval($input["quantity"]) : 1;
        $shopper_id = isset($input["shopper_id"]) ? intval($input["shopper_id"]) : 0;
        $reason = isset($input["reason"]) ? trim($input["reason"]) : "Produto não disponível no mercado";
        
        if (!$order_id || !$product_name || $price <= 0) {
            echo json_encode(array("success" => false, "error" => "Dados incompletos"));
            exit;
        }
        
        // Buscar pedido
        $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
        $stmt->execute(array($order_id));
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(array("success" => false, "error" => "Pedido não encontrado"));
            exit;
        }
        
        $refund_amount = $price * $quantity;
        
        $pdo->beginTransaction();
        
        try {
            // Criar ajuste
            $stmt = $pdo->prepare("
                INSERT INTO om_order_adjustments 
                (order_id, customer_id, type, product_id, product_name, original_price, original_quantity,
                 new_price, new_quantity, amount, direction, status, created_by, created_by_id, reason)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute(array(
                $order_id, $order["customer_id"], "product_not_found",
                $product_id, $product_name, $price, $quantity,
                0, 0, $refund_amount, "refund", "processed",
                "shopper", $shopper_id, $reason
            ));
            
            $adjustment_id = $pdo->lastInsertId();
            
            // Marcar produto como removido
            $stmt = $pdo->prepare("
                UPDATE om_market_order_items 
                SET is_removed = 1, removal_reason = ?
                WHERE order_id = ? AND (product_id = ? OR name = ?)
            ");
            $stmt->execute(array($reason, $order_id, $product_id, $product_name));
            
            // Atualizar totais do pedido
            updateOrderTotals($pdo, $order_id);
            
            // Criar reembolso automático como crédito
            createAutoRefund($pdo, $order_id, $order["customer_id"], $refund_amount, 
                "Produto não encontrado: $product_name", $adjustment_id);
            
            // Mensagem no chat
            $msg = "❌ *Produto não encontrado*\n\n";
            $msg .= "Produto: $product_name\n";
            $msg .= "Valor: R$ " . number_format($refund_amount, 2, ",", ".") . "\n\n";
            $msg .= "💰 Este valor foi creditado na sua conta.";
            
            $stmt = $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)");
            $stmt->execute(array($order_id, "system", 0, $msg));
            
            $pdo->commit();
            
            // Notificar cliente
            if (function_exists("notificarCliente")) {
                notificarCliente($pdo, $order_id, "ajuste_valor", array(
                    "type" => "refund",
                    "amount" => $refund_amount,
                    "reason" => "Produto não encontrado"
                ));
            }
            
            echo json_encode(array(
                "success" => true,
                "adjustment_id" => $adjustment_id,
                "refund_amount" => $refund_amount,
                "message" => "Reembolso de R$ " . number_format($refund_amount, 2, ",", ".") . " processado"
            ));
            
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(array("success" => false, "error" => $e->getMessage()));
        }
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // SUBSTITUIÇÃO - Ajuste automático de preço
    // ══════════════════════════════════════════════════════════════════════════
    case "substitution_price_change":
        $order_id = isset($input["order_id"]) ? intval($input["order_id"]) : 0;
        $substitution_id = isset($input["substitution_id"]) ? intval($input["substitution_id"]) : 0;
        
        // Buscar substituição aprovada
        $stmt = $pdo->prepare("SELECT * FROM om_product_substitutions WHERE substitution_id = ? AND status = \"approved\"");
        $stmt->execute(array($substitution_id));
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$sub) {
            echo json_encode(array("success" => false, "error" => "Substituição não encontrada ou não aprovada"));
            exit;
        }
        
        $order_id = $sub["order_id"];
        
        // Buscar pedido
        $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
        $stmt->execute(array($order_id));
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $original_total = floatval($sub["original_price"]) * intval($sub["original_quantity"]);
        $new_total = floatval($sub["suggested_price"]) * intval($sub["suggested_quantity"]);
        $difference = $new_total - $original_total;
        
        if (abs($difference) < 0.01) {
            echo json_encode(array("success" => true, "message" => "Sem diferença de preço", "difference" => 0));
            exit;
        }
        
        $pdo->beginTransaction();
        
        try {
            $type = $difference < 0 ? "substitution_cheaper" : "substitution_expensive";
            $direction = $difference < 0 ? "refund" : "charge";
            $amount = abs($difference);
            
            // Criar ajuste
            $stmt = $pdo->prepare("
                INSERT INTO om_order_adjustments 
                (order_id, customer_id, type, product_id, product_name, original_price, original_quantity,
                 new_price, new_quantity, amount, direction, status, created_by, reason)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $reason = $difference < 0 ? 
                "Substituição mais barata: {$sub["original_name"]} → {$sub["suggested_name"]}" :
                "Substituição mais cara: {$sub["original_name"]} → {$sub["suggested_name"]}";
            
            // Se é cobrança adicional, precisa aprovação do cliente
            $status = $direction == "refund" ? "processed" : "pending";
            
            $stmt->execute(array(
                $order_id, $order["customer_id"], $type,
                $sub["suggested_product_id"], $sub["suggested_name"],
                $sub["original_price"], $sub["original_quantity"],
                $sub["suggested_price"], $sub["suggested_quantity"],
                $amount, $direction, $status, "system", $reason
            ));
            
            $adjustment_id = $pdo->lastInsertId();
            
            // Atualizar totais
            updateOrderTotals($pdo, $order_id);
            
            if ($direction == "refund") {
                // Reembolso automático
                createAutoRefund($pdo, $order_id, $order["customer_id"], $amount, $reason, $adjustment_id);
                
                $msg = "💰 *Diferença de preço - Reembolso*\n\n";
                $msg .= "A substituição ficou R$ " . number_format($amount, 2, ",", ".") . " mais barata.\n";
                $msg .= "Este valor foi creditado na sua conta.";
            } else {
                // Cobrança adicional - precisa aprovação
                $stmt = $pdo->prepare("UPDATE om_market_orders SET pending_charge = pending_charge + ? WHERE order_id = ?");
                $stmt->execute(array($amount, $order_id));
                
                $msg = "💳 *Diferença de preço - Cobrança*\n\n";
                $msg .= "A substituição ficou R$ " . number_format($amount, 2, ",", ".") . " mais cara.\n\n";
                $msg .= "Este valor será cobrado automaticamente.";
            }
            
            $stmt = $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)");
            $stmt->execute(array($order_id, "system", 0, $msg));
            
            $pdo->commit();
            
            echo json_encode(array(
                "success" => true,
                "adjustment_id" => $adjustment_id,
                "direction" => $direction,
                "amount" => $amount
            ));
            
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(array("success" => false, "error" => $e->getMessage()));
        }
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // PROCESSAR COBRANÇA ADICIONAL
    // ══════════════════════════════════════════════════════════════════════════
    case "process_charge":
        $adjustment_id = isset($input["adjustment_id"]) ? intval($input["adjustment_id"]) : 0;
        
        $stmt = $pdo->prepare("SELECT * FROM om_order_adjustments WHERE adjustment_id = ? AND direction = \"charge\" AND status = \"pending\"");
        $stmt->execute(array($adjustment_id));
        $adj = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$adj) {
            echo json_encode(array("success" => false, "error" => "Ajuste não encontrado"));
            exit;
        }
        
        // Buscar pedido para dados de pagamento
        $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
        $stmt->execute(array($adj["order_id"]));
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $pdo->beginTransaction();
        
        try {
            // Aqui você integraria com seu gateway de pagamento
            // Por enquanto, vamos simular como "cobrado"
            
            // Marcar como processado
            $stmt = $pdo->prepare("
                UPDATE om_order_adjustments 
                SET status = \"processed\", payment_status = \"captured\", processed_at = NOW()
                WHERE adjustment_id = ?
            ");
            $stmt->execute(array($adjustment_id));
            
            // Atualizar pedido
            $stmt = $pdo->prepare("
                UPDATE om_market_orders 
                SET pending_charge = GREATEST(0, pending_charge - ?),
                    final_total = COALESCE(final_total, total) + ?
                WHERE order_id = ?
            ");
            $stmt->execute(array($adj["amount"], $adj["amount"], $adj["order_id"]));
            
            // Mensagem no chat
            $msg = "✅ *Cobrança processada*\n\n";
            $msg .= "Valor: R$ " . number_format($adj["amount"], 2, ",", ".") . "\n";
            $msg .= "Motivo: " . $adj["reason"];
            
            $stmt = $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)");
            $stmt->execute(array($adj["order_id"], "system", 0, $msg));
            
            $pdo->commit();
            
            echo json_encode(array("success" => true, "message" => "Cobrança processada"));
            
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(array("success" => false, "error" => $e->getMessage()));
        }
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // LISTAR AJUSTES DO PEDIDO
    // ══════════════════════════════════════════════════════════════════════════
    case "list":
        $order_id = isset($_GET["order_id"]) ? intval($_GET["order_id"]) : 0;
        
        $stmt = $pdo->prepare("SELECT * FROM om_order_adjustments WHERE order_id = ? ORDER BY created_at DESC");
        $stmt->execute(array($order_id));
        $adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calcular totais
        $total_refund = 0;
        $total_charge = 0;
        foreach ($adjustments as $adj) {
            if ($adj["direction"] == "refund" && $adj["status"] == "processed") {
                $total_refund += floatval($adj["amount"]);
            } elseif ($adj["direction"] == "charge" && $adj["status"] == "processed") {
                $total_charge += floatval($adj["amount"]);
            }
        }
        
        echo json_encode(array(
            "success" => true,
            "adjustments" => $adjustments,
            "summary" => array(
                "total_refund" => $total_refund,
                "total_charge" => $total_charge,
                "net" => $total_charge - $total_refund
            )
        ));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // RESUMO FINANCEIRO DO PEDIDO
    // ══════════════════════════════════════════════════════════════════════════
    case "order_summary":
        $order_id = isset($_GET["order_id"]) ? intval($_GET["order_id"]) : 0;
        
        $stmt = $pdo->prepare("
            SELECT 
                o.total as original_total,
                o.adjustments_total,
                o.final_total,
                o.pending_charge,
                o.pending_refund,
                o.credits_used,
                (SELECT COALESCE(SUM(amount), 0) FROM om_order_adjustments WHERE order_id = o.order_id AND direction = \"refund\" AND status = \"processed\") as total_refunded,
                (SELECT COALESCE(SUM(amount), 0) FROM om_order_adjustments WHERE order_id = o.order_id AND direction = \"charge\" AND status = \"processed\") as total_charged
            FROM om_market_orders o
            WHERE o.order_id = ?
        ");
        $stmt->execute(array($order_id));
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(array("success" => true, "summary" => $summary));
        break;
    
    default:
        echo json_encode(array("success" => false, "error" => "Invalid action"));
}

/**
 * Atualiza totais do pedido
 */
function updateOrderTotals($pdo, $order_id) {
    // Recalcular total dos produtos ativos
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total), 0) as products_total
        FROM om_market_order_items 
        WHERE order_id = ? AND (is_removed = 0 OR is_removed IS NULL)
    ");
    $stmt->execute(array($order_id));
    $products_total = floatval($stmt->fetchColumn());
    
    // Calcular ajustes
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN direction = \"refund\" AND status = \"processed\" THEN amount ELSE 0 END), 0) as refunds,
            COALESCE(SUM(CASE WHEN direction = \"charge\" AND status = \"processed\" THEN amount ELSE 0 END), 0) as charges
        FROM om_order_adjustments 
        WHERE order_id = ?
    ");
    $stmt->execute(array($order_id));
    $adj = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $adjustments_total = floatval($adj["charges"]) - floatval($adj["refunds"]);
    $final_total = $products_total + $adjustments_total;
    
    // Atualizar pedido
    $stmt = $pdo->prepare("
        UPDATE om_market_orders 
        SET adjustments_total = ?,
            final_total = ?,
            total = ?
        WHERE order_id = ?
    ");
    $stmt->execute(array($adjustments_total, $final_total, $final_total, $order_id));
}

/**
 * Criar reembolso automático como crédito
 */
function createAutoRefund($pdo, $order_id, $customer_id, $amount, $reason, $adjustment_id) {
    if ($amount <= 0) return;
    
    // Criar registro de crédito
    $expires = date("Y-m-d H:i:s", strtotime("+90 days"));
    
    $stmt = $pdo->prepare("
        INSERT INTO om_customer_credits 
        (customer_id, amount, type, reference_type, reference_id, description, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute(array(
        $customer_id, $amount, "refund",
        "adjustment", $adjustment_id, $reason, $expires
    ));
    
    // Atualizar saldo do cliente
    $stmt = $pdo->prepare("
        UPDATE om_market_customers 
        SET credit_balance = credit_balance + ?
        WHERE customer_id = ?
    ");
    $stmt->execute(array($amount, $customer_id));
}
