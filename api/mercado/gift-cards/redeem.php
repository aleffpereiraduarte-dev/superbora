<?php
/**
 * POST /api/mercado/gift-cards/redeem.php
 * Redeem a gift card code and add balance to customer account
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/rate-limit.php";
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

    // Rate limiting: 5 redemptions per 15 minutes per IP
    $ip = getRateLimitIP();
    if (!checkRateLimit("giftcard_redeem_{$ip}", 5, 15)) {
        response(false, null, "Muitas tentativas de resgate. Tente novamente em 15 minutos.", 429);
    }

    $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', $input['code'] ?? ''));
    if (empty($code)) response(false, null, "Codigo obrigatorio", 400);

    // SECURITY: Use transaction + FOR UPDATE to prevent double-spend race condition
    $db->beginTransaction();

    $stmt = $db->prepare("SELECT id, code, amount, balance, status FROM om_market_gift_cards WHERE code = ? FOR UPDATE");
    $stmt->execute([$code]);
    $card = $stmt->fetch();

    if (!$card) { $db->rollBack(); response(false, null, "Cartao presente nao encontrado", 404); }
    if ($card['status'] !== 'active') { $db->rollBack(); response(false, null, "Este cartao ja foi resgatado ou expirou", 400); }
    if ((float)$card['balance'] <= 0) { $db->rollBack(); response(false, null, "Este cartao nao tem saldo", 400); }

    $balance = (float)$card['balance'];

    // Mark card as redeemed (atomic with lock held)
    $stmt = $db->prepare("
        UPDATE om_market_gift_cards
        SET status = 'redeemed', redeemed_by = ?, redeemed_at = NOW(), balance = 0
        WHERE id = ? AND status = 'active'
    ");
    $stmt->execute([$customerId, $card['id']]);

    if ($stmt->rowCount() === 0) {
        $db->rollBack();
        response(false, null, "Cartao ja foi resgatado por outra pessoa", 400);
    }

    // Check if customer has gift_card_balance column, if not use a separate approach
    // Add to customer's balance (stored as a redeemed gift card entry)
    // We'll create a simple balance tracking: sum of all redeemed cards minus spent

    $db->commit();

    // Calculate total balance
    $balStmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM om_market_gift_cards
        WHERE redeemed_by = ? AND status = 'redeemed'
    ");
    $balStmt->execute([$customerId]);
    $totalBalance = (float)$balStmt->fetch()['total'];

    response(true, [
        'redeemed_amount' => $balance,
        'total_balance' => $totalBalance,
    ], "Cartao presente resgatado! R$ " . number_format($balance, 2, ',', '.') . " adicionado ao seu saldo.");

} catch (Exception $e) {
    $db->rollBack();
    error_log("[API Gift Card Redeem] Erro: " . $e->getMessage());
    response(false, null, "Erro ao resgatar cartao presente", 500);
}
