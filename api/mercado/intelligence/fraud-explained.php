<?php
/**
 * POST /api/mercado/intelligence/fraud-explained.php
 * Body: { "order_id":123 }
 *
 * Pulls signals about the order and asks Llama to label risk + explain.
 * Returns: { "risk":0-100, "level":"low|medium|high","flags":["..."],"explanation":"...","action":"allow|review|block" }
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $orderId = (int)($input['order_id'] ?? 0);
    if (!$orderId) response(false, null, 'order_id obrigatorio', 400);

    $db = getDB();

    $stmt = $db->prepare(
        "SELECT o.order_id, o.customer_id, o.total, o.payment_method, o.created_at,
                c.name, c.email, c.phone, c.created_at AS customer_since,
                (SELECT COUNT(*) FROM om_market_orders WHERE customer_id = o.customer_id AND created_at < o.created_at) AS prev_orders,
                (SELECT COUNT(*) FROM om_market_orders WHERE customer_id = o.customer_id AND created_at >= NOW() - INTERVAL '24 hours') AS orders_24h
         FROM om_market_orders o
         LEFT JOIN om_customers c ON o.customer_id = c.customer_id
         WHERE o.order_id = :oid"
    );
    $stmt->execute([':oid' => $orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) response(false, null, 'pedido nao encontrado', 404);

    // Compute simple signals
    $accountAge = $row['customer_since']
        ? max(0, (time() - strtotime($row['customer_since'])) / 86400)
        : 0;
    $signals = [
        'order_id' => $orderId,
        'amount' => (float)$row['total'],
        'payment_method' => $row['payment_method'],
        'account_age_days' => round($accountAge, 1),
        'previous_orders' => (int)$row['prev_orders'],
        'orders_24h' => (int)$row['orders_24h'],
        'high_value' => (float)$row['total'] > 500,
        'very_high_value' => (float)$row['total'] > 1000,
        'new_account' => $accountAge < 1,
        'frequent_24h' => (int)$row['orders_24h'] > 3,
    ];

    $prompt = "Analise risco de fraude. Sinais:\n" . json_encode($signals, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n" .
              "Em pt-BR responda APENAS JSON: " .
              '{"risk":0-100,"level":"low|medium|high|critical","flags":["..."],' .
              '"explanation":"max 200 chars","action":"allow|review|block"}';

    $reply = ClaudeClient::text(
        $prompt,
        'Voce eh analista de fraude do SuperBora. Calibrado: low <40, medium 40-69, high 70+.',
        500
    );
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed) response(false, null, 'falha analise', 502);

    $out = [
        'order_id' => $orderId,
        'risk' => (int)($parsed['risk'] ?? 0),
        'level' => $parsed['level'] ?? 'low',
        'flags' => is_array($parsed['flags'] ?? null) ? $parsed['flags'] : [],
        'explanation' => $parsed['explanation'] ?? '',
        'action' => $parsed['action'] ?? 'allow',
        'signals' => $signals,
    ];

    response(true, $out);
} catch (Exception $e) {
    error_log('[fraud-explained] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
