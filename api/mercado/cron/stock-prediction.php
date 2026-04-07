<?php
/**
 * Cron: Stock prediction
 *
 * For each partner, looks at the last 30 days of sales velocity per product
 * and asks Llama to flag items that will run out in <7 days. Notifies partner.
 *
 * Schedule: 0 6 * * *  (6 AM daily)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

$db = getDB();

try {
    $db->exec("CREATE TABLE IF NOT EXISTS om_stock_predictions (
        id BIGSERIAL PRIMARY KEY,
        partner_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        avg_daily_sales DECIMAL(8,2),
        days_until_zero INTEGER,
        urgency VARCHAR(20),
        message TEXT,
        created_at TIMESTAMPTZ DEFAULT NOW()
    )");
} catch (Exception $e) {}

// Find products with stock and recent sales velocity
$sql = "
    SELECT p.product_id, p.partner_id, p.name, p.stock,
           COUNT(oi.id) AS sold_30d,
           COALESCE(SUM(oi.quantity), 0) AS qty_30d
    FROM om_market_products p
    LEFT JOIN om_market_order_items oi ON oi.product_id = p.product_id
    LEFT JOIN om_market_orders o ON o.order_id = oi.order_id
        AND o.created_at > NOW() - INTERVAL '30 days'
        AND o.status NOT IN ('cancelado','recusado')
    WHERE p.status::text = '1'
      AND COALESCE(p.stock, -1) >= 0
      AND COALESCE(p.stock, 0) > 0
    GROUP BY p.product_id, p.partner_id, p.name, p.stock
    HAVING COUNT(oi.id) >= 3
    ORDER BY p.partner_id LIMIT 200
";

try {
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "[stock-predict] schema lacks stock col, skipping\n");
    exit(0);
}

if (empty($rows)) {
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "[stock-predict] nothing to process\n");
    exit(0);
}

$ins = $db->prepare(
    "INSERT INTO om_stock_predictions (partner_id, product_id, avg_daily_sales, days_until_zero, urgency, message)
     VALUES (:pid, :prod, :avg, :days, :u, :m)"
);
$alerted = 0;

// Group by partner
$byPartner = [];
foreach ($rows as $r) $byPartner[$r['partner_id']][] = $r;

foreach ($byPartner as $partnerId => $products) {
    $atRisk = [];
    foreach ($products as $p) {
        $avgDaily = (float)$p['qty_30d'] / 30;
        if ($avgDaily < 0.5) continue;
        $daysLeft = $p['stock'] / $avgDaily;
        if ($daysLeft < 7) {
            $atRisk[] = [
                'product_id' => (int)$p['product_id'],
                'name' => $p['name'],
                'stock' => (int)$p['stock'],
                'avg_daily' => round($avgDaily, 1),
                'days_left' => round($daysLeft, 1),
            ];
        }
    }
    if (empty($atRisk)) continue;

    $prompt = "Produtos do parceiro {$partnerId} prestes a esgotar:\n" .
              json_encode($atRisk, JSON_UNESCAPED_UNICODE) . "\n\n" .
              "Gere uma mensagem CURTA pro parceiro alertando sobre reposicao. " .
              "Em pt-BR max 400 chars com emoji.";
    $msg = ClaudeClient::text($prompt, 'Voce eh o assistente de estoque do parceiro.', 400);
    if (!$msg) continue;

    foreach ($atRisk as $item) {
        $urgency = $item['days_left'] < 2 ? 'critical' : ($item['days_left'] < 4 ? 'high' : 'medium');
        $ins->execute([
            ':pid' => $partnerId,
            ':prod' => $item['product_id'],
            ':avg' => $item['avg_daily'],
            ':days' => (int)$item['days_left'],
            ':u' => $urgency,
            ':m' => $msg,
        ]);
        $alerted++;
    }
    usleep(150000);
}

if (PHP_SAPI === 'cli') fwrite(STDOUT, "[stock-predict] alerted on {$alerted} items\n");
