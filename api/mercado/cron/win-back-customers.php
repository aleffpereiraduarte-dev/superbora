<?php
/**
 * Cron: Win-back at-risk customers
 *
 * Identifies customers who:
 *   - Made >= 2 orders historically
 *   - Have not ordered in 14-30 days
 *   - Have not received a win-back push in last 7 days
 *
 * Generates a personalized push via Llama and sends it.
 *
 * Schedule: 0 11 * * *  (every day at 11 AM)
 * Run manually: php cron/win-back-customers.php
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

$db = getDB();

// Find at-risk customers (paginated)
$sql = "
    WITH last_orders AS (
        SELECT customer_id,
               MAX(created_at) AS last_at,
               COUNT(*) AS total_orders
        FROM om_market_orders
        WHERE status NOT IN ('cancelado','recusado')
        GROUP BY customer_id
    )
    SELECT c.customer_id, c.name, c.email,
           lo.last_at, lo.total_orders,
           EXTRACT(DAY FROM (NOW() - lo.last_at))::int AS days_since
    FROM om_customers c
    JOIN last_orders lo ON c.customer_id = lo.customer_id
    WHERE lo.total_orders >= 2
      AND lo.last_at < NOW() - INTERVAL '14 days'
      AND lo.last_at > NOW() - INTERVAL '30 days'
      AND NOT EXISTS (
          SELECT 1 FROM om_smart_push_log spl
          WHERE spl.customer_id = c.customer_id
            AND spl.push_type = 'winback'
            AND spl.sent_at > NOW() - INTERVAL '7 days'
      )
    LIMIT 50
";

try {
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    fwrite(STDERR, "[win-back] query failed: " . $e->getMessage() . "\n");
    exit(1);
}

if (empty($rows)) {
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "[win-back] no at-risk customers\n");
    exit(0);
}

$sent = 0;
$failed = 0;
foreach ($rows as $r) {
    // Find favorite category
    $stmt = $db->prepare(
        "SELECT p.categoria, COUNT(*) AS qty
         FROM om_market_orders o
         JOIN om_market_partners p ON o.partner_id = p.partner_id
         WHERE o.customer_id = :cid
         GROUP BY p.categoria ORDER BY qty DESC LIMIT 1"
    );
    $stmt->execute([':cid' => $r['customer_id']]);
    $fav = $stmt->fetch(PDO::FETCH_ASSOC);
    $favCategory = $fav['categoria'] ?? 'restaurante';

    $firstName = explode(' ', trim($r['name'] ?? 'amigo'))[0];

    $prompt = "Cliente {$firstName} nao pede ha {$r['days_since']} dias. Categoria favorita: {$favCategory}. " .
              "Total de pedidos historicos: {$r['total_orders']}. " .
              "Gere uma push notification curta de win-back em pt-BR. " .
              "Tom: amigavel, urgencia leve, oferta. Responda APENAS JSON: " .
              '{"title":"max 50 chars com emoji","body":"max 100 chars","cupom_sugerido":"FRETE_GRATIS|DESCONTO_10|CASHBACK_DUPLO"}';

    $reply = ClaudeClient::text(
        $prompt,
        'Voce eh o gerente de retencao do SuperBora. Crie pushes que convertem.',
        300
    );
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed || empty($parsed['title'])) {
        $failed++;
        continue;
    }

    // Log it (the push sender will pick it up via om_smart_push_log)
    try {
        $stmt = $db->prepare(
            "INSERT INTO om_smart_push_log (customer_id, push_type, title, body, sent_at, ai_generated)
             VALUES (:cid, 'winback', :t, :b, NOW(), true)"
        );
        $stmt->execute([
            ':cid' => $r['customer_id'],
            ':t' => mb_substr($parsed['title'], 0, 100),
            ':b' => mb_substr($parsed['body'] ?? '', 0, 200),
        ]);
        $sent++;
    } catch (Exception $e) {
        error_log("[win-back] insert failed: " . $e->getMessage());
        $failed++;
    }

    // Throttle the AI calls a bit
    usleep(200000); // 0.2s
}

if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, "[win-back] generated {$sent} pushes, {$failed} failed\n");
}
