<?php
/**
 * ══════════════════════════════════════════════════════════════
 * 🚀 ONEMUNDO - API DE ENTREGAS V2
 * GPS + Rotas + Tempo Estimado
 * ══════════════════════════════════════════════════════════════
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Conexao segura via config central
require_once dirname(__DIR__) . '/config/database.php';
$pdo = getPDO();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ══════════════════════════════════════════════════════════════
// GPS - Atualizar localização do delivery (30 segundos)
// ══════════════════════════════════════════════════════════════
if ($action === 'update_location') {
    $delivery_id = intval($_POST['delivery_id'] ?? 0);
    $latitude = floatval($_POST['latitude'] ?? 0);
    $longitude = floatval($_POST['longitude'] ?? 0);
    $accuracy = floatval($_POST['accuracy'] ?? 0);
    $speed = floatval($_POST['speed'] ?? 0);
    $heading = floatval($_POST['heading'] ?? 0);
    
    if (!$delivery_id || !$latitude || !$longitude) {
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
        exit;
    }
    
    // Buscar rota ativa
    $stmt = $pdo->prepare("SELECT route_id FROM om_delivery_routes WHERE delivery_id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$delivery_id]);
    $route = $stmt->fetch(PDO::FETCH_ASSOC);
    $route_id = $route['route_id'] ?? null;
    
    // Inserir localização
    $stmt = $pdo->prepare("INSERT INTO om_delivery_locations (delivery_id, route_id, latitude, longitude, accuracy, speed, heading) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$delivery_id, $route_id, $latitude, $longitude, $accuracy, $speed, $heading]);
    
    // Atualizar delivery
    $stmt = $pdo->prepare("UPDATE om_market_deliveries SET current_latitude = ?, current_longitude = ?, last_location_update = NOW() WHERE delivery_id = ?");
    $stmt->execute([$latitude, $longitude, $delivery_id]);
    
    // Se tem rota ativa, recalcular tempos estimados
    if ($route_id) {
        recalcularTemposEstimados($pdo, $route_id, $latitude, $longitude);
    }
    
    echo json_encode(['success' => true]);
    exit;
}

// ══════════════════════════════════════════════════════════════
// CRIAR ROTA - Organiza pedidos por proximidade
// ══════════════════════════════════════════════════════════════
if ($action === 'create_route') {
    $delivery_id = intval($_POST['delivery_id'] ?? 0);
    $order_ids = $_POST['order_ids'] ?? []; // Array de IDs
    
    if (!$delivery_id || empty($order_ids)) {
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
        exit;
    }
    
    // Limitar a 5 entregas
    $order_ids = array_slice($order_ids, 0, 5);
    
    // Buscar localização atual do delivery
    $stmt = $pdo->prepare("SELECT current_latitude, current_longitude FROM om_market_deliveries WHERE delivery_id = ?");
    $stmt->execute([$delivery_id]);
    $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $start_lat = $delivery['current_latitude'] ?? -23.5505; // SP default
    $start_lon = $delivery['current_longitude'] ?? -46.6333;
    
    // Buscar pedidos com coordenadas
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT o.order_id, o.customer_name, o.customer_phone, o.delivery_code,
               o.shipping_address, o.shipping_latitude, o.shipping_longitude,
               p.partner_id
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.order_id IN ($placeholders)
    ");
    $stmt->execute($order_ids);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ordenar por proximidade (algoritmo guloso)
    $ordered = [];
    $current_lat = $start_lat;
    $current_lon = $start_lon;
    
    while (count($orders) > 0) {
        $nearest_idx = 0;
        $nearest_dist = PHP_FLOAT_MAX;
        
        foreach ($orders as $idx => $order) {
            $dist = calcularDistancia(
                $current_lat, $current_lon,
                $order['shipping_latitude'] ?? $start_lat,
                $order['shipping_longitude'] ?? $start_lon
            );
            
            if ($dist < $nearest_dist) {
                $nearest_dist = $dist;
                $nearest_idx = $idx;
            }
        }
        
        $ordered[] = $orders[$nearest_idx];
        $current_lat = $orders[$nearest_idx]['shipping_latitude'] ?? $current_lat;
        $current_lon = $orders[$nearest_idx]['shipping_longitude'] ?? $current_lon;
        array_splice($orders, $nearest_idx, 1);
    }
    
    // Criar rota
    $stmt = $pdo->prepare("INSERT INTO om_delivery_routes (delivery_id, partner_id, status, total_stops) VALUES (?, ?, 'pending', ?)");
    $stmt->execute([$delivery_id, $ordered[0]['partner_id'] ?? null, count($ordered)]);
    $route_id = $pdo->lastInsertId();
    
    // Inserir paradas
    $tempo_acumulado = 0;
    foreach ($ordered as $idx => $order) {
        $stop_order = $idx + 1;
        
        // Calcular tempo estimado (20 km/h média em cidade + 3 min por entrega)
        $dist = calcularDistancia(
            $idx === 0 ? $start_lat : ($ordered[$idx-1]['shipping_latitude'] ?? $start_lat),
            $idx === 0 ? $start_lon : ($ordered[$idx-1]['shipping_longitude'] ?? $start_lon),
            $order['shipping_latitude'] ?? $start_lat,
            $order['shipping_longitude'] ?? $start_lon
        );
        $tempo_viagem = ceil(($dist / 20) * 60); // minutos
        $tempo_acumulado += $tempo_viagem + 3; // +3 min por entrega
        
        $stmt = $pdo->prepare("
            INSERT INTO om_delivery_route_stops 
            (route_id, order_id, stop_order, customer_name, customer_phone, address, latitude, longitude, delivery_code, estimated_time)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $route_id,
            $order['order_id'],
            $stop_order,
            $order['customer_name'],
            $order['customer_phone'],
            $order['shipping_address'],
            $order['shipping_latitude'],
            $order['shipping_longitude'],
            $order['delivery_code'],
            $tempo_acumulado
        ]);
        
        // Atualizar pedido (usando prepared statement)
        $stmtUpdate = $pdo->prepare("UPDATE om_market_orders SET route_id = ?, stop_order = ?, estimated_delivery_time = ? WHERE order_id = ?");
        $stmtUpdate->execute([$route_id, $stop_order, $tempo_acumulado, $order['order_id']]);
    }
    
    echo json_encode([
        'success' => true,
        'route_id' => $route_id,
        'total_stops' => count($ordered),
        'stops' => $ordered
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════
// INICIAR ROTA
// ══════════════════════════════════════════════════════════════
if ($action === 'start_route') {
    $route_id = intval($_POST['route_id'] ?? 0);

    // Usando prepared statements para seguranca
    $stmtRoute = $pdo->prepare("UPDATE om_delivery_routes SET status = 'active', started_at = NOW() WHERE route_id = ?");
    $stmtRoute->execute([$route_id]);

    // Marcar primeira parada como atual
    $stmtStop = $pdo->prepare("UPDATE om_delivery_route_stops SET status = 'current' WHERE route_id = ? AND stop_order = 1");
    $stmtStop->execute([$route_id]);

    // Atualizar status dos pedidos
    $stmt = $pdo->prepare("SELECT order_id FROM om_delivery_route_stops WHERE route_id = ?");
    $stmt->execute([$route_id]);
    $stops = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtOrderStatus = $pdo->prepare("UPDATE om_market_orders SET status = 'delivering' WHERE order_id = ?");
    foreach ($stops as $stop) {
        $stmtOrderStatus->execute([$stop['order_id']]);
        
        // Enviar mensagem automática
        enviarMensagemAutomatica($pdo, $stop['order_id'], 'delivery_started');
    }
    
    echo json_encode(['success' => true]);
    exit;
}

// ══════════════════════════════════════════════════════════════
// VALIDAR CÓDIGO E CONFIRMAR ENTREGA
// ══════════════════════════════════════════════════════════════
if ($action === 'validate_delivery') {
    $route_id = intval($_POST['route_id'] ?? 0);
    $order_id = intval($_POST['order_id'] ?? 0);
    $code = strtoupper(trim($_POST['code'] ?? ''));
    
    // Buscar código correto
    $stmt = $pdo->prepare("SELECT delivery_code FROM om_delivery_route_stops WHERE route_id = ? AND order_id = ?");
    $stmt->execute([$route_id, $order_id]);
    $stop = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$stop) {
        echo json_encode(['success' => false, 'error' => 'Parada não encontrada']);
        exit;
    }
    
    if ($stop['delivery_code'] !== $code) {
        echo json_encode(['success' => false, 'error' => 'Código incorreto']);
        exit;
    }
    
    // Confirmar entrega (usando prepared statements)
    $stmtConfirm = $pdo->prepare("UPDATE om_delivery_route_stops SET status = 'completed', delivered_at = NOW() WHERE route_id = ? AND order_id = ?");
    $stmtConfirm->execute([$route_id, $order_id]);

    // Atualizar pedido
    $chat_expires = date('Y-m-d H:i:s', strtotime('+60 minutes'));
    $stmtOrder = $pdo->prepare("UPDATE om_market_orders SET status = 'delivered', delivered_at = NOW() WHERE order_id = ?");
    $stmtOrder->execute([$order_id]);
    $stmtAssign = $pdo->prepare("UPDATE om_order_assignments SET status = 'completed', delivered_at = NOW(), chat_expires_at = ? WHERE order_id = ?");
    $stmtAssign->execute([$chat_expires, $order_id]);

    // Atualizar rota
    $stmtRouteUp = $pdo->prepare("UPDATE om_delivery_routes SET completed_stops = completed_stops + 1 WHERE route_id = ?");
    $stmtRouteUp->execute([$route_id]);

    // Verificar se tem proxima parada
    $stmt = $pdo->prepare("SELECT * FROM om_delivery_route_stops WHERE route_id = ? AND status = 'pending' ORDER BY stop_order ASC LIMIT 1");
    $stmt->execute([$route_id]);
    $next_stop = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($next_stop) {
        // Marcar proxima como atual
        $stmtNext = $pdo->prepare("UPDATE om_delivery_route_stops SET status = 'current' WHERE stop_id = ?");
        $stmtNext->execute([$next_stop['stop_id']]);

        // Avisar proximo cliente
        enviarMensagemAutomatica($pdo, $next_stop['order_id'], 'delivery_arriving');
    } else {
        // Rota finalizada
        $stmtComplete = $pdo->prepare("UPDATE om_delivery_routes SET status = 'completed', completed_at = NOW() WHERE route_id = ?");
        $stmtComplete->execute([$route_id]);
    }
    
    // Enviar mensagem de entrega concluída
    enviarMensagemAutomatica($pdo, $order_id, 'delivery_completed');
    
    echo json_encode([
        'success' => true,
        'message' => 'Entrega confirmada!',
        'next_stop' => $next_stop
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════
// BUSCAR STATUS DO PEDIDO PARA CLIENTE
// ══════════════════════════════════════════════════════════════
if ($action === 'customer_status') {
    $order_id = intval($_GET['order_id'] ?? 0);
    
    $stmt = $pdo->prepare("
        SELECT 
            o.order_id, o.status, o.delivery_code, o.stop_order, o.estimated_delivery_time,
            o.customer_name,
            s.name as shopper_name, s.avatar as shopper_avatar,
            d.name as delivery_name,
            r.total_stops, r.completed_stops, r.status as route_status,
            dl.current_latitude as delivery_lat, dl.current_longitude as delivery_lon
        FROM om_market_orders o
        LEFT JOIN om_order_assignments a ON o.order_id = a.order_id
        LEFT JOIN om_order_shoppers s ON a.shopper_id = s.shopper_id
        LEFT JOIN om_delivery_routes r ON o.route_id = r.route_id
        LEFT JOIN om_market_deliveries dl ON r.delivery_id = dl.delivery_id
        LEFT JOIN om_market_deliveries d ON r.delivery_id = d.delivery_id
        WHERE o.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
        exit;
    }
    
    // Calcular posição na fila
    $posicao_fila = null;
    $tempo_estimado = null;
    
    if ($order['route_status'] === 'active' && $order['stop_order']) {
        $posicao_fila = $order['stop_order'] - $order['completed_stops'];
        if ($posicao_fila < 1) $posicao_fila = 1;
        
        // Recalcular tempo se tiver GPS
        if ($order['delivery_lat'] && $order['delivery_lon']) {
            $stmt = $pdo->prepare("SELECT latitude, longitude FROM om_delivery_route_stops WHERE route_id = (SELECT route_id FROM om_market_orders WHERE order_id = ?) AND order_id = ?");
            $stmt->execute([$order_id, $order_id]);
            $stop = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($stop && $stop['latitude']) {
                $dist = calcularDistancia($order['delivery_lat'], $order['delivery_lon'], $stop['latitude'], $stop['longitude']);
                $tempo_estimado = ceil(($dist / 20) * 60) + (($posicao_fila - 1) * 5); // tempo viagem + 5 min por entrega anterior
            }
        }
        
        if (!$tempo_estimado) {
            $tempo_estimado = $order['estimated_delivery_time'];
        }
    }
    
    echo json_encode([
        'success' => true,
        'order' => [
            'order_id' => $order['order_id'],
            'status' => $order['status'],
            'delivery_code' => $order['delivery_code'],
            'shopper_name' => $order['shopper_name'],
            'shopper_avatar' => $order['shopper_avatar'],
            'delivery_name' => $order['delivery_name'],
            'posicao_fila' => $posicao_fila,
            'tempo_estimado' => $tempo_estimado,
            'total_entregas' => $order['total_stops'],
            'entregas_feitas' => $order['completed_stops']
        ]
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════
// FUNÇÕES AUXILIARES
// ══════════════════════════════════════════════════════════════

function calcularDistancia($lat1, $lon1, $lat2, $lon2) {
    $R = 6371; // km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}

function recalcularTemposEstimados($pdo, $route_id, $delivery_lat, $delivery_lon) {
    // Buscar paradas pendentes
    $stmt = $pdo->prepare("
        SELECT stop_id, order_id, latitude, longitude, stop_order 
        FROM om_delivery_route_stops 
        WHERE route_id = ? AND status IN ('pending', 'current')
        ORDER BY stop_order ASC
    ");
    $stmt->execute([$route_id]);
    $stops = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $current_lat = $delivery_lat;
    $current_lon = $delivery_lon;
    $tempo_acumulado = 0;
    
    foreach ($stops as $stop) {
        $dist = calcularDistancia($current_lat, $current_lon, $stop['latitude'] ?? $current_lat, $stop['longitude'] ?? $current_lon);
        $tempo_viagem = ceil(($dist / 20) * 60);
        $tempo_acumulado += $tempo_viagem + 3;
        
        $pdo->exec("UPDATE om_delivery_route_stops SET estimated_time = $tempo_acumulado WHERE stop_id = {$stop['stop_id']}");
        $pdo->exec("UPDATE om_market_orders SET estimated_delivery_time = $tempo_acumulado WHERE order_id = {$stop['order_id']}");
        
        $current_lat = $stop['latitude'] ?? $current_lat;
        $current_lon = $stop['longitude'] ?? $current_lon;
    }
}

function enviarMensagemAutomatica($pdo, $order_id, $trigger_event) {
    // Buscar template
    $stmt = $pdo->prepare("SELECT * FROM om_auto_messages WHERE trigger_event = ? AND is_active = 1");
    $stmt->execute([$trigger_event]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) return;
    
    // Buscar dados do pedido
    $stmt = $pdo->prepare("
        SELECT o.*, s.name as shopper_name, d.name as delivery_name, 
               rs.stop_order, rs.estimated_time,
               (SELECT COUNT(*) FROM om_delivery_route_stops WHERE route_id = o.route_id AND stop_order < rs.stop_order AND status != 'completed') + 1 as posicao_fila
        FROM om_market_orders o
        LEFT JOIN om_order_assignments a ON o.order_id = a.order_id
        LEFT JOIN om_order_shoppers s ON a.shopper_id = s.shopper_id
        LEFT JOIN om_delivery_routes r ON o.route_id = r.route_id
        LEFT JOIN om_market_deliveries d ON r.delivery_id = d.delivery_id
        LEFT JOIN om_delivery_route_stops rs ON o.order_id = rs.order_id
        WHERE o.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) return;
    
    // Substituir variáveis
    $mensagem = $template['message_template'];
    $mensagem = str_replace('{cliente_nome}', $order['customer_name'] ?? 'Cliente', $mensagem);
    $mensagem = str_replace('{shopper_nome}', $order['shopper_name'] ?? 'Shopper', $mensagem);
    $mensagem = str_replace('{delivery_nome}', $order['delivery_name'] ?? 'Entregador', $mensagem);
    $mensagem = str_replace('{codigo_entrega}', $order['delivery_code'] ?? '', $mensagem);
    $mensagem = str_replace('{posicao_fila}', $order['posicao_fila'] ?? '1', $mensagem);
    $mensagem = str_replace('{tempo_estimado}', $order['estimated_time'] ?? $order['estimated_delivery_time'] ?? '20', $mensagem);
    
    // Inserir mensagem no chat
    $sender_type = $template['sender_type'];
    $sender_name = $sender_type === 'shopper' ? ($order['shopper_name'] ?? 'Shopper') : 
                   ($sender_type === 'delivery' ? ($order['delivery_name'] ?? 'Entregador') : 'Sistema');
    
    $stmt = $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_name, message, message_type) VALUES (?, ?, ?, ?, 'text')");
    $stmt->execute([$order_id, $sender_type, $sender_name, $mensagem]);
}

// Resposta padrão
echo json_encode(['success' => false, 'error' => 'Ação não reconhecida']);
