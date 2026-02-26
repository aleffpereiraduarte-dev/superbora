<?php
require_once __DIR__ . '/../config/database.php';
/**
 * API de Geolocalização - OneMundo Mercado
 * Detecta localização por IP e verifica mercados próximos
 */

// IMPORTANTE: Iniciar sessão com mesmo nome do OpenCart ANTES de qualquer output
if (session_status() === PHP_SESSION_NONE) {
    session_name('OCSESSID');
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Configuração
define('RAIO_BUSCA_KM', 50); // Raio de busca em km

$pdo = getPDO();

$action = $_GET['action'] ?? $_POST['action'] ?? 'detect';

switch ($action) {

    // Detectar localização por IP
    case 'detect':
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

        // Limpar IP (pegar primeiro se houver múltiplos)
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }

        // IP local = usar IP público
        if (in_array($ip, ['127.0.0.1', '::1', 'localhost']) || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
            // Tentar pegar IP público
            $publicIp = @file_get_contents('https://api.ipify.org?format=text');
            if ($publicIp) $ip = trim($publicIp);
        }

        // Buscar localização pelo IP (usando ip-api.com - gratuito)
        $geoData = @file_get_contents("http://ip-api.com/json/{$ip}?lang=pt-BR&fields=status,message,country,regionName,city,lat,lon");

        if ($geoData) {
            $geo = json_decode($geoData, true);

            if ($geo && $geo['status'] === 'success') {
                $lat = $geo['lat'];
                $lng = $geo['lon'];
                $cidade = $geo['city'];
                $estado = $geo['regionName'];

                // Buscar mercado mais próximo
                $mercado = buscarMercadoProximo($pdo, $lat, $lng);

                echo json_encode([
                    'success' => true,
                    'detected' => true,
                    'location' => [
                        'cidade' => $cidade,
                        'estado' => $estado,
                        'lat' => $lat,
                        'lng' => $lng
                    ],
                    'mercado' => $mercado,
                    'tem_cobertura' => $mercado !== null
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'detected' => false,
                    'message' => 'Não foi possível detectar sua localização'
                ]);
            }
        } else {
            echo json_encode([
                'success' => true,
                'detected' => false,
                'message' => 'Serviço de geolocalização indisponível'
            ]);
        }
        break;

    // Buscar por CEP
    case 'cep':
        $cep = preg_replace('/[^0-9]/', '', $_GET['cep'] ?? $_POST['cep'] ?? '');

        if (strlen($cep) !== 8) {
            echo json_encode(['success' => false, 'error' => 'CEP inválido']);
            exit;
        }

        // Buscar no ViaCEP
        $viaCep = @file_get_contents("https://viacep.com.br/ws/{$cep}/json/");

        if (!$viaCep) {
            echo json_encode(['success' => false, 'error' => 'Erro ao consultar CEP']);
            exit;
        }

        $endereco = json_decode($viaCep, true);

        if (isset($endereco['erro'])) {
            echo json_encode(['success' => false, 'error' => 'CEP não encontrado']);
            exit;
        }

        // Geocodificar o endereço (usando Nominatim - gratuito)
        $query = urlencode("{$endereco['logradouro']}, {$endereco['bairro']}, {$endereco['localidade']}, {$endereco['uf']}, Brasil");
        $nominatim = @file_get_contents("https://nominatim.openstreetmap.org/search?q={$query}&format=json&limit=1", false, stream_context_create([
            'http' => ['header' => 'User-Agent: OneMundoMercado/1.0']
        ]));

        $lat = null;
        $lng = null;

        if ($nominatim) {
            $coords = json_decode($nominatim, true);
            if (!empty($coords)) {
                $lat = floatval($coords[0]['lat']);
                $lng = floatval($coords[0]['lon']);
            }
        }

        // Se não conseguiu geocodificar, tentar só com cidade
        if (!$lat) {
            $queryCidade = urlencode("{$endereco['localidade']}, {$endereco['uf']}, Brasil");
            $nominatim = @file_get_contents("https://nominatim.openstreetmap.org/search?q={$queryCidade}&format=json&limit=1", false, stream_context_create([
                'http' => ['header' => 'User-Agent: OneMundoMercado/1.0']
            ]));

            if ($nominatim) {
                $coords = json_decode($nominatim, true);
                if (!empty($coords)) {
                    $lat = floatval($coords[0]['lat']);
                    $lng = floatval($coords[0]['lon']);
                }
            }
        }

        // Buscar mercado próximo
        $mercado = null;
        if ($lat && $lng) {
            $mercado = buscarMercadoProximo($pdo, $lat, $lng);
        } else {
            // Fallback: buscar por cidade
            $mercado = buscarMercadoPorCidade($pdo, $endereco['localidade'], $endereco['uf']);
        }

        echo json_encode([
            'success' => true,
            'endereco' => [
                'cep' => $cep,
                'logradouro' => $endereco['logradouro'],
                'bairro' => $endereco['bairro'],
                'cidade' => $endereco['localidade'],
                'estado' => $endereco['uf'],
                'lat' => $lat,
                'lng' => $lng
            ],
            'mercado' => $mercado,
            'tem_cobertura' => $mercado !== null
        ]);
        break;

    // Listar mercados disponíveis (apenas com produtos)
    case 'mercados':
        $stmt = $pdo->query("
            SELECT p.partner_id, p.name, p.city, p.state, p.lat, p.lng, p.delivery_fee, p.delivery_time_min
            FROM om_market_partners p
            WHERE p.status = '1'
              AND EXISTS (SELECT 1 FROM om_market_products_price pp WHERE pp.partner_id = p.partner_id AND pp.status = '1' AND pp.price > 0)
            ORDER BY p.name
        ");
        $mercados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'mercados' => $mercados,
            'total' => count($mercados)
        ]);
        break;

    // Selecionar mercado manualmente
    case 'selecionar':
        $partner_id = intval($_POST['partner_id'] ?? 0);

        if (!$partner_id) {
            echo json_encode(['success' => false, 'error' => 'Mercado não informado']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM om_market_partners WHERE partner_id = ? AND status = '1'");
        $stmt->execute([$partner_id]);
        $mercado = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($mercado) {
            $_SESSION['market_partner_id'] = $mercado['partner_id'];
            $_SESSION['market_name'] = $mercado['name'];
            $_SESSION['cep_cidade'] = $mercado['city'];
            $_SESSION['cep_estado'] = $mercado['state'];

            echo json_encode([
                'success' => true,
                'mercado' => [
                    'id' => $mercado['partner_id'],
                    'nome' => $mercado['name'],
                    'cidade' => $mercado['city'],
                    'estado' => $mercado['state']
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Mercado não encontrado']);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Ação inválida']);
}

/**
 * Buscar mercado mais próximo por coordenadas
 * IMPORTANTE: Só retorna mercados que têm produtos cadastrados
 */
function buscarMercadoProximo($pdo, $lat, $lng) {
    // Fórmula de Haversine para calcular distância
    // Inclui verificação se mercado tem produtos cadastrados
    $stmt = $pdo->prepare("
        SELECT p.partner_id, p.name, p.city, p.state, p.lat, p.lng, p.delivery_fee, p.delivery_time_min,
               (6371 * acos(cos(radians(?)) * cos(radians(p.lat)) * cos(radians(p.lng) - radians(?)) + sin(radians(?)) * sin(radians(p.lat)))) AS distancia
        FROM om_market_partners p
        WHERE p.status = '1' AND p.lat IS NOT NULL AND p.lng IS NOT NULL
          AND EXISTS (SELECT 1 FROM om_market_products_price pp WHERE pp.partner_id = p.partner_id AND pp.status = '1' AND pp.price > 0)
        HAVING distancia <= ?
        ORDER BY distancia
        LIMIT 1
    ");
    $stmt->execute([$lat, $lng, $lat, RAIO_BUSCA_KM]);
    $mercado = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($mercado) {
        return [
            'id' => $mercado['partner_id'],
            'nome' => $mercado['name'],
            'cidade' => $mercado['city'],
            'estado' => $mercado['state'],
            'distancia_km' => round($mercado['distancia'], 1),
            'taxa_entrega' => floatval($mercado['delivery_fee']),
            'tempo_entrega' => intval($mercado['delivery_time_min'])
        ];
    }

    return null;
}

/**
 * Buscar mercado por cidade (fallback)
 * IMPORTANTE: Só retorna mercados que têm produtos cadastrados
 */
function buscarMercadoPorCidade($pdo, $cidade, $estado) {
    $stmt = $pdo->prepare("
        SELECT p.partner_id, p.name, p.city, p.state, p.delivery_fee, p.delivery_time_min
        FROM om_market_partners p
        WHERE p.status = '1' AND (p.city LIKE ? OR p.city LIKE ?)
          AND EXISTS (SELECT 1 FROM om_market_products_price pp WHERE pp.partner_id = p.partner_id AND pp.status = '1' AND pp.price > 0)
        ORDER BY p.name
        LIMIT 1
    ");
    $stmt->execute(["%{$cidade}%", "%{$estado}%"]);
    $mercado = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($mercado) {
        return [
            'id' => $mercado['partner_id'],
            'nome' => $mercado['name'],
            'cidade' => $mercado['city'],
            'estado' => $mercado['state'],
            'taxa_entrega' => floatval($mercado['delivery_fee']),
            'tempo_entrega' => intval($mercado['delivery_time_min'])
        ];
    }

    return null;
}
