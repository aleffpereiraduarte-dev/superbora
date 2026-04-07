<?php
/**
 * POST /api/mercado/corporate/sales-script.php
 * Body: { "company_name":"...","industry":"...","employees":50,"city":"...","contact_role":"RH" }
 *
 * Generates a personalized B2B sales pitch script for SuperBora corporate accounts.
 * Used by the sales team via the admin panel.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $company = trim($input['company_name'] ?? '');
    $industry = trim($input['industry'] ?? '');
    $employees = (int)($input['employees'] ?? 0);
    $city = trim($input['city'] ?? '');
    $role = trim($input['contact_role'] ?? 'RH');

    if ($company === '') response(false, null, 'company_name obrigatorio', 400);

    $prompt = "Gere um script de venda B2B para o programa Corporativo SuperBora.\n\n" .
              "Empresa alvo: {$company}\n" .
              "Setor: " . ($industry ?: 'nao informado') . "\n" .
              "Funcionarios: " . ($employees ?: 'nao informado') . "\n" .
              "Cidade: " . ($city ?: 'nao informado') . "\n" .
              "Cargo do contato: {$role}\n\n" .
              "Programa Corporativo SuperBora oferece: vale-refeicao digital, controle de orcamento por departamento, " .
              "aprovacao hierarquica de pedidos, faturamento mensal consolidado com NF-e, app proprio para funcionarios.\n\n" .
              "Gere em pt-BR. Responda APENAS JSON: " .
              '{"opening":"primeira frase para quebrar o gelo","value_props":["beneficio1","beneficio2","beneficio3"],' .
              '"objections":[{"objection":"...","answer":"..."}],' .
              '"call_to_action":"acao especifica para fechar reuniao",' .
              '"email_subject":"max 60 chars","email_body":"max 800 chars"}';

    $reply = ClaudeClient::text(
        $prompt,
        'Voce eh diretor de vendas B2B com 15 anos de experiencia. Pratico e baseado em valor.',
        1500
    );
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed) response(false, null, 'falha geracao', 502);

    response(true, [
        'company' => $company,
        'opening' => $parsed['opening'] ?? '',
        'value_props' => is_array($parsed['value_props'] ?? null) ? $parsed['value_props'] : [],
        'objections' => is_array($parsed['objections'] ?? null) ? $parsed['objections'] : [],
        'call_to_action' => $parsed['call_to_action'] ?? '',
        'email_subject' => $parsed['email_subject'] ?? '',
        'email_body' => $parsed['email_body'] ?? '',
    ]);
} catch (Exception $e) {
    error_log('[sales-script] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
