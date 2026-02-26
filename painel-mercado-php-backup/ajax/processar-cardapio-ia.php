<?php
/**
 * POST /painel/mercado/ajax/processar-cardapio-ia.php
 * Processa fotos de cardapio usando Claude Vision (Anthropic API)
 * Extrai: nome, descricao, ingredientes, preco, categoria
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo nao permitido']);
    exit;
}

if (!isset($_SESSION['mercado_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nao autenticado']);
    exit;
}

$partner_id = (int)$_SESSION['mercado_id'];

// Carregar .env
$envFile = dirname(__DIR__, 3) . '/.env';
$claudeApiKey = '';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        if (trim($key) === 'CLAUDE_API_KEY') {
            $claudeApiKey = trim($val);
            break;
        }
    }
}

if (empty($claudeApiKey)) {
    echo json_encode(['success' => false, 'message' => 'Chave da API Claude nao configurada']);
    exit;
}

// Verificar se tem arquivos
if (empty($_FILES['images'])) {
    echo json_encode(['success' => false, 'message' => 'Nenhuma imagem enviada']);
    exit;
}

$files = $_FILES['images'];
$maxFiles = 10;
$maxFileSize = 10 * 1024 * 1024; // 10MB
$allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

// Normalizar array de files
$fileCount = is_array($files['name']) ? count($files['name']) : 1;
if ($fileCount > $maxFiles) {
    echo json_encode(['success' => false, 'message' => "Maximo de $maxFiles imagens por vez"]);
    exit;
}

// Preparar imagens para Claude
$images = [];
for ($i = 0; $i < $fileCount; $i++) {
    $tmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
    $size = is_array($files['size']) ? $files['size'][$i] : $files['size'];
    $type = is_array($files['type']) ? $files['type'][$i] : $files['type'];
    $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];

    if ($error !== UPLOAD_ERR_OK) continue;
    if ($size > $maxFileSize) continue;

    // Verificar tipo real
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realType = finfo_file($finfo, $tmpName);
    finfo_close($finfo);

    if (!in_array($realType, $allowedTypes)) continue;

    $imageData = base64_encode(file_get_contents($tmpName));
    $mediaType = $realType;

    $images[] = [
        'type' => 'image',
        'source' => [
            'type' => 'base64',
            'media_type' => $mediaType,
            'data' => $imageData,
        ]
    ];
}

if (empty($images)) {
    echo json_encode(['success' => false, 'message' => 'Nenhuma imagem valida encontrada']);
    exit;
}

// Buscar categorias existentes do parceiro
require_once dirname(__DIR__, 3) . '/database.php';
$db = getDB();
$stmtCat = $db->prepare("SELECT category_id, name FROM om_market_categories WHERE partner_id = ? AND status = 1 ORDER BY name");
$stmtCat->execute([$partner_id]);
$categorias = $stmtCat->fetchAll(PDO::FETCH_ASSOC);
$catList = array_map(fn($c) => $c['name'], $categorias);
$catJson = !empty($catList) ? implode(', ', $catList) : 'Entradas, Pratos Principais, Bebidas, Sobremesas, Lanches, Acompanhamentos';

// Montar prompt para Claude
$promptText = <<<PROMPT
Voce e um especialista em analise de cardapios de restaurantes. Analise as imagens de cardapio enviadas e extraia TODOS os itens encontrados.

Para cada item do cardapio, retorne os seguintes campos:
- **nome**: Nome do prato/produto exatamente como aparece
- **descricao**: Descricao detalhada do prato (ingredientes, modo de preparo, acompanhamentos). Se nao houver descricao visivel, crie uma descricao atrativa e realista baseada no nome do prato.
- **ingredientes**: Lista dos principais ingredientes separados por virgula
- **preco**: Preco em reais (numero decimal, ex: 29.90). Se houver tamanhos diferentes, use o preco do tamanho padrao/medio. Se nao houver preco visivel, coloque 0.
- **categoria**: Categorize em uma destas categorias: {$catJson}. Se nenhuma se encaixar, sugira uma nova categoria.
- **tamanhos**: Se houver opcoes de tamanho (P, M, G, etc), liste como array de objetos com "nome" e "preco"
- **observacoes**: Qualquer informacao adicional relevante (vegetariano, sem gluten, picante, destaque, mais vendido, etc)

REGRAS IMPORTANTES:
1. Extraia TODOS os itens visiveis, nao pule nenhum
2. Precos devem ser numericos (sem R$, sem virgula como separador decimal - use ponto)
3. Se o preco usar virgula (ex: 29,90), converta para ponto (29.90)
4. Se um item tiver variantes de tamanho, liste o menor preco como preco principal
5. Descricoes devem ser em portugues brasileiro
6. Seja detalhado nas descricoes - inclua ingredientes e modo de preparo quando possivel
7. Se a imagem estiver borrada ou ilegivel em partes, extraia o que for possivel e indique com [ilegivel]

Retorne EXCLUSIVAMENTE um JSON valido neste formato (sem markdown, sem texto adicional):
{
  "items": [
    {
      "nome": "string",
      "descricao": "string",
      "ingredientes": "string",
      "preco": number,
      "categoria": "string",
      "tamanhos": [{"nome": "P", "preco": 19.90}, {"nome": "G", "preco": 29.90}] ou null,
      "observacoes": "string" ou null
    }
  ],
  "total_items": number,
  "categorias_encontradas": ["string"],
  "observacoes_gerais": "string ou null"
}
PROMPT;

// Montar conteudo da mensagem
$content = [];
foreach ($images as $img) {
    $content[] = $img;
}
$content[] = [
    'type' => 'text',
    'text' => $promptText
];

// Chamar Claude API
$payload = [
    'model' => 'claude-sonnet-4-20250514',
    'max_tokens' => 8192,
    'messages' => [
        [
            'role' => 'user',
            'content' => $content
        ]
    ]
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . $claudeApiKey,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_CONNECTTIMEOUT => 10,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    error_log("[cardapio-ia] Curl error: $curlError");
    echo json_encode(['success' => false, 'message' => 'Erro de conexao com a IA. Tente novamente.']);
    exit;
}

if ($httpCode !== 200) {
    error_log("[cardapio-ia] API HTTP $httpCode: $response");
    $errData = json_decode($response, true);
    $errMsg = $errData['error']['message'] ?? 'Erro na API da IA';
    echo json_encode(['success' => false, 'message' => "Erro da IA ($httpCode): $errMsg"]);
    exit;
}

$apiResult = json_decode($response, true);
if (!$apiResult || empty($apiResult['content'])) {
    echo json_encode(['success' => false, 'message' => 'Resposta vazia da IA']);
    exit;
}

// Extrair texto da resposta
$textContent = '';
foreach ($apiResult['content'] as $block) {
    if ($block['type'] === 'text') {
        $textContent .= $block['text'];
    }
}

// Limpar JSON (remover markdown code blocks se houver)
$textContent = trim($textContent);
if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $textContent, $m)) {
    $textContent = $m[1];
}

// Parse JSON
$menuData = json_decode($textContent, true);
if (!$menuData || !isset($menuData['items'])) {
    // Tentar extrair JSON de qualquer forma
    if (preg_match('/\{[\s\S]*"items"[\s\S]*\}/', $textContent, $m2)) {
        $menuData = json_decode($m2[0], true);
    }
    if (!$menuData || !isset($menuData['items'])) {
        error_log("[cardapio-ia] Falha ao parsear JSON: " . substr($textContent, 0, 500));
        echo json_encode([
            'success' => false,
            'message' => 'A IA nao retornou dados estruturados. Tente com uma foto mais nitida.',
            'raw_response' => substr($textContent, 0, 1000)
        ]);
        exit;
    }
}

// Enriquecer com IDs de categorias existentes
foreach ($menuData['items'] as &$item) {
    $item['categoria_id'] = null;
    foreach ($categorias as $cat) {
        if (stripos($cat['name'], $item['categoria']) !== false || stripos($item['categoria'], $cat['name']) !== false) {
            $item['categoria_id'] = (int)$cat['category_id'];
            break;
        }
    }
    // Garantir preco numerico
    $item['preco'] = (float)($item['preco'] ?? 0);
    // Limpar campos
    $item['nome'] = trim($item['nome'] ?? '');
    $item['descricao'] = trim($item['descricao'] ?? '');
    $item['ingredientes'] = trim($item['ingredientes'] ?? '');
}
unset($item);

// Log
error_log("[cardapio-ia] Parceiro $partner_id: " . count($menuData['items']) . " itens extraidos de $fileCount imagem(ns)");

echo json_encode([
    'success' => true,
    'message' => count($menuData['items']) . ' itens encontrados no cardapio!',
    'data' => $menuData,
    'categorias_existentes' => $categorias,
    'images_processed' => count($images)
]);
