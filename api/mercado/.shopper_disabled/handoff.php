<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
setCorsHeaders();
try {
    $db = getDB(); OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requireShopper();
    $shopper_id = $payload['sub'] ?? $payload['uid'];
    $input = getInput();
    $order_id = (int)($input['order_id'] ?? 0);
    if (!$order_id) response(false, null, "order_id obrigatório", 400);

    // Verify order ownership
    $stmtCheck = $db->prepare("SELECT 1 FROM om_market_orders WHERE order_id = ? AND shopper_id = ?");
    $stmtCheck->execute([$order_id, $shopper_id]);
    if (!$stmtCheck->fetch()) response(false, null, "Pedido nao encontrado", 404);

    try {
        $db->prepare("INSERT INTO om_order_handoff (order_id, qr_code, photos, confirmed, created_at) VALUES (?,?,?,1,NOW())")
            ->execute([$order_id, $input['qr_code'] ?? '', json_encode($input['photos'] ?? [])]);
    } catch(Exception $e) {
        $db->exec("CREATE TABLE IF NOT EXISTS om_order_handoff (id SERIAL PRIMARY KEY, order_id INT, qr_code VARCHAR(255), photos JSON, driver_id INT, confirmed SMALLINT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        $db->prepare("INSERT INTO om_order_handoff (order_id, qr_code, photos, confirmed, created_at) VALUES (?,?,?,1,NOW())")
            ->execute([$order_id, $input['qr_code'] ?? '', json_encode($input['photos'] ?? [])]);
    }
    $db->prepare("UPDATE om_market_orders SET status = 'handoff', updated_at = NOW(), date_modified = NOW() WHERE order_id = ? AND shopper_id = ?")->execute([$order_id, $shopper_id]);
    response(true, null, "Handoff registrado");
} catch (Exception $e) { error_log("[shopper/handoff] " . $e->getMessage()); response(false, null, "Erro", 500); }
