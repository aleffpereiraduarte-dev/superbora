<?php
/**
 * POST /api/mercado/intelligence/translate.php
 * Translate texts to a target language using Llama (via ClaudeClient -> Groq).
 *
 * Body: { "texts": ["...", "..."], "target": "en"|"es"|"pt", "context": "product_name"|"description"|"general" }
 * Returns: { "translations": ["...", "..."] }
 *
 * Cached aggressively (results are stable per input).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $texts = array_values(array_filter(array_map('strval', $input['texts'] ?? []), 'strlen'));
    $target = strtolower(trim($input['target'] ?? 'en'));
    $context = trim($input['context'] ?? 'general');

    if (empty($texts)) {
        response(false, null, 'texts obrigatorio', 400);
    }
    if (count($texts) > 50) {
        response(false, null, 'max 50 textos por chamada', 400);
    }
    if (!in_array($target, ['en', 'es', 'pt', 'fr', 'it'], true)) {
        response(false, null, 'target invalido', 400);
    }

    // Cache key by md5 of inputs+target
    $cacheKey = 'tr_' . md5($target . '|' . $context . '|' . implode("\n", $texts));
    $cacheFile = sys_get_temp_dir() . '/sb_' . $cacheKey . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 7 * 86400) {
        response(true, json_decode(file_get_contents($cacheFile), true) ?: ['translations' => $texts]);
    }

    $langName = ['en' => 'English', 'es' => 'Spanish', 'pt' => 'Portuguese (Brazil)', 'fr' => 'French', 'it' => 'Italian'][$target];

    $userPrompt = "Translate the following list to {$langName}. Context: {$context}. " .
                  "Return ONLY a JSON object with the key 'translations' containing the translated strings in the same order. " .
                  "Keep brand names and proper nouns unchanged.\n\n" .
                  "List:\n" . json_encode($texts, JSON_UNESCAPED_UNICODE);

    $reply = ClaudeClient::text($userPrompt, 'You are a translation engine. Always reply with valid JSON only.', 1500);
    if (!$reply) {
        response(false, null, 'falha na traducao', 502);
    }

    $parsed = ClaudeClient::parseJson($reply);
    $translations = $parsed['translations'] ?? null;
    if (!is_array($translations) || count($translations) !== count($texts)) {
        // Fall back to original texts
        $translations = $texts;
    }

    $out = ['translations' => $translations, 'target' => $target];
    @file_put_contents($cacheFile, json_encode($out));
    response(true, $out);
} catch (Exception $e) {
    error_log('[translate] ' . $e->getMessage());
    response(false, null, 'erro ao traduzir', 500);
}
