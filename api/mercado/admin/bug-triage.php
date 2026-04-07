<?php
/**
 * POST /api/mercado/admin/bug-triage.php
 * Body: { "title":"...","body":"..." }
 *
 * Categorizes a bug/issue and suggests priority + which area/team owns it.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
require_once dirname(__DIR__, 3) . '/includes/classes/OmAuth.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, 'Token ausente', 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || !in_array($payload['type'] ?? '', ['admin', 'staff'], true)) {
        response(false, null, 'Acesso restrito', 403);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $title = trim($input['title'] ?? '');
    $body = trim($input['body'] ?? '');
    if ($title === '' && $body === '') response(false, null, 'title ou body obrigatorio', 400);

    $prompt = "Issue do SuperBora:\nTitulo: {$title}\nDescricao: {$body}\n\n" .
              "Faca triagem. Areas: mobile_customer, mobile_partner, backend_php, backend_go, backend_rust, " .
              "backend_cpp, db, infra, design, ai. Responda APENAS JSON: " .
              '{"category":"bug|feature|chore|question|incident","priority":"P0|P1|P2|P3",' .
              '"area":"area_owner","severity":"low|medium|high|critical",' .
              '"summary":"resumo 1 frase","next_steps":["passo1","passo2"],' .
              '"estimated_effort":"<1h|1-4h|1d|2-5d|1w+","tags":["..."]}';

    $reply = ClaudeClient::text($prompt, 'Voce eh tech lead. Triagem rapida e precisa.', 600);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed) response(false, null, 'falha triage', 502);

    response(true, $parsed);
} catch (Exception $e) {
    error_log('[bug-triage] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
