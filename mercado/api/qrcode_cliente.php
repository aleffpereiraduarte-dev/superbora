<?php
/**
 * API QR Code para Confirmacao do Cliente
 * Actions: generate, validate, get
 */
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once dirname(__DIR__) . '/config/database.php';
$pdo = getPDO();

$action = $_REQUEST["action"] ?? "";

switch ($action) {
    case "generate":
        $order_id = (int)($_POST["order_id"] ?? 0);

        if (!$order_id) {
            echo json_encode(["success" => false, "error" => "Order ID obrigatorio"]);
            exit;
        }

        // Verificar se ja tem QR
        $stmt = $pdo->prepare("SELECT customer_qr_code FROM om_market_orders WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($order && !empty($order["customer_qr_code"])) {
            echo json_encode(["success" => true, "qr_code" => $order["customer_qr_code"], "exists" => true]);
            exit;
        }

        // Gerar novo QR
        $qr_code = "CLIENTE-" . strtoupper(substr(md5($order_id . time() . rand()), 0, 8));

        $stmt = $pdo->prepare("UPDATE om_market_orders SET customer_qr_code = ? WHERE order_id = ?");
        $stmt->execute([$qr_code, $order_id]);

        echo json_encode(["success" => true, "qr_code" => $qr_code, "exists" => false]);
        break;

    case "validate":
        $qr_code = $_POST["qr_code"] ?? "";
        $order_id = (int)($_POST["order_id"] ?? 0);

        if (!$qr_code) {
            echo json_encode(["success" => false, "error" => "QR Code obrigatorio"]);
            exit;
        }

        $sql = "SELECT order_id, customer_qr_code, customer_confirmed_at FROM om_market_orders WHERE customer_qr_code = ?";
        $params = [$qr_code];

        if ($order_id) {
            $sql .= " AND order_id = ?";
            $params[] = $order_id;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            if ($row["customer_confirmed_at"]) {
                echo json_encode(["success" => false, "error" => "QR Code ja foi usado", "confirmed_at" => $row["customer_confirmed_at"]]);
            } else {
                // Marcar como confirmado
                $stmt = $pdo->prepare("UPDATE om_market_orders SET customer_confirmed_at = NOW(), status = 'delivered' WHERE order_id = ?");
                $stmt->execute([$row["order_id"]]);
                echo json_encode(["success" => true, "order_id" => $row["order_id"], "message" => "Entrega confirmada!"]);
            }
        } else {
            echo json_encode(["success" => false, "error" => "QR Code invalido"]);
        }
        break;

    case "get":
        $order_id = (int)($_GET["order_id"] ?? 0);

        $stmt = $pdo->prepare("SELECT customer_qr_code, customer_confirmed_at FROM om_market_orders WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            echo json_encode(["success" => true, "qr_code" => $row["customer_qr_code"], "confirmed_at" => $row["customer_confirmed_at"]]);
        } else {
            echo json_encode(["success" => false, "error" => "Pedido nao encontrado"]);
        }
        break;

    default:
        echo json_encode(["success" => false, "actions" => ["generate","validate","get"]]);
}
