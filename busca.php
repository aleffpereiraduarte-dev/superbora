<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║          🔍 BUSCA INTELIGENTE - ONEMUNDO MERCADO                             ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  Sistema de busca com AI que parece natural                                  ║
 * ║  - Entende linguagem natural ("algo pra fazer bolo")                        ║
 * ║  - Corrige erros de digitação                                                ║
 * ║  - Sugere produtos relacionados                                              ║
 * ║  - Cache inteligente para economia                                           ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

// ═══════════════════════════════════════════════════════════════════════════════
// CONFIGURAÇÃO
// ═══════════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/includes/env_loader.php';

define('OPENAI_KEY', env('OPENAI_API_KEY', ''));
define('GROQ_KEY', env('GROQ_API_KEY', ''));

// Banco de dados
try {
    $pdo = new PDO(
        "pgsql:host=" . env('DB_HOSTNAME', 'localhost') . ";dbname=" . env('DB_DATABASE', ''),
        env('DB_USERNAME', ''),
        env('DB_PASSWORD', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'error' => 'Erro de conexão']));
}

// Partner ID do mercado
$partner_id = 4;

// ═══════════════════════════════════════════════════════════════════════════════
// RECEBER BUSCA
// ═══════════════════════════════════════════════════════════════════════════════

$query = '';
$mode = 'search'; // search, suggest, analyze

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $query = trim($input['q'] ?? $input['query'] ?? '');
    $mode = $input['mode'] ?? 'search';
} else {
    $query = trim($_GET['q'] ?? $_GET['query'] ?? '');
    $mode = $_GET['mode'] ?? 'search';
}

if (empty($query) && $mode !== 'populares') {
    echo json_encode(['success' => false, 'error' => 'Digite algo para buscar']);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// FUNÇÕES DE AI
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Chamar Groq (Llama 3) - Rápido e gratuito
 */
function callGroq($prompt, $system = '') {
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    
    $messages = [];
    if ($system) {
        $messages[] = ['role' => 'system', 'content' => $system];
    }
    $messages[] = ['role' => 'user', 'content' => $prompt];
    
    $payload = [
        'model' => 'llama-3.3-70b-versatile',
        'messages' => $messages,
        'max_tokens' => 500,
        'temperature' => 0.3
    ];
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . GROQ_KEY
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        return $data['choices'][0]['message']['content'] ?? null;
    }
    
    return null;
}

/**
 * Chamar OpenAI (GPT-4o-mini) - Fallback
 */
function callOpenAI($prompt, $system = '') {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    
    $messages = [];
    if ($system) {
        $messages[] = ['role' => 'system', 'content' => $system];
    }
    $messages[] = ['role' => 'user', 'content' => $prompt];
    
    $payload = [
        'model' => 'gpt-4o-mini',
        'messages' => $messages,
        'max_tokens' => 500,
        'temperature' => 0.3
    ];
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_KEY
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 15
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        return $data['choices'][0]['message']['content'] ?? null;
    }
    
    return null;
}

/**
 * Chamar AI com fallback
 */
function callAI($prompt, $system = '') {
    // Primeiro tenta Groq (grátis e rápido)
    $result = callGroq($prompt, $system);
    
    // Se falhar, tenta OpenAI
    if (!$result) {
        $result = callOpenAI($prompt, $system);
    }
    
    return $result;
}

/**
 * Interpretar busca em linguagem natural
 */
function interpretarBusca($query) {
    global $pdo, $partner_id;
    
    // Primeiro: verificar cache
    $cacheKey = md5(strtolower(trim($query)));
    $stmt = $pdo->prepare("SELECT termos, categoria_sugerida, created_at FROM om_busca_cache WHERE cache_key = ? AND created_at > NOW() - INTERVAL '7 days'");
    $stmt->execute([$cacheKey]);
    $cached = $stmt->fetch();
    
    if ($cached) {
        return [
            'termos' => json_decode($cached['termos'], true),
            'categoria' => $cached['categoria_sugerida'],
            'cached' => true
        ];
    }
    
    // Buscar categorias disponíveis para contexto
    $categorias = $pdo->query("SELECT name FROM om_market_categories WHERE status = 1 LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);
    $catList = implode(', ', $categorias);
    
    // Prompt para a AI
    $system = "Você é um assistente de supermercado brasileiro. Sua função é interpretar o que o cliente quer e retornar termos de busca.

REGRAS:
1. Retorne APENAS JSON válido, nada mais
2. Corrija erros de digitação
3. Expanda abreviações (refri = refrigerante)
4. Entenda contexto (fazer bolo = farinha, açúcar, ovos, fermento)
5. Use termos comuns de supermercado brasileiro

Categorias disponíveis: {$catList}

FORMATO OBRIGATÓRIO:
{\"termos\": [\"termo1\", \"termo2\", \"termo3\"], \"categoria\": \"categoria ou null\"}";

    $prompt = "Cliente buscou: \"{$query}\"\n\nRetorne o JSON com termos de busca:";
    
    $response = callAI($prompt, $system);
    
    if ($response) {
        // Limpar resposta (remover markdown se houver)
        $response = preg_replace('/```json\s*/', '', $response);
        $response = preg_replace('/```\s*/', '', $response);
        $response = trim($response);
        
        $data = json_decode($response, true);
        
        if ($data && isset($data['termos'])) {
            // Salvar no cache
            try {
                $stmt = $pdo->prepare("INSERT INTO om_busca_cache (cache_key, query_original, termos, categoria_sugerida, created_at) VALUES (?, ?, ?, ?, NOW()) ON CONFLICT (cache_key) DO UPDATE SET termos = EXCLUDED.termos, categoria_sugerida = EXCLUDED.categoria_sugerida, created_at = NOW()");
                $stmt->execute([$cacheKey, $query, json_encode($data['termos']), $data['categoria'] ?? null]);
            } catch (Exception $e) {
                // Ignora erro de cache
            }
            
            return [
                'termos' => $data['termos'],
                'categoria' => $data['categoria'] ?? null,
                'cached' => false
            ];
        }
    }
    
    // Fallback: usar query original
    return [
        'termos' => [$query],
        'categoria' => null,
        'cached' => false
    ];
}

/**
 * Buscar produtos no banco
 */
function buscarProdutos($termos, $categoria = null, $limit = 24) {
    global $pdo, $partner_id;
    
    $produtos = [];
    $termosUsados = [];
    
    foreach ($termos as $termo) {
        $termo = trim($termo);
        if (empty($termo) || strlen($termo) < 2) continue;
        
        $termosUsados[] = $termo;
        
        // Busca com LIKE
        $sql = "
            SELECT DISTINCT 
                pb.product_id, 
                pb.name, 
                pb.brand, 
                pb.image, 
                pb.unit,
                pp.price, 
                pp.price_promo, 
                pp.stock,
                mc.name as categoria
            FROM om_market_products_base pb
            JOIN om_market_products_price pp ON pb.product_id = pp.product_id
            LEFT JOIN om_market_categories mc ON pb.category_id = mc.category_id
            WHERE pp.partner_id = ?
              AND pp.status = 1
              AND pp.price > 0
              AND (
                  pb.name LIKE ? 
                  OR pb.brand LIKE ?
                  OR pb.tags LIKE ?
              )
        ";
        
        $params = [$partner_id, "%{$termo}%", "%{$termo}%", "%{$termo}%"];
        
        // Filtrar por categoria se especificada
        if ($categoria) {
            $sql .= " AND mc.name LIKE ?";
            $params[] = "%{$categoria}%";
        }
        
        $sql .= " ORDER BY 
            CASE 
                WHEN pb.name LIKE ? THEN 1
                WHEN pb.name LIKE ? THEN 2
                ELSE 3
            END,
            pp.price_promo > 0 DESC,
            pb.name
        ";
        $params[] = "{$termo}%";
        $params[] = "%{$termo}%";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        while ($row = $stmt->fetch()) {
            if (!isset($produtos[$row['product_id']])) {
                $produtos[$row['product_id']] = $row;
            }
        }
        
        // Se já tem produtos suficientes, para
        if (count($produtos) >= $limit) break;
    }
    
    return [
        'produtos' => array_values(array_slice($produtos, 0, $limit)),
        'termos_usados' => $termosUsados,
        'total' => count($produtos)
    ];
}

/**
 * Gerar sugestões de autocomplete
 */
function gerarSugestoes($query) {
    global $pdo, $partner_id;
    
    $sugestoes = [];
    
    // 1. Buscar produtos que começam com o termo
    $stmt = $pdo->prepare("
        SELECT DISTINCT pb.name
        FROM om_market_products_base pb
        JOIN om_market_products_price pp ON pb.product_id = pp.product_id
        WHERE pp.partner_id = ? AND pp.status = 1 AND pb.name LIKE ?
        ORDER BY pb.name
        LIMIT 5
    ");
    $stmt->execute([$partner_id, "{$query}%"]);
    
    while ($row = $stmt->fetch()) {
        $sugestoes[] = [
            'texto' => $row['name'],
            'tipo' => 'produto'
        ];
    }
    
    // 2. Buscar marcas
    $stmt = $pdo->prepare("
        SELECT DISTINCT pb.brand
        FROM om_market_products_base pb
        JOIN om_market_products_price pp ON pb.product_id = pp.product_id
        WHERE pp.partner_id = ? AND pp.status = 1 AND pb.brand LIKE ? AND pb.brand IS NOT NULL AND pb.brand != ''
        ORDER BY pb.brand
        LIMIT 3
    ");
    $stmt->execute([$partner_id, "{$query}%"]);
    
    while ($row = $stmt->fetch()) {
        $sugestoes[] = [
            'texto' => $row['brand'],
            'tipo' => 'marca'
        ];
    }
    
    // 3. Buscar categorias
    $stmt = $pdo->prepare("
        SELECT name FROM om_market_categories 
        WHERE status = 1 AND name LIKE ?
        ORDER BY name
        LIMIT 3
    ");
    $stmt->execute(["%{$query}%"]);
    
    while ($row = $stmt->fetch()) {
        $sugestoes[] = [
            'texto' => $row['name'],
            'tipo' => 'categoria'
        ];
    }
    
    return array_slice($sugestoes, 0, 8);
}

/**
 * Produtos populares/recomendados
 */
function produtosPopulares($limit = 12) {
    global $pdo, $partner_id;
    
    $stmt = $pdo->prepare("
        SELECT 
            pb.product_id, 
            pb.name, 
            pb.brand, 
            pb.image, 
            pb.unit,
            pp.price, 
            pp.price_promo, 
            pp.stock
        FROM om_market_products_base pb
        JOIN om_market_products_price pp ON pb.product_id = pp.product_id
        WHERE pp.partner_id = ? AND pp.status = 1 AND pp.price > 0
        ORDER BY RANDOM()
        LIMIT ?
    ");
    $stmt->execute([$partner_id, $limit]);
    
    return $stmt->fetchAll();
}

// ═══════════════════════════════════════════════════════════════════════════════
// CRIAR TABELA DE CACHE SE NÃO EXISTIR
// ═══════════════════════════════════════════════════════════════════════════════

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS om_busca_cache (
            id SERIAL PRIMARY KEY,
            cache_key VARCHAR(32) UNIQUE,
            query_original VARCHAR(255),
            termos JSON,
            categoria_sugerida VARCHAR(100),
            hits INT DEFAULT 1,
            created_at TIMESTAMP
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_busca_cache_key ON om_busca_cache(cache_key)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_busca_cache_created ON om_busca_cache(created_at)");
} catch (Exception $e) {
    // Tabela pode já existir
}

// ═══════════════════════════════════════════════════════════════════════════════
// PROCESSAR REQUISIÇÃO
// ═══════════════════════════════════════════════════════════════════════════════

$startTime = microtime(true);

switch ($mode) {
    case 'suggest':
        // Autocomplete
        $sugestoes = gerarSugestoes($query);
        echo json_encode([
            'success' => true,
            'sugestoes' => $sugestoes
        ]);
        break;
        
    case 'populares':
        // Produtos populares
        $produtos = produtosPopulares(12);
        echo json_encode([
            'success' => true,
            'produtos' => $produtos
        ]);
        break;
        
    case 'search':
    default:
        // Busca inteligente
        $interpretado = interpretarBusca($query);
        $resultado = buscarProdutos($interpretado['termos'], $interpretado['categoria']);
        
        $endTime = microtime(true);
        $tempo = round(($endTime - $startTime) * 1000);
        
        // Log da busca
        try {
            $pdo->prepare("INSERT INTO om_busca_log (query, termos_ia, total_resultados, tempo_ms, created_at) VALUES (?, ?, ?, ?, NOW())")
                ->execute([$query, json_encode($interpretado['termos']), $resultado['total'], $tempo]);
        } catch (Exception $e) {
            // Ignora erro de log
        }
        
        echo json_encode([
            'success' => true,
            'query_original' => $query,
            'interpretado' => [
                'termos' => $interpretado['termos'],
                'categoria' => $interpretado['categoria'],
                'cached' => $interpretado['cached']
            ],
            'produtos' => $resultado['produtos'],
            'total' => $resultado['total'],
            'tempo_ms' => $tempo
        ]);
        break;
}

// Criar tabela de log se não existir
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS om_busca_log (
            id SERIAL PRIMARY KEY,
            query VARCHAR(255),
            termos_ia JSON,
            total_resultados INT,
            tempo_ms INT,
            created_at TIMESTAMP
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_busca_log_created ON om_busca_log(created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_busca_log_query ON om_busca_log(query)");
} catch (Exception $e) {
    // Ignora
}
