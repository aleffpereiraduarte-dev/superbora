<?php
/**
 * API DE NOTIFICACOES
 */

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../config.php";

// Funcao para obter cliente do OpenCart/sessao
function getMarketCustomer() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Verificar sessao do mercado
    if (isset($_SESSION["market_customer_id"]) && $_SESSION["market_customer_id"] > 0) {
        return ["id" => $_SESSION["market_customer_id"]];
    }

    // Verificar sessao do OpenCart
    if (isset($_SESSION["customer_id"]) && $_SESSION["customer_id"] > 0) {
        return ["id" => $_SESSION["customer_id"]];
    }

    return null;
}

try {
    $pdo = getDB();
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => "Erro de conexao"]);
    exit;
}

$customer = getMarketCustomer();
$customer_id = $customer["id"] ?? 0;

if (!$customer_id) {
    echo json_encode(["success" => false, "error" => "Nao autenticado"]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $action = $_GET["action"] ?? "list";

    if ($action === "list") {
        $sql = "SELECT * FROM om_order_notifications
                WHERE customer_id = :customer_id
                ORDER BY created_at DESC
                LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([":customer_id" => $customer_id]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["success" => true, "notifications" => $notifications]);
        exit;
    }

    if ($action === "unread_count") {
        $sql = "SELECT COUNT(*) as count FROM om_order_notifications
                WHERE customer_id = :customer_id AND is_read = 0";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([":customer_id" => $customer_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode(["success" => true, "count" => (int)$result["count"]]);
        exit;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);
    $action = $input["action"] ?? "";

    if ($action === "mark_read") {
        $notification_id = intval($input["notification_id"] ?? 0);

        if ($notification_id) {
            $sql = "UPDATE om_order_notifications SET is_read = 1
                    WHERE notification_id = :id AND customer_id = :customer_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([":id" => $notification_id, ":customer_id" => $customer_id]);
        } else {
            // Marcar todas como lidas
            $sql = "UPDATE om_order_notifications SET is_read = 1
                    WHERE customer_id = :customer_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([":customer_id" => $customer_id]);
        }

        echo json_encode(["success" => true]);
        exit;
    }
}

echo json_encode(["success" => false, "error" => "Requisicao invalida"]);
