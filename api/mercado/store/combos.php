<?php
/**
 * GET /api/mercado/store/combos.php?partner_id=X
 * Lista combos ativos de uma loja (para exibicao no cliente)
 */

require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

try {
    $db = getDB();

    $partnerId = (int)($_GET['partner_id'] ?? 0);
    if (!$partnerId) response(false, null, "partner_id obrigatorio", 400);

    // Single query with JOIN to avoid N+1
    $stmt = $db->prepare("
        SELECT c.id, c.name, c.description, c.image, c.price, c.original_price,
               ci.product_id AS item_product_id, ci.quantity AS item_quantity,
               p.name AS item_name, p.price AS item_price, p.image AS item_image
        FROM om_market_combos c
        LEFT JOIN om_market_combo_items ci ON ci.combo_id = c.id
        LEFT JOIN om_market_products p ON p.product_id = ci.product_id
        WHERE c.partner_id = ? AND c.status = '1'
        ORDER BY c.created_at DESC, ci.product_id ASC
    ");
    $stmt->execute([$partnerId]);
    $rows = $stmt->fetchAll();

    // Group rows by combo
    $combosMap = [];
    foreach ($rows as $row) {
        $comboId = $row['id'];
        if (!isset($combosMap[$comboId])) {
            $price = (float)$row['price'];
            $originalPrice = (float)$row['original_price'];
            $combosMap[$comboId] = [
                'id' => $comboId,
                'name' => $row['name'],
                'description' => $row['description'],
                'image' => $row['image'],
                'price' => $price,
                'original_price' => $originalPrice,
                'discount_percent' => $originalPrice > 0
                    ? round((1 - $price / $originalPrice) * 100)
                    : 0,
                'items' => []
            ];
        }
        if ($row['item_product_id']) {
            $combosMap[$comboId]['items'][] = [
                'product_id' => $row['item_product_id'],
                'quantity' => $row['item_quantity'],
                'name' => $row['item_name'],
                'price' => $row['item_price'],
                'image' => $row['item_image']
            ];
        }
    }
    $combos = array_values($combosMap);

    response(true, ['combos' => $combos]);

} catch (Exception $e) {
    error_log("[store/combos] Erro: " . $e->getMessage());
    response(false, null, "Erro ao listar combos", 500);
}
