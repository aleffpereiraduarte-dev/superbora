<?php
/**
 * Shopping List AI (like iFood's "Compr.IA")
 * POST: {input: "ingredientes pra bolo de chocolate pra 10 pessoas"}
 * Returns ingredient list matched with real products
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { response(false, null, 'Method not allowed', 405); }

$customerId = requireCustomerAuth();
checkRateLimit("ai_lista:{$customerId}", 10, 60);
$input = json_decode(file_get_contents('php://input'), true);
$text = trim($input['input'] ?? '');

if (empty($text)) { response(false, null, 'Input required', 400); }
if (strlen($text) > 500) { response(false, null, 'Input too long', 400); }

// Ask Claude to extract ingredients
$claude = new ClaudeClient();
$systemPrompt = "Voce e um assistente de compras de supermercado. Extraia ingredientes de receitas ou listas de compras.
Retorne JSON: {\"recipe_name\": \"...\", \"servings\": 0, \"ingredients\": [{\"name\": \"...\", \"quantity\": \"...\", \"search_term\": \"...\"}]}
O search_term deve ser a palavra-chave para buscar no supermercado (ex: 'farinha de trigo' ao inves de '2 xicaras de farinha de trigo peneirada').
Se nao for receita, trate como lista de compras.";

$result = $claude->send($systemPrompt, [['role' => 'user', 'content' => $text]], 2048);
if (!$result['success']) { response(false, null, 'AI processing failed', 500); }

$parsed = ClaudeClient::parseJson($result['text']);
if (!$parsed || !isset($parsed['ingredients'])) { response(false, null, 'Could not parse ingredients', 500); }

// Search each ingredient in Meilisearch
$matched = [];
$totalEstimated = 0;
$meiliKey = $_ENV['MEILI_ADMIN_KEY'] ?? getenv('MEILI_ADMIN_KEY') ?: '';

foreach ($parsed['ingredients'] as $ing) {
    $searchTerm = $ing['search_term'] ?? $ing['name'];
    $products = [];

    try {
        $ch = curl_init('http://localhost:7700/indexes/products/search');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['q' => $searchTerm, 'limit' => 3]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $meiliKey],
            CURLOPT_TIMEOUT => 3,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($res, true);
        $products = array_map(fn($h) => [
            'product_id' => $h['id'] ?? 0,
            'name' => $h['name'] ?? $h['nome'] ?? '',
            'price' => floatval($h['price'] ?? $h['preco'] ?? 0),
            'partner_name' => $h['partner_name'] ?? '',
            'image' => $h['image'] ?? '',
        ], $data['hits'] ?? []);
    } catch (Exception $e) { /* skip */ }

    $bestMatch = $products[0] ?? null;
    if ($bestMatch) { $totalEstimated += $bestMatch['price']; }

    $matched[] = [
        'ingredient' => $ing['name'],
        'quantity' => $ing['quantity'] ?? '',
        'matched_products' => $products,
        'best_match' => $bestMatch,
    ];
}

response(true, [
    'recipe_name' => $parsed['recipe_name'] ?? '',
    'servings' => $parsed['servings'] ?? 0,
    'ingredients' => $matched,
    'total_estimated' => round($totalEstimated, 2),
    'matched_count' => count(array_filter($matched, fn($m) => $m['best_match'] !== null)),
    'total_count' => count($matched),
]);
