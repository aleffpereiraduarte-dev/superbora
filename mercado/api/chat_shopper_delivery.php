<?php
/**
 * API Chat entre Shopper e Delivery
 * Actions: send, get, mark_read
 */
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once dirname(__DIR__) . '/config/database.php';
$pdo = getPDO();

$action = $_REQUEST["action"] ?? "";

switch ($action) {
    case "send":
        $order_id = (int)($_POST["order_id"] ?? 0);
        $sender_type = $_POST["sender_type"] ?? ""; // shopper ou delivery
        $sender_id = (int)($_POST["sender_id"] ?? 0);
        $message = trim($_POST["message"] ?? "");

        if (!$order_id || !$sender_type || !$sender_id || !$message) {
            echo json_encode(["success" => false, "error" => "Dados incompletos"]);
            exit;
        }

        // Buscar IDs do pedido
        $stmt = $pdo->prepare("SELECT shopper_id, delivery_id FROM om_market_orders WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            echo json_encode(["success" => false, "error" => "Pedido nao encontrado"]);
            exit;
        }

        $shopper_id = $order["shopper_id"];
        $delivery_id = $order["delivery_id"];

        $stmt = $pdo->prepare("INSERT INTO om_shopper_delivery_chat (order_id, shopper_id, delivery_id, sender_type, message) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$order_id, $shopper_id, $delivery_id, $sender_type, $message]);

        echo json_encode(["success" => true, "message_id" => $pdo->lastInsertId()]);
        break;

    case "get":
        $order_id = (int)($_GET["order_id"] ?? 0);
        $after_id = (int)($_GET["after_id"] ?? 0);

        $sql = "SELECT * FROM om_shopper_delivery_chat WHERE order_id = ?";
        $params = [$order_id];

        if ($after_id) {
            $sql .= " AND id > ?";
            $params[] = $after_id;
        }
        $sql .= " ORDER BY sent_at ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["success" => true, "messages" => $messages]);
        break;

    case "mark_read":
        $order_id = (int)($_POST["order_id"] ?? 0);
        $reader_type = $_POST["reader_type"] ?? "";

        $stmt = $pdo->prepare("UPDATE om_shopper_delivery_chat SET read_at = NOW()
            WHERE order_id = ? AND sender_type != ? AND read_at IS NULL");
        $stmt->execute([$order_id, $reader_type]);

        echo json_encode(["success" => true]);
        break;

    default:
        echo json_encode(["success" => false, "actions" => ["send","get","mark_read"]]);
}
