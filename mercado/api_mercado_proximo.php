<?php
require_once __DIR__ . '/config/database.php';
/**
 * API Mercado Próximo - OneMundo
 * VERSÃO CORRIGIDA v2.1
 * 
 * Correções:
 * - Aceita status = '1' ou 'active'
 * - Fallback de coordenadas para cidade
 * - Logs para debug
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Debug mode
$debug = isset($_GET['debug']);

$config = [
    'db_host' => '147.93.12.236',
    'db_name' => 'love1',
    'db_user' => 'love1',
    'db_pass' => DB_PASSWORD
];

function calcDist($lat1, $lon1, $lat2, $lon2) {
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + 
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * 
         sin($dLon/2) * sin($dLon/2);
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

function getCoords($cep) {
    $cep = preg_replace('/\D/', '', $cep);
    
    // 1. Buscar dados do CEP via ViaCEP
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $r = @file_get_contents("https://viacep.com.br/ws/{$cep}/json/", false, $ctx);
    if (!$r) return null;
    
    $d = json_decode($r, true);
    if (isset($d['erro'])) return null;
    
    // 2. Geocodificar via Nominatim
    $ctx2 = stream_context_create(['http' => [
        'timeout' => 5,
        'header' => "User-Agent: OneMundo/1.0\r\n"
    ]]);
    
    // Tentar com endereço completo primeiro
    $endereco = urlencode("{$d['localidade']}, {$d['uf']}, Brasil");
    $g = @file_get_contents(
        "https://nominatim.openstreetmap.org/search?format=json&q={$endereco}&limit=1",
        false, $ctx2
    );
    
    if ($g) {
        $gd = json_decode($g, true);
        if (!empty($gd[0]['lat'])) {
            return [
                'lat' => (float)$gd[0]['lat'],
                'lon' => (float)$gd[0]['lon'],
                'cidade' => $d['localidade'],
                'uf' => $d['uf']
            ];
        }
    }
    
    // 3. Fallback: coordenadas conhecidas para algumas cidades
    $cidadesConhecidas = [
        'Governador Valadares' => ['lat' => -18.8537, 'lon' => -41.9495],
        'Belo Horizonte' => ['lat' => -19.9167, 'lon' => -43.9345],
        'São Paulo' => ['lat' => -23.5505, 'lon' => -46.6333],
        'Rio de Janeiro' => ['lat' => -22.9068, 'lon' => -43.1729],
    ];
    
    if (isset($cidadesConhecidas[$d['localidade']])) {
        return [
            'lat' => $cidadesConhecidas[$d['localidade']]['lat'],
            'lon' => $cidadesConhecidas[$d['localidade']]['lon'],
            'cidade' => $d['localidade'],
            'uf' => $d['uf'],
            'fonte' => 'fallback'
        ];
    }
    
    return null;
}

// Validar CEP
$cep = $_GET['cep'] ?? '';
$cep = preg_replace('/\D/', '', $cep);

if (strlen($cep) !== 8) {
    echo json_encode(['success' => false, 'error' => 'CEP inválido', 'disponivel' => false]);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Buscar coordenadas do CEP
    $coords = getCoords($cep);
    
    if (!$coords) {
        echo json_encode([
            'success' => false, 
            'error' => 'Não foi possível obter coordenadas do CEP', 
            'disponivel' => false,
            'cep' => $cep
        ]);
        exit;
    }
    
    // Buscar mercados ativos (status = 'active' OU status = '1')
    $stmt = $pdo->query("
        SELECT 
            partner_id as id,
            COALESCE(trade_name, name, business_name) as nome,
            latitude,
            longitude,
            COALESCE(delivery_radius_km, 50) as delivery_radius_km,
            COALESCE(delivery_time_min, 60) as tempo_estimado_min,
            status
        FROM om_market_partners 
        WHERE (status = 'active' OR status = '1' OR status = '1')
        AND latitude IS NOT NULL 
        AND latitude != 0
        AND longitude IS NOT NULL 
        AND longitude != 0
    ");
    
    $mercados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($mercados)) {
        echo json_encode([
            'success' => true, 
            'disponivel' => false, 
            'motivo' => 'Nenhum mercado ativo encontrado',
            'debug' => $debug ? ['coords' => $coords] : null
        ]);
        exit;
    }
    
    // Encontrar mercado mais próximo dentro do raio
    $mercadoProximo = null;
    $menorDist = PHP_FLOAT_MAX;
    $debugMercados = [];
    
    foreach ($mercados as $m) {
        $dist = calcDist(
            $coords['lat'], 
            $coords['lon'], 
            (float)$m['latitude'], 
            (float)$m['longitude']
        );
        
        $raio = (float)$m['delivery_radius_km'];
        
        if ($debug) {
            $debugMercados[] = [
                'id' => $m['id'],
                'nome' => $m['nome'],
                'distancia' => round($dist, 2),
                'raio' => $raio,
                'dentro' => $dist <= $raio
            ];
        }
        
        if ($dist <= $raio && $dist < $menorDist) {
            $menorDist = $dist;
            $mercadoProximo = $m;
            $mercadoProximo['distancia_km'] = round($dist, 2);
        }
    }
    
    if ($mercadoProximo) {
        // Calcular tempo estimado baseado na distância
        $tempo = max(15, ceil(($menorDist / 40) * 60)); // 40 km/h média
        $mercadoProximo['tempo_estimado_min'] = min($tempo, (int)$mercadoProximo['tempo_estimado_min']);
        
        $response = [
            'success' => true,
            'disponivel' => true,
            'mercado' => $mercadoProximo,
            'cliente' => [
                'cep' => $cep,
                'cidade' => $coords['cidade'],
                'uf' => $coords['uf']
            ]
        ];
        
        if ($debug) {
            $response['debug'] = [
                'coords_cliente' => $coords,
                'mercados_analisados' => $debugMercados
            ];
        }
        
        echo json_encode($response);
    } else {
        $response = [
            'success' => true,
            'disponivel' => false,
            'motivo' => 'Fora da área de entrega'
        ];
        
        if ($debug) {
            $response['debug'] = [
                'coords_cliente' => $coords,
                'mercados_analisados' => $debugMercados
            ];
        }
        
        echo json_encode($response);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno: ' . $e->getMessage(),
        'disponivel' => false
    ]);
}
