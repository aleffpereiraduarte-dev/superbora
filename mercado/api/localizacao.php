<?php
/**
 * API DE LOCALIZACAO - ONEMUNDO v3.0
 * Detecta mercados proximos baseado no CEP/Endereco do cliente
 *
 * Acoes disponiveis:
 * - verificar_cep: Verifica CEP e retorna mercado mais proximo
 * - selecionar_mercado: Define um mercado especifico
 * - get_mercado_atual: Retorna mercado da sessao
 * - listar_mercados: Lista todos os mercados ativos
 * - listar_enderecos: Lista enderecos do cliente logado
 */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(json_encode(['success' => true]));
}

// Sessao
if (session_status() === PHP_SESSION_NONE) {
    session_name('OCSESSID');
    session_start();
}

// Debug mode
$debug = isset($_GET['debug']);

// Conexao segura via config central
require_once dirname(__DIR__) . '/config/database.php';
try {
    $pdo = getPDO();
} catch (Exception $e) {
    die(json_encode(['success' => false, 'error' => 'Erro de conexao']));
}

// Input
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? $_GET['action'] ?? $_POST['action'] ?? '';
$cep = $input['cep'] ?? $_GET['cep'] ?? $_POST['cep'] ?? '';

$customer_id = $_SESSION['customer_id'] ?? 0;

// ===============================================================================
// FUNCOES
// ===============================================================================

function calcularDistancia($lat1, $lng1, $lat2, $lng2) {
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLng/2) * sin($dLng/2);
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

function buscarCEP($cep) {
    $cep = preg_replace('/\D/', '', $cep);
    if (strlen($cep) !== 8) return null;

    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $response = @file_get_contents("https://viacep.com.br/ws/{$cep}/json/", false, $ctx);

    if (!$response) return null;

    $data = json_decode($response, true);
    return isset($data['erro']) ? null : $data;
}

function geocodificar($endereco) {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 5,
            'header' => "User-Agent: OneMundo/1.0\r\n"
        ]
    ]);

    $url = "https://nominatim.openstreetmap.org/search?format=json&q=" . urlencode($endereco) . "&limit=1";
    $response = @file_get_contents($url, false, $ctx);

    if (!$response) return null;

    $data = json_decode($response, true);
    if (!empty($data[0]['lat'])) {
        return [
            'lat' => floatval($data[0]['lat']),
            'lng' => floatval($data[0]['lon'])
        ];
    }

    return null;
}

function buscarMercadosProximos($pdo, $lat, $lng, $limite = 5) {
    $sql = "SELECT partner_id, name, code, logo, address, city, state,
                   latitude, longitude, raio_entrega_km, min_order_value, delivery_fee
            FROM om_market_partners
            WHERE status = '1'
            AND latitude IS NOT NULL
            AND latitude != 0
            AND longitude IS NOT NULL
            AND longitude != 0";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $mercados = [];
    foreach ($rows as $row) {
        $dist = calcularDistancia($lat, $lng, floatval($row['latitude']), floatval($row['longitude']));
        $raio = floatval($row['raio_entrega_km'] ?: 20);

        $row['distancia_km'] = round($dist, 2);
        $row['dentro_raio'] = $dist <= $raio;
        $row['tempo_estimado'] = ceil($dist * 3); // ~3 min por km

        $mercados[] = $row;
    }

    // Ordenar por distancia
    usort($mercados, function($a, $b) {
        return $a['distancia_km'] <=> $b['distancia_km'];
    });

    return array_slice($mercados, 0, $limite);
}

// ===============================================================================
// ACOES
// ===============================================================================

switch ($action) {

    // ===============================================================================
    // VERIFICAR CEP
    // ===============================================================================
    case 'verificar_cep':
        $cep = preg_replace('/\D/', '', $cep);

        if (strlen($cep) !== 8) {
            echo json_encode(['success' => false, 'error' => 'CEP invalido']);
            exit;
        }

        // Buscar endereco via ViaCEP
        $dadosCep = buscarCEP($cep);

        if (!$dadosCep) {
            echo json_encode(['success' => false, 'error' => 'CEP nao encontrado']);
            exit;
        }

        // Geocodificar
        $endereco = "{$dadosCep['localidade']}, {$dadosCep['uf']}, Brasil";
        $coords = geocodificar($endereco);

        if (!$coords) {
            // Fallback: coordenadas aproximadas por estado
            $fallback = [
                'SP' => ['lat' => -23.5505, 'lng' => -46.6333],
                'RJ' => ['lat' => -22.9068, 'lng' => -43.1729],
                'MG' => ['lat' => -19.9167, 'lng' => -43.9345],
                'BA' => ['lat' => -12.9714, 'lng' => -38.5014],
                'RS' => ['lat' => -30.0346, 'lng' => -51.2177],
                'PR' => ['lat' => -25.4284, 'lng' => -49.2733],
            ];
            $uf = $dadosCep['uf'];
            $coords = $fallback[$uf] ?? ['lat' => -15.7801, 'lng' => -47.9292]; // Brasilia
        }

        // Buscar mercados proximos
        $mercados = buscarMercadosProximos($pdo, $coords['lat'], $coords['lng'], 10);

        if (empty($mercados)) {
            echo json_encode([
                'success' => true,
                'disponivel' => false,
                'mensagem' => 'Ainda nao atendemos sua regiao',
                'localizacao' => [
                    'cep' => $cep,
                    'cidade' => $dadosCep['localidade'],
                    'uf' => $dadosCep['uf'],
                    'endereco' => $dadosCep['logradouro'] ?? ''
                ]
            ]);
            exit;
        }

        // Filtrar apenas mercados dentro do raio de entrega
        $mercadosDentroRaio = array_filter($mercados, function($m) {
            return $m['dentro_raio'] === true;
        });
        $mercadosDentroRaio = array_values($mercadosDentroRaio); // Reindexar

        // Se NENHUM mercado esta dentro do raio, retornar indisponivel com mensagem amigavel
        if (empty($mercadosDentroRaio)) {
            $mercadoMaisProximo = $mercados[0] ?? null;

            // Salvar dados na sessao para o formulario de waitlist
            $_SESSION['waitlist_cep'] = $cep;
            $_SESSION['waitlist_cidade'] = $dadosCep['localidade'];
            $_SESSION['waitlist_uf'] = $dadosCep['uf'];
            $_SESSION['waitlist_bairro'] = $dadosCep['bairro'] ?? '';
            $_SESSION['waitlist_coords'] = $coords;
            $_SESSION['waitlist_mercado_proximo'] = $mercadoMaisProximo ? [
                'nome' => $mercadoMaisProximo['name'],
                'distancia' => $mercadoMaisProximo['distancia_km']
            ] : null;

            echo json_encode([
                'success' => true,
                'disponivel' => false,
                'show_waitlist' => true,
                'mensagem_titulo' => 'Ops! Ainda nao chegamos ai',
                'mensagem' => "Que pena! Ainda nao temos mercados parceiros em {$dadosCep['localidade']} - {$dadosCep['uf']}. Mas estamos expandindo rapidinho!",
                'mensagem_cta' => 'Deixe seu e-mail e avisamos assim que chegarmos na sua regiao!',
                'icone' => 'location-off',
                'localizacao' => [
                    'cep' => $cep,
                    'cidade' => $dadosCep['localidade'],
                    'uf' => $dadosCep['uf'],
                    'endereco' => $dadosCep['logradouro'] ?? '',
                    'bairro' => $dadosCep['bairro'] ?? ''
                ],
                'mercado_mais_proximo' => $mercadoMaisProximo ? [
                    'nome' => $mercadoMaisProximo['name'],
                    'distancia_km' => $mercadoMaisProximo['distancia_km'],
                    'cidade' => $mercadoMaisProximo['city'] ?? ''
                ] : null,
                'waitlist_form' => [
                    'action' => '/mercado/api/localizacao.php?action=salvar_waitlist',
                    'fields' => ['email', 'nome'],
                    'cep_preenchido' => $cep
                ]
            ]);
            exit;
        }

        // Pegar mercado mais proximo DENTRO do raio
        $mercadoSelecionado = $mercadosDentroRaio[0];

        // Salvar na sessao - IMPORTANTE: salva nas DUAS variaveis!
        $_SESSION['market_partner_id'] = $mercadoSelecionado['partner_id'];
        $_SESSION['market_partner_name'] = $mercadoSelecionado['name'];
        $_SESSION['market_partner_code'] = $mercadoSelecionado['code'] ?? '';

        $_SESSION['mercado_proximo'] = [
            'partner_id' => $mercadoSelecionado['partner_id'],
            'nome' => $mercadoSelecionado['name'],
            'distancia' => $mercadoSelecionado['distancia_km'],
            'tempo' => $mercadoSelecionado['tempo_estimado']
        ];

        $_SESSION['customer_cep'] = $cep;
        $_SESSION['customer_coords'] = $coords;
        $_SESSION['customer_endereco'] = "{$dadosCep['logradouro']}, {$dadosCep['bairro']}, {$dadosCep['localidade']} - {$dadosCep['uf']}";

        // Filtrar outros mercados apenas dentro do raio
        $outrosMercados = array_slice($mercadosDentroRaio, 1, 4);

        // Resposta
        $response = [
            'success' => true,
            'disponivel' => true,
            'mercado' => [
                'partner_id' => $mercadoSelecionado['partner_id'],
                'nome' => $mercadoSelecionado['name'],
                'distancia_km' => $mercadoSelecionado['distancia_km'],
                'tempo_estimado' => $mercadoSelecionado['tempo_estimado'],
                'dentro_raio' => true,
                'pedido_minimo' => floatval($mercadoSelecionado['min_order_value'] ?? 0),
                'taxa_entrega' => floatval($mercadoSelecionado['delivery_fee'] ?? 0)
            ],
            'localizacao' => [
                'cep' => $cep,
                'cidade' => $dadosCep['localidade'],
                'uf' => $dadosCep['uf'],
                'endereco' => $dadosCep['logradouro'] ?? '',
                'bairro' => $dadosCep['bairro'] ?? ''
            ],
            'mensagem' => "Entrega em ate {$mercadoSelecionado['tempo_estimado']} minutos!",
            'outros_mercados' => $outrosMercados
        ];

        if ($debug) {
            $response['debug'] = [
                'coords' => $coords,
                'total_mercados' => count($mercados),
                'sessao' => [
                    'market_partner_id' => $_SESSION['market_partner_id'],
                    'mercado_proximo' => $_SESSION['mercado_proximo']
                ]
            ];
        }

        echo json_encode($response);
        break;

    // ===============================================================================
    // SELECIONAR MERCADO
    // ===============================================================================
    case 'selecionar_mercado':
        $partnerId = intval($input['partner_id'] ?? $_GET['partner_id'] ?? $_POST['partner_id'] ?? 0);

        if ($partnerId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Partner ID invalido']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT partner_id, name, code, logo FROM om_market_partners WHERE partner_id = ? AND status = '1'");
        $stmt->execute([$partnerId]);
        $mercado = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$mercado) {
            echo json_encode(['success' => false, 'error' => 'Mercado nao encontrado']);
            exit;
        }

        // Salvar na sessao
        $_SESSION['market_partner_id'] = $mercado['partner_id'];
        $_SESSION['market_partner_name'] = $mercado['name'];
        $_SESSION['market_partner_code'] = $mercado['code'] ?? '';

        $_SESSION['mercado_proximo'] = [
            'partner_id' => $mercado['partner_id'],
            'nome' => $mercado['name']
        ];

        echo json_encode([
            'success' => true,
            'mercado' => $mercado,
            'mensagem' => 'Mercado selecionado!'
        ]);
        break;

    // ===============================================================================
    // OBTER MERCADO ATUAL
    // ===============================================================================
    case 'get_mercado_atual':
    case 'get_partner':
        $partnerId = $_SESSION['market_partner_id'] ?? null;

        if (!$partnerId) {
            echo json_encode([
                'success' => false,
                'error' => 'Nenhum mercado selecionado',
                'precisa_cep' => true
            ]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT partner_id, name, code, logo, address, city, state FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$partnerId]);
        $mercado = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'mercado' => $mercado,
            'cliente' => [
                'cep' => $_SESSION['customer_cep'] ?? null,
                'endereco' => $_SESSION['customer_endereco'] ?? null
            ]
        ]);
        break;

    // ===============================================================================
    // LISTAR MERCADOS
    // ===============================================================================
    case 'listar_mercados':
        $stmt = $pdo->query("
            SELECT partner_id, name, code, logo, city, state, rating
            FROM om_market_partners
            WHERE status = '1'
            ORDER BY name ASC
        ");
        $mercados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'mercados' => $mercados,
            'total' => count($mercados)
        ]);
        break;

    // ===============================================================================
    // LISTAR ENDERECOS DO CLIENTE
    // ===============================================================================
    case 'listar_enderecos':
        if (!$customer_id) {
            echo json_encode(['success' => false, 'error' => 'Nao logado', 'enderecos' => []]);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT a.address_id, a.firstname, a.lastname, a.address_1, a.address_2,
                   a.city, a.postcode, z.code as zone_code
            FROM oc_address a
            LEFT JOIN oc_zone z ON a.zone_id = z.zone_id
            WHERE a.customer_id = ?
            ORDER BY a.address_id DESC
        ");
        $stmt->execute([$customer_id]);
        $enderecos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'enderecos' => $enderecos
        ]);
        break;

    // ===============================================================================
    // SALVAR NA LISTA DE ESPERA (WAITLIST)
    // ===============================================================================
    case 'salvar_waitlist':
    case 'waitlist':
        $email = trim($input['email'] ?? $_POST['email'] ?? '');
        $nome = trim($input['nome'] ?? $_POST['nome'] ?? '');
        $cepWait = preg_replace('/\D/', '', $input['cep'] ?? $_POST['cep'] ?? $_SESSION['waitlist_cep'] ?? '');

        // Validar email
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode([
                'success' => false,
                'error' => 'Por favor, informe um e-mail valido'
            ]);
            exit;
        }

        // Validar CEP
        if (strlen($cepWait) !== 8) {
            echo json_encode([
                'success' => false,
                'error' => 'CEP invalido'
            ]);
            exit;
        }

        // Pegar dados da sessao ou buscar novamente
        $cidadeWait = $_SESSION['waitlist_cidade'] ?? null;
        $ufWait = $_SESSION['waitlist_uf'] ?? null;
        $bairroWait = $_SESSION['waitlist_bairro'] ?? '';
        $coordsWait = $_SESSION['waitlist_coords'] ?? null;
        $mercadoProximoWait = $_SESSION['waitlist_mercado_proximo'] ?? null;

        // Se nao tem na sessao, buscar via ViaCEP
        if (!$cidadeWait || !$ufWait) {
            $dadosCepWait = buscarCEP($cepWait);
            if ($dadosCepWait) {
                $cidadeWait = $dadosCepWait['localidade'];
                $ufWait = $dadosCepWait['uf'];
                $bairroWait = $dadosCepWait['bairro'] ?? '';
            }
        }

        $lat = $coordsWait['lat'] ?? null;
        $lng = $coordsWait['lng'] ?? null;
        $mercadoNome = $mercadoProximoWait['nome'] ?? null;
        $mercadoDist = $mercadoProximoWait['distancia'] ?? null;

        // Inserir ou atualizar na waitlist
        if (isPostgreSQL()) {
            $stmt = $pdo->prepare("
                INSERT INTO om_market_waitlist
                (email, nome, cep, cidade, uf, bairro, latitude, longitude, mercado_proximo_nome, mercado_proximo_distancia)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (email) DO UPDATE SET
                    nome = EXCLUDED.nome,
                    cidade = EXCLUDED.cidade,
                    uf = EXCLUDED.uf,
                    bairro = EXCLUDED.bairro,
                    latitude = EXCLUDED.latitude,
                    longitude = EXCLUDED.longitude,
                    mercado_proximo_nome = EXCLUDED.mercado_proximo_nome,
                    mercado_proximo_distancia = EXCLUDED.mercado_proximo_distancia,
                    updated_at = NOW()
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO om_market_waitlist
                (email, nome, cep, cidade, uf, bairro, latitude, longitude, mercado_proximo_nome, mercado_proximo_distancia)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    nome = VALUES(nome),
                    cidade = VALUES(cidade),
                    uf = VALUES(uf),
                    bairro = VALUES(bairro),
                    latitude = VALUES(latitude),
                    longitude = VALUES(longitude),
                    mercado_proximo_nome = VALUES(mercado_proximo_nome),
                    mercado_proximo_distancia = VALUES(mercado_proximo_distancia),
                    updated_at = NOW()
            ");
        }

        if ($stmt->execute([$email, $nome, $cepWait, $cidadeWait, $ufWait, $bairroWait, $lat, $lng, $mercadoNome, $mercadoDist])) {
            // Limpar dados da sessao
            unset($_SESSION['waitlist_cep'], $_SESSION['waitlist_cidade'], $_SESSION['waitlist_uf'],
                  $_SESSION['waitlist_bairro'], $_SESSION['waitlist_coords'], $_SESSION['waitlist_mercado_proximo']);

            echo json_encode([
                'success' => true,
                'mensagem_titulo' => 'Voce esta na lista!',
                'mensagem' => "Maravilha, {$nome}! Salvamos seu interesse e vamos te avisar em {$email} assim que chegarmos em {$cidadeWait} - {$ufWait}!",
                'mensagem_secundaria' => 'Fique de olho no seu e-mail. Novidades chegando em breve!',
                'icone' => 'check-heart',
                'dados' => [
                    'email' => $email,
                    'cidade' => $cidadeWait,
                    'uf' => $ufWait
                ]
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Erro ao salvar. Tente novamente.'
            ]);
        }
        break;

    // ===============================================================================
    // LISTAR WAITLIST (ADMIN)
    // ===============================================================================
    case 'listar_waitlist':
        $ufFiltro = $input['uf'] ?? $_GET['uf'] ?? '';
        $cidadeFiltro = $input['cidade'] ?? $_GET['cidade'] ?? '';

        $sql = "SELECT id, email, nome, cep, cidade, uf, bairro, mercado_proximo_nome,
                       mercado_proximo_distancia, notificado, created_at
                FROM om_market_waitlist WHERE 1=1";
        $params = [];

        if ($ufFiltro) {
            $sql .= " AND uf = ?";
            $params[] = $ufFiltro;
        }
        if ($cidadeFiltro) {
            $sql .= " AND cidade LIKE ?";
            $params[] = "%{$cidadeFiltro}%";
        }

        $sql .= " ORDER BY created_at DESC LIMIT 500";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $lista = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Estatisticas por UF
        $statsStmt = $pdo->query("
            SELECT uf, COUNT(*) as total
            FROM om_market_waitlist
            GROUP BY uf
            ORDER BY total DESC
        ");
        $estatisticas = $statsStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'waitlist' => $lista,
            'total' => count($lista),
            'estatisticas_por_uf' => $estatisticas
        ]);
        break;

    // ===============================================================================
    // DEFAULT
    // ===============================================================================
    default:
        echo json_encode([
            'success' => true,
            'api' => 'OneMundo Localizacao v3.1',
            'acoes' => ['verificar_cep', 'selecionar_mercado', 'get_mercado_atual', 'listar_mercados', 'listar_enderecos', 'salvar_waitlist', 'listar_waitlist'],
            'exemplo' => '?action=verificar_cep&cep=01310100'
        ]);
}
