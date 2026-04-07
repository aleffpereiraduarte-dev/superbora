<?php
/**
 * POST /api/mercado/partner/onboarding-chat.php
 * Body: { "step":"welcome|menu|hours|delivery|done", "partner_id":123, "message":"" }
 *
 * Conversational onboarding for new partners. Tracks the step they're on
 * and generates the next message + question. The frontend renders this as
 * a chat bubble interface.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $step = $input['step'] ?? 'welcome';
    $partnerId = (int)($input['partner_id'] ?? 0);
    $message = trim($input['message'] ?? '');

    if (!$partnerId) response(false, null, 'partner_id obrigatorio', 400);

    $db = getDB();
    $stmt = $db->prepare("SELECT name, categoria, address_city FROM om_market_partners WHERE partner_id = :pid");
    $stmt->execute([':pid' => $partnerId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) response(false, null, 'parceiro nao encontrado', 404);

    $stepDescriptions = [
        'welcome' => 'Boas vindas ao SuperBora. Pergunte se quer comecar o setup.',
        'menu' => 'Pergunte como quer adicionar o cardapio: foto, manual ou importar do iFood.',
        'hours' => 'Pergunte os horarios de funcionamento.',
        'delivery' => 'Pergunte o raio de entrega e taxa minima.',
        'photos' => 'Pergunte se ja tem fotos dos pratos ou se quer ajuda IA.',
        'pricing' => 'Pergunte se aceita comissao padrao ou quer negociar.',
        'launch' => 'Felicite e diga que esta pronto pra primeira venda.',
    ];

    $stepInstr = $stepDescriptions[$step] ?? $stepDescriptions['welcome'];

    $prompt = "Voce eh o assistente de onboarding do SuperBora. " .
              "Parceiro: {$p['name']} ({$p['categoria']}) em {$p['address_city']}.\n" .
              "Passo atual: {$step}\n" .
              "Instrucao: {$stepInstr}\n" .
              "Mensagem do parceiro: " . ($message ?: '(inicio)') . "\n\n" .
              "Em pt-BR, responda APENAS JSON: " .
              '{"reply":"mensagem amigavel max 400 chars","next_step":"...","quick_replies":["opcao1","opcao2","opcao3"],"completed":false}';

    $reply = ClaudeClient::text(
        $prompt,
        'Voce eh o coach de onboarding. Acolhedor, paciente, vai por etapas curtas.',
        500
    );
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed) response(false, null, 'falha resposta', 502);

    response(true, [
        'step' => $step,
        'reply' => $parsed['reply'] ?? '',
        'next_step' => $parsed['next_step'] ?? $step,
        'quick_replies' => is_array($parsed['quick_replies'] ?? null) ? $parsed['quick_replies'] : [],
        'completed' => (bool)($parsed['completed'] ?? false),
    ]);
} catch (Exception $e) {
    error_log('[onboarding-chat] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
