<?php
/**
 * GET /campaign/greeting.php?campaign_id=1
 * Returns a personalized greeting for the campaign detail screen.
 * Uses Claude AI to generate a warm, human message based on customer data.
 * Auth required.
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/claude-client.php";

setCorsHeaders();

try {
    $db = getDB();
    $customerId = requireCustomerAuth();
    $campaignId = (int)($_GET['campaign_id'] ?? 0);

    if (!$campaignId) {
        response(false, null, "campaign_id obrigatorio", 400);
    }

    // Get customer data
    $stmt = $db->prepare("
        SELECT name, gender, birth_date, created_at
        FROM om_customers WHERE customer_id = ?
    ");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        response(false, null, "Cliente nao encontrado", 404);
    }

    // Get campaign data
    $stmt = $db->prepare("SELECT name, reward_text, description FROM om_campaigns WHERE campaign_id = ?");
    $stmt->execute([$campaignId]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$campaign) {
        response(false, null, "Campanha nao encontrada", 404);
    }

    $firstName = explode(' ', trim($customer['name']))[0];
    $hour = (int)date('H');
    $gender = $customer['gender'] ?? '';
    $age = null;
    if (!empty($customer['birth_date'])) {
        $birth = new DateTime($customer['birth_date']);
        $now = new DateTime();
        $age = $now->diff($birth)->y;
    }

    // Calculate days since registration
    $daysSinceRegistration = null;
    if (!empty($customer['created_at'])) {
        $created = new DateTime($customer['created_at']);
        $now = new DateTime();
        $daysSinceRegistration = $now->diff($created)->days;
    }

    $timeOfDay = $hour < 12 ? 'manha' : ($hour < 18 ? 'tarde' : 'noite');

    $systemPrompt = "Voce e o assistente da SuperBora, um app de delivery de comida brasileiro. " .
        "Gere uma saudacao personalizada CURTA (2-3 frases no maximo) para a tela de detalhes de uma campanha promocional. " .
        "Seja caloroso, humano e acolhedor. Use o nome da pessoa. " .
        "Mencione a promocao de forma natural. " .
        "NAO use emojis. NAO use caracteres especiais ou acentos. " .
        "Responda APENAS com o texto da saudacao, sem aspas, sem explicacao.";

    $userMsg = "Dados do cliente:\n" .
        "- Nome: {$firstName}\n" .
        "- Horario: {$timeOfDay}\n" .
        ($gender ? "- Genero: {$gender}\n" : "") .
        ($age ? "- Idade: {$age} anos\n" : "") .
        ($daysSinceRegistration !== null ? "- Cliente ha {$daysSinceRegistration} dias\n" : "") .
        "\nCampanha: {$campaign['name']}\n" .
        "Premiacao: {$campaign['reward_text']}\n" .
        ($campaign['description'] ? "Descricao: {$campaign['description']}\n" : "") .
        "\nGere a saudacao personalizada agora:";

    $claude = new ClaudeClient(ClaudeClient::DEFAULT_MODEL, 15, 0);
    $result = $claude->send($systemPrompt, [
        ['role' => 'user', 'content' => $userMsg]
    ], 200);

    if ($result['success'] && !empty($result['text'])) {
        $greeting = trim($result['text']);
        // Remove quotes if Claude wrapped them
        $greeting = trim($greeting, '"\'');
        response(true, ['greeting' => $greeting]);
    } else {
        // Fallback: generate a simple greeting without AI
        $saudacao = $hour < 12 ? 'Bom dia' : ($hour < 18 ? 'Boa tarde' : 'Boa noite');
        $fallback = "{$saudacao}, {$firstName}! Obrigado por fazer parte da familia SuperBora. Preparamos algo especial pra voce.";
        response(true, ['greeting' => $fallback]);
    }

} catch (Exception $e) {
    error_log("[campaign/greeting] Erro: " . $e->getMessage());
    // Fallback on any error
    response(true, ['greeting' => 'Que bom ter voce aqui! A SuperBora preparou algo especial pra voce.']);
}
