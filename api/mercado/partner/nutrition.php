<?php
/**
 * GET/POST /api/mercado/partner/nutrition.php
 * GET ?product_id=X: Return nutrition, allergens, dietary data for a product
 * POST: Save/update nutrition + allergens + dietary for a product
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partnerId = (int)$payload['uid'];
    $method = $_SERVER["REQUEST_METHOD"];

    // Helper: validate product belongs to partner
    $validateProduct = function(int $productId) use ($db, $partnerId): bool {
        $stmt = $db->prepare("
            SELECT id FROM om_market_products_price
            WHERE product_id = ? AND partner_id = ?
            LIMIT 1
        ");
        $stmt->execute([$productId, $partnerId]);
        return (bool)$stmt->fetch();
    };

    // ===== GET: Return nutrition data =====
    if ($method === "GET") {
        $productId = (int)($_GET['product_id'] ?? 0);
        if (!$productId) {
            response(false, null, "product_id obrigatorio", 400);
        }

        if (!$validateProduct($productId)) {
            response(false, null, "Produto nao encontrado ou nao pertence ao parceiro", 404);
        }

        // Nutrition info
        $nutrition = null;
        try {
            $stmt = $db->prepare("
                SELECT calories, protein, carbs, fat, fiber, sodium, serving_size, serving_unit
                FROM om_market_product_nutrition
                WHERE product_id = ?
            ");
            $stmt->execute([$productId]);
            $row = $stmt->fetch();
            if ($row) {
                $nutrition = [
                    'calories' => $row['calories'] !== null ? (float)$row['calories'] : null,
                    'protein' => $row['protein'] !== null ? (float)$row['protein'] : null,
                    'carbs' => $row['carbs'] !== null ? (float)$row['carbs'] : null,
                    'fat' => $row['fat'] !== null ? (float)$row['fat'] : null,
                    'fiber' => $row['fiber'] !== null ? (float)$row['fiber'] : null,
                    'sodium' => $row['sodium'] !== null ? (float)$row['sodium'] : null,
                    'serving_size' => $row['serving_size'] !== null ? (float)$row['serving_size'] : null,
                    'serving_unit' => $row['serving_unit']
                ];
            }
        } catch (Exception $e) {
            // Table may not exist
        }

        // Allergens
        $allergens = [];
        try {
            $stmt = $db->prepare("
                SELECT allergen_type FROM om_market_product_allergens
                WHERE product_id = ?
            ");
            $stmt->execute([$productId]);
            $rows = $stmt->fetchAll();
            foreach ($rows as $row) {
                $allergens[] = $row['allergen_type'];
            }
        } catch (Exception $e) {
            // Table may not exist
        }

        // Dietary badges
        $dietary = [];
        try {
            $stmt = $db->prepare("
                SELECT vegan, vegetarian, organic, sugar_free, gluten_free, lactose_free, low_sodium, keto
                FROM om_market_product_dietary
                WHERE product_id = ?
            ");
            $stmt->execute([$productId]);
            $row = $stmt->fetch();
            if ($row) {
                $dietary = [
                    'vegan' => (bool)$row['vegan'],
                    'vegetarian' => (bool)$row['vegetarian'],
                    'organic' => (bool)$row['organic'],
                    'sugar_free' => (bool)$row['sugar_free'],
                    'gluten_free' => (bool)$row['gluten_free'],
                    'lactose_free' => (bool)$row['lactose_free'],
                    'low_sodium' => (bool)$row['low_sodium'],
                    'keto' => (bool)$row['keto']
                ];
            }
        } catch (Exception $e) {
            // Table may not exist
        }

        response(true, [
            'product_id' => $productId,
            'nutrition' => $nutrition,
            'allergens' => $allergens,
            'dietary' => $dietary
        ], "Dados nutricionais carregados");
    }

    // ===== POST: Save/update nutrition data =====
    elseif ($method === "POST") {
        $input = getInput();

        $productId = (int)($input['product_id'] ?? 0);
        if (!$productId) {
            response(false, null, "product_id obrigatorio", 400);
        }

        if (!$validateProduct($productId)) {
            response(false, null, "Produto nao encontrado ou nao pertence ao parceiro", 404);
        }

        // Save nutrition
        if (isset($input['nutrition']) && is_array($input['nutrition'])) {
            $n = $input['nutrition'];
            try {
                $stmt = $db->prepare("
                    INSERT INTO om_market_product_nutrition
                        (product_id, calories, protein, carbs, fat, fiber, sodium, serving_size, serving_unit, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ON CONFLICT (product_id) DO UPDATE SET
                        calories = EXCLUDED.calories,
                        protein = EXCLUDED.protein,
                        carbs = EXCLUDED.carbs,
                        fat = EXCLUDED.fat,
                        fiber = EXCLUDED.fiber,
                        sodium = EXCLUDED.sodium,
                        serving_size = EXCLUDED.serving_size,
                        serving_unit = EXCLUDED.serving_unit,
                        updated_at = NOW()
                ");
                $stmt->execute([
                    $productId,
                    isset($n['calories']) ? (float)$n['calories'] : null,
                    isset($n['protein']) ? (float)$n['protein'] : null,
                    isset($n['carbs']) ? (float)$n['carbs'] : null,
                    isset($n['fat']) ? (float)$n['fat'] : null,
                    isset($n['fiber']) ? (float)$n['fiber'] : null,
                    isset($n['sodium']) ? (float)$n['sodium'] : null,
                    isset($n['serving_size']) ? (float)$n['serving_size'] : null,
                    trim($n['serving_unit'] ?? 'g')
                ]);
            } catch (Exception $e) {
                error_log("[partner/nutrition] Erro ao salvar nutrition: " . $e->getMessage());
            }
        }

        // Save allergens (delete + reinsert)
        if (isset($input['allergens']) && is_array($input['allergens'])) {
            $validAllergens = ['gluten', 'lactose', 'nuts', 'eggs', 'soy', 'fish', 'shellfish'];
            try {
                $db->prepare("DELETE FROM om_market_product_allergens WHERE product_id = ?")->execute([$productId]);

                $stmtInsert = $db->prepare("
                    INSERT INTO om_market_product_allergens (product_id, allergen_type, created_at)
                    VALUES (?, ?, NOW())
                ");
                foreach ($input['allergens'] as $allergen) {
                    $allergen = trim($allergen);
                    if (in_array($allergen, $validAllergens, true)) {
                        $stmtInsert->execute([$productId, $allergen]);
                    }
                }
            } catch (Exception $e) {
                error_log("[partner/nutrition] Erro ao salvar allergens: " . $e->getMessage());
            }
        }

        // Save dietary badges
        if (isset($input['dietary']) && is_array($input['dietary'])) {
            $d = $input['dietary'];
            try {
                $stmt = $db->prepare("
                    INSERT INTO om_market_product_dietary
                        (product_id, vegan, vegetarian, organic, sugar_free, gluten_free, lactose_free, low_sodium, keto, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ON CONFLICT (product_id) DO UPDATE SET
                        vegan = EXCLUDED.vegan,
                        vegetarian = EXCLUDED.vegetarian,
                        organic = EXCLUDED.organic,
                        sugar_free = EXCLUDED.sugar_free,
                        gluten_free = EXCLUDED.gluten_free,
                        lactose_free = EXCLUDED.lactose_free,
                        low_sodium = EXCLUDED.low_sodium,
                        keto = EXCLUDED.keto,
                        updated_at = NOW()
                ");
                $stmt->execute([
                    $productId,
                    !empty($d['vegan']) ? 1 : 0,
                    !empty($d['vegetarian']) ? 1 : 0,
                    !empty($d['organic']) ? 1 : 0,
                    !empty($d['sugar_free']) ? 1 : 0,
                    !empty($d['gluten_free']) ? 1 : 0,
                    !empty($d['lactose_free']) ? 1 : 0,
                    !empty($d['low_sodium']) ? 1 : 0,
                    !empty($d['keto']) ? 1 : 0
                ]);
            } catch (Exception $e) {
                error_log("[partner/nutrition] Erro ao salvar dietary: " . $e->getMessage());
            }
        }

        response(true, ['product_id' => $productId], "Dados nutricionais salvos");
    }

    else {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[partner/nutrition] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
