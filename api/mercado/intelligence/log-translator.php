<?php
/**
 * POST /api/mercado/intelligence/log-translator.php
 * Body: { "log":"...","language":"pt"}
 *
 * Takes a raw error log/stack trace and explains it in plain Portuguese
 * for non-technical admins.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $log = trim($input['log'] ?? '');
    if ($log === '') response(false, null, 'log obrigatorio', 400);

    $log = mb_substr($log, 0, 4000); // limit input

    $cacheFile = sys_get_temp_dir() . '/sb_logtr_' . md5($log) . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        response(true, json_decode(file_get_contents($cacheFile), true));
    }

    $prompt = "Aqui esta um log de erro do SuperBora:\n\n{$log}\n\n" .
              "Traduza para portugues simples para um admin nao-tecnico. " .
              "Responda APENAS JSON: " .
              '{"summary":"o que aconteceu em 1 frase","severity":"info|warning|error|critical",' .
              '"likely_cause":"causa provavel","fix_suggestion":"como resolver","affects_users":true_ou_false}';

    $reply = ClaudeClient::text(
        $prompt,
        'Voce eh um SRE que explica logs em pt-BR para o time de produto.',
        500
    );
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed) response(false, null, 'falha analise', 502);

    @file_put_contents($cacheFile, json_encode($parsed));
    response(true, $parsed);
} catch (Exception $e) {
    error_log('[log-translator] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
