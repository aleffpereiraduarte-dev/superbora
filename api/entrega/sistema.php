<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * SISTEMA DE ENTREGA "RECEBE HOJE" - OneMundo
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Fluxo completo:
 * 1. Cliente compra → escolhe retirada grátis ou recebe hoje
 * 2. Vendedor notificado → leva ao ponto de apoio
 * 3. Handoff → Ponto recebe e notifica cliente
 * 4. Se recebe hoje → Ponto chama BoraUm (ou Uber fallback)
 * 5. Entregador entrega → Cliente rastreia em tempo real
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Configurações
define('TEMPO_RETIRADA_HORAS', 4);      // Tempo estimado para disponível retirada
define('TEMPO_ENTREGA_HORAS', 3);       // Tempo estimado para entrega em casa
define('PRECO_RECEBE_HOJE_KM', 2.00);   // Preço por km
define('PRECO_RECEBE_HOJE_MIN', 9.90);  // Preço mínimo
define('RAIO_MESMA_CIDADE_KM', 50);     // Raio máximo para "mesma cidade"

// Conexão
require_once dirname(__DIR__, 2) . '/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
        DB_USERNAME, DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    jsonResponse(false, 'Erro de conexão');
}

// Sessão
session_name('OCSESSID');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Input
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? $_GET['action'] ?? $_POST['action'] ?? '';

// ════════════════════════════════════════════════════════════════════════════════
// FUNÇÕES AUXILIARES
// ════════════════════════════════════════════════════════════════════════════════

function jsonResponse($success, $message = '', $data = []) {
    echo json_encode(array_merge(
        ['success' => $success, 'message' => $message],
        $data
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

function calcularDistancia($lat1, $lng1, $lat2, $lng2) {
    if (!$lat1 || !$lng1 || !$lat2 || !$lng2) return null;
    $earthRadius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) * sin($dLng/2);
    return $earthRadius * 2 * atan2(sqrt($a), sqrt(1-$a));
}

function gerarCodigoRetirada() {
    return strtoupper(substr(md5(uniqid()), 0, 6));
}

function geocodificarCEP($cep) {
    $cep = preg_replace('/\D/', '', $cep);
    if (strlen($cep) !== 8) return null;

    $viaCep = @file_get_contents("https://viacep.com.br/ws/{$cep}/json/");
    if (!$viaCep) return null;

    $endereco = json_decode($viaCep, true);
    if (isset($endereco['erro'])) return null;

    // Geocodificar
    $query = urlencode("{$endereco['localidade']}, {$endereco['uf']}, Brasil");
    $nominatim = @file_get_contents(
        "https://nominatim.openstreetmap.org/search?q={$query}&format=json&limit=1",
        false,
        stream_context_create(['http' => ['header' => 'User-Agent: OneMundo/1.0']])
    );

    $lat = $lng = null;
    if ($nominatim) {
        $coords = json_decode($nominatim, true);
        if (!empty($coords)) {
            $lat = floatval($coords[0]['lat']);
            $lng = floatval($coords[0]['lon']);
        }
    }

    return [
        'cep' => $cep,
        'cidade' => $endereco['localidade'] ?? '',
        'estado' => $endereco['uf'] ?? '',
        'lat' => $lat,
        'lng' => $lng
    ];
}

function buscarPontoMaisProximo($pdo, $lat, $lng, $cidade = null) {
    $sql = "
        SELECT id as seller_id, nome as store_name, endereco as store_address, latitude as store_latitude, longitude as store_longitude,
               cidade as store_city, horario_abertura as ponto_horario_abertura, horario_fechamento as ponto_horario_fechamento,
               (6371 * acos(cos(radians(?)) * cos(radians(latitude)) *
                cos(radians(longitude) - radians(?)) +
                sin(radians(?)) * sin(radians(latitude)))) AS distancia
        FROM om_pontos_apoio
        WHERE status = 'ativo'
          AND latitude IS NOT NULL
          AND pacotes_atuais < capacidade_pacotes
        ORDER BY distancia
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$lat, $lng, $lat]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function enviarNotificacao($pdo, $entregaId, $destinatarioTipo, $destinatarioId, $titulo, $mensagem, $tipo = 'info', $acaoUrl = null) {
    $stmt = $pdo->prepare("
        INSERT INTO om_entrega_notificacoes
        (entrega_id, destinatario_tipo, destinatario_id, titulo, mensagem, tipo, acao_url, enviado)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1)
    ");
    $stmt->execute([$entregaId, $destinatarioTipo, $destinatarioId, $titulo, $mensagem, $tipo, $acaoUrl]);

    // TODO: Integrar com push notification real (Firebase, OneSignal, etc)
    return $pdo->lastInsertId();
}

function atualizarStatusEntrega($pdo, $entregaId, $novoStatus, $dados = []) {
    $campos = ['status = ?'];
    $valores = [$novoStatus];

    foreach ($dados as $campo => $valor) {
        $campos[] = "$campo = ?";
        $valores[] = $valor;
    }

    $valores[] = $entregaId;

    $sql = "UPDATE om_entregas SET " . implode(', ', $campos) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($valores);

    // Registrar no tracking
    $stmt = $pdo->prepare("
        INSERT INTO om_entrega_tracking (entrega_id, status, mensagem)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$entregaId, $novoStatus, getStatusMensagem($novoStatus)]);

    return true;
}

function getStatusMensagem($status) {
    $mensagens = [
        'aguardando_vendedor' => 'Pedido recebido, aguardando vendedor preparar',
        'vendedor_preparando' => 'Vendedor está preparando seu pedido',
        'a_caminho_ponto' => 'Pedido a caminho do ponto de apoio',
        'no_ponto' => 'Pedido chegou ao ponto de apoio',
        'disponivel_retirada' => 'Disponível para retirada!',
        'aguardando_entregador' => 'Buscando entregador...',
        'entregador_a_caminho' => 'Entregador a caminho!',
        'entregue' => 'Pedido entregue!',
        // Novos status para entrega direta (sem ponto de apoio)
        'aguardando_coleta' => 'Aguardando entregador ir buscar',
        'entregador_coletando' => 'Entregador buscando no vendedor',
        'em_transito_direto' => 'Pedido em trânsito para você'
    ];
    return $mensagens[$status] ?? $status;
}

function getStatusLabel($status) {
    $labels = [
        'aguardando_vendedor' => ['texto' => 'Aguardando Vendedor', 'cor' => '#FFA500'],
        'vendedor_preparando' => ['texto' => 'Preparando', 'cor' => '#3B82F6'],
        'a_caminho_ponto' => ['texto' => 'A Caminho do Ponto', 'cor' => '#8B5CF6'],
        'no_ponto' => ['texto' => 'No Ponto de Apoio', 'cor' => '#06B6D4'],
        'disponivel_retirada' => ['texto' => 'Disponível p/ Retirada', 'cor' => '#22C55E'],
        'aguardando_entregador' => ['texto' => 'Buscando Entregador', 'cor' => '#F59E0B'],
        'entregador_a_caminho' => ['texto' => 'Entregador a Caminho', 'cor' => '#8B5CF6'],
        'entregue' => ['texto' => 'Entregue', 'cor' => '#22C55E'],
        'cancelado' => ['texto' => 'Cancelado', 'cor' => '#EF4444'],
        // Novos status para entrega direta (sem ponto de apoio)
        'aguardando_coleta' => ['texto' => 'Aguardando Coleta', 'cor' => '#F59E0B'],
        'entregador_coletando' => ['texto' => 'Coletando no Vendedor', 'cor' => '#8B5CF6'],
        'em_transito_direto' => ['texto' => 'Em Trânsito', 'cor' => '#3B82F6']
    ];
    return $labels[$status] ?? ['texto' => $status, 'cor' => '#6B7280'];
}

// ════════════════════════════════════════════════════════════════════════════════
// AÇÕES DA API
// ════════════════════════════════════════════════════════════════════════════════

switch ($action) {

    // ═══════════════════════════════════════════════════════════════════════════
    // CALCULAR OPÇÕES - Retorna opções de entrega para o checkout
    // ═══════════════════════════════════════════════════════════════════════════
    case 'calcular_opcoes':
        $sellerId = intval($input['seller_id'] ?? 0);
        $cepCliente = preg_replace('/\D/', '', $input['cep'] ?? '');
        $subtotal = floatval($input['subtotal'] ?? 0);

        if (!$sellerId || strlen($cepCliente) !== 8) {
            jsonResponse(false, 'Dados inválidos');
        }

        // Buscar vendedor
        $stmt = $pdo->prepare("
            SELECT id as seller_id, nome as store_name, endereco as store_address, cidade as store_city, estado as store_state,
                   latitude as store_latitude, longitude as store_longitude, 1 as is_ponto_apoio
            FROM om_pontos_apoio WHERE id = ?
        ");
        $stmt->execute([$sellerId]);
        $vendedor = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$vendedor) {
            jsonResponse(false, 'Vendedor não encontrado');
        }

        // Geocodificar cliente
        $cliente = geocodificarCEP($cepCliente);
        if (!$cliente) {
            jsonResponse(false, 'CEP inválido');
        }

        $opcoes = [];
        $vendedorLat = floatval($vendedor['store_latitude']);
        $vendedorLng = floatval($vendedor['store_longitude']);
        $clienteLat = $cliente['lat'];
        $clienteLng = $cliente['lng'];

        // Verificar se é mesma cidade
        $mesmaCidade = strtolower(trim($vendedor['store_city'])) === strtolower(trim($cliente['cidade']));
        $distancia = calcularDistancia($vendedorLat, $vendedorLng, $clienteLat, $clienteLng);

        // Se mesma cidade, buscar ponto de apoio mais próximo
        $pontoProximo = null;
        if ($mesmaCidade && $clienteLat && $clienteLng) {
            $pontoProximo = buscarPontoMaisProximo($pdo, $clienteLat, $clienteLng, $cliente['cidade']);
        }

        // OPÇÃO 1: Retirar no Ponto de Apoio (GRÁTIS) - só se mesma cidade
        if ($mesmaCidade && $pontoProximo) {
            $distanciaPonto = calcularDistancia($clienteLat, $clienteLng,
                floatval($pontoProximo['store_latitude']), floatval($pontoProximo['store_longitude']));

            $opcoes[] = [
                'id' => 'retirada_ponto',
                'tipo' => 'retirada_ponto',
                'nome' => 'Retirar GRÁTIS',
                'subtitulo' => $pontoProximo['store_name'],
                'descricao' => $pontoProximo['store_address'] . ' (' . round($distanciaPonto, 1) . ' km de você)',
                'preco' => 0,
                'preco_texto' => 'GRÁTIS',
                'tempo_texto' => 'Disponível em ' . TEMPO_RETIRADA_HORAS . 'h',
                'tempo_horas' => TEMPO_RETIRADA_HORAS,
                'badge' => '🎉 Economize no frete!',
                'ponto_apoio' => [
                    'id' => $pontoProximo['seller_id'],
                    'nome' => $pontoProximo['store_name'],
                    'endereco' => $pontoProximo['store_address'],
                    'horario' => substr($pontoProximo['ponto_horario_abertura'], 0, 5) . ' - ' . substr($pontoProximo['ponto_horario_fechamento'], 0, 5),
                    'distancia_km' => round($distanciaPonto, 1)
                ],
                'mesma_cidade' => true
            ];
        }

        // OPÇÃO 2: Recebe Hoje em Casa - só se mesma cidade
        if ($mesmaCidade && $pontoProximo) {
            $distanciaTotal = $distancia ?? 10;
            $precoEntrega = max(PRECO_RECEBE_HOJE_MIN, round($distanciaTotal * PRECO_RECEBE_HOJE_KM, 2));

            $opcoes[] = [
                'id' => 'recebe_hoje',
                'tipo' => 'recebe_hoje',
                'nome' => '🚀 Recebe HOJE',
                'subtitulo' => 'Entrega via BoraUm',
                'descricao' => 'Receba em casa em até ' . TEMPO_ENTREGA_HORAS . ' horas',
                'preco' => $precoEntrega,
                'preco_texto' => 'R$ ' . number_format($precoEntrega, 2, ',', '.'),
                'tempo_texto' => 'Até ' . TEMPO_ENTREGA_HORAS . 'h',
                'tempo_horas' => TEMPO_ENTREGA_HORAS,
                'badge' => '⚡ Mais rápido!',
                'ponto_apoio' => [
                    'id' => $pontoProximo['seller_id'],
                    'nome' => $pontoProximo['store_name']
                ],
                'mesma_cidade' => true
            ];
        }

        // OPÇÃO 3: Entrega Padrão (sempre disponível)
        $freteTabela = [
            "0" => 18.90, "1" => 18.90, "2" => 22.90, "3" => 20.90, "4" => 28.90,
            "5" => 32.90, "6" => 38.90, "7" => 28.90, "8" => 22.90, "9" => 25.90
        ];
        $precoPadrao = $freteTabela[$cepCliente[0]] ?? 18.90;
        $diasPadrao = $mesmaCidade ? 2 : ($cepCliente[0] === '0' || $cepCliente[0] === '1' ? 3 : 5);

        $opcoes[] = [
            'id' => 'padrao',
            'tipo' => 'padrao',
            'nome' => 'Entrega Padrão',
            'subtitulo' => 'Correios/Transportadora',
            'descricao' => 'Receba em ' . $diasPadrao . ' dias úteis',
            'preco' => $precoPadrao,
            'preco_texto' => 'R$ ' . number_format($precoPadrao, 2, ',', '.'),
            'tempo_texto' => $diasPadrao . ' dias úteis',
            'tempo_dias' => $diasPadrao,
            'mesma_cidade' => $mesmaCidade
        ];

        jsonResponse(true, 'Opções calculadas', [
            'opcoes' => $opcoes,
            'mesma_cidade' => $mesmaCidade,
            'cidade_cliente' => $cliente['cidade'],
            'cidade_vendedor' => $vendedor['store_city'],
            'distancia_km' => $distancia ? round($distancia, 1) : null
        ]);
        break;

    // ═══════════════════════════════════════════════════════════════════════════
    // CRIAR ENTREGA - Chamado após checkout
    // ═══════════════════════════════════════════════════════════════════════════
    case 'criar':
        $orderId = intval($input['order_id'] ?? 0);
        $customerId = intval($input['customer_id'] ?? $_SESSION['customer_id'] ?? 0);
        $sellerId = intval($input['seller_id'] ?? 0);
        $tipoEntrega = $input['tipo_entrega'] ?? 'padrao';
        $pontoApoioId = intval($input['ponto_apoio_id'] ?? 0);
        $endereco = $input['endereco'] ?? '';
        $cep = preg_replace('/\D/', '', $input['cep'] ?? '');
        $valorFrete = floatval($input['valor_frete'] ?? 0);
        $valorProduto = floatval($input['valor_produto'] ?? 0);

        if (!$orderId || !$customerId || !$sellerId) {
            jsonResponse(false, 'Dados obrigatórios faltando');
        }

        // Geocodificar
        $geo = geocodificarCEP($cep);

        // Calcular previsões
        $agora = new DateTime();
        if ($tipoEntrega === 'retirada_ponto') {
            $previsao = (clone $agora)->modify('+' . TEMPO_RETIRADA_HORAS . ' hours');
        } elseif ($tipoEntrega === 'recebe_hoje') {
            $previsao = (clone $agora)->modify('+' . TEMPO_ENTREGA_HORAS . ' hours');
        } else {
            $previsao = (clone $agora)->modify('+3 days');
        }

        // Gerar código de retirada
        $codigoRetirada = gerarCodigoRetirada();

        // Buscar dados do vendedor
        $vendedorData = null;
        if ($sellerId) {
            $stmt = $pdo->prepare("SELECT nome as store_name, endereco as store_address, telefone as store_phone, latitude as store_latitude, longitude as store_longitude FROM om_pontos_apoio WHERE id = ?");
            $stmt->execute([$sellerId]);
            $vendedorData = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Buscar dados do cliente
        $clienteData = null;
        if ($customerId) {
            $clienteData = [
                'firstname' => $input['cliente_nome'] ?? 'Cliente',
                'lastname' => '',
                'telephone' => $input['cliente_telefone'] ?? ''
            ];
        }

        // Inserir entrega (estrutura adaptada para om_entregas atual)
        $stmt = $pdo->prepare("
            INSERT INTO om_entregas (
                tipo, origem_sistema, referencia_id,
                remetente_tipo, remetente_id, remetente_nome, remetente_telefone,
                coleta_endereco, coleta_lat, coleta_lng,
                destinatario_nome, destinatario_telefone,
                entrega_endereco, entrega_lat, entrega_lng,
                valor_frete, valor_declarado, status,
                metodo_entrega, ponto_apoio_id, pin_entrega
            ) VALUES (?, 'onemundo', ?, 'vendedor', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendente', ?, ?, ?)
        ");
        $stmt->execute([
            $tipoEntrega === 'recebe_hoje' ? 'express' : 'padrao',
            $orderId,
            $sellerId,
            $vendedorData['store_name'] ?? 'Vendedor',
            $vendedorData['store_phone'] ?? '',
            $vendedorData['store_address'] ?? '',
            $vendedorData['store_latitude'] ?? null,
            $vendedorData['store_longitude'] ?? null,
            ($clienteData['firstname'] ?? '') . ' ' . ($clienteData['lastname'] ?? ''),
            $clienteData['telephone'] ?? '',
            $endereco,
            $geo['lat'] ?? null,
            $geo['lng'] ?? null,
            $valorFrete,
            $valorProduto,
            $tipoEntrega,
            $pontoApoioId ?: null,
            $codigoRetirada
        ]);
        $entregaId = $pdo->lastInsertId();

        // Buscar dados do ponto
        $pontoNome = 'Ponto de Apoio';
        if ($pontoApoioId) {
            $stmt = $pdo->prepare("SELECT nome as store_name FROM om_pontos_apoio WHERE id = ?");
            $stmt->execute([$pontoApoioId]);
            $pontoNome = $stmt->fetchColumn() ?: $pontoNome;
        }

        // ══════════════════════════════════════════════════════════════════════
        // ENTREGA DIRETA (sem ponto de apoio) - Fluxo especial
        // ══════════════════════════════════════════════════════════════════════
        if ($tipoEntrega === 'recebe_hoje_direto') {
            // NOTIFICAR VENDEDOR - NÃO mostra endereço do cliente!
            enviarNotificacao($pdo, $entregaId, 'vendedor', $sellerId,
                '🛒 Novo Pedido #' . $orderId . ' - RECEBE HOJE',
                "URGENTE! Cliente quer RECEBER HOJE. Prepare o pedido que um entregador irá buscar aí. Você NÃO precisa levar a nenhum lugar.",
                'acao_necessaria',
                '/vendedor/pedidos/?entrega=' . $entregaId
            );

            // NOTIFICAR SUPORTE - Precisa chamar entregador
            enviarNotificacao($pdo, $entregaId, 'suporte', 0,
                '🚨 ATENÇÃO: Entrega Direta Necessária',
                "Pedido #$orderId precisa de entrega direta (sem Ponto de Apoio na região). Por favor, acione BoraUm/Uber para coleta no vendedor.",
                'urgente',
                '/admin/entregas/?entrega=' . $entregaId
            );

            // NOTIFICAR CLIENTE
            enviarNotificacao($pdo, $entregaId, 'cliente', $customerId,
                '✅ Pedido Confirmado!',
                "Seu pedido #$orderId foi confirmado! Um entregador irá buscar diretamente no vendedor e entregar a você. Previsão: " . $previsao->format('d/m H:i'),
                'sucesso',
                '/minha-conta/pedidos/?id=' . $orderId
            );
        } else {
            // ══════════════════════════════════════════════════════════════════════
            // FLUXO NORMAL (com ponto de apoio)
            // ══════════════════════════════════════════════════════════════════════

            // NOTIFICAR VENDEDOR
            enviarNotificacao($pdo, $entregaId, 'vendedor', $sellerId,
                '🛒 Novo Pedido #' . $orderId,
                $tipoEntrega === 'recebe_hoje'
                    ? "URGENTE! Cliente quer RECEBER HOJE. Leve ao $pontoNome o mais rápido possível."
                    : "Novo pedido para enviar ao $pontoNome. Cliente vai retirar lá.",
                'acao_necessaria',
                '/vendedor/pedidos/?entrega=' . $entregaId
            );

            // NOTIFICAR PONTO DE APOIO
            if ($pontoApoioId) {
                enviarNotificacao($pdo, $entregaId, 'ponto_apoio', $pontoApoioId,
                    '📦 Pacote a Caminho',
                    "Vendedor vai enviar pacote do pedido #$orderId. " .
                    ($tipoEntrega === 'recebe_hoje' ? 'RECEBE HOJE - Prepare para despachar!' : 'Cliente vai retirar.'),
                    'info'
                );
            }

            // NOTIFICAR CLIENTE
            enviarNotificacao($pdo, $entregaId, 'cliente', $customerId,
                '✅ Pedido Confirmado!',
                $tipoEntrega === 'retirada_ponto'
                    ? "Seu pedido #$orderId foi confirmado! Você receberá uma notificação quando estiver disponível para retirada em $pontoNome. Código: $codigoRetirada"
                    : "Seu pedido #$orderId foi confirmado! Previsão de entrega: " . $previsao->format('d/m H:i'),
                'sucesso',
                '/minha-conta/pedidos/?id=' . $orderId
            );
        }

        // Tracking inicial
        $stmt = $pdo->prepare("INSERT INTO om_entrega_tracking (entrega_id, status, mensagem) VALUES (?, 'criado', 'Pedido confirmado')");
        $stmt->execute([$entregaId]);

        jsonResponse(true, 'Entrega criada', [
            'entrega_id' => $entregaId,
            'codigo_retirada' => $codigoRetirada,
            'previsao' => $previsao->format('Y-m-d H:i:s'),
            'tipo' => $tipoEntrega
        ]);
        break;

    // ═══════════════════════════════════════════════════════════════════════════
    // VENDEDOR: Atualizar status (preparando, enviou)
    // ═══════════════════════════════════════════════════════════════════════════
    case 'vendedor_status':
        $entregaId = intval($input['entrega_id'] ?? 0);
        $sellerId = intval($input['seller_id'] ?? 0);
        $novoStatus = $input['status'] ?? '';

        if (!$entregaId || !$sellerId) {
            jsonResponse(false, 'Dados inválidos');
        }

        // Verificar se é o vendedor correto
        $stmt = $pdo->prepare("SELECT * FROM om_entregas WHERE id = ? AND remetente_id = ?");
        $stmt->execute([$entregaId, $sellerId]);
        $entrega = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$entrega) {
            jsonResponse(false, 'Entrega não encontrada');
        }

        if ($novoStatus === 'preparando') {
            atualizarStatusEntrega($pdo, $entregaId, 'vendedor_preparando');

            enviarNotificacao($pdo, $entregaId, 'cliente', 0,
                '📦 Pedido em Preparação',
                'O vendedor está preparando seu pedido!',
                'info'
            );

        } elseif ($novoStatus === 'enviou') {
            atualizarStatusEntrega($pdo, $entregaId, 'a_caminho_ponto');

            // Criar handoff vendedor → ponto
            $stmt = $pdo->prepare("
                INSERT INTO om_entrega_handoffs (entrega_id, de_tipo, de_id, para_tipo, para_id, status)
                VALUES (?, 'vendedor', ?, 'ponto_apoio', ?, 'pendente')
            ");
            $stmt->execute([$entregaId, $sellerId, $entrega['ponto_apoio_id']]);

            // Notificar ponto
            enviarNotificacao($pdo, $entregaId, 'ponto_apoio', $entrega['ponto_apoio_id'],
                '🚚 Pacote a Caminho!',
                'Vendedor despachou o pacote do pedido #' . $entrega['referencia_id'] . '. Aguarde recebimento.',
                'acao_necessaria',
                '/vendedor/ponto-apoio/?entrega=' . $entregaId
            );

            enviarNotificacao($pdo, $entregaId, 'cliente', 0,
                '🚚 Pedido Enviado!',
                'Seu pedido está a caminho do ponto de apoio.',
                'info'
            );
        }

        jsonResponse(true, 'Status atualizado');
        break;

    // ═══════════════════════════════════════════════════════════════════════════
    // PONTO DE APOIO: Receber pacote (handoff)
    // ═══════════════════════════════════════════════════════════════════════════
    case 'ponto_receber':
        $entregaId = intval($input['entrega_id'] ?? 0);
        $pontoId = intval($input['ponto_id'] ?? 0);

        if (!$entregaId || !$pontoId) {
            jsonResponse(false, 'Dados inválidos');
        }

        $stmt = $pdo->prepare("SELECT * FROM om_entregas WHERE id = ? AND ponto_apoio_id = ?");
        $stmt->execute([$entregaId, $pontoId]);
        $entrega = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$entrega) {
            jsonResponse(false, 'Entrega não encontrada');
        }

        // Atualizar handoff
        $stmt = $pdo->prepare("
            UPDATE om_entrega_handoffs
            SET status = 'aceito', data_aceito = NOW()
            WHERE entrega_id = ? AND para_tipo = 'ponto_apoio' AND para_id = ?
        ");
        $stmt->execute([$entregaId, $pontoId]);

        if ($entrega['metodo_entrega'] === 'retirada_ponto') {
            // Retirada - avisar cliente que está disponível
            atualizarStatusEntrega($pdo, $entregaId, 'disponivel_retirada');

            enviarNotificacao($pdo, $entregaId, 'cliente', 0,
                '🎉 Pronto para Retirada!',
                "Seu pedido está disponível para retirada! Código: {$entrega['pin_entrega']}",
                'sucesso',
                '/minha-conta/pedidos/?id=' . $entrega['referencia_id']
            );
        } else {
            // Recebe hoje - preparar para despacho
            atualizarStatusEntrega($pdo, $entregaId, 'no_ponto');

            enviarNotificacao($pdo, $entregaId, 'cliente', 0,
                '📦 Pedido no Ponto de Apoio',
                'Seu pedido chegou ao ponto de apoio e logo sairá para entrega!',
                'info'
            );
        }

        jsonResponse(true, 'Pacote recebido', ['tipo' => $entrega['metodo_entrega']]);
        break;

    // ═══════════════════════════════════════════════════════════════════════════
    // PONTO DE APOIO: Chamar entregador BoraUm
    // ═══════════════════════════════════════════════════════════════════════════
    case 'chamar_entregador':
        $entregaId = intval($input['entrega_id'] ?? 0);
        $pontoId = intval($input['ponto_id'] ?? 0);

        if (!$entregaId || !$pontoId) {
            jsonResponse(false, 'Dados inválidos');
        }

        $stmt = $pdo->prepare("SELECT e.*, p.nome as store_name, p.endereco as store_address, p.latitude as store_latitude, p.longitude as store_longitude
            FROM om_entregas e
            JOIN om_pontos_apoio p ON p.id = e.ponto_apoio_id
            WHERE e.id = ? AND e.ponto_apoio_id = ?");
        $stmt->execute([$entregaId, $pontoId]);
        $entrega = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$entrega) {
            jsonResponse(false, 'Entrega não encontrada');
        }

        // Calcular distância e valor
        $distancia = calcularDistancia(
            floatval($entrega['store_latitude']), floatval($entrega['store_longitude']),
            floatval($entrega['entrega_lat']), floatval($entrega['entrega_lng'])
        ) ?? 5;
        $valorEstimado = max(PRECO_RECEBE_HOJE_MIN, round($distancia * PRECO_RECEBE_HOJE_KM, 2));

        // Criar chamada BoraUm
        $stmt = $pdo->prepare("
            INSERT INTO om_boraum_chamadas (
                entrega_id, ponto_apoio_id,
                origem_endereco, origem_lat, origem_lng,
                destino_endereco, destino_lat, destino_lng,
                status, valor_estimado
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'buscando_motorista', ?)
        ");
        $stmt->execute([
            $entregaId, $pontoId,
            $entrega['store_address'], $entrega['store_latitude'], $entrega['store_longitude'],
            $entrega['entrega_endereco'], $entrega['entrega_lat'], $entrega['entrega_lng'],
            $valorEstimado
        ]);
        $chamadaId = $pdo->lastInsertId();

        atualizarStatusEntrega($pdo, $entregaId, 'aguardando_entregador');

        enviarNotificacao($pdo, $entregaId, 'cliente', 0,
            '🔍 Buscando Entregador',
            'Estamos buscando um entregador para seu pedido. Logo ele estará a caminho!',
            'info'
        );

        // Simular busca de motorista BoraUm (em produção, chamaria a API real)
        // Por enquanto, vamos simular aceitação após 5 segundos via outro endpoint

        jsonResponse(true, 'Buscando entregador', [
            'chamada_id' => $chamadaId,
            'valor_estimado' => $valorEstimado,
            'distancia_km' => round($distancia, 1)
        ]);
        break;

    // ═══════════════════════════════════════════════════════════════════════════
    // MOTORISTA ACEITO (simulação/webhook BoraUm)
    // ═══════════════════════════════════════════════════════════════════════════
    case 'motorista_aceito':
        $chamadaId = intval($input['chamada_id'] ?? 0);
        $motoristaId = intval($input['motorista_id'] ?? 0);
        $motoristaNome = $input['motorista_nome'] ?? 'Motorista BoraUm';
        $motoristaTelefone = $input['motorista_telefone'] ?? '';
        $motoristaPlaca = $input['motorista_placa'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM om_boraum_chamadas WHERE id = ?");
        $stmt->execute([$chamadaId]);
        $chamada = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$chamada) {
            jsonResponse(false, 'Chamada não encontrada');
        }

        // Atualizar chamada
        $stmt = $pdo->prepare("
            UPDATE om_boraum_chamadas
            SET status = 'motorista_aceito', motorista_id = ?, motorista_nome = ?,
                motorista_telefone = ?, motorista_placa = ?
            WHERE id = ?
        ");
        $stmt->execute([$motoristaId, $motoristaNome, $motoristaTelefone, $motoristaPlaca, $chamadaId]);

        // Atualizar entrega
        $stmt = $pdo->prepare("
            UPDATE om_entregas
            SET driver_id = ?
            WHERE id = ?
        ");
        $stmt->execute([$motoristaId, $chamada['entrega_id']]);

        atualizarStatusEntrega($pdo, $chamada['entrega_id'], 'entregador_a_caminho');

        // Buscar referencia_id
        $stmt = $pdo->prepare("SELECT referencia_id FROM om_entregas WHERE id = ?");
        $stmt->execute([$chamada['entrega_id']]);
        $customerId = $stmt->fetchColumn();

        // Handoff ponto → entregador
        $stmt = $pdo->prepare("
            INSERT INTO om_entrega_handoffs (entrega_id, de_tipo, de_id, de_nome, para_tipo, para_id, para_nome, status, data_aceito)
            VALUES (?, 'ponto_apoio', ?, ?, 'entregador', ?, ?, 'aceito', NOW())
        ");
        $stmt->execute([
            $chamada['entrega_id'], $chamada['ponto_apoio_id'], 'Ponto de Apoio',
            $motoristaId, $motoristaNome
        ]);

        enviarNotificacao($pdo, $chamada['entrega_id'], 'cliente', $customerId,
            '🏍️ Entregador a Caminho!',
            "$motoristaNome está indo buscar seu pedido. Placa: $motoristaPlaca",
            'sucesso'
        );

        jsonResponse(true, 'Motorista aceito');
        break;

    // ═══════════════════════════════════════════════════════════════════════════
    // ENTREGA FINALIZADA
    // ═══════════════════════════════════════════════════════════════════════════
    case 'finalizar':
        $entregaId = intval($input['entrega_id'] ?? 0);
        $tipo = $input['tipo'] ?? 'entrega'; // 'entrega' ou 'retirada'
        $codigoRetirada = $input['codigo'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM om_entregas WHERE id = ?");
        $stmt->execute([$entregaId]);
        $entrega = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$entrega) {
            jsonResponse(false, 'Entrega não encontrada');
        }

        // Se retirada, validar código
        if ($tipo === 'retirada' && $entrega['pin_entrega'] !== strtoupper($codigoRetirada)) {
            jsonResponse(false, 'Código de retirada inválido');
        }

        atualizarStatusEntrega($pdo, $entregaId, 'entregue', [
            'entrega_realizada_em' => date('Y-m-d H:i:s')
        ]);

        // Handoff final → cliente
        $stmt = $pdo->prepare("
            INSERT INTO om_entrega_handoffs (entrega_id, de_tipo, de_id, para_tipo, para_nome, status, data_aceito)
            VALUES (?, ?, ?, 'cliente', 'Cliente', 'aceito', NOW())
        ");
        $deId = $tipo === 'retirada' ? $entrega['ponto_apoio_id'] : $entrega['driver_id'];
        $deTipo = $tipo === 'retirada' ? 'ponto_apoio' : 'entregador';
        $stmt->execute([$entregaId, $deTipo, $deId]);

        enviarNotificacao($pdo, $entregaId, 'cliente', 0,
            '✅ Pedido Entregue!',
            $tipo === 'retirada' ? 'Você retirou seu pedido com sucesso!' : 'Seu pedido foi entregue!',
            'sucesso'
        );

        jsonResponse(true, 'Entrega finalizada');
        break;

    // ═══════════════════════════════════════════════════════════════════════════
    // STATUS - Consultar status da entrega (para cliente)
    // ═══════════════════════════════════════════════════════════════════════════
    case 'status':
        $entregaId = intval($input['entrega_id'] ?? $_GET['entrega_id'] ?? 0);
        $orderId = intval($input['order_id'] ?? $_GET['order_id'] ?? 0);

        $where = $entregaId ? "id = ?" : "referencia_id = ?";
        $param = $entregaId ?: $orderId;

        $stmt = $pdo->prepare("
            SELECT e.*, p.nome as ponto_nome, p.endereco as ponto_endereco,
                   p.latitude as ponto_lat, p.longitude as ponto_lng
            FROM om_entregas e
            LEFT JOIN om_pontos_apoio p ON p.id = e.ponto_apoio_id
            WHERE e.$where
        ");
        $stmt->execute([$param]);
        $entrega = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$entrega) {
            jsonResponse(false, 'Entrega não encontrada');
        }

        // Buscar tracking
        $stmt = $pdo->prepare("SELECT * FROM om_entrega_tracking WHERE entrega_id = ? ORDER BY created_at DESC LIMIT 20");
        $stmt->execute([$entrega['id']]);
        $tracking = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Buscar entregador se houver
        $entregador = null;
        if ($entrega['driver_id']) {
            $entregador = [
                'nome' => 'Entregador',
                'telefone' => '',
                'tipo' => 'boraum'
            ];
        }

        $statusInfo = getStatusLabel($entrega['status']);

        jsonResponse(true, 'Status encontrado', [
            'entrega' => [
                'id' => $entrega['id'],
                'order_id' => $entrega['referencia_id'],
                'tipo' => $entrega['metodo_entrega'],
                'status' => $entrega['status'],
                'status_texto' => $statusInfo['texto'],
                'status_cor' => $statusInfo['cor'],
                'codigo_retirada' => $entrega['pin_entrega'],
                'previsao' => null,
                'valor_frete' => floatval($entrega['valor_frete'])
            ],
            'ponto_apoio' => $entrega['ponto_apoio_id'] ? [
                'nome' => $entrega['ponto_nome'],
                'endereco' => $entrega['ponto_endereco'],
                'lat' => floatval($entrega['ponto_lat']),
                'lng' => floatval($entrega['ponto_lng'])
            ] : null,
            'entregador' => $entregador,
            'tracking' => $tracking
        ]);
        break;

    // ═══════════════════════════════════════════════════════════════════════════
    // LISTAR - Para admin/vendedor/ponto
    // ═══════════════════════════════════════════════════════════════════════════
    case 'listar':
        $tipo = $input['tipo'] ?? $_GET['tipo'] ?? 'todas';
        $id = intval($input['id'] ?? $_GET['id'] ?? 0);
        $status = $input['status'] ?? $_GET['status'] ?? '';
        $limite = min(100, intval($input['limite'] ?? $_GET['limite'] ?? 50));

        $where = "1=1";
        $params = [];

        if ($tipo === 'vendedor' && $id) {
            $where .= " AND e.remetente_id = ?";
            $params[] = $id;
        } elseif ($tipo === 'ponto' && $id) {
            $where .= " AND e.ponto_apoio_id = ?";
            $params[] = $id;
        } elseif ($tipo === 'cliente' && $id) {
            $where .= " AND e.referencia_id = ?";
            $params[] = $id;
        }

        if ($status) {
            $where .= " AND e.status = ?";
            $params[] = $status;
        }

        // LIMIT precisa ser inteiro direto na query (não aceita bind em algumas versões MySQL)
        $limite = intval($limite);

        $stmt = $pdo->prepare("
            SELECT e.*,
                   e.destinatario_nome as cliente_nome, '' as cliente_email,
                   v.nome as vendedor_nome,
                   p.nome as ponto_nome
            FROM om_entregas e
            LEFT JOIN om_pontos_apoio v ON v.id = e.remetente_id
            LEFT JOIN om_pontos_apoio p ON p.id = e.ponto_apoio_id
            WHERE $where
            ORDER BY e.created_at DESC
            LIMIT $limite
        ");
        $stmt->execute($params);
        $entregas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Formatar
        $resultado = array_map(function($e) {
            $status = getStatusLabel($e['status']);
            return [
                'id' => $e['id'],
                'order_id' => $e['referencia_id'],
                'tipo' => $e['metodo_entrega'],
                'status' => $e['status'],
                'status_texto' => $status['texto'],
                'status_cor' => $status['cor'],
                'cliente' => $e['cliente_nome'],
                'vendedor' => $e['vendedor_nome'],
                'ponto' => $e['ponto_nome'],
                'valor_frete' => floatval($e['valor_frete']),
                'codigo_retirada' => $e['pin_entrega'],
                'criado' => $e['created_at']
            ];
        }, $entregas);

        jsonResponse(true, 'Lista de entregas', ['entregas' => $resultado, 'total' => count($resultado)]);
        break;

    // ═══════════════════════════════════════════════════════════════════════════
    // NOTIFICAÇÕES - Buscar notificações não lidas
    // ═══════════════════════════════════════════════════════════════════════════
    case 'notificacoes':
        $tipo = $input['tipo'] ?? $_GET['tipo'] ?? '';
        $id = intval($input['id'] ?? $_GET['id'] ?? 0);

        if (!$tipo || !$id) {
            jsonResponse(false, 'Tipo e ID obrigatórios');
        }

        $stmt = $pdo->prepare("
            SELECT * FROM om_entrega_notificacoes
            WHERE destinatario_tipo = ? AND destinatario_id = ? AND lido = 0
            ORDER BY created_at DESC LIMIT 20
        ");
        $stmt->execute([$tipo, $id]);
        $notificacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(true, 'Notificações', ['notificacoes' => $notificacoes, 'total' => count($notificacoes)]);
        break;

    // ═══════════════════════════════════════════════════════════════════════════
    // MARCAR LIDA
    // ═══════════════════════════════════════════════════════════════════════════
    case 'marcar_lida':
        $notificacaoId = intval($input['notificacao_id'] ?? 0);

        $stmt = $pdo->prepare("UPDATE om_entrega_notificacoes SET lido = 1, data_lido = NOW() WHERE id = ?");
        $stmt->execute([$notificacaoId]);

        jsonResponse(true, 'Marcada como lida');
        break;

    // ═══════════════════════════════════════════════════════════════════════════
    // SUPORTE: Chamar entregador direto (para recebe_hoje_direto sem ponto de apoio)
    // Vendedor NÃO vê o endereço do cliente - privacidade total
    // ═══════════════════════════════════════════════════════════════════════════
    case 'chamar_entregador_direto':
        $entregaId = intval($input['entrega_id'] ?? 0);
        $servicoTipo = $input['servico'] ?? 'boraum'; // boraum, uber, 99

        if (!$entregaId) {
            jsonResponse(false, 'ID da entrega obrigatório');
        }

        // Buscar entrega com dados do vendedor
        $stmt = $pdo->prepare("
            SELECT e.*, v.nome as vendedor_nome, v.endereco as vendedor_endereco,
                   v.latitude as vendedor_lat, v.longitude as vendedor_lng,
                   v.telefone as vendedor_telefone
            FROM om_entregas e
            JOIN om_pontos_apoio v ON v.id = e.remetente_id
            WHERE e.id = ?
        ");
        $stmt->execute([$entregaId]);
        $entrega = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$entrega) {
            jsonResponse(false, 'Entrega não encontrada');
        }

        if ($entrega['metodo_entrega'] !== 'recebe_hoje_direto') {
            jsonResponse(false, 'Esta entrega não é do tipo direto');
        }

        // Calcular distância e valor
        $distancia = calcularDistancia(
            floatval($entrega['vendedor_lat']), floatval($entrega['vendedor_lng']),
            floatval($entrega['entrega_lat']), floatval($entrega['entrega_lng'])
        ) ?? 5;
        $valorEstimado = max(12.90, round($distancia * 2.50, 2)); // Preço maior para entrega direta

        // Criar chamada de entregador (BoraUm ou fallback)
        $stmt = $pdo->prepare("
            INSERT INTO om_boraum_chamadas (
                entrega_id, ponto_apoio_id,
                origem_endereco, origem_lat, origem_lng,
                destino_endereco, destino_lat, destino_lng,
                status, valor_estimado, servico_tipo, entrega_direta
            ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, 'buscando_motorista', ?, ?, 1)
        ");
        $stmt->execute([
            $entregaId,
            $entrega['vendedor_endereco'], $entrega['vendedor_lat'], $entrega['vendedor_lng'],
            $entrega['entrega_endereco'], $entrega['entrega_lat'], $entrega['entrega_lng'],
            $valorEstimado, $servicoTipo
        ]);
        $chamadaId = $pdo->lastInsertId();

        atualizarStatusEntrega($pdo, $entregaId, 'aguardando_coleta');

        // NOTIFICAR VENDEDOR - Avisa que entregador vai buscar
        enviarNotificacao($pdo, $entregaId, 'vendedor', $entrega['remetente_id'],
            '🏍️ Entregador a Caminho!',
            'Um entregador está vindo buscar o pedido #' . $entrega['referencia_id'] . '. Deixe pronto para retirada!',
            'info'
        );

        // NOTIFICAR CLIENTE
        enviarNotificacao($pdo, $entregaId, 'cliente', 0,
            '🔍 Buscando Entregador',
            'Estamos buscando um entregador para seu pedido. Ele irá buscar diretamente no vendedor e entregar a você!',
            'info'
        );

        // TODO: Integrar com API real do BoraUm/Uber/99
        // Por enquanto, simulamos a busca de motorista

        jsonResponse(true, 'Buscando entregador para coleta direta', [
            'chamada_id' => $chamadaId,
            'valor_estimado' => $valorEstimado,
            'distancia_km' => round($distancia, 1),
            'servico' => $servicoTipo,
            'origem' => [
                'nome' => $entrega['vendedor_nome'],
                'endereco' => $entrega['vendedor_endereco']
            ],
            'nota' => 'Vendedor NÃO vê o endereço do cliente - privacidade mantida'
        ]);
        break;

    // ═══════════════════════════════════════════════════════════════════════════
    // MOTORISTA COLETOU NO VENDEDOR (para entrega direta)
    // ═══════════════════════════════════════════════════════════════════════════
    case 'motorista_coletou':
        $chamadaId = intval($input['chamada_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM om_boraum_chamadas WHERE id = ?");
        $stmt->execute([$chamadaId]);
        $chamada = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$chamada) {
            jsonResponse(false, 'Chamada não encontrada');
        }

        // Atualizar chamada
        $stmt = $pdo->prepare("UPDATE om_boraum_chamadas SET status = 'coletado', data_coleta = NOW() WHERE id = ?");
        $stmt->execute([$chamadaId]);

        // Atualizar entrega
        atualizarStatusEntrega($pdo, $chamada['entrega_id'], 'em_transito_direto');

        // Buscar customer_id
        $stmt = $pdo->prepare("SELECT referencia_id, driver_id, destinatario_nome FROM om_entregas WHERE id = ?");
        $stmt->execute([$chamada['entrega_id']]);
        $entrega = $stmt->fetch(PDO::FETCH_ASSOC);

        enviarNotificacao($pdo, $chamada['entrega_id'], 'cliente', 0,
            '📦 Pedido Coletado!',
            'O entregador coletou seu pedido e está a caminho da sua casa!',
            'info'
        );

        jsonResponse(true, 'Coleta registrada');
        break;

    // ═══════════════════════════════════════════════════════════════════════════
    // LISTAR ENTREGAS PENDENTES DE SUPORTE (sem ponto de apoio)
    // ═══════════════════════════════════════════════════════════════════════════
    case 'listar_pendentes_suporte':
        $stmt = $pdo->prepare("
            SELECT e.*, v.nome as vendedor_nome, v.endereco as vendedor_endereco,
                   e.destinatario_nome as cliente_nome, e.destinatario_telefone as cliente_telefone
            FROM om_entregas e
            JOIN om_pontos_apoio v ON v.id = e.remetente_id
            WHERE e.metodo_entrega = 'recebe_hoje_direto'
              AND e.status IN ('aguardando_vendedor', 'vendedor_preparando')
              AND e.ponto_apoio_id IS NULL
            ORDER BY e.created_at ASC
        ");
        $stmt->execute();
        $entregas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $resultado = array_map(function($e) {
            return [
                'id' => $e['id'],
                'order_id' => $e['referencia_id'],
                'status' => $e['status'],
                'vendedor' => $e['vendedor_nome'],
                'vendedor_endereco' => $e['vendedor_endereco'],
                'cliente' => $e['cliente_nome'],
                'cidade' => '',
                'valor_frete' => floatval($e['valor_frete']),
                'criado' => $e['created_at'],
                'urgente' => true
            ];
        }, $entregas);

        jsonResponse(true, 'Entregas pendentes de suporte', ['entregas' => $resultado, 'total' => count($resultado)]);
        break;

    default:
        jsonResponse(false, 'Ação inválida', [
            'acoes' => [
                'calcular_opcoes', 'criar', 'vendedor_status', 'ponto_receber',
                'chamar_entregador', 'motorista_aceito', 'finalizar', 'status',
                'listar', 'notificacoes', 'marcar_lida',
                // Novas ações para entrega direta (sem ponto de apoio)
                'chamar_entregador_direto', 'motorista_coletou', 'listar_pendentes_suporte'
            ]
        ]);
}
