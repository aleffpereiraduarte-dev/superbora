<?php
/**
 * Cron: Birthday auto-greetings
 *
 * Sends a personalized happy birthday push to customers whose birthday is today,
 * with a Llama-generated message and a special discount coupon.
 *
 * Schedule: 0 9 * * *  (9 AM daily)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

$db = getDB();

try {
    $stmt = $db->query(
        "SELECT customer_id, name, email
         FROM om_customers
         WHERE birth_date IS NOT NULL
           AND TO_CHAR(birth_date, 'MM-DD') = TO_CHAR(NOW(), 'MM-DD')
           AND NOT EXISTS (
               SELECT 1 FROM om_smart_push_log spl
               WHERE spl.customer_id = om_customers.customer_id
                 AND spl.push_type = 'birthday'
                 AND DATE(spl.sent_at) = CURRENT_DATE
           )
         LIMIT 200"
    );
    $birthdays = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    fwrite(STDERR, "[birthday] " . $e->getMessage() . "\n");
    exit(1);
}

if (empty($birthdays)) {
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "[birthday] no birthdays today\n");
    exit(0);
}

$logStmt = $db->prepare(
    "INSERT INTO om_smart_push_log (customer_id, push_type, title, body, sent_at, ai_generated)
     VALUES (:cid, 'birthday', :t, :b, NOW(), true)"
);
$sent = 0;

foreach ($birthdays as $c) {
    $first = explode(' ', trim($c['name'] ?? 'amigo'))[0];

    $prompt = "Cliente {$first} faz aniversario hoje! Gere uma mensagem de parabens. " .
              "Responda APENAS JSON: " .
              '{"title":"max 60 chars com emoji de bolo","body":"max 140 chars amigavel mencionando cupom especial"}';

    $reply = ClaudeClient::text($prompt, 'Voce eh o gerente de relacionamento. Caloroso, festivo, breve.', 250);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed || empty($parsed['title'])) continue;

    try {
        $logStmt->execute([
            ':cid' => $c['customer_id'],
            ':t' => mb_substr($parsed['title'], 0, 100),
            ':b' => mb_substr($parsed['body'] ?? '', 0, 250),
        ]);
        $sent++;
    } catch (Exception $e) {}
    usleep(150000);
}

if (PHP_SAPI === 'cli') fwrite(STDOUT, "[birthday] sent {$sent} birthday greetings\n");
