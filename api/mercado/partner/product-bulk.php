<?php
/**
 * POST /api/mercado/partner/product-bulk.php
 * Operacoes em lote em produtos
 *
 * Body: {
 *   "action": "activate" | "deactivate" | "price_adjust",
 *   "product_ids": [1, 2, 3],
 *   "type": "percent" | "fixed",  // apenas para price_adjust
 *   "amount": 10                   // apenas para price_adjust
 * }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/PusherService.php";

setCorsHeaders();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = $payload['uid'];
    $input = getInput();

    $action = $input['action'] ?? '';
    $productIds = $input['product_ids'] ?? [];

    if (empty($action) || empty($productIds) || !is_array($productIds)) {
        response(false, null, "Acao e lista de produtos sao obrigatorios", 400);
    }

    if (count($productIds) > 100) {
        response(false, null, "Maximo de 100 produtos por operacao", 400);
    }

    // Sanitize IDs
    $productIds = array_map('intval', $productIds);
    $productIds = array_filter($productIds, fn($id) => $id > 0);

    if (empty($productIds)) {
        response(false, null, "Nenhum produto valido", 400);
    }

    // Verify all products belong to partner
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $params = array_merge($productIds, [$partnerId]);
    $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_products WHERE product_id IN ($placeholders) AND partner_id = ?");
    $stmt->execute($params);
    $count = $stmt->fetchColumn();

    if ((int)$count !== count($productIds)) {
        response(false, null, "Alguns produtos nao pertencem a sua loja", 403);
    }

    $db->beginTransaction();

    try {
        switch ($action) {
            case 'activate':
                $stmt = $db->prepare("UPDATE om_market_products SET status = '1' WHERE product_id IN ($placeholders) AND partner_id = ?");
                $stmt->execute($params);
                $msg = "$count produto(s) ativado(s)";
                break;

            case 'deactivate':
                $stmt = $db->prepare("UPDATE om_market_products SET status = '0' WHERE product_id IN ($placeholders) AND partner_id = ?");
                $stmt->execute($params);
                $msg = "$count produto(s) desativado(s)";
                break;

            case 'price_adjust':
                $type = $input['type'] ?? 'percent';
                $amount = floatval($input['amount'] ?? 0);

                if ($amount == 0) {
                    if ($db->inTransaction()) $db->rollBack();
                    response(false, null, "Valor do ajuste e obrigatorio", 400);
                }

                if ($type === 'percent') {
                    if ($amount < -90 || $amount > 500) {
                        if ($db->inTransaction()) $db->rollBack();
                        response(false, null, "Ajuste percentual deve ser entre -90% e 500%", 400);
                    }
                    $stmt = $db->prepare("UPDATE om_market_products SET price = ROUND(price * (1 + ? / 100), 2) WHERE product_id IN ($placeholders) AND partner_id = ?");
                    $adjustParams = array_merge([$amount], $productIds, [$partnerId]);
                } else {
                    // Cap fixed adjustments to prevent extreme price manipulation
                    if ($amount > 10000 || $amount < -10000) {
                        if ($db->inTransaction()) $db->rollBack();
                        response(false, null, "Ajuste fixo deve ser entre -R$10.000 e R$10.000", 400);
                    }
                    $stmt = $db->prepare("UPDATE om_market_products SET price = GREATEST(0.01, LEAST(99999.99, price + ?)) WHERE product_id IN ($placeholders) AND partner_id = ?");
                    $adjustParams = array_merge([$amount], $productIds, [$partnerId]);
                }

                $stmt->execute($adjustParams);
                $msg = "Preco ajustado em $count produto(s)";
                break;

            default:
                if ($db->inTransaction()) $db->rollBack();
                response(false, null, "Acao invalida: $action", 400);
        }

        $db->commit();

        // Pusher: notificar parceiro sobre atualizacao em lote de produtos
        try {
            foreach ($productIds as $pid) {
                PusherService::productUpdate($partnerId, [
                    'product_id' => $pid,
                    'action' => 'bulk_' . $action,
                    'product' => null
                ]);
            }
        } catch (Exception $pusherErr) {
            error_log("[product-bulk] Pusher erro: " . $pusherErr->getMessage());
        }

        response(true, ['affected' => $count], $msg);

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("[partner/product-bulk] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
