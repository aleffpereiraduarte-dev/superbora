<?php
/**
 * GET /api/mercado/boraum/pedidos.php
 * Listar pedidos do passageiro BoraUm
 *
 * GET (sem params)      - Lista pedidos paginada
 * GET ?id=X             - Detalhe completo de um pedido
 * GET ?page=1&limit=15  - Paginacao
 * GET ?status=active     - Filtrar por ativos (pending,aceito,preparando,pronto,saiu_entrega,em_entrega)
 * GET ?status=completed  - Filtrar por finalizados (entregue,delivered,cancelado)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';

setCorsHeaders();

try {
    $db = getDB();
    $user = requirePassageiro($db);

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $customerId = $user['customer_id'];
    $orderId = isset($_GET['id']) ? (int)$_GET['id'] : null;

    // =================== DETALHE DE UM PEDIDO ===================
    if ($orderId) {
        $stmt = $db->prepare("
            SELECT o.*,
                   p.name as partner_name_full, p.trade_name, p.logo as partner_logo,
                   p.phone as partner_phone, p.categoria as partner_categoria,
                   p.lat as partner_lat, p.lng as partner_lng
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.order_id = ? AND o.customer_id = ?
        ");
        $stmt->execute([$orderId, $customerId]);
        $order = $stmt->fetch();

        if (!$order) {
            response(false, null, "Pedido nao encontrado", 404);
        }

        // Fetch items
        $stmtItems = $db->prepare("
            SELECT product_id, product_name, quantity, price, total, product_image, notes
            FROM om_market_order_items
            WHERE order_id = ?
        ");
        $stmtItems->execute([$orderId]);
        $items = $stmtItems->fetchAll();

        // Fetch timeline events
        $events = [];
        try {
            $stmtEvents = $db->prepare("
                SELECT event_type, message, created_at
                FROM om_market_order_events
                WHERE order_id = ?
                ORDER BY created_at ASC
            ");
            $stmtEvents->execute([$orderId]);
            $events = $stmtEvents->fetchAll();
        } catch (Exception $e) {
            // Table may not exist
        }

        // Fetch shopper info
        $shopper = null;
        if (!empty($order['shopper_id'])) {
            try {
                $stmtShopper = $db->prepare("SELECT shopper_id, nome, telefone, foto, avaliacao_media FROM om_market_shoppers WHERE shopper_id = ?");
                $stmtShopper->execute([(int)$order['shopper_id']]);
                $s = $stmtShopper->fetch();
                if ($s) {
                    $shopper = [
                        'id' => (int)$s['shopper_id'],
                        'nome' => $s['nome'],
                        'telefone' => $s['telefone'],
                        'foto' => $s['foto'],
                        'avaliacao' => $s['avaliacao_media'] ? (float)$s['avaliacao_media'] : null,
                    ];
                }
            } catch (Exception $e) {}
        }

        // Fetch driver info
        $driver = null;
        if (!empty($order['motorista_id'])) {
            try {
                $stmtDriver = $db->prepare("SELECT id, nome, telefone, foto, veiculo_tipo, veiculo_placa FROM om_motoristas WHERE id = ?");
                $stmtDriver->execute([(int)$order['motorista_id']]);
                $d = $stmtDriver->fetch();
                if ($d) {
                    $driver = [
                        'id' => (int)$d['id'],
                        'nome' => $d['nome'],
                        'telefone' => $d['telefone'],
                        'foto' => $d['foto'],
                        'veiculo_tipo' => $d['veiculo_tipo'] ?? null,
                        'veiculo_placa' => $d['veiculo_placa'] ? substr($d['veiculo_placa'], 0, 4) . '***' : null,
                    ];
                }
            } catch (Exception $e) {}
        }

        $statusLabels = getStatusLabels();

        $detail = [
            'id' => (int)$order['order_id'],
            'order_number' => $order['order_number'],
            'status' => $order['status'],
            'status_label' => $statusLabels[$order['status']] ?? $order['status'],
            'partner' => [
                'id' => (int)$order['partner_id'],
                'nome' => $order['trade_name'] ?: $order['partner_name_full'] ?: $order['partner_name'],
                'logo' => $order['partner_logo'] ?? null,
                'categoria' => $order['partner_categoria'] ?? $order['partner_categoria'] ?? null,
                'telefone' => $order['partner_phone'] ?? null,
                'lat' => $order['partner_lat'] ? (float)$order['partner_lat'] : null,
                'lng' => $order['partner_lng'] ? (float)$order['partner_lng'] : null,
            ],
            'items' => array_map(function($i) {
                return [
                    'product_id' => (int)$i['product_id'],
                    'nome' => $i['product_name'],
                    'quantidade' => (int)$i['quantity'],
                    'preco' => (float)$i['price'],
                    'total' => (float)$i['total'],
                    'imagem' => $i['product_image'] ?? null,
                    'notas' => $i['notes'] ?? null,
                ];
            }, $items),
            'subtotal' => (float)($order['subtotal'] ?? 0),
            'taxa_entrega' => (float)($order['delivery_fee'] ?? 0),
            'taxa_servico' => (float)($order['service_fee'] ?? 0),
            'gorjeta' => (float)($order['tip_amount'] ?? 0),
            'desconto' => (float)($order['discount'] ?? 0) + (float)($order['coupon_discount'] ?? 0),
            'total' => (float)($order['total'] ?? 0),
            'pagamento' => $order['forma_pagamento'] ?? $order['payment_method'] ?? null,
            'endereco' => $order['delivery_address'] ?? $order['shipping_address'] ?? null,
            'notas' => $order['notes'] ?? null,
            'instrucoes_entrega' => $order['delivery_instructions'] ?? null,
            'contactless' => (bool)($order['contactless'] ?? false),
            'is_pickup' => (bool)($order['is_pickup'] ?? false),
            'codigo_entrega' => $order['codigo_entrega'] ?? $order['delivery_code'] ?? null,
            'is_scheduled' => (bool)($order['is_scheduled'] ?? false),
            'schedule_date' => $order['schedule_date'] ?? null,
            'schedule_time' => $order['schedule_time'] ?? null,
            'shopper' => $shopper,
            'driver' => $driver,
            'timeline' => array_map(function($e) {
                return [
                    'evento' => $e['event_type'],
                    'mensagem' => $e['message'],
                    'data' => $e['created_at'],
                ];
            }, $events),
            'created_at' => $order['created_at'] ?? $order['date_added'],
            'accepted_at' => $order['accepted_at'] ?? null,
            'preparing_at' => $order['preparing_at'] ?? null,
            'ready_at' => $order['ready_at'] ?? null,
            'delivering_at' => $order['delivering_at'] ?? null,
            'delivered_at' => $order['delivered_at'] ?? null,
            'cancelled_at' => $order['cancelled_at'] ?? null,
            'cancel_reason' => $order['cancel_reason'] ?? $order['cancellation_reason'] ?? null,
        ];

        response(true, ['pedido' => $detail]);
    }

    // =================== LISTA DE PEDIDOS ===================
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 15)));
    $offset = ($page - 1) * $limit;
    $statusFilter = $_GET['status'] ?? null;

    $where = "o.customer_id = ?";
    $params = [$customerId];

    // Only show BoraUm orders
    $where .= " AND (o.source = 'boraum' OR o.order_number LIKE 'BU-%')";

    if ($statusFilter === 'active') {
        $activeStatuses = ['pending', 'pendente', 'aceito', 'confirmed', 'confirmado', 'preparando', 'pronto', 'saiu_entrega', 'em_entrega'];
        $ph = implode(',', array_fill(0, count($activeStatuses), '?'));
        $where .= " AND o.status IN ($ph)";
        $params = array_merge($params, $activeStatuses);
    } elseif ($statusFilter === 'completed') {
        $completedStatuses = ['entregue', 'cancelado', 'cancelled'];
        $ph = implode(',', array_fill(0, count($completedStatuses), '?'));
        $where .= " AND o.status IN ($ph)";
        $params = array_merge($params, $completedStatuses);
    }

    // Count total
    $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_market_orders o WHERE $where");
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();

    // Fetch orders
    $stmt = $db->prepare("
        SELECT o.order_id, o.order_number, o.status, o.partner_id, o.partner_name,
               o.total, o.items_count, o.forma_pagamento, o.payment_method,
               o.is_pickup, o.is_scheduled, o.schedule_date, o.schedule_time,
               o.created_at, o.date_added, o.delivered_at, o.cancelled_at,
               p.logo as partner_logo, p.trade_name
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE $where
        ORDER BY o.order_id DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    $statusLabels = getStatusLabels();

    $pedidos = array_map(function($o) use ($statusLabels) {
        return [
            'id' => (int)$o['order_id'],
            'order_number' => $o['order_number'],
            'status' => $o['status'],
            'status_label' => $statusLabels[$o['status']] ?? $o['status'],
            'partner' => [
                'id' => (int)$o['partner_id'],
                'nome' => $o['trade_name'] ?: $o['partner_name'],
                'logo' => $o['partner_logo'] ?? null,
            ],
            'total' => (float)($o['total'] ?? 0),
            'items_count' => (int)($o['items_count'] ?? 0),
            'pagamento' => $o['forma_pagamento'] ?? $o['payment_method'] ?? null,
            'is_pickup' => (bool)($o['is_pickup'] ?? false),
            'is_scheduled' => (bool)($o['is_scheduled'] ?? false),
            'created_at' => $o['created_at'] ?? $o['date_added'],
            'delivered_at' => $o['delivered_at'] ?? null,
            'cancelled_at' => $o['cancelled_at'] ?? null,
        ];
    }, $orders);

    response(true, [
        'pedidos' => $pedidos,
        'total' => $total,
        'pagina' => $page,
        'por_pagina' => $limit,
        'total_paginas' => (int)ceil($total / $limit),
    ]);

} catch (Exception $e) {
    error_log("[BoraUm Pedidos] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar pedidos", 500);
}

function getStatusLabels(): array {
    return [
        'pending' => 'Pendente',
        'pendente' => 'Pendente',
        'aceito' => 'Aceito',
        'confirmed' => 'Confirmado',
        'confirmado' => 'Confirmado',
        'preparando' => 'Preparando',
        'pronto' => 'Pronto',
        'saiu_entrega' => 'Saiu para entrega',
        'em_entrega' => 'Em entrega',
        'entregue' => 'Entregue',
        'delivered' => 'Entregue',
        'cancelado' => 'Cancelado',
        'cancelled' => 'Cancelado',
    ];
}
