<?php
/**
 * Cron: Auto-FAQ generator
 *
 * Reads support tickets from the last 30 days, clusters similar questions,
 * and generates a FAQ in pt-BR. Stored in om_faq_auto for the help center to consume.
 *
 * Schedule: 0 4 * * 1  (every Monday at 4 AM)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

$db = getDB();

// Pull tickets — table name varies, try a few
$tickets = [];
foreach (['om_support_tickets', 'om_market_support_tickets', 'om_tickets'] as $tbl) {
    try {
        $stmt = $db->prepare(
            "SELECT subject, body FROM {$tbl}
             WHERE created_at > NOW() - INTERVAL '30 days' AND COALESCE(body,'') <> ''
             ORDER BY created_at DESC LIMIT 200"
        );
        $stmt->execute();
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($tickets)) break;
    } catch (Exception $e) { /* try next */ }
}

if (empty($tickets)) {
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "[auto-faq] no tickets found\n");
    exit(0);
}

// Truncate each to 200 chars to keep prompt small
$texts = array_map(function($t) {
    $line = trim(($t['subject'] ?? '') . ': ' . ($t['body'] ?? ''));
    return mb_substr($line, 0, 200);
}, $tickets);

$prompt = "Aqui estao tickets de suporte do SuperBora dos ultimos 30 dias:\n\n" .
          implode("\n", $texts) . "\n\n" .
          "TAREFA: Identifique as 10 duvidas mais comuns. Para CADA uma:\n" .
          "1. REESCREVA a pergunta em primeira pessoa, no jeito natural que um cliente perguntaria " .
          "(NAO copie o texto cru do ticket). Maximo 100 caracteres.\n" .
          "2. Escreva uma resposta clara, util e curta em pt-BR (max 250 chars).\n" .
          "3. Atribua categoria: pedido, pagamento, entrega, conta ou outros.\n" .
          "4. Estime frequencia (1-10) baseada em quantos tickets parecidos viu.\n\n" .
          "Responda APENAS JSON valido: " .
          '{"faqs":[{"question":"...","answer":"...","frequency":<int>,"category":"..."}]}';

// Fast tier with JSON-safe fallback to 70B
$parsed = ClaudeClient::sendFastJson(
    $prompt,
    'Voce eh especialista em customer success. Reescreva perguntas no jeito natural do cliente, NAO copie tickets.',
    2500
);

if (!$parsed || empty($parsed['faqs'])) {
    fwrite(STDERR, "[auto-faq] AI did not return valid JSON\n");
    exit(1);
}

// Persist
try {
    $db->exec("CREATE TABLE IF NOT EXISTS om_faq_auto (
        id BIGSERIAL PRIMARY KEY,
        question TEXT NOT NULL,
        answer TEXT NOT NULL,
        category VARCHAR(30),
        frequency INTEGER,
        generated_at TIMESTAMPTZ DEFAULT NOW(),
        published BOOLEAN DEFAULT false
    )");
    // Replace previous batch
    $db->exec("UPDATE om_faq_auto SET published = false WHERE published = true");
    $stmt = $db->prepare("INSERT INTO om_faq_auto (question, answer, category, frequency, published) VALUES (:q, :a, :c, :f, true)");
    $count = 0;
    foreach (array_slice($parsed['faqs'], 0, 10) as $faq) {
        if (empty($faq['question']) || empty($faq['answer'])) continue;
        $stmt->execute([
            ':q' => $faq['question'],
            ':a' => $faq['answer'],
            ':c' => $faq['category'] ?? 'outros',
            ':f' => (int)($faq['frequency'] ?? 1),
        ]);
        $count++;
    }
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "[auto-faq] generated {$count} FAQs\n");
} catch (Exception $e) {
    fwrite(STDERR, "[auto-faq] DB error: " . $e->getMessage() . "\n");
    exit(1);
}
