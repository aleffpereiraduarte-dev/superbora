<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
setCorsHeaders();
try {
    $db = getDB(); OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requireShopper(); $shopper_id = $payload['sub'] ?? $payload['uid'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = getInput();
        $order_id = (int)($input['order_id'] ?? 0); $message = trim($input['message'] ?? '');
        if (!$order_id || !$message) response(false, null, "order_id e message obrigatórios", 400);

        // Verify order ownership
        $stmtCheck = $db->prepare("SELECT 1 FROM om_market_orders WHERE order_id = ? AND shopper_id = ?");
        $stmtCheck->execute([$order_id, $shopper_id]);
        if (!$stmtCheck->fetch()) response(false, null, "Pedido nao encontrado", 404);

        try {
            $db->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message, created_at) VALUES (?,'shopper',?,?,NOW())")->execute([$order_id, $shopper_id, $message]);
        } catch(Exception $e) {
            $db->exec("CREATE TABLE IF NOT EXISTS om_order_chat (id SERIAL PRIMARY KEY, order_id INT, sender_type VARCHAR(20), sender_id INT, message TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_order_chat_order ON om_order_chat(order_id)");
            $db->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message, created_at) VALUES (?,'shopper',?,?,NOW())")->execute([$order_id, $shopper_id, $message]);
        }
        response(true, null, "Mensagem enviada");
    }
    $order_id = (int)($_GET['order_id'] ?? 0);
    $since = $_GET['since'] ?? '2000-01-01';
    if (!$order_id) response(false, null, "order_id obrigatório", 400);

    // Verify order ownership for GET too
    $stmtCheck = $db->prepare("SELECT 1 FROM om_market_orders WHERE order_id = ? AND shopper_id = ?");
    $stmtCheck->execute([$order_id, $shopper_id]);
    if (!$stmtCheck->fetch()) response(false, null, "Pedido nao encontrado", 404);

    try {
        $stmt = $db->prepare("SELECT * FROM om_order_chat WHERE order_id = ? AND created_at > ? ORDER BY created_at ASC");
        $stmt->execute([$order_id, $since]);
        response(true, $stmt->fetchAll());
    } catch(Exception $e) { response(true, []); }
} catch (Exception $e) { error_log("[shopper/chat] " . $e->getMessage()); response(false, null, "Erro", 500); }
