<?php
/**
 * POST /api/mercado/partner/product-create.php
 * Create a new product (base + price entry for partner)
 * Body: {name, description, category_id, price, promotional_price, stock, unit, status}
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
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

    // Accept both Portuguese and English field names
    $name = strip_tags(trim($input['name'] ?? $input['nome'] ?? ''));
    $description = strip_tags(trim($input['description'] ?? $input['descricao'] ?? ''));
    $category_id = (int)($input['category_id'] ?? $input['categoria_id'] ?? 0);
    $price = (float)($input['price'] ?? $input['preco'] ?? 0);
    $promotional_price = null;
    if (isset($input['promotional_price']) && $input['promotional_price'] !== '' && $input['promotional_price'] !== null) {
        $promotional_price = (float)$input['promotional_price'];
    } elseif (isset($input['preco_promocional']) && $input['preco_promocional'] !== '' && $input['preco_promocional'] !== null) {
        $promotional_price = (float)$input['preco_promocional'];
    }
    $stock = (int)($input['stock'] ?? $input['estoque'] ?? 0);
    $unit = trim($input['unit'] ?? $input['unidade'] ?? 'un');
    $status = (int)($input['status'] ?? $input['ativo'] ?? 1);

    // Availability schedule
    $availability_schedule = null;
    $availability_schedule_json = null;
    if (isset($input['availability_schedule']) && $input['availability_schedule'] !== null && $input['availability_schedule'] !== '') {
        $validation = validateSchedule($input['availability_schedule']);
        if (!$validation['valid']) {
            response(false, null, $validation['error'], 400);
        }
        $availability_schedule = $validation['schedule'];
        $availability_schedule_json = $availability_schedule ? json_encode($availability_schedule) : null;
    }

    // Validations
    if (empty($name)) {
        response(false, null, "Nome do produto e obrigatorio", 400);
    }

    if (mb_strlen($name) > 255) {
        response(false, null, "Nome muito longo (max 255 caracteres)", 400);
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

    // Validate category exists (if provided)
    if ($category_id > 0) {
        $stmtCat = $db->prepare("SELECT category_id FROM om_market_categories WHERE category_id = ?");
        $stmtCat->execute([$category_id]);
        if (!$stmtCat->fetch()) {
            response(false, null, "Categoria nao encontrada", 404);
        }
    }

    $db->beginTransaction();

    try {
        // 1. Create base product
        // Detect available columns dynamically to handle schema variations
        $baseColumns = ['name'];
        $basePlaceholders = ['?'];
        $baseValues = [$name];

        // Always try to include these columns - they may or may not exist in the schema
        // We use a safe approach: try with all columns, fall back if needed
        $fullInsertSQL = "
            INSERT INTO om_market_products_base
                (name, description, category_id, unit, status, created_at)
            VALUES
                (?, ?, ?, ?, ?, NOW())
            RETURNING product_id
        ";
        $fullValues = [$name, $description, $category_id > 0 ? $category_id : null, $unit, $status];

        try {
            $stmtBase = $db->prepare($fullInsertSQL);
            $stmtBase->execute($fullValues);
        } catch (PDOException $e) {
            // If columns don't exist, try a minimal insert
            if (strpos($e->getMessage(), 'does not exist') !== false) {
                // Try with just name, category, status
                $stmtBase = $db->prepare("
                    INSERT INTO om_market_products_base (name, category, status, created_at)
                    VALUES (?, ?, ?, NOW())
                    RETURNING product_id
                ");
                $categoryName = '';
                if ($category_id > 0) {
                    $stmtCatName = $db->prepare("SELECT name FROM om_market_categories WHERE category_id = ?");
                    $stmtCatName->execute([$category_id]);
                    $catRow = $stmtCatName->fetch();
                    $categoryName = $catRow ? $catRow['name'] : '';
                }
                $stmtBase->execute([$name, $categoryName, $status]);
            } else {
                throw $e;
            }
        }

        $product_id = (int)$stmtBase->fetchColumn();

        if (!$product_id) {
            throw new Exception("Falha ao criar produto base");
        }

        // 2. Create price entry for this partner
        // Try with price_promo column first (matches CREATE TABLE), fall back to promotional_price
        $priceInsertSQL = "
            INSERT INTO om_market_products_price
                (product_id, partner_id, price, price_promo, stock, status, availability_schedule, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ";
        $priceValues = [$product_id, $partner_id, $price, $promotional_price, $stock, $status, $availability_schedule_json];

        try {
            $stmtPrice = $db->prepare($priceInsertSQL);
            $stmtPrice->execute($priceValues);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'does not exist') !== false) {
                // Fall back to promotional_price column name
                $stmtPrice = $db->prepare("
                    INSERT INTO om_market_products_price
                        (product_id, partner_id, price, promotional_price, stock, status, availability_schedule, created_at, updated_at)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmtPrice->execute($priceValues);
            } else {
                throw $e;
            }
        }

        $db->commit();

        // Log audit
        om_audit()->log(
            OmAudit::ACTION_CREATE,
            'product',
            $product_id,
            null,
            [
                'name' => $name,
                'description' => $description,
                'category_id' => $category_id,
                'price' => $price,
                'promotional_price' => $promotional_price,
                'stock' => $stock,
                'unit' => $unit,
                'status' => $status
            ],
            "Novo produto '{$name}' criado pelo parceiro #{$partner_id}",
            'partner',
            $partner_id
        );

        response(true, [
            "product_id" => $product_id,
            "partner_id" => $partner_id,
            "name" => $name,
            "description" => $description,
            "category_id" => $category_id,
            "price" => $price,
            "promotional_price" => $promotional_price,
            "stock" => $stock,
            "unit" => $unit,
            "status" => $status,
            "availability_schedule" => $availability_schedule
        ], "Produto criado com sucesso");

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("[partner/product-create] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
