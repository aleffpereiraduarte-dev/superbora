<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * POST /api/mercado/shopper/stop-tracking.php
 * Finaliza o tracking de um pedido (chamar quando entregar)
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REQUER: Autenticacao de Shopper
 * Header: Authorization: Bearer <token>
 *
 * Body: {
 *   "order_id": 123,
 *   "latitude": -23.5505,    // Posicao final (opcional)
 *   "longitude": -46.6333
 * }
 *
 * Response: {
 *   "success": true,
 *   "data": {
 *     "tracking_stopped": true
 *   }
 * }
 */

require_once __DIR__ . "/../config/auth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/PusherService.php";

setCorsHeaders();

try {
    $input = getInput();
    $db = getDB();

    // ═══════════════════════════════════════════════════════════════════
    // AUTENTICACAO
    // ═══════════════════════════════════════════════════════════════════
    $auth = requireShopperAuth();
    $shopper_id = $auth['uid'];

    // ═══════════════════════════════════════════════════════════════════
    // VALIDACAO
    // ═══════════════════════════════════════════════════════════════════
    $order_id = isset($input["order_id"]) ? (int)$input["order_id"] : null;
    $lat = isset($input["latitude"]) ? floatval($input["latitude"]) : null;
    $lng = isset($input["longitude"]) ? floatval($input["longitude"]) : null;

    if (!$order_id) {
        response(false, null, "order_id e obrigatorio", 400);
    }

    // Verificar se o pedido pertence a este shopper
    $stmt = $db->prepare("
        SELECT order_id, status, customer_id
        FROM om_market_orders
        WHERE order_id = ?
        AND shopper_id = ?
    ");
    $stmt->execute([$order_id, $shopper_id]);
    $order = $stmt->fetch();

    if (!$order) {
        response(false, null, "Pedido nao encontrado ou nao atribuido a voce", 404);
    }

    // ═══════════════════════════════════════════════════════════════════
    // ATUALIZAR TRACKING COMO ENTREGUE
    // ═══════════════════════════════════════════════════════════════════
    $stmt = $db->prepare("
        UPDATE om_delivery_tracking_live
        SET status = 'entregue',
            eta_minutes = 0,
            distance_km = 0,
            updated_at = NOW()
        WHERE order_id = ?
    ");
    $stmt->execute([$order_id]);

    // Salvar posicao final no historico
    if ($lat && $lng) {
        $stmt = $db->prepare("
            INSERT INTO om_delivery_locations
            (order_id, worker_id, latitude, longitude)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$order_id, $shopper_id, $lat, $lng]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // NOTIFICAR CLIENTE VIA PUSHER
    // ═══════════════════════════════════════════════════════════════════
    try {
        PusherService::driverArrived($order_id);
        PusherService::orderStatusUpdate($order_id, 'entregue', 'Pedido entregue!');
    } catch (Exception $e) {
        error_log("[stop-tracking] Pusher error: " . $e->getMessage());
    }

    response(true, [
        'tracking_stopped' => true,
        'order_id' => $order_id
    ], "Tracking finalizado!");

} catch (Exception $e) {
    error_log("[stop-tracking] Erro: " . $e->getMessage());
    response(false, null, "Erro ao finalizar tracking", 500);
}
