<?php
/**
 * API OFERTAS DELIVERY - OneMundo Mercado
 */
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");

session_start();

require_once dirname(__DIR__) . "/config/database.php";

try {
    $pdo = getPDO();
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => "Erro de conexão"]);
    exit;
}

$action = $_GET["action"] ?? $_POST["action"] ?? "list";
$delivery_id = $_SESSION["delivery_id"] ?? $_GET["delivery_id"] ?? $_POST["delivery_id"] ?? 0;

switch ($action) {
    case "list":
        // Listar ofertas disponíveis para o delivery
        $stmt = $pdo->prepare("
            SELECT o.*, 
                   p.trade_name as partner_name,
                   p.address as partner_address,
                   p.latitude as partner_lat,
                   p.longitude as partner_lng,
                   s.name as shopper_name
            FROM om_delivery_offers o
            LEFT JOIN om_market_orders ord ON o.order_id = ord.order_id
            LEFT JOIN om_market_partners p ON ord.partner_id = p.id
            LEFT JOIN om_market_shoppers s ON ord.shopper_id = s.id
            WHERE o.delivery_id = ? AND o.status = \"pending\"
            ORDER BY o.created_at DESC
        ");
        $stmt->execute([$delivery_id]);
        $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(["success" => true, "offers" => $offers]);
        break;
        
    case "accept":
        $offer_id = $_POST["offer_id"] ?? 0;
        $order_id = $_POST["order_id"] ?? 0;
        
        if (!$delivery_id || !$order_id) {
            echo json_encode(["success" => false, "error" => "Dados inválidos"]);
            exit;
        }
        
        $pdo->beginTransaction();
        try {
            // Atualizar oferta
            $stmt = $pdo->prepare("UPDATE om_delivery_offers SET status = \"accepted\", accepted_at = NOW() WHERE order_id = ? AND delivery_id = ?");
            $stmt->execute([$order_id, $delivery_id]);
            
            // Atualizar pedido
            $stmt = $pdo->prepare("UPDATE om_market_orders SET delivery_id = ?, status = \"delivering\" WHERE order_id = ?");
            $stmt->execute([$delivery_id, $order_id]);
            
            // Rejeitar outras ofertas
            $stmt = $pdo->prepare("UPDATE om_delivery_offers SET status = \"rejected\" WHERE order_id = ? AND delivery_id != ?");
            $stmt->execute([$order_id, $delivery_id]);
            
            $pdo->commit();
            echo json_encode(["success" => true, "message" => "Oferta aceita!"]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(["success" => false, "error" => $e->getMessage()]);
        }
        break;
        
    case "reject":
        $offer_id = $_POST["offer_id"] ?? 0;
        $order_id = $_POST["order_id"] ?? 0;
        
        $stmt = $pdo->prepare("UPDATE om_delivery_offers SET status = \"rejected\" WHERE order_id = ? AND delivery_id = ?");
        $stmt->execute([$order_id, $delivery_id]);
        
        echo json_encode(["success" => true]);
        break;
        
    default:
        echo json_encode(["success" => false, "error" => "Ação inválida"]);
}
?>