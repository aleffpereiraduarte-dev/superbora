<?php
/**
 * Smart Tags — Shared functions for AI-generated product tags
 * Used by both the HTTP endpoint and the cron batch processor.
 *
 * Requires: config/database.php and helpers/claude-client.php
 * to be loaded before including this file.
 */

// Guard against double-inclusion
if (defined('SMART_TAGS_FUNCTIONS_LOADED')) return;
define('SMART_TAGS_FUNCTIONS_LOADED', true);

// ─── Tag definitions (canonical list) ────────────────────────────────
const VALID_TAGS = [
    // Dietary
    'vegetariano'     => ['label' => 'Vegetariano',     'category' => 'dietary'],
    'vegano'          => ['label' => 'Vegano',          'category' => 'dietary'],
    'sem-gluten'      => ['label' => 'Sem Gluten',      'category' => 'dietary'],
    'sem-lactose'     => ['label' => 'Sem Lactose',     'category' => 'dietary'],
    'low-carb'        => ['label' => 'Low Carb',        'category' => 'dietary'],
    'keto'            => ['label' => 'Keto',            'category' => 'dietary'],
    'integral'        => ['label' => 'Integral',        'category' => 'dietary'],
    'zero-acucar'     => ['label' => 'Zero Acucar',     'category' => 'dietary'],
    'light'           => ['label' => 'Light',           'category' => 'dietary'],
    'kosher'          => ['label' => 'Kosher',          'category' => 'dietary'],
    'halal'           => ['label' => 'Halal',           'category' => 'dietary'],

    // Attributes
    'picante'         => ['label' => 'Picante',         'category' => 'attribute'],
    'alto-em-proteina'=> ['label' => 'Alto em Proteina','category' => 'attribute'],
    'organico'        => ['label' => 'Organico',        'category' => 'attribute'],
    'artesanal'       => ['label' => 'Artesanal',       'category' => 'attribute'],
    'fitness'         => ['label' => 'Fitness',         'category' => 'attribute'],
    'comfort-food'    => ['label' => 'Comfort Food',    'category' => 'attribute'],
    'sem-conservantes'=> ['label' => 'Sem Conservantes','category' => 'attribute'],
    'natural'         => ['label' => 'Natural',         'category' => 'attribute'],
    'importado'       => ['label' => 'Importado',       'category' => 'attribute'],
    'premium'         => ['label' => 'Premium',         'category' => 'attribute'],

    // Allergens
    'contem-nozes'    => ['label' => 'Contem Nozes',    'category' => 'allergen'],
    'contem-amendoim' => ['label' => 'Contem Amendoim', 'category' => 'allergen'],
    'contem-soja'     => ['label' => 'Contem Soja',     'category' => 'allergen'],
    'contem-ovo'      => ['label' => 'Contem Ovo',      'category' => 'allergen'],
    'contem-leite'    => ['label' => 'Contem Leite',    'category' => 'allergen'],
    'contem-trigo'    => ['label' => 'Contem Trigo',    'category' => 'allergen'],
    'contem-peixe'    => ['label' => 'Contem Peixe',    'category' => 'allergen'],
    'contem-crustaceo' => ['label' => 'Contem Crustaceo','category' => 'allergen'],
];

/**
 * Get cached smart tags for a single product
 */
function getTagsForProduct(PDO $db, int $productId): array {
    $stmt = $db->prepare("
        SELECT tag_slug, tag_label, tag_category, confidence
        FROM om_product_smart_tags
        WHERE product_id = ?
        ORDER BY
            CASE tag_category
                WHEN 'dietary' THEN 1
                WHEN 'attribute' THEN 2
                WHEN 'allergen' THEN 3
            END,
            confidence DESC
    ");
    $stmt->execute([$productId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get cached smart tags for multiple products (batch)
 */
function getTagsForProducts(PDO $db, array $productIds): array {
    if (empty($productIds)) return [];

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $db->prepare("
        SELECT product_id, tag_slug, tag_label, tag_category, confidence
        FROM om_product_smart_tags
        WHERE product_id IN ({$placeholders})
        ORDER BY product_id,
            CASE tag_category
                WHEN 'dietary' THEN 1
                WHEN 'attribute' THEN 2
                WHEN 'allergen' THEN 3
            END,
            confidence DESC
    ");
    $stmt->execute(array_values($productIds));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by product_id
    $result = [];
    foreach ($productIds as $pid) {
        $result[$pid] = [];
    }
    foreach ($rows as $row) {
        $pid = (int)$row['product_id'];
        unset($row['product_id']);
        $result[$pid][] = $row;
    }
    return $result;
}

/**
 * Generate smart tags for a single product using Claude AI
 */
function generateTagsForProduct(PDO $db, int $productId): array {
    // Fetch product data
    $stmt = $db->prepare("
        SELECT p.id, p.name, p.description, p.brand, p.unit,
               p.is_organic, p.is_fresh, p.is_frozen,
               p.dietary_tags, p.allergens,
               c.name as category_name
        FROM om_market_products p
        LEFT JOIN om_market_categories c ON c.category_id = p.category_id
        WHERE p.id = ? AND p.status::text = '1'
    ");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        // Try product_id column
        $stmt2 = $db->prepare("
            SELECT p.id, p.name, p.description, p.brand, p.unit,
                   p.is_organic, p.is_fresh, p.is_frozen,
                   p.dietary_tags, p.allergens,
                   c.name as category_name
            FROM om_market_products p
            LEFT JOIN om_market_categories c ON c.category_id = p.category_id
            WHERE p.product_id = ? AND p.status::text = '1'
        ");
        $stmt2->execute([$productId]);
        $product = $stmt2->fetch(PDO::FETCH_ASSOC);
    }

    if (!$product) {
        logTagProcessing($db, $productId, 'failed', 'Produto nao encontrado');
        return ['success' => false, 'error' => 'Produto nao encontrado'];
    }

    // Build context for AI
    $productName = $product['name'] ?? '';
    $description = $product['description'] ?? '';
    $brand = $product['brand'] ?? '';
    $category = $product['category_name'] ?? '';
    $unit = $product['unit'] ?? '';
    $isOrganic = (bool)$product['is_organic'];
    $isFresh = (bool)$product['is_fresh'];
    $isFrozen = (bool)$product['is_frozen'];

    // Include existing manual tags if any
    $existingDietary = $product['dietary_tags'] ? json_decode($product['dietary_tags'], true) : [];
    $existingAllergens = $product['allergens'] ? json_decode($product['allergens'], true) : [];

    $systemPrompt = "Voce e um especialista em classificacao de alimentos e produtos de supermercado brasileiro. Sua tarefa e analisar produtos e atribuir tags relevantes de um conjunto pre-definido. Seja preciso e conservador — so atribua uma tag se tiver alta confianca. Para alergenicos, erre pelo lado da cautela (melhor alertar do que omitir).";

    $userPrompt = "Analise este produto de supermercado e atribua as tags relevantes.\n\n";
    $userPrompt .= "PRODUTO:\n";
    $userPrompt .= "- Nome: {$productName}\n";
    if ($description) $userPrompt .= "- Descricao: {$description}\n";
    if ($brand) $userPrompt .= "- Marca: {$brand}\n";
    if ($category) $userPrompt .= "- Categoria: {$category}\n";
    if ($unit) $userPrompt .= "- Unidade: {$unit}\n";
    if ($isOrganic) $userPrompt .= "- Flag: organico\n";
    if ($isFresh) $userPrompt .= "- Flag: fresco\n";
    if ($isFrozen) $userPrompt .= "- Flag: congelado\n";
    if (!empty($existingDietary)) $userPrompt .= "- Tags existentes: " . implode(', ', $existingDietary) . "\n";
    if (!empty($existingAllergens)) $userPrompt .= "- Alergenicos existentes: " . implode(', ', $existingAllergens) . "\n";

    $userPrompt .= "\nTAGS DISPONIVEIS (use APENAS estes slugs):\n";

    // Group by category for clarity
    $byCategory = ['dietary' => [], 'attribute' => [], 'allergen' => []];
    foreach (VALID_TAGS as $slug => $info) {
        $byCategory[$info['category']][] = $slug;
    }
    $userPrompt .= "Dieteticas: " . implode(', ', $byCategory['dietary']) . "\n";
    $userPrompt .= "Atributos: " . implode(', ', $byCategory['attribute']) . "\n";
    $userPrompt .= "Alergenicos: " . implode(', ', $byCategory['allergen']) . "\n";

    $userPrompt .= "\nResponda APENAS com um JSON array. Cada item deve ter:\n";
    $userPrompt .= '- "slug": um dos slugs validos acima' . "\n";
    $userPrompt .= '- "confidence": numero de 0.50 a 1.00 (sua confianca na tag)' . "\n";
    $userPrompt .= "\nExemplo: [{\"slug\": \"vegano\", \"confidence\": 0.95}, {\"slug\": \"sem-gluten\", \"confidence\": 0.80}]\n";
    $userPrompt .= "\nRegras:\n";
    $userPrompt .= "- NAO invente tags fora da lista\n";
    $userPrompt .= "- So inclua tags com confianca >= 0.60\n";
    $userPrompt .= "- Para alergenicos, inclua se ha chance razoavel (confidence >= 0.60)\n";
    $userPrompt .= "- Se nao ha tags relevantes, retorne um array vazio []\n";
    $userPrompt .= "- Maximo 8 tags por produto\n";

    $claude = new ClaudeClient(ClaudeClient::DEFAULT_MODEL, 30, 1);
    $result = $claude->send($systemPrompt, [
        ['role' => 'user', 'content' => $userPrompt]
    ], 512);

    if (!$result['success']) {
        logTagProcessing($db, $productId, 'failed', $result['error'] ?? 'AI error');
        return ['success' => false, 'error' => $result['error'] ?? 'AI call failed'];
    }

    $parsed = ClaudeClient::parseJson($result['text']);
    if ($parsed === null) {
        logTagProcessing($db, $productId, 'failed', 'Could not parse AI response');
        return ['success' => false, 'error' => 'Could not parse AI response'];
    }

    // Validate and normalize tags
    $tags = [];
    // Handle both array-of-objects and the entire parsed response
    $tagItems = isset($parsed[0]) ? $parsed : (isset($parsed['tags']) ? $parsed['tags'] : []);

    foreach ($tagItems as $item) {
        $slug = $item['slug'] ?? '';
        $confidence = (float)($item['confidence'] ?? 0);

        // Only accept valid slugs with sufficient confidence
        if (!isset(VALID_TAGS[$slug]) || $confidence < 0.60) {
            continue;
        }

        $tags[] = [
            'slug' => $slug,
            'label' => VALID_TAGS[$slug]['label'],
            'category' => VALID_TAGS[$slug]['category'],
            'confidence' => round(min(1.0, $confidence), 2),
        ];
    }

    // Limit to 8 tags max
    $tags = array_slice($tags, 0, 8);

    // Persist to database
    saveTags($db, $productId, $tags);
    logTagProcessing($db, $productId, 'processed', null, $result['total_tokens'] ?? 0);

    return [
        'success' => true,
        'tags' => $tags,
        'tokens' => $result['total_tokens'] ?? 0,
    ];
}

/**
 * Save tags to the database (upsert)
 */
function saveTags(PDO $db, int $productId, array $tags): void {
    // Delete old tags for this product first, then insert new ones in a transaction
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("DELETE FROM om_product_smart_tags WHERE product_id = ?");
        $stmt->execute([$productId]);

        if (!empty($tags)) {
            $insertStmt = $db->prepare("
                INSERT INTO om_product_smart_tags (product_id, tag_slug, tag_label, tag_category, confidence, source)
                VALUES (?, ?, ?, ?, ?, 'ai')
                ON CONFLICT (product_id, tag_slug) DO UPDATE SET
                    tag_label = EXCLUDED.tag_label,
                    tag_category = EXCLUDED.tag_category,
                    confidence = EXCLUDED.confidence,
                    updated_at = NOW()
            ");

            foreach ($tags as $tag) {
                $insertStmt->execute([
                    $productId,
                    $tag['slug'],
                    $tag['label'],
                    $tag['category'],
                    $tag['confidence'],
                ]);
            }
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        error_log("[SmartTags] Failed to save tags for product {$productId}: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Log tag processing status
 */
function logTagProcessing(PDO $db, int $productId, string $status, ?string $error = null, int $tokens = 0): void {
    try {
        $stmt = $db->prepare("
            INSERT INTO om_smart_tags_log (product_id, status, error_message, tokens_used, processed_at)
            VALUES (?, ?, ?, ?, NOW())
            ON CONFLICT (product_id) DO UPDATE SET
                status = EXCLUDED.status,
                error_message = EXCLUDED.error_message,
                tokens_used = EXCLUDED.tokens_used,
                processed_at = NOW()
        ");
        $stmt->execute([$productId, $status, $error, $tokens]);
    } catch (Exception $e) {
        error_log("[SmartTags] Failed to log processing: " . $e->getMessage());
    }
}

/**
 * Batch-generate tags for products that don't have them yet
 * Designed for cron usage — processes N products per run
 */
function batchGenerateTags(PDO $db, int $limit = 20): array {
    // Find products without smart tags (and not recently failed)
    $stmt = $db->prepare("
        SELECT p.id
        FROM om_market_products p
        WHERE p.status::text = '1'
          AND NOT EXISTS (
              SELECT 1 FROM om_product_smart_tags st WHERE st.product_id = p.id
          )
          AND NOT EXISTS (
              SELECT 1 FROM om_smart_tags_log l
              WHERE l.product_id = p.id
                AND l.status = 'failed'
                AND l.processed_at > NOW() - INTERVAL '24 hours'
          )
        ORDER BY p.id
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $productIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($productIds)) {
        return [
            'processed' => 0,
            'total_tokens' => 0,
            'message' => 'Nenhum produto pendente para gerar tags',
        ];
    }

    $processed = 0;
    $failed = 0;
    $totalTokens = 0;

    foreach ($productIds as $pid) {
        $result = generateTagsForProduct($db, (int)$pid);
        if ($result['success']) {
            $processed++;
            $totalTokens += $result['tokens'] ?? 0;
        } else {
            $failed++;
        }

        // Small delay between API calls to respect rate limits
        if ($processed + $failed < count($productIds)) {
            usleep(500000); // 0.5s
        }
    }

    return [
        'processed' => $processed,
        'failed' => $failed,
        'total_tokens' => $totalTokens,
        'products_remaining' => countProductsWithoutTags($db),
    ];
}

/**
 * Count products that still need tags
 */
function countProductsWithoutTags(PDO $db): int {
    $stmt = $db->query("
        SELECT COUNT(*)
        FROM om_market_products p
        WHERE p.status::text = '1'
          AND NOT EXISTS (
              SELECT 1 FROM om_product_smart_tags st WHERE st.product_id = p.id
          )
    ");
    return (int)$stmt->fetchColumn();
}
