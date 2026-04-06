<?php
/**
 * Product Image Quality Scoring with Claude Vision AI
 * POST /api/mercado/partner/image-quality.php
 *
 * Parameters (JSON body or form data):
 *   image_url  - URL of the product image to analyze (required)
 *
 * Returns quality score 1-10 and per-dimension scores:
 *   - lighting: Is the photo well lit?
 *   - focus: Is the image sharp and in focus?
 *   - composition: Is the food well centered and framed?
 *   - appetizing: Does the food look appealing for a delivery app?
 *   - background: Is the background clean and not distracting?
 *   - tips: Specific improvement suggestions in Portuguese
 *
 * Auth: Bearer token (partner type)
 * Cache: 24h per image URL hash via CacheHelper
 * Rate limit: uses global rate limiter from config/database.php
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/claude-client.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

setCorsHeaders();

// --- Constants ---
const MAX_IMAGE_DOWNLOAD_SIZE = 10 * 1024 * 1024; // 10 MB
const IMAGE_DOWNLOAD_TIMEOUT = 15; // seconds
const CACHE_TTL = 86400; // 24 hours
const ALLOWED_IMAGE_MIMES = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/gif',
];

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // --- Auth ---
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido. Use POST.", 405);
    }

    // --- Get input ---
    $input = getInput();
    $imageUrl = trim($input['image_url'] ?? '');

    if (empty($imageUrl)) {
        response(false, null, "Parametro image_url e obrigatorio", 400);
    }

    // Validate URL format
    if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
        response(false, null, "URL da imagem invalida", 400);
    }

    // SECURITY: Only allow http/https schemes
    $scheme = parse_url($imageUrl, PHP_URL_SCHEME);
    if (!in_array(strtolower($scheme), ['http', 'https'])) {
        response(false, null, "URL deve usar http ou https", 400);
    }

    // SECURITY: Block local/private IPs to prevent SSRF
    $host = parse_url($imageUrl, PHP_URL_HOST);
    if ($host) {
        $ip = gethostbyname($host);
        if ($ip && (
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
        )) {
            // Allow known CDN/storage domains even if they resolve to private IPs
            $allowedHosts = ['superbora.com.br', 'www.superbora.com.br', 'app.superbora.com.br', 'cdn.superbora.com.br'];
            if (!in_array(strtolower($host), $allowedHosts)) {
                response(false, null, "URL de imagem nao permitida", 400);
            }
        }
    }

    // --- Check cache ---
    $cacheKey = "img_quality_v1:" . md5($imageUrl);
    $cached = CacheHelper::get($cacheKey);
    if ($cached !== null) {
        response(true, $cached, "Resultado em cache");
    }

    // --- Download image ---
    $imageData = downloadImage($imageUrl);
    if (!$imageData['success']) {
        response(false, null, $imageData['error'], 400);
    }

    $base64 = base64_encode($imageData['data']);
    $mime = $imageData['mime'];

    // --- Call Claude Vision ---
    $result = analyzeImageQuality($base64, $mime);

    if (!$result['success']) {
        response(false, null, "Erro ao analisar imagem: " . ($result['error'] ?? 'desconhecido'), 500);
    }

    // --- Cache result ---
    CacheHelper::set($cacheKey, $result['data'], CACHE_TTL);

    // --- Log usage ---
    logImageAnalysis($db, $partnerId, $imageUrl, $result['data']['score'] ?? 0);

    response(true, $result['data']);

} catch (Exception $e) {
    error_log("[ImageQuality] Erro: " . $e->getMessage());
    response(false, null, "Erro ao analisar qualidade da imagem", 500);
}

// ============================================================
// Helper functions
// ============================================================

/**
 * Download image from URL and validate it
 */
function downloadImage(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => IMAGE_DOWNLOAD_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'SuperBora/1.0 ImageQuality',
        // Limit download size
        CURLOPT_PROGRESSFUNCTION => function($resource, $downloadSize, $downloaded) {
            if ($downloaded > MAX_IMAGE_DOWNLOAD_SIZE) {
                return 1; // abort
            }
            return 0;
        },
        CURLOPT_NOPROGRESS => false,
    ]);

    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => "Erro ao baixar imagem: {$curlError}"];
    }

    if ($httpCode !== 200) {
        return ['success' => false, 'error' => "Imagem nao encontrada (HTTP {$httpCode})"];
    }

    if (empty($data)) {
        return ['success' => false, 'error' => "Imagem vazia"];
    }

    if (strlen($data) > MAX_IMAGE_DOWNLOAD_SIZE) {
        return ['success' => false, 'error' => "Imagem muito grande (max 10MB)"];
    }

    // Determine MIME type from content or content-type header
    $mime = null;
    $contentType = strtolower(trim(explode(';', $contentType ?? '')[0]));

    if (in_array($contentType, ALLOWED_IMAGE_MIMES)) {
        $mime = $contentType;
    }

    // Fallback: detect from data
    if (!$mime) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->buffer($data);
        if (in_array($detectedMime, ALLOWED_IMAGE_MIMES)) {
            $mime = $detectedMime;
        }
    }

    if (!$mime) {
        return ['success' => false, 'error' => "Formato de imagem nao suportado. Use JPEG, PNG, WebP ou GIF."];
    }

    return [
        'success' => true,
        'data' => $data,
        'mime' => $mime,
        'size' => strlen($data),
    ];
}

/**
 * Analyze image quality using Claude Vision API
 */
function analyzeImageQuality(string $base64, string $mime): array {
    $claude = new ClaudeClient(ClaudeClient::DEFAULT_MODEL, 60, 1);

    $systemPrompt = <<<'PROMPT'
Voce e um especialista em fotografia de alimentos e produtos para apps de delivery. Analise a foto do produto enviada e avalie a qualidade para uso em um aplicativo de delivery de comida e mercado.

Avalie as seguintes dimensoes com nota de 1 a 10:

1. **lighting** (Iluminacao): A foto esta bem iluminada? Luz natural e preferivel. Sombras fortes ou escuridao reduzem a nota.
2. **focus** (Foco): A imagem esta nitida e em foco? Desfoque reduz a nota.
3. **composition** (Composicao): O produto esta centralizado e bem enquadrado? Ha espaco adequado ao redor?
4. **appetizing** (Atratividade): O produto parece apetitoso/atraente? Cores vibrantes, apresentacao caprichada aumentam a nota.
5. **background** (Fundo): O fundo e limpo e nao distrai? Fundos brancos ou neutros sao ideais.

Calcule um **score** geral de 1 a 10 (media ponderada: atratividade 30%, iluminacao 25%, composicao 20%, foco 15%, fundo 10%).

Forneça ate 5 **tips** (dicas) praticas e especificas em portugues para melhorar a foto. Seja direto e acionavel.

Se a imagem NAO for de um produto/alimento, retorne score 1 e indique nas dicas que a imagem parece nao ser de um produto.

Responda APENAS com JSON valido neste formato:
{
  "score": 8,
  "lighting": 9,
  "focus": 7,
  "composition": 8,
  "appetizing": 9,
  "background": 6,
  "tips": [
    "Dica 1 aqui",
    "Dica 2 aqui"
  ],
  "summary": "Resumo curto da avaliacao em uma frase"
}
PROMPT;

    $textPrompt = "Analise a qualidade desta foto de produto para uso em um aplicativo de delivery. Retorne APENAS o JSON com as notas e dicas.";

    $images = [
        ['data' => $base64, 'mime' => $mime]
    ];

    $result = $claude->sendWithVision($systemPrompt, $images, $textPrompt, 2048);

    if (!$result['success']) {
        return ['success' => false, 'error' => $result['error'] ?? 'Claude API error'];
    }

    // Parse JSON from Claude response
    $parsed = ClaudeClient::parseJson($result['text']);

    if (!$parsed || !isset($parsed['score'])) {
        error_log("[ImageQuality] Failed to parse Claude response: " . substr($result['text'] ?? '', 0, 500));
        return ['success' => false, 'error' => 'Nao foi possivel interpretar a resposta da IA'];
    }

    // Validate and clamp scores to 1-10
    $data = [
        'score'       => clampScore((int)($parsed['score'] ?? 5)),
        'lighting'    => clampScore((int)($parsed['lighting'] ?? 5)),
        'focus'       => clampScore((int)($parsed['focus'] ?? 5)),
        'composition' => clampScore((int)($parsed['composition'] ?? 5)),
        'appetizing'  => clampScore((int)($parsed['appetizing'] ?? 5)),
        'background'  => clampScore((int)($parsed['background'] ?? 5)),
        'tips'        => [],
        'summary'     => sanitizeOutput($parsed['summary'] ?? ''),
        'tokens_used' => $result['total_tokens'] ?? 0,
    ];

    // Sanitize tips
    if (isset($parsed['tips']) && is_array($parsed['tips'])) {
        foreach (array_slice($parsed['tips'], 0, 5) as $tip) {
            if (is_string($tip) && !empty(trim($tip))) {
                $data['tips'][] = sanitizeOutput(trim($tip));
            }
        }
    }

    return ['success' => true, 'data' => $data];
}

/**
 * Clamp a score to the 1-10 range
 */
function clampScore(int $score): int {
    return max(1, min(10, $score));
}

/**
 * Log image analysis for partner usage tracking
 */
function logImageAnalysis(PDO $db, int $partnerId, string $imageUrl, int $score): void {
    try {
        // Log to om_ai_menu_sessions table (reuse existing AI tracking)
        $stmt = $db->prepare("
            INSERT INTO om_ai_image_quality_log (partner_id, image_url, score, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$partnerId, substr($imageUrl, 0, 1000), $score]);
    } catch (Exception $e) {
        // Table might not exist yet - create it
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS om_ai_image_quality_log (
                    id SERIAL PRIMARY KEY,
                    partner_id INTEGER NOT NULL,
                    image_url TEXT NOT NULL,
                    score INTEGER NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT NOW()
                )
            ");
            // Retry insert
            $stmt = $db->prepare("
                INSERT INTO om_ai_image_quality_log (partner_id, image_url, score, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$partnerId, substr($imageUrl, 0, 1000), $score]);
        } catch (Exception $e2) {
            // Non-critical - just log and continue
            error_log("[ImageQuality] Log insert failed: " . $e2->getMessage());
        }
    }
}
