<?php
require_once __DIR__ . '/../config/database.php';
/**
 * 🔄 API DE SUBSTITUIÇÃO DE PRODUTOS
 */
header("Content-Type: application/json");
session_start();

$pdo = getPDO();

// Helper de notificações
if (file_exists(__DIR__ . "/../includes/cliente_notifications.php")) {
    require_once __DIR__ . "/../includes/cliente_notifications.php";
}

$input = json_decode(file_get_contents("php://input"), true);
$action = isset($input["action"]) ? $input["action"] : (isset($_GET["action"]) ? $_GET["action"] : "");

switch ($action) {
    
    // ══════════════════════════════════════════════════════════════════════════
    // SHOPPER: Sugerir substituição
    // ══════════════════════════════════════════════════════════════════════════
    case "suggest":
        $order_id = isset($input["order_id"]) ? intval($input["order_id"]) : 0;
        $order_product_id = isset($input["order_product_id"]) ? intval($input["order_product_id"]) : 0;
        $shopper_id = isset($input["shopper_id"]) ? intval($input["shopper_id"]) : 0;
        
        $original_product_id = isset($input["original_product_id"]) ? intval($input["original_product_id"]) : 0;
        $original_name = isset($input["original_name"]) ? trim($input["original_name"]) : "";
        $original_price = isset($input["original_price"]) ? floatval($input["original_price"]) : 0;
        $original_quantity = isset($input["original_quantity"]) ? intval($input["original_quantity"]) : 1;
        
        $suggested_product_id = isset($input["suggested_product_id"]) ? intval($input["suggested_product_id"]) : null;
        $suggested_name = isset($input["suggested_name"]) ? trim($input["suggested_name"]) : "";
        $suggested_price = isset($input["suggested_price"]) ? floatval($input["suggested_price"]) : 0;
        $suggested_quantity = isset($input["suggested_quantity"]) ? intval($input["suggested_quantity"]) : 1;
        $suggested_image = isset($input["suggested_image"]) ? trim($input["suggested_image"]) : null;
        
        $shopper_note = isset($input["note"]) ? trim($input["note"]) : null;
        
        if (!$order_id || !$original_name || !$suggested_name) {
            echo json_encode(array("success" => false, "error" => "Dados incompletos"));
            exit;
        }
        
        // Calcular diferença de preço
        $original_total = $original_price * $original_quantity;
        $suggested_total = $suggested_price * $suggested_quantity;
        $price_difference = $suggested_total - $original_total;
        
        // Expira em 10 minutos
        $expires_at = date("Y-m-d H:i:s", strtotime("+10 minutes"));
        
        // Inserir substituição
        $stmt = $pdo->prepare("
            INSERT INTO om_product_substitutions 
            (order_id, order_product_id, original_product_id, original_name, original_price, original_quantity,
             suggested_product_id, suggested_name, suggested_price, suggested_quantity, suggested_image,
             shopper_id, shopper_note, price_difference, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute(array(
            $order_id, $order_product_id, $original_product_id, $original_name, $original_price, $original_quantity,
            $suggested_product_id, $suggested_name, $suggested_price, $suggested_quantity, $suggested_image,
            $shopper_id, $shopper_note, $price_difference, $expires_at
        ));
        
        $substitution_id = $pdo->lastInsertId();
        
        // Mensagem no chat
        $diff_text = $price_difference > 0 ? "(+R$ " . number_format($price_difference, 2, ",", ".") . ")" : 
                    ($price_difference < 0 ? "(-R$ " . number_format(abs($price_difference), 2, ",", ".") . ")" : "(mesmo preço)");
        
        $msg = "🔄 *Sugestão de Substituição*\n\n";
        $msg .= "❌ Não encontrei: *$original_name*\n";
        $msg .= "✅ Sugestão: *$suggested_name* $diff_text\n";
        if ($shopper_note) $msg .= "\n💬 $shopper_note";
        $msg .= "\n\n⏰ Responda em até 10 minutos";
        
        $stmt = $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)");
        $stmt->execute(array($order_id, "shopper", $shopper_id, $msg));
        
        // Notificar cliente
        if (function_exists("notificarCliente")) {
            notificarCliente($pdo, $order_id, "substituicao", array(
                "original" => $original_name,
                "suggested" => $suggested_name
            ));
        }
        
        echo json_encode(array(
            "success" => true,
            "substitution_id" => $substitution_id,
            "expires_at" => $expires_at
        ));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // CLIENTE: Responder substituição
    // ══════════════════════════════════════════════════════════════════════════
    case "respond":
        $substitution_id = isset($input["substitution_id"]) ? intval($input["substitution_id"]) : 0;
        $response = isset($input["response"]) ? $input["response"] : ""; // approved ou rejected
        
        if (!$substitution_id || !in_array($response, array("approved", "rejected"))) {
            echo json_encode(array("success" => false, "error" => "Dados inválidos"));
            exit;
        }
        
        // Buscar substituição
        $stmt = $pdo->prepare("SELECT * FROM om_product_substitutions WHERE substitution_id = ?");
        $stmt->execute(array($substitution_id));
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$sub) {
            echo json_encode(array("success" => false, "error" => "Substituição não encontrada"));
            exit;
        }
        
        if ($sub["status"] != "pending") {
            echo json_encode(array("success" => false, "error" => "Substituição já foi respondida"));
            exit;
        }
        
        // Verificar expiração
        if (strtotime($sub["expires_at"]) < time()) {
            $pdo->prepare("UPDATE om_product_substitutions SET status = \"expired\" WHERE substitution_id = ?")->execute(array($substitution_id));
            echo json_encode(array("success" => false, "error" => "Tempo expirado"));
            exit;
        }
        
        // Atualizar status
        $stmt = $pdo->prepare("UPDATE om_product_substitutions SET status = ?, customer_response_at = NOW() WHERE substitution_id = ?");
        $stmt->execute(array($response, $substitution_id));
        
        // Se aprovado, atualizar produto no pedido
        if ($response == "approved") {
            $stmt = $pdo->prepare("
                UPDATE om_market_order_items 
                SET product_id = ?,
                    name = ?,
                    price = ?,
                    quantity = ?,
                    total = ? * ?,
                    is_substitution = 1,
                    original_product_id = ?
                WHERE id = ?
            ");
            $stmt->execute(array(
                $sub["suggested_product_id"],
                $sub["suggested_name"],
                $sub["suggested_price"],
                $sub["suggested_quantity"],
                $sub["suggested_price"], $sub["suggested_quantity"],
                $sub["original_product_id"],
                $sub["order_product_id"]
            ));
            
            // Atualizar total do pedido
            $stmt = $pdo->prepare("
                UPDATE om_market_orders o
                SET total = (SELECT SUM(total) FROM om_market_order_items WHERE order_id = o.order_id)
                WHERE order_id = ?
            ");
            $stmt->execute(array($sub["order_id"]));
            
            $msg = "✅ Substituição *APROVADA*\n\n{$sub["original_name"]} → {$sub["suggested_name"]}";
        } else {
            $msg = "❌ Substituição *RECUSADA*\n\n{$sub["original_name"]} será removido do pedido";
            
            // Marcar produto como removido
            $stmt = $pdo->prepare("UPDATE om_market_order_items SET is_removed = 1 WHERE id = ?");
            $stmt->execute(array($sub["order_product_id"]));
        }
        
        // Mensagem no chat
        $stmt = $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)");
        $stmt->execute(array($sub["order_id"], "system", 0, $msg));
        
        // Processar ajuste de preço automaticamente
        if ($response == "approved") {
            $adj_input = json_encode(array(
                "action" => "substitution_price_change",
                "substitution_id" => $substitution_id
            ));
            
            // Chamar API de ajustes
            $ch = curl_init("http://" . $_SERVER["HTTP_HOST"] . "/mercado/api/adjustments.php");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $adj_input);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            curl_close($ch);
        }
        
        echo json_encode(array("success" => true, "status" => $response));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // LISTAR SUBSTITUIÇÕES PENDENTES
    // ══════════════════════════════════════════════════════════════════════════
    case "pending":
        $order_id = isset($_GET["order_id"]) ? intval($_GET["order_id"]) : 0;
        
        $stmt = $pdo->prepare("
            SELECT * FROM om_product_substitutions 
            WHERE order_id = ? AND status = \"pending\" AND expires_at > NOW()
            ORDER BY created_at DESC
        ");
        $stmt->execute(array($order_id));
        $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(array("success" => true, "substitutions" => $subs));
        break;
    
    default:
        echo json_encode(array("success" => false, "error" => "Invalid action"));
}
