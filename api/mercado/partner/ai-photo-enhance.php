<?php
/**
 * POST /api/mercado/partner/ai-photo-enhance.php
 * Analyzes a product photo using Claude Vision and returns:
 *   - Quality score (1-10)
 *   - Lighting, composition, background assessments
 *   - Specific improvement suggestions (in Portuguese)
 *   - Auto-generated product description from the photo
 *   - Suggested tags/categories
 *
 * Auth: Bearer token (partner type)
 *
 * Body (multipart/form-data):
 *   image   — product photo (JPEG/PNG/WebP, max 10 MB)
 *   product_id (optional) — link analysis to existing product
 *   product_name (optional) — helps Claude produce better descriptions
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/claude-client.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

const MAX_PHOTO_SIZE  = 10 * 1024 * 1024; // 10 MB
const PHOTO_ALLOWED   = ['image/jpeg', 'image/png', 'image/webp'];
const CLAUDE_MODEL    = 'claude-sonnet-4-20250514';

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

    // --- Validate upload ---
    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'Imagem muito grande.',
            UPLOAD_ERR_FORM_SIZE  => 'Imagem excede o tamanho maximo.',
            UPLOAD_ERR_PARTIAL    => 'Upload incompleto. Tente novamente.',
            UPLOAD_ERR_NO_FILE    => 'Nenhuma imagem enviada. Envie no campo "image".',
            UPLOAD_ERR_NO_TMP_DIR => 'Erro no servidor.',
            UPLOAD_ERR_CANT_WRITE => 'Erro ao salvar arquivo.',
        ];
        $code = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
        response(false, null, $uploadErrors[$code] ?? 'Envie uma imagem no campo "image".', 400);
    }

    if ($_FILES['image']['size'] > MAX_PHOTO_SIZE) {
        response(false, null, "Imagem muito grande. Maximo 10 MB.", 400);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($_FILES['image']['tmp_name']);
    if (!in_array($mime, PHOTO_ALLOWED, true)) {
        response(false, null, "Tipo nao suportado ({$mime}). Envie JPEG, PNG ou WebP.", 400);
    }

    $imageData = file_get_contents($_FILES['image']['tmp_name']);
    if ($imageData === false || strlen($imageData) === 0) {
        response(false, null, "Falha ao ler a imagem.", 400);
    }

    $productId   = (int)($_POST['product_id'] ?? 0);
    $productName = trim($_POST['product_name'] ?? '');

    // If product_id given, fetch product info for richer analysis
    $productInfo = '';
    if ($productId > 0) {
        $stmt = $db->prepare("
            SELECT p.name, p.description, p.category, p.brand, p.price, p.unit
            FROM om_market_products p
            WHERE p.product_id = ? AND p.partner_id = ?
            LIMIT 1
        ");
        $stmt->execute([$productId, $partnerId]);
        $row = $stmt->fetch();
        if (!$row) {
            // Try catalog model
            $stmt2 = $db->prepare("
                SELECT pb.name, pb.description, pb.brand, pb.unit, pp.price,
                       cat.name as category
                FROM om_market_products_price pp
                INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id
                LEFT JOIN om_market_categories cat ON cat.category_id = pb.category_id
                WHERE pp.product_id = ? AND pp.partner_id = ?
                LIMIT 1
            ");
            $stmt2->execute([$productId, $partnerId]);
            $row = $stmt2->fetch();
        }
        if ($row) {
            $productName = $productName ?: ($row['name'] ?? '');
            $parts = [];
            if (!empty($row['name']))        $parts[] = "Nome: {$row['name']}";
            if (!empty($row['category']))    $parts[] = "Categoria: {$row['category']}";
            if (!empty($row['brand']))       $parts[] = "Marca: {$row['brand']}";
            if (!empty($row['price']))       $parts[] = "Preco: R$ " . number_format((float)$row['price'], 2, ',', '.');
            if (!empty($row['unit']))        $parts[] = "Unidade: {$row['unit']}";
            if (!empty($row['description'])) $parts[] = "Descricao atual: {$row['description']}";
            $productInfo = implode("\n", $parts);
        }
    }

    // --- Build Claude prompt ---
    $systemPrompt  = "Voce e um especialista em fotografia de produtos para delivery de alimentos e supermercados. ";
    $systemPrompt .= "Analise a foto do produto e retorne uma avaliacao detalhada em JSON.\n\n";
    $systemPrompt .= "## Criterios de Avaliacao\n";
    $systemPrompt .= "Avalie cada aspecto de 1 a 10:\n";
    $systemPrompt .= "- **qualidade_geral**: Score geral da foto (1=pessima, 10=profissional)\n";
    $systemPrompt .= "- **iluminacao**: Qualidade da luz (escura, dura, natural, profissional)\n";
    $systemPrompt .= "- **composicao**: Enquadramento, angulo, centralizacao\n";
    $systemPrompt .= "- **fundo**: Fundo limpo? Distracao? Branco/neutro e ideal.\n";
    $systemPrompt .= "- **foco**: Nitidez do produto\n";
    $systemPrompt .= "- **apelo_visual**: Quanto a foto faz o cliente querer comprar\n\n";

    $systemPrompt .= "## Retorne este JSON exato:\n";
    $systemPrompt .= "{\n";
    $systemPrompt .= "  \"scores\": {\n";
    $systemPrompt .= "    \"qualidade_geral\": <1-10>,\n";
    $systemPrompt .= "    \"iluminacao\": <1-10>,\n";
    $systemPrompt .= "    \"composicao\": <1-10>,\n";
    $systemPrompt .= "    \"fundo\": <1-10>,\n";
    $systemPrompt .= "    \"foco\": <1-10>,\n";
    $systemPrompt .= "    \"apelo_visual\": <1-10>\n";
    $systemPrompt .= "  },\n";
    $systemPrompt .= "  \"iluminacao_detalhe\": \"descricao curta da iluminacao\",\n";
    $systemPrompt .= "  \"composicao_detalhe\": \"descricao curta da composicao\",\n";
    $systemPrompt .= "  \"fundo_detalhe\": \"descricao curta do fundo\",\n";
    $systemPrompt .= "  \"melhorias\": [\"sugestao 1\", \"sugestao 2\", ...],\n";
    $systemPrompt .= "  \"descricao_sugerida\": \"Descricao apetitosa do produto para o app (max 200 chars)\",\n";
    $systemPrompt .= "  \"tags\": [\"tag1\", \"tag2\", ...],\n";
    $systemPrompt .= "  \"categoria_sugerida\": \"Categoria mais adequada\",\n";
    $systemPrompt .= "  \"produto_identificado\": \"Nome do produto identificado na foto\",\n";
    $systemPrompt .= "  \"dicas_foto\": [\"Dica pratica 1 para refazer a foto\", \"Dica 2\", ...]\n";
    $systemPrompt .= "}\n\n";

    $systemPrompt .= "## Regras:\n";
    $systemPrompt .= "- Retorne APENAS o JSON, sem texto antes ou depois.\n";
    $systemPrompt .= "- Tudo em portugues brasileiro.\n";
    $systemPrompt .= "- melhorias: 3-6 sugestoes especificas e praticas.\n";
    $systemPrompt .= "- dicas_foto: 2-4 dicas simples para o parceiro melhorar a foto (ex: 'Use luz natural', 'Fundo branco').\n";
    $systemPrompt .= "- tags: 3-8 tags relevantes para busca (ex: 'sem gluten', 'vegano', 'gourmet').\n";
    $systemPrompt .= "- descricao_sugerida: Uma descricao apetitosa que faca o cliente querer comprar.\n";

    $textPrompt = "Analise esta foto de produto para delivery/supermercado.";
    if ($productName) {
        $textPrompt .= "\n\nProduto: {$productName}";
    }
    if ($productInfo) {
        $textPrompt .= "\n\nInformacoes do produto:\n{$productInfo}";
    }
    $textPrompt .= "\n\nRetorne a avaliacao completa em JSON conforme as instrucoes do sistema.";

    // --- Call Claude Vision ---
    $claude = new ClaudeClient(CLAUDE_MODEL, 120);
    $images = [['data' => base64_encode($imageData), 'mime' => $mime]];
    $claudeResult = $claude->sendWithVision($systemPrompt, $images, $textPrompt, 4096);

    if (!$claudeResult['success']) {
        error_log("[ai-photo-enhance] Claude error for partner {$partnerId}: " . ($claudeResult['error'] ?? 'unknown'));
        response(false, null, "Erro ao analisar a foto. Tente novamente.", 500);
    }

    // --- Parse response ---
    $parsed = ClaudeClient::parseJson($claudeResult['text']);

    if ($parsed === null) {
        error_log("[ai-photo-enhance] Parse failed for partner {$partnerId}. Raw: " . substr($claudeResult['text'], 0, 500));
        response(false, ['raw_response' => $claudeResult['text']], "A IA retornou uma resposta, mas nao foi possivel estrutura-la.", 422);
    }

    // Validate and sanitize scores
    $scoreKeys = ['qualidade_geral', 'iluminacao', 'composicao', 'fundo', 'foco', 'apelo_visual'];
    $scores = [];
    foreach ($scoreKeys as $key) {
        $val = (int)($parsed['scores'][$key] ?? 5);
        $scores[$key] = max(1, min(10, $val));
    }
    $parsed['scores'] = $scores;

    // Ensure arrays
    if (!is_array($parsed['melhorias'] ?? null)) $parsed['melhorias'] = [];
    if (!is_array($parsed['tags'] ?? null)) $parsed['tags'] = [];
    if (!is_array($parsed['dicas_foto'] ?? null)) $parsed['dicas_foto'] = [];

    // --- Log session ---
    try {
        $stmtLog = $db->prepare("
            INSERT INTO om_ai_menu_sessions (partner_id, session_type, input_data, result_data, status, tokens_used, page_count)
            VALUES (?, 'photo_enhance', ?, ?, 'completed', ?, 1)
        ");
        $stmtLog->execute([
            $partnerId,
            json_encode(['product_id' => $productId, 'product_name' => $productName]),
            json_encode(['score' => $scores['qualidade_geral'], 'suggestions' => count($parsed['melhorias'])]),
            $claudeResult['total_tokens'] ?? 0,
        ]);
    } catch (Exception $e) {
        error_log("[ai-photo-enhance] Log error: " . $e->getMessage());
    }

    response(true, [
        'scores'              => $parsed['scores'],
        'iluminacao_detalhe'  => $parsed['iluminacao_detalhe'] ?? '',
        'composicao_detalhe'  => $parsed['composicao_detalhe'] ?? '',
        'fundo_detalhe'       => $parsed['fundo_detalhe'] ?? '',
        'melhorias'           => $parsed['melhorias'],
        'descricao_sugerida'  => $parsed['descricao_sugerida'] ?? '',
        'tags'                => $parsed['tags'],
        'categoria_sugerida'  => $parsed['categoria_sugerida'] ?? '',
        'produto_identificado'=> $parsed['produto_identificado'] ?? '',
        'dicas_foto'          => $parsed['dicas_foto'],
        'tokens_used'         => $claudeResult['total_tokens'] ?? 0,
        'model'               => $claudeResult['model'] ?? CLAUDE_MODEL,
    ], "Foto analisada com sucesso!");

} catch (Exception $e) {
    error_log("[ai-photo-enhance] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
