<?php
/**
 * Cron: Loyalty milestones
 *
 * Detects customers who hit a milestone (10, 25, 50, 100, 250, 500 orders)
 * and queues a celebratory push.
 *
 * Schedule: 0 10 * * *  (10 AM daily)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

$db = getDB();
$milestones = [10, 25, 50, 100, 250, 500, 1000];

$rows = $db->query(
    "SELECT c.customer_id, c.name, COUNT(o.order_id) AS total
     FROM om_customers c
     JOIN om_market_orders o ON o.customer_id = c.customer_id
     WHERE o.status NOT IN ('cancelado','recusado')
     GROUP BY c.customer_id, c.name"
)->fetchAll(PDO::FETCH_ASSOC);

$logStmt = $db->prepare(
    "INSERT INTO om_smart_push_log (customer_id, push_type, title, body, sent_at, ai_generated)
     VALUES (:cid, 'milestone_:m', :t, :b, NOW(), true)"
);
$sent = 0;

foreach ($rows as $r) {
    $total = (int)$r['total'];
    if (!in_array($total, $milestones, true)) continue;

    // Did we already send for this milestone?
    $check = $db->prepare(
        "SELECT 1 FROM om_smart_push_log
         WHERE customer_id = :cid AND push_type = :t LIMIT 1"
    );
    $check->execute([':cid' => $r['customer_id'], ':t' => "milestone_{$total}"]);
    if ($check->fetch()) continue;

    $first = explode(' ', trim($r['name'] ?? 'amigo'))[0];
    $prompt = "Cliente {$first} acaba de fazer o pedido numero {$total}! Marco importante. " .
              "Gere uma mensagem de comemoracao. Responda APENAS JSON: " .
              '{"title":"celebrativo max 60 chars","body":"max 150 chars com emoji + premio especial"}';

    $reply = ClaudeClient::text($prompt, 'Voce eh o gerente de fidelidade. Festivo, generoso, autentico.', 250);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed || empty($parsed['title'])) continue;

    try {
        $stmt = $db->prepare(
            "INSERT INTO om_smart_push_log (customer_id, push_type, title, body, sent_at, ai_generated)
             VALUES (:cid, :pt, :t, :b, NOW(), true)"
        );
        $stmt->execute([
            ':cid' => $r['customer_id'],
            ':pt' => "milestone_{$total}",
            ':t' => mb_substr($parsed['title'], 0, 100),
            ':b' => mb_substr($parsed['body'] ?? '', 0, 250),
        ]);
        $sent++;
    } catch (Exception $e) {}
    usleep(150000);
}

if (PHP_SAPI === 'cli') fwrite(STDOUT, "[loyalty-milestones] sent {$sent} celebrations\n");
