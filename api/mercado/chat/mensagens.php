<?php
/**
 * GET /api/mercado/chat/mensagens.php?order_id=10
 */
require_once __DIR__ . "/../config/database.php";

try {
    $order_id = intval($_GET["order_id"] ?? 0);
    if (!$order_id) response(false, null, "order_id é obrigatório", 400);

    // Autenticacao: customer deve ser dono do pedido
    $customer_id = getCustomerIdFromToken();
    $db = getDB();

    if ($customer_id) {
        $stmtCheck = $db->prepare("SELECT customer_id FROM om_market_orders WHERE order_id = ?");
        $stmtCheck->execute([$order_id]);
        $order = $stmtCheck->fetch();
        if (!$order || (int)$order['customer_id'] !== $customer_id) {
            response(false, null, "Pedido não encontrado", 404);
        }
    } else {
        // Require valid admin/shopper/partner token
        require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
        $auth = OmAuth::getInstance();
        $auth->setDb($db);
        $token = $auth->getTokenFromRequest();
        if (!$token) response(false, null, "Autenticacao necessaria", 401);
        $tokenPayload = $auth->validateToken($token);
        if (!$tokenPayload) response(false, null, "Token invalido", 401);
        // Admin can view any order's chat; shopper/partner need ownership check
        $tokenType = $tokenPayload['type'] ?? '';
        $tokenUid = (int)($tokenPayload['uid'] ?? 0);
        if (!in_array($tokenType, ['admin', 'superadmin'])) {
            // For shopper/partner, verify they are assigned to this order
            if ($tokenType === 'shopper') {
                $ownerCheck = $db->prepare("SELECT 1 FROM om_market_orders WHERE order_id = ? AND shopper_id = ?");
                $ownerCheck->execute([$order_id, $tokenUid]);
            } elseif ($tokenType === 'partner') {
                $ownerCheck = $db->prepare("SELECT 1 FROM om_market_orders WHERE order_id = ? AND partner_id = ?");
                $ownerCheck->execute([$order_id, $tokenUid]);
            } else {
                response(false, null, "Acesso negado", 403);
            }
            if (!$ownerCheck->fetch()) response(false, null, "Acesso negado ao chat deste pedido", 403);
        }
    }

    // Buscar mensagens (prepared statement)
    $stmtMsg = $db->prepare("SELECT message_id AS id, order_id, sender_type, sender_id, message, created_at FROM om_order_chat WHERE order_id = ? ORDER BY created_at ASC");
    $stmtMsg->execute([$order_id]);
    $mensagens = $stmtMsg->fetchAll();

    response(true, ["mensagens" => $mensagens]);

} catch (Exception $e) {
    error_log("[chat/mensagens] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
