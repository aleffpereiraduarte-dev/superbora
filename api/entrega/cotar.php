<?php
/**
 * API de Cotação de Entrega Inteligente - OneMundo v4.0
 * Integra: Driver próprio + Melhor Envio (preços reais) + IA
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once dirname(__DIR__, 2) . '/config.php';

define('CEP_PADRAO', '07023022');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
        DB_USERNAME, DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Exception $e) {
    die(json_encode(['success' => false, 'error' => 'DB Error']));
}

// Claude API key from config
$CLAUDE_API_KEY = null;
$stmt = $pdo->query("SELECT valor FROM om_entrega_config WHERE chave = 'claude_api_key'");
$CLAUDE_API_KEY = $stmt->fetchColumn();

$input = json_decode(file_get_contents('php://input'), true) ?: $_REQUEST;

// Funções auxiliares
function getConfig($pdo, $chave, $default = null) {
    $stmt = $pdo->prepare("SELECT valor FROM om_entrega_config WHERE chave = ?");
    $stmt->execute([$chave]);
    return $stmt->fetchColumn() ?: $default;
}

function limparCEP($cep) { return preg_replace('/\D/', '', $cep ?? ''); }

function buscarCEP($cep) {
    $cep = limparCEP($cep);
    if (strlen($cep) !== 8) return null;
    $r = @file_get_contents("https://viacep.com.br/ws/{$cep}/json/", false, stream_context_create(['http' => ['timeout' => 5]]));
    return $r ? (($d = json_decode($r, true)) && !isset($d['erro']) ? $d : null) : null;
}

function buscarCEPOrigem($pdo, $cep_informado, $product_id, $seller_id) {
    $cep = limparCEP($cep_informado);
    if (strlen($cep) === 8) return ['cep' => $cep, 'fonte' => 'informado'];
    
    if ($product_id) {
        $stmt = $pdo->prepare("SELECT cep_localizacao, seller_id FROM oc_product WHERE product_id = ?");
        $stmt->execute([$product_id]);
        if ($p = $stmt->fetch()) {
            $cep = limparCEP($p['cep_localizacao']);
            if (strlen($cep) === 8) return ['cep' => $cep, 'fonte' => 'produto'];
            if (!$seller_id && $p['seller_id']) $seller_id = $p['seller_id'];
        }
    }
    
    if ($seller_id) {
        $stmt = $pdo->prepare("SELECT cep, trade_name FROM om_market_partners WHERE partner_id = ? OR customer_id = ?");
        $stmt->execute([$seller_id, $seller_id]);
        if ($p = $stmt->fetch()) {
            $cep = limparCEP($p['cep']);
            if (strlen($cep) === 8) return ['cep' => $cep, 'fonte' => 'parceiro', 'nome' => $p['trade_name']];
        }
    }
    
    return ['cep' => CEP_PADRAO, 'fonte' => 'padrao'];
}

function cotarMelhorEnvio($pdo, $cep_origem, $cep_destino, $peso, $valor) {
    $token = getConfig($pdo, 'melhor_envio_token');
    if (!$token) return [];
    
    $payload = [
        'from' => ['postal_code' => $cep_origem],
        'to' => ['postal_code' => $cep_destino],
        'products' => [[
            'id' => '1', 'width' => 15, 'height' => 5, 'length' => 20,
            'weight' => max($peso, 0.3), 'insurance_value' => $valor, 'quantity' => 1
        ]],
        'services' => '1,2,3,4'
    ];
    
    $ch = curl_init('https://melhorenvio.com.br/api/v2/me/shipment/calculate');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 
                               'Authorization: Bearer ' . $token, 'User-Agent: OneMundo/1.0'],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    $opcoes = [];
    
    if (is_array($result)) {
        foreach ($result as $s) {
            if (isset($s['price']) && !isset($s['error'])) {
                $opcoes[] = [
                    'id' => 'me_' . $s['id'],
                    'nome' => '📦 ' . ($s['name'] ?? 'Correios'),
                    'preco' => (float)$s['price'],
                    'prazo' => $s['delivery_time'] . ' dias úteis',
                    'prazo_dias' => (int)$s['delivery_time'],
                    'metodo' => 'correios',
                    'empresa' => $s['company']['name'] ?? 'Correios'
                ];
            }
        }
    }
    
    return $opcoes;
}

function verificarCache($pdo, $hash) {
    $stmt = $pdo->prepare("SELECT resultado FROM om_cotacoes_cache WHERE hash_consulta = ? AND expires_at > NOW()");
    $stmt->execute([$hash]);
    $r = $stmt->fetchColumn();
    return $r ? json_decode($r, true) : null;
}

function salvarCache($pdo, $hash, $cep_o, $cep_d, $peso, $resultado, $ttl = 3600) {
    $stmt = $pdo->prepare("INSERT INTO om_cotacoes_cache (hash_consulta, cep_origem, cep_destino, peso, resultado, expires_at) 
                           VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
                           ON DUPLICATE KEY UPDATE resultado = VALUES(resultado), expires_at = VALUES(expires_at)");
    $stmt->execute([$hash, $cep_o, $cep_d, $peso, json_encode($resultado), $ttl]);
}

function consultarIA($dados) {
    $prompt = "Analise e retorne APENAS JSON. Dados: " . json_encode($dados, JSON_UNESCAPED_UNICODE) . "
REGRAS: 1) PRIORIZE VELOCIDADE se preço < 30% maior 2) Express > Correios se preço similar 3) Max 3 opções 4) Mais rápido = recomendado:true
JSON: {\"opcoes\": [{\"id\": \"x\", \"nome\": \"x\", \"preco\": 0, \"prazo\": \"x\", \"recomendado\": true/false}], \"justificativa\": \"x\"}";

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . CLAUDE_API_KEY, 'anthropic-version: 2023-06-01'],
        CURLOPT_POSTFIELDS => json_encode(['model' => 'claude-sonnet-4-20250514', 'max_tokens' => 500, 'messages' => [['role' => 'user', 'content' => $prompt]]])
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    if (isset($data['content'][0]['text'])) {
        preg_match('/\{[\s\S]*\}/', $data['content'][0]['text'], $m);
        return $m ? json_decode($m[0], true) : null;
    }
    return null;
}

// ═══════════════════════════════════════════════════════════════
// PROCESSAR COTAÇÃO
// ═══════════════════════════════════════════════════════════════

$cep_destino = limparCEP($input['cep_destino'] ?? '');
$product_id = (int)($input['product_id'] ?? 0);
$seller_id = (int)($input['seller_id'] ?? $input['vendedor_id'] ?? 0);
$peso = (float)($input['peso'] ?? 0.5);
$valor = (float)($input['valor_declarado'] ?? $input['valor'] ?? 100);

if (strlen($cep_destino) !== 8) {
    die(json_encode(['success' => false, 'error' => 'CEP destino inválido']));
}

$origem_info = buscarCEPOrigem($pdo, $input['cep_origem'] ?? '', $product_id, $seller_id);
$cep_origem = $origem_info['cep'];

// Cache
$hash = md5("v4|$cep_origem|$cep_destino|$peso");
if ($cached = verificarCache($pdo, $hash)) {
    $cached['cache'] = true;
    echo json_encode($cached, JSON_UNESCAPED_UNICODE);
    exit;
}

$origem = buscarCEP($cep_origem);
$destino = buscarCEP($cep_destino);

if (!$destino) {
    die(json_encode(['success' => false, 'error' => 'CEP destino não encontrado']));
}

// Calcular proximidade
$mesma_cidade = $origem && (strtolower($origem['localidade'] ?? '') === strtolower($destino['localidade']));
$mesmo_estado = $origem && ($origem['uf'] ?? '') === $destino['uf'];

// Configs
$taxa_base = (float)getConfig($pdo, 'taxa_base_driver', 5);
$preco_km = (float)getConfig($pdo, 'preco_km_driver', 1.5);
$minimo = (float)getConfig($pdo, 'minimo_driver', 8);

$opcoes = [];

// 1. Express (mesma cidade)
if ($mesma_cidade) {
    $preco = max($taxa_base + ($preco_km * 8), $minimo);
    $opcoes[] = [
        'id' => 'express_6h', 
        'nome' => '⚡ Receba em 6 horas', 
        'preco' => round($preco, 2), 
        'prazo' => 'Hoje até ' . date('H:i', strtotime('+6 hours')),
        'prazo_dias' => 0,
        'metodo' => 'driver'
    ];
}

// 2. Melhor Envio (preços reais)
$opcoes_me = cotarMelhorEnvio($pdo, $cep_origem, $cep_destino, $peso, $valor);
$opcoes = array_merge($opcoes, $opcoes_me);

// Ordenar por prazo
usort($opcoes, fn($a, $b) => ($a['prazo_dias'] ?? 99) <=> ($b['prazo_dias'] ?? 99));

// Limitar e marcar recomendado
$opcoes = array_slice($opcoes, 0, 4);
if (!empty($opcoes)) {
    // Express sempre recomendado se existir, senão o mais rápido
    $found = false;
    foreach ($opcoes as &$op) {
        if ($op['id'] === 'express_6h') {
            $op['recomendado'] = true;
            $found = true;
            break;
        }
    }
    if (!$found) $opcoes[0]['recomendado'] = true;
}

$resposta = [
    'success' => true,
    'opcoes' => $opcoes,
    'origem_info' => $origem_info,
    'destino' => $destino['localidade'] . '/' . $destino['uf'],
    'cache' => false
];

salvarCache($pdo, $hash, $cep_origem, $cep_destino, $peso, $resposta);
echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
