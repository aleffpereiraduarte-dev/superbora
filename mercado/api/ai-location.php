<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * 🤖 ONEMUNDO MERCADO - API DE LOCALIZAÇÃO COM IA
 * Powered by Claude AI (Anthropic)
 *
 * Entende qualquer formato de entrada:
 * - CEP (00000-000)
 * - Endereço completo (Rua X, 123, Bairro Y)
 * - Cidade/Estado (São Paulo, SP)
 * - Bairro (Moema)
 * - Perguntas naturais ("vocês entregam em Guarulhos?")
 * ═══════════════════════════════════════════════════════════════════════════════
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/database.php';

// Configuração
define('CLAUDE_API_KEY', env('CLAUDE_API_KEY', ''));
define('CLAUDE_MODEL', 'claude-sonnet-4-20250514');

$pdo = getPDO();

// Receber input
$input = json_decode(file_get_contents('php://input'), true);
$userMessage = trim($input['message'] ?? '');
$context = $input['context'] ?? [];

if (empty($userMessage)) {
    echo json_encode(['success' => false, 'message' => 'Mensagem vazia']);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// FUNÇÕES AUXILIARES
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Buscar mercados parceiros ativos
 */
function getMercadosAtivos($pdo) {
    $stmt = $pdo->query("
        SELECT partner_id, name, city, state, cep_inicio, cep_fim 
        FROM om_market_partners 
        WHERE status = '1' 
        ORDER BY city
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Buscar mercado por CEP
 */
function buscarMercadoPorCEP($pdo, $cep) {
    $cep = preg_replace('/\D/', '', $cep);
    if (strlen($cep) < 5) return null;
    
    $prefixo = substr($cep, 0, 5);
    $stmt = $pdo->prepare("
        SELECT partner_id, name, city, state 
        FROM om_market_partners 
        WHERE status = '1' AND cep_inicio <= ? AND cep_fim >= ? 
        LIMIT 1
    ");
    $stmt->execute([$prefixo, $prefixo]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Buscar mercado por cidade
 */
function buscarMercadoPorCidade($pdo, $cidade) {
    // Normalizar cidade
    $cidade = trim($cidade);
    
    // Busca exata
    $stmt = $pdo->prepare("
        SELECT partner_id, name, city, state 
        FROM om_market_partners 
        WHERE status = '1' AND LOWER(city) = LOWER(?) 
        LIMIT 1
    ");
    $stmt->execute([$cidade]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) return $result;
    
    // Busca aproximada (LIKE)
    $stmt = $pdo->prepare("
        SELECT partner_id, name, city, state 
        FROM om_market_partners 
        WHERE status = '1' AND LOWER(city) LIKE LOWER(?) 
        LIMIT 1
    ");
    $stmt->execute(["%{$cidade}%"]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Consultar CEP via ViaCEP
 */
function consultarViaCEP($cep) {
    $cep = preg_replace('/\D/', '', $cep);
    if (strlen($cep) !== 8) return null;
    
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $response = @file_get_contents("https://viacep.com.br/ws/{$cep}/json/", false, $ctx);
    
    if ($response) {
        $data = json_decode($response, true);
        if ($data && !isset($data['erro'])) {
            return [
                'cep' => $data['cep'],
                'logradouro' => $data['logradouro'] ?? '',
                'bairro' => $data['bairro'] ?? '',
                'cidade' => $data['localidade'] ?? '',
                'estado' => $data['uf'] ?? ''
            ];
        }
    }
    return null;
}

/**
 * Chamar Claude AI
 */
function askClaude($prompt, $systemPrompt) {
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    
    $payload = [
        'model' => CLAUDE_MODEL,
        'max_tokens' => 500,
        'system' => $systemPrompt,
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ]
    ];
    
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . CLAUDE_API_KEY,
            'anthropic-version: 2023-06-01'
        ],
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        return $data['content'][0]['text'] ?? null;
    }
    
    return null;
}

// ═══════════════════════════════════════════════════════════════════════════════
// PROCESSAMENTO PRINCIPAL
// ═══════════════════════════════════════════════════════════════════════════════

// Obter lista de mercados para contexto
$mercados = getMercadosAtivos($pdo);
$cidadesAtendidas = array_unique(array_column($mercados, 'city'));

// Verificar se é um CEP direto
$cepMatch = preg_replace('/\D/', '', $userMessage);
if (strlen($cepMatch) === 8) {
    // É um CEP, buscar diretamente
    $viaCep = consultarViaCEP($cepMatch);
    
    if ($viaCep) {
        $mercado = buscarMercadoPorCEP($pdo, $cepMatch);
        
        if ($mercado) {
            echo json_encode([
                'success' => true,
                'found_market' => true,
                'cep' => $cepMatch,
                'cidade' => $viaCep['cidade'],
                'estado' => $viaCep['estado'],
                'bairro' => $viaCep['bairro'],
                'mercado' => $mercado['name'],
                'tempo_entrega' => '30-45'
            ]);
            exit;
        } else {
            echo json_encode([
                'success' => true,
                'found_market' => false,
                'message' => "
                    <p>😔 Ainda não chegamos no CEP <strong>" . substr($cepMatch, 0, 5) . "-" . substr($cepMatch, 5) . "</strong> ({$viaCep['cidade']}/{$viaCep['estado']}).</p>
                    <p>Estamos expandindo rapidamente! As cidades que já atendemos são: <strong>" . implode(', ', array_slice($cidadesAtendidas, 0, 5)) . "</strong>" . (count($cidadesAtendidas) > 5 ? ' e mais!' : '.') . "</p>
                    <p>🔔 Quer que eu avise quando chegarmos na sua região?</p>
                "
            ]);
            exit;
        }
    }
}

// Não é CEP, usar IA para interpretar
$systemPrompt = "Você é o assistente de localização do OneMundo Mercado, um serviço de delivery de supermercado no Brasil.

CIDADES QUE ATENDEMOS:
" . implode(', ', $cidadesAtendidas) . "

SUA TAREFA:
1. Interpretar a mensagem do usuário e extrair informações de localização (cidade, bairro, endereço, CEP)
2. Verificar se atendemos a região mencionada
3. Responder de forma amigável e útil

REGRAS:
- Se o usuário mencionar uma cidade que atendemos, responda com entusiasmo
- Se não atendemos, seja gentil e mencione as cidades próximas que atendemos
- Se a mensagem for uma pergunta genérica, responda naturalmente
- Se conseguir identificar uma cidade/região, indique no formato JSON no final da resposta

FORMATO DE RESPOSTA:
Sempre termine sua resposta com uma linha JSON assim (se identificou localização):
###JSON:{\"cidade\":\"Nome da Cidade\",\"estado\":\"UF\",\"atendemos\":true/false}###

Se não identificou localização, não inclua o JSON.

EXEMPLOS:
- \"Rua Augusta, 1200\" → Provavelmente São Paulo/SP
- \"Moema\" → Bairro de São Paulo/SP
- \"vocês entregam em Campinas?\" → Verificar se Campinas está na lista
- \"Gov Valadares\" → Governador Valadares/MG";

$aiResponse = askClaude($userMessage, $systemPrompt);

if (!$aiResponse) {
    // Fallback sem IA
    echo json_encode([
        'success' => true,
        'found_market' => false,
        'message' => "
            <p>Hmm, não consegui entender completamente. 🤔</p>
            <p>Pode tentar de outra forma? Por exemplo:</p>
            <ul>
                <li>Digite seu CEP: <strong>01310-100</strong></li>
                <li>Ou a cidade: <strong>São Paulo</strong></li>
            </ul>
        "
    ]);
    exit;
}

// Processar resposta da IA
$response = [
    'success' => true,
    'found_market' => false,
    'message' => ''
];

// Extrair JSON se houver
if (preg_match('/###JSON:(\{.*?\})###/s', $aiResponse, $matches)) {
    $locationData = json_decode($matches[1], true);
    $aiResponse = preg_replace('/###JSON:.*?###/s', '', $aiResponse);
    
    if ($locationData && isset($locationData['cidade'])) {
        // Verificar no banco se realmente atendemos
        $mercado = buscarMercadoPorCidade($pdo, $locationData['cidade']);
        
        if ($mercado) {
            $response['found_market'] = true;
            $response['cidade'] = $mercado['city'];
            $response['estado'] = $mercado['state'];
            $response['mercado'] = $mercado['name'];
            $response['tempo_entrega'] = '30-45';
        }
    }
}

// Limpar e formatar resposta
$aiResponse = trim($aiResponse);
$aiResponse = str_replace("\n", "</p><p>", $aiResponse);
$response['message'] = "<p>{$aiResponse}</p>";

echo json_encode($response);
