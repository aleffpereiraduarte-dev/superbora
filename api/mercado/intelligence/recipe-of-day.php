<?php
/**
 * GET /api/mercado/intelligence/recipe-of-day.php?partner_id=123
 * Suggests a recipe using items from the customer's favorite market.
 *
 * Authenticated. Returns:
 *   { "title":"...","time":"30 min","servings":4,
 *     "ingredients":[{"name":"...","qty":"...","product_id":123}],
 *     "steps":["...","..."], "cart_total": 45.90 }
 *
 * Cached 12h per partner per day.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
require_once dirname(__DIR__, 3) . '/includes/classes/OmAuth.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $partnerId = (int)($_GET['partner_id'] ?? 0);
    if (!$partnerId) {
        response(false, null, 'partner_id obrigatorio', 400);
    }

    $cacheFile = sys_get_temp_dir() . "/sb_recipe_{$partnerId}_" . date('Ymd') . ".json";
    if (file_exists($cacheFile)) {
        response(true, json_decode(file_get_contents($cacheFile), true));
    }

    // Get top 30 products from this partner
    $stmt = $db->prepare(
        "SELECT product_id, name, price, category
         FROM om_market_products
         WHERE partner_id = :pid AND status::text = '1' AND price > 0 AND price < 50
         ORDER BY product_id LIMIT 50"
    );
    $stmt->execute([':pid' => $partnerId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($products)) {
        response(false, null, 'sem produtos suficientes', 404);
    }

    $catalog = array_map(fn($p) => "id={$p['product_id']} {$p['name']} R${$p['price']}", array_slice($products, 0, 30));

    $userPrompt = "Sugira UMA receita simples usando produtos do catalogo abaixo. " .
                  "Selecione 4-8 ingredientes do catalogo (use os IDs). Responda APENAS JSON:\n\n" .
                  "Catalogo:\n" . implode("\n", $catalog) . "\n\n" .
                  '{"title":"...","time":"30 min","servings":4,"ingredients":[{"product_id":123,"qty":"100g","note":""}],"steps":["1. ...","2. ..."]}';

    $reply = ClaudeClient::text(
        $userPrompt,
        'Voce eh um chef brasileiro. Use sempre produtos reais do catalogo. Responda APENAS JSON.',
        800
    );
    $parsed = ClaudeClient::parseJson($reply ?: '') ?: [];
    if (empty($parsed['title']) || empty($parsed['ingredients'])) {
        response(false, null, 'falha ao gerar receita', 502);
    }

    // Resolve IDs to product info and compute cart total
    $byId = [];
    foreach ($products as $p) $byId[(int)$p['product_id']] = $p;

    $resolved = [];
    $total = 0;
    foreach ($parsed['ingredients'] as $ing) {
        $pid = (int)($ing['product_id'] ?? 0);
        if (!isset($byId[$pid])) continue;
        $resolved[] = [
            'product_id' => $pid,
            'name' => $byId[$pid]['name'],
            'price' => (float)$byId[$pid]['price'],
            'qty' => $ing['qty'] ?? '1 un',
            'note' => $ing['note'] ?? '',
        ];
        $total += (float)$byId[$pid]['price'];
    }

    $out = [
        'partner_id' => $partnerId,
        'title' => $parsed['title'],
        'time' => $parsed['time'] ?? '',
        'servings' => $parsed['servings'] ?? 2,
        'ingredients' => $resolved,
        'steps' => is_array($parsed['steps'] ?? null) ? $parsed['steps'] : [],
        'cart_total' => round($total, 2),
    ];
    @file_put_contents($cacheFile, json_encode($out));
    response(true, $out);
} catch (Exception $e) {
    error_log('[recipe-of-day] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
