<?php
/**
 * POST /api/mercado/admin/whatsapp-segmented.php
 * Body: { "segment":"vips|inactive|new|all", "context":"Promocao de pizza sextou" }
 *
 * Generates a personalized WhatsApp message per segment + previews count.
 * Auth: admin only.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
require_once dirname(__DIR__, 3) . '/includes/classes/OmAuth.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, 'Token ausente', 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || !in_array($payload['type'] ?? '', ['admin', 'staff'], true)) {
        response(false, null, 'Acesso restrito', 403);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $segment = trim($input['segment'] ?? 'all');
    $context = trim($input['context'] ?? '');
    if ($context === '') response(false, null, 'context obrigatorio', 400);

    $segmentDescriptions = [
        'vips' => 'clientes com 10+ pedidos no historico',
        'inactive' => 'clientes que nao pedem ha 30+ dias',
        'new' => 'clientes que se cadastraram nos ultimos 7 dias',
        'all' => 'todos os clientes ativos',
        'high_value' => 'clientes com ticket medio acima de R$80',
    ];

    if (!isset($segmentDescriptions[$segment])) response(false, null, 'segment invalido', 400);

    // Count audience
    $countSql = match ($segment) {
        'vips' => "SELECT COUNT(*) FROM (SELECT customer_id, COUNT(*) AS qty FROM om_market_orders WHERE status NOT IN ('cancelado','recusado') GROUP BY customer_id HAVING COUNT(*) >= 10) sub",
        'inactive' => "SELECT COUNT(DISTINCT customer_id) FROM om_market_orders WHERE customer_id NOT IN (SELECT customer_id FROM om_market_orders WHERE created_at > NOW() - INTERVAL '30 days')",
        'new' => "SELECT COUNT(*) FROM om_customers WHERE created_at > NOW() - INTERVAL '7 days'",
        'high_value' => "SELECT COUNT(*) FROM (SELECT customer_id, AVG(total) AS avg_t FROM om_market_orders WHERE status NOT IN ('cancelado','recusado') GROUP BY customer_id HAVING AVG(total) > 80) sub",
        default => "SELECT COUNT(*) FROM om_customers WHERE COALESCE(is_active,1) = 1",
    };

    try {
        $audienceCount = (int)$db->query($countSql)->fetchColumn();
    } catch (Exception $e) {
        $audienceCount = 0;
    }

    $prompt = "Segmento: {$segment} ({$segmentDescriptions[$segment]})\n" .
              "Contexto/oferta: {$context}\n" .
              "Audiencia: {$audienceCount} pessoas\n\n" .
              "Gere uma mensagem WhatsApp personalizada para este segmento. " .
              "Tom apropriado: " . match ($segment) {
                  'vips' => 'reconhecimento, exclusividade',
                  'inactive' => 'saudade leve, oferta de retorno',
                  'new' => 'boas vindas, primeira oferta',
                  'high_value' => 'sofisticado, premium',
                  default => 'direto e amigavel',
              } . ".\n\n" .
              "Responda APENAS JSON: " .
              '{"message":"max 600 chars com 1 emoji","cta":"acao max 30 chars","subject_line":"linha de assunto curta","best_send_time":"manha|tarde|noite"}';

    $reply = ClaudeClient::text($prompt, 'Voce eh especialista em marketing WhatsApp segmentado.', 700);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed) response(false, null, 'falha geracao', 502);

    response(true, [
        'segment' => $segment,
        'audience_count' => $audienceCount,
        'message' => $parsed['message'] ?? '',
        'cta' => $parsed['cta'] ?? '',
        'subject_line' => $parsed['subject_line'] ?? '',
        'best_send_time' => $parsed['best_send_time'] ?? 'tarde',
    ]);
} catch (Exception $e) {
    error_log('[whatsapp-segmented] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
