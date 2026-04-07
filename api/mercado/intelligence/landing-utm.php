<?php
/**
 * GET /api/mercado/intelligence/landing-utm.php?utm_source=instagram&utm_medium=cpc&utm_campaign=acai_promo
 *
 * Returns headline + subheadline + CTA tailored to the UTM source/medium/campaign.
 * Used by the public landing page renderer.
 *
 * Cached 1h per UTM combo.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $source = trim($_GET['utm_source'] ?? '');
    $medium = trim($_GET['utm_medium'] ?? '');
    $campaign = trim($_GET['utm_campaign'] ?? '');

    if ($source === '' && $campaign === '') {
        response(true, [
            'headline' => 'Peca seu delivery favorito',
            'subheadline' => 'Tudo no SuperBora',
            'cta' => 'Baixar agora',
        ]);
    }

    $cacheKey = 'utm_' . md5("{$source}|{$medium}|{$campaign}");
    $cacheFile = sys_get_temp_dir() . '/sb_' . $cacheKey . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        response(true, json_decode(file_get_contents($cacheFile), true));
    }

    $prompt = "Gere conteudo de landing page personalizado para o trafego:\n" .
              "Fonte: {$source}\nMeio: {$medium}\nCampanha: {$campaign}\n\n" .
              "Empresa: SuperBora delivery (comida, mercado, farmacia em domicilio). " .
              "Audiencia chega da fonte acima. Em pt-BR. " .
              "Responda APENAS JSON: " .
              '{"headline":"max 60 chars","subheadline":"max 120 chars","cta":"max 25 chars","social_proof":"max 80 chars","incentivo":"oferta especifica para essa fonte max 100 chars"}';

    $reply = ClaudeClient::text($prompt, 'Voce eh growth marketer expert em landing page personalization.', 500);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed) response(false, null, 'falha geracao', 502);

    @file_put_contents($cacheFile, json_encode($parsed));
    response(true, $parsed);
} catch (Exception $e) {
    error_log('[landing-utm] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
