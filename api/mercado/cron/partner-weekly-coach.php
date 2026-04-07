<?php
/**
 * Cron: Partner Weekly Coach
 *
 * Every Monday at 9 AM, generates a personalized weekly performance report
 * for each active partner: vendas vs semana anterior, top produtos, problemas,
 * sugestoes acionaveis. Sends via WhatsApp.
 *
 * Schedule: 0 9 * * 1
 * Run manually: php cron/partner-weekly-coach.php [partner_id]
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

$db = getDB();

$singlePartner = isset($argv[1]) ? (int)$argv[1] : null;

$partnersSql = $singlePartner
    ? "SELECT partner_id, name, phone FROM om_market_partners WHERE partner_id = :pid AND status::text = '1'"
    : "SELECT partner_id, name, phone FROM om_market_partners WHERE status::text = '1' ORDER BY partner_id LIMIT 100";

$stmt = $db->prepare($partnersSql);
if ($singlePartner) $stmt->execute([':pid' => $singlePartner]);
else $stmt->execute();
$partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

$success = 0;
$failed = 0;

foreach ($partners as $p) {
    $pid = (int)$p['partner_id'];

    // This week vs last week (Mon-Sun)
    $thisWeek = $db->prepare(
        "SELECT COUNT(*) AS qty, COALESCE(SUM(total),0) AS gmv
         FROM om_market_orders
         WHERE partner_id = :pid
           AND created_at >= date_trunc('week', NOW() - INTERVAL '7 days')
           AND created_at < date_trunc('week', NOW())
           AND status NOT IN ('cancelado','recusado')"
    );
    $thisWeek->execute([':pid' => $pid]);
    $tw = $thisWeek->fetch(PDO::FETCH_ASSOC);

    $prevWeek = $db->prepare(
        "SELECT COUNT(*) AS qty, COALESCE(SUM(total),0) AS gmv
         FROM om_market_orders
         WHERE partner_id = :pid
           AND created_at >= date_trunc('week', NOW() - INTERVAL '14 days')
           AND created_at < date_trunc('week', NOW() - INTERVAL '7 days')
           AND status NOT IN ('cancelado','recusado')"
    );
    $prevWeek->execute([':pid' => $pid]);
    $pw = $prevWeek->fetch(PDO::FETCH_ASSOC);

    if ((int)$tw['qty'] === 0 && (int)$pw['qty'] === 0) {
        continue; // skip dormant partners
    }

    // Top 3 products this week
    $topProd = $db->prepare(
        "SELECT pr.name, COUNT(*) AS qty
         FROM om_market_order_items oi
         JOIN om_market_orders o ON o.order_id = oi.order_id
         JOIN om_market_products pr ON pr.product_id = oi.product_id
         WHERE o.partner_id = :pid
           AND o.created_at >= date_trunc('week', NOW() - INTERVAL '7 days')
           AND o.created_at < date_trunc('week', NOW())
         GROUP BY pr.product_id, pr.name
         ORDER BY qty DESC LIMIT 3"
    );
    $topProd->execute([':pid' => $pid]);
    $topProducts = $topProd->fetchAll(PDO::FETCH_ASSOC);

    // Cancellation count
    $cancStmt = $db->prepare(
        "SELECT COUNT(*) FROM om_market_orders
         WHERE partner_id = :pid
           AND status IN ('cancelado','recusado')
           AND created_at >= NOW() - INTERVAL '7 days'"
    );
    $cancStmt->execute([':pid' => $pid]);
    $cancellations = (int)$cancStmt->fetchColumn();

    $data = [
        'partner_name' => $p['name'],
        'week_orders' => (int)$tw['qty'],
        'week_gmv' => round((float)$tw['gmv'], 2),
        'prev_orders' => (int)$pw['qty'],
        'prev_gmv' => round((float)$pw['gmv'], 2),
        'cancellations' => $cancellations,
        'top_products' => $topProducts,
    ];

    $prompt = "Voce eh o coach virtual de um restaurante/loja parceira do SuperBora. " .
              "Analise os dados da ultima semana e escreva uma mensagem CURTA (max 700 chars) " .
              "em pt-BR formato WhatsApp com emojis. Estrutura:\n" .
              "1. Saudacao com o nome\n2. Numero principal da semana\n3. Comparativo c/ semana anterior\n" .
              "4. UMA acao recomendada concreta\n5. Frase motivacional\n\n" .
              "Dados:\n" . json_encode($data, JSON_UNESCAPED_UNICODE);

    $message = ClaudeClient::text(
        $prompt,
        'Voce eh um coach de negocios pratico e direto. Sem firula.',
        700
    );

    if (!$message) {
        $failed++;
        continue;
    }

    // Persist + send
    try {
        $db->prepare(
            "INSERT INTO om_partner_coaching (partner_id, week_start, message, data_json, sent_at)
             VALUES (:pid, date_trunc('week', NOW() - INTERVAL '7 days')::date, :m, :d, NOW())"
        )->execute([':pid' => $pid, ':m' => $message, ':d' => json_encode($data)]);
    } catch (Exception $e) {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS om_partner_coaching (
                id BIGSERIAL PRIMARY KEY,
                partner_id INTEGER NOT NULL,
                week_start DATE NOT NULL,
                message TEXT,
                data_json JSONB,
                sent_at TIMESTAMPTZ DEFAULT NOW(),
                UNIQUE(partner_id, week_start)
            )");
            $db->prepare("INSERT INTO om_partner_coaching (partner_id, week_start, message, data_json) VALUES (:pid, date_trunc('week', NOW() - INTERVAL '7 days')::date, :m, :d) ON CONFLICT DO NOTHING")
               ->execute([':pid' => $pid, ':m' => $message, ':d' => json_encode($data)]);
        } catch (Exception $e2) { /* ignore */ }
    }

    if ($p['phone'] && file_exists(__DIR__ . '/../helpers/zapi-whatsapp.php')) {
        require_once __DIR__ . '/../helpers/zapi-whatsapp.php';
        if (function_exists('sendWhatsappMessage')) {
            try { sendWhatsappMessage($p['phone'], $message); } catch (Exception $e) { /* swallow */ }
        }
    }

    $success++;
    usleep(300000);
}

if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, "[partner-coach] sent {$success}, failed {$failed}\n");
}
