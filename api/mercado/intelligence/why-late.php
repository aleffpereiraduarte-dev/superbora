<?php
/**
 * GET /api/mercado/intelligence/why-late.php?order_id=123
 * Generates an empathetic explanation when an order is late.
 *
 * Authenticated. Only the order owner can call.
 * Returns: { "message": "...", "tone": "apologetic", "eta_extra_min": 10 }
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

    $customerId = 0;
    $token = om_auth()->getTokenFromRequest();
    if ($token) {
        $payload = om_auth()->validateToken($token);
        if ($payload && ($payload['type'] ?? '') === 'customer') {
            $customerId = (int)$payload['uid'];
        }
    }
    if (!$customerId) response(false, null, 'Nao autorizado', 401);

    $orderId = (int)($_GET['order_id'] ?? 0);
    if (!$orderId) response(false, null, 'order_id obrigatorio', 400);

    $stmt = $db->prepare(
        "SELECT o.order_id, o.status, o.created_at, o.estimated_delivery_at,
                o.total, p.name AS partner_name, p.categoria
         FROM om_market_orders o
         LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
         WHERE o.order_id = :oid AND o.customer_id = :cid"
    );
    $stmt->execute([':oid' => $orderId, ':cid' => $customerId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        response(false, null, 'pedido nao encontrado', 404);
    }

    $now = time();
    $createdAt = strtotime($order['created_at']);
    $estimatedAt = $order['estimated_delivery_at'] ? strtotime($order['estimated_delivery_at']) : ($createdAt + 45 * 60);
    $minsElapsed = round(($now - $createdAt) / 60);
    $minsLate = max(0, round(($now - $estimatedAt) / 60));

    // Cache 5 min so we don't hammer the API on a polling screen
    $cacheFile = sys_get_temp_dir() . "/sb_whylate_{$orderId}_{$minsLate}.json";
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 300) {
        response(true, json_decode(file_get_contents($cacheFile), true));
    }

    $hour = (int)date('H');
    $rush = ($hour >= 11 && $hour <= 13) || ($hour >= 18 && $hour <= 20);

    $userPrompt = "Pedido #{$orderId} de {$order['partner_name']} ({$order['categoria']}). " .
                  "Status: {$order['status']}. " .
                  "Decorridos: {$minsElapsed} min. Atraso: {$minsLate} min. " .
                  ($rush ? "Horario de pico." : "Horario normal.") . "\n\n" .
                  "Gere uma mensagem curta (max 200 chars) empatica em pt-BR explicando o atraso. " .
                  "Tom: " . ($minsLate > 30 ? 'pedido de desculpas sincero' : 'tranquilizador') . ". " .
                  "NAO seja generico, mencione o contexto. Responda APENAS JSON: " .
                  '{"message":"...","tone":"apologetic|reassuring","eta_extra_min":<numero>}';

    $reply = ClaudeClient::text(
        $userPrompt,
        'Voce eh o assistente de suporte do SuperBora. Empatico, sincero, breve.',
        250
    );
    $parsed = ClaudeClient::parseJson($reply ?: '') ?: [];

    $out = [
        'order_id' => $orderId,
        'mins_late' => $minsLate,
        'message' => $parsed['message'] ?? 'Seu pedido esta a caminho. Pedimos desculpas pela demora.',
        'tone' => $parsed['tone'] ?? 'reassuring',
        'eta_extra_min' => isset($parsed['eta_extra_min']) ? (int)$parsed['eta_extra_min'] : 10,
    ];
    @file_put_contents($cacheFile, json_encode($out));
    response(true, $out);
} catch (Exception $e) {
    error_log('[why-late] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
