<?php
/**
 * Smart Tags — AI-generated dietary/preference tags for products
 *
 * GET  /api/mercado/intelligence/smart-tags.php?product_id=123
 *   Returns cached smart tags for a single product
 *
 * GET  /api/mercado/intelligence/smart-tags.php?product_ids=123,456,789
 *   Returns cached smart tags for multiple products (batch)
 *
 * POST /api/mercado/intelligence/smart-tags.php
 *   Body: { "product_id": 123 }  OR  { "product_ids": [123, 456] }
 *   Generates smart tags via Claude AI (creates if not cached)
 *
 * GET  /api/mercado/intelligence/smart-tags.php?action=cron&limit=20
 *   Batch-generate tags for products without them (cron use)
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/claude-client.php";
require_once __DIR__ . "/../helpers/smart-tags-functions.php";
setCorsHeaders();

try {
    $db = getDB();
    $method = $_SERVER['REQUEST_METHOD'];

    // ═══ GET: Retrieve cached smart tags ═══
    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

        // Cron batch endpoint
        if ($action === 'cron') {
            $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
            $result = batchGenerateTags($db, $limit);
            response(true, $result);
        }

        // Single product
        $productId = (int)($_GET['product_id'] ?? 0);
        // Batch products
        $productIdsRaw = $_GET['product_ids'] ?? '';

        if ($productId) {
            $tags = getTagsForProduct($db, $productId);
            response(true, ['product_id' => $productId, 'tags' => $tags]);
        }

        if ($productIdsRaw) {
            $productIds = array_filter(array_map('intval', explode(',', $productIdsRaw)));
            if (empty($productIds)) {
                response(false, null, 'product_ids invalidos', 400);
            }
            if (count($productIds) > 100) {
                response(false, null, 'Maximo 100 produtos por requisicao', 400);
            }
            $result = getTagsForProducts($db, $productIds);
            response(true, ['products' => $result]);
        }

        response(false, null, 'product_id ou product_ids obrigatorio', 400);
    }

    // ═══ POST: Generate smart tags via AI ═══
    if ($method === 'POST') {
        $input = getInput();
        $productId = (int)($input['product_id'] ?? 0);
        $productIds = $input['product_ids'] ?? [];
        $force = (bool)($input['force'] ?? false);

        if ($productId) {
            $productIds = [$productId];
        }

        if (!is_array($productIds) || empty($productIds)) {
            response(false, null, 'product_id ou product_ids obrigatorio', 400);
        }

        $productIds = array_filter(array_map('intval', $productIds));
        if (count($productIds) > 20) {
            response(false, null, 'Maximo 20 produtos por requisicao POST', 400);
        }

        $results = [];
        $totalTokens = 0;

        foreach ($productIds as $pid) {
            // Check if already has tags (skip if not forcing)
            if (!$force) {
                $existing = getTagsForProduct($db, $pid);
                if (!empty($existing)) {
                    $results[$pid] = ['tags' => $existing, 'source' => 'cache'];
                    continue;
                }
            }

            $genResult = generateTagsForProduct($db, $pid);
            if ($genResult['success']) {
                $results[$pid] = ['tags' => $genResult['tags'], 'source' => 'ai'];
                $totalTokens += $genResult['tokens'] ?? 0;
            } else {
                $results[$pid] = ['tags' => [], 'error' => $genResult['error']];
            }
        }

        response(true, [
            'products' => $results,
            'total_tokens' => $totalTokens,
        ]);
    }

    response(false, null, 'Metodo nao permitido', 405);

} catch (Exception $e) {
    error_log("[SmartTags] Error: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
