<?php
/**
 * GET/POST /mercado/api/shopper/status.php
 * Status do shopper (online/offline)
 */
require_once __DIR__ . "/../config.php";

try {
    $db = getDB();

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $shopper_id = intval($_GET["shopper_id"] ?? 0);

        if (!$shopper_id) {
            response(false, null, "shopper_id obrigatório", 400);
        }

        $stmt = $db->prepare("SELECT shopper_id, name, email, phone, photo, rating, online, disponivel, saldo, total_orders, latitude, longitude
                              FROM om_market_shoppers WHERE shopper_id = ?");
        $stmt->execute([$shopper_id]);
        $shopper = $stmt->fetch();

        if (!$shopper) {
            response(false, null, "Shopper não encontrado", 404);
        }

        // Verificar pedido ativo
        $stmt = $db->prepare("SELECT order_id, status FROM om_market_orders
                              WHERE shopper_id = ?
                              AND status IN ('aceito','coletando','em_entrega')
                              LIMIT 1");
        $stmt->execute([$shopper_id]);
        $pedido_ativo = $stmt->fetch();

        response(true, [
            "shopper" => $shopper,
            "pedido_ativo" => $pedido_ativo ?: null
        ]);
    }

    // POST - Atualizar status
    $input = getInput();

    $shopper_id = intval($input["shopper_id"] ?? 0);
    $online = isset($input["online"]) ? ($input["online"] ? 1 : 0) : null;
    $lat = isset($input["latitude"]) ? floatval($input["latitude"]) : null;
    $lng = isset($input["longitude"]) ? floatval($input["longitude"]) : null;

    if (!$shopper_id) {
        response(false, null, "shopper_id obrigatório", 400);
    }

    $updates = [];
    $params = [];

    if ($online !== null) {
        $updates[] = "online = ?";
        $params[] = $online;
    }
    if ($lat !== null) {
        $updates[] = "latitude = ?";
        $params[] = $lat;
    }
    if ($lng !== null) {
        $updates[] = "longitude = ?";
        $params[] = $lng;
    }
    $updates[] = "ultima_atividade = NOW()";
    $params[] = $shopper_id;

    $stmt = $db->prepare("UPDATE om_market_shoppers SET " . implode(", ", $updates) . " WHERE shopper_id = ?");
    $stmt->execute($params);

    response(true, [
        "shopper_id" => $shopper_id,
        "online" => $online,
        "latitude" => $lat,
        "longitude" => $lng
    ], $online ? "Você está online!" : "Você está offline");

} catch (Exception $e) {
    response(false, null, $e->getMessage(), 500);
}
