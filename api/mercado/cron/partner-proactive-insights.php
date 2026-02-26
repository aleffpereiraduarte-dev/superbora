<?php
/**
 * Partner Proactive Insights — Cron Monday 10:00 AM
 * Claude AI analyzes partner metrics and generates actionable suggestions
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
require_once __DIR__ . '/../helpers/zapi-whatsapp.php';

// Cron auth guard
$cronKey = $_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
$expectedKey = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: '';
if (empty($expectedKey) || !hash_equals($expectedKey, $cronKey)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = getDB();
$processed = 0;

$partners = $db->query("
    SELECT p.partner_id, COALESCE(p.trade_name, p.name) as business_name, p.phone
    FROM om_market_partners p
    WHERE p.status::text = '1'
    ORDER BY p.partner_id
");

$claude = new ClaudeClient();

while ($partner = $partners->fetch(PDO::FETCH_ASSOC)) {
    $pid = $partner['partner_id'];

    // Gather metrics for last 30 days
    $metrics = $db->prepare("
        SELECT COUNT(*) as total_orders, COALESCE(SUM(total), 0) as total_revenue,
               COALESCE(AVG(total), 0) as avg_ticket,
               COUNT(CASE WHEN status = 'cancelado' THEN 1 END) as cancelled,
               COUNT(DISTINCT DATE(created_at)) as active_days
        FROM om_market_orders
        WHERE partner_id = ? AND created_at > NOW() - INTERVAL '30 days'
    ");
    $metrics->execute([$pid]);
    $m = $metrics->fetch(PDO::FETCH_ASSOC);

    if ($m['total_orders'] < 5) continue; // Skip low-activity

    // Top products
    $topProds = $db->prepare("
        SELECT pb.name, COUNT(*) as qty
        FROM om_market_order_items oi
        JOIN om_market_products_base pb ON pb.product_id = oi.product_id
        JOIN om_market_orders o ON o.order_id = oi.order_id
        WHERE o.partner_id = ? AND o.created_at > NOW() - INTERVAL '30 days' AND o.status = 'entregue'
        GROUP BY pb.name ORDER BY qty DESC LIMIT 10
    ");
    $topProds->execute([$pid]);
    $topProducts = $topProds->fetchAll(PDO::FETCH_COLUMN);

    // Rating
    $rating = $db->prepare("SELECT COALESCE(AVG(rating), 0) as avg_rating, COUNT(*) as total_reviews FROM om_market_reviews WHERE partner_id = ? AND created_at > NOW() - INTERVAL '30 days'");
    $rating->execute([$pid]);
    $r = $rating->fetch(PDO::FETCH_ASSOC);

    $systemPrompt = "Voce e um consultor de negocios para restaurantes. Analise os dados e gere 3-5 sugestoes acionaveis.
Retorne JSON: {\"suggestions\": [{\"title\": \"...\", \"description\": \"...\", \"priority\": \"alta|media|baixa\", \"category\": \"vendas|menu|operacao|marketing\"}], \"headline\": \"...\"}
A headline deve ser a insight mais importante em 1 frase.";

    $dataMsg = "Restaurante: {$partner['business_name']}
Ultimos 30 dias:
- Pedidos: {$m['total_orders']} ({$m['active_days']} dias ativos)
- Receita: R$ " . number_format($m['total_revenue'], 2) . "
- Ticket medio: R$ " . number_format($m['avg_ticket'], 2) . "
- Cancelamentos: {$m['cancelled']}
- Avaliacao: " . number_format($r['avg_rating'], 1) . " ({$r['total_reviews']} reviews)
Top produtos: " . json_encode($topProducts);

    $result = $claude->send($systemPrompt, [['role' => 'user', 'content' => $dataMsg]], 1024);
    if (!$result['success']) continue;

    $parsed = ClaudeClient::parseJson($result['text']);
    if (!$parsed) continue;

    $suggestions = $parsed['suggestions'] ?? [];
    $headline = $parsed['headline'] ?? '';

    foreach ($suggestions as $s) {
        $db->prepare("INSERT INTO om_market_ai_alerts (partner_id, alert_type, title, message, severity, category, details, created_at) VALUES (?, 'insight', ?, ?, ?, ?, ?, NOW())")
           ->execute([$pid, $s['title'] ?? '', $s['description'] ?? '', $s['priority'] === 'alta' ? 'high' : 'medium', $s['category'] ?? 'vendas', json_encode($s)]);
    }

    // Send top insight via WhatsApp
    if (!empty($partner['phone']) && $headline) {
        try {
            $msg = "Insight semanal — {$partner['business_name']}:\n{$headline}";
            if (!empty($suggestions[0])) {
                $msg .= "\n\nDica: {$suggestions[0]['description']}";
            }
            sendWhatsApp($partner['phone'], $msg);
        } catch (Exception $e) { error_log("Insight WA error: " . $e->getMessage()); }
    }

    $processed++;
}

echo date('Y-m-d H:i:s') . " — Proactive insights: {$processed} partners analyzed\n";
