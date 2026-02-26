<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * POST /api/mercado/shopper/localizacao.php
 * Atualiza GPS do shopper
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REQUER: Autenticação de Shopper
 * Header: Authorization: Bearer <token>
 *
 * Body: {
 *   "latitude": -23.5505,
 *   "longitude": -46.6333
 * }
 *
 * SEGURANÇA:
 * - ✅ Autenticação obrigatória
 * - ✅ Validação de coordenadas
 * - ✅ Prepared statements
 */

require_once __DIR__ . "/../config/auth.php";

setCorsHeaders();

try {
    $input = getInput();
    $db = getDB();

    // ═══════════════════════════════════════════════════════════════════
    // AUTENTICAÇÃO
    // ═══════════════════════════════════════════════════════════════════
    $auth = requireShopperAuth();
    $shopper_id = $auth['uid'];

    $lat = $input["latitude"] ?? $input["lat"] ?? null;
    $lng = $input["longitude"] ?? $input["lng"] ?? null;

    // Validação de dados
    if ($lat === null || $lng === null) {
        response(false, null, "latitude e longitude são obrigatórios", 400);
    }

    $lat = floatval($lat);
    $lng = floatval($lng);

    // Validar range de coordenadas
    if ($lat < -90 || $lat > 90) {
        response(false, null, "Latitude inválida. Deve estar entre -90 e 90", 400);
    }

    if ($lng < -180 || $lng > 180) {
        response(false, null, "Longitude inválida. Deve estar entre -180 e 180", 400);
    }

    // Atualizar localização
    $stmt = $db->prepare("
        UPDATE om_market_shoppers SET
            latitude = ?,
            longitude = ?,
            ultima_atividade = NOW()
        WHERE shopper_id = ?
    ");
    $stmt->execute([$lat, $lng, $shopper_id]);

    // Verificar se tem pedido ativo
    $stmt = $db->prepare("
        SELECT order_id, status, delivery_address
        FROM om_market_orders
        WHERE shopper_id = ?
        AND status IN ('aceito', 'coletando', 'coleta_finalizada', 'em_entrega')
        LIMIT 1
    ");
    $stmt->execute([$shopper_id]);
    $pedido = $stmt->fetch();

    // Feature 1: Atualizar delivery_tracking para rastreamento em tempo real
    if ($pedido) {
        $stmtTrack = $db->prepare("
            INSERT INTO om_market_delivery_tracking (order_id, shopper_id, last_lat, last_lng, last_location_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE last_lat = VALUES(last_lat), last_lng = VALUES(last_lng), last_location_at = NOW()
        ");
        $stmtTrack->execute([$pedido['order_id'], $shopper_id, $lat, $lng]);
    }

    response(true, [
        "latitude" => $lat,
        "longitude" => $lng,
        "atualizado_em" => date('c'),
        "pedido_ativo" => $pedido ? [
            "order_id" => $pedido["order_id"],
            "status" => $pedido["status"],
            "endereco_entrega" => $pedido["delivery_address"]
        ] : null
    ], "Localização atualizada!");

} catch (Exception $e) {
    error_log("[localizacao] Erro: " . $e->getMessage());
    response(false, null, "Erro ao atualizar localização. Tente novamente.", 500);
}
