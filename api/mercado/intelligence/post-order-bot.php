<?php
/**
 * POST /api/mercado/intelligence/post-order-bot.php
 * Body: { "order_id":123, "customer_message":"opcional" }
 *
 * 2 modes:
 *   - GET (no message): generates a check-in question to send to the customer
 *     after delivery ("Tudo certo com seu pedido?")
 *   - POST (with message): interprets the customer's reply and decides next action
 *     (close, escalate, refund, retry).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $db = getDB();

    if ($method === 'GET') {
        $orderId = (int)($_GET['order_id'] ?? 0);
        if (!$orderId) response(false, null, 'order_id obrigatorio', 400);

        $stmt = $db->prepare(
            "SELECT o.order_id, p.name AS partner_name, p.categoria
             FROM om_market_orders o
             LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
             WHERE o.order_id = :oid"
        );
        $stmt->execute([':oid' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) response(false, null, 'pedido nao encontrado', 404);

        $msg = ClaudeClient::text(
            "Pedido #{$orderId} de {$order['partner_name']} ({$order['categoria']}) acabou de ser entregue. " .
            "Gere uma pergunta CURTA (max 120 chars) em pt-BR, simpatica, perguntando se tudo deu certo. " .
            "Use 1 emoji. Pode mencionar o tipo de loja.",
            'Voce eh o assistente pos-venda do SuperBora.',
            150
        );
        response(true, ['order_id' => $orderId, 'message' => $msg ?: 'Tudo certo com seu pedido?']);
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $orderId = (int)($input['order_id'] ?? 0);
        $customerMsg = trim($input['customer_message'] ?? '');

        if (!$orderId || $customerMsg === '') {
            response(false, null, 'order_id e customer_message obrigatorios', 400);
        }

        $prompt = "Pedido #{$orderId}. Cliente respondeu apos entrega: \"{$customerMsg}\"\n\n" .
                  "Interprete a resposta e decida acao. Responda APENAS JSON: " .
                  '{"sentiment":"positive|neutral|negative","intent":"satisfeito|reclamacao|duvida|sem_relacao",' .
                  '"action":"close|reply|escalate|refund_partial|refund_full",' .
                  '"reply":"resposta empatica ao cliente em pt-BR max 200 chars",' .
                  '"summary":"resumo curto pra historico"}';

        $reply = ClaudeClient::text(
            $prompt,
            'Voce eh o agente pos-venda. Resolva no chat sempre que possivel.',
            500
        );
        $parsed = ClaudeClient::parseJson($reply ?: '');
        if (!$parsed) response(false, null, 'falha parsing', 502);

        $out = [
            'sentiment' => $parsed['sentiment'] ?? 'neutral',
            'intent' => $parsed['intent'] ?? 'sem_relacao',
            'action' => $parsed['action'] ?? 'close',
            'reply' => $parsed['reply'] ?? 'Obrigado pelo retorno!',
            'summary' => $parsed['summary'] ?? '',
        ];

        // Persist
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS om_post_order_log (
                id BIGSERIAL PRIMARY KEY,
                order_id INTEGER,
                customer_message TEXT,
                sentiment VARCHAR(20),
                intent VARCHAR(50),
                action VARCHAR(30),
                reply TEXT,
                created_at TIMESTAMPTZ DEFAULT NOW()
            )");
            $db->prepare("INSERT INTO om_post_order_log (order_id, customer_message, sentiment, intent, action, reply) VALUES (?, ?, ?, ?, ?, ?)")
               ->execute([$orderId, $customerMsg, $out['sentiment'], $out['intent'], $out['action'], $out['reply']]);
        } catch (Exception $e) {}

        response(true, $out);
    }

    response(false, null, 'Metodo nao permitido', 405);
} catch (Exception $e) {
    error_log('[post-order-bot] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
