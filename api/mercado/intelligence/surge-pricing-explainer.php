<?php
/**
 * GET /api/mercado/intelligence/surge-pricing-explainer.php?multiplier=1.4&context=...
 * Generates a friendly explanation when the customer sees a higher delivery fee.
 *
 * Cached 5 minutes per multiplier+context.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $multiplier = (float)($_GET['multiplier'] ?? 1.0);
    $reason = trim($_GET['reason'] ?? '');
    if ($multiplier <= 1.0) {
        response(true, ['message' => '', 'show' => false]);
    }

    $cacheKey = 'surge_' . md5($multiplier . $reason);
    $cacheFile = sys_get_temp_dir() . '/sb_' . $cacheKey . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 300) {
        response(true, json_decode(file_get_contents($cacheFile), true));
    }

    $hour = (int)date('H');
    $rush = ($hour >= 11 && $hour <= 13) || ($hour >= 18 && $hour <= 20);
    $pct = round(($multiplier - 1) * 100);

    $prompt = "Taxa de entrega esta {$pct}% mais alta agora. " .
              "Motivo provavel: " . ($reason ?: ($rush ? 'horario de pico' : 'alta demanda na regiao')) . ". " .
              "Em pt-BR max 150 chars, explique pro cliente de forma transparente, sem desculpas exageradas. " .
              "Tom: honesto, util, pode mencionar alternativa (esperar X min).";

    $msg = ClaudeClient::text($prompt, 'Voce explica precos dinamicos com transparencia.', 200);
    $out = ['message' => $msg ?: "Taxa esta {$pct}% maior agora por alta demanda. Pode esperar uns minutos pra normalizar.", 'multiplier' => $multiplier, 'show' => true];
    @file_put_contents($cacheFile, json_encode($out));
    response(true, $out);
} catch (Exception $e) {
    error_log('[surge-explainer] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
