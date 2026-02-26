<?php
/**
 * ONEMUNDO MERCADO - API Pedidos
 */

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// Config
require_once dirname(__DIR__) . '/config/database.php';

try {
    $pdo = getPDO();
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => "Erro de conexao"]);
    exit;
}

$action = $_GET["action"] ?? $_POST["action"] ?? "";
$method = $_SERVER["REQUEST_METHOD"];

switch ($action) {

    case "list":
        $status = $_GET["status"] ?? "";
        $shopper_id = $_GET["shopper_id"] ?? "";
        $limit = min((int)($_GET["limit"] ?? 20), 100);

        $sql = "SELECT o.*, c.firstname, c.lastname, c.email, c.telephone
                FROM om_market_orders o
                LEFT JOIN oc_customer c ON o.customer_id = c.customer_id
                WHERE 1=1";
        $params = [];

        if ($status) {
            $sql .= " AND o.status = ?";
            $params[] = $status;
        }
        if ($shopper_id) {
            $sql .= " AND o.shopper_id = ?";
            $params[] = (int)$shopper_id;
        }

        $sql .= " ORDER BY o.date_added DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["success" => true, "orders" => $orders]);
        break;

    case "get":
        $id = (int)($_GET["id"] ?? 0);
        if (!$id) {
            echo json_encode(["success" => false, "error" => "ID obrigatorio"]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT o.*, c.firstname, c.lastname, c.email, c.telephone
                FROM om_market_orders o
                LEFT JOIN oc_customer c ON o.customer_id = c.customer_id
                WHERE o.order_id = ?");
        $stmt->execute([$id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($order) {
            // Buscar itens
            $stmt = $pdo->prepare("SELECT * FROM om_market_order_items WHERE order_id = ?");
            $stmt->execute([$id]);
            $order["items"] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(["success" => true, "order" => $order]);
        } else {
            echo json_encode(["success" => false, "error" => "Pedido nao encontrado"]);
        }
        break;

    case "update_status":
        $id = (int)($_POST["id"] ?? 0);
        $status = $_POST["status"] ?? "";

        if (!$id || !$status) {
            echo json_encode(["success" => false, "error" => "ID e status obrigatorios"]);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE om_market_orders SET status = ?, date_modified = NOW() WHERE order_id = ?");
        $stmt->execute([$status, $id]);

        echo json_encode(["success" => true]);
        break;

    case "assign_shopper":
        $order_id = (int)($_POST["order_id"] ?? 0);
        $shopper_id = (int)($_POST["shopper_id"] ?? 0);

        if (!$order_id || !$shopper_id) {
            echo json_encode(["success" => false, "error" => "Dados obrigatorios"]);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE om_market_orders SET shopper_id = ?, status = 'accepted', date_modified = NOW() WHERE order_id = ?");
        $stmt->execute([$shopper_id, $order_id]);

        echo json_encode(["success" => true]);
        break;

    default:
        echo json_encode([
            "success" => true,
            "message" => "API Pedidos Mercado",
            "actions" => ["list", "get", "update_status", "assign_shopper"]
        ]);
}
