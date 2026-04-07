<?php
/**
 * Cron: Generate combo suggestions for each partner from co-purchase data.
 *
 * Looks at order items in the last 90 days, finds items frequently bought
 * together, and asks Llama to name + describe the combo.
 *
 * Schedule: 0 9 * * 2  (Tuesdays 9 AM)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

$db = getDB();

try {
    $db->exec("CREATE TABLE IF NOT EXISTS om_combo_suggestions (
        id BIGSERIAL PRIMARY KEY,
        partner_id INTEGER NOT NULL,
        product_ids INTEGER[] NOT NULL,
        combo_name VARCHAR(200),
        description TEXT,
        suggested_price DECIMAL(10,2),
        suggested_discount_pct INTEGER,
        confidence INTEGER,
        co_purchase_count INTEGER,
        created_at TIMESTAMPTZ DEFAULT NOW()
    )");
} catch (Exception $e) {}

// Top partners by recent activity
$partners = $db->query(
    "SELECT partner_id FROM om_market_partners WHERE status::text = '1' ORDER BY partner_id LIMIT 30"
)->fetchAll(PDO::FETCH_COLUMN);

$ins = $db->prepare(
    "INSERT INTO om_combo_suggestions (partner_id, product_ids, combo_name, description, suggested_price, suggested_discount_pct, confidence, co_purchase_count)
     VALUES (:pid, :ids::int[], :n, :d, :sp, :sd, :c, :cp)"
);
$totalCombos = 0;

foreach ($partners as $pid) {
    // Find top co-purchase pairs for this partner
    $sql = "
        SELECT a.product_id AS pid_a, b.product_id AS pid_b,
               pa.name AS name_a, pb.name AS name_b,
               pa.price AS price_a, pb.price AS price_b,
               COUNT(*) AS co_count
        FROM om_market_order_items a
        JOIN om_market_order_items b ON a.order_id = b.order_id AND a.product_id < b.product_id
        JOIN om_market_orders o ON o.order_id = a.order_id
        JOIN om_market_products pa ON pa.product_id = a.product_id
        JOIN om_market_products pb ON pb.product_id = b.product_id
        WHERE o.partner_id = :pid
          AND o.created_at > NOW() - INTERVAL '90 days'
          AND o.status NOT IN ('cancelado','recusado')
        GROUP BY a.product_id, b.product_id, pa.name, pb.name, pa.price, pb.price
        HAVING COUNT(*) >= 3
        ORDER BY co_count DESC LIMIT 5
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([':pid' => $pid]);
    $pairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pairs as $pair) {
        $sumPrice = (float)$pair['price_a'] + (float)$pair['price_b'];

        $prompt = "Combo: {$pair['name_a']} (R\${$pair['price_a']}) + {$pair['name_b']} (R\${$pair['price_b']}) — vendidos juntos {$pair['co_count']}x. " .
                  "Crie um combo. Responda APENAS JSON: " .
                  '{"name":"nome chamativo","description":"breve descricao","suggested_discount_pct":5-15,"confidence":0-100}';

        $reply = ClaudeClient::text($prompt, 'Voce eh especialista em combos de delivery. JSON apenas.', 250);
        $parsed = ClaudeClient::parseJson($reply ?: '');
        if (!$parsed || empty($parsed['name'])) continue;

        $discount = max(0, min(30, (int)($parsed['suggested_discount_pct'] ?? 10)));
        $price = round($sumPrice * (1 - $discount / 100), 2);

        try {
            $ins->execute([
                ':pid' => $pid,
                ':ids' => '{' . $pair['pid_a'] . ',' . $pair['pid_b'] . '}',
                ':n' => mb_substr($parsed['name'], 0, 200),
                ':d' => $parsed['description'] ?? '',
                ':sp' => $price,
                ':sd' => $discount,
                ':c' => (int)($parsed['confidence'] ?? 80),
                ':cp' => (int)$pair['co_count'],
            ]);
            $totalCombos++;
        } catch (Exception $e) {}
        usleep(150000);
    }
}

if (PHP_SAPI === 'cli') fwrite(STDOUT, "[bulk-combo] generated {$totalCombos} combos across " . count($partners) . " partners\n");
