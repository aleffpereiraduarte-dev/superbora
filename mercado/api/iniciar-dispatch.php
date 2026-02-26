<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ONEMUNDO - INICIAR DISPATCH PARA PEDIDO
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Endpoint para iniciar o dispatch de um pedido.
 * Chamado apos a confirmacao do pedido.
 *
 * Fluxo:
 * 1. Recebe order_id
 * 2. Identifica vendedor(es) do pedido
 * 3. Identifica melhor ponto de apoio
 * 4. Cria dispatch e inicia etapa 1
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getPDO();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexao']);
    exit;
}

function jsonOut($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function callDispatchApi($action, $data) {
    $url = 'http://localhost/mercado/api/dispatch-ponto-apoio.php?action=' . $action;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true) ?? ['success' => false];
}

function calcularDistancia($lat1, $lng1, $lat2, $lng2) {
    if (!$lat1 || !$lng1 || !$lat2 || !$lng2) return null;

    $earthRadius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);

    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLng/2) * sin($dLng/2);

    $c = 2 * atan2(sqrt($a), sqrt(1-$a));

    return $earthRadius * $c;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$order_id = (int)($input['order_id'] ?? $_GET['order_id'] ?? 0);
$tipo_frete = $input['tipo_frete'] ?? 'ponto_apoio'; // ponto_apoio, retirada

if (!$order_id) {
    jsonOut(['success' => false, 'error' => 'order_id obrigatorio']);
}

// Verificar se e um pedido OpenCart ou Mercado
$is_opencart = false;
$order = null;

// Tentar buscar do OpenCart
$stmt = $pdo->prepare("
    SELECT o.*,
           c.customer_id, c.firstname, c.lastname, c.telephone,
           a.address_1 as shipping_address_1, a.city as shipping_city,
           a.postcode as shipping_postcode
    FROM oc_order o
    JOIN oc_customer c ON c.customer_id = o.customer_id
    LEFT JOIN oc_address a ON a.address_id = o.shipping_address_id
    WHERE o.order_id = ?
");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if ($order) {
    $is_opencart = true;
} else {
    // Tentar buscar do Mercado
    $stmt = $pdo->prepare("SELECT * FROM om_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
}

if (!$order) {
    jsonOut(['success' => false, 'error' => 'Pedido nao encontrado']);
}

// Se for retirada, nao precisa de dispatch
if ($tipo_frete === 'retirada') {
    jsonOut([
        'success' => true,
        'message' => 'Pedido para retirada - dispatch nao necessario',
        'tipo_frete' => 'retirada'
    ]);
}

// Buscar vendedores do pedido
$sellers = [];

if ($is_opencart) {
    // Pedido OpenCart - buscar via PurpleTree
    $stmt = $pdo->prepare("
        SELECT DISTINCT pvp.seller_id,
               v.vendedor_id, v.nome_loja, v.latitude, v.longitude, v.cidade
        FROM oc_order_product op
        JOIN oc_product p ON p.product_id = op.product_id
        JOIN oc_purpletree_vendor_products pvp ON pvp.product_id = p.product_id
        JOIN om_vendedores v ON v.opencart_seller_id = pvp.seller_id
        WHERE op.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $sellers = $stmt->fetchAll();
} else {
    // Pedido Mercado - verificar se tem seller_id
    if (!empty($order['seller_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM om_vendedores WHERE vendedor_id = ?");
        $stmt->execute([$order['seller_id']]);
        $seller = $stmt->fetch();
        if ($seller) {
            $sellers[] = $seller;
        }
    }
}

if (empty($sellers)) {
    jsonOut(['success' => false, 'error' => 'Nenhum vendedor encontrado para o pedido']);
}

// Endereco do cliente
$cliente_lat = $order['shipping_lat'] ?? 0;
$cliente_lng = $order['shipping_lng'] ?? 0;
$cliente_cidade = $order['shipping_city'] ?? '';

// Para cada vendedor, encontrar melhor ponto de apoio e criar dispatch
$dispatches = [];

foreach ($sellers as $seller) {
    $seller_id = $seller['vendedor_id'] ?? $seller['seller_id'];
    $seller_lat = $seller['latitude'] ?? 0;
    $seller_lng = $seller['longitude'] ?? 0;
    $seller_cidade = $seller['cidade'] ?? '';

    // Encontrar melhor ponto de apoio
    // Prioridade: 1) Ponto na mesma cidade do cliente, 2) Ponto mais proximo do vendedor
    $stmt = $pdo->prepare("
        SELECT *,
               (6371 * acos(cos(radians(?)) * cos(radians(latitude))
               * cos(radians(longitude) - radians(?))
               + sin(radians(?)) * sin(radians(latitude)))) AS distancia_vendedor
        FROM om_pontos_apoio
        WHERE status = 'ativo'
          AND aceita_coleta = 1
          AND aceita_despacho = 1
        ORDER BY
            CASE WHEN cidade = ? THEN 0 ELSE 1 END,
            distancia_vendedor ASC
        LIMIT 1
    ");
    $stmt->execute([$seller_lat, $seller_lng, $seller_lat, $cliente_cidade]);
    $ponto = $stmt->fetch();

    if (!$ponto) {
        // Tentar qualquer ponto ativo
        $ponto = $pdo->query("SELECT * FROM om_pontos_apoio WHERE status = 'ativo' LIMIT 1")->fetch();
    }

    if (!$ponto) {
        $dispatches[] = [
            'seller_id' => $seller_id,
            'success' => false,
            'error' => 'Nenhum ponto de apoio disponivel'
        ];
        continue;
    }

    // Calcular taxas
    $dist_vendedor_ponto = calcularDistancia($seller_lat, $seller_lng, $ponto['latitude'], $ponto['longitude']) ?? 5;
    $dist_ponto_cliente = calcularDistancia($ponto['latitude'], $ponto['longitude'], $cliente_lat, $cliente_lng) ?? 5;

    $preco_km = 2.50;
    $minimo = 8.00;

    $taxa_etapa1 = max($minimo, round($dist_vendedor_ponto * $preco_km, 2));
    $taxa_etapa2 = max($minimo, round($dist_ponto_cliente * $preco_km, 2));

    // Criar dispatch
    $result = callDispatchApi('criar', [
        'order_id' => $order_id,
        'seller_id' => $seller_id,
        'ponto_apoio_id' => $ponto['id']
    ]);

    if ($result['success'] ?? false) {
        // Atualizar taxas
        $pdo->prepare("
            UPDATE om_dispatch_ponto_apoio
            SET taxa_etapa1 = ?, taxa_etapa2 = ?, taxa_total = ?
            WHERE id = ?
        ")->execute([$taxa_etapa1, $taxa_etapa2, $taxa_etapa1 + $taxa_etapa2, $result['dispatch_id']]);

        // Iniciar etapa 1
        $dispatch_result = callDispatchApi('dispatch_etapa1', ['order_id' => $order_id]);

        $dispatches[] = [
            'seller_id' => $seller_id,
            'seller_nome' => $seller['nome_loja'] ?? '',
            'ponto_apoio_id' => $ponto['id'],
            'ponto_nome' => $ponto['nome'],
            'dispatch_id' => $result['dispatch_id'],
            'pacote_codigo' => $result['codigo_pacote'] ?? '',
            'taxa_etapa1' => $taxa_etapa1,
            'taxa_etapa2' => $taxa_etapa2,
            'success' => true,
            'etapa1_status' => $dispatch_result['success'] ? 'despachado' : 'erro'
        ];
    } else {
        $dispatches[] = [
            'seller_id' => $seller_id,
            'success' => false,
            'error' => $result['error'] ?? 'Erro ao criar dispatch'
        ];
    }
}

// Atualizar pedido com info de dispatch
$success_count = count(array_filter($dispatches, fn($d) => $d['success']));

jsonOut([
    'success' => $success_count > 0,
    'message' => "Dispatch iniciado para $success_count vendedor(es)",
    'order_id' => $order_id,
    'dispatches' => $dispatches
]);
