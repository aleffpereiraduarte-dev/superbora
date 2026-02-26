<?php
/**
 * GET /api/mercado/produtos/extras.php?product_id=123
 * Retorna grupos de extras/adicionais de um produto
 *
 * POST — Salva extras no carrinho
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

try {
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $productId = (int)($_GET['product_id'] ?? 0);
        $partnerId = (int)($_GET['partner_id'] ?? 0);

        if (!$productId && !$partnerId) {
            response(false, null, 'product_id ou partner_id obrigatorio', 400);
        }

        // Buscar partner_id do produto se nao fornecido
        if (!$partnerId && $productId) {
            $stmt = $db->prepare("SELECT partner_id FROM om_market_products WHERE product_id = ?");
            $stmt->execute([$productId]);
            $partnerId = (int)$stmt->fetchColumn();
        }

        // Buscar grupos de extras (especificos do produto + globais do parceiro)
        $stmt = $db->prepare("
            SELECT g.group_id, g.name, g.description, g.min_select, g.max_select, g.sort_order
            FROM om_product_extra_groups g
            WHERE g.is_active = 1
              AND g.partner_id = ?
              AND (g.product_id = ? OR g.product_id IS NULL)
            ORDER BY g.sort_order, g.group_id
        ");
        $stmt->execute([$partnerId, $productId]);
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Buscar opcoes de cada grupo
        $optStmt = $db->prepare("
            SELECT option_id, name, description, price, max_quantity, sort_order
            FROM om_product_extra_options
            WHERE group_id = ? AND is_active = 1
            ORDER BY sort_order, option_id
        ");

        $result = [];
        foreach ($groups as $g) {
            $optStmt->execute([$g['group_id']]);
            $options = $optStmt->fetchAll(PDO::FETCH_ASSOC);

            $result[] = [
                'group_id' => (int)$g['group_id'],
                'name' => $g['name'],
                'description' => $g['description'],
                'min_select' => (int)$g['min_select'],
                'max_select' => (int)$g['max_select'],
                'required' => (int)$g['min_select'] > 0,
                'options' => array_map(function($o) {
                    return [
                        'option_id' => (int)$o['option_id'],
                        'name' => $o['name'],
                        'description' => $o['description'],
                        'price' => (float)$o['price'],
                        'max_quantity' => (int)$o['max_quantity'],
                    ];
                }, $options),
            ];
        }

        response(true, ['extras' => $result]);
    }

    // POST: salvar extras no carrinho
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Require authentication for cart modification
        OmAuth::getInstance()->setDb($db);
        $token = om_auth()->getTokenFromRequest();
        if (!$token) response(false, null, 'Token ausente', 401);
        $payload = om_auth()->validateToken($token);
        if (!$payload || $payload['type'] !== 'customer') {
            response(false, null, 'Nao autorizado', 401);
        }
        $authCustomerId = (int)$payload['uid'];

        $input = json_decode(file_get_contents('php://input'), true);
        $cartId = (int)($input['cart_id'] ?? 0);
        $extras = $input['extras'] ?? []; // [{group_id, option_id, quantity}]

        if (!$cartId) response(false, null, 'cart_id obrigatorio', 400);

        // Verify cart belongs to the authenticated customer
        $ownerStmt = $db->prepare("SELECT cart_id FROM om_market_cart WHERE cart_id = ? AND customer_id = ?");
        $ownerStmt->execute([$cartId, $authCustomerId]);
        if (!$ownerStmt->fetch()) {
            response(false, null, 'Carrinho nao encontrado', 404);
        }

        // Limpar extras anteriores desse cart item
        $db->prepare("DELETE FROM om_cart_item_extras WHERE cart_id = ?")->execute([$cartId]);

        // Inserir novos
        $stmt = $db->prepare("INSERT INTO om_cart_item_extras (cart_id, group_id, option_id, quantity, unit_price)
            VALUES (?, ?, ?, ?, (SELECT price FROM om_product_extra_options WHERE option_id = ?))");

        $totalExtras = 0;
        foreach ($extras as $e) {
            $optId = (int)($e['option_id'] ?? 0);
            $grpId = (int)($e['group_id'] ?? 0);
            $qty = max(1, (int)($e['quantity'] ?? 1));
            if ($optId) {
                $stmt->execute([$cartId, $grpId, $optId, $qty, $optId]);
            }
        }

        // Retornar total de extras
        $sumStmt = $db->prepare("SELECT COALESCE(SUM(unit_price * quantity), 0) FROM om_cart_item_extras WHERE cart_id = ?");
        $sumStmt->execute([$cartId]);
        $totalExtras = (float)$sumStmt->fetchColumn();

        response(true, ['total_extras' => $totalExtras]);
    }

} catch (Exception $e) {
    error_log("[Extras] " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
