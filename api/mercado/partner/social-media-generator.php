<?php
/**
 * GET /api/mercado/partner/social-media-generator.php?platform=instagram
 * Auth: partner
 *
 * Generates ready-to-post social media content based on the partner's
 * top products of the week. Free marketing for partners.
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

    $platform = $_GET['platform'] ?? 'instagram';
    if (!in_array($platform, ['instagram', 'facebook', 'tiktok', 'whatsapp_status', 'twitter'], true)) {
        response(false, null, 'platform invalido', 400);
    }

    // Fetch partner + top products
    $stmt = $db->prepare("SELECT name, categoria, city FROM om_market_partners WHERE partner_id = :pid");
    $stmt->execute([':pid' => $partnerId]);
    $partner = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$partner) response(false, null, 'parceiro nao encontrado', 404);

    $topStmt = $db->prepare(
        "SELECT pr.name, pr.price, COUNT(oi.id) AS sales
         FROM om_market_order_items oi
         JOIN om_market_orders o ON o.order_id = oi.order_id
         JOIN om_market_products pr ON pr.product_id = oi.product_id
         WHERE o.partner_id = :pid AND o.created_at > NOW() - INTERVAL '14 days'
           AND o.status NOT IN ('cancelado','recusado')
         GROUP BY pr.product_id, pr.name, pr.price
         ORDER BY sales DESC LIMIT 5"
    );
    $topStmt->execute([':pid' => $partnerId]);
    $top = $topStmt->fetchAll(PDO::FETCH_ASSOC);

    $topList = empty($top) ? '' : "\nTop produtos:\n" . implode("\n", array_map(fn($p) => "- {$p['name']} (R\${$p['price']})", $top));

    $platformGuide = [
        'instagram' => 'Caption para Instagram: 1 frase forte + 5 hashtags + emojis. Max 220 chars.',
        'facebook' => 'Post Facebook: paragrafo amigavel + call to action + 3 hashtags. Max 400 chars.',
        'tiktok' => 'Descricao TikTok: gancho + 8 hashtags virais + emoji. Max 150 chars.',
        'whatsapp_status' => 'Status WhatsApp: frase impactante + emoji. Max 100 chars.',
        'twitter' => 'Tweet: punchy + 2 hashtags. Max 280 chars.',
    ];

    $prompt = "Loja: {$partner['name']} ({$partner['categoria']}) em {$partner['city']}.{$topList}\n\n" .
              "Gere 3 variacoes de post para {$platform}. {$platformGuide[$platform]}\n" .
              "Responda APENAS JSON: " .
              '{"posts":[{"text":"...","best_time":"manha|tarde|noite","cta":"...","hashtags":["#tag1"]}]}';

    $reply = ClaudeClient::text($prompt, 'Voce eh social media manager especializado em food delivery.', 1200);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed || empty($parsed['posts'])) response(false, null, 'falha geracao', 502);

    response(true, [
        'partner_id' => $partnerId,
        'platform' => $platform,
        'posts' => $parsed['posts'],
    ]);
} catch (Exception $e) {
    error_log('[social-media-gen] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
