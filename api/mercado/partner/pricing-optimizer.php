<?php
/**
 * GET /api/mercado/partner/pricing-optimizer.php
 * Auth: partner
 *
 * Compares the partner's product prices to similar products on the platform
 * and suggests adjustments. Llama generates the rationale.
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
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, 'Token ausente', 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) response(false, null, 'Acesso restrito', 403);
    $partnerId = (int)$payload['uid'];

    $stmt = $db->prepare("SELECT categoria FROM om_market_partners WHERE partner_id = :pid");
    $stmt->execute([':pid' => $partnerId]);
    $cat = $stmt->fetchColumn();

    // Get partner's products + market average for similar names
    $prods = $db->prepare(
        "SELECT product_id, name, price FROM om_market_products
         WHERE partner_id = :pid AND status::text = '1'
         LIMIT 30"
    );
    $prods->execute([':pid' => $partnerId]);
    $myProducts = $prods->fetchAll(PDO::FETCH_ASSOC);

    $analyses = [];
    foreach ($myProducts as $mp) {
        // Find similar in same category
        $sim = $db->prepare(
            "SELECT AVG(price) AS avg_p, COUNT(*) AS qty, MIN(price) AS min_p, MAX(price) AS max_p
             FROM om_market_products mp
             JOIN om_market_partners pa ON mp.partner_id = pa.partner_id
             WHERE pa.categoria = :cat
               AND pa.partner_id <> :pid
               AND LOWER(mp.name) LIKE :q
               AND mp.status::text = '1' AND mp.price > 0"
        );
        $token = mb_substr(trim($mp['name']), 0, 20);
        $sim->execute([':cat' => $cat, ':pid' => $partnerId, ':q' => '%' . strtolower($token) . '%']);
        $market = $sim->fetch(PDO::FETCH_ASSOC);
        if (!$market || (int)$market['qty'] < 2) continue;

        $myPrice = (float)$mp['price'];
        $marketAvg = (float)$market['avg_p'];
        $diffPct = $marketAvg > 0 ? round((($myPrice - $marketAvg) / $marketAvg) * 100, 1) : 0;

        if (abs($diffPct) < 5) continue; // ignorar diferenças pequenas

        $analyses[] = [
            'product_id' => (int)$mp['product_id'],
            'name' => $mp['name'],
            'my_price' => $myPrice,
            'market_avg' => round($marketAvg, 2),
            'market_min' => round((float)$market['min_p'], 2),
            'market_max' => round((float)$market['max_p'], 2),
            'samples' => (int)$market['qty'],
            'diff_pct' => $diffPct,
        ];
    }

    if (empty($analyses)) {
        response(true, ['suggestions' => [], 'message' => 'Seus precos estao alinhados com o mercado.']);
    }

    $prompt = "Analise pricing pro parceiro do SuperBora ({$cat}). " .
              "Comparativo (diff_pct = quanto seu preco esta acima/abaixo da media):\n" .
              json_encode(array_slice($analyses, 0, 15), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n" .
              "Sugira ajustes em pt-BR. Para cada item: explique se subir/baixar/manter e por que. " .
              "Responda APENAS JSON: " .
              '{"summary":"resumo geral max 200 chars",' .
              '"suggestions":[{"product_id":123,"action":"subir|baixar|manter","new_price":0,"reason":"..."}]}';

    $reply = ClaudeClient::text($prompt, 'Voce eh consultor de pricing. Estrategico, baseado em dados de mercado.', 1500);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed) response(false, null, 'falha analise', 502);

    response(true, [
        'partner_id' => $partnerId,
        'summary' => $parsed['summary'] ?? '',
        'suggestions' => $parsed['suggestions'] ?? [],
        'analyses' => $analyses,
    ]);
} catch (Exception $e) {
    error_log('[pricing-optimizer] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
