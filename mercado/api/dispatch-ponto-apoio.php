<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ONEMUNDO - API DE DISPATCH PONTO DE APOIO + BORAUM
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Sistema de logistica em duas etapas:
 * ETAPA 1: Vendedor → Ponto de Apoio (moto via BoraUm)
 * ETAPA 2: Ponto de Apoio → Cliente (moto via BoraUm)
 *
 * Endpoints:
 * - dispatch_etapa1: Despachar coleta no vendedor → entrega no ponto
 * - dispatch_etapa2: Despachar coleta no ponto → entrega no cliente
 * - status: Verificar status do dispatch
 * - motoristas: Ver motoristas disponiveis
 * - cancelar: Cancelar dispatch
 * - webhook: Receber atualizacoes do BoraUm
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

// Config
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getPDO();
} catch (Exception $e) {
    jsonOut(['success' => false, 'error' => 'Erro de conexao']);
}

// URL da API do BoraUm
define('BORAUM_API_URL', 'http://localhost/boraum/api_entrega.php');

// Funcao de resposta JSON
function jsonOut($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Funcao para chamar API do BoraUm
function callBoraUm($action, $data = []) {
    $url = BORAUM_API_URL . '?action=' . $action;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => 0, 'erro' => 'Falha na comunicacao: ' . $error];
    }

    $decoded = json_decode($response, true);
    if (!$decoded) {
        return ['ok' => 0, 'erro' => 'Resposta invalida do BoraUm'];
    }

    return $decoded;
}

// Criar tabela de dispatches se nao existir
$pdo->exec("CREATE TABLE IF NOT EXISTS om_dispatch_ponto_apoio (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    seller_id INT NOT NULL,
    ponto_apoio_id INT NOT NULL,
    customer_id INT NOT NULL,
    pacote_id INT NULL,

    -- Etapa 1: Vendedor -> Ponto
    etapa1_status ENUM('pendente','buscando','aceito','coletando','coletado','entregando','entregue','cancelado') DEFAULT 'pendente',
    etapa1_boraum_id INT NULL,
    etapa1_motorista_id INT NULL,
    etapa1_motorista_nome VARCHAR(100) NULL,
    etapa1_motorista_telefone VARCHAR(20) NULL,
    etapa1_motorista_placa VARCHAR(10) NULL,
    etapa1_dispatched_at DATETIME NULL,
    etapa1_accepted_at DATETIME NULL,
    etapa1_collected_at DATETIME NULL,
    etapa1_delivered_at DATETIME NULL,

    -- Etapa 2: Ponto -> Cliente
    etapa2_status ENUM('aguardando','pendente','buscando','aceito','coletando','coletado','entregando','entregue','cancelado') DEFAULT 'aguardando',
    etapa2_boraum_id INT NULL,
    etapa2_motorista_id INT NULL,
    etapa2_motorista_nome VARCHAR(100) NULL,
    etapa2_motorista_telefone VARCHAR(20) NULL,
    etapa2_motorista_placa VARCHAR(10) NULL,
    etapa2_dispatched_at DATETIME NULL,
    etapa2_accepted_at DATETIME NULL,
    etapa2_collected_at DATETIME NULL,
    etapa2_delivered_at DATETIME NULL,

    -- Enderecos
    vendedor_endereco VARCHAR(255),
    vendedor_lat DECIMAL(10,8),
    vendedor_lng DECIMAL(11,8),
    ponto_endereco VARCHAR(255),
    ponto_lat DECIMAL(10,8),
    ponto_lng DECIMAL(11,8),
    cliente_endereco VARCHAR(255),
    cliente_lat DECIMAL(10,8),
    cliente_lng DECIMAL(11,8),

    -- Valores
    taxa_etapa1 DECIMAL(10,2) DEFAULT 0,
    taxa_etapa2 DECIMAL(10,2) DEFAULT 0,
    taxa_total DECIMAL(10,2) DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_order (order_id),
    INDEX idx_seller (seller_id),
    INDEX idx_ponto (ponto_apoio_id),
    INDEX idx_status1 (etapa1_status),
    INDEX idx_status2 (etapa2_status)
) ENGINE=InnoDB");

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// ═══════════════════════════════════════════════════════════════════════════════
// CRIAR DISPATCH: Inicializa o dispatch para um pedido
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'criar') {
    $order_id = (int)($input['order_id'] ?? 0);
    $seller_id = (int)($input['seller_id'] ?? 0);
    $ponto_apoio_id = (int)($input['ponto_apoio_id'] ?? 0);

    if (!$order_id || !$seller_id || !$ponto_apoio_id) {
        jsonOut(['success' => false, 'error' => 'order_id, seller_id e ponto_apoio_id sao obrigatorios']);
    }

    // Verificar se ja existe
    $stmt = $pdo->prepare("SELECT id FROM om_dispatch_ponto_apoio WHERE order_id = ?");
    $stmt->execute([$order_id]);
    if ($stmt->fetch()) {
        jsonOut(['success' => false, 'error' => 'Dispatch ja existe para este pedido']);
    }

    // Buscar dados do vendedor (usando oc_purpletree_vendor_stores)
    $stmt = $pdo->prepare("
        SELECT v.seller_id, v.store_name as nome_loja, v.store_address,
               v.store_latitude as latitude, v.store_longitude as longitude,
               v.store_city as city, v.store_state as state,
               c.firstname, c.telephone
        FROM oc_purpletree_vendor_stores v
        LEFT JOIN oc_customer c ON c.customer_id = v.seller_id
        WHERE v.seller_id = ?
    ");
    $stmt->execute([$seller_id]);
    $vendedor = $stmt->fetch();

    if (!$vendedor) {
        jsonOut(['success' => false, 'error' => 'Vendedor nao encontrado']);
    }

    // Buscar dados do ponto de apoio
    $stmt = $pdo->prepare("SELECT * FROM om_pontos_apoio WHERE id = ? AND status = 'ativo'");
    $stmt->execute([$ponto_apoio_id]);
    $ponto = $stmt->fetch();

    if (!$ponto) {
        jsonOut(['success' => false, 'error' => 'Ponto de apoio nao encontrado ou inativo']);
    }

    // Buscar dados do pedido e cliente (om_market_orders)
    $stmt = $pdo->prepare("
        SELECT o.*,
               o.customer_id, o.customer_name as firstname, o.customer_phone as telephone,
               o.shipping_address as shipping_address_1,
               o.shipping_city, o.shipping_state,
               o.shipping_latitude as shipping_lat, o.shipping_longitude as shipping_lng
        FROM om_market_orders o
        WHERE o.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();

    if (!$order) {
        // Tentar na tabela oc_order (OpenCart padrão)
        $stmt = $pdo->prepare("
            SELECT o.*, c.firstname, c.lastname, c.telephone,
                   o.shipping_address_1, o.shipping_city
            FROM oc_order o
            LEFT JOIN oc_customer c ON c.customer_id = o.customer_id
            WHERE o.order_id = ?
        ");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();
    }

    if (!$order) {
        jsonOut(['success' => false, 'error' => 'Pedido nao encontrado']);
    }

    // Montar enderecos
    $vendedor_endereco = trim(($vendedor['store_address'] ?? '') . ', ' . ($vendedor['city'] ?? ''));
    $ponto_endereco = trim($ponto['endereco'] . ' ' . $ponto['numero'] . ', ' . $ponto['bairro'] . ', ' . $ponto['cidade']);
    $cliente_endereco = trim(($order['shipping_address_1'] ?? $order['shipping_address']) . ', ' . ($order['shipping_city'] ?? ''));

    // Criar registro do dispatch
    $stmt = $pdo->prepare("
        INSERT INTO om_dispatch_ponto_apoio (
            order_id, seller_id, ponto_apoio_id, customer_id,
            vendedor_endereco, vendedor_lat, vendedor_lng,
            ponto_endereco, ponto_lat, ponto_lng,
            cliente_endereco, cliente_lat, cliente_lng,
            etapa1_status, etapa2_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendente', 'aguardando')
    ");
    $stmt->execute([
        $order_id, $seller_id, $ponto_apoio_id, $order['customer_id'],
        $vendedor_endereco, $vendedor['latitude'] ?? 0, $vendedor['longitude'] ?? 0,
        $ponto_endereco, $ponto['latitude'], $ponto['longitude'],
        $cliente_endereco, $order['shipping_lat'] ?? 0, $order['shipping_lng'] ?? 0
    ]);

    $dispatch_id = $pdo->lastInsertId();

    // Criar pacote no ponto de apoio
    $codigo_pacote = 'PKT-' . strtoupper(substr(md5($order_id . time()), 0, 8));
    $stmt = $pdo->prepare("
        INSERT INTO om_ponto_apoio_pacotes (
            ponto_apoio_id, tipo, referencia_id, codigo_pacote,
            origem_tipo, origem_id, destino_tipo, destino_id, status
        ) VALUES (?, 'envio_vendedor', ?, ?, 'vendedor', ?, 'cliente', ?, 'aguardando')
    ");
    $stmt->execute([$ponto_apoio_id, $order_id, $codigo_pacote, $seller_id, $order['customer_id']]);
    $pacote_id = $pdo->lastInsertId();

    // Atualizar dispatch com pacote_id
    $pdo->prepare("UPDATE om_dispatch_ponto_apoio SET pacote_id = ? WHERE id = ?")->execute([$pacote_id, $dispatch_id]);

    jsonOut([
        'success' => true,
        'dispatch_id' => $dispatch_id,
        'pacote_id' => $pacote_id,
        'codigo_pacote' => $codigo_pacote,
        'message' => 'Dispatch criado. Use dispatch_etapa1 para iniciar a coleta.'
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// DISPATCH ETAPA 1: Vendedor → Ponto de Apoio
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'dispatch_etapa1') {
    $order_id = (int)($input['order_id'] ?? 0);

    if (!$order_id) {
        jsonOut(['success' => false, 'error' => 'order_id obrigatorio']);
    }

    // Buscar dispatch
    $stmt = $pdo->prepare("SELECT * FROM om_dispatch_ponto_apoio WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $dispatch = $stmt->fetch();

    if (!$dispatch) {
        jsonOut(['success' => false, 'error' => 'Dispatch nao encontrado. Crie primeiro com action=criar']);
    }

    if ($dispatch['etapa1_status'] !== 'pendente') {
        jsonOut(['success' => false, 'error' => 'Etapa 1 ja foi despachada. Status: ' . $dispatch['etapa1_status']]);
    }

    // Buscar dados do vendedor
    $stmt = $pdo->prepare("SELECT v.*, c.firstname, c.telephone FROM om_vendedores v LEFT JOIN oc_customer c ON c.customer_id = v.customer_id WHERE v.vendedor_id = ?");
    $stmt->execute([$dispatch['seller_id']]);
    $vendedor = $stmt->fetch();

    // Buscar ponto de apoio
    $stmt = $pdo->prepare("SELECT * FROM om_pontos_apoio WHERE id = ?");
    $stmt->execute([$dispatch['ponto_apoio_id']]);
    $ponto = $stmt->fetch();

    // Preparar dados para BoraUm
    $boraUmData = [
        'pedido_id' => 'OM-E1-' . $order_id,
        'loja_nome' => $vendedor['nome_loja'] ?? $vendedor['firstname'] ?? 'Vendedor OneMundo',
        'loja_endereco' => $dispatch['vendedor_endereco'],
        'loja_lat' => (float)$dispatch['vendedor_lat'],
        'loja_lng' => (float)$dispatch['vendedor_lng'],
        'cliente_nome' => 'Ponto de Apoio: ' . $ponto['nome'],
        'cliente_telefone' => $ponto['telefone'] ?? '',
        'cliente_endereco' => $dispatch['ponto_endereco'],
        'cliente_lat' => (float)$dispatch['ponto_lat'],
        'cliente_lng' => (float)$dispatch['ponto_lng'],
        'valor_pedido' => 0,
        'taxa_entrega' => (float)$dispatch['taxa_etapa1'],
        'forma_pagamento' => 'app'
    ];

    // Enviar para BoraUm
    $result = callBoraUm('novo_pedido', $boraUmData);

    if ($result['ok'] ?? false) {
        // Atualizar dispatch
        $stmt = $pdo->prepare("
            UPDATE om_dispatch_ponto_apoio
            SET etapa1_status = 'buscando',
                etapa1_boraum_id = ?,
                etapa1_dispatched_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$result['pedido_id'] ?? 0, $dispatch['id']]);

        // Atualizar pacote
        $pdo->prepare("UPDATE om_ponto_apoio_pacotes SET status = 'aguardando_coleta' WHERE id = ?")->execute([$dispatch['pacote_id']]);

        jsonOut([
            'success' => true,
            'message' => 'Etapa 1 despachada: Vendedor -> Ponto de Apoio',
            'boraum_id' => $result['pedido_id'] ?? 0,
            'dispatch' => $result['dispatch'] ?? null
        ]);
    } else {
        jsonOut([
            'success' => false,
            'error' => $result['erro'] ?? 'Erro ao despachar para BoraUm'
        ]);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// DISPATCH ETAPA 2: Ponto de Apoio → Cliente
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'dispatch_etapa2') {
    $order_id = (int)($input['order_id'] ?? 0);

    if (!$order_id) {
        jsonOut(['success' => false, 'error' => 'order_id obrigatorio']);
    }

    // Buscar dispatch
    $stmt = $pdo->prepare("SELECT * FROM om_dispatch_ponto_apoio WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $dispatch = $stmt->fetch();

    if (!$dispatch) {
        jsonOut(['success' => false, 'error' => 'Dispatch nao encontrado']);
    }

    // Verificar se etapa 1 foi concluida
    if ($dispatch['etapa1_status'] !== 'entregue') {
        jsonOut(['success' => false, 'error' => 'Etapa 1 ainda nao foi concluida. Status: ' . $dispatch['etapa1_status']]);
    }

    if (!in_array($dispatch['etapa2_status'], ['aguardando', 'pendente'])) {
        jsonOut(['success' => false, 'error' => 'Etapa 2 ja foi despachada. Status: ' . $dispatch['etapa2_status']]);
    }

    // Buscar ponto de apoio
    $stmt = $pdo->prepare("SELECT * FROM om_pontos_apoio WHERE id = ?");
    $stmt->execute([$dispatch['ponto_apoio_id']]);
    $ponto = $stmt->fetch();

    // Buscar cliente
    $stmt = $pdo->prepare("SELECT c.*, CONCAT(c.firstname, ' ', c.lastname) as nome_completo FROM oc_customer c WHERE c.customer_id = ?");
    $stmt->execute([$dispatch['customer_id']]);
    $cliente = $stmt->fetch();

    // Preparar dados para BoraUm
    $boraUmData = [
        'pedido_id' => 'OM-E2-' . $order_id,
        'loja_nome' => 'Ponto de Apoio: ' . $ponto['nome'],
        'loja_endereco' => $dispatch['ponto_endereco'],
        'loja_lat' => (float)$dispatch['ponto_lat'],
        'loja_lng' => (float)$dispatch['ponto_lng'],
        'cliente_nome' => $cliente['nome_completo'] ?? 'Cliente OneMundo',
        'cliente_telefone' => $cliente['telephone'] ?? '',
        'cliente_endereco' => $dispatch['cliente_endereco'],
        'cliente_lat' => (float)$dispatch['cliente_lat'],
        'cliente_lng' => (float)$dispatch['cliente_lng'],
        'valor_pedido' => 0,
        'taxa_entrega' => (float)$dispatch['taxa_etapa2'],
        'forma_pagamento' => 'app'
    ];

    // Enviar para BoraUm
    $result = callBoraUm('novo_pedido', $boraUmData);

    if ($result['ok'] ?? false) {
        // Atualizar dispatch
        $stmt = $pdo->prepare("
            UPDATE om_dispatch_ponto_apoio
            SET etapa2_status = 'buscando',
                etapa2_boraum_id = ?,
                etapa2_dispatched_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$result['pedido_id'] ?? 0, $dispatch['id']]);

        // Atualizar pacote
        $pdo->prepare("UPDATE om_ponto_apoio_pacotes SET status = 'aguardando_coleta' WHERE id = ?")->execute([$dispatch['pacote_id']]);

        jsonOut([
            'success' => true,
            'message' => 'Etapa 2 despachada: Ponto de Apoio -> Cliente',
            'boraum_id' => $result['pedido_id'] ?? 0,
            'dispatch' => $result['dispatch'] ?? null
        ]);
    } else {
        jsonOut([
            'success' => false,
            'error' => $result['erro'] ?? 'Erro ao despachar para BoraUm'
        ]);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// STATUS: Verificar status completo do dispatch
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'status') {
    $order_id = (int)($input['order_id'] ?? $_GET['order_id'] ?? 0);

    if (!$order_id) {
        jsonOut(['success' => false, 'error' => 'order_id obrigatorio']);
    }

    // Buscar dispatch
    $stmt = $pdo->prepare("
        SELECT d.*,
               pa.nome as ponto_nome, pa.telefone as ponto_telefone,
               v.nome_loja as vendedor_nome,
               p.codigo_pacote
        FROM om_dispatch_ponto_apoio d
        LEFT JOIN om_pontos_apoio pa ON pa.id = d.ponto_apoio_id
        LEFT JOIN om_vendedores v ON v.vendedor_id = d.seller_id
        LEFT JOIN om_ponto_apoio_pacotes p ON p.id = d.pacote_id
        WHERE d.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $dispatch = $stmt->fetch();

    if (!$dispatch) {
        jsonOut(['success' => false, 'error' => 'Dispatch nao encontrado']);
    }

    // Sincronizar com BoraUm se houver dispatches ativos
    if ($dispatch['etapa1_boraum_id'] && $dispatch['etapa1_status'] === 'buscando') {
        $result = callBoraUm('status', ['pedido_id' => $dispatch['etapa1_boraum_id']]);
        if ($result['ok'] ?? false) {
            $pedido = $result['pedido'] ?? [];
            $newStatus = mapBoraUmStatus($pedido['status'] ?? '');

            if ($newStatus && $newStatus !== $dispatch['etapa1_status']) {
                $updates = ["etapa1_status = '$newStatus'"];

                if (!empty($pedido['motorista_id'])) {
                    $updates[] = "etapa1_motorista_id = " . (int)$pedido['motorista_id'];
                    $updates[] = "etapa1_motorista_nome = '" . addslashes($pedido['motorista_nome'] ?? '') . "'";
                    $updates[] = "etapa1_motorista_telefone = '" . addslashes($pedido['motorista_telefone'] ?? '') . "'";
                    $updates[] = "etapa1_motorista_placa = '" . addslashes($pedido['veiculo_placa'] ?? '') . "'";
                }

                if ($newStatus === 'aceito' && !$dispatch['etapa1_accepted_at']) {
                    $updates[] = "etapa1_accepted_at = NOW()";
                }
                if ($newStatus === 'coletado' && !$dispatch['etapa1_collected_at']) {
                    $updates[] = "etapa1_collected_at = NOW()";
                }
                if ($newStatus === 'entregue' && !$dispatch['etapa1_delivered_at']) {
                    $updates[] = "etapa1_delivered_at = NOW()";
                    $updates[] = "etapa2_status = 'pendente'"; // Liberar etapa 2
                }

                $pdo->exec("UPDATE om_dispatch_ponto_apoio SET " . implode(', ', $updates) . " WHERE id = " . $dispatch['id']);
            }
        }
    }

    if ($dispatch['etapa2_boraum_id'] && $dispatch['etapa2_status'] === 'buscando') {
        $result = callBoraUm('status', ['pedido_id' => $dispatch['etapa2_boraum_id']]);
        if ($result['ok'] ?? false) {
            $pedido = $result['pedido'] ?? [];
            $newStatus = mapBoraUmStatus($pedido['status'] ?? '');

            if ($newStatus && $newStatus !== $dispatch['etapa2_status']) {
                $updates = ["etapa2_status = '$newStatus'"];

                if (!empty($pedido['motorista_id'])) {
                    $updates[] = "etapa2_motorista_id = " . (int)$pedido['motorista_id'];
                    $updates[] = "etapa2_motorista_nome = '" . addslashes($pedido['motorista_nome'] ?? '') . "'";
                    $updates[] = "etapa2_motorista_telefone = '" . addslashes($pedido['motorista_telefone'] ?? '') . "'";
                    $updates[] = "etapa2_motorista_placa = '" . addslashes($pedido['veiculo_placa'] ?? '') . "'";
                }

                if ($newStatus === 'aceito' && !$dispatch['etapa2_accepted_at']) {
                    $updates[] = "etapa2_accepted_at = NOW()";
                }
                if ($newStatus === 'coletado' && !$dispatch['etapa2_collected_at']) {
                    $updates[] = "etapa2_collected_at = NOW()";
                }
                if ($newStatus === 'entregue' && !$dispatch['etapa2_delivered_at']) {
                    $updates[] = "etapa2_delivered_at = NOW()";
                }

                $pdo->exec("UPDATE om_dispatch_ponto_apoio SET " . implode(', ', $updates) . " WHERE id = " . $dispatch['id']);
            }
        }
    }

    // Recarregar dispatch atualizado
    $stmt->execute([$order_id]);
    $dispatch = $stmt->fetch();

    // Determinar status geral
    $status_geral = 'criado';
    if ($dispatch['etapa1_status'] === 'entregue' && $dispatch['etapa2_status'] === 'entregue') {
        $status_geral = 'concluido';
    } elseif ($dispatch['etapa2_status'] !== 'aguardando') {
        $status_geral = 'etapa2_' . $dispatch['etapa2_status'];
    } elseif ($dispatch['etapa1_status'] !== 'pendente') {
        $status_geral = 'etapa1_' . $dispatch['etapa1_status'];
    }

    jsonOut([
        'success' => true,
        'status_geral' => $status_geral,
        'dispatch' => $dispatch,
        'etapa1' => [
            'descricao' => 'Vendedor -> Ponto de Apoio',
            'status' => $dispatch['etapa1_status'],
            'motorista' => $dispatch['etapa1_motorista_nome'] ? [
                'nome' => $dispatch['etapa1_motorista_nome'],
                'telefone' => $dispatch['etapa1_motorista_telefone'],
                'placa' => $dispatch['etapa1_motorista_placa']
            ] : null
        ],
        'etapa2' => [
            'descricao' => 'Ponto de Apoio -> Cliente',
            'status' => $dispatch['etapa2_status'],
            'motorista' => $dispatch['etapa2_motorista_nome'] ? [
                'nome' => $dispatch['etapa2_motorista_nome'],
                'telefone' => $dispatch['etapa2_motorista_telefone'],
                'placa' => $dispatch['etapa2_motorista_placa']
            ] : null
        ]
    ]);
}

// Funcao para mapear status do BoraUm
function mapBoraUmStatus($status) {
    $map = [
        'novo' => 'pendente',
        'buscando' => 'buscando',
        'aceito' => 'aceito',
        'coletando' => 'coletando',
        'coletado' => 'coletado',
        'entregando' => 'entregando',
        'entregue' => 'entregue',
        'cancelado' => 'cancelado'
    ];
    return $map[$status] ?? null;
}

// ═══════════════════════════════════════════════════════════════════════════════
// WEBHOOK: Receber atualizacoes do BoraUm
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'webhook') {
    $pedido_externo = $input['pedido_id'] ?? '';
    $status = $input['status'] ?? '';
    $motorista = $input['motorista'] ?? [];

    if (!$pedido_externo || !$status) {
        jsonOut(['success' => false, 'error' => 'pedido_id e status obrigatorios']);
    }

    // Identificar qual etapa (OM-E1-xxx ou OM-E2-xxx)
    if (preg_match('/^OM-E([12])-(\d+)$/', $pedido_externo, $matches)) {
        $etapa = $matches[1];
        $order_id = (int)$matches[2];

        $prefix = ($etapa === '1') ? 'etapa1' : 'etapa2';
        $newStatus = mapBoraUmStatus($status);

        if ($newStatus) {
            $updates = ["{$prefix}_status = '$newStatus'"];

            if (!empty($motorista['id'])) {
                $updates[] = "{$prefix}_motorista_id = " . (int)$motorista['id'];
                $updates[] = "{$prefix}_motorista_nome = '" . addslashes($motorista['nome'] ?? '') . "'";
                $updates[] = "{$prefix}_motorista_telefone = '" . addslashes($motorista['telefone'] ?? '') . "'";
                $updates[] = "{$prefix}_motorista_placa = '" . addslashes($motorista['placa'] ?? '') . "'";
            }

            if ($newStatus === 'aceito') {
                $updates[] = "{$prefix}_accepted_at = NOW()";
            }
            if ($newStatus === 'coletado') {
                $updates[] = "{$prefix}_collected_at = NOW()";
            }
            if ($newStatus === 'entregue') {
                $updates[] = "{$prefix}_delivered_at = NOW()";
                if ($etapa === '1') {
                    $updates[] = "etapa2_status = 'pendente'";
                }
            }

            $pdo->exec("UPDATE om_dispatch_ponto_apoio SET " . implode(', ', $updates) . " WHERE order_id = $order_id");

            // Se etapa 1 entregue, atualizar pacote
            if ($etapa === '1' && $newStatus === 'entregue') {
                $pdo->exec("UPDATE om_ponto_apoio_pacotes p
                            JOIN om_dispatch_ponto_apoio d ON d.pacote_id = p.id
                            SET p.status = 'recebido', p.data_recebimento = NOW()
                            WHERE d.order_id = $order_id");
            }

            // Se etapa 2 entregue, finalizar pacote
            if ($etapa === '2' && $newStatus === 'entregue') {
                $pdo->exec("UPDATE om_ponto_apoio_pacotes p
                            JOIN om_dispatch_ponto_apoio d ON d.pacote_id = p.id
                            SET p.status = 'entregue', p.data_entrega = NOW()
                            WHERE d.order_id = $order_id");
            }
        }

        jsonOut(['success' => true, 'message' => 'Webhook processado']);
    }

    jsonOut(['success' => false, 'error' => 'Formato de pedido_id invalido']);
}

// ═══════════════════════════════════════════════════════════════════════════════
// CANCELAR: Cancelar dispatch
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'cancelar') {
    $order_id = (int)($input['order_id'] ?? 0);
    $motivo = $input['motivo'] ?? 'Cancelado pelo sistema';

    if (!$order_id) {
        jsonOut(['success' => false, 'error' => 'order_id obrigatorio']);
    }

    $stmt = $pdo->prepare("SELECT * FROM om_dispatch_ponto_apoio WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $dispatch = $stmt->fetch();

    if (!$dispatch) {
        jsonOut(['success' => false, 'error' => 'Dispatch nao encontrado']);
    }

    // Cancelar no BoraUm
    if ($dispatch['etapa1_boraum_id']) {
        callBoraUm('cancelar', ['pedido_id' => $dispatch['etapa1_boraum_id'], 'motivo' => $motivo]);
    }
    if ($dispatch['etapa2_boraum_id']) {
        callBoraUm('cancelar', ['pedido_id' => $dispatch['etapa2_boraum_id'], 'motivo' => $motivo]);
    }

    // Atualizar dispatch
    $pdo->prepare("
        UPDATE om_dispatch_ponto_apoio
        SET etapa1_status = 'cancelado', etapa2_status = 'cancelado'
        WHERE id = ?
    ")->execute([$dispatch['id']]);

    // Atualizar pacote
    $pdo->prepare("UPDATE om_ponto_apoio_pacotes SET status = 'problema' WHERE id = ?")->execute([$dispatch['pacote_id']]);

    jsonOut(['success' => true, 'message' => 'Dispatch cancelado']);
}

// ═══════════════════════════════════════════════════════════════════════════════
// MOTORISTAS: Ver motoristas disponiveis
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'motoristas') {
    $lat = (float)($input['lat'] ?? $_GET['lat'] ?? 0);
    $lng = (float)($input['lng'] ?? $_GET['lng'] ?? 0);

    if (!$lat || !$lng) {
        jsonOut(['success' => false, 'error' => 'lat e lng obrigatorios']);
    }

    $result = callBoraUm('motoristas_disponiveis', ['lat' => $lat, 'lng' => $lng]);

    jsonOut([
        'success' => $result['ok'] ?? false,
        'motoristas' => $result['motoristas'] ?? [],
        'total' => $result['total'] ?? 0
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// LISTAR: Listar dispatches
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'listar') {
    $seller_id = (int)($_GET['seller_id'] ?? 0);
    $status = $_GET['status'] ?? '';
    $limit = min(50, (int)($_GET['limit'] ?? 20));

    $where = [];
    $params = [];

    if ($seller_id) {
        $where[] = "d.seller_id = ?";
        $params[] = $seller_id;
    }
    if ($status) {
        $where[] = "(d.etapa1_status = ? OR d.etapa2_status = ?)";
        $params[] = $status;
        $params[] = $status;
    }

    $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("
        SELECT d.*,
               pa.nome as ponto_nome,
               v.nome_loja as vendedor_nome,
               CONCAT(c.firstname, ' ', c.lastname) as cliente_nome
        FROM om_dispatch_ponto_apoio d
        LEFT JOIN om_pontos_apoio pa ON pa.id = d.ponto_apoio_id
        LEFT JOIN om_vendedores v ON v.vendedor_id = d.seller_id
        LEFT JOIN oc_customer c ON c.customer_id = d.customer_id
        $whereClause
        ORDER BY d.created_at DESC
        LIMIT $limit
    ");
    $stmt->execute($params);
    $dispatches = $stmt->fetchAll();

    jsonOut([
        'success' => true,
        'dispatches' => $dispatches,
        'total' => count($dispatches)
    ]);
}

// Acao invalida
jsonOut([
    'success' => false,
    'error' => 'Acao invalida',
    'actions' => ['criar', 'dispatch_etapa1', 'dispatch_etapa2', 'status', 'webhook', 'cancelar', 'motoristas', 'listar']
]);
