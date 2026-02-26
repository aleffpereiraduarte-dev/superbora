<?php
/**
 * Integração Melhor Envio - OneMundo
 * Cotação real de Correios e transportadoras
 */

class MelhorEnvio {
    private $token;
    private $sandbox = false;
    private $baseUrl;
    
    public function __construct($token, $sandbox = false) {
        $this->token = $token;
        $this->sandbox = $sandbox;
        $this->baseUrl = $sandbox 
            ? 'https://sandbox.melhorenvio.com.br/api/v2' 
            : 'https://melhorenvio.com.br/api/v2';
    }
    
    public function cotar($cep_origem, $cep_destino, $produtos) {
        $payload = [
            'from' => ['postal_code' => preg_replace('/\D/', '', $cep_origem)],
            'to' => ['postal_code' => preg_replace('/\D/', '', $cep_destino)],
            'products' => $produtos,
            'options' => [
                'insurance_value' => $produtos[0]['insurance_value'] ?? 0,
                'receipt' => false,
                'own_hand' => false
            ],
            'services' => '1,2,3,4,17' // PAC, SEDEX, SEDEX 10, SEDEX 12, Mini Envios
        ];
        
        return $this->request('POST', '/me/shipment/calculate', $payload);
    }
    
    public function rastrear($codigo) {
        return $this->request('GET', '/me/shipment/tracking', ['orders' => $codigo]);
    }
    
    private function request($method, $endpoint, $data = []) {
        $ch = curl_init();
        
        $url = $this->baseUrl . $endpoint;
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->token,
            'User-Agent: OneMundo/1.0'
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
            curl_setopt($ch, CURLOPT_URL, $url);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['error' => $error];
        }
        
        $result = json_decode($response, true);
        
        if ($httpCode >= 400) {
            return ['error' => $result['message'] ?? 'Erro na API', 'code' => $httpCode];
        }
        
        return $result;
    }
}

// ═══════════════════════════════════════════════════════════════
// ENDPOINT DE COTAÇÃO
// ═══════════════════════════════════════════════════════════════

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$input = json_decode(file_get_contents('php://input'), true) ?: $_REQUEST;

require_once dirname(__DIR__, 2) . '/config.php';

// Buscar token do banco
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
        DB_USERNAME, DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $stmt = $pdo->query("SELECT valor FROM om_entrega_config WHERE chave = 'melhor_envio_token'");
    $token = $stmt->fetchColumn();
} catch (Exception $e) {
    die(json_encode(['error' => 'DB Error']));
}

if (empty($token)) {
    die(json_encode([
        'error' => 'Token Melhor Envio não configurado',
        'help' => 'Configure em om_entrega_config com chave "melhor_envio_token"'
    ]));
}

$cep_origem = $input['cep_origem'] ?? '';
$cep_destino = $input['cep_destino'] ?? '';
$peso = (float)($input['peso'] ?? 0.5);
$altura = (float)($input['altura'] ?? 5);
$largura = (float)($input['largura'] ?? 15);
$comprimento = (float)($input['comprimento'] ?? 20);
$valor = (float)($input['valor'] ?? 100);

if (strlen(preg_replace('/\D/', '', $cep_origem)) !== 8 || strlen(preg_replace('/\D/', '', $cep_destino)) !== 8) {
    die(json_encode(['error' => 'CEPs inválidos']));
}

$produtos = [[
    'id' => '1',
    'width' => $largura,
    'height' => $altura,
    'length' => $comprimento,
    'weight' => $peso,
    'insurance_value' => $valor,
    'quantity' => 1
]];

$me = new MelhorEnvio($token, false);
$resultado = $me->cotar($cep_origem, $cep_destino, $produtos);

if (isset($resultado['error'])) {
    echo json_encode(['success' => false, 'error' => $resultado['error']]);
} else {
    // Formatar resultado
    $opcoes = [];
    foreach ($resultado as $servico) {
        if (isset($servico['error'])) continue;
        if (!isset($servico['price'])) continue;
        
        $opcoes[] = [
            'id' => 'me_' . $servico['id'],
            'nome' => $servico['name'] ?? $servico['company']['name'],
            'empresa' => $servico['company']['name'] ?? 'Correios',
            'preco' => (float)$servico['price'],
            'prazo' => $servico['delivery_time'] . ' dias úteis',
            'prazo_dias' => (int)$servico['delivery_time']
        ];
    }
    
    // Ordenar por prazo
    usort($opcoes, fn($a, $b) => $a['prazo_dias'] <=> $b['prazo_dias']);
    
    echo json_encode([
        'success' => true,
        'opcoes' => $opcoes,
        'origem' => $cep_origem,
        'destino' => $cep_destino
    ], JSON_UNESCAPED_UNICODE);
}
