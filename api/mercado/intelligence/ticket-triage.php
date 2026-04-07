<?php
/**
 * POST /api/mercado/intelligence/ticket-triage.php
 * Body: { "subject":"...","body":"...","customer_id":123 }
 * Returns:
 *   { "category":"pedido_atrasado|cobranca|reembolso|tecnico|elogio|outros",
 *     "urgency":"low|medium|high|critical",
 *     "sentiment":"positive|neutral|negative",
 *     "summary":"...","suggested_reply":"...","needs_human": true/false }
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $subject = trim($input['subject'] ?? '');
    $body = trim($input['body'] ?? '');
    $customerId = (int)($input['customer_id'] ?? 0);

    if ($subject === '' && $body === '') {
        response(false, null, 'subject ou body obrigatorio', 400);
    }

    $prompt = "Ticket de suporte do SuperBora delivery:\n" .
              "Assunto: {$subject}\n" .
              "Mensagem: {$body}\n\n" .
              "Faca a triagem completa. Responda APENAS JSON: " .
              '{"category":"pedido_atrasado|cobranca|reembolso|tecnico|qualidade|elogio|outros",' .
              '"urgency":"low|medium|high|critical","sentiment":"positive|neutral|negative",' .
              '"summary":"resumo de 1 frase","suggested_reply":"resposta empatica em pt-BR max 400 chars",' .
              '"needs_human":true_se_complexo_ou_critico,"tags":["tag1","tag2"]}';

    $reply = ClaudeClient::text(
        $prompt,
        'Voce eh o agente de triagem de suporte do SuperBora. Empatico, profissional, eficiente.',
        700
    );
    if (!$reply) response(false, null, 'falha no triage', 502);

    $parsed = ClaudeClient::parseJson($reply);
    if (!$parsed) response(false, null, 'resposta invalida', 502);

    $out = [
        'category' => $parsed['category'] ?? 'outros',
        'urgency' => $parsed['urgency'] ?? 'medium',
        'sentiment' => $parsed['sentiment'] ?? 'neutral',
        'summary' => $parsed['summary'] ?? '',
        'suggested_reply' => $parsed['suggested_reply'] ?? '',
        'needs_human' => (bool)($parsed['needs_human'] ?? false),
        'tags' => is_array($parsed['tags'] ?? null) ? $parsed['tags'] : [],
    ];

    // Optionally persist
    if ($customerId) {
        try {
            $db = getDB();
            $db->exec("CREATE TABLE IF NOT EXISTS om_ticket_triage_log (
                id BIGSERIAL PRIMARY KEY,
                customer_id INTEGER,
                category VARCHAR(50),
                urgency VARCHAR(20),
                sentiment VARCHAR(20),
                needs_human BOOLEAN,
                summary TEXT,
                created_at TIMESTAMPTZ DEFAULT NOW()
            )");
            $db->prepare("INSERT INTO om_ticket_triage_log (customer_id, category, urgency, sentiment, needs_human, summary) VALUES (?, ?, ?, ?, ?, ?)")
               ->execute([$customerId, $out['category'], $out['urgency'], $out['sentiment'], $out['needs_human'] ? 't' : 'f', $out['summary']]);
        } catch (Exception $e) {}
    }

    response(true, $out);
} catch (Exception $e) {
    error_log('[ticket-triage] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
