<?php
/**
 * POST /api/mercado/chat/enviar.php
 * Body: { "order_id": 10, "mensagem": "Oi" }
 */
require_once __DIR__ . "/../config/database.php";

try {
    $input = getInput();

    $order_id = (int)($input["order_id"] ?? 0);
    $mensagem = trim($input["mensagem"] ?? "");
    // SECURITY: Limit message length and sanitize
    $mensagem = mb_substr($mensagem, 0, 2000);
    $mensagem = strip_tags($mensagem);

    if (!$order_id || !$mensagem) response(false, null, "order_id e mensagem obrigatorios", 400);

    // Autenticacao: aceitar customer, shopper ou admin
    $remetente_tipo = "cliente";
    $remetente_id = 0;
    $customer_id = getCustomerIdFromToken();
    if ($customer_id) {
        $remetente_tipo = "cliente";
        $remetente_id = $customer_id;
    } else {
        // Require valid admin/shopper/partner token
        require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
        $auth = OmAuth::getInstance();
        $auth->setDb(getDB());
        $token = $auth->getTokenFromRequest();
        if (!$token) response(false, null, "Autenticacao necessaria", 401);
        $tokenPayload = $auth->validateToken($token);
        if (!$tokenPayload) response(false, null, "Token invalido", 401);
        $tokenType = $tokenPayload['type'] ?? '';
        $tokenUid = (int)($tokenPayload['uid'] ?? 0);
        // Map token type to remetente_tipo
        $typeMap = ['admin' => 'admin', 'superadmin' => 'admin', 'partner' => 'parceiro', 'shopper' => 'shopper'];
        $remetente_tipo = $typeMap[$tokenType] ?? null;
        if (!$remetente_tipo || !$tokenUid) response(false, null, "Tipo de usuario invalido", 403);
        $remetente_id = $tokenUid;
    }

    $db = getDB();

    // Verificar se pedido existe e pertence ao usuario
    if ($remetente_tipo === "cliente" && $customer_id) {
        $stmtCheck = $db->prepare("SELECT customer_id FROM om_market_orders WHERE order_id = ?");
        $stmtCheck->execute([$order_id]);
        $order = $stmtCheck->fetch();
        if (!$order || (int)$order['customer_id'] !== $customer_id) {
            response(false, null, "Pedido não encontrado", 404);
        }
    } elseif ($remetente_tipo === "shopper") {
        $stmtCheck = $db->prepare("SELECT shopper_id FROM om_market_orders WHERE order_id = ?");
        $stmtCheck->execute([$order_id]);
        $order = $stmtCheck->fetch();
        if (!$order || (int)($order['shopper_id'] ?? 0) !== $remetente_id) {
            response(false, null, "Pedido não encontrado", 404);
        }
    } elseif ($remetente_tipo === "parceiro") {
        $stmtCheck = $db->prepare("SELECT partner_id FROM om_market_orders WHERE order_id = ?");
        $stmtCheck->execute([$order_id]);
        $order = $stmtCheck->fetch();
        if (!$order || (int)($order['partner_id'] ?? 0) !== $remetente_id) {
            response(false, null, "Pedido não encontrado", 404);
        }
    }

    // Validar remetente_tipo
    $allowed_types = ['cliente', 'shopper', 'admin', 'parceiro'];
    if (!in_array($remetente_tipo, $allowed_types)) $remetente_tipo = 'cliente';

    $stmt = $db->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message, created_at)
               VALUES (?, ?, ?, ?, NOW()) RETURNING message_id");
    $stmt->execute([$order_id, $remetente_tipo, $remetente_id, $mensagem]);

    response(true, ["chat_id" => (int)$stmt->fetchColumn()], "Mensagem enviada");
    
} catch (Exception $e) {
    error_log("[chat/enviar] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
