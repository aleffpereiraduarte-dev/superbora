<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
/**
 * API: Listar Ofertas Disponíveis
 * GET /api/offers.php
 */
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

session_start();

$workerId = $_SESSION["worker_id"] ?? 0;
if (!$workerId) {
    echo json_encode(["success" => false, "error" => "Não autenticado"]);
    exit;
}

try {
    $pdo = getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Buscar worker
    $stmt = $pdo->prepare("SELECT * FROM om_market_workers WHERE worker_id = ?");
    $stmt->execute([$workerId]);
    $worker = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$worker) {
        throw new Exception("Worker não encontrado");
    }
    
    if (!$worker["is_online"]) {
        echo json_encode(["success" => true, "offers" => [], "message" => "Você está offline"]);
        exit;
    }
    
    $lat = $worker["current_lat"] ?? -23.55;
    $lng = $worker["current_lng"] ?? -46.63;
    $maxDistance = $worker["max_distance_km"] ?? 10;
    $workerType = $worker["worker_type"];
    $workMode = $worker["work_mode"] ?? "both";
    
    // Determinar status válidos
    $validStatuses = [];
    if ($workerType === "shopper" || ($workerType === "full_service" && in_array($workMode, ["shopping", "both"]))) {
        $validStatuses[] = "paid";
    }
    if ($workerType === "driver" || ($workerType === "full_service" && in_array($workMode, ["delivery", "both"]))) {
        $validStatuses[] = "ready_for_pickup";
    }
    
    if (empty($validStatuses)) {
        echo json_encode(["success" => true, "offers" => []]);
        exit;
    }
    
    $placeholders = implode(",", array_fill(0, count($validStatuses), "?"));
    
    $sql = "SELECT o.order_id, o.status, o.total_items, o.subtotal, o.worker_fee,
                   o.delivery_address, o.delivery_lat, o.delivery_lng,
                   p.store_name, p.store_address, p.store_lat, p.store_lng,
                   (6371 * acos(cos(radians(?)) * cos(radians(p.store_lat)) * 
                    cos(radians(p.store_lng) - radians(?)) + sin(radians(?)) * 
                    sin(radians(p.store_lat)))) AS distance
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.status IN ($placeholders)
            AND o.worker_id IS NULL
            HAVING distance <= ?
            ORDER BY distance ASC, o.created_at ASC
            LIMIT 20";
    
    $params = array_merge([$lat, $lng, $lat], $validStatuses, [$maxDistance]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatar ofertas
    foreach ($offers as &$offer) {
        $offer["distance"] = round($offer["distance"], 1);
        $offer["type"] = $offer["status"] === "paid" ? "shopping" : "delivery";
        $offer["worker_fee"] = floatval($offer["worker_fee"] ?? 15);
    }
    
    echo json_encode([
        "success" => true,
        "offers" => $offers,
        "count" => count($offers)
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}