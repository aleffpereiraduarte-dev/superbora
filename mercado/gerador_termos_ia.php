<?php
require_once __DIR__ . '/config/database.php';
/**
 * GERADOR DE TERMOS COM IA - OneMundo
 * Usa GPT para criar milhares de termos de supermercado
 */

set_time_limit(300);
header('Content-Type: text/plain; charset=utf-8');

$config = [
    'openai_key' => env('OPENAI_API_KEY', ''), // Vamos pegar do sistema
    'db_host' => '147.93.12.236',
    'db_name' => 'love1',
    'db_user' => 'root',
    'db_pass' => DB_PASSWORD
];

// Tentar pegar key do OpenAI do sistema
$keyFile = '/var/www/html/config_keys.php';
if (file_exists($keyFile)) {
    include $keyFile;
    if (defined('OPENAI_API_KEY')) $config['openai_key'] = OPENAI_API_KEY;
}

// Ou usar variável de ambiente
if (getenv('OPENAI_API_KEY')) {
    $config['openai_key'] = getenv('OPENAI_API_KEY');
}

try {
    $pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    die("Erro DB: " . $e->getMessage());
}

// Criar tabela
$pdo->exec("CREATE TABLE IF NOT EXISTS om_crawler_termos_turbo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    termo VARCHAR(255) NOT NULL UNIQUE,
    categoria VARCHAR(100) DEFAULT NULL,
    processado TINYINT DEFAULT 0,
    produtos_encontrados INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    INDEX(processado),
    INDEX(categoria)
)");

// Categorias para o GPT gerar termos
$categorias = [
    'laticínios e derivados de leite',
    'carnes bovinas cortes e tipos',
    'carnes suínas e embutidos',
    'frangos e aves',
    'peixes e frutos do mar',
    'arroz feijão e grãos',
    'massas e macarrões',
    'molhos e temperos',
    'óleos azeites e vinagres',
    'açúcar adoçantes e mel',
    'cafés e achocolatados',
    'chás e infusões',
    'refrigerantes e sucos',
    'águas e bebidas',
    'cervejas nacionais e importadas',
    'vinhos e espumantes',
    'destilados whisky vodka gin',
    'biscoitos e bolachas',
    'chocolates e bombons',
    'snacks salgadinhos e amendoins',
    'cereais matinais e granolas',
    'pães e produtos de padaria',
    'bolos e sobremesas prontas',
    'congelados pizzas e lasanhas',
    'sorvetes e picolés',
    'frutas nomes e variedades',
    'verduras e legumes',
    'temperos frescos e ervas',
    'produtos orgânicos',
    'produtos sem glúten',
    'produtos sem lactose',
    'produtos veganos',
    'produtos diet e light',
    'produtos fitness e proteicos',
    'papinhas e comida de bebê',
    'fórmulas infantis e leites',
    'fraldas e higiene bebê',
    'ração para cachorro marcas',
    'ração para gato marcas',
    'petiscos e acessórios pet',
    'detergentes e sabões',
    'produtos de limpeza casa',
    'desinfetantes e alvejantes',
    'amaciantes de roupa',
    'sabonetes e sabonetes líquidos',
    'shampoos e condicionadores',
    'cremes e hidratantes',
    'desodorantes marcas',
    'pasta de dente e higiene bucal',
    'papel higiênico e lenços',
    'absorventes femininos',
    'produtos de barbear',
    'protetor solar e bronzeadores',
    'medicamentos básicos otc',
    'vitaminas e suplementos',
    'preservativos e íntimos',
    'pilhas e lâmpadas',
    'inseticidas e repelentes',
    'produtos importados italianos',
    'produtos portugueses',
    'produtos orientais japoneses',
    'produtos mexicanos',
    'queijos especiais e importados',
    'presuntos e frios premium',
    'conservas e enlatados',
    'azeitonas e pickles',
    'geleias e compotas',
    'manteigas e margarinas',
    'cream cheese e requeijão',
    'iogurtes e bebidas lácteas',
    'leites e compostos lácteos',
    'farinhas e fermentos',
    'misturas para bolo',
    'sobremesas em pó',
    'gelatinas e pudins',
    'caldos e sopas prontas',
    'comidas prontas e marmitas',
    'sushi e comida japonesa',
    'massas frescas',
    'pães especiais e artesanais'
];

function gerarTermosGPT($categoria, $apiKey, $quantidade = 50) {
    $prompt = "Gere uma lista de $quantidade termos de busca para produtos de supermercado brasileiro na categoria: $categoria

REGRAS:
- Termos curtos (2-4 palavras máximo)
- Incluir marcas populares brasileiras
- Variar entre genéricos e específicos
- Sem gramatura ou peso (não usar 500g, 1kg, etc)
- Formato: um termo por linha, sem numeração
- Termos que um brasileiro usaria para buscar no Google Shopping

Exemplos bons: 'leite integral piracanjuba', 'queijo mussarela', 'iogurte grego danone', 'cerveja heineken'
Exemplos ruins: 'leite 1 litro', 'queijo 500g', 'iogurte 170g'

Gere apenas os termos, nada mais:";

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'Você é um especialista em produtos de supermercado brasileiro. Gere termos de busca otimizados para Google Shopping.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.9,
            'max_tokens' => 2000
        ])
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode != 200) {
        return ['erro' => "HTTP $httpCode", 'resposta' => $response];
    }
    
    $data = json_decode($response, true);
    $texto = $data['choices'][0]['message']['content'] ?? '';
    
    // Extrair termos (um por linha)
    $linhas = explode("\n", $texto);
    $termos = [];
    
    foreach ($linhas as $linha) {
        $linha = trim($linha);
        $linha = preg_replace('/^\d+[\.\)\-]\s*/', '', $linha); // Remove numeração
        $linha = preg_replace('/^[\-\*]\s*/', '', $linha); // Remove bullets
        $linha = trim($linha, '"\'');
        
        if (strlen($linha) >= 3 && strlen($linha) <= 100 && !preg_match('/\d+\s*(g|kg|ml|l|un)\b/i', $linha)) {
            $termos[] = mb_strtolower($linha);
        }
    }
    
    return array_unique($termos);
}

// STATUS
if (isset($_GET['status'])) {
    $total = $pdo->query("SELECT COUNT(*) FROM om_crawler_termos_turbo")->fetchColumn();
    $processados = $pdo->query("SELECT COUNT(*) FROM om_crawler_termos_turbo WHERE processado = 1")->fetchColumn();
    $catalogo = $pdo->query("SELECT COUNT(*) FROM om_market_products_base")->fetchColumn();
    
    header('Content-Type: application/json');
    echo json_encode([
        'termos_total' => (int)$total,
        'termos_pendentes' => (int)($total - $processados),
        'catalogo' => (int)$catalogo,
        'categorias' => count($categorias)
    ]);
    exit;
}

// GERAR TERMOS
$apiKey = $_GET['key'] ?? $config['openai_key'];
$numCategorias = (int)($_GET['n'] ?? 5);

if ($apiKey == 'SUA_KEY_OPENAI' || empty($apiKey)) {
    echo "❌ API Key OpenAI não configurada!\n\n";
    echo "Use: ?key=SUA_OPENAI_KEY&n=5\n";
    echo "Ou configure no arquivo.\n";
    exit;
}

echo "=== GERADOR DE TERMOS COM IA ===\n";
echo date('Y-m-d H:i:s') . "\n";
echo "Gerando termos para $numCategorias categorias...\n\n";

// Pegar categorias ainda não muito exploradas
$categoriasUsadas = $pdo->query("SELECT categoria, COUNT(*) as qtd FROM om_crawler_termos_turbo GROUP BY categoria")->fetchAll(PDO::FETCH_KEY_PAIR);

// Ordenar categorias por menos usadas
usort($categorias, function($a, $b) use ($categoriasUsadas) {
    return ($categoriasUsadas[$a] ?? 0) - ($categoriasUsadas[$b] ?? 0);
});

$totalInseridos = 0;
$categoriasProcessadas = 0;

foreach (array_slice($categorias, 0, $numCategorias) as $categoria) {
    echo "📦 Categoria: $categoria\n";
    
    $termos = gerarTermosGPT($categoria, $apiKey, 50);
    
    if (isset($termos['erro'])) {
        echo "   ❌ Erro: {$termos['erro']}\n\n";
        continue;
    }
    
    $inseridos = 0;
    $stmt = $pdo->prepare("INSERT IGNORE INTO om_crawler_termos_turbo (termo, categoria) VALUES (?, ?)");
    
    foreach ($termos as $termo) {
        $stmt->execute([$termo, $categoria]);
        if ($stmt->rowCount() > 0) $inseridos++;
    }
    
    echo "   ✅ Gerados: " . count($termos) . " | Inseridos: $inseridos\n\n";
    $totalInseridos += $inseridos;
    $categoriasProcessadas++;
    
    usleep(500000); // 500ms entre categorias (rate limit)
}

$totalTermos = $pdo->query("SELECT COUNT(*) FROM om_crawler_termos_turbo")->fetchColumn();
$pendentes = $pdo->query("SELECT COUNT(*) FROM om_crawler_termos_turbo WHERE processado = 0")->fetchColumn();

echo "=== RESUMO ===\n";
echo "Categorias processadas: $categoriasProcessadas\n";
echo "Termos inseridos: $totalInseridos\n";
echo "Total de termos: " . number_format($totalTermos) . "\n";
echo "Pendentes para crawlear: " . number_format($pendentes) . "\n";
