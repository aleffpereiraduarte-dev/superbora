<?php
/**
 * POST /api/mercado/partner/price-adjust.php
 *
 * Aplica ajuste de preco em lote por categoria.
 *
 * Body: {
 *   category_id: int,
 *   adjust_type: 'percent'|'fixed',
 *   adjust_value: float  // positive = increase, negative = decrease
 * }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = requirePartnerAuth();
    $partner_id = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') response(false, null, "Metodo nao permitido", 405);

    $input = getInput();
    $category_id = (int)($input['category_id'] ?? 0);
    $adjust_type = $input['adjust_type'] ?? 'percent';
    $adjust_value = (float)($input['adjust_value'] ?? 0);

    if (!$category_id) response(false, null, "category_id obrigatorio", 400);
    if ($adjust_value == 0) response(false, null, "adjust_value obrigatorio", 400);
    if (!in_array($adjust_type, ['percent', 'fixed'])) $adjust_type = 'percent';

    $db->beginTransaction();

    // Get products in category with lock
    $stmt = $db->prepare("
        SELECT product_id, name, price, special_price
        FROM om_market_products
        WHERE partner_id = ? AND category_id = ? AND status != 'deleted'
        FOR UPDATE
    ");
    $stmt->execute([$partner_id, $category_id]);
    $products = $stmt->fetchAll();

    if (empty($products)) {
        $db->rollBack();
        response(false, null, "Nenhum produto encontrado nesta categoria", 404);
    }

    $updated = 0;
    $changes = [];

    foreach ($products as $p) {
        $oldPrice = (float)$p['price'];
        if ($adjust_type === 'percent') {
            $newPrice = round($oldPrice * (1 + $adjust_value / 100), 2);
        } else {
            $newPrice = round($oldPrice + $adjust_value, 2);
        }
        $newPrice = max(0.01, $newPrice);

        // Also adjust special_price proportionally if exists
        $oldSpecial = (float)($p['special_price'] ?? 0);
        $newSpecial = null;
        if ($oldSpecial > 0) {
            if ($adjust_type === 'percent') {
                $newSpecial = round($oldSpecial * (1 + $adjust_value / 100), 2);
            } else {
                $newSpecial = round($oldSpecial + $adjust_value, 2);
            }
            $newSpecial = max(0.01, $newSpecial);
            // Ensure special < regular
            if ($newSpecial >= $newPrice) $newSpecial = null;
        }

        $stmt = $db->prepare("UPDATE om_market_products SET price = ?, special_price = ?, updated_at = NOW() WHERE product_id = ?");
        $stmt->execute([$newPrice, $newSpecial, $p['product_id']]);
        $updated++;

        $changes[] = [
            'product_id' => (int)$p['product_id'],
            'name' => $p['name'],
            'old_price' => $oldPrice,
            'new_price' => $newPrice,
        ];
    }

    $db->commit();

    $direction = $adjust_value > 0 ? 'aumento' : 'reducao';
    $desc = "Ajuste de preco em lote: {$direction} de " . ($adjust_type === 'percent' ? abs($adjust_value) . '%' : 'R$ ' . number_format(abs($adjust_value), 2, ',', '.')) . " em {$updated} produtos da categoria #{$category_id}";

    om_audit()->log('price_bulk_adjust', 'product', $category_id,
        null,
        ['adjust_type' => $adjust_type, 'adjust_value' => $adjust_value, 'updated_count' => $updated],
        $desc
    );

    response(true, [
        'updated' => $updated,
        'adjust_type' => $adjust_type,
        'adjust_value' => $adjust_value,
        'changes' => $changes,
    ], "Precos ajustados com sucesso ({$updated} produtos)");

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[partner/price-adjust] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
