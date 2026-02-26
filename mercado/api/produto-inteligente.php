<?php
/**
 * ════════════════════════════════════════════════════════════════════════════════
 * ONEMUNDO MERCADO - API PRODUTO INTELIGENTE COM CLAUDE AI
 * ════════════════════════════════════════════════════════════════════════════════
 * Usa Claude AI para gerar descrições ricas, dicas, receitas e informações úteis
 * ════════════════════════════════════════════════════════════════════════════════
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Carregar configurações
require_once __DIR__ . '/../config/database.php';
require_once dirname(__DIR__) . '/includes/env_loader.php';

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexão com banco']);
    exit;
}

// Obter API Key da Claude
$claude_api_key = getenv('CLAUDE_API_KEY') ?: ($_ENV['CLAUDE_API_KEY'] ?? '');

if (empty($claude_api_key)) {
    // Tentar carregar do .env diretamente
    $env_file = dirname(__DIR__) . '/.env';
    if (file_exists($env_file)) {
        $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, 'CLAUDE_API_KEY=') === 0) {
                $claude_api_key = trim(substr($line, 15));
                break;
            }
        }
    }
}

// Validar produto
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$product_id) {
    echo json_encode(['success' => false, 'error' => 'ID do produto não informado']);
    exit;
}

session_start();
$partner_id = $_SESSION['market_partner_id'] ?? 4;

// Buscar produto
$stmt = $pdo->prepare("
    SELECT
        pb.product_id,
        pb.name,
        pb.brand,
        pb.barcode,
        pb.image,
        pb.unit,
        pb.description,
        pb.ingredients,
        pb.nutrition_json,
        pb.category_id,
        pb.ai_generated_at,
        pp.price,
        pp.price_promo,
        pp.stock,
        c.name as category_name
    FROM om_market_products_base pb
    JOIN om_market_products_price pp ON pb.product_id = pp.product_id
    LEFT JOIN om_market_categories c ON pb.category_id = c.category_id
    WHERE pb.product_id = ? AND pp.status = '1'
    LIMIT 1
");
$stmt->execute([$product_id]);
$product = $stmt->fetch();

if (!$product) {
    echo json_encode(['success' => false, 'error' => 'Produto não encontrado']);
    exit;
}

// Verificar cache de IA
$cache_table_exists = false;
try {
    $pdo->query("SELECT 1 FROM om_market_ai_cache LIMIT 1");
    $cache_table_exists = true;
} catch (Exception $e) {
    // Criar tabela de cache
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS om_market_ai_cache (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            ai_content TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY (product_id),
            INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $cache_table_exists = true;
}

// Verificar cache existente (válido por 7 dias)
$cached_content = null;
if ($cache_table_exists) {
    $stmt = $pdo->prepare("
        SELECT ai_content FROM om_market_ai_cache
        WHERE product_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stmt->execute([$product_id]);
    $cache = $stmt->fetch();
    if ($cache && $cache['ai_content']) {
        $cached_content = json_decode($cache['ai_content'], true);
    }
}

// Verificar se deve salvar no cache direto do produto
$save_to_product = isset($_GET['save_cache']) && $_GET['save_cache'] == '1';

// Verificar cache direto na tabela de produtos
$product_cache = null;
if (!empty($product['ai_generated_at'])) {
    // Verificar se tem dados nas colunas do produto (cache direto)
    $stmt = $pdo->prepare("SELECT ai_benefits, ai_tips, ai_combines, ai_recipe FROM om_market_products_base WHERE product_id = ?");
    $stmt->execute([$product_id]);
    $product_ai = $stmt->fetch();
    if ($product_ai && ($product_ai['ai_benefits'] || $product_ai['ai_tips'])) {
        $product_cache = [
            'beneficios' => $product_ai['ai_benefits'] ? json_decode($product_ai['ai_benefits'], true) : null,
            'dicas_uso' => $product_ai['ai_tips'] ? json_decode($product_ai['ai_tips'], true) : null,
            'harmonizacao' => $product_ai['ai_combines'] ? json_decode($product_ai['ai_combines'], true) : null,
            'receita_rapida' => $product_ai['ai_recipe'] ? json_decode($product_ai['ai_recipe'], true) : null
        ];
    }
}

// Gerar conteúdo com Claude se não tiver cache
$ai_content = $product_cache ?: $cached_content;

if (!$ai_content && !empty($claude_api_key)) {
    $ai_content = generateAIContent($product, $claude_api_key);

    // Salvar no cache antigo (tabela separada)
    if ($ai_content && $cache_table_exists) {
        $stmt = $pdo->prepare("
            INSERT INTO om_market_ai_cache (product_id, ai_content)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE ai_content = VALUES(ai_content), updated_at = NOW()
        ");
        $stmt->execute([$product_id, json_encode($ai_content, JSON_UNESCAPED_UNICODE)]);
    }

    // Salvar nas novas colunas do produto (cache direto) se solicitado
    if ($ai_content && $save_to_product) {
        try {
            $stmt = $pdo->prepare("
                UPDATE om_market_products_base SET
                    ai_benefits = ?,
                    ai_tips = ?,
                    ai_combines = ?,
                    ai_recipe = ?,
                    ai_generated_at = NOW()
                WHERE product_id = ?
            ");
            $stmt->execute([
                isset($ai_content['beneficios']) ? json_encode($ai_content['beneficios'], JSON_UNESCAPED_UNICODE) : null,
                isset($ai_content['dicas_uso']) ? json_encode($ai_content['dicas_uso'], JSON_UNESCAPED_UNICODE) : null,
                isset($ai_content['harmonizacao']) ? json_encode($ai_content['harmonizacao'], JSON_UNESCAPED_UNICODE) : null,
                isset($ai_content['receita_rapida']) ? json_encode($ai_content['receita_rapida'], JSON_UNESCAPED_UNICODE) : null,
                $product_id
            ]);
        } catch (Exception $e) {
            error_log("Erro ao salvar cache AI no produto: " . $e->getMessage());
        }
    }
}

// Buscar produtos relacionados
$related = [];
if ($product['category_id']) {
    $stmt = $pdo->prepare("
        SELECT pb.product_id, pb.name, pb.brand, pb.image, pp.price, pp.price_promo
        FROM om_market_products_base pb
        JOIN om_market_products_price pp ON pb.product_id = pp.product_id
        WHERE pb.category_id = ? AND pb.product_id != ? AND pp.status = '1' AND pp.price > 0
        ORDER BY RANDOM() LIMIT 8
    ");
    $stmt->execute([$product['category_id'], $product_id]);
    $related = $stmt->fetchAll();
}

// Formatar resposta
$product['price'] = (float)$product['price'];
$product['price_promo'] = (float)$product['price_promo'];
$product['stock'] = (int)$product['stock'];

if ($product['nutrition_json'] && is_string($product['nutrition_json'])) {
    $product['nutrition_json'] = json_decode($product['nutrition_json'], true);
}

foreach ($related as &$r) {
    $r['price'] = (float)$r['price'];
    $r['price_promo'] = (float)$r['price_promo'];
}

echo json_encode([
    'success' => true,
    'product' => $product,
    'ai_content' => $ai_content,
    'related' => $related
], JSON_UNESCAPED_UNICODE);

/**
 * Gera conteúdo inteligente usando Claude AI
 */
function generateAIContent($product, $api_key) {
    $prompt = "Você é um assistente especializado em produtos de supermercado. Analise o produto abaixo e gere conteúdo útil para o cliente.

PRODUTO:
- Nome: {$product['name']}
- Marca: " . ($product['brand'] ?: 'Não informada') . "
- Categoria: " . ($product['category_name'] ?: 'Não informada') . "
- Unidade: " . ($product['unit'] ?: 'Não informada') . "
- Ingredientes: " . ($product['ingredients'] ?: 'Não informados') . "

Responda APENAS em JSON válido com esta estrutura exata:
{
    \"descricao_rica\": \"Uma descrição atraente do produto em 2-3 frases\",
    \"beneficios\": [\"benefício 1\", \"benefício 2\", \"benefício 3\"],
    \"dicas_uso\": [\"dica 1\", \"dica 2\", \"dica 3\"],
    \"harmonizacao\": [\"sugestão 1\", \"sugestão 2\"],
    \"curiosidade\": \"Um fato interessante sobre o produto\",
    \"conservacao\": \"Como conservar o produto\",
    \"receita_rapida\": {
        \"nome\": \"Nome da receita\",
        \"ingredientes\": [\"ingrediente 1\", \"ingrediente 2\"],
        \"preparo\": \"Instruções rápidas de preparo\"
    },
    \"tags\": [\"tag1\", \"tag2\", \"tag3\"]
}

Se não souber algo específico sobre o produto, use informações genéricas baseadas na categoria. Seja criativo mas preciso.";

    $data = [
        'model' => 'claude-sonnet-4-20250514',
        'max_tokens' => 1024,
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ]
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01'
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        error_log("Claude API error: HTTP $http_code - $response");
        return getDefaultAIContent($product);
    }

    $result = json_decode($response, true);

    if (isset($result['content'][0]['text'])) {
        $text = $result['content'][0]['text'];

        // Extrair JSON da resposta
        if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            $ai_data = json_decode($matches[0], true);
            if ($ai_data) {
                return $ai_data;
            }
        }
    }

    return getDefaultAIContent($product);
}

/**
 * Conteúdo padrão caso a IA falhe
 */
function getDefaultAIContent($product) {
    $category = $product['category_name'] ?? 'Geral';

    return [
        'descricao_rica' => "Produto de qualidade da categoria {$category}. " . ($product['brand'] ? "Marca {$product['brand']} reconhecida no mercado." : "Selecionado especialmente para você."),
        'beneficios' => [
            'Produto de qualidade selecionada',
            'Ótimo custo-benefício',
            'Entrega rápida garantida'
        ],
        'dicas_uso' => [
            'Verifique sempre a data de validade',
            'Armazene conforme instruções da embalagem',
            'Consuma de acordo com suas preferências'
        ],
        'harmonizacao' => [
            'Combine com outros produtos da mesma categoria',
            'Experimente diferentes formas de preparo'
        ],
        'curiosidade' => "Produtos de qualidade fazem toda diferença na sua alimentação diária.",
        'conservacao' => 'Siga as instruções de conservação presentes na embalagem do produto.',
        'receita_rapida' => null,
        'tags' => [$category, 'Qualidade', 'Entrega Rápida']
    ];
}
