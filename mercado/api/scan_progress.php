<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * API para atualizar progresso do scan
 * Chamada pelo app do shopper quando escaneia um produto
 */

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die(json_encode(["error" => "DB error"]));
}

$input = json_decode(file_get_contents("php://input"), true);
$order_id = intval($input["order_id"] ?? $_GET["order_id"] ?? 0);
$action = $input["action"] ?? $_GET["action"] ?? "";

if (!$order_id) {
    die(json_encode(["error" => "order_id required"]));
}

switch ($action) {
    case "scan_item":
        // Incrementar items_scanned e recalcular progress
        $pdo->prepare("
            UPDATE om_market_orders 
            SET items_scanned = items_scanned + 1,
                scan_progress = CASE 
                    WHEN items_total > 0 THEN ROUND((items_scanned + 1) / items_total * 100, 2)
                    ELSE 0
                END
            WHERE order_id = ?
        ")->execute([$order_id]);
        
        // Buscar valores atualizados
        $stmt = $pdo->prepare("SELECT items_scanned, items_total, scan_progress FROM om_market_orders WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            "success" => true,
            "items_scanned" => intval($order["items_scanned"]),
            "items_total" => intval($order["items_total"]),
            "scan_progress" => floatval($order["scan_progress"])
        ]);
        break;
        
    case "get_progress":
        $stmt = $pdo->prepare("SELECT items_scanned, items_total, scan_progress, driver_dispatch_at FROM om_market_orders WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            "success" => true,
            "items_scanned" => intval($order["items_scanned"] ?? 0),
            "items_total" => intval($order["items_total"] ?? 0),
            "scan_progress" => floatval($order["scan_progress"] ?? 0),
            "driver_dispatched" => !empty($order["driver_dispatch_at"])
        ]);
        break;
        
    default:
        echo json_encode(["error" => "action required: scan_item or get_progress"]);
}
