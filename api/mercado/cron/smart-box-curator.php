<?php
/**
 * Cron: Smart subscription box curator
 *
 * For each active box subscription, picks the items that will go in the next
 * delivery based on customer preferences and the box base sample. Stored in
 * om_subscription_deliveries.items as a JSON array of product picks.
 *
 * Schedule: 0 5 * * *  (every day at 5 AM, picks for deliveries within next 24h)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

$db = getDB();

$stmt = $db->query(
    "SELECT s.id AS sub_id, s.customer_id, s.box_id, s.next_delivery,
            b.name AS box_name, b.category, b.base_price, b.sample_items,
            c.name AS customer_name
     FROM om_box_subscriptions s
     JOIN om_subscription_boxes b ON s.box_id = b.id
     LEFT JOIN om_customers c ON s.customer_id = c.customer_id
     WHERE s.status = 'active'
       AND s.next_delivery BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '1 day'"
);
$subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($subs)) {
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "[smart-box] no upcoming deliveries\n");
    exit(0);
}

$processed = 0;

foreach ($subs as $s) {
    // Avoid re-curating an already-curated delivery
    $check = $db->prepare(
        "SELECT id FROM om_subscription_deliveries
         WHERE subscription_id = :sid AND scheduled_date = :d AND status = 'scheduled'"
    );
    $check->execute([':sid' => $s['sub_id'], ':d' => $s['next_delivery']]);
    if ($check->fetch()) continue;

    // Get customer's order history with this box's category to inform preferences
    $favs = $db->prepare(
        "SELECT pr.name, COUNT(*) AS qty
         FROM om_market_order_items oi
         JOIN om_market_orders o ON o.order_id = oi.order_id
         JOIN om_market_products pr ON pr.product_id = oi.product_id
         WHERE o.customer_id = :cid
         GROUP BY pr.name ORDER BY qty DESC LIMIT 10"
    );
    $favs->execute([':cid' => $s['customer_id']]);
    $favorites = array_column($favs->fetchAll(PDO::FETCH_ASSOC), 'name');

    $base = is_string($s['sample_items']) ? json_decode($s['sample_items'], true) : $s['sample_items'];
    if (!is_array($base)) $base = [];

    $firstName = explode(' ', trim($s['customer_name'] ?? 'cliente'))[0];

    $prompt = "Cesta {$s['box_name']} (categoria: {$s['category']}) para {$firstName}. " .
              "Itens base: " . implode(', ', $base) . ". " .
              "Historico de itens favoritos do cliente: " . (implode(', ', $favorites) ?: 'sem historico') . ". " .
              "Personalize a cesta dessa semana mantendo o nucleo + 2 surpresas baseadas no perfil. " .
              "Responda APENAS JSON: " .
              '{"items":[{"name":"...","reason":"breve motivo"}],"theme":"tema da semana","note":"recado curto pro cliente"}';

    $reply = ClaudeClient::text(
        $prompt,
        'Voce eh o curador de cestas semanais. Personalize com criatividade mas mantenha valor.',
        500
    );
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed || empty($parsed['items'])) continue;

    try {
        $db->prepare(
            "INSERT INTO om_subscription_deliveries (subscription_id, items, scheduled_date, status, created_at)
             VALUES (:sid, :items, :d, 'scheduled', NOW())"
        )->execute([
            ':sid' => $s['sub_id'],
            ':items' => json_encode($parsed),
            ':d' => $s['next_delivery'],
        ]);
        $processed++;
    } catch (Exception $e) {
        error_log('[smart-box] ' . $e->getMessage());
    }
}

if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, "[smart-box] curated {$processed} deliveries\n");
}
