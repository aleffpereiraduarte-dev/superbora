<?php
/**
 * API: Consultar ganhos do entregador
 * GET /mercado/api/entregador/ganhos.php?driver_id=X
 */
require_once __DIR__ . '/config.php';

$driver_id = (int)($_GET['driver_id'] ?? $_POST['driver_id'] ?? 0);
$periodo = $_GET['periodo'] ?? 'hoje'; // hoje, semana, mes, total

if (!$driver_id) {
    jsonResponse(['success' => false, 'error' => 'driver_id obrigatorio'], 400);
}

$pdo = getDB();

// Verificar driver
$driver = validateDriver($driver_id);
if (!$driver) {
    jsonResponse(['success' => false, 'error' => 'Motorista nao encontrado'], 404);
}

// Determinar filtro de data
$dateFilter = '';
switch ($periodo) {
    case 'hoje':
        $dateFilter = "AND DATE(delivered_at) = CURRENT_DATE";
        break;
    case 'semana':
        $dateFilter = "AND delivered_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)";
        break;
    case 'mes':
        $dateFilter = "AND delivered_at >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)";
        break;
    case 'total':
    default:
        $dateFilter = "";
}

// Buscar entregas do motorista no mercado
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_entregas,
        COALESCE(SUM(delivery_fee), 0) as total_ganho,
        COALESCE(AVG(delivery_fee), 0) as media_por_entrega
    FROM om_market_orders
    WHERE delivery_driver_id = ?
    AND status = 'delivered'
    $dateFilter
");
$stmt->execute([$driver_id]);
$stats = $stmt->fetch();

// Buscar ultimas entregas
$stmt = $pdo->prepare("
    SELECT order_id, total, delivery_fee, delivered_at, shipping_city
    FROM om_market_orders
    WHERE delivery_driver_id = ?
    AND status = 'delivered'
    ORDER BY delivered_at DESC
    LIMIT 20
");
$stmt->execute([$driver_id]);
$entregas = $stmt->fetchAll();

// Buscar corridas do BoraUm tambem
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_corridas,
        COALESCE(SUM(COALESCE(price_final, price_estimated) * 0.8), 0) as total_ganho_boraum
    FROM om_boraum_rides
    WHERE driver_id = ?
    AND status = 'completed'
    " . str_replace('delivered_at', 'completed_at', $dateFilter) . "
");
$stmt->execute([$driver_id]);
$boraum_stats = $stmt->fetch();

jsonResponse([
    'success' => true,
    'driver' => [
        'id' => $driver_id,
        'name' => $driver['name'],
        'balance' => (float)$driver['balance'],
        'total_earnings' => (float)$driver['total_earnings'],
        'rating' => (float)$driver['rating']
    ],
    'periodo' => $periodo,
    'mercado' => [
        'entregas' => (int)$stats['total_entregas'],
        'ganho' => round((float)$stats['total_ganho'], 2),
        'media' => round((float)$stats['media_por_entrega'], 2)
    ],
    'boraum' => [
        'corridas' => (int)$boraum_stats['total_corridas'],
        'ganho' => round((float)$boraum_stats['total_ganho_boraum'], 2)
    ],
    'total_periodo' => round((float)$stats['total_ganho'] + (float)$boraum_stats['total_ganho_boraum'], 2),
    'ultimas_entregas' => array_map(function($e) {
        return [
            'order_id' => $e['order_id'],
            'valor_pedido' => (float)$e['total'],
            'ganho' => (float)$e['delivery_fee'],
            'data' => $e['delivered_at'],
            'cidade' => $e['shipping_city']
        ];
    }, $entregas)
]);
