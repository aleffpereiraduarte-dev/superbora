<?php
/**
 * POST /api/mercado/produtos/busca-visual.php
 * Body (multipart/form-data): image (file) OR JSON {"image_base64": "...", "mime": "image/jpeg"}
 *
 * Pipeline:
 *   1. Claude vision describes uploaded image (rich PT-BR text)
 *   2. OpenAI text-embedding-3-small embeds description (1536 dims)
 *   3. pgvector cosine search against om_market_products.embedding
 *   4. Return top 20 most visually similar products
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/claude-client.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Use POST', 405);
}

$OPENAI_KEY = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '';
if (!$OPENAI_KEY) response(false, null, 'Servico indisponivel', 503);

// ── 1. Parse image from request ──
$imgData = null;
$imgMime = 'image/jpeg';

if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
    $imgMime = mime_content_type($_FILES['image']['tmp_name']) ?: 'image/jpeg';
    $imgBinary = file_get_contents($_FILES['image']['tmp_name']);
    if (strlen($imgBinary) > 5 * 1024 * 1024) {
        response(false, null, 'Imagem muito grande (max 5MB)', 413);
    }
    $imgData = base64_encode($imgBinary);
} else {
    $body = json_decode(file_get_contents('php://input'), true);
    $b64 = $body['image_base64'] ?? '';
    if (preg_match('/^data:(image\/[a-z+]+);base64,(.+)$/', $b64, $m)) {
        $imgMime = $m[1];
        $b64 = $m[2];
    } else {
        $imgMime = $body['mime'] ?? 'image/jpeg';
    }
    if ($b64) $imgData = $b64;
}

if (!$imgData) {
    response(false, null, 'Imagem obrigatoria (multipart "image" ou JSON "image_base64")', 400);
}

if (!str_starts_with($imgMime, 'image/')) {
    response(false, null, 'Arquivo nao e imagem', 400);
}

// ── 2. Claude vision describes image ──
try {
    $claude = new ClaudeClient('claude-sonnet-4-20250514', 25, 0);
    $prompt = <<<'P'
Descreva esta foto de produto/comida em portugues brasileiro para busca por similaridade.
Inclua: tipo de produto, cor, textura, ingredientes visiveis, formato.
Responda em texto corrido, maximo 2 frases, direto ao ponto.
P;
    $r = $claude->sendWithVision($prompt, [['data' => $imgData, 'mime' => $imgMime]], '', 256);
    if (!$r['success']) {
        response(false, null, 'Nao consegui entender a imagem', 502);
    }
    $description = trim($r['text'] ?? '');
    if (mb_strlen($description) < 10) {
        response(false, null, 'Imagem nao reconhecida', 400);
    }
} catch (Exception $e) {
    error_log("[visual-search] claude vision: " . $e->getMessage());
    response(false, null, 'Erro ao processar imagem', 500);
}

// ── 3. OpenAI embeds the description ──
$ch = curl_init('https://api.openai.com/v1/embeddings');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $OPENAI_KEY, 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode([
        'model' => 'text-embedding-3-small',
        'input' => $description,
        'dimensions' => 1536,
    ]),
    CURLOPT_TIMEOUT => 15,
]);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200) {
    response(false, null, 'Servico de embedding indisponivel', 503);
}
$vec = json_decode($res, true)['data'][0]['embedding'] ?? null;
if (!$vec) response(false, null, 'Erro de embedding', 500);

$vectorLiteral = '[' . implode(',', array_map(fn($v) => sprintf('%.6f', $v), $vec)) . ']';

// ── 4. pgvector cosine similarity search ──
try {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT p.product_id, p.name, p.description, p.price, p.special_price, p.image,
               p.partner_id, p.unit,
               mp.name AS partner_name, mp.logo AS partner_logo, mp.rating AS partner_rating,
               mp.is_open AS partner_is_open,
               1 - (p.embedding <=> ?::vector) AS similarity
        FROM om_market_products p
        INNER JOIN om_market_partners mp ON mp.partner_id = p.partner_id AND mp.status::text = '1'
        WHERE p.status::text = '1'
          AND p.embedding IS NOT NULL
          AND p.image IS NOT NULL AND TRIM(p.image) != ''
        ORDER BY p.embedding <=> ?::vector
        LIMIT 20
    ");
    $stmt->execute([$vectorLiteral, $vectorLiteral]);
    $rows = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("[visual-search] pgvector: " . $e->getMessage());
    response(false, null, 'Erro na busca', 500);
}

// ── 5. Format response ──
$produtos = array_map(function($r) {
    return [
        'id' => (int)$r['product_id'],
        'nome' => $r['name'],
        'descricao' => $r['description'] ?? '',
        'preco' => (float)$r['price'],
        'preco_promo' => $r['special_price'] ? (float)$r['special_price'] : null,
        'imagem' => $r['image'],
        'unidade' => $r['unit'] ?? 'un',
        'parceiro_id' => (int)$r['partner_id'],
        'parceiro_nome' => $r['partner_name'],
        'parceiro_logo' => $r['partner_logo'],
        'parceiro_avaliacao' => (float)($r['partner_rating'] ?? 0),
        'parceiro_aberto' => (int)($r['partner_is_open'] ?? 0) === 1,
        'similarity' => round((float)$r['similarity'], 4),
    ];
}, $rows);

response(true, [
    'description' => $description,
    'total' => count($produtos),
    'produtos' => $produtos,
]);
