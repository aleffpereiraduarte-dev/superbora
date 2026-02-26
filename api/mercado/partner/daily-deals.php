<?php
/**
 * GET /api/mercado/partner/daily-deals.php - Listar ofertas do dia
 * POST /api/mercado/partner/daily-deals.php - Criar/atualizar oferta
 * DELETE /api/mercado/partner/daily-deals.php?id=X - Desativar oferta
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
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
            SELECT d.*, p.name AS product_name, p.price AS product_price, p.image AS product_image
            FROM om_partner_daily_deals d
            JOIN om_market_products p ON p.product_id = d.product_id
            WHERE d.partner_id = ? AND d.status = 'active' AND d.deal_date >= CURRENT_DATE
            ORDER BY d.deal_date ASC, d.created_at DESC
        ");
        $stmt->execute([$partnerId]);
        $deals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($deals as &$deal) {
            $deal['discount_price'] = round($deal['product_price'] * (1 - $deal['discount_percent'] / 100), 2);
        }

        response(true, ['deals' => $deals]);
    }

    if ($method === 'POST') {
        $input = getInput();
        $productId = intval($input['product_id'] ?? 0);
        $discountPercent = floatval($input['discount_percent'] ?? 10);
        $dealDate = $input['deal_date'] ?? '';
        $dealId = intval($input['id'] ?? 0);

        if (!$productId || !$dealDate) {
            response(false, null, "Produto e data sao obrigatorios", 400);
        }

        if ($discountPercent < 5 || $discountPercent > 70) {
            response(false, null, "Desconto deve ser entre 5% e 70%", 400);
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dealDate)) {
            response(false, null, "Formato de data invalido", 400);
        }

        // Verify product belongs to partner
        $stmt = $db->prepare("SELECT product_id FROM om_market_products WHERE product_id = ? AND partner_id = ?");
        $stmt->execute([$productId, $partnerId]);
        if (!$stmt->fetch()) {
            response(false, null, "Produto nao encontrado", 404);
        }

        $db->beginTransaction();
        try {
            if ($dealId > 0) {
                // Update
                $stmt = $db->prepare("UPDATE om_partner_daily_deals SET product_id = ?, discount_percent = ?, deal_date = ? WHERE id = ? AND partner_id = ?");
                $stmt->execute([$productId, $discountPercent, $dealDate, $dealId, $partnerId]);
            } else {
                // Check max 3 per day (lock rows to prevent race condition)
                $stmt = $db->prepare("SELECT COUNT(*) FROM om_partner_daily_deals WHERE partner_id = ? AND deal_date = ? AND status = 'active' FOR UPDATE");
                $stmt->execute([$partnerId, $dealDate]);
                if ($stmt->fetchColumn() >= 3) {
                    $db->rollBack();
                    response(false, null, "Maximo de 3 ofertas por dia", 400);
                }

                $stmt = $db->prepare("INSERT INTO om_partner_daily_deals (partner_id, product_id, discount_percent, deal_date) VALUES (?, ?, ?, ?) RETURNING id");
                $stmt->execute([$partnerId, $productId, $discountPercent, $dealDate]);
                $dealId = $stmt->fetchColumn();
            }
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        response(true, ['id' => $dealId], "Oferta salva!");
    }

    if ($method === 'DELETE') {
        $id = intval($_GET['id'] ?? 0);
        if (!$id) response(false, null, "ID obrigatorio", 400);

        $stmt = $db->prepare("UPDATE om_partner_daily_deals SET status = 'inactive' WHERE id = ? AND partner_id = ?");
        $stmt->execute([$id, $partnerId]);

        response(true, null, "Oferta removida!");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/daily-deals] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
