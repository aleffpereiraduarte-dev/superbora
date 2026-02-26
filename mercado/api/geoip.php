<?php
/**
 * API DE GEOLOCALIZACAO POR IP
 * Detecta localização aproximada do usuário pelo IP
 * Usa serviços gratuitos: ip-api.com, ipinfo.io
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(json_encode(['success' => true]));
}

// Sessão
if (session_status() === PHP_SESSION_NONE) {
    session_name('OCSESSID');
    session_start();
}

// Conexão
require_once dirname(__DIR__) . '/config/database.php';
try {
    $pdo = getPDO();
} catch (Exception $e) {
    die(json_encode(['success' => false, 'error' => 'Erro de conexão']));
}

// Funções auxiliares
function getClientIP() {
    $headers = [
        'HTTP_CF_CONNECTING_IP',     // Cloudflare
        'HTTP_X_FORWARDED_FOR',      // Proxy
        'HTTP_X_REAL_IP',            // Nginx
        'HTTP_CLIENT_IP',            // Cliente
        'REMOTE_ADDR'                // Padrão
    ];

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];
            // Se for lista de IPs, pegar o primeiro
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            // Validar IP
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? null;
}

function getLocationFromIP($ip) {
    // Ignorar IPs locais
    if (in_array($ip, ['127.0.0.1', '::1']) || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
        return null;
    }

    // Tentar ip-api.com (gratuito, 45 req/min)
    $ctx = stream_context_create(['http' => ['timeout' => 3]]);
    $response = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,countryCode,region,regionName,city,zip,lat,lon,query&lang=pt-BR", false, $ctx);

    if ($response) {
        $data = json_decode($response, true);
        if ($data && $data['status'] === 'success') {
            return [
                'ip' => $ip,
                'cidade' => $data['city'] ?? '',
                'estado' => $data['region'] ?? '',
                'estado_nome' => $data['regionName'] ?? '',
                'pais' => $data['countryCode'] ?? 'BR',
                'cep' => $data['zip'] ?? '',
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lon'] ?? null,
                'source' => 'ip-api'
            ];
        }
    }

    // Fallback: ipinfo.io (gratuito, 50k/mês)
    $response = @file_get_contents("https://ipinfo.io/{$ip}/json", false, $ctx);

    if ($response) {
        $data = json_decode($response, true);
        if ($data && !isset($data['error'])) {
            $loc = explode(',', $data['loc'] ?? '0,0');
            return [
                'ip' => $ip,
                'cidade' => $data['city'] ?? '',
                'estado' => $data['region'] ?? '',
                'estado_nome' => $data['region'] ?? '',
                'pais' => $data['country'] ?? 'BR',
                'cep' => $data['postal'] ?? '',
                'lat' => floatval($loc[0] ?? 0),
                'lng' => floatval($loc[1] ?? 0),
                'source' => 'ipinfo'
            ];
        }
    }

    return null;
}

function calcularDistancia($lat1, $lng1, $lat2, $lng2) {
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLng/2) * sin($dLng/2);
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

function buscarMercadosProximos($pdo, $lat, $lng, $limite = 5) {
    $sql = "SELECT partner_id, name, code, logo, address, city, state,
                   latitude, longitude, raio_entrega_km, min_order_value, delivery_fee
            FROM om_market_partners
            WHERE status = '1'
            AND latitude IS NOT NULL AND latitude != 0
            AND longitude IS NOT NULL AND longitude != 0";

    $result = $pdo->query($sql);
    if (!$result) return [];

    $mercados = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $dist = calcularDistancia($lat, $lng, floatval($row['latitude']), floatval($row['longitude']));
        $raio = floatval($row['raio_entrega_km'] ?: 20);

        $row['distancia_km'] = round($dist, 2);
        $row['dentro_raio'] = $dist <= $raio;
        $row['tempo_estimado'] = ceil($dist * 3);

        $mercados[] = $row;
    }

    usort($mercados, function($a, $b) {
        return $a['distancia_km'] <=> $b['distancia_km'];
    });

    return array_slice($mercados, 0, $limite);
}

// ═══════════════════════════════════════════════════════════════════════════════
// AÇÃO PRINCIPAL: Detectar localização e mercado
// ═══════════════════════════════════════════════════════════════════════════════

$action = $_GET['action'] ?? $_POST['action'] ?? 'detect';

if ($action === 'detect' || $action === 'auto') {
    $ip = getClientIP();
    $location = getLocationFromIP($ip);

    // Se não conseguiu localização por IP
    if (!$location || !$location['lat'] || !$location['lng']) {
        echo json_encode([
            'success' => true,
            'detected' => false,
            'need_cep' => true,
            'ip' => $ip,
            'mensagem' => 'Não conseguimos detectar sua localização automaticamente.',
            'mensagem_cta' => 'Por favor, informe seu CEP para encontrarmos os mercados mais próximos.'
        ]);
        exit;
    }

    // Buscar mercados próximos
    $mercados = buscarMercadosProximos($pdo, $location['lat'], $location['lng'], 10);

    // Filtrar apenas mercados dentro do raio
    $mercadosDentroRaio = array_filter($mercados, function($m) {
        return $m['dentro_raio'] === true;
    });
    $mercadosDentroRaio = array_values($mercadosDentroRaio);

    // Se não há mercados dentro do raio
    if (empty($mercadosDentroRaio)) {
        $mercadoMaisProximo = $mercados[0] ?? null;

        // Salvar na sessão para o waitlist
        $_SESSION['waitlist_cidade'] = $location['cidade'];
        $_SESSION['waitlist_uf'] = $location['estado'];
        $_SESSION['waitlist_coords'] = ['lat' => $location['lat'], 'lng' => $location['lng']];
        $_SESSION['waitlist_cep'] = $location['cep'] ?? '';

        echo json_encode([
            'success' => true,
            'detected' => true,
            'disponivel' => false,
            'show_waitlist' => true,
            'mensagem_titulo' => 'Ops! Ainda não chegamos aí',
            'mensagem' => "Que pena! Ainda não temos mercados parceiros em {$location['cidade']} - {$location['estado']}. Mas estamos expandindo rapidinho!",
            'mensagem_cta' => 'Deixe seu e-mail e avisamos assim que chegarmos na sua região!',
            'localizacao' => $location,
            'mercado_mais_proximo' => $mercadoMaisProximo ? [
                'nome' => $mercadoMaisProximo['name'],
                'distancia_km' => $mercadoMaisProximo['distancia_km'],
                'cidade' => $mercadoMaisProximo['city']
            ] : null,
            'ip' => $ip
        ]);
        exit;
    }

    // Tem mercado disponível!
    $mercadoSelecionado = $mercadosDentroRaio[0];

    // Salvar na sessão
    $_SESSION['market_partner_id'] = $mercadoSelecionado['partner_id'];
    $_SESSION['market_partner_name'] = $mercadoSelecionado['name'];
    $_SESSION['market_partner_code'] = $mercadoSelecionado['code'] ?? '';
    $_SESSION['cep_cidade'] = $location['cidade'];
    $_SESSION['cep_estado'] = $location['estado'];
    $_SESSION['customer_coords'] = ['lat' => $location['lat'], 'lng' => $location['lng']];
    $_SESSION['location_source'] = 'geoip';

    // Outros mercados disponíveis
    $outrosMercados = array_slice($mercadosDentroRaio, 1, 4);

    echo json_encode([
        'success' => true,
        'detected' => true,
        'disponivel' => true,
        'mercado' => [
            'partner_id' => $mercadoSelecionado['partner_id'],
            'nome' => $mercadoSelecionado['name'],
            'logo' => $mercadoSelecionado['logo'] ?? '',
            'cidade' => $mercadoSelecionado['city'],
            'estado' => $mercadoSelecionado['state'],
            'distancia_km' => $mercadoSelecionado['distancia_km'],
            'tempo_estimado' => $mercadoSelecionado['tempo_estimado'],
            'pedido_minimo' => floatval($mercadoSelecionado['min_order_value'] ?? 0),
            'taxa_entrega' => floatval($mercadoSelecionado['delivery_fee'] ?? 0)
        ],
        'localizacao' => $location,
        'outros_mercados' => $outrosMercados,
        'mensagem' => "Encontramos mercados em {$location['cidade']}!",
        'ip' => $ip
    ]);
    exit;
}

// Ação desconhecida
echo json_encode([
    'success' => false,
    'error' => 'Ação inválida',
    'acoes' => ['detect', 'auto']
]);
