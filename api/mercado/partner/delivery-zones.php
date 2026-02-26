<?php
/**
 * GET /api/mercado/partner/delivery-zones.php - Listar zonas de entrega
 * POST /api/mercado/partner/delivery-zones.php - Criar/atualizar zona
 * DELETE /api/mercado/partner/delivery-zones.php?id=X - Desativar zona
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();

    // Table om_partner_delivery_zones created via migration
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = $payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $stmt = $db->prepare("
            SELECT * FROM om_partner_delivery_zones
            WHERE partner_id = ? AND status = '1'
            ORDER BY radius_min_km ASC
        ");
        $stmt->execute([$partnerId]);
        $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

        response(true, ['zones' => $zones]);
    }

    if ($method === 'POST') {
        $input = getInput();
        $zoneId = intval($input['id'] ?? 0);
        $label = trim($input['label'] ?? '');
        $radiusMin = floatval($input['radius_min_km'] ?? 0);
        $radiusMax = floatval($input['radius_max_km'] ?? 5);
        $fee = floatval($input['fee'] ?? 5);
        $estimatedTime = trim($input['estimated_time'] ?? '30-45 min');

        if (empty($label)) {
            response(false, null, "Nome da zona e obrigatorio", 400);
        }

        if ($radiusMax <= $radiusMin) {
            response(false, null, "Raio maximo deve ser maior que o minimo", 400);
        }

        if ($fee < 0) {
            response(false, null, "Taxa nao pode ser negativa", 400);
        }

        if ($zoneId > 0) {
            $stmt = $db->prepare("
                UPDATE om_partner_delivery_zones
                SET label = ?, radius_min_km = ?, radius_max_km = ?, fee = ?, estimated_time = ?
                WHERE id = ? AND partner_id = ?
            ");
            $stmt->execute([$label, $radiusMin, $radiusMax, $fee, $estimatedTime, $zoneId, $partnerId]);
            if ($stmt->rowCount() === 0) {
                response(false, null, "Zona nao encontrada", 404);
            }
        } else {
            // Use transaction + FOR UPDATE to prevent sort_order race condition
            $db->beginTransaction();
            $stmtMax = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM om_partner_delivery_zones WHERE partner_id = ? FOR UPDATE");
            $stmtMax->execute([$partnerId]);
            $nextSort = (int)$stmtMax->fetchColumn();

            $stmt = $db->prepare("
                INSERT INTO om_partner_delivery_zones (partner_id, label, radius_min_km, radius_max_km, fee, estimated_time, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                RETURNING id
            ");
            $stmt->execute([$partnerId, $label, $radiusMin, $radiusMax, $fee, $estimatedTime, $nextSort]);
            $zoneId = $stmt->fetchColumn();
            $db->commit();
        }

        response(true, ['id' => $zoneId], "Zona salva!");
    }

    if ($method === 'DELETE') {
        $id = intval($_GET['id'] ?? 0);
        if (!$id) response(false, null, "ID obrigatorio", 400);

        $stmt = $db->prepare("UPDATE om_partner_delivery_zones SET status = '0' WHERE id = ? AND partner_id = ?");
        $stmt->execute([$id, $partnerId]);
        if ($stmt->rowCount() === 0) {
            response(false, null, "Zona nao encontrada", 404);
        }

        response(true, null, "Zona removida!");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/delivery-zones] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
