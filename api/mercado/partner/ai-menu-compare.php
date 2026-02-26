<?php
/**
 * POST /api/mercado/partner/ai-menu-compare.php
 * Compares an uploaded menu (image/PDF/text) against existing products.
 *
 * Returns a diff: new_items[], removed_items[], price_changes[], name_changes[], unchanged[]
 *
 * Supports:
 *   - Single image: field "image"
 *   - Multiple images: field "images[]"
 *   - PDF: field "image" or "pdf"
 *   - Text: JSON field "text"
 *
 * Auth: Bearer token (partner type)
 * Rate limit: 5 per hour per partner
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/claude-client.php";
require_once __DIR__ . "/../helpers/rate-limit.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

// --- Constants ---
const MAX_IMAGE_SIZE   = 20 * 1024 * 1024; // 20 MB per file
const MAX_TOTAL_SIZE   = 50 * 1024 * 1024; // 50 MB total
const MAX_PAGES        = 10;
const MAX_PDF_PAGES    = 10;
const ALLOWED_MIME_TYPES = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/gif',
    'application/pdf',
];
const CLAUDE_MODEL     = 'claude-sonnet-4-20250514';
const CLAUDE_MAX_TOKENS = 8192;

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

    // --- Rate limiting ---
    if (!checkRateLimit($db, "ai_menu_compare_{$partnerId}", 5, 60)) {
        response(false, null, "Muitas comparacoes. Tente novamente em 1 hora.", 429);
    }

    // --- Collect uploaded content ---
    $base64Images = [];
    $userText = null;
    $mode = null;
    $pageCount = 0;

    // 1. Multiple images (images[])
    if (!empty($_FILES['images']) && is_array($_FILES['images']['name'])) {
        $fileCount = count($_FILES['images']['name']);
        if ($fileCount > MAX_PAGES) {
            response(false, null, "Maximo de " . MAX_PAGES . " imagens por vez.", 400);
        }

        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) {
                if ($_FILES['images']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                response(false, null, "Erro no upload da imagem " . ($i + 1) . ".", 400);
            }

            $result = processUploadedFileCompare(
                $_FILES['images']['tmp_name'][$i],
                $_FILES['images']['size'][$i]
            );
            if ($result['error']) {
                response(false, null, "Imagem " . ($i + 1) . ": " . $result['error'], 400);
            }

            if (!empty($result['text_fallback'])) {
                $userText = ($userText ?? '') . "\n" . $result['text_fallback'];
            } else {
                foreach ($result['images'] as $img) {
                    $base64Images[] = $img;
                }
            }
        }

        if (!empty($base64Images)) {
            $mode = 'image';
            $pageCount = count($base64Images);
        } elseif (!empty($userText)) {
            $mode = 'text';
        }
    }

    // 2. Single image (image)
    if ($mode === null && !empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $result = processUploadedFileCompare(
            $_FILES['image']['tmp_name'],
            $_FILES['image']['size']
        );
        if ($result['error']) {
            response(false, null, $result['error'], 400);
        }

        if (!empty($result['text_fallback'])) {
            $userText = $result['text_fallback'];
            $mode = 'text';
        } else {
            $base64Images = $result['images'];
            $mode = 'image';
            $pageCount = count($base64Images);
        }
    }

    // 3. PDF upload (pdf field)
    if ($mode === null && !empty($_FILES['pdf']) && $_FILES['pdf']['error'] === UPLOAD_ERR_OK) {
        $result = processUploadedFileCompare(
            $_FILES['pdf']['tmp_name'],
            $_FILES['pdf']['size']
        );
        if ($result['error']) {
            response(false, null, $result['error'], 400);
        }

        if (!empty($result['text_fallback'])) {
            $userText = $result['text_fallback'];
            $mode = 'text';
        } else {
            $base64Images = $result['images'];
            $mode = 'image';
            $pageCount = count($base64Images);
        }
    }

    // 4. Text input
    if ($mode === null) {
        $input = getInput();
        $userText = trim($input['text'] ?? '');
        if (empty($userText) && !empty($_POST['text'])) {
            $userText = trim($_POST['text']);
        }
        if (!empty($userText)) {
            $mode = 'text';
        }
    }

    if ($mode === null) {
        response(false, null, "Envie imagem(ns) do cardapio (campo 'image' ou 'images[]'), um PDF (campo 'pdf'), ou texto (campo 'text').", 400);
    }

    // ── Step 1: Extract products from the new menu using Claude ──
    $extractionPrompt = buildExtractionSystemPrompt();
    $claude = new ClaudeClient(CLAUDE_MODEL, 180);

    if ($mode === 'image') {
        $analysisPrompt = "Extraia todos os produtos deste cardapio em formato JSON. Liste nome, descricao, preco e categoria de cada item.";
        if ($pageCount > 1) {
            $analysisPrompt .= " Este cardapio tem {$pageCount} paginas. Consolide em um unico JSON.";
        }
        $extractionResult = $claude->sendWithVision($extractionPrompt, $base64Images, $analysisPrompt, CLAUDE_MAX_TOKENS);
    } else {
        $messages = [['role' => 'user', 'content' => "Extraia todos os produtos deste cardapio:\n\n" . $userText]];
        $extractionResult = $claude->send($extractionPrompt, $messages, CLAUDE_MAX_TOKENS);
    }

    if (!$extractionResult['success']) {
        error_log("[partner/ai-menu-compare] Claude extraction error for partner {$partnerId}: " . ($extractionResult['error'] ?? 'unknown'));
        response(false, null, "Erro ao analisar o cardapio. Tente novamente.", 500);
    }

    $newMenuRaw = $extractionResult['text'];
    $newMenuParsed = ClaudeClient::parseJson($newMenuRaw);

    if (!$newMenuParsed) {
        error_log("[partner/ai-menu-compare] Failed to parse extraction for partner {$partnerId}: " . substr($newMenuRaw, 0, 500));
        response(false, ['raw_response' => $newMenuRaw], "Nao foi possivel extrair produtos do cardapio.", 422);
    }

    // Flatten new menu products
    $newProducts = [];
    $categories = $newMenuParsed['categories'] ?? $newMenuParsed['produtos'] ?? $newMenuParsed;
    if (isset($newMenuParsed['categories'])) {
        foreach ($newMenuParsed['categories'] as $cat) {
            foreach ($cat['products'] ?? $cat['produtos'] ?? [] as $p) {
                $newProducts[] = [
                    'name' => trim($p['name'] ?? $p['nome'] ?? ''),
                    'category' => trim($cat['name'] ?? $cat['nome'] ?? ''),
                    'price' => round((float)($p['price'] ?? $p['preco'] ?? 0), 2),
                    'description' => trim($p['description'] ?? $p['descricao'] ?? ''),
                ];
            }
        }
    } elseif (is_array($newMenuParsed) && isset($newMenuParsed[0])) {
        foreach ($newMenuParsed as $p) {
            $newProducts[] = [
                'name' => trim($p['name'] ?? $p['nome'] ?? ''),
                'category' => trim($p['category'] ?? $p['categoria'] ?? ''),
                'price' => round((float)($p['price'] ?? $p['preco'] ?? 0), 2),
                'description' => trim($p['description'] ?? $p['descricao'] ?? ''),
            ];
        }
    }

    if (empty($newProducts)) {
        response(false, null, "Nenhum produto encontrado no cardapio enviado.", 400);
    }

    // ── Step 2: Fetch existing products for this partner ──
    $stmt = $db->prepare("
        SELECT pb.product_id, pb.name, pb.description, pp.price, c.name as category_name
        FROM om_market_products_base pb
        JOIN om_market_products_price pp ON pb.product_id = pp.product_id AND pp.partner_id = ?
        LEFT JOIN om_market_categories c ON pb.category_id = c.category_id
        WHERE pp.status = 1
        ORDER BY c.name, pb.name
    ");
    $stmt->execute([$partnerId]);
    $existingProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format existing products for comparison
    $existingFormatted = [];
    foreach ($existingProducts as $ep) {
        $existingFormatted[] = [
            'product_id' => (int)$ep['product_id'],
            'name' => $ep['name'],
            'description' => $ep['description'] ?? '',
            'price' => round((float)$ep['price'], 2),
            'category' => $ep['category_name'] ?? '',
        ];
    }

    // ── Step 3: Send both lists to Claude for comparison ──
    $comparisonPrompt = buildComparisonSystemPrompt();
    $comparisonMessage = "## CARDAPIO NOVO (extraido):\n" . json_encode($newProducts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $comparisonMessage .= "\n\n## CARDAPIO EXISTENTE (banco de dados):\n" . json_encode($existingFormatted, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $comparisonMessages = [['role' => 'user', 'content' => $comparisonMessage]];
    $comparisonResult = $claude->send($comparisonPrompt, $comparisonMessages, CLAUDE_MAX_TOKENS);

    if (!$comparisonResult['success']) {
        error_log("[partner/ai-menu-compare] Claude comparison error for partner {$partnerId}: " . ($comparisonResult['error'] ?? 'unknown'));
        response(false, null, "Erro ao comparar cardapios. Tente novamente.", 500);
    }

    $comparisonParsed = ClaudeClient::parseJson($comparisonResult['text']);

    if (!$comparisonParsed) {
        error_log("[partner/ai-menu-compare] Failed to parse comparison for partner {$partnerId}: " . substr($comparisonResult['text'], 0, 500));
        response(false, ['raw_response' => $comparisonResult['text']], "Nao foi possivel estruturar a comparacao.", 422);
    }

    // Validate and sanitize comparison result
    $diff = [
        'new_items' => [],
        'removed_items' => [],
        'price_changes' => [],
        'name_changes' => [],
        'unchanged' => [],
    ];

    foreach ($comparisonParsed['new_items'] ?? [] as $item) {
        $diff['new_items'][] = [
            'name' => trim($item['name'] ?? ''),
            'category' => trim($item['category'] ?? ''),
            'price' => round((float)($item['price'] ?? 0), 2),
        ];
    }

    foreach ($comparisonParsed['removed_items'] ?? [] as $item) {
        $diff['removed_items'][] = [
            'product_id' => (int)($item['product_id'] ?? 0),
            'name' => trim($item['name'] ?? ''),
            'category' => trim($item['category'] ?? ''),
        ];
    }

    foreach ($comparisonParsed['price_changes'] ?? [] as $item) {
        $diff['price_changes'][] = [
            'product_id' => (int)($item['product_id'] ?? 0),
            'name' => trim($item['name'] ?? ''),
            'old_price' => round((float)($item['old_price'] ?? 0), 2),
            'new_price' => round((float)($item['new_price'] ?? 0), 2),
        ];
    }

    foreach ($comparisonParsed['name_changes'] ?? [] as $item) {
        $diff['name_changes'][] = [
            'product_id' => (int)($item['product_id'] ?? 0),
            'old_name' => trim($item['old_name'] ?? ''),
            'new_name' => trim($item['new_name'] ?? ''),
        ];
    }

    foreach ($comparisonParsed['unchanged'] ?? [] as $item) {
        $diff['unchanged'][] = [
            'product_id' => (int)($item['product_id'] ?? 0),
            'name' => trim($item['name'] ?? ''),
        ];
    }

    // ── Log session ──
    $totalTokens = ($extractionResult['total_tokens'] ?? 0) + ($comparisonResult['total_tokens'] ?? 0);
    try {
        $stmtLog = $db->prepare("
            INSERT INTO om_ai_menu_sessions (partner_id, session_type, input_data, result_data, status, tokens_used, page_count)
            VALUES (?, 'compare', ?, ?, 'completed', ?, ?)
        ");
        $stmtLog->execute([
            $partnerId,
            json_encode([
                'mode' => $mode,
                'page_count' => $pageCount,
                'new_products_extracted' => count($newProducts),
                'existing_products' => count($existingProducts),
            ]),
            json_encode([
                'new_items' => count($diff['new_items']),
                'removed_items' => count($diff['removed_items']),
                'price_changes' => count($diff['price_changes']),
                'name_changes' => count($diff['name_changes']),
                'unchanged' => count($diff['unchanged']),
            ]),
            $totalTokens,
            $pageCount,
        ]);
    } catch (Exception $e) {
        error_log("[partner/ai-menu-compare] Log error: " . $e->getMessage());
    }

    // ── Return comparison result ──
    response(true, [
        'diff' => $diff,
        'summary' => [
            'new_items' => count($diff['new_items']),
            'removed_items' => count($diff['removed_items']),
            'price_changes' => count($diff['price_changes']),
            'name_changes' => count($diff['name_changes']),
            'unchanged' => count($diff['unchanged']),
        ],
        'existing_product_count' => count($existingProducts),
        'new_menu_product_count' => count($newProducts),
        'tokens_used' => $totalTokens,
    ], "Comparacao concluida com sucesso!");

} catch (Exception $e) {
    error_log("[partner/ai-menu-compare] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

// =====================================================================
// Helper Functions
// =====================================================================

/**
 * Process an uploaded file into base64 images array (same as ai-menu.php)
 */
function processUploadedFileCompare(string $tmpPath, int $fileSize): array {
    if ($fileSize > MAX_IMAGE_SIZE) {
        return ['error' => "Arquivo muito grande. Maximo " . (MAX_IMAGE_SIZE / 1024 / 1024) . " MB.", 'images' => []];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $finfo->file($tmpPath);

    if (!in_array($detectedMime, ALLOWED_MIME_TYPES, true)) {
        return ['error' => "Tipo nao suportado ({$detectedMime}). Envie JPEG, PNG, WebP, GIF ou PDF.", 'images' => []];
    }

    // PDF handling
    if ($detectedMime === 'application/pdf') {
        return processPdfFileCompare($tmpPath);
    }

    // Regular image
    $imageData = file_get_contents($tmpPath);
    if ($imageData === false || strlen($imageData) === 0) {
        return ['error' => "Falha ao ler o arquivo.", 'images' => []];
    }

    return [
        'error' => null,
        'images' => [['data' => base64_encode($imageData), 'mime' => $detectedMime]]
    ];
}

/**
 * Convert PDF to images using Imagick, with pdftotext fallback
 */
function processPdfFileCompare(string $pdfPath): array {
    if (!class_exists('Imagick')) {
        $text = shell_exec("pdftotext " . escapeshellarg($pdfPath) . " - 2>/dev/null");
        if (!empty(trim($text ?? ''))) {
            return ['error' => null, 'images' => [], 'text_fallback' => trim($text)];
        }
        return ['error' => "PDF nao suportado (Imagick nao disponivel). Envie uma foto.", 'images' => []];
    }

    try {
        $imagick = new Imagick();
        $imagick->setResolution(200, 200);
        $imagick->readImage($pdfPath);
        $pageCount = min($imagick->getNumberImages(), MAX_PDF_PAGES);

        $images = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $imagick->setIteratorIndex($i);
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality(85);
            if ($imagick->getImageWidth() > 2000) {
                $imagick->resizeImage(2000, 0, Imagick::FILTER_LANCZOS, 1);
            }
            $images[] = ['data' => base64_encode($imagick->getImageBlob()), 'mime' => 'image/jpeg'];
        }

        $imagick->destroy();
        return ['error' => null, 'images' => $images];
    } catch (Exception $e) {
        error_log("[ai-menu-compare] PDF conversion error: " . $e->getMessage());
        $text = shell_exec("pdftotext " . escapeshellarg($pdfPath) . " - 2>/dev/null");
        if (!empty(trim($text ?? ''))) {
            return ['error' => null, 'images' => [], 'text_fallback' => trim($text)];
        }
        return ['error' => "Erro ao processar PDF. Tente enviar fotos.", 'images' => []];
    }
}

/**
 * System prompt for extracting products from menu
 */
function buildExtractionSystemPrompt(): string {
    return <<<'PROMPT'
Voce e um especialista em analise de cardapios. Extraia TODOS os produtos e retorne JSON:

{
  "categories": [
    {
      "name": "Categoria",
      "products": [
        {"name": "Nome", "description": "Descricao", "price": 29.90}
      ]
    }
  ]
}

Regras:
1. Retorne APENAS JSON valido.
2. Extraia TODOS os produtos visiveis.
3. Precos como numeros decimais (29.90, nao "R$ 29,90").
4. Se preco nao visivel, use 0.
5. Agrupe em categorias logicas.
PROMPT;
}

/**
 * System prompt for comparing menus
 */
function buildComparisonSystemPrompt(): string {
    return <<<'PROMPT'
Compare o cardapio NOVO com o cardapio EXISTENTE. Retorne APENAS JSON valido:

{
  "new_items": [{"name": "...", "category": "...", "price": 0}],
  "removed_items": [{"product_id": 123, "name": "...", "category": "..."}],
  "price_changes": [{"product_id": 123, "name": "...", "old_price": 25.90, "new_price": 29.90}],
  "name_changes": [{"product_id": 123, "old_name": "...", "new_name": "..."}],
  "unchanged": [{"product_id": 123, "name": "..."}]
}

Regras:
1. Match produtos pelo NOME (ignorando acentos e maiusculas).
2. Se o nome mudou levemente (ex: "X-Burguer" → "X-Burger"), inclua em name_changes com o product_id do existente.
3. Se um produto existe no NOVO mas NAO no EXISTENTE, inclua em new_items.
4. Se um produto existe no EXISTENTE mas NAO no NOVO, inclua em removed_items com o product_id.
5. Se o preco mudou, inclua em price_changes com product_id, old_price e new_price.
6. Se nome e preco sao iguais, inclua em unchanged.
7. Um produto so deve aparecer em UMA lista (prioridade: name_changes > price_changes > unchanged).
8. Retorne SOMENTE o JSON. Nenhum texto adicional.
PROMPT;
}
