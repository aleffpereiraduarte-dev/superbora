<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * 💰 API DE REEMBOLSO
 */
header("Content-Type: application/json");
session_start();

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die(json_encode(array("success" => false, "error" => "DB Error")));
}

// Helper de notificações
if (file_exists(__DIR__ . "/../includes/cliente_notifications.php")) {
    require_once __DIR__ . "/../includes/cliente_notifications.php";
}

$input = json_decode(file_get_contents("php://input"), true);
$action = isset($input["action"]) ? $input["action"] : (isset($_GET["action"]) ? $_GET["action"] : "");

switch ($action) {
    
    // ══════════════════════════════════════════════════════════════════════════
    // CRIAR REEMBOLSO (automático ou manual)
    // ══════════════════════════════════════════════════════════════════════════
    case "create":
        $order_id = isset($input["order_id"]) ? intval($input["order_id"]) : 0;
        $reason = isset($input["reason"]) ? $input["reason"] : "cancellation";
        $reason_details = isset($input["reason_details"]) ? trim($input["reason_details"]) : null;
        $refund_method = isset($input["refund_method"]) ? $input["refund_method"] : "credit";
        $custom_amount = isset($input["custom_amount"]) ? floatval($input["custom_amount"]) : null;
        $fee_percent = isset($input["fee_percent"]) ? floatval($input["fee_percent"]) : 0;
        
        if (!$order_id) {
            echo json_encode(array("success" => false, "error" => "order_id required"));
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
        
        // Verificar se já tem reembolso
        $stmt = $pdo->prepare("SELECT refund_id FROM om_refunds WHERE order_id = ?");
        $stmt->execute(array($order_id));
        if ($stmt->fetch()) {
            echo json_encode(array("success" => false, "error" => "Pedido já possui reembolso"));
            exit;
        }
        
        // Calcular valores
        $original_amount = floatval($order["total"]);
        $fee_amount = ($fee_percent > 0) ? ($original_amount * ($fee_percent / 100)) : 0;
        $refund_amount = $custom_amount !== null ? $custom_amount : ($original_amount - $fee_amount);
        
        // Criar reembolso
        $pdo->beginTransaction();
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO om_refunds 
                (order_id, customer_id, original_amount, refund_amount, fee_amount, fee_reason,
                 payment_method, refund_method, reason, reason_details, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $fee_reason = $fee_percent > 0 ? "Taxa de cancelamento ($fee_percent%)" : null;
            $status = $refund_method == "credit" ? "completed" : "pending";
            
            $stmt->execute(array(
                $order_id, $order["customer_id"],
                $original_amount, $refund_amount, $fee_amount, $fee_reason,
                $order["payment_method"] ?? null,
                $refund_method, $reason, $reason_details, $status
            ));
            
            $refund_id = $pdo->lastInsertId();
            
            // Se for crédito, adicionar ao saldo do cliente
            if ($refund_method == "credit" && $refund_amount > 0) {
                // Criar registro de crédito
                $stmt = $pdo->prepare("
                    INSERT INTO om_customer_credits 
                    (customer_id, amount, type, reference_type, reference_id, description, expires_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                $expires = date("Y-m-d H:i:s", strtotime("+90 days"));
                $description = "Reembolso do pedido #$order_id";
                
                $stmt->execute(array(
                    $order["customer_id"], $refund_amount, "refund",
                    "refund", $refund_id, $description, $expires
                ));
                
                // Atualizar saldo do cliente
                $stmt = $pdo->prepare("
                    UPDATE om_market_customers 
                    SET credit_balance = credit_balance + ?
                    WHERE customer_id = ?
                ");
                $stmt->execute(array($refund_amount, $order["customer_id"]));
                
                // Marcar como processado
                $stmt = $pdo->prepare("UPDATE om_refunds SET status = \"completed\", processed_at = NOW() WHERE refund_id = ?");
                $stmt->execute(array($refund_id));
            }
            
            // Mensagem no chat
            $msg = "💰 *Reembolso processado*\n\n";
            $msg .= "Valor: R$ " . number_format($refund_amount, 2, ",", ".");
            if ($fee_amount > 0) {
                $msg .= "\nTaxa: R$ " . number_format($fee_amount, 2, ",", ".");
            }
            $msg .= "\nMétodo: " . ($refund_method == "credit" ? "Crédito na conta" : "Estorno no cartão");
            
            $stmt = $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)");
            $stmt->execute(array($order_id, "system", 0, $msg));
            
            $pdo->commit();
            
            // Notificar cliente
            if (function_exists("notificarCliente")) {
                notificarCliente($pdo, $order_id, "reembolso", array(
                    "amount" => $refund_amount,
                    "method" => $refund_method
                ));
            }
            
            echo json_encode(array(
                "success" => true,
                "refund_id" => $refund_id,
                "refund_amount" => $refund_amount,
                "status" => $status
            ));
            
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(array("success" => false, "error" => "Erro: " . $e->getMessage()));
        }
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // BUSCAR REEMBOLSO DO PEDIDO
    // ══════════════════════════════════════════════════════════════════════════
    case "get":
        $order_id = isset($_GET["order_id"]) ? intval($_GET["order_id"]) : 0;
        
        $stmt = $pdo->prepare("SELECT * FROM om_refunds WHERE order_id = ?");
        $stmt->execute(array($order_id));
        $refund = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(array("success" => true, "refund" => $refund));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // SALDO DE CRÉDITOS DO CLIENTE
    // ══════════════════════════════════════════════════════════════════════════
    case "balance":
        $customer_id = isset($_GET["customer_id"]) ? intval($_GET["customer_id"]) : 
                      (isset($_SESSION["customer_id"]) ? $_SESSION["customer_id"] : 0);
        
        if (!$customer_id) {
            echo json_encode(array("success" => false, "error" => "customer_id required"));
            exit;
        }
        
        // Saldo atual
        $stmt = $pdo->prepare("SELECT credit_balance FROM om_market_customers WHERE customer_id = ?");
        $stmt->execute(array($customer_id));
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Créditos ativos
        $stmt = $pdo->prepare("
            SELECT * FROM om_customer_credits 
            WHERE customer_id = ? AND status = \"active\" AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY expires_at ASC
        ");
        $stmt->execute(array($customer_id));
        $credits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(array(
            "success" => true,
            "balance" => floatval($customer["credit_balance"] ?? 0),
            "credits" => $credits
        ));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // USAR CRÉDITOS NO PEDIDO
    // ══════════════════════════════════════════════════════════════════════════
    case "use_credits":
        $customer_id = isset($input["customer_id"]) ? intval($input["customer_id"]) : 
                      (isset($_SESSION["customer_id"]) ? $_SESSION["customer_id"] : 0);
        $order_id = isset($input["order_id"]) ? intval($input["order_id"]) : 0;
        $amount = isset($input["amount"]) ? floatval($input["amount"]) : 0;
        
        if (!$customer_id || !$order_id || $amount <= 0) {
            echo json_encode(array("success" => false, "error" => "Dados inválidos"));
            exit;
        }
        
        // Verificar saldo
        $stmt = $pdo->prepare("SELECT credit_balance FROM om_market_customers WHERE customer_id = ?");
        $stmt->execute(array($customer_id));
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $balance = floatval($customer["credit_balance"] ?? 0);
        
        if ($amount > $balance) {
            echo json_encode(array("success" => false, "error" => "Saldo insuficiente"));
            exit;
        }
        
        $pdo->beginTransaction();
        
        try {
            // Descontar do saldo
            $stmt = $pdo->prepare("UPDATE om_market_customers SET credit_balance = credit_balance - ? WHERE customer_id = ?");
            $stmt->execute(array($amount, $customer_id));
            
            // Registrar uso nos créditos (FIFO - primeiro que expira primeiro)
            $remaining = $amount;
            $stmt = $pdo->prepare("
                SELECT * FROM om_customer_credits 
                WHERE customer_id = ? AND status = \"active\" AND (expires_at IS NULL OR expires_at > NOW())
                ORDER BY expires_at ASC
                FOR UPDATE
            ");
            $stmt->execute(array($customer_id));
            $credits = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($credits as $credit) {
                if ($remaining <= 0) break;
                
                $available = floatval($credit["amount"]) - floatval($credit["used_amount"]);
                $use = min($available, $remaining);
                
                $stmt = $pdo->prepare("
                    UPDATE om_customer_credits 
                    SET used_amount = used_amount + ?,
                        status = CASE WHEN used_amount + ? >= amount THEN \"used\" ELSE status END
                    WHERE credit_id = ?
                ");
                $stmt->execute(array($use, $use, $credit["credit_id"]));
                
                $remaining -= $use;
            }
            
            // Atualizar pedido com desconto
            $stmt = $pdo->prepare("
                UPDATE om_market_orders 
                SET credits_used = ?,
                    total = total - ?
                WHERE order_id = ?
            ");
            $stmt->execute(array($amount, $amount, $order_id));
            
            $pdo->commit();
            
            echo json_encode(array(
                "success" => true,
                "credits_used" => $amount,
                "new_balance" => $balance - $amount
            ));
            
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(array("success" => false, "error" => "Erro: " . $e->getMessage()));
        }
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // ADMIN: LISTAR REEMBOLSOS PENDENTES
    // ══════════════════════════════════════════════════════════════════════════
    case "pending_refunds":
        $stmt = $pdo->query("
            SELECT r.*, o.customer_name, o.customer_phone
            FROM om_refunds r
            JOIN om_market_orders o ON r.order_id = o.order_id
            WHERE r.status = \"pending\"
            ORDER BY r.created_at ASC
        ");
        $refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(array("success" => true, "refunds" => $refunds));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // ADMIN: PROCESSAR REEMBOLSO
    // ══════════════════════════════════════════════════════════════════════════
    case "process":
        $refund_id = isset($input["refund_id"]) ? intval($input["refund_id"]) : 0;
        $new_status = isset($input["status"]) ? $input["status"] : "completed";
        $admin_notes = isset($input["admin_notes"]) ? trim($input["admin_notes"]) : null;
        $processed_by = isset($_SESSION["admin_id"]) ? $_SESSION["admin_id"] : 0;
        
        if (!$refund_id) {
            echo json_encode(array("success" => false, "error" => "refund_id required"));
            exit;
        }
        
        $stmt = $pdo->prepare("
            UPDATE om_refunds 
            SET status = ?, admin_notes = ?, processed_by = ?, processed_at = NOW()
            WHERE refund_id = ?
        ");
        $stmt->execute(array($new_status, $admin_notes, $processed_by, $refund_id));
        
        echo json_encode(array("success" => true, "status" => $new_status));
        break;
    
    default:
        echo json_encode(array("success" => false, "error" => "Invalid action"));
}
