<?php
/**
 * HOME DO MERCADO - OneMundo
 *
 * Retorna produtos do mercado mais proximo baseado no CEP
 *
 * FLUXO:
 * 1. Cliente LOGADO: pega CEP do endereco dele
 * 2. Cliente NAO LOGADO: recebe CEP por parametro
 * 3. Busca mercado mais proximo
 * 4. Se NAO tem mercado: retorna erro + pede nome/email
 * 5. Se TEM mercado: retorna produtos
 *
 * GET /api/mercado/home.php (logado - usa endereco do cliente)
 * GET /api/mercado/home.php?cep=01310100 (nao logado)
 * POST /api/mercado/home.php (salvar na lista de espera)
 */

require_once dirname(dirname(__DIR__)) . '/database.php';
require_once dirname(dirname(__DIR__)) . '/cache/CacheHelper.php';

// CORS: origin whitelist (replaces Access-Control-Allow-Origin: *)
$_corsAllowed = ['https://superbora.com.br', 'https://www.superbora.com.br', 'https://onemundo.com.br', 'https://www.onemundo.com.br'];
$_corsOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($_corsOrigin, $_corsAllowed, true)) {
    header("Access-Control-Allow-Origin: " . $_corsOrigin);
    header("Vary: Origin");
}
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

try {
    $db = getDB();

    // POST = salvar na lista de espera
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        salvarListaEspera($db);
        exit;
    }

    // GET = buscar produtos
    $cep = null;
    $customerId = null;

    // 1. Verificar se cliente esta logado
    session_set_cookie_params(['secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    if (isset($_SESSION['customer_id'])) {
        $customerId = $_SESSION['customer_id'];

        // Buscar CEP do endereco padrao
        $stmt = $db->prepare("
            SELECT cep FROM om_customer_addresses
            WHERE customer_id = ?
            ORDER BY is_default DESC, created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$customerId]);
        $endereco = $stmt->fetch();

        if ($endereco && $endereco['cep']) {
            $cep = preg_replace('/\D/', '', $endereco['cep']);
        }
    }

    // 2. Se nao tem CEP do cliente logado, pegar do parametro
    if (!$cep && isset($_GET['cep'])) {
        $cep = preg_replace('/\D/', '', $_GET['cep']);
    }

    // 3. Se ainda nao tem CEP, pedir
    if (!$cep || strlen($cep) !== 8) {
        response(true, [
            "status" => "precisa_cep",
            "mensagem" => "Informe seu CEP para ver os produtos disponiveis",
            "tem_mercado" => null
        ]);
    }

    // 4. Buscar mercado mais proximo
    $mercado = buscarMercadoMaisProximo($db, $cep);

    if (!$mercado) {
        // Nao tem mercado - retornar info para lista de espera
        $cidadeInfo = buscarCidadePorCep($cep);

        response(true, [
            "status" => "sem_mercado",
            "mensagem" => "Ainda nao temos mercado na sua regiao, mas estamos expandindo!",
            "tem_mercado" => false,
            "cep" => $cep,
            "cidade" => $cidadeInfo['cidade'] ?? null,
            "estado" => $cidadeInfo['estado'] ?? null,
            "acao" => "Deixe seu nome e email que avisaremos quando chegarmos ai!"
        ]);
    }

    // 5. Tem mercado - buscar produtos
    $categorias = buscarCategorias($db, $mercado['partner_id']);
    $produtos = buscarProdutos($db, $mercado['partner_id'], $_GET['categoria'] ?? null, $_GET['busca'] ?? null);
    $destaques = buscarDestaques($db, $mercado['partner_id']);
    $banners = buscarBanners($db);

    // Verificar status de abertura
    $statusHorario = verificarSeAberto($db, $mercado['partner_id']);

    response(true, [
        "status" => "ok",
        "tem_mercado" => true,
        "cep" => $cep,
        "banners" => $banners,
        "mercado" => [
            "id" => (int)$mercado['partner_id'],
            "nome" => $mercado['name'],
            "logo" => $mercado['logo'],
            "endereco" => $mercado['address'],
            "taxa_entrega" => floatval($mercado['delivery_fee'] ?? $mercado['taxa_entrega'] ?? 0),
            "pedido_minimo" => floatval($mercado['min_order'] ?? $mercado['min_order_value'] ?? 0),
            "tempo_estimado" => (int)($mercado['delivery_time_min'] ?? 45),
            "avaliacao" => floatval($mercado['rating'] ?? 5),
            "aberto" => $statusHorario['aberto'] ?? false,
            "horario" => [
                "aberto" => $statusHorario['aberto'] ?? false,
                "fecha_as" => $statusHorario['fecha_as'] ?? null,
                "motivo" => $statusHorario['motivo'] ?? null,
                "abre_em" => $statusHorario['abre_em'] ?? null
            ],
            "aceita_agendamento" => !($statusHorario['aberto'] ?? false), // Se fechado, oferece agendamento
            "agendamento_info" => !($statusHorario['aberto'] ?? false) ? [
                "disponivel" => true,
                "mensagem" => "Agende sua compra para quando o mercado abrir",
                "proximo_horario" => $statusHorario['abre_em'] ?? null
            ] : null
        ],
        "categorias" => $categorias,
        "destaques" => $destaques,
        "produtos" => $produtos
    ]);

} catch (Exception $e) {
    error_log("Erro home mercado: " . $e->getMessage());
    response(false, null, "Erro ao carregar. Tente novamente.", 500);
}

/**
 * Busca mercado mais proximo pelo CEP
 */
function buscarMercadoMaisProximo($db, $cep) {
    // 1. Primeiro tenta por cobertura especifica
    $stmt = $db->prepare("
        SELECT p.* FROM om_market_partners p
        INNER JOIN om_partner_coverage pc ON p.partner_id = pc.partner_id
        WHERE p.status::text = '1'
          AND pc.ativo::text = '1'
          AND CAST(? AS BIGINT) BETWEEN CAST(pc.cep_inicio AS BIGINT) AND CAST(pc.cep_fim AS BIGINT)
        ORDER BY p.rating DESC, p.delivery_fee ASC
        LIMIT 1
    ");
    $stmt->execute([$cep]);
    $mercado = $stmt->fetch();

    if ($mercado) {
        return $mercado;
    }

    // 2. Se nao tem cobertura especifica, buscar por regiao (3 primeiros digitos)
    $prefixoCep = substr($cep, 0, 3);

    $stmt = $db->prepare("
        SELECT * FROM om_market_partners
        WHERE status::text = '1'
          AND (
              SUBSTRING(REPLACE(zipcode, '-', ''), 1, 3) = ?
              OR delivery_radius_km >= 50
          )
        ORDER BY rating DESC, delivery_fee ASC
        LIMIT 1
    ");
    $stmt->execute([$prefixoCep]);

    return $stmt->fetch() ?: null;
}

/**
 * Busca cidade/estado pelo CEP via ViaCEP
 */
function buscarCidadePorCep($cep) {
    $cacheKey = "cidade_cep_{$cep}";

    return CacheHelper::remember($cacheKey, 86400, function() use ($cep) {
        $ch = curl_init("https://viacep.com.br/ws/{$cep}/json/");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $data = json_decode($response, true);
            if ($data && !isset($data['erro'])) {
                return [
                    'cidade' => $data['localidade'] ?? null,
                    'estado' => $data['uf'] ?? null,
                    'bairro' => $data['bairro'] ?? null
                ];
            }
        }

        return ['cidade' => null, 'estado' => null];
    });
}

/**
 * Verifica se mercado esta aberto agora
 * Retorna array com status e info de quando abre
 */
function verificarSeAberto($db, $partnerId) {
    $diaSemana = (int)date('w'); // 0=Dom, 1=Seg, ..., 6=Sab
    $horaAtual = date('H:i:s');
    $dataHoje = date('Y-m-d');

    // 1. Verificar feriados primeiro
    $stmt = $db->prepare("
        SELECT * FROM om_partner_holidays
        WHERE partner_id = ? AND date = ?
    ");
    $stmt->execute([$partnerId, $dataHoje]);
    $feriado = $stmt->fetch();

    if ($feriado) {
        if ($feriado['is_closed']) {
            // Fechado por feriado - buscar proximo dia que abre
            $proximoAberto = buscarProximoHorarioAberto($db, $partnerId);
            return [
                'aberto' => false,
                'motivo' => $feriado['reason'] ?? 'Feriado',
                'abre_em' => $proximoAberto
            ];
        } else {
            // Horario especial de feriado
            if ($horaAtual >= $feriado['open_time'] && $horaAtual <= $feriado['close_time']) {
                return ['aberto' => true, 'fecha_as' => substr($feriado['close_time'], 0, 5)];
            }
            return [
                'aberto' => false,
                'motivo' => 'Horario especial: ' . substr($feriado['open_time'], 0, 5) . ' - ' . substr($feriado['close_time'], 0, 5),
                'abre_em' => ['hoje' => true, 'hora' => substr($feriado['open_time'], 0, 5)]
            ];
        }
    }

    // 2. Verificar horario regular do dia
    $stmt = $db->prepare("
        SELECT * FROM om_partner_hours
        WHERE partner_id = ? AND day_of_week = ?
    ");
    $stmt->execute([$partnerId, $diaSemana]);
    $horario = $stmt->fetch();

    if (!$horario || $horario['is_closed'] || !$horario['open_time'] || !$horario['close_time']) {
        // Fechado hoje - buscar proximo dia que abre
        $proximoAberto = buscarProximoHorarioAberto($db, $partnerId);
        return [
            'aberto' => false,
            'motivo' => 'Fechado hoje',
            'abre_em' => $proximoAberto
        ];
    }

    // Verificar se esta no horario de funcionamento
    if ($horaAtual >= $horario['open_time'] && $horaAtual <= $horario['close_time']) {
        return ['aberto' => true, 'fecha_as' => substr($horario['close_time'], 0, 5)];
    }

    // Ainda vai abrir hoje?
    if ($horaAtual < $horario['open_time']) {
        return [
            'aberto' => false,
            'motivo' => 'Abre as ' . substr($horario['open_time'], 0, 5),
            'abre_em' => ['hoje' => true, 'hora' => substr($horario['open_time'], 0, 5)]
        ];
    }

    // Ja fechou hoje - proximo dia
    $proximoAberto = buscarProximoHorarioAberto($db, $partnerId);
    return [
        'aberto' => false,
        'motivo' => 'Fechado - abre ' . ($proximoAberto['dia_texto'] ?? 'em breve'),
        'abre_em' => $proximoAberto
    ];
}

/**
 * Busca proximo horario que o mercado abre
 */
function buscarProximoHorarioAberto($db, $partnerId) {
    $diasSemana = ['Domingo', 'Segunda', 'Terca', 'Quarta', 'Quinta', 'Sexta', 'Sabado'];
    $diaAtual = (int)date('w');
    $horaAtual = date('H:i:s');

    // Verificar proximos 7 dias
    for ($i = 0; $i <= 7; $i++) {
        $dia = ($diaAtual + $i) % 7;
        $data = date('Y-m-d', strtotime("+{$i} days"));

        // Verificar feriado
        $stmt = $db->prepare("SELECT * FROM om_partner_holidays WHERE partner_id = ? AND date = ?");
        $stmt->execute([$partnerId, $data]);
        $feriado = $stmt->fetch();

        if ($feriado && $feriado['is_closed']) continue; // Dia fechado

        // Verificar horario regular
        $stmt = $db->prepare("SELECT * FROM om_partner_hours WHERE partner_id = ? AND day_of_week = ?");
        $stmt->execute([$partnerId, $dia]);
        $horario = $stmt->fetch();

        if (!$horario || $horario['is_closed'] || !$horario['open_time']) continue;

        $horaAbre = $feriado ? $feriado['open_time'] : $horario['open_time'];

        // Se for hoje e ja passou da hora, continuar
        if ($i === 0 && $horaAtual >= $horario['close_time']) continue;

        $diaTexto = $i === 0 ? 'Hoje' : ($i === 1 ? 'Amanha' : $diasSemana[$dia]);

        return [
            'dia' => $dia,
            'dia_texto' => $diaTexto,
            'data' => $data,
            'hora' => substr($horaAbre, 0, 5),
            'hoje' => ($i === 0)
        ];
    }

    return ['dia_texto' => 'em breve', 'hora' => null];
}

/**
 * Busca categorias do mercado
 */
function buscarCategorias($db, $partnerId) {
    $stmt = $db->prepare("
        SELECT c.category_id, c.name, c.icon, c.sort_order,
               COUNT(p.product_id) as total_produtos
        FROM om_market_categories c
        INNER JOIN om_market_products p ON p.category_id = c.category_id
        WHERE p.partner_id = ? AND p.status::text = '1' AND c.status::text = '1'
        GROUP BY c.category_id, c.name, c.icon, c.sort_order
        ORDER BY c.sort_order, c.name
    ");
    $stmt->execute([$partnerId]);

    return array_map(function($c) {
        return [
            "id" => (int)$c['category_id'],
            "nome" => $c['name'],
            "icone" => $c['icon'],
            "total" => (int)$c['total_produtos']
        ];
    }, $stmt->fetchAll());
}

/**
 * Busca produtos em destaque
 */
function buscarDestaques($db, $partnerId) {
    $stmt = $db->prepare("
        SELECT p.*, c.name as categoria_nome
        FROM om_market_products p
        LEFT JOIN om_market_categories c ON p.category_id = c.category_id
        WHERE p.partner_id = ? AND p.status::text = '1' AND p.is_featured::text = '1'
        ORDER BY p.sort_order ASC NULLS LAST, p.name
        LIMIT 10
    ");
    $stmt->execute([$partnerId]);

    return formatarProdutos($stmt->fetchAll());
}

/**
 * Busca produtos (com filtro opcional)
 */
function buscarProdutos($db, $partnerId, $categoriaId = null, $busca = null) {
    $where = ["p.partner_id = ?", "p.status::text = '1'"];
    $params = [$partnerId];

    if ($categoriaId) {
        $where[] = "p.category_id = ?";
        $params[] = (int)$categoriaId;
    }

    if ($busca) {
        // SECURITY: Limit search length and escape LIKE wildcards to prevent DoS
        $busca = mb_substr($busca, 0, 100);
        $buscaEscaped = str_replace(['%', '_'], ['\\%', '\\_'], $busca);
        $where[] = "(p.name LIKE ? OR p.description LIKE ?)";
        $params[] = "%{$buscaEscaped}%";
        $params[] = "%{$buscaEscaped}%";
    }

    $whereSQL = implode(" AND ", $where);

    $stmt = $db->prepare("
        SELECT p.*, c.name as categoria_nome
        FROM om_market_products p
        LEFT JOIN om_market_categories c ON p.category_id = c.category_id
        WHERE {$whereSQL}
        ORDER BY c.sort_order, p.name
        LIMIT 100
    ");
    $stmt->execute($params);

    return formatarProdutos($stmt->fetchAll());
}

/**
 * Formata produtos para resposta
 */
function formatarProdutos($produtos) {
    return array_map(function($p) {
        // Determinar preco de venda
        $preco = 0;
        if (!empty($p['preco_venda']) && $p['preco_venda'] > 0) {
            $preco = floatval($p['preco_venda']);
        } elseif (!empty($p['price'])) {
            $preco = floatval($p['price']);
        }

        $precoOriginal = floatval($p['price'] ?? $preco);
        $temDesconto = $precoOriginal > $preco;

        return [
            "id" => (int)$p['product_id'],
            "nome" => $p['name'],
            "descricao" => $p['description'] ?? '',
            "preco" => $preco,
            "preco_original" => $precoOriginal,
            "tem_desconto" => $temDesconto,
            "desconto_percent" => ($temDesconto && $precoOriginal > 0) ? round((1 - $preco / $precoOriginal) * 100) : 0,
            "imagem" => $p['image'],
            "categoria" => $p['categoria_nome'] ?? '',
            "categoria_id" => (int)($p['category_id'] ?? 0),
            "unidade" => $p['unit'] ?? 'un',
            "estoque" => (int)($p['quantity'] ?? 999),
            "disponivel" => ((int)($p['quantity'] ?? 999)) > 0
        ];
    }, $produtos);
}

/**
 * Busca banners ativos
 */
function buscarBanners($db) {
    $cacheKey = "home_banners";
    return CacheHelper::remember($cacheKey, 300, function() use ($db) {
        $stmt = $db->prepare("
            SELECT banner_id AS id, title, subtitle, image AS image_url, link, icon, bg_color
            FROM om_market_banners
            WHERE status::text = '1' AND (end_date IS NULL OR end_date > NOW())
            ORDER BY sort_order ASC, created_at DESC
            LIMIT 5
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    });
}

/**
 * Salvar na lista de espera (quando nao tem mercado)
 */
function salvarListaEspera($db) {
    $input = json_decode(file_get_contents("php://input"), true) ?: $_POST;

    $cep = preg_replace('/\D/', '', $input['cep'] ?? '');
    $nome = trim($input['nome'] ?? '');
    $email = strtolower(trim($input['email'] ?? ''));
    $telefone = preg_replace('/\D/', '', $input['telefone'] ?? '');

    // Validacoes
    if (strlen($cep) !== 8) {
        response(false, null, "CEP invalido", 400);
    }

    if (strlen($nome) < 2) {
        response(false, null, "Nome obrigatorio", 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        response(false, null, "Email invalido", 400);
    }

    // Buscar cidade pelo CEP
    $cidadeInfo = buscarCidadePorCep($cep);
    $cidade = $cidadeInfo['cidade'] ?? 'Desconhecida';
    $estado = $cidadeInfo['estado'] ?? 'XX';

    // SECURITY: Rate limiting — max 3 waitlist submissions per IP per hour
    $waitlistIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $rlStmt = $db->prepare("SELECT COUNT(*) FROM om_city_interest WHERE ip_address = ? AND created_at > NOW() - INTERVAL '1 hour'");
    $rlStmt->execute([$waitlistIp]);
    if ((int)$rlStmt->fetchColumn() >= 3) {
        response(false, null, "Muitas requisicoes. Aguarde 1 hora.", 429);
    }

    // Salvar na lista de espera
    $stmt = $db->prepare("
        INSERT INTO om_city_interest (city, state, email, name, phone, service_type, ip_address, created_at)
        VALUES (?, ?, ?, ?, ?, 'mercado', ?, NOW())
        ON CONFLICT (city, state, email, service_type) DO UPDATE SET name = EXCLUDED.name, phone = EXCLUDED.phone
    ");
    $stmt->execute([
        $cidade,
        $estado,
        $email,
        $nome,
        $telefone ?: null,
        $waitlistIp
    ]);

    // Contar quantos estao esperando
    $stmt = $db->prepare("SELECT COUNT(*) FROM om_city_interest WHERE city = ? AND state = ?");
    $stmt->execute([$cidade, $estado]);
    $total = $stmt->fetchColumn();

    response(true, [
        "status" => "salvo",
        "mensagem" => "Obrigado! Voce sera avisado quando tivermos mercado em {$cidade}/{$estado}.",
        "cidade" => $cidade,
        "estado" => $estado,
        "pessoas_esperando" => (int)$total
    ]);
}

/**
 * Resposta padronizada
 */
function response($success, $data = null, $message = null, $httpCode = 200) {
    http_response_code($httpCode);

    $response = ["success" => $success];

    if ($message) {
        $response["message"] = $message;
    }

    if ($data) {
        $response = array_merge($response, $data);
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}
