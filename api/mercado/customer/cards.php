<?php
/**
 * /api/mercado/customer/cards.php
 * GET - lista cartoes salvos
 * POST - salvar novo cartao
 * DELETE ?id=X - remover cartao
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Token invalido", 401);
    $customerId = (int)$payload['uid'];

    $method = $_SERVER["REQUEST_METHOD"];

    // GET - Listar cartoes salvos
    if ($method === "GET") {
        $stmt = $db->prepare("
            SELECT id, card_last4, card_brand, card_exp_month, card_exp_year, is_default, created_at
            FROM om_market_saved_cards
            WHERE customer_id = ?
            ORDER BY is_default DESC, created_at DESC
        ");
        $stmt->execute([$customerId]);
        $cards = $stmt->fetchAll();

        response(true, ['cards' => $cards]);
    }

    // POST - Salvar novo cartao
    if ($method === "POST") {
        $input = getInput();

        $last4 = preg_replace('/\D/', '', $input['card_last4'] ?? '');
        $brand = preg_replace('/[^a-zA-Z]/', '', $input['card_brand'] ?? 'visa');
        $token_card = trim($input['card_token'] ?? '');
        $expMonth = (int)($input['card_exp_month'] ?? 0);
        $expYear = (int)($input['card_exp_year'] ?? 0);
        $isDefault = (int)($input['is_default'] ?? 0);

        if (strlen($last4) !== 4) response(false, null, "Ultimos 4 digitos invalidos", 400);
        if ($expMonth < 1 || $expMonth > 12) response(false, null, "Mes invalido", 400);
        if ($expYear < (int)date('Y')) response(false, null, "Cartao expirado", 400);

        // Se nao tem token, gerar placeholder (em producao seria token do gateway)
        if (!$token_card) {
            $token_card = 'tok_' . bin2hex(random_bytes(16));
        }

        $db->beginTransaction();

        // Se marcado como default, desmarcar outros
        if ($isDefault) {
            $db->prepare("UPDATE om_market_saved_cards SET is_default = 0 WHERE customer_id = ?")->execute([$customerId]);
        }

        // Verificar se e o primeiro cartao (se for, marcar como default)
        $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM om_market_saved_cards WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        $countCards = (int)$stmt->fetch()['cnt'];
        if ($countCards === 0) $isDefault = 1;

        $stmt = $db->prepare("
            INSERT INTO om_market_saved_cards (customer_id, card_token, card_last4, card_brand, card_exp_month, card_exp_year, is_default)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$customerId, $token_card, $last4, $brand, $expMonth, $expYear, $isDefault]);

        $db->commit();

        response(true, [
            'id' => (int)$db->lastInsertId(),
            'card_last4' => $last4,
            'card_brand' => $brand,
            'is_default' => $isDefault
        ], "Cartao salvo com sucesso");
    }

    // DELETE - Remover cartao
    if ($method === "DELETE") {
        $cardId = (int)($_GET['id'] ?? 0);
        if (!$cardId) response(false, null, "ID do cartao obrigatorio", 400);

        $stmt = $db->prepare("DELETE FROM om_market_saved_cards WHERE id = ? AND customer_id = ?");
        $stmt->execute([$cardId, $customerId]);

        if ($stmt->rowCount() === 0) response(false, null, "Cartao nao encontrado", 404);

        response(true, null, "Cartao removido");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[customer/cards] Erro: " . $e->getMessage());
    response(false, null, "Erro ao gerenciar cartoes", 500);
}
