<?php
/**
 * ❌ API DE CANCELAMENTO DE PEDIDO
 */
header("Content-Type: application/json");
session_start();

require_once dirname(__DIR__) . '/config/database.php';

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

// Motivos de cancelamento
$CANCEL_REASONS = array(
    "changed_mind" => "Mudei de ideia",
    "wrong_address" => "Endereço errado",
    "wrong_items" => "Itens errados",
    "found_better_price" => "Encontrei preço melhor",
    "taking_too_long" => "Demorando muito",
    "duplicate_order" => "Pedido duplicado",
    "other" => "Outro motivo"
);

// Status que permitem cancelamento pelo cliente
$CANCELLABLE_STATUS = array("pending", "confirmed");

// Status que permitem cancelamento com reembolso parcial
$PARTIAL_REFUND_STATUS = array("preparing");

switch ($action) {
    
    // ══════════════════════════════════════════════════════════════════════════
    // VERIFICAR SE PODE CANCELAR
    // ══════════════════════════════════════════════════════════════════════════
    case "check":
        $order_id = isset($_GET["order_id"]) ? intval($_GET["order_id"]) : 0;
        
        $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
        $stmt->execute(array($order_id));
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(array("success" => false, "can_cancel" => false, "reason" => "not_found"));
            exit;
        }
        
        $status = $order["status"];
        $can_cancel = in_array($status, $CANCELLABLE_STATUS);
        $partial_refund = in_array($status, $PARTIAL_REFUND_STATUS);
        
        // Calcular tempo desde criação
        $created = strtotime($order["created_at"]);
        $minutes_elapsed = (time() - $created) / 60;
        
        // Não pode cancelar após 30 minutos em status avançado
        if ($partial_refund && $minutes_elapsed > 30) {
            $can_cancel = false;
        }
        
        // Mensagem explicativa
        $message = "";
        if ($can_cancel) {
            $message = "Você pode cancelar este pedido";
            if ($partial_refund) {
                $message = "Cancelamento com reembolso parcial (taxa de 10%)";
            }
        } else {
            if ($status == "delivering" || $status == "ready") {
                $message = "Pedido já está em entrega, não pode ser cancelado";
            } elseif ($status == "delivered" || $status == "completed") {
                $message = "Pedido já foi entregue";
            } elseif ($status == "cancelled") {
                $message = "Pedido já foi cancelado";
            } else {
                $message = "Este pedido não pode ser cancelado no momento";
            }
        }
        
        echo json_encode(array(
            "success" => true,
            "can_cancel" => $can_cancel || $partial_refund,
            "full_refund" => $can_cancel,
            "partial_refund" => $partial_refund,
            "status" => $status,
            "message" => $message,
            "reasons" => $CANCEL_REASONS
        ));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // CANCELAR PEDIDO
    // ══════════════════════════════════════════════════════════════════════════
    case "cancel":
        $order_id = isset($input["order_id"]) ? intval($input["order_id"]) : 0;
        $reason = isset($input["reason"]) ? $input["reason"] : "";
        $reason_details = isset($input["reason_details"]) ? trim($input["reason_details"]) : "";
        $cancelled_by = isset($input["cancelled_by"]) ? $input["cancelled_by"] : "customer";
        $cancelled_by_id = isset($_SESSION["customer_id"]) ? $_SESSION["customer_id"] : 0;
        
        if (!$order_id || !$reason) {
            echo json_encode(array("success" => false, "error" => "order_id e reason são obrigatórios"));
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
        
        $status = $order["status"];
        
        // Verificar se pode cancelar
        $can_cancel = in_array($status, $CANCELLABLE_STATUS);
        $partial_refund = in_array($status, $PARTIAL_REFUND_STATUS);
        
        if (!$can_cancel && !$partial_refund) {
            echo json_encode(array("success" => false, "error" => "Este pedido não pode ser cancelado"));
            exit;
        }
        
        // Calcular reembolso
        $refund_amount = floatval($order["total"]);
        $refund_status = "pending";
        
        if ($partial_refund) {
            // 10% de taxa
            $refund_amount = $refund_amount * 0.90;
        }
        
        // Iniciar transação
        $pdo->beginTransaction();
        
        try {
            // Atualizar pedido
            $stmt = $pdo->prepare("
                UPDATE om_market_orders 
                SET status = \"cancelled\", 
                    cancelled_at = NOW(),
                    cancellation_reason = ?
                WHERE order_id = ?
            ");
            $stmt->execute(array($CANCEL_REASONS[$reason] ?? $reason, $order_id));
            
            // Registrar cancelamento
            $stmt = $pdo->prepare("
                INSERT INTO om_order_cancellations 
                (order_id, cancelled_by, cancelled_by_id, reason, reason_details, order_status_at_cancel, refund_amount, refund_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute(array(
                $order_id, $cancelled_by, $cancelled_by_id,
                $reason, $reason_details, $status,
                $refund_amount, $refund_status
            ));
            
            // Cancelar ofertas pendentes
            $pdo->prepare("UPDATE om_delivery_offers SET status = \"cancelled\" WHERE order_id = ? AND status = \"pending\"")->execute(array($order_id));
            
            // Liberar shopper se estava ocupado
            if ($order["shopper_id"]) {
                $pdo->prepare("UPDATE om_market_shoppers SET is_busy = 0, current_order_id = NULL WHERE shopper_id = ? AND current_order_id = ?")->execute(array($order["shopper_id"], $order_id));
            }
            
            // Liberar delivery se estava ocupado
            if ($order["delivery_id"]) {
                $pdo->prepare("UPDATE om_market_deliveries SET current_deliveries = GREATEST(0, current_deliveries - 1) WHERE delivery_id = ?")->execute(array($order["delivery_id"]));
            }
            
            // Mensagem no chat
            $reason_text = $CANCEL_REASONS[$reason] ?? $reason;
            $msg = "❌ Pedido cancelado\n\nMotivo: $reason_text";
            if ($refund_amount > 0) {
                $msg .= "\n\n💰 Reembolso: R$ " . number_format($refund_amount, 2, ",", ".");
            }
            
            $stmt = $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)");
            $stmt->execute(array($order_id, "system", 0, $msg));
            
            $pdo->commit();
            
            // Notificar cliente
            if (function_exists("notificarCliente")) {
                notificarCliente($pdo, $order_id, "cancelado", array("reason" => $reason_text));
            }
            
            echo json_encode(array(
                "success" => true,
                "message" => "Pedido cancelado com sucesso",
                "refund_amount" => $refund_amount,
                "refund_status" => $refund_status
            ));
            
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(array("success" => false, "error" => "Erro ao cancelar: " . $e->getMessage()));
        }
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // LISTAR MOTIVOS
    // ══════════════════════════════════════════════════════════════════════════
    case "reasons":
        echo json_encode(array("success" => true, "reasons" => $CANCEL_REASONS));
        break;
    
    default:
        echo json_encode(array("success" => false, "error" => "Invalid action"));
}
