<?php
/**
 * POST /api/mercado/partner/ai-menu-confirm.php
 * Batch-creates products from the AI-generated menu after partner review/confirmation.
 *
 * Auth: Bearer token (partner type)
 *
 * Body JSON:
 * {
 *   "categories": [
 *     {
 *       "name": "Categoria",
 *       "products": [
 *         {
 *           "name": "Produto",
 *           "description": "Descricao",
 *           "price": 29.90,
 *           "options": [
 *             {
 *               "group_name": "Tamanho",
 *               "required": true,
 *               "options": [
 *                 { "name": "P", "price_modifier": 0 },
 *                 { "name": "G", "price_modifier": 5.00 }
 *               ]
 *             }
 *           ]
 *         }
 *       ]
 *     }
 *   ]
 * }
 *
 * Response: { products_created, categories_created, option_groups_created, options_created }
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/rate-limit.php";
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
    $partnerId = (int)$payload['uid'];

    // Rate limiting: 5 bulk imports per hour per partner
    if (!checkRateLimit("ai_menu_confirm_{$partnerId}", 5, 60)) {
        response(false, null, "Muitas importacoes. Tente novamente em 1 hora.", 429);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido. Use POST.", 405);
    }

    $input = getInput();

    // ── Validate input structure ──
    if (!isset($input['categories']) || !is_array($input['categories'])) {
        response(false, null, "Campo 'categories' e obrigatorio e deve ser um array", 400);
    }

    if (empty($input['categories'])) {
        response(false, null, "Nenhuma categoria enviada", 400);
    }

    // Limit to avoid abuse
    $totalProducts = 0;
    foreach ($input['categories'] as $catIdx => $category) {
        if (!is_array($category)) {
            response(false, null, "Categoria no indice {$catIdx} e invalida", 400);
        }
        if (empty(trim($category['name'] ?? ''))) {
            response(false, null, "Categoria no indice {$catIdx} deve ter um nome", 400);
        }
        if (!isset($category['products']) || !is_array($category['products'])) {
            response(false, null, "Categoria '{$category['name']}' deve ter um array de produtos", 400);
        }
        foreach ($category['products'] as $prodIdx => $product) {
            if (!is_array($product)) {
                response(false, null, "Produto no indice {$prodIdx} da categoria '{$category['name']}' e invalido", 400);
            }
            if (empty(trim($product['name'] ?? ''))) {
                response(false, null, "Produto no indice {$prodIdx} da categoria '{$category['name']}' deve ter um nome", 400);
            }
            $price = (float)($product['price'] ?? 0);
            if ($price < 0) {
                response(false, null, "Preco do produto '{$product['name']}' nao pode ser negativo", 400);
            }
            // Validate options structure if present
            if (isset($product['options']) && is_array($product['options'])) {
                foreach ($product['options'] as $optGroupIdx => $optGroup) {
                    if (!is_array($optGroup)) {
                        response(false, null, "Grupo de opcoes no indice {$optGroupIdx} do produto '{$product['name']}' e invalido", 400);
                    }
                    if (empty(trim($optGroup['group_name'] ?? ''))) {
                        response(false, null, "Grupo de opcoes no indice {$optGroupIdx} do produto '{$product['name']}' deve ter um group_name", 400);
                    }
                    if (isset($optGroup['options']) && is_array($optGroup['options'])) {
                        foreach ($optGroup['options'] as $optIdx => $opt) {
                            if (!is_array($opt) || empty(trim($opt['name'] ?? ''))) {
                                response(false, null, "Opcao no indice {$optIdx} do grupo '{$optGroup['group_name']}' do produto '{$product['name']}' e invalida", 400);
                            }
                        }
                    }
                }
            }
            $totalProducts++;
        }
    }

    if ($totalProducts === 0) {
        response(false, null, "Nenhum produto encontrado nas categorias", 400);
    }

    if ($totalProducts > 500) {
        response(false, null, "Maximo de 500 produtos por importacao", 400);
    }

    // ── Begin transaction ──
    $db->beginTransaction();

    try {
        $categoriesCreated = 0;
        $productsCreated = 0;
        $optionGroupsCreated = 0;
        $optionsCreated = 0;
        $createdProductIds = [];

        foreach ($input['categories'] as $category) {
            $categoryName = trim($category['name']);

            // ── Find or create category ──
            $stmtFindCat = $db->prepare("
                SELECT category_id FROM om_market_categories
                WHERE LOWER(name) = LOWER(?) AND status = '1'
                LIMIT 1
            ");
            $stmtFindCat->execute([$categoryName]);
            $existingCat = $stmtFindCat->fetch();

            if ($existingCat) {
                $categoryId = (int)$existingCat['category_id'];
            } else {
                // Generate slug
                $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $categoryName)));
                $slug = trim($slug, '-');

                // Get next sort_order
                $stmtSort = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 as next_sort FROM om_market_categories");
                $stmtSort->execute();
                $nextSort = (int)$stmtSort->fetch()['next_sort'];

                $stmtInsertCat = $db->prepare("
                    INSERT INTO om_market_categories (name, slug, parent_id, sort_order, status)
                    VALUES (?, ?, 0, ?, 1)
                    RETURNING category_id
                ");
                $stmtInsertCat->execute([$categoryName, $slug, $nextSort]);
                $categoryId = (int)$stmtInsertCat->fetchColumn();
                $categoriesCreated++;
            }

            // ── Create products in this category ──
            foreach ($category['products'] as $product) {
                $prodName = trim(mb_substr($product['name'] ?? '', 0, 255));
                $prodDescription = trim($product['description'] ?? '');
                $prodPrice = round((float)($product['price'] ?? 0), 2);

                // 1. Insert into om_market_products_base
                $stmtBase = $db->prepare("
                    INSERT INTO om_market_products_base
                        (name, description, category_id, unit, status, date_added, date_modified)
                    VALUES (?, ?, ?, 'un', 1, NOW(), NOW())
                    RETURNING product_id
                ");
                $stmtBase->execute([$prodName, $prodDescription, $categoryId]);
                $productId = (int)$stmtBase->fetchColumn();
                if (!$productId) {
                    throw new Exception("Falha ao criar produto base: {$prodName}");
                }

                // 2. Insert into om_market_products_price
                $stmtPrice = $db->prepare("
                    INSERT INTO om_market_products_price
                        (product_id, partner_id, price, price_promo, stock, status, date_added, date_modified)
                    VALUES (?, ?, ?, NULL, 0, 1, NOW(), NOW())
                ");
                $stmtPrice->execute([$productId, $partnerId, $prodPrice]);

                $productsCreated++;
                $createdProductIds[] = $productId;

                // 3. Insert option groups and options
                $productOptions = $product['options'] ?? [];
                if (is_array($productOptions)) {
                    foreach ($productOptions as $sortIdx => $optGroup) {
                        $groupName = trim(mb_substr($optGroup['group_name'] ?? '', 0, 100));
                        if (empty($groupName)) continue;

                        $required = (int)(!empty($optGroup['required']));
                        $minSelect = $required ? 1 : 0;
                        $optionItems = $optGroup['options'] ?? [];
                        $maxSelect = is_array($optionItems) ? max(1, count($optionItems)) : 1;

                        $stmtGroup = $db->prepare("
                            INSERT INTO om_product_option_groups
                                (product_id, partner_id, name, required, min_select, max_select, sort_order, active, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
                            RETURNING id
                        ");
                        $stmtGroup->execute([
                            $productId,
                            $partnerId,
                            $groupName,
                            $required,
                            $minSelect,
                            $maxSelect,
                            $sortIdx
                        ]);

                        $groupId = (int)$stmtGroup->fetchColumn();
                        if (!$groupId) {
                            throw new Exception("Falha ao criar grupo de opcoes: {$groupName}");
                        }
                        $optionGroupsCreated++;

                        // Insert individual options
                        if (is_array($optionItems)) {
                            foreach ($optionItems as $optSortIdx => $opt) {
                                $optName = trim(mb_substr($opt['name'] ?? '', 0, 150));
                                if (empty($optName)) continue;

                                $priceExtra = round((float)($opt['price_modifier'] ?? 0), 2);

                                $stmtOpt = $db->prepare("
                                    INSERT INTO om_product_options
                                        (group_id, name, price_extra, available, sort_order, created_at)
                                    VALUES (?, ?, ?, 1, ?, NOW())
                                ");
                                $stmtOpt->execute([$groupId, $optName, $priceExtra, $optSortIdx]);
                                $optionsCreated++;
                            }
                        }
                    }
                }
            }
        }

        $db->commit();

        response(true, [
            "products_created" => $productsCreated,
            "categories_created" => $categoriesCreated,
            "option_groups_created" => $optionGroupsCreated,
            "options_created" => $optionsCreated,
            "product_ids" => $createdProductIds
        ], "Cardapio importado com sucesso! {$productsCreated} produto(s) criado(s).");

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("[partner/ai-menu-confirm] Erro: " . $e->getMessage());
    response(false, null, "Erro ao importar cardapio", 500);
}
