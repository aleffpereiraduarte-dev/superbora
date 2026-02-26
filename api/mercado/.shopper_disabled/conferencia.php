<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
setCorsHeaders();
try {
    $db = getDB(); OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requireShopper(); $shopper_id = $payload['sub'] ?? $payload['uid'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = getInput();
        $order_id = (int)($input['order_id'] ?? 0);
        if (!$order_id) response(false, null, "order_id obrigatório", 400);

        // Verify order ownership
        $stmtCheck = $db->prepare("SELECT 1 FROM om_market_orders WHERE order_id = ? AND shopper_id = ?");
        $stmtCheck->execute([$order_id, $shopper_id]);
        if (!$stmtCheck->fetch()) response(false, null, "Pedido nao encontrado", 404);

        try {
            $db->prepare("INSERT INTO om_order_conferencia (order_id, items_verified, bags_count, confirmed, created_at) VALUES (?,?,?,1,NOW()) ON CONFLICT (order_id) DO UPDATE SET items_verified=EXCLUDED.items_verified, bags_count=EXCLUDED.bags_count, confirmed=1")
                ->execute([$order_id, json_encode($input['items_verified'] ?? []), (int)($input['bags_count'] ?? 1)]);
        } catch(Exception $e) {
            $db->exec("CREATE TABLE IF NOT EXISTS om_order_conferencia (id SERIAL PRIMARY KEY, order_id INT UNIQUE, items_verified JSON, bags_count INT DEFAULT 1, photos JSON, confirmed SMALLINT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
            $db->prepare("INSERT INTO om_order_conferencia (order_id, items_verified, bags_count, confirmed, created_at) VALUES (?,?,?,1,NOW())")
                ->execute([$order_id, json_encode($input['items_verified'] ?? []), (int)($input['bags_count'] ?? 1)]);
        }
        response(true, null, "Conferência salva");
    }
    $order_id = (int)($_GET['order_id'] ?? 0);
    if (!$order_id) response(false, null, "order_id obrigatório", 400);

    // Verify order ownership for GET too
    $stmtCheck = $db->prepare("SELECT 1 FROM om_market_orders WHERE order_id = ? AND shopper_id = ?");
    $stmtCheck->execute([$order_id, $shopper_id]);
    if (!$stmtCheck->fetch()) response(false, null, "Pedido nao encontrado", 404);

    $stmt = $db->prepare("SELECT i.*, COALESCE(i.collected,0) as collected FROM om_market_order_items i WHERE i.order_id = ?");
    $stmt->execute([$order_id]);
    response(true, $stmt->fetchAll());
} catch (Exception $e) { error_log("[shopper/conferencia] " . $e->getMessage()); response(false, null, "Erro", 500); }
