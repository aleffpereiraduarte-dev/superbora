<?php
/**
 * API BRIDGE: Mercado -> BoraUm
 * Integra o sistema de entregas do mercado com os motoristas do BoraUm
 *
 * Quando o Shopper finaliza a compra, este endpoint:
 * 1. Recebe os dados do pedido
 * 2. Chama a API do BoraUm para dispatch
 * 3. BoraUm oferece para motoristas em waves (3km, 5km, 10km)
 * 4. Retorna status da busca por motorista
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

// Config do mercado
require_once dirname(__DIR__) . '/config/database.php';
$pdo = getPDO();

// Funcao para chamar API do BoraUm
function callBoraUm($action, $data) {
    $url = 'http://localhost/boraum/api_entrega.php?action=' . $action;

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
    curl_close($ch);

    if ($response === false) {
        return ['ok' => 0, 'erro' => 'Falha na comunicacao com BoraUm'];
    }

    return json_decode($response, true) ?: ['ok' => 0, 'erro' => 'Resposta invalida'];
}

function jsonOut($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

// ═══════════════════════════════════════════════════════════════════════════════
// DISPATCH: Enviar pedido para BoraUm buscar motorista
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'dispatch') {
    $order_id = (int)($input['order_id'] ?? 0);

    if (!$order_id) {
        jsonOut(['success' => false, 'error' => 'order_id obrigatorio']);
    }

    // Buscar dados do pedido
    $stmt = $pdo->prepare("
        SELECT o.*,
               p.name as partner_name,
               p.address as partner_address,
               p.lat as partner_lat,
               p.lng as partner_lng,
               p.phone as partner_phone,
               c.firstname as customer_firstname,
               c.lastname as customer_lastname,
               c.telephone as customer_phone
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        LEFT JOIN oc_customer c ON o.customer_id = c.customer_id
        WHERE o.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        jsonOut(['success' => false, 'error' => 'Pedido nao encontrado']);
    }

    // Verificar se ja tem motorista
    if (!empty($order['delivery_id']) && $order['delivery_id'] > 0) {
        jsonOut(['success' => false, 'error' => 'Pedido ja tem motorista atribuido']);
    }

    // Buscar itens do pedido
    $stmt = $pdo->prepare("SELECT * FROM om_market_order_items WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Preparar dados para BoraUm
    $boraUmData = [
        'pedido_id' => 'MKT-' . $order_id,
        'loja_nome' => $order['partner_name'] ?? 'Mercado OneMundo',
        'loja_endereco' => $order['partner_address'] ?? '',
        'loja_lat' => (float)($order['partner_lat'] ?? -23.5505),
        'loja_lng' => (float)($order['partner_lng'] ?? -46.6333),
        'cliente_nome' => trim(($order['customer_firstname'] ?? '') . ' ' . ($order['customer_lastname'] ?? '')),
        'cliente_telefone' => $order['customer_phone'] ?? $order['telephone'] ?? '',
        'cliente_endereco' => $order['shipping_address'] ?? $order['delivery_address'] ?? '',
        'cliente_lat' => (float)($order['delivery_lat'] ?? $order['shipping_lat'] ?? -23.5510),
        'cliente_lng' => (float)($order['delivery_lng'] ?? $order['shipping_lng'] ?? -46.6340),
        'itens' => array_map(function($item) {
            return [
                'nome' => $item['name'] ?? $item['product_name'] ?? 'Item',
                'quantidade' => $item['quantity'] ?? 1,
                'preco' => $item['price'] ?? 0
            ];
        }, $items),
        'valor_pedido' => (float)($order['total'] ?? 0),
        'taxa_entrega' => (float)($order['delivery_fee'] ?? $order['shipping_fee'] ?? 8),
        'forma_pagamento' => $order['payment_method'] ?? 'cartao'
    ];

    // Chamar BoraUm
    $result = callBoraUm('novo_pedido', $boraUmData);

    if ($result['ok'] ?? false) {
        // Atualizar pedido com referencia do BoraUm
        $boraum_id = $result['pedido_id'] ?? 0;
        $stmt = $pdo->prepare("UPDATE om_market_orders SET
            boraum_pedido_id = ?,
            status = 'awaiting_delivery',
            delivery_dispatched_at = NOW()
            WHERE order_id = ?");
        $stmt->execute([$boraum_id, $order_id]);

        jsonOut([
            'success' => true,
            'message' => 'Pedido enviado para motoristas',
            'boraum_id' => $boraum_id,
            'dispatch' => $result['dispatch'] ?? null
        ]);
    } else {
        jsonOut([
            'success' => false,
            'error' => $result['erro'] ?? 'Erro ao enviar para BoraUm'
        ]);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// STATUS: Verificar status do dispatch no BoraUm
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'status') {
    $order_id = (int)($input['order_id'] ?? $_GET['order_id'] ?? 0);

    if (!$order_id) {
        jsonOut(['success' => false, 'error' => 'order_id obrigatorio']);
    }

    // Buscar pedido
    $stmt = $pdo->prepare("SELECT boraum_pedido_id, status, delivery_id FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order || !$order['boraum_pedido_id']) {
        jsonOut(['success' => false, 'error' => 'Pedido nao encontrado ou nao enviado ao BoraUm']);
    }

    // Consultar BoraUm
    $result = callBoraUm('status', ['pedido_id' => $order['boraum_pedido_id']]);

    if ($result['ok'] ?? false) {
        $pedido = $result['pedido'] ?? [];

        // Sincronizar dados do motorista se aceito
        if (($pedido['status'] ?? '') === 'aceito' && !empty($pedido['motorista_id'])) {
            // Buscar ou criar motorista no mercado
            $stmt = $pdo->prepare("SELECT delivery_id FROM om_market_deliveries WHERE boraum_driver_id = ?");
            $stmt->execute([$pedido['motorista_id']]);
            $delivery = $stmt->fetch(PDO::FETCH_ASSOC);

            $delivery_id = $delivery['delivery_id'] ?? null;

            if (!$delivery_id) {
                // Criar registro do motorista
                $stmt = $pdo->prepare("INSERT INTO om_market_deliveries
                    (boraum_driver_id, name, phone, vehicle_type, vehicle_plate, rating, status, created_at)
                    VALUES (?, ?, ?, 'moto', ?, ?, 'active', NOW())");
                $stmt->execute([
                    $pedido['motorista_id'],
                    $pedido['motorista_nome'] ?? 'Motorista BoraUm',
                    $pedido['motorista_telefone'] ?? '',
                    $pedido['veiculo_placa'] ?? '',
                    $pedido['motorista_nota'] ?? 5.0
                ]);
                $delivery_id = $pdo->lastInsertId();
            }

            // Atualizar pedido com motorista
            $stmt = $pdo->prepare("UPDATE om_market_orders SET
                delivery_id = ?,
                delivery_name = ?,
                delivery_phone = ?,
                status = 'delivering',
                delivery_accepted_at = NOW()
                WHERE order_id = ?");
            $stmt->execute([
                $delivery_id,
                $pedido['motorista_nome'] ?? 'Motorista',
                $pedido['motorista_telefone'] ?? '',
                $order_id
            ]);
        }

        jsonOut([
            'success' => true,
            'status' => $pedido['status'] ?? 'desconhecido',
            'motorista' => [
                'nome' => $pedido['motorista_nome'] ?? null,
                'telefone' => $pedido['motorista_telefone'] ?? null,
                'veiculo' => ($pedido['veiculo_marca'] ?? '') . ' ' . ($pedido['veiculo_modelo'] ?? ''),
                'placa' => $pedido['veiculo_placa'] ?? null,
                'nota' => $pedido['motorista_nota'] ?? null,
                'lat' => $pedido['motorista_lat'] ?? null,
                'lng' => $pedido['motorista_lng'] ?? null
            ]
        ]);
    } else {
        jsonOut(['success' => false, 'error' => $result['erro'] ?? 'Erro ao consultar BoraUm']);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// RETRY: Reenviar dispatch se nenhum motorista aceitou
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'retry') {
    $order_id = (int)($input['order_id'] ?? 0);

    if (!$order_id) {
        jsonOut(['success' => false, 'error' => 'order_id obrigatorio']);
    }

    $stmt = $pdo->prepare("SELECT boraum_pedido_id FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order || !$order['boraum_pedido_id']) {
        jsonOut(['success' => false, 'error' => 'Pedido nao encontrado']);
    }

    $result = callBoraUm('retry_dispatch', ['pedido_id' => $order['boraum_pedido_id']]);

    jsonOut([
        'success' => $result['ok'] ?? false,
        'dispatch' => $result['dispatch'] ?? null,
        'error' => $result['erro'] ?? null
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// MOTORISTAS: Ver motoristas disponiveis na regiao
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'motoristas') {
    $lat = (float)($input['lat'] ?? $_GET['lat'] ?? 0);
    $lng = (float)($input['lng'] ?? $_GET['lng'] ?? 0);

    if (!$lat || !$lng) {
        jsonOut(['success' => false, 'error' => 'Coordenadas obrigatorias']);
    }

    $result = callBoraUm('motoristas_disponiveis', ['lat' => $lat, 'lng' => $lng]);

    jsonOut([
        'success' => $result['ok'] ?? false,
        'motoristas' => $result['motoristas'] ?? [],
        'total' => $result['total'] ?? 0
    ]);
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

    $stmt = $pdo->prepare("SELECT boraum_pedido_id FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($order && $order['boraum_pedido_id']) {
        callBoraUm('cancelar', ['pedido_id' => $order['boraum_pedido_id'], 'motivo' => $motivo]);
    }

    // Atualizar status no mercado
    $stmt = $pdo->prepare("UPDATE om_market_orders SET delivery_id = NULL, status = 'ready' WHERE order_id = ?");
    $stmt->execute([$order_id]);

    jsonOut(['success' => true, 'message' => 'Dispatch cancelado']);
}

// Acao invalida
jsonOut(['success' => false, 'error' => 'Acao invalida. Use: dispatch, status, retry, motoristas, cancelar']);
