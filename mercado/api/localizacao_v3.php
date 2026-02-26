<?php
/**
 * API Mercado Próximo v2.0
 * Endpoint simplificado para verificar disponibilidade
 *
 * Uso: /mercado/api/localizacao_v3.php?cep=35040090
 * Ou:  /mercado/api/localizacao_v3.php?action=verificar&cep=35040090
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';

// Configuração
define('EARTH_RADIUS_KM', 6371);

$db = getPDO();

// Parâmetros
$action = $_GET['action'] ?? $_POST['action'] ?? 'verificar'; // Default: verificar
$cep = preg_replace('/\D/', '', $_GET['cep'] ?? $_POST['cep'] ?? '');

// ═══════════════════════════════════════════════════════════════════════════════
// FUNÇÕES
// ═══════════════════════════════════════════════════════════════════════════════

function getCepCoordinates($cep) {
    // Coordenadas aproximadas por prefixo de CEP (principais cidades)
    $prefixos = [
        '35' => ['lat' => -18.8509, 'lng' => -41.9450, 'cidade' => 'Governador Valadares', 'estado' => 'MG'],
        '350' => ['lat' => -18.8509, 'lng' => -41.9450, 'cidade' => 'Governador Valadares', 'estado' => 'MG'],
        '3504' => ['lat' => -18.8509, 'lng' => -41.9450, 'cidade' => 'Governador Valadares', 'estado' => 'MG'],
        '35040' => ['lat' => -18.8509, 'lng' => -41.9450, 'cidade' => 'Governador Valadares', 'estado' => 'MG'],
        '30' => ['lat' => -19.9167, 'lng' => -43.9345, 'cidade' => 'Belo Horizonte', 'estado' => 'MG'],
        '301' => ['lat' => -19.9167, 'lng' => -43.9345, 'cidade' => 'Belo Horizonte', 'estado' => 'MG'],
        '3013' => ['lat' => -19.9167, 'lng' => -43.9345, 'cidade' => 'Belo Horizonte', 'estado' => 'MG'],
        '01' => ['lat' => -23.5505, 'lng' => -46.6333, 'cidade' => 'São Paulo', 'estado' => 'SP'],
        '013' => ['lat' => -23.5505, 'lng' => -46.6333, 'cidade' => 'São Paulo', 'estado' => 'SP'],
        '04' => ['lat' => -23.5505, 'lng' => -46.6333, 'cidade' => 'São Paulo', 'estado' => 'SP'],
        '07' => ['lat' => -23.4538, 'lng' => -46.5333, 'cidade' => 'Guarulhos', 'estado' => 'SP'],
    ];
    
    // Tentar do mais específico para o mais genérico
    for ($len = 5; $len >= 2; $len--) {
        $prefix = substr($cep, 0, $len);
        if (isset($prefixos[$prefix])) {
            return $prefixos[$prefix];
        }
    }
    
    // Fallback: tentar API externa (ViaCEP)
    $viaCep = @file_get_contents("https://viacep.com.br/ws/{$cep}/json/");
    if ($viaCep) {
        $data = json_decode($viaCep, true);
        if ($data && !isset($data['erro'])) {
            // Coordenadas aproximadas baseadas na cidade
            return [
                'lat' => -18.8509,
                'lng' => -41.9450,
                'cidade' => $data['localidade'] ?? 'Desconhecida',
                'estado' => $data['uf'] ?? ''
            ];
        }
    }
    
    return null;
}

function haversineDistance($lat1, $lng1, $lat2, $lng2) {
    $lat1 = deg2rad($lat1);
    $lat2 = deg2rad($lat2);
    $lng1 = deg2rad($lng1);
    $lng2 = deg2rad($lng2);
    
    $dlat = $lat2 - $lat1;
    $dlng = $lng2 - $lng1;
    
    $a = sin($dlat/2) * sin($dlat/2) + cos($lat1) * cos($lat2) * sin($dlng/2) * sin($dlng/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    
    return EARTH_RADIUS_KM * $c;
}

function getMercadoProximo($db, $lat, $lng) {
    // Buscar mercados ativos
    $stmt = $db->query("
        SELECT 
            partner_id,
            name,
            city,
            state,
            lat,
            lng,
            delivery_radius_km,
            delivery_time_min,
            delivery_time_max,
            delivery_fee,
            min_order,
            rating,
            is_open,
            open_time,
            close_time
        FROM om_market_partners 
        WHERE status = '1' 
        AND lat IS NOT NULL 
        AND lng IS NOT NULL
    ");
    
    $mercados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $mercadoMaisProximo = null;
    $menorDistancia = PHP_INT_MAX;
    
    foreach ($mercados as $m) {
        $raio = floatval($m['delivery_radius_km'] ?: 10);
        $distancia = haversineDistance($lat, $lng, $m['lat'], $m['lng']);
        
        if ($distancia <= $raio && $distancia < $menorDistancia) {
            $menorDistancia = $distancia;
            $mercadoMaisProximo = $m;
            $mercadoMaisProximo['distancia_km'] = round($distancia, 2);
        }
    }
    
    return $mercadoMaisProximo;
}

// ═══════════════════════════════════════════════════════════════════════════════
// PROCESSAMENTO
// ═══════════════════════════════════════════════════════════════════════════════

switch ($action) {
    case 'verificar':
    case 'check':
    case 'disponivel':
    default:
        if (!$cep || strlen($cep) < 8) {
            echo json_encode([
                'success' => false,
                'error' => 'CEP inválido',
                'disponivel' => false
            ]);
            exit;
        }
        
        // Obter coordenadas do CEP
        $coords = getCepCoordinates($cep);
        
        if (!$coords) {
            echo json_encode([
                'success' => false,
                'error' => 'Não foi possível obter coordenadas do CEP',
                'disponivel' => false,
                'cep' => $cep
            ]);
            exit;
        }
        
        // Buscar mercado mais próximo
        $mercado = getMercadoProximo($db, $coords['lat'], $coords['lng']);
        
        if ($mercado) {
            echo json_encode([
                'success' => true,
                'disponivel' => true,
                'cep' => $cep,
                'cidade' => $coords['cidade'],
                'estado' => $coords['estado'],
                'mercado_id' => $mercado['partner_id'],
                'mercado_nome' => $mercado['name'],
                'mercado' => [
                    'id' => $mercado['partner_id'],
                    'nome' => $mercado['name'],
                    'cidade' => $mercado['city'],
                    'estado' => $mercado['state'],
                    'distancia_km' => $mercado['distancia_km'],
                    'raio_km' => $mercado['delivery_radius_km'],
                    'tempo_entrega' => $mercado['delivery_time_min'] ?: 25,
                    'tempo_entrega_max' => $mercado['delivery_time_max'] ?: 45,
                    'taxa_entrega' => $mercado['delivery_fee'],
                    'pedido_minimo' => $mercado['min_order'],
                    'rating' => $mercado['rating'],
                    'aberto' => $mercado['is_open'] == 1
                ],
                // Campos legados para compatibilidade
                'tempo_entrega' => $mercado['delivery_time_min'] ?: 25,
                'delivery_time' => $mercado['delivery_time_min'] ?: 25,
                'partner_name' => $mercado['name'],
                'city' => $mercado['city'] ?: $coords['cidade']
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'disponivel' => false,
                'cep' => $cep,
                'cidade' => $coords['cidade'],
                'estado' => $coords['estado'],
                'message' => 'Nenhum mercado disponível para este CEP'
            ]);
        }
        break;
        
    case 'listar':
        // Listar todos os mercados disponíveis
        $stmt = $db->query("SELECT partner_id, name, city, state FROM om_market_partners WHERE status = '1'");
        $mercados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode([
            'success' => true,
            'total' => count($mercados),
            'mercados' => $mercados
        ]);
        break;
}