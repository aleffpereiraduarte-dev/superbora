<?php
/**
 * POST /api/mercado/partner/product-save.php
 * Upsert product price/stock/promo for partner
 * Body: {product_id, price, promotional_price, stock, status}
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
require_once dirname(__DIR__, 3) . "/includes/classes/PusherService.php";
require_once __DIR__ . "/../helpers/availability.php";

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

    $product_id = (int)($input['product_id'] ?? 0);
    // Accept both English and Portuguese field names
    $price = (float)($input['price'] ?? $input['preco'] ?? 0);
    $promotional_price = null;
    if (isset($input['promotional_price']) && $input['promotional_price'] !== '' && $input['promotional_price'] !== null) {
        $promotional_price = (float)$input['promotional_price'];
    } elseif (isset($input['preco_promocional']) && $input['preco_promocional'] !== '' && $input['preco_promocional'] !== null) {
        $promotional_price = (float)$input['preco_promocional'];
    }
    $stock = (int)($input['stock'] ?? $input['estoque'] ?? 0);
    $status = (int)($input['status'] ?? 1);

    // Dietary tags (JSON array of slugs)
    $dietary_tags = null;
    if (isset($input['dietary_tags'])) {
        if (is_array($input['dietary_tags'])) {
            $validTags = ['vegano', 'sem_gluten', 'sem_lactose', 'organico', 'zero_acucar', 'integral'];
            $filtered = array_values(array_filter($input['dietary_tags'], function($t) use ($validTags) {
                return in_array($t, $validTags);
            }));
            $dietary_tags = !empty($filtered) ? json_encode($filtered) : null;
        }
    }

    // Validacoes
    if (!$product_id) {
        response(false, null, "product_id obrigatorio", 400);
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

    // Availability schedule validation
    $availability_schedule = null;
    $availability_schedule_json = null;
    if (array_key_exists('availability_schedule', $input)) {
        $scheduleInput = $input['availability_schedule'];
        if ($scheduleInput !== null && $scheduleInput !== '' && $scheduleInput !== 'null') {
            $validation = validateSchedule($scheduleInput);
            if (!$validation['valid']) {
                response(false, null, $validation['error'], 400);
            }
            $availability_schedule = $validation['schedule'];
            $availability_schedule_json = $availability_schedule ? json_encode($availability_schedule) : null;
        }
    }

    // Verificar se o produto base existe
    $stmtCheck = $db->prepare("SELECT product_id FROM om_market_products_base WHERE product_id = ?");
    $stmtCheck->execute([$product_id]);
    if (!$stmtCheck->fetch()) {
        response(false, null, "Produto base nao encontrado", 404);
    }

    // Upsert: INSERT ON CONFLICT (PostgreSQL)
    $stmt = $db->prepare("
        INSERT INTO om_market_products_price
            (product_id, partner_id, price, promotional_price, stock, status, availability_schedule, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON CONFLICT (product_id, partner_id) DO UPDATE SET
            price = EXCLUDED.price,
            promotional_price = EXCLUDED.promotional_price,
            stock = EXCLUDED.stock,
            status = EXCLUDED.status,
            availability_schedule = EXCLUDED.availability_schedule,
            updated_at = NOW()
    ");
    $stmt->execute([$product_id, $partner_id, $price, $promotional_price, $stock, $status, $availability_schedule_json]);

    // Update dietary_tags on the main product table if provided
    if (array_key_exists('dietary_tags', $input)) {
        $stmtTags = $db->prepare("UPDATE om_market_products SET dietary_tags = ? WHERE product_id = ? AND partner_id = ?");
        $stmtTags->execute([$dietary_tags, $product_id, $partner_id]);
    }

    // Update availability_schedule on the main product table if provided
    if (array_key_exists('availability_schedule', $input)) {
        $stmtSched = $db->prepare("UPDATE om_market_products SET availability_schedule = ? WHERE product_id = ? AND partner_id = ?");
        $stmtSched->execute([$availability_schedule_json, $product_id, $partner_id]);
    }

    // Log audit
    om_audit()->log(
        OmAudit::ACTION_UPDATE,
        'product_price',
        $product_id,
        null,
        ['price' => $price, 'promotional_price' => $promotional_price, 'stock' => $stock, 'status' => $status],
        "Produto #{$product_id} preco/estoque atualizado pelo parceiro #{$partner_id}",
        'partner',
        $partner_id
    );

    // Pusher: notificar parceiro sobre atualizacao do produto em tempo real
    try {
        // Buscar nome do produto
        $stmtProdName = $db->prepare("SELECT name FROM om_market_products_base WHERE product_id = ?");
        $stmtProdName->execute([$product_id]);
        $prodName = $stmtProdName->fetchColumn();

        PusherService::productUpdate($partner_id, [
            'product_id' => $product_id,
            'action' => 'update',
            'product' => [
                'id' => $product_id,
                'name' => $prodName,
                'price' => $price,
                'promotional_price' => $promotional_price,
                'stock' => $stock,
                'status' => $status
            ]
        ]);

        // Se estoque mudou significativamente, enviar stock update
        PusherService::stockUpdate($partner_id, [
            'product_id' => $product_id,
            'product_name' => $prodName,
            'new_stock' => $stock
        ]);
    } catch (Exception $pusherErr) {
        error_log("[product-save] Pusher erro: " . $pusherErr->getMessage());
    }

    response(true, [
        "product_id" => $product_id,
        "partner_id" => $partner_id,
        "price" => $price,
        "promotional_price" => $promotional_price,
        "stock" => $stock,
        "status" => $status,
        "dietary_tags" => $dietary_tags ? json_decode($dietary_tags, true) : null,
        "availability_schedule" => $availability_schedule
    ], "Produto salvo com sucesso");

} catch (Exception $e) {
    error_log("[partner/product-save] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
