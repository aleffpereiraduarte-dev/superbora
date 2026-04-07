<?php
/**
 * GET /api/mercado/intelligence/smart-receipt.php?order_id=123
 * Returns a personalized post-order "thank you" + cross-sell.
 *
 * Cached 24h per order.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $orderId = (int)($_GET['order_id'] ?? 0);
    if (!$orderId) response(false, null, 'order_id obrigatorio', 400);

    $cacheFile = sys_get_temp_dir() . "/sb_receipt_{$orderId}.json";
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        response(true, json_decode(file_get_contents($cacheFile), true));
    }

    $db = getDB();
    $stmt = $db->prepare(
        "SELECT o.order_id, o.total, o.created_at,
                p.name AS partner_name, p.categoria,
                c.name AS customer_name,
                (SELECT COUNT(*) FROM om_market_orders WHERE customer_id = o.customer_id) AS total_orders
         FROM om_market_orders o
         LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
         LEFT JOIN om_customers c ON o.customer_id = c.customer_id
         WHERE o.order_id = :oid"
    );
    $stmt->execute([':oid' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) response(false, null, 'pedido nao encontrado', 404);

    $firstName = explode(' ', trim($order['customer_name'] ?? 'cliente'))[0];

    $prompt = "Pedido #{$orderId} de {$order['partner_name']} ({$order['categoria']}). " .
              "Cliente: {$firstName}. Pedido numero {$order['total_orders']} dele(a). Total R\${$order['total']}. " .
              "Gere um recibo personalizado em pt-BR. Responda APENAS JSON: " .
              '{"thank_you":"agradecimento curto max 120 chars","fun_fact":"curiosidade do produto/loja max 100 chars",' .
              '"cross_sell":"sugestao do que pedir da proxima vez max 100 chars","loyalty_message":"se >5 pedidos, mensagem especial"}';

    $reply = ClaudeClient::text($prompt, 'Voce eh o gerente de relacionamento do SuperBora. Caloroso e breve.', 400);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed) response(false, null, 'falha geracao', 502);

    $out = [
        'order_id' => $orderId,
        'thank_you' => $parsed['thank_you'] ?? 'Obrigado pelo seu pedido!',
        'fun_fact' => $parsed['fun_fact'] ?? '',
        'cross_sell' => $parsed['cross_sell'] ?? '',
        'loyalty_message' => $parsed['loyalty_message'] ?? '',
    ];
    @file_put_contents($cacheFile, json_encode($out));
    response(true, $out);
} catch (Exception $e) {
    error_log('[smart-receipt] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
