<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * API: Simular Ganho (antes de aceitar)
 * GET /api/financeiro/simular-ganho.php?order_id=X&tipo=shopper
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Mostra quanto o shopper/motorista vai ganhar ANTES de aceitar
 * Usa mesma lógica do processar-entrega.php para consistência
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once dirname(__DIR__, 2) . '/database.php';
require_once dirname(__DIR__, 2) . '/includes/classes/OmComissao.php';

try {
    $pdo = getDB();
    om_comissao()->setDb($pdo);

    $order_id = intval($_GET['order_id'] ?? 0);
    $tipo = $_GET['tipo'] ?? 'shopper'; // 'motorista' ou 'shopper'

    if (!$order_id) {
        echo json_encode(['success' => false, 'error' => 'order_id obrigatório']);
        exit;
    }

    // Buscar pedido
    $stmt = $pdo->prepare("
        SELECT o.*,
            (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as items_count,
            p.name as partner_name
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
        exit;
    }

    // Calcular ganho usando classe unificada
    $ganho = om_comissao()->simularGanho($tipo, $pedido);

    // Calcular distância se tiver coordenadas
    $distancia_km = null;
    $tempo_min = null;

    if (!empty($pedido['shipping_lat']) && !empty($pedido['delivery_lat'])) {
        $distancia_km = calcularDistancia(
            $pedido['shipping_lat'], $pedido['shipping_lng'],
            $pedido['delivery_lat'] ?: $pedido['customer_lat'],
            $pedido['delivery_lng'] ?: $pedido['customer_lng']
        );
        // Tempo estimado (30km/h em cidade)
        $tempo_min = $distancia_km ? ceil(($distancia_km / 30) * 60) : null;
    }

    echo json_encode([
        'success' => true,
        'order_id' => $order_id,
        'tipo_worker' => $ganho['tipo'],
        'funcao' => $ganho['funcao'],
        'seu_ganho' => [
            'valor' => $ganho['valor'],
            'valor_formatado' => $ganho['valor_formatado'],
            'minimo_garantido' => $ganho['minimo_garantido'],
            'como_calculado' => $ganho['como_calculado']
        ],
        'pedido' => [
            'mercado' => $pedido['partner_name'] ?: 'Mercado OneMundo',
            'cliente' => $pedido['customer_name'],
            'itens' => $pedido['items_count'],
            'valor_produtos' => floatval($pedido['subtotal'] ?: ($pedido['total'] - ($pedido['delivery_fee'] ?? 0))),
            'valor_total' => floatval($pedido['total'])
        ],
        'entrega' => [
            'distancia_km' => $distancia_km ? round($distancia_km, 1) : null,
            'tempo_estimado_min' => $tempo_min,
            'origem' => $pedido['shipping_address'],
            'destino' => $pedido['delivery_address'] ?: $pedido['customer_address']
        ],
        'modelo' => $ganho['modelo']
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("[simular-ganho] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao simular ganho']);
}

function calcularDistancia($lat1, $lng1, $lat2, $lng2) {
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) * sin($dLng/2);
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}
