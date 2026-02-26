<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * API DE FRETE INTELIGENTE COM PONTO DE APOIO - OneMundo Mercado
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Sistema inteligente de logistica que:
 * 1. Se vendedor E ponto de apoio → cliente retira la (GRATIS)
 * 2. Se tem ponto de apoio perto do cliente → cliente retira la (GRATIS)
 * 3. Se cliente longe → Vendedor envia pra ponto de apoio (moto) → Ponto envia pro cliente (moto)
 *
 * Rotas:
 * - retirada_vendedor: Cliente retira no vendedor (se for ponto de apoio)
 * - retirada_ponto: Cliente retira em ponto de apoio proximo
 * - entrega_direta: Moto taxi direto do vendedor pro cliente
 * - entrega_via_ponto: Vendedor → Ponto Apoio → Cliente (duas etapas de moto)
 * - correios: Melhor Envio (Correios/Transportadoras)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Configuracoes
define('RAIO_RETIRADA_KM', 15);      // Distancia maxima para cliente ir retirar
define('RAIO_PONTO_VENDEDOR_KM', 20); // Distancia maxima vendedor → ponto de apoio
define('RAIO_PONTO_CLIENTE_KM', 30);  // Distancia maxima ponto de apoio → cliente
define('PRECO_MOTO_KM', 2.50);        // Preco por km de moto taxi
define('PRECO_MOTO_MINIMO', 8.00);    // Preco minimo moto taxi
define('TEMPO_MOTO_KM_MIN', 3);       // Minutos por km de moto
define('FLUXO_PONTO_APENAS_MESMA_CIDADE', true); // Ponto de apoio so na mesma cidade

// Conectar ao banco
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getPDO();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexao']);
    exit;
}

// Sessao
session_name('OCSESSID');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$customer_id = $_SESSION['customer_id'] ?? 0;

// Input
$input_raw = file_get_contents('php://input');
$input = json_decode($input_raw, true) ?? [];
$action = $input['action'] ?? $_GET['action'] ?? $_POST['action'] ?? 'calcular';

/**
 * Calcular distancia entre dois pontos usando Haversine
 */
function calcularDistancia($lat1, $lng1, $lat2, $lng2) {
    if (!$lat1 || !$lng1 || !$lat2 || !$lng2) return null;

    $earthRadius = 6371; // km

    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);

    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLng/2) * sin($dLng/2);

    $c = 2 * atan2(sqrt($a), sqrt(1-$a));

    return $earthRadius * $c;
}

/**
 * Calcular preco de moto taxi por distancia
 */
function calcularPrecoMoto($distancia_km) {
    $preco = $distancia_km * PRECO_MOTO_KM;
    return max(PRECO_MOTO_MINIMO, round($preco, 2));
}

/**
 * Calcular tempo de moto taxi por distancia
 */
function calcularTempoMoto($distancia_km) {
    return max(15, round($distancia_km * TEMPO_MOTO_KM_MIN));
}

/**
 * Buscar coordenadas pelo CEP
 */
function geocodificarCEP($cep) {
    $cep = preg_replace('/\D/', '', $cep);
    if (strlen($cep) !== 8) return null;

    // Buscar no ViaCEP
    $viaCep = @file_get_contents("https://viacep.com.br/ws/{$cep}/json/");
    if (!$viaCep) return null;

    $endereco = json_decode($viaCep, true);
    if (isset($endereco['erro'])) return null;

    // Geocodificar usando Nominatim
    $query = urlencode("{$endereco['logradouro']}, {$endereco['bairro']}, {$endereco['localidade']}, {$endereco['uf']}, Brasil");
    $nominatim = @file_get_contents(
        "https://nominatim.openstreetmap.org/search?q={$query}&format=json&limit=1",
        false,
        stream_context_create(['http' => ['header' => 'User-Agent: OneMundoMercado/1.0']])
    );

    $lat = null;
    $lng = null;

    if ($nominatim) {
        $coords = json_decode($nominatim, true);
        if (!empty($coords)) {
            $lat = floatval($coords[0]['lat']);
            $lng = floatval($coords[0]['lon']);
        }
    }

    // Fallback: tentar so com cidade
    if (!$lat) {
        $queryCidade = urlencode("{$endereco['localidade']}, {$endereco['uf']}, Brasil");
        $nominatim = @file_get_contents(
            "https://nominatim.openstreetmap.org/search?q={$queryCidade}&format=json&limit=1",
            false,
            stream_context_create(['http' => ['header' => 'User-Agent: OneMundoMercado/1.0']])
        );

        if ($nominatim) {
            $coords = json_decode($nominatim, true);
            if (!empty($coords)) {
                $lat = floatval($coords[0]['lat']);
                $lng = floatval($coords[0]['lon']);
            }
        }
    }

    return [
        'cep' => $cep,
        'logradouro' => $endereco['logradouro'] ?? '',
        'bairro' => $endereco['bairro'] ?? '',
        'cidade' => $endereco['localidade'] ?? '',
        'estado' => $endereco['uf'] ?? '',
        'lat' => $lat,
        'lng' => $lng
    ];
}

/**
 * Buscar dados do vendedor
 */
function getVendedor($pdo, $seller_id) {
    $stmt = $pdo->prepare("
        SELECT seller_id, store_name, store_address, store_latitude, store_longitude,
               is_ponto_apoio, ponto_apoio_status, ponto_capacidade, ponto_pacotes_atuais,
               ponto_horario_abertura, ponto_horario_fechamento, ponto_dias_funcionamento,
               store_city, store_state
        FROM oc_purpletree_vendor_stores
        WHERE seller_id = ?
    ");
    $stmt->execute([$seller_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Verifica se vendedor e cliente estao na mesma cidade
 */
function mesmaCidade($vendedor_cidade, $cliente_cidade) {
    if (empty($vendedor_cidade) || empty($cliente_cidade)) return false;
    // Normalizar para comparacao
    $v = mb_strtolower(trim(preg_replace('/\s+/', ' ', $vendedor_cidade)));
    $c = mb_strtolower(trim(preg_replace('/\s+/', ' ', $cliente_cidade)));
    return $v === $c;
}

/**
 * Buscar pontos de apoio ativos proximos a uma coordenada
 */
function buscarPontosApoioProximos($pdo, $lat, $lng, $raio_km, $excluir_seller_id = null) {
    $sql = "
        SELECT seller_id, store_name, store_address, store_latitude, store_longitude,
               ponto_capacidade, ponto_pacotes_atuais, ponto_horario_abertura,
               ponto_horario_fechamento, ponto_dias_funcionamento,
               ponto_taxa_recebimento, ponto_taxa_despacho,
               (6371 * acos(cos(radians(?)) * cos(radians(store_latitude)) *
                cos(radians(store_longitude) - radians(?)) +
                sin(radians(?)) * sin(radians(store_latitude)))) AS distancia
        FROM oc_purpletree_vendor_stores
        WHERE is_ponto_apoio = 1
          AND ponto_apoio_status = 'ativo'
          AND store_latitude IS NOT NULL
          AND store_longitude IS NOT NULL
          AND ponto_pacotes_atuais < ponto_capacidade
    ";

    $params = [$lat, $lng, $lat];

    if ($excluir_seller_id) {
        $sql .= " AND seller_id != ?";
        $params[] = $excluir_seller_id;
    }

    $sql .= " HAVING distancia <= ? ORDER BY distancia LIMIT 5";
    $params[] = $raio_km;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Calcular frete via Melhor Envio
 */
function calcularMelhorEnvio($pdo, $cep_origem, $cep_destino, $peso, $valor) {
    try {
        $stmt = $pdo->query("SELECT valor FROM om_entrega_config WHERE chave = 'melhor_envio_token'");
        $token = $stmt->fetchColumn();
        if (!$token) return null;

        $payload = [
            'from' => ['postal_code' => preg_replace('/\D/', '', $cep_origem)],
            'to' => ['postal_code' => preg_replace('/\D/', '', $cep_destino)],
            'products' => [[
                'id' => '1',
                'width' => 15,
                'height' => 10,
                'length' => 20,
                'weight' => max(0.3, $peso),
                'insurance_value' => $valor,
                'quantity' => 1
            ]],
            'services' => '1,2,3,4,17'
        ];

        $ch = curl_init('https://melhorenvio.com.br/api/v2/me/shipment/calculate');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
                'User-Agent: OneMundoMercado/1.0'
            ],
            CURLOPT_TIMEOUT => 15
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);
        $opcoes = [];

        if (is_array($result)) {
            foreach ($result as $servico) {
                if (isset($servico['error']) || !isset($servico['price'])) continue;

                $opcoes[] = [
                    'id' => 'me_' . $servico['id'],
                    'nome' => $servico['name'] ?? $servico['company']['name'],
                    'empresa' => $servico['company']['name'] ?? 'Correios',
                    'preco' => (float)$servico['price'],
                    'prazo_dias' => (int)$servico['delivery_time']
                ];
            }
        }

        return $opcoes;
    } catch (Exception $e) {
        return null;
    }
}

switch ($action) {

    // ════════════════════════════════════════════════════════════════════════════
    // CALCULAR - Calculo inteligente de frete com ponto de apoio
    // ════════════════════════════════════════════════════════════════════════════
    case 'calcular':
        $seller_id = intval($input['seller_id'] ?? $_GET['seller_id'] ?? $_POST['seller_id'] ?? 0);
        $cep_destino = preg_replace('/\D/', '', $input['cep'] ?? $_GET['cep'] ?? $_POST['cep'] ?? '');
        $subtotal = floatval($input['subtotal'] ?? $_GET['subtotal'] ?? $_POST['subtotal'] ?? 0);
        $peso = floatval($input['peso'] ?? $_GET['peso'] ?? $_POST['peso'] ?? 1);

        if (!$seller_id) {
            echo json_encode(['success' => false, 'error' => 'Vendedor nao informado']);
            exit;
        }

        if (strlen($cep_destino) !== 8) {
            echo json_encode(['success' => false, 'error' => 'CEP invalido']);
            exit;
        }

        // Buscar dados do vendedor
        $vendedor = getVendedor($pdo, $seller_id);
        if (!$vendedor) {
            echo json_encode(['success' => false, 'error' => 'Vendedor nao encontrado']);
            exit;
        }

        // Geocodificar CEP do cliente
        $cliente = geocodificarCEP($cep_destino);
        if (!$cliente || !$cliente['lat']) {
            echo json_encode(['success' => false, 'error' => 'Nao foi possivel localizar o CEP']);
            exit;
        }

        $opcoes = [];
        $vendedor_lat = floatval($vendedor['store_latitude']);
        $vendedor_lng = floatval($vendedor['store_longitude']);
        $cliente_lat = $cliente['lat'];
        $cliente_lng = $cliente['lng'];

        // Calcular distancia vendedor → cliente
        $distancia_direta = calcularDistancia($vendedor_lat, $vendedor_lng, $cliente_lat, $cliente_lng);

        // Verificar se e mesma cidade (para fluxo via ponto de apoio)
        $vendedor_cidade = $vendedor['store_city'] ?? '';
        $cliente_cidade = $cliente['cidade'] ?? '';
        $is_mesma_cidade = mesmaCidade($vendedor_cidade, $cliente_cidade);

        // ════════════════════════════════════════════════════════════════════════
        // CENARIO 1: Vendedor E ponto de apoio → Retirada gratis (MESMA CIDADE)
        // ════════════════════════════════════════════════════════════════════════
        if ($is_mesma_cidade && $vendedor['is_ponto_apoio'] && $vendedor['ponto_apoio_status'] === 'ativo') {
            $opcoes[] = [
                'id' => 'retirada_vendedor',
                'tipo' => 'retirada',
                'nome' => 'Retirar na Loja',
                'descricao' => 'Retire seu pedido em ' . $vendedor['store_name'],
                'local' => [
                    'nome' => $vendedor['store_name'],
                    'endereco' => $vendedor['store_address'],
                    'lat' => $vendedor_lat,
                    'lng' => $vendedor_lng,
                    'horario' => $vendedor['ponto_horario_abertura'] . ' - ' . $vendedor['ponto_horario_fechamento'],
                    'dias' => $vendedor['ponto_dias_funcionamento']
                ],
                'preco' => 0,
                'preco_texto' => 'GRATIS',
                'prazo_texto' => 'Disponivel apos preparo',
                'is_free' => true,
                'distancia_km' => $distancia_direta ? round($distancia_direta, 1) : null,
                'badge' => 'Economize no frete!'
            ];
        }

        // ════════════════════════════════════════════════════════════════════════
        // CENARIO 2: Pontos de apoio proximos ao cliente → Retirada gratis (MESMA CIDADE)
        // ════════════════════════════════════════════════════════════════════════
        if ($is_mesma_cidade && $cliente_lat && $cliente_lng) {
            $pontos_cliente = buscarPontosApoioProximos($pdo, $cliente_lat, $cliente_lng, RAIO_RETIRADA_KM, $seller_id);

            foreach ($pontos_cliente as $ponto) {
                $opcoes[] = [
                    'id' => 'retirada_ponto_' . $ponto['seller_id'],
                    'tipo' => 'retirada_ponto',
                    'nome' => 'Retirar em Ponto de Apoio',
                    'descricao' => 'Retire em ' . $ponto['store_name'] . ' (a ' . round($ponto['distancia'], 1) . ' km de voce)',
                    'local' => [
                        'id' => $ponto['seller_id'],
                        'nome' => $ponto['store_name'],
                        'endereco' => $ponto['store_address'],
                        'lat' => floatval($ponto['store_latitude']),
                        'lng' => floatval($ponto['store_longitude']),
                        'horario' => $ponto['ponto_horario_abertura'] . ' - ' . $ponto['ponto_horario_fechamento'],
                        'dias' => $ponto['ponto_dias_funcionamento']
                    ],
                    'preco' => 0,
                    'preco_texto' => 'GRATIS',
                    'prazo_texto' => '1-3 dias uteis',
                    'is_free' => true,
                    'distancia_km' => round($ponto['distancia'], 1),
                    'badge' => 'Sem custo de frete!'
                ];
            }
        }

        // ════════════════════════════════════════════════════════════════════════
        // CENARIO 3: Entrega via Ponto de Apoio (Vendedor → Ponto → Cliente) - MESMA CIDADE
        // REGRA ONEMUNDO: Nunca existe vendedor → cliente direto
        // TODAS as entregas passam obrigatoriamente por um Ponto de Apoio
        // ════════════════════════════════════════════════════════════════════════
        if ($is_mesma_cidade && $vendedor_lat && $vendedor_lng) {
            // Buscar pontos de apoio proximos ao VENDEDOR
            $pontos_vendedor = buscarPontosApoioProximos($pdo, $vendedor_lat, $vendedor_lng, RAIO_PONTO_VENDEDOR_KM, $seller_id);

            foreach ($pontos_vendedor as $ponto) {
                $ponto_lat = floatval($ponto['store_latitude']);
                $ponto_lng = floatval($ponto['store_longitude']);

                // Calcular distancias
                $dist_vendedor_ponto = calcularDistancia($vendedor_lat, $vendedor_lng, $ponto_lat, $ponto_lng);
                $dist_ponto_cliente = calcularDistancia($ponto_lat, $ponto_lng, $cliente_lat, $cliente_lng);

                // Verificar se a rota via ponto faz sentido (ponto mais perto do cliente)
                if ($dist_ponto_cliente && $dist_ponto_cliente <= RAIO_PONTO_CLIENTE_KM) {
                    // Calcular precos de cada trecho
                    $preco_trecho1 = calcularPrecoMoto($dist_vendedor_ponto);
                    $preco_trecho2 = calcularPrecoMoto($dist_ponto_cliente);
                    $taxa_ponto = floatval($ponto['ponto_taxa_recebimento']) + floatval($ponto['ponto_taxa_despacho']);

                    $preco_total = $preco_trecho1 + $preco_trecho2 + $taxa_ponto;

                    // Tempo estimado
                    $tempo_trecho1 = calcularTempoMoto($dist_vendedor_ponto);
                    $tempo_trecho2 = calcularTempoMoto($dist_ponto_cliente);
                    $tempo_total = $tempo_trecho1 + $tempo_trecho2 + 60; // +1h para processar no ponto

                    $opcoes[] = [
                        'id' => 'via_ponto_' . $ponto['seller_id'],
                        'tipo' => 'via_ponto',
                        'nome' => 'Entrega via Ponto de Apoio',
                        'descricao' => 'Rota: Vendedor → ' . $ponto['store_name'] . ' → Voce',
                        'ponto_apoio' => [
                            'id' => $ponto['seller_id'],
                            'nome' => $ponto['store_name'],
                            'endereco' => $ponto['store_address']
                        ],
                        'rota' => [
                            'trecho1' => [
                                'de' => $vendedor['store_name'],
                                'para' => $ponto['store_name'],
                                'distancia_km' => round($dist_vendedor_ponto, 1),
                                'preco' => $preco_trecho1,
                                'tempo_min' => $tempo_trecho1
                            ],
                            'trecho2' => [
                                'de' => $ponto['store_name'],
                                'para' => 'Cliente',
                                'distancia_km' => round($dist_ponto_cliente, 1),
                                'preco' => $preco_trecho2,
                                'tempo_min' => $tempo_trecho2
                            ],
                            'taxa_ponto' => $taxa_ponto
                        ],
                        'preco' => round($preco_total, 2),
                        'preco_texto' => 'R$ ' . number_format($preco_total, 2, ',', '.'),
                        'prazo_minutos' => $tempo_total,
                        'prazo_texto' => $tempo_total < 120 ? $tempo_total . ' min' : round($tempo_total / 60, 1) . 'h',
                        'is_free' => false,
                        'distancia_total_km' => round($dist_vendedor_ponto + $dist_ponto_cliente, 1)
                    ];
                }
            }
        }

        // ════════════════════════════════════════════════════════════════════════
        // CENARIO 5: Melhor Envio (Correios/Transportadoras) - Fallback
        // ════════════════════════════════════════════════════════════════════════
        // Buscar CEP do vendedor
        $cep_vendedor = '01310100'; // Default SP
        if ($vendedor['store_address']) {
            if (preg_match('/(\d{5}-?\d{3})/', $vendedor['store_address'], $matches)) {
                $cep_vendedor = preg_replace('/\D/', '', $matches[1]);
            }
        }

        $me_opcoes = calcularMelhorEnvio($pdo, $cep_vendedor, $cep_destino, $peso, $subtotal);
        if ($me_opcoes) {
            foreach ($me_opcoes as $me) {
                $opcoes[] = [
                    'id' => $me['id'],
                    'tipo' => 'correios',
                    'nome' => $me['nome'],
                    'empresa' => $me['empresa'],
                    'preco' => $me['preco'],
                    'preco_texto' => 'R$ ' . number_format($me['preco'], 2, ',', '.'),
                    'prazo_dias' => $me['prazo_dias'],
                    'prazo_texto' => $me['prazo_dias'] . ' dias uteis',
                    'is_free' => false
                ];
            }
        }

        // Ordenar: gratis primeiro, depois por preco
        usort($opcoes, function($a, $b) {
            if ($a['is_free'] && !$b['is_free']) return -1;
            if (!$a['is_free'] && $b['is_free']) return 1;
            return $a['preco'] <=> $b['preco'];
        });

        echo json_encode([
            'success' => true,
            'opcoes' => $opcoes,
            'total_opcoes' => count($opcoes),
            'vendedor' => [
                'id' => $vendedor['seller_id'],
                'nome' => $vendedor['store_name'],
                'is_ponto_apoio' => (bool)$vendedor['is_ponto_apoio'],
                'lat' => $vendedor_lat,
                'lng' => $vendedor_lng
            ],
            'cliente' => [
                'cep' => $cep_destino,
                'cidade' => $cliente['cidade'],
                'estado' => $cliente['estado'],
                'lat' => $cliente_lat,
                'lng' => $cliente_lng
            ],
            'distancia_direta_km' => $distancia_direta ? round($distancia_direta, 1) : null
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ════════════════════════════════════════════════════════════════════════════
    // PONTOS - Listar pontos de apoio proximos
    // ════════════════════════════════════════════════════════════════════════════
    case 'pontos':
        $lat = floatval($input['lat'] ?? $_GET['lat'] ?? 0);
        $lng = floatval($input['lng'] ?? $_GET['lng'] ?? 0);
        $raio = floatval($input['raio'] ?? $_GET['raio'] ?? 20);

        if (!$lat || !$lng) {
            // Tentar por CEP
            $cep = preg_replace('/\D/', '', $input['cep'] ?? $_GET['cep'] ?? '');
            if (strlen($cep) === 8) {
                $geo = geocodificarCEP($cep);
                if ($geo && $geo['lat']) {
                    $lat = $geo['lat'];
                    $lng = $geo['lng'];
                }
            }
        }

        if (!$lat || !$lng) {
            echo json_encode(['success' => false, 'error' => 'Localizacao nao informada']);
            exit;
        }

        $pontos = buscarPontosApoioProximos($pdo, $lat, $lng, $raio);

        echo json_encode([
            'success' => true,
            'pontos' => array_map(function($p) {
                return [
                    'id' => $p['seller_id'],
                    'nome' => $p['store_name'],
                    'endereco' => $p['store_address'],
                    'lat' => floatval($p['store_latitude']),
                    'lng' => floatval($p['store_longitude']),
                    'distancia_km' => round($p['distancia'], 1),
                    'capacidade' => $p['ponto_capacidade'],
                    'ocupacao' => $p['ponto_pacotes_atuais'],
                    'disponivel' => $p['ponto_capacidade'] - $p['ponto_pacotes_atuais'],
                    'horario' => $p['ponto_horario_abertura'] . ' - ' . $p['ponto_horario_fechamento'],
                    'dias' => $p['ponto_dias_funcionamento']
                ];
            }, $pontos),
            'total' => count($pontos)
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ════════════════════════════════════════════════════════════════════════════
    // MAPA - Dados para exibir mapa com pontos de apoio
    // ════════════════════════════════════════════════════════════════════════════
    case 'mapa':
        $stmt = $pdo->query("
            SELECT seller_id, store_name, store_address, store_latitude, store_longitude,
                   ponto_capacidade, ponto_pacotes_atuais, ponto_horario_abertura,
                   ponto_horario_fechamento, ponto_dias_funcionamento
            FROM oc_purpletree_vendor_stores
            WHERE is_ponto_apoio = 1
              AND ponto_apoio_status = 'ativo'
              AND store_latitude IS NOT NULL
              AND store_longitude IS NOT NULL
        ");
        $pontos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'pontos' => array_map(function($p) {
                return [
                    'id' => $p['seller_id'],
                    'nome' => $p['store_name'],
                    'endereco' => $p['store_address'],
                    'lat' => floatval($p['store_latitude']),
                    'lng' => floatval($p['store_longitude']),
                    'disponivel' => $p['ponto_capacidade'] - $p['ponto_pacotes_atuais'],
                    'horario' => substr($p['ponto_horario_abertura'], 0, 5) . '-' . substr($p['ponto_horario_fechamento'], 0, 5)
                ];
            }, $pontos),
            'total' => count($pontos)
        ], JSON_UNESCAPED_UNICODE);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Acao invalida']);
}
