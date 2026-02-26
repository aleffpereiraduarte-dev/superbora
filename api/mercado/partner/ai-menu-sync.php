<?php
/**
 * POST /api/mercado/partner/ai-menu-sync.php
 * Syncs menu changes after ai-menu-compare: add products, update prices, deactivate items.
 *
 * Auth: Bearer token (partner type)
 * Rate limit: 5 per hour per partner
 *
 * Body JSON:
 * {
 *   "actions": {
 *     "add": [{"name": "...", "description": "...", "price": 29.90, "category": "..."}],
 *     "update_prices": [{"product_id": 123, "new_price": 29.90}],
 *     "deactivate": [456, 789]
 *   }
 * }
 *
 * Response: { added, price_updated, deactivated, product_ids }
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/rate-limit.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // --- Auth ---
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }
    $partnerId = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido. Use POST.", 405);
    }

    // --- Rate limiting ---
    if (!checkRateLimit($db, "ai_menu_sync_{$partnerId}", 5, 60)) {
        response(false, null, "Muitas sincronizacoes. Tente novamente em 1 hora.", 429);
    }

    $input = getInput();

    // --- Validate input ---
    if (!isset($input['actions']) || !is_array($input['actions'])) {
        response(false, null, "Campo 'actions' e obrigatorio e deve ser um objeto.", 400);
    }

    $actions = $input['actions'];
    $toAdd = $actions['add'] ?? [];
    $toUpdatePrices = $actions['update_prices'] ?? [];
    $toDeactivate = $actions['deactivate'] ?? [];

    if (!is_array($toAdd)) $toAdd = [];
    if (!is_array($toUpdatePrices)) $toUpdatePrices = [];
    if (!is_array($toDeactivate)) $toDeactivate = [];

    // Validate no action is empty
    if (empty($toAdd) && empty($toUpdatePrices) && empty($toDeactivate)) {
        response(false, null, "Nenhuma acao para executar. Envie pelo menos uma acao (add, update_prices ou deactivate).", 400);
    }

    // Validate add items
    foreach ($toAdd as $idx => $item) {
        if (!is_array($item)) {
            response(false, null, "Item para adicionar no indice {$idx} e invalido.", 400);
        }
        if (empty(trim($item['name'] ?? ''))) {
            response(false, null, "Item para adicionar no indice {$idx} deve ter um nome.", 400);
        }
        $price = (float)($item['price'] ?? 0);
        if ($price < 0) {
            response(false, null, "Preco do item '{$item['name']}' nao pode ser negativo.", 400);
        }
    }

    // Validate update_prices items
    foreach ($toUpdatePrices as $idx => $item) {
        if (!is_array($item)) {
            response(false, null, "Item para atualizar preco no indice {$idx} e invalido.", 400);
        }
        if (empty($item['product_id'])) {
            response(false, null, "Item para atualizar preco no indice {$idx} deve ter product_id.", 400);
        }
        if (!isset($item['new_price']) || (float)$item['new_price'] < 0) {
            response(false, null, "Novo preco do produto #{$item['product_id']} e invalido.", 400);
        }
    }

    // Validate deactivate items
    foreach ($toDeactivate as $idx => $productId) {
        if (!is_numeric($productId) || (int)$productId <= 0) {
            response(false, null, "Product ID para desativar no indice {$idx} e invalido.", 400);
        }
    }

    // Limit total actions to prevent abuse
    $totalActions = count($toAdd) + count($toUpdatePrices) + count($toDeactivate);
    if ($totalActions > 500) {
        response(false, null, "Maximo de 500 acoes por sincronizacao.", 400);
    }

    // --- Begin transaction ---
    $db->beginTransaction();

    try {
        $addedCount = 0;
        $priceUpdatedCount = 0;
        $deactivatedCount = 0;
        $createdProductIds = [];

        // ── ADD new products ──
        foreach ($toAdd as $item) {
            $prodName = trim(mb_substr($item['name'] ?? '', 0, 255));
            $prodDescription = trim($item['description'] ?? '');
            $prodPrice = round((float)($item['price'] ?? 0), 2);
            $categoryName = trim($item['category'] ?? 'Geral');

            // Find or create category
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
                $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $categoryName)));
                $slug = trim($slug, '-');

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
            }

            // Insert product base
            $stmtBase = $db->prepare("
                INSERT INTO om_market_products_base
                    (name, description, category_id, unit, status, date_added, date_modified)
                VALUES (?, ?, ?, 'un', 1, NOW(), NOW())
                RETURNING product_id
            ");
            $stmtBase->execute([$prodName, $prodDescription, $categoryId]);
            $productId = (int)$stmtBase->fetchColumn();

            if (!$productId) {
                throw new Exception("Falha ao criar produto: {$prodName}");
            }

            // Insert product price
            $stmtPrice = $db->prepare("
                INSERT INTO om_market_products_price
                    (product_id, partner_id, price, price_promo, stock, status, date_added, date_modified)
                VALUES (?, ?, ?, NULL, 0, 1, NOW(), NOW())
            ");
            $stmtPrice->execute([$productId, $partnerId, $prodPrice]);

            $createdProductIds[] = $productId;
            $addedCount++;
        }

        // ── UPDATE prices ──
        $stmtUpdatePrice = $db->prepare("
            UPDATE om_market_products_price
            SET price = ?, date_modified = NOW()
            WHERE product_id = ? AND partner_id = ?
        ");

        foreach ($toUpdatePrices as $item) {
            $productId = (int)$item['product_id'];
            $newPrice = round((float)$item['new_price'], 2);

            // Verify product belongs to this partner
            $stmtCheck = $db->prepare("
                SELECT product_id FROM om_market_products_price
                WHERE product_id = ? AND partner_id = ? AND status = 1
            ");
            $stmtCheck->execute([$productId, $partnerId]);
            if (!$stmtCheck->fetch()) {
                continue; // Skip products not belonging to this partner
            }

            $stmtUpdatePrice->execute([$newPrice, $productId, $partnerId]);
            $priceUpdatedCount++;
        }

        // ── DEACTIVATE products ──
        $stmtDeactivate = $db->prepare("
            UPDATE om_market_products_price
            SET status = 0, date_modified = NOW()
            WHERE product_id = ? AND partner_id = ?
        ");

        foreach ($toDeactivate as $productId) {
            $productId = (int)$productId;

            // Verify product belongs to this partner
            $stmtCheck = $db->prepare("
                SELECT product_id FROM om_market_products_price
                WHERE product_id = ? AND partner_id = ? AND status = 1
            ");
            $stmtCheck->execute([$productId, $partnerId]);
            if (!$stmtCheck->fetch()) {
                continue;
            }

            $stmtDeactivate->execute([$productId, $partnerId]);
            $deactivatedCount++;
        }

        $db->commit();

        // Log the sync session
        try {
            $stmtLog = $db->prepare("
                INSERT INTO om_ai_menu_sessions (partner_id, session_type, input_data, result_data, status, tokens_used)
                VALUES (?, 'sync', ?, ?, 'completed', 0)
            ");
            $stmtLog->execute([
                $partnerId,
                json_encode([
                    'add_requested' => count($toAdd),
                    'update_prices_requested' => count($toUpdatePrices),
                    'deactivate_requested' => count($toDeactivate),
                ]),
                json_encode([
                    'added' => $addedCount,
                    'price_updated' => $priceUpdatedCount,
                    'deactivated' => $deactivatedCount,
                ]),
            ]);
        } catch (Exception $e) {
            error_log("[partner/ai-menu-sync] Log error: " . $e->getMessage());
        }

        $messages = [];
        if ($addedCount > 0) $messages[] = "{$addedCount} produto(s) adicionado(s)";
        if ($priceUpdatedCount > 0) $messages[] = "{$priceUpdatedCount} preco(s) atualizado(s)";
        if ($deactivatedCount > 0) $messages[] = "{$deactivatedCount} produto(s) desativado(s)";

        response(true, [
            'added' => $addedCount,
            'price_updated' => $priceUpdatedCount,
            'deactivated' => $deactivatedCount,
            'product_ids' => $createdProductIds,
        ], "Sincronizacao concluida! " . implode(', ', $messages) . ".");

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("[partner/ai-menu-sync] Erro: " . $e->getMessage());
    response(false, null, "Erro ao sincronizar cardapio", 500);
}
