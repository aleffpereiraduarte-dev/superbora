<?php
/**
 * POST /api/mercado/partner/ai-photo.php
 * AI Photo System for partners
 *
 * Actions:
 *   - standardize: Upload raw photo, AI analyzes + GD enhances
 *   - generate: Generate AI photo from text via DALL-E
 *   - enhance: Quick enhance existing product photo
 *   - bulk_generate: Generate photos for products without images
 *
 * Auth: Bearer token (partner type)
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/claude-client.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

// --- Constants ---
const MAX_PHOTO_SIZE    = 10 * 1024 * 1024; // 10 MB
const ALLOWED_PHOTO_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
const UPLOAD_BASE_DIR   = '/var/www/html/storage/uploads/products';
const UPLOAD_BASE_URL   = '/storage/uploads/products';
const CLAUDE_MODEL      = 'claude-sonnet-4-20250514';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido. Use POST.", 405);
}

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

    // Determine action from POST or JSON body
    $action = $_POST['action'] ?? null;
    if (!$action) {
        $jsonInput = json_decode(file_get_contents('php://input'), true);
        $action = $jsonInput['action'] ?? null;
    }

    if (!$action) {
        response(false, null, "Campo 'action' obrigatorio (standardize, generate, enhance, bulk_generate)", 400);
    }

    switch ($action) {
        case 'standardize':
            handleStandardize($db, $partnerId);
            break;
        case 'generate':
            handleGenerate($db, $partnerId);
            break;
        case 'enhance':
            handleEnhance($db, $partnerId);
            break;
        case 'bulk_generate':
            handleBulkGenerate($db, $partnerId);
            break;
        default:
            response(false, null, "Acao invalida: $action", 400);
    }

} catch (Exception $e) {
    error_log("[partner/ai-photo] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

// =====================================================================
// ACTION: STANDARDIZE
// =====================================================================

function handleStandardize(PDO $db, int $partnerId): void {
    // Validate uploaded photo
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = 'Nenhuma foto enviada';
        if (isset($_FILES['photo'])) {
            $errors = [
                UPLOAD_ERR_INI_SIZE => 'Foto muito grande (limite do servidor)',
                UPLOAD_ERR_FORM_SIZE => 'Foto excede o tamanho maximo',
                UPLOAD_ERR_PARTIAL => 'Upload incompleto',
                UPLOAD_ERR_NO_FILE => 'Nenhuma foto enviada',
            ];
            $errorMsg = $errors[$_FILES['photo']['error']] ?? 'Erro no upload';
        }
        response(false, null, $errorMsg, 400);
    }

    $file = $_FILES['photo'];

    if ($file['size'] > MAX_PHOTO_SIZE) {
        response(false, null, "Foto muito grande. Maximo: 10MB", 400);
    }

    // Validate MIME type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, ALLOWED_PHOTO_MIMES, true)) {
        response(false, null, "Tipo nao suportado ({$mime}). Use JPG, PNG ou WebP.", 400);
    }

    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $ext = $extensions[$mime];

    // Save original
    $originalsDir = UPLOAD_BASE_DIR . '/originals';
    ensureDir($originalsDir);

    $timestamp = time();
    $rand = bin2hex(random_bytes(4));
    $originalFilename = "orig_{$partnerId}_{$timestamp}_{$rand}.{$ext}";
    $originalPath = "{$originalsDir}/{$originalFilename}";

    if (!move_uploaded_file($file['tmp_name'], $originalPath)) {
        response(false, null, "Erro ao salvar foto original", 500);
    }

    // Use Claude Vision to analyze the photo
    $imageData = file_get_contents($originalPath);
    $base64 = base64_encode($imageData);

    $claude = new ClaudeClient(CLAUDE_MODEL, 60);
    $systemPrompt = "Voce e um especialista em fotografia de alimentos e gastronomia. "
        . "Analise a foto do prato/produto alimenticio e retorne um JSON com: "
        . "1) 'description': descricao detalhada e apetitosa do prato em portugues (2-3 frases) "
        . "2) 'quality_score': nota de 1 a 10 para a qualidade da foto (iluminacao, composicao, apelo visual) "
        . "3) 'food_name': nome provavel do prato "
        . "4) 'improvements': lista de sugestoes para melhorar a foto (array de strings) "
        . "5) 'colors_dominant': 3 cores dominantes (hex). "
        . "Retorne APENAS JSON valido, sem texto adicional.";

    $claudeResult = $claude->sendWithVision(
        $systemPrompt,
        [['data' => $base64, 'mime' => $mime]],
        "Analise esta foto de produto alimenticio.",
        2048
    );

    $aiDescription = '';
    $qualityScore = 5;
    $foodName = '';
    $improvements = [];

    if ($claudeResult['success']) {
        $parsed = ClaudeClient::parseJson($claudeResult['text']);
        if ($parsed) {
            $aiDescription = $parsed['description'] ?? '';
            $qualityScore = max(1, min(10, (int)($parsed['quality_score'] ?? 5)));
            $foodName = $parsed['food_name'] ?? '';
            $improvements = $parsed['improvements'] ?? [];
        }
    }

    // Process with GD
    $processedDir = UPLOAD_BASE_DIR . '/processed';
    ensureDir($processedDir);

    $processedFilename = "proc_{$partnerId}_{$timestamp}_{$rand}.jpg";
    $processedPath = "{$processedDir}/{$processedFilename}";

    $processResult = processImageWithGD($originalPath, $processedPath, $mime);
    if (!$processResult) {
        // Fallback: copy original as processed
        copy($originalPath, $processedPath);
    }

    $originalUrl = UPLOAD_BASE_URL . "/originals/{$originalFilename}";
    $processedUrl = UPLOAD_BASE_URL . "/processed/{$processedFilename}";

    response(true, [
        'original_url' => $originalUrl,
        'processed_url' => $processedUrl,
        'ai_description' => $aiDescription,
        'food_name' => $foodName,
        'quality_score' => $qualityScore,
        'improvements' => $improvements,
    ], "Foto padronizada com sucesso!");
}

// =====================================================================
// ACTION: GENERATE
// =====================================================================

function handleGenerate(PDO $db, int $partnerId): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        // Try POST fields (multipart scenario)
        $input = $_POST;
    }

    $productName = trim($input['product_name'] ?? '');
    $description = trim($input['description'] ?? '');
    $category = trim($input['category'] ?? '');

    if (empty($productName)) {
        response(false, null, "Campo 'product_name' e obrigatorio", 400);
    }

    $openaiKey = $_ENV['OPENAI_API_KEY'] ?? '';
    if (empty($openaiKey)) {
        response(false, null, "OPENAI_API_KEY nao configurado no servidor", 500);
    }

    // Build DALL-E prompt
    $prompt = "Professional food photography of {$productName}.";
    if ($description) {
        $prompt .= " {$description}.";
    }
    if ($category) {
        $prompt .= " Category: {$category}.";
    }
    $prompt .= " Brazilian cuisine style. Top-down angle, clean white plate, natural lighting, appetizing presentation, restaurant quality, high resolution, no text or watermarks.";

    // Call DALL-E API
    $dalleResult = callDalleApi($openaiKey, $prompt);

    if (!$dalleResult['success']) {
        error_log("[partner/ai-photo] DALL-E error for partner {$partnerId}: " . ($dalleResult['error'] ?? 'unknown'));
        response(false, null, "Erro ao gerar imagem: " . ($dalleResult['error'] ?? 'tente novamente'), 500);
    }

    $imageUrl = $dalleResult['url'];

    // Download the generated image
    $imageData = downloadImage($imageUrl);
    if (!$imageData) {
        response(false, null, "Erro ao baixar imagem gerada", 500);
    }

    // Save raw generated image temporarily
    $generatedDir = UPLOAD_BASE_DIR . '/ai_generated';
    ensureDir($generatedDir);

    $timestamp = time();
    $rand = bin2hex(random_bytes(4));
    $tempPath = sys_get_temp_dir() . "/dalle_{$timestamp}_{$rand}.png";
    file_put_contents($tempPath, $imageData);

    // Process with GD: resize + enhance + watermark
    $finalFilename = "ai_{$partnerId}_{$timestamp}_{$rand}.jpg";
    $finalPath = "{$generatedDir}/{$finalFilename}";

    $processResult = processImageWithGD($tempPath, $finalPath, 'image/png', true);
    if (!$processResult) {
        // Fallback: save as-is
        $finalFilename = "ai_{$partnerId}_{$timestamp}_{$rand}.png";
        $finalPath = "{$generatedDir}/{$finalFilename}";
        file_put_contents($finalPath, $imageData);
    }

    @unlink($tempPath);

    $generatedUrl = UPLOAD_BASE_URL . "/ai_generated/{$finalFilename}";

    response(true, [
        'generated_url' => $generatedUrl,
        'prompt_used' => $prompt,
        'is_ai_generated' => true,
    ], "Foto gerada com sucesso!");
}

// =====================================================================
// ACTION: ENHANCE
// =====================================================================

function handleEnhance(PDO $db, int $partnerId): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;

    $productId = (int)($input['product_id'] ?? 0);
    if (!$productId) {
        response(false, null, "Campo 'product_id' e obrigatorio", 400);
    }

    // Find the product and verify ownership
    $product = findPartnerProduct($db, $partnerId, $productId);
    if (!$product) {
        response(false, null, "Produto nao encontrado ou nao pertence a este parceiro", 404);
    }

    $currentImage = $product['image'] ?? '';
    if (empty($currentImage)) {
        response(false, null, "Produto nao possui foto para melhorar. Use 'generate' para criar uma.", 400);
    }

    // Download current image
    $imagePath = '/var/www/html' . $currentImage;
    if (!file_exists($imagePath)) {
        // Try with /mercado prefix
        $imagePath = '/var/www/html/mercado/uploads/products/' . basename($currentImage);
        if (!file_exists($imagePath)) {
            response(false, null, "Foto atual nao encontrada no servidor", 404);
        }
    }

    $imageData = file_get_contents($imagePath);
    if (!$imageData) {
        response(false, null, "Erro ao ler foto atual", 500);
    }

    // Detect MIME
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($imagePath);

    // Process with GD
    $processedDir = UPLOAD_BASE_DIR . '/processed';
    ensureDir($processedDir);

    $timestamp = time();
    $rand = bin2hex(random_bytes(4));
    $processedFilename = "enh_{$partnerId}_{$productId}_{$timestamp}_{$rand}.jpg";
    $processedPath = "{$processedDir}/{$processedFilename}";

    $processResult = processImageWithGD($imagePath, $processedPath, $mime);
    if (!$processResult) {
        response(false, null, "Erro ao processar imagem", 500);
    }

    $processedUrl = UPLOAD_BASE_URL . "/processed/{$processedFilename}";

    // Update product image in database
    updateProductImage($db, $productId, $processedUrl);

    response(true, [
        'before_url' => $currentImage,
        'after_url' => $processedUrl,
        'product_id' => $productId,
    ], "Foto melhorada com sucesso!");
}

// =====================================================================
// ACTION: BULK GENERATE
// =====================================================================

function handleBulkGenerate(PDO $db, int $partnerId): void {
    $openaiKey = $_ENV['OPENAI_API_KEY'] ?? '';
    if (empty($openaiKey)) {
        response(false, null, "OPENAI_API_KEY nao configurado no servidor", 500);
    }

    // Find products without photos (max 10)
    $products = findProductsWithoutPhotos($db, $partnerId, 10);

    if (empty($products)) {
        response(true, [
            'processed' => 0,
            'failed' => 0,
            'products' => [],
        ], "Todos os produtos ja possuem foto!");
    }

    $processed = 0;
    $failed = 0;
    $results = [];

    foreach ($products as $product) {
        $productName = $product['name'] ?? '';
        $description = $product['description'] ?? '';
        $category = $product['category_name'] ?? '';
        $productId = (int)$product['product_id'];

        if (empty($productName)) {
            $failed++;
            $results[] = [
                'product_id' => $productId,
                'name' => $productName,
                'status' => 'failed',
                'error' => 'Produto sem nome',
            ];
            continue;
        }

        // Build prompt
        $prompt = "Professional food photography of {$productName}.";
        if ($description) {
            $prompt .= " {$description}.";
        }
        if ($category) {
            $prompt .= " Category: {$category}.";
        }
        $prompt .= " Brazilian cuisine style. Top-down angle, clean white plate, natural lighting, appetizing presentation, restaurant quality, high resolution, no text or watermarks.";

        // Call DALL-E
        $dalleResult = callDalleApi($openaiKey, $prompt);

        if (!$dalleResult['success']) {
            $failed++;
            $results[] = [
                'product_id' => $productId,
                'name' => $productName,
                'status' => 'failed',
                'error' => $dalleResult['error'] ?? 'Erro na geracao',
            ];
            continue;
        }

        // Download
        $imageData = downloadImage($dalleResult['url']);
        if (!$imageData) {
            $failed++;
            $results[] = [
                'product_id' => $productId,
                'name' => $productName,
                'status' => 'failed',
                'error' => 'Erro ao baixar imagem',
            ];
            continue;
        }

        // Save and process
        $generatedDir = UPLOAD_BASE_DIR . '/ai_generated';
        ensureDir($generatedDir);

        $timestamp = time();
        $rand = bin2hex(random_bytes(4));
        $tempPath = sys_get_temp_dir() . "/dalle_{$productId}_{$timestamp}_{$rand}.png";
        file_put_contents($tempPath, $imageData);

        $finalFilename = "ai_{$partnerId}_{$productId}_{$timestamp}_{$rand}.jpg";
        $finalPath = "{$generatedDir}/{$finalFilename}";

        $processResult = processImageWithGD($tempPath, $finalPath, 'image/png', true);
        if (!$processResult) {
            $finalFilename = "ai_{$partnerId}_{$productId}_{$timestamp}_{$rand}.png";
            $finalPath = "{$generatedDir}/{$finalFilename}";
            file_put_contents($finalPath, $imageData);
        }

        @unlink($tempPath);

        $imageUrl = UPLOAD_BASE_URL . "/ai_generated/{$finalFilename}";

        // Update database
        updateProductImage($db, $productId, $imageUrl);

        $processed++;
        $results[] = [
            'product_id' => $productId,
            'name' => $productName,
            'status' => 'success',
            'image_url' => $imageUrl,
            'is_ai_generated' => true,
        ];
    }

    response(true, [
        'processed' => $processed,
        'failed' => $failed,
        'total_without_photo' => count($products),
        'products' => $results,
    ], "{$processed} foto(s) gerada(s) com sucesso!");
}

// =====================================================================
// Helper Functions
// =====================================================================

/**
 * Ensure directory exists with proper permissions
 */
function ensureDir(string $dir): void {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

/**
 * Process an image with GD library:
 * - Resize to 800x600 (landscape) or 600x600 (square) maintaining aspect ratio, center crop
 * - Increase saturation +10%
 * - Increase contrast +5%
 * - Apply warm color shift
 * - Sharpen slightly
 * - Optional: add "Imagem ilustrativa" watermark
 *
 * @return bool Success
 */
function processImageWithGD(string $inputPath, string $outputPath, string $mime, bool $addWatermark = false): bool {
    if (!function_exists('imagecreatefrompng')) {
        error_log("[ai-photo] GD extension not available");
        return false;
    }

    // Load source image
    $src = null;
    switch ($mime) {
        case 'image/jpeg':
            $src = @imagecreatefromjpeg($inputPath);
            break;
        case 'image/png':
            $src = @imagecreatefrompng($inputPath);
            break;
        case 'image/webp':
            if (function_exists('imagecreatefromwebp')) {
                $src = @imagecreatefromwebp($inputPath);
            }
            break;
    }

    if (!$src) {
        error_log("[ai-photo] Failed to load image: {$inputPath}");
        return false;
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);

    // Determine target dimensions
    $aspectRatio = $srcW / $srcH;
    if ($aspectRatio > 1.2) {
        // Landscape: target 800x600
        $targetW = 800;
        $targetH = 600;
    } elseif ($aspectRatio < 0.8) {
        // Portrait: target 600x800
        $targetW = 600;
        $targetH = 800;
    } else {
        // Square-ish: target 600x600
        $targetW = 600;
        $targetH = 600;
    }

    // Calculate center crop dimensions
    $targetAspect = $targetW / $targetH;
    if ($aspectRatio > $targetAspect) {
        // Source is wider: crop sides
        $cropH = $srcH;
        $cropW = (int)($srcH * $targetAspect);
        $cropX = (int)(($srcW - $cropW) / 2);
        $cropY = 0;
    } else {
        // Source is taller: crop top/bottom
        $cropW = $srcW;
        $cropH = (int)($srcW / $targetAspect);
        $cropX = 0;
        $cropY = (int)(($srcH - $cropH) / 2);
    }

    // Create target image and resize with center crop
    $dst = imagecreatetruecolor($targetW, $targetH);
    imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $targetW, $targetH, $cropW, $cropH);
    imagedestroy($src);

    // --- Enhancement filters ---

    // 1. Increase contrast slightly (+5% ~ value around -5 in PHP GD, negative = more contrast)
    imagefilter($dst, IMG_FILTER_CONTRAST, -5);

    // 2. Increase brightness very slightly for food appeal
    imagefilter($dst, IMG_FILTER_BRIGHTNESS, 5);

    // 3. Apply warm color shift (add red/yellow, reduce blue slightly)
    // This makes food look more appetizing
    applyWarmShift($dst, $targetW, $targetH);

    // 4. Increase saturation by ~10%
    applySaturationBoost($dst, $targetW, $targetH, 1.10);

    // 5. Sharpen slightly using convolution
    $sharpenMatrix = [
        [0, -0.5, 0],
        [-0.5, 3, -0.5],
        [0, -0.5, 0],
    ];
    imageconvolution($dst, $sharpenMatrix, 1, 0);

    // 6. Optional watermark
    if ($addWatermark) {
        addWatermarkText($dst, $targetW, $targetH, "Imagem ilustrativa");
    }

    // Save as JPEG quality 90
    $result = imagejpeg($dst, $outputPath, 90);
    imagedestroy($dst);

    return $result;
}

/**
 * Apply a warm color shift: slightly boost R/G channels, slightly reduce B
 */
function applyWarmShift($img, int $w, int $h): void {
    // Use GD color matrix approach: add slight red, reduce blue
    // IMG_FILTER_COLORIZE: red, green, blue, alpha
    // Warm: +8 red, +4 green, -6 blue
    imagefilter($img, IMG_FILTER_COLORIZE, 8, 4, -6);
}

/**
 * Boost saturation by a factor (e.g., 1.10 = +10%)
 * Works pixel-by-pixel using HSL conversion
 */
function applySaturationBoost($img, int $w, int $h, float $factor): void {
    // For performance, sample every 2nd pixel on large images — but for 800x600 this is fine
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgb = imagecolorat($img, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;

            // RGB to HSL
            $r1 = $r / 255;
            $g1 = $g / 255;
            $b1 = $b / 255;

            $max = max($r1, $g1, $b1);
            $min = min($r1, $g1, $b1);
            $l = ($max + $min) / 2;

            if ($max === $min) {
                // Achromatic — skip
                continue;
            }

            $d = $max - $min;
            $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

            // Determine hue
            if ($max === $r1) {
                $h2 = ($g1 - $b1) / $d + ($g1 < $b1 ? 6 : 0);
            } elseif ($max === $g1) {
                $h2 = ($b1 - $r1) / $d + 2;
            } else {
                $h2 = ($r1 - $g1) / $d + 4;
            }
            $h2 /= 6;

            // Boost saturation
            $s = min(1.0, $s * $factor);

            // HSL to RGB
            $c = (1 - abs(2 * $l - 1)) * $s;
            $x2 = $c * (1 - abs(fmod($h2 * 6, 2) - 1));
            $m = $l - $c / 2;

            $hSector = (int)($h2 * 6);
            switch ($hSector % 6) {
                case 0: $r2 = $c; $g2 = $x2; $b2 = 0; break;
                case 1: $r2 = $x2; $g2 = $c; $b2 = 0; break;
                case 2: $r2 = 0; $g2 = $c; $b2 = $x2; break;
                case 3: $r2 = 0; $g2 = $x2; $b2 = $c; break;
                case 4: $r2 = $x2; $g2 = 0; $b2 = $c; break;
                default: $r2 = $c; $g2 = 0; $b2 = $x2; break;
            }

            $newR = max(0, min(255, (int)(($r2 + $m) * 255)));
            $newG = max(0, min(255, (int)(($g2 + $m) * 255)));
            $newB = max(0, min(255, (int)(($b2 + $m) * 255)));

            $newColor = imagecolorallocate($img, $newR, $newG, $newB);
            imagesetpixel($img, $x, $y, $newColor);
        }
    }
}

/**
 * Add semi-transparent watermark text in bottom-right corner
 */
function addWatermarkText($img, int $w, int $h, string $text): void {
    $fontSize = 3; // GD built-in font size (1-5)
    $fontWidth = imagefontwidth($fontSize);
    $fontHeight = imagefontheight($fontSize);
    $textWidth = strlen($text) * $fontWidth;

    // Position: bottom-right with padding
    $x = $w - $textWidth - 10;
    $y = $h - $fontHeight - 10;

    // Semi-transparent background
    $bgColor = imagecolorallocatealpha($img, 0, 0, 0, 80); // ~69% transparent
    imagefilledrectangle($img, $x - 6, $y - 4, $x + $textWidth + 6, $y + $fontHeight + 4, $bgColor);

    // White text
    $textColor = imagecolorallocatealpha($img, 255, 255, 255, 30); // slightly transparent
    imagestring($img, $fontSize, $x, $y, $text, $textColor);
}

/**
 * Call OpenAI DALL-E API
 * @return array{success: bool, url?: string, error?: string}
 */
function callDalleApi(string $apiKey, string $prompt): array {
    $data = [
        'model' => 'dall-e-3',
        'prompt' => $prompt,
        'n' => 1,
        'size' => '1024x1024',
        'quality' => 'standard',
    ];

    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => 'Erro de conexao: ' . $curlError];
    }

    if ($httpCode !== 200) {
        $body = json_decode($response, true);
        $errorMsg = $body['error']['message'] ?? "HTTP {$httpCode}";
        return ['success' => false, 'error' => $errorMsg];
    }

    $result = json_decode($response, true);
    $imageUrl = $result['data'][0]['url'] ?? null;

    if (!$imageUrl) {
        return ['success' => false, 'error' => 'Resposta sem URL de imagem'];
    }

    return ['success' => true, 'url' => $imageUrl];
}

/**
 * Download an image from URL
 */
function downloadImage(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($data)) {
        return null;
    }

    return $data;
}

/**
 * Find a partner's product by ID
 */
function findPartnerProduct(PDO $db, int $partnerId, int $productId): ?array {
    // Try catalog model first (om_market_products_price + om_market_products_base)
    $stmt = $db->prepare("
        SELECT pb.product_id, pb.name, pb.description, pb.image, pb.category_id,
               pp.price, cat.name as category_name
        FROM om_market_products_price pp
        INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id
        LEFT JOIN om_market_categories cat ON cat.category_id = pb.category_id
        WHERE pp.product_id = ? AND pp.partner_id = ?
        LIMIT 1
    ");
    $stmt->execute([$productId, $partnerId]);
    $row = $stmt->fetch();
    if ($row) return $row;

    // Try simple model (om_market_products)
    $stmt = $db->prepare("
        SELECT product_id, name, description, image, category_id, price, category as category_name
        FROM om_market_products
        WHERE product_id = ? AND partner_id = ?
        LIMIT 1
    ");
    $stmt->execute([$productId, $partnerId]);
    return $stmt->fetch() ?: null;
}

/**
 * Find products without photos for a partner
 */
function findProductsWithoutPhotos(PDO $db, int $partnerId, int $limit = 10): array {
    // Try catalog model first
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM om_market_products_price WHERE partner_id = ?
    ");
    $stmt->execute([$partnerId]);
    $hasCatalog = (int)$stmt->fetchColumn() > 0;

    if ($hasCatalog) {
        $stmt = $db->prepare("
            SELECT pb.product_id, pb.name, pb.description, pb.image, cat.name as category_name
            FROM om_market_products_price pp
            INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id
            LEFT JOIN om_market_categories cat ON cat.category_id = pb.category_id
            WHERE pp.partner_id = ? AND pp.status = 1
              AND (pb.image IS NULL OR pb.image = '' OR pb.image = 'null')
            ORDER BY pb.name ASC
            LIMIT ?
        ");
        $stmt->execute([$partnerId, $limit]);
        return $stmt->fetchAll();
    }

    // Simple model
    $stmt = $db->prepare("
        SELECT product_id, name, description, image, category as category_name
        FROM om_market_products
        WHERE partner_id = ? AND COALESCE(status, 1) = 1
          AND (image IS NULL OR image = '' OR image = 'null')
        ORDER BY name ASC
        LIMIT ?
    ");
    $stmt->execute([$partnerId, $limit]);
    return $stmt->fetchAll();
}

/**
 * Update product image in the appropriate table
 */
function updateProductImage(PDO $db, int $productId, string $imageUrl): void {
    // Try catalog model first
    try {
        $stmt = $db->prepare("UPDATE om_market_products_base SET image = ? WHERE product_id = ?");
        $stmt->execute([$imageUrl, $productId]);
        if ($stmt->rowCount() > 0) return;
    } catch (Exception $e) {
        // Table might not exist, try simple model
    }

    // Simple model
    try {
        $stmt = $db->prepare("UPDATE om_market_products SET image = ? WHERE product_id = ?");
        $stmt->execute([$imageUrl, $productId]);
    } catch (Exception $e) {
        error_log("[ai-photo] Failed to update product image: " . $e->getMessage());
    }
}
