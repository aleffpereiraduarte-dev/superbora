<?php
/**
 * GET /api/mercado/intelligence/driver-assignment-explainer.php?order_id=123&driver_id=456
 * Generates a transparency message for the driver/shopper explaining
 * why they were chosen for this order.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $orderId = (int)($_GET['order_id'] ?? 0);
    $driverId = (int)($_GET['driver_id'] ?? 0);
    if (!$orderId || !$driverId) response(false, null, 'order_id e driver_id obrigatorios', 400);

    $db = getDB();
    $stmt = $db->prepare(
        "SELECT o.total, o.created_at,
                p.name AS partner_name, p.address_city,
                s.nome AS driver_name, s.rating AS driver_rating, s.veiculo
         FROM om_market_orders o
         LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
         LEFT JOIN om_market_shoppers s ON s.shopper_id = :did
         WHERE o.order_id = :oid"
    );
    $stmt->execute([':oid' => $orderId, ':did' => $driverId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) response(false, null, 'dados nao encontrados', 404);

    $prompt = "Pedido #{$orderId} de {$row['partner_name']} foi atribuido ao entregador {$row['driver_name']} " .
              "(rating {$row['driver_rating']}, {$row['veiculo']}). " .
              "Em pt-BR max 200 chars, explique pro entregador por que ele foi escolhido. " .
              "Tom: profissional e motivador.";

    $reply = ClaudeClient::text($prompt, 'Voce eh o sistema de matching. Explique decisoes de forma clara.', 250);
    if (!$reply) response(false, null, 'falha explicacao', 502);

    response(true, ['order_id' => $orderId, 'driver_id' => $driverId, 'explanation' => $reply]);
} catch (Exception $e) {
    error_log('[driver-explainer] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
