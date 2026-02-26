<?php
/**
 * POST /api/mercado/partner/ai-menu.php
 * Analisa cardapio (foto, PDF, multiplas fotos ou texto) usando Claude AI Vision
 * e retorna produtos estruturados para importacao.
 *
 * Supports:
 *   - Single image: field "image"
 *   - Multiple images: field "images[]"
 *   - PDF: field "image" or "pdf" (converted to images via Imagick)
 *   - Text: JSON field "text"
 *
 * Enhanced features:
 *   - Multi-page menu consolidation
 *   - Photo region detection (crop product photos from menu)
 *   - Handwriting recognition with confidence levels
 *   - Advanced price detection (combos, size-based, "a partir de")
 *   - Subcategory support
 *
 * Auth: Bearer token (partner type)
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/claude-client.php";
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

    $partnerId = $payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method !== 'POST') {
        response(false, null, "Metodo nao permitido. Use POST.", 405);
    }

    // --- Collect all images ---
    $base64Images = []; // [{data, mime}]
    $userText = null;
    $mode = null; // 'image' | 'text'
    $pageCount = 0;

    // 1. Check for multiple images (images[])
    if (!empty($_FILES['images']) && is_array($_FILES['images']['name'])) {
        $fileCount = count($_FILES['images']['name']);
        if ($fileCount > MAX_PAGES) {
            response(false, null, "Maximo de " . MAX_PAGES . " imagens por vez.", 400);
        }

        $totalSize = 0;
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) {
                if ($_FILES['images']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                response(false, null, "Erro no upload da imagem " . ($i + 1) . ".", 400);
            }

            $result = processUploadedFile(
                $_FILES['images']['tmp_name'][$i],
                $_FILES['images']['size'][$i]
            );
            if ($result['error']) {
                response(false, null, "Imagem " . ($i + 1) . ": " . $result['error'], 400);
            }

            foreach ($result['images'] as $img) {
                $base64Images[] = $img;
                $totalSize += strlen(base64_decode($img['data']));
            }
        }

        if (!empty($base64Images)) {
            $mode = 'image';
            $pageCount = count($base64Images);
        }
    }

    // 2. Check for single image (image) - backward compatible
    if ($mode === null && !empty($_FILES['image'])) {
        if ($_FILES['image']['error'] !== UPLOAD_ERR_OK && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE   => 'Imagem muito grande. Maximo permitido pelo servidor.',
                UPLOAD_ERR_FORM_SIZE  => 'Imagem excede o tamanho maximo do formulario.',
                UPLOAD_ERR_PARTIAL    => 'Upload incompleto. Tente novamente.',
                UPLOAD_ERR_NO_TMP_DIR => 'Erro no servidor (tmp dir). Contate o suporte.',
                UPLOAD_ERR_CANT_WRITE => 'Erro ao salvar arquivo. Contate o suporte.',
            ];
            $errMsg = $uploadErrors[$_FILES['image']['error']] ?? 'Erro no upload da imagem.';
            response(false, null, $errMsg, 400);
        }

        if ($_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $result = processUploadedFile(
                $_FILES['image']['tmp_name'],
                $_FILES['image']['size']
            );
            if ($result['error']) {
                response(false, null, $result['error'], 400);
            }

            $base64Images = $result['images'];
            $mode = 'image';
            $pageCount = count($base64Images);
        }
    }

    // 3. Check for PDF upload (pdf field)
    if ($mode === null && !empty($_FILES['pdf']) && $_FILES['pdf']['error'] === UPLOAD_ERR_OK) {
        $result = processUploadedFile(
            $_FILES['pdf']['tmp_name'],
            $_FILES['pdf']['size']
        );
        if ($result['error']) {
            response(false, null, $result['error'], 400);
        }

        $base64Images = $result['images'];
        $mode = 'image';
        $pageCount = count($base64Images);
    }

    // 4. Check for text input
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

    // --- Build Claude API request ---
    $systemPrompt = buildMenuSystemPrompt($pageCount);
    $claude = new ClaudeClient(CLAUDE_MODEL, 180); // 3 min timeout for large menus

    if ($mode === 'image') {
        $analysisPrompt = "Analise este cardapio e extraia todos os produtos em formato JSON conforme as instrucoes do sistema.";
        if ($pageCount > 1) {
            $analysisPrompt .= " Este cardapio tem {$pageCount} paginas. Consolide todas as paginas em um unico JSON.";
        }
        $claudeResult = $claude->sendWithVision($systemPrompt, $base64Images, $analysisPrompt, CLAUDE_MAX_TOKENS);
    } else {
        $analysisPrompt = "Analise este cardapio e extraia todos os produtos em formato JSON conforme as instrucoes do sistema.\n\n" . $userText;
        $messages = [['role' => 'user', 'content' => $analysisPrompt]];
        $claudeResult = $claude->send($systemPrompt, $messages, CLAUDE_MAX_TOKENS);
    }

    if (!$claudeResult['success']) {
        error_log("[partner/ai-menu] Claude API error for partner {$partnerId}: " . ($claudeResult['error'] ?? 'unknown'));
        response(false, null, "Erro ao analisar o cardapio. Tente novamente.", 500);
    }

    // --- Parse Claude response as JSON ---
    $rawResponse = $claudeResult['text'];
    $parsed = parseMenuResponse($rawResponse);

    if ($parsed === null) {
        error_log("[partner/ai-menu] Failed to parse Claude JSON for partner {$partnerId}. Raw: " . substr($rawResponse, 0, 500));
        response(false, [
            'raw_response' => $rawResponse
        ], "A IA retornou uma resposta, mas nao foi possivel estrutura-la. Revise manualmente.", 422);
    }

    // --- Log session ---
    try {
        $stmtLog = $db->prepare("
            INSERT INTO om_ai_menu_sessions (partner_id, session_type, input_data, result_data, status, tokens_used, page_count, photos_extracted)
            VALUES (?, 'import', ?, ?, 'completed', ?, ?, ?)
        ");
        $photosExtracted = countPhotosExtracted($parsed);
        $stmtLog->execute([
            $partnerId,
            json_encode(['mode' => $mode, 'page_count' => $pageCount]),
            json_encode(['total_products' => countProducts($parsed), 'total_categories' => count($parsed['categories'] ?? [])]),
            $claudeResult['total_tokens'] ?? 0,
            $pageCount,
            $photosExtracted
        ]);
    } catch (Exception $e) {
        error_log("[partner/ai-menu] Log error: " . $e->getMessage());
    }

    // --- Return structured data ---
    response(true, [
        'categories'       => $parsed['categories'] ?? [],
        'total_products'   => countProducts($parsed),
        'total_categories' => count($parsed['categories'] ?? []),
        'page_count'       => $pageCount,
        'photos_detected'  => $photosExtracted ?? 0,
        'model'            => $claudeResult['model'] ?? CLAUDE_MODEL,
        'tokens_used'      => $claudeResult['total_tokens'] ?? 0,
    ], "Cardapio analisado com sucesso!");

} catch (Exception $e) {
    error_log("[partner/ai-menu] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

// =====================================================================
// Helper Functions
// =====================================================================

/**
 * Process an uploaded file (image or PDF) into base64 images array
 * @return array{error: ?string, images: array}
 */
function processUploadedFile(string $tmpPath, int $fileSize): array {
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
        return processPdfFile($tmpPath);
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
 * Convert PDF pages to JPEG images using Imagick
 */
function processPdfFile(string $pdfPath): array {
    if (!class_exists('Imagick')) {
        // Fallback: try pdftotext
        $text = shell_exec("pdftotext " . escapeshellarg($pdfPath) . " - 2>/dev/null");
        if (!empty(trim($text ?? ''))) {
            // Return as a pseudo-image with text content
            return [
                'error' => null,
                'images' => [],
                'text_fallback' => trim($text)
            ];
        }
        return ['error' => "PDF nao suportado (Imagick nao disponivel). Envie uma foto em vez de PDF.", 'images' => []];
    }

    try {
        $imagick = new Imagick();
        $imagick->setResolution(200, 200);
        $imagick->readImage($pdfPath);
        $pageCount = $imagick->getNumberImages();

        if ($pageCount > MAX_PDF_PAGES) {
            $pageCount = MAX_PDF_PAGES;
        }

        $images = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $imagick->setIteratorIndex($i);
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality(85);

            // Limit resolution to avoid huge base64 payloads
            $width = $imagick->getImageWidth();
            if ($width > 2000) {
                $imagick->resizeImage(2000, 0, Imagick::FILTER_LANCZOS, 1);
            }

            $jpegData = $imagick->getImageBlob();
            $images[] = ['data' => base64_encode($jpegData), 'mime' => 'image/jpeg'];
        }

        $imagick->destroy();
        return ['error' => null, 'images' => $images];

    } catch (Exception $e) {
        error_log("[ai-menu] PDF conversion error: " . $e->getMessage());

        // Fallback to pdftotext
        $text = shell_exec("pdftotext " . escapeshellarg($pdfPath) . " - 2>/dev/null");
        if (!empty(trim($text ?? ''))) {
            return ['error' => null, 'images' => [], 'text_fallback' => trim($text)];
        }

        return ['error' => "Erro ao processar PDF. Tente enviar fotos em vez de PDF.", 'images' => []];
    }
}

/**
 * System prompt that instructs Claude how to analyze menus
 */
function buildMenuSystemPrompt(int $pageCount = 1): string {
    $prompt = "Voce e um especialista em analise de cardapios de restaurantes e delivery. ";
    $prompt .= "Sua tarefa e extrair TODOS os produtos do cardapio fornecido e retornar em formato JSON estruturado.\n\n";

    $prompt .= "## Regras OBRIGATORIAS:\n";
    $prompt .= "1. Retorne APENAS JSON valido. NAO use blocos de codigo markdown. NAO adicione texto antes ou depois do JSON.\n";
    $prompt .= "2. Extraia TODOS os produtos visiveis no cardapio.\n";
    $prompt .= "3. Se o preco nao estiver visivel, use 0 como valor.\n";
    $prompt .= "4. Se a descricao nao estiver clara, deixe como string vazia.\n";
    $prompt .= "5. Agrupe os produtos em categorias logicas. Se o cardapio ja tiver categorias, use-as.\n";
    $prompt .= "6. Se houver opcoes/variacoes (tamanhos, sabores, adicionais), inclua no campo 'options'.\n";
    $prompt .= "7. Precos devem ser numeros decimais (ex: 29.90, nao \"R$ 29,90\").\n";
    $prompt .= "8. price_modifier e a diferenca de preco em relacao ao preco base. Se nao houver diferenca, use 0.\n\n";

    // Multi-page instructions
    if ($pageCount > 1) {
        $prompt .= "## MULTIPLAS PAGINAS:\n";
        $prompt .= "- Este cardapio tem {$pageCount} paginas/fotos. Trate como UM UNICO cardapio.\n";
        $prompt .= "- Consolide categorias iguais ou similares entre paginas (ex: 'Pizzas' na pagina 1 e 'Pizzas' na pagina 2 devem ser UMA categoria).\n";
        $prompt .= "- Se uma categoria comeca em uma pagina e continua na proxima, merge os produtos.\n\n";
    }

    // Enhanced price detection
    $prompt .= "## PRECOS AVANCADOS:\n";
    $prompt .= "- \"a partir de R\$X\" → use X como preco e adicione \"price_note\": \"a partir de\"\n";
    $prompt .= "- Tamanhos P/M/G com precos diferentes → crie options group \"Tamanho\" com price_modifier relativo ao menor\n";
    $prompt .= "- Se o item for combo/kit → adicione \"is_combo\": true\n";
    $prompt .= "- Meia pizza / meio a meio → use options group \"Sabores\" com required: true\n\n";

    // Photo detection
    $prompt .= "## FOTOS DE PRODUTOS:\n";
    $prompt .= "- Se o cardapio contiver FOTOS de produtos visiveis na imagem, identifique cada foto.\n";
    $prompt .= "- Para cada produto com foto visivel, retorne \"photo_region\": {\"x\": pixels, \"y\": pixels, \"width\": pixels, \"height\": pixels}\n";
    $prompt .= "- As coordenadas devem ser em pixels relativos a imagem original.\n";
    $prompt .= "- Se o produto NAO tiver foto visivel, use \"photo_region\": null\n\n";

    // Handwriting
    $prompt .= "## CARDAPIOS MANUSCRITOS:\n";
    $prompt .= "- Se o cardapio for escrito a mao, faca o melhor esforco para ler.\n";
    $prompt .= "- Para itens com leitura duvidosa, adicione \"confidence\": \"low\" e \"uncertain_text\": \"texto como lido\"\n";
    $prompt .= "- Para itens claros, use \"confidence\": \"high\"\n\n";

    // Subcategories
    $prompt .= "## SUBCATEGORIAS:\n";
    $prompt .= "- Se detectar hierarquia (ex: Pizzas > Salgadas / Doces), use \"subcategories\" dentro da categoria.\n";
    $prompt .= "- Subcategorias tem a mesma estrutura: {name, products[]}\n\n";

    // JSON structure
    $prompt .= "## Estrutura JSON esperada:\n";
    $prompt .= "{\n";
    $prompt .= "  \"categories\": [\n";
    $prompt .= "    {\n";
    $prompt .= "      \"name\": \"Nome da Categoria\",\n";
    $prompt .= "      \"subcategories\": [],\n";
    $prompt .= "      \"products\": [\n";
    $prompt .= "        {\n";
    $prompt .= "          \"name\": \"Nome do Produto\",\n";
    $prompt .= "          \"description\": \"Descricao do produto\",\n";
    $prompt .= "          \"price\": 29.90,\n";
    $prompt .= "          \"price_note\": null,\n";
    $prompt .= "          \"is_combo\": false,\n";
    $prompt .= "          \"confidence\": \"high\",\n";
    $prompt .= "          \"uncertain_text\": null,\n";
    $prompt .= "          \"photo_region\": null,\n";
    $prompt .= "          \"options\": [\n";
    $prompt .= "            {\n";
    $prompt .= "              \"group_name\": \"Tamanho\",\n";
    $prompt .= "              \"required\": true,\n";
    $prompt .= "              \"options\": [\n";
    $prompt .= "                { \"name\": \"P\", \"price_modifier\": 0 },\n";
    $prompt .= "                { \"name\": \"G\", \"price_modifier\": 5.00 }\n";
    $prompt .= "              ]\n";
    $prompt .= "            }\n";
    $prompt .= "          ]\n";
    $prompt .= "        }\n";
    $prompt .= "      ]\n";
    $prompt .= "    }\n";
    $prompt .= "  ]\n";
    $prompt .= "}\n\n";

    $prompt .= "Se o produto nao tiver opcoes, use um array vazio: \"options\": []\n";
    $prompt .= "Responda SOMENTE com o JSON. Nenhum texto adicional.";

    return $prompt;
}

/**
 * Parse the Claude response text as JSON.
 */
function parseMenuResponse(string $raw): ?array {
    $parsed = ClaudeClient::parseJson($raw);

    if ($parsed === null) return null;

    // Validate basic structure: must have "categories" array
    if (!isset($parsed['categories']) || !is_array($parsed['categories'])) {
        if (isset($parsed[0]) && is_array($parsed[0])) {
            $parsed = ['categories' => [['name' => 'Geral', 'products' => $parsed]]];
        } else {
            return null;
        }
    }

    // Sanitize and normalize
    foreach ($parsed['categories'] as &$category) {
        $category['name'] = trim($category['name'] ?? 'Sem categoria');

        // Handle subcategories — flatten into main categories for now
        if (!empty($category['subcategories']) && is_array($category['subcategories'])) {
            foreach ($category['subcategories'] as $sub) {
                $subName = trim($sub['name'] ?? '');
                $subProducts = $sub['products'] ?? [];
                if (!empty($subName) && !empty($subProducts)) {
                    // Add as separate category with parent reference
                    $parsed['categories'][] = [
                        'name' => $category['name'] . ' - ' . $subName,
                        'parent_name' => $category['name'],
                        'products' => $subProducts,
                    ];
                }
            }
            // Move products that were directly in parent
            if (empty($category['products']) || !is_array($category['products'])) {
                // Remove empty parent (products are in subcategories)
                $category['_remove'] = true;
            }
        }

        if (!isset($category['products']) || !is_array($category['products'])) {
            $category['products'] = [];
        }

        foreach ($category['products'] as &$product) {
            $product['name'] = trim($product['name'] ?? '');
            $product['description'] = trim($product['description'] ?? '');
            $product['price'] = round((float)($product['price'] ?? 0), 2);
            $product['price_note'] = trim($product['price_note'] ?? '') ?: null;
            $product['is_combo'] = (bool)($product['is_combo'] ?? false);
            $product['confidence'] = in_array($product['confidence'] ?? 'high', ['high', 'low']) ? ($product['confidence'] ?? 'high') : 'high';
            $product['uncertain_text'] = trim($product['uncertain_text'] ?? '') ?: null;

            // Photo region validation
            if (isset($product['photo_region']) && is_array($product['photo_region'])) {
                $region = $product['photo_region'];
                if (isset($region['x'], $region['y'], $region['width'], $region['height'])
                    && $region['width'] > 10 && $region['height'] > 10) {
                    $product['photo_region'] = [
                        'x' => max(0, (int)$region['x']),
                        'y' => max(0, (int)$region['y']),
                        'width' => (int)$region['width'],
                        'height' => (int)$region['height'],
                    ];
                } else {
                    $product['photo_region'] = null;
                }
            } else {
                $product['photo_region'] = null;
            }

            if (!isset($product['options']) || !is_array($product['options'])) {
                $product['options'] = [];
            }
            foreach ($product['options'] as &$optGroup) {
                $optGroup['group_name'] = trim($optGroup['group_name'] ?? '');
                $optGroup['required'] = (bool)($optGroup['required'] ?? false);
                if (!isset($optGroup['options']) || !is_array($optGroup['options'])) {
                    $optGroup['options'] = [];
                }
                foreach ($optGroup['options'] as &$opt) {
                    $opt['name'] = trim($opt['name'] ?? '');
                    $opt['price_modifier'] = round((float)($opt['price_modifier'] ?? 0), 2);
                }
                unset($opt);
            }
            unset($optGroup);
        }
        unset($product);
    }
    unset($category);

    // Remove empty parent categories (products moved to subcategories)
    $parsed['categories'] = array_values(array_filter($parsed['categories'], fn($c) => empty($c['_remove'])));

    return $parsed;
}

/**
 * Count total products across all categories
 */
function countProducts(array $parsed): int {
    $count = 0;
    foreach ($parsed['categories'] ?? [] as $category) {
        $count += count($category['products'] ?? []);
    }
    return $count;
}

/**
 * Count products with photo_region detected
 */
function countPhotosExtracted(array $parsed): int {
    $count = 0;
    foreach ($parsed['categories'] ?? [] as $category) {
        foreach ($category['products'] ?? [] as $product) {
            if (!empty($product['photo_region'])) $count++;
        }
    }
    return $count;
}
