<?php
/**
 * API Mensagens Automaticas
 * Actions: trigger, get_templates, update_template
 */
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once dirname(__DIR__) . '/config/database.php';
$pdo = getPDO();

$action = $_REQUEST["action"] ?? "";

switch ($action) {
    case "trigger":
        $event = $_POST["event"] ?? "";
        $order_id = (int)($_POST["order_id"] ?? 0);

        if (!$event || !$order_id) {
            echo json_encode(["success" => false, "error" => "Event e order_id obrigatorios"]);
            exit;
        }

        // Buscar template
        $stmt = $pdo->prepare("SELECT message_template FROM om_auto_messages WHERE trigger_event = ? AND is_active = 1");
        $stmt->execute([$event]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$template) {
            echo json_encode(["success" => false, "error" => "Template nao encontrado para: $event"]);
            exit;
        }

        // Buscar dados do pedido
        $stmt = $pdo->prepare("SELECT o.*,
            s.name as shopper_name,
            d.name as delivery_name,
            o.delivery_code
            FROM om_market_orders o
            LEFT JOIN om_market_shoppers s ON o.shopper_id = s.shopper_id
            LEFT JOIN om_market_deliveries d ON o.delivery_id = d.delivery_id
            WHERE o.order_id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            echo json_encode(["success" => false, "error" => "Pedido nao encontrado"]);
            exit;
        }

        // Substituir placeholders
        $message = $template["message_template"];
        $message = str_replace("{cliente_nome}", $order["customer_name"] ?? "Cliente", $message);
        $message = str_replace("{shopper_nome}", $order["shopper_name"] ?? "Shopper", $message);
        $message = str_replace("{delivery_nome}", $order["delivery_name"] ?? "Entregador", $message);
        $message = str_replace("{codigo}", $order["delivery_code"] ?? "", $message);
        $message = str_replace("{order_number}", $order["order_number"] ?? $order_id, $message);

        // Salvar mensagem no chat
        $stmt = $pdo->prepare("INSERT INTO om_market_chat (order_id, sender_type, sender_id, sender_name, message, message_type) VALUES (?, ?, ?, ?, ?, ?)");
        $sender_type = "system";
        $sender_id = 0;
        $sender_name = "Sistema";
        $message_type = "auto";
        $stmt->execute([$order_id, $sender_type, $sender_id, $sender_name, $message, $message_type]);

        echo json_encode(["success" => true, "message" => $message, "message_id" => $pdo->lastInsertId()]);
        break;

    case "get_templates":
        $result = $pdo->query("SELECT * FROM om_auto_messages ORDER BY trigger_event");
        $templates = $result->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["success" => true, "templates" => $templates]);
        break;

    case "update_template":
        $event = $_POST["event"] ?? "";
        $template = $_POST["template"] ?? "";
        $is_active = (int)($_POST["is_active"] ?? 1);

        $stmt = $pdo->prepare("UPDATE om_auto_messages SET message_template = ?, is_active = ? WHERE trigger_event = ?");
        $stmt->execute([$template, $is_active, $event]);

        echo json_encode(["success" => true, "affected" => $stmt->rowCount()]);
        break;

    default:
        echo json_encode(["success" => false, "actions" => ["trigger","get_templates","update_template"]]);
}
