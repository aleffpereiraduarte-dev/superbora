<?php
/**
 * POST /api/mercado/partner/price-update.php
 * Update price for a specific product_price record
 * Body: {product_price_id, price, promotional_price, stock}
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        response(false, null, "Metodo nao permitido", 405);
    }

    $input = getInput();

    $product_price_id = (int)($input['product_price_id'] ?? 0);
    $price = (float)($input['price'] ?? 0);
    $promotional_price = isset($input['promotional_price']) && $input['promotional_price'] !== '' && $input['promotional_price'] !== null
        ? (float)$input['promotional_price'] : null;
    $stock = (int)($input['stock'] ?? 0);

    if (!$product_price_id) {
        response(false, null, "product_price_id obrigatorio", 400);
    }

    if ($price <= 0) {
        response(false, null, "Preco deve ser maior que zero", 400);
    }

    if ($promotional_price !== null && $promotional_price >= $price) {
        response(false, null, "Preco promocional deve ser menor que o preco normal", 400);
    }

    if ($stock < 0) {
        response(false, null, "Estoque nao pode ser negativo", 400);
    }

    // Verificar que o registro pertence ao parceiro
    $stmtCheck = $db->prepare("
        SELECT id, price, promotional_price, stock
        FROM om_market_products_price
        WHERE id = ? AND partner_id = ?
    ");
    $stmtCheck->execute([$product_price_id, $partner_id]);
    $existing = $stmtCheck->fetch();

    if (!$existing) {
        response(false, null, "Registro de preco nao encontrado", 404);
    }

    // Update
    $stmt = $db->prepare("
        UPDATE om_market_products_price
        SET price = ?, promotional_price = ?, stock = ?, updated_at = NOW()
        WHERE id = ? AND partner_id = ?
    ");
    $stmt->execute([$price, $promotional_price, $stock, $product_price_id, $partner_id]);

    // Audit log
    om_audit()->log(
        OmAudit::ACTION_UPDATE,
        'product_price',
        $product_price_id,
        ['price' => (float)$existing['price'], 'promotional_price' => (float)$existing['promotional_price'], 'stock' => (int)$existing['stock']],
        ['price' => $price, 'promotional_price' => $promotional_price, 'stock' => $stock],
        "Preco atualizado pelo parceiro #{$partner_id}",
        'partner',
        $partner_id
    );

    response(true, [
        "product_price_id" => $product_price_id,
        "price" => $price,
        "promotional_price" => $promotional_price,
        "stock" => $stock
    ], "Preco atualizado com sucesso");

} catch (Exception $e) {
    error_log("[partner/price-update] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
