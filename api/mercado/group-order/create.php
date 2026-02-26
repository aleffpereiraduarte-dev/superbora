<?php
/**
 * POST /api/mercado/group-order/create.php
 * Creates a group order session with a shareable code
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

try {
    $input = getInput();
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Token invalido", 401);
    $customerId = (int)$payload['uid'];

    $partnerId = (int)($input['partner_id'] ?? 0);
    if (!$partnerId) response(false, null, "partner_id obrigatorio", 400);

    // Verify partner exists
    $stmt = $db->prepare("SELECT partner_id AS id, name AS nome FROM om_market_partners WHERE partner_id = ?");
    $stmt->execute([$partnerId]);
    $partner = $stmt->fetch();
    if (!$partner) response(false, null, "Estabelecimento nao encontrado", 404);

    // Generate unique 6-char share code
    $shareCode = '';
    $attempts = 0;
    do {
        $shareCode = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $check = $db->prepare("SELECT id FROM om_market_group_orders WHERE share_code = ?");
        $check->execute([$shareCode]);
        $attempts++;
    } while ($check->fetch() && $attempts < 10);

    $expiresAt = date('Y-m-d H:i:s', strtotime('+2 hours'));

    $db->beginTransaction();

    $stmt = $db->prepare("
        INSERT INTO om_market_group_orders (creator_id, partner_id, share_code, status, expires_at)
        VALUES (?, ?, ?, 'active', ?)
    ");
    $stmt->execute([$customerId, $partnerId, $shareCode, $expiresAt]);
    $groupOrderId = (int)$db->lastInsertId();

    // Auto-add creator as first participant
    $stmt = $db->prepare("
        INSERT INTO om_market_group_order_participants (group_order_id, customer_id)
        VALUES (?, ?)
    ");
    $stmt->execute([$groupOrderId, $customerId]);

    $db->commit();

    $baseUrl = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'onemundo.com.br');
    $shareUrl = $baseUrl . '/mercado/vitrine/grupo/?code=' . $shareCode;

    response(true, [
        'group_order_id' => $groupOrderId,
        'share_code' => $shareCode,
        'share_url' => $shareUrl,
        'partner_name' => $partner['nome'],
        'expires_at' => $expiresAt,
    ], "Pedido em grupo criado!");

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[API Group Order Create] Erro: " . $e->getMessage());
    response(false, null, "Erro ao criar pedido em grupo", 500);
}
