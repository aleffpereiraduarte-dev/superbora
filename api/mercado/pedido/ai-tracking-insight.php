<?php
/**
 * GET /api/mercado/pedido/ai-tracking-insight.php?order_id=X
 * Returns AI-powered contextual insight about the order tracking status.
 * Uses ClaudeClient to generate personalized, friendly messages in PT-BR.
 * Cached 60s per order_id:status combination.
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";
require_once __DIR__ . "/../helpers/claude-client.php";
setCorsHeaders();

try {
    $db = getDB();
    $customer_id = requireCustomerAuth();

    $order_id = (int)($_GET['order_id'] ?? 0);
    if (!$order_id) {
        response(false, null, "order_id obrigatorio", 400);
    }

    // Fetch order with partner info
    $stmt = $db->prepare("
        SELECT o.order_id, o.status, o.delivery_type, o.total, o.date_added,
               o.driver_name, o.driver_phone,
               p.name AS partner_name, p.categoria AS partner_category
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.order_id = ? AND o.customer_id = ?
    ");
    $stmt->execute([$order_id, $customer_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        response(false, null, "Pedido nao encontrado", 404);
    }

    $status = $order['status'];

    // Cache by order_id + status (insight changes when status changes)
    $cacheKey = "ai_tracking_{$order_id}_{$status}";
    $cached = CacheHelper::get($cacheKey);
    if ($cached) {
        response(true, $cached);
    }

    // Fetch items for context
    $stmtItems = $db->prepare("
        SELECT p.name FROM om_market_order_items i
        INNER JOIN om_market_products p ON i.product_id = p.product_id
        WHERE i.order_id = ? LIMIT 5
    ");
    $stmtItems->execute([$order_id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_COLUMN);

    // Build context for Claude
    $minutesAgo = round((time() - strtotime($order['date_added'])) / 60);
    $itemList = implode(', ', $items);
    $driverInfo = $order['driver_name'] ? "Motorista: {$order['driver_name']}" : "Sem motorista atribuido";

    $statusLabels = [
        'pendente' => 'Aguardando confirmacao da loja',
        'confirmado' => 'Pedido confirmado pela loja',
        'aceito' => 'Pedido aceito, aguardando preparo',
        'preparando' => 'Pedido sendo preparado',
        'pronto' => 'Pedido pronto para coleta',
        'em_entrega' => 'Motorista a caminho da loja',
        'out_for_delivery' => 'Pedido saiu para entrega',
        'entregue' => 'Pedido entregue',
        'cancelado' => 'Pedido cancelado',
    ];
    $statusLabel = $statusLabels[$status] ?? $status;

    $systemPrompt = <<<PROMPT
Voce e o assistente de entregas SuperBora. Gere uma mensagem curta, amigavel e contextual (max 2 frases) sobre o status atual do pedido.
Use o nome da loja e do motorista quando disponivel. Seja positivo e informativo.
Inclua sugestoes uteis quando relevante (max 2 sugestoes curtas).

IMPORTANTE: Retorne APENAS um JSON valido, sem markdown, sem explicacoes:
{"message": "sua mensagem aqui", "emoji": "emoji_aqui", "suggestions": ["sugestao1", "sugestao2"], "context": "contexto_curto"}
PROMPT;

    $userMessage = <<<MSG
Pedido #{$order_id} na loja "{$order['partner_name']}"
Status: {$statusLabel}
Itens: {$itemList}
{$driverInfo}
Tempo desde o pedido: {$minutesAgo} minutos
Tipo de entrega: {$order['delivery_type']}
Total: R$ {$order['total']}
MSG;

    $claude = new ClaudeClient(ClaudeClient::DEFAULT_MODEL, 15, 0); // 15s timeout, no retries
    $result = $claude->send($systemPrompt, [
        ['role' => 'user', 'content' => $userMessage],
    ], 256);

    if (!$result['success']) {
        // AI not available — return static fallback
        $fallback = getStaticInsight($status, $order['partner_name'], $order['driver_name']);
        CacheHelper::set($cacheKey, $fallback, 60);
        response(true, $fallback);
    }

    $parsed = ClaudeClient::parseJson($result['text']);
    if (!$parsed || empty($parsed['message'])) {
        $fallback = getStaticInsight($status, $order['partner_name'], $order['driver_name']);
        CacheHelper::set($cacheKey, $fallback, 60);
        response(true, $fallback);
    }

    $data = [
        'insight_message' => $parsed['message'],
        'mood_emoji' => $parsed['emoji'] ?? getStatusEmoji($status),
        'suggestions' => array_slice($parsed['suggestions'] ?? [], 0, 2),
        'partner_context' => $parsed['context'] ?? null,
        'source' => 'ai',
    ];

    CacheHelper::set($cacheKey, $data, 60);
    response(true, $data);

} catch (Exception $e) {
    error_log("[ai-tracking-insight] Erro: " . $e->getMessage());
    response(false, null, "Erro ao gerar insight", 500);
}

function getStaticInsight(string $status, ?string $partnerName, ?string $driverName): array {
    $store = $partnerName ?: 'a loja';
    $driver = $driverName ?: 'O entregador';

    $messages = [
        'pendente' => ["Seu pedido foi enviado para {$store}. Aguardando confirmacao!", getStatusEmoji($status)],
        'confirmado' => ["{$store} confirmou seu pedido! Em breve comeca o preparo.", getStatusEmoji($status)],
        'aceito' => ["{$store} aceitou seu pedido e ja vai comecar a preparar!", getStatusEmoji($status)],
        'preparando' => ["{$store} esta preparando seu pedido com carinho!", getStatusEmoji($status)],
        'pronto' => ["Seu pedido esta pronto! Aguardando motorista para coleta.", getStatusEmoji($status)],
        'em_entrega' => ["{$driver} esta a caminho da loja para coletar seu pedido!", getStatusEmoji($status)],
        'out_for_delivery' => ["{$driver} ja pegou seu pedido e esta a caminho!", getStatusEmoji($status)],
        'entregue' => ["Pedido entregue! Esperamos que aproveite. Obrigado por usar SuperBora!", getStatusEmoji($status)],
        'cancelado' => ["Este pedido foi cancelado.", getStatusEmoji($status)],
    ];

    $msg = $messages[$status] ?? ["Acompanhe seu pedido aqui!", "📦"];

    return [
        'insight_message' => $msg[0],
        'mood_emoji' => $msg[1],
        'suggestions' => [],
        'partner_context' => null,
        'source' => 'static',
    ];
}

function getStatusEmoji(string $status): string {
    $emojis = [
        'pendente' => '⏳',
        'confirmado' => '✅',
        'aceito' => '👍',
        'preparando' => '👨‍🍳',
        'pronto' => '📦',
        'em_entrega' => '🏍️',
        'out_for_delivery' => '🚀',
        'entregue' => '🎉',
        'cancelado' => '❌',
    ];
    return $emojis[$status] ?? '📋';
}
