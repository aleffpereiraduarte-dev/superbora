<?php
/**
 * Scheduled & Recurring Orders API
 * GET - List scheduled orders + recurring configs
 * POST - Create scheduled order / recurring config
 * PUT - Update scheduled order
 * DELETE - Cancel scheduled order or deactivate recurring
 */

require_once __DIR__ . '/../config/database.php';
setCorsHeaders();

$customerId = requireCustomerAuth();
$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $type = $_GET['type'] ?? 'all'; // all, scheduled, recurring

    $result = [];

    if ($type === 'all' || $type === 'scheduled') {
        // Upcoming scheduled orders
        $stmt = $db->prepare("
            SELECT so.id, so.scheduled_date, so.scheduled_time, so.items,
                   so.subtotal, so.delivery_fee, so.total, so.status, so.notes,
                   so.recurring_id, so.created_at,
                   s.id as store_id, s.name as store_name, s.logo_url
            FROM om_market_scheduled_orders so
            JOIN om_market_stores s ON s.id = so.store_id
            WHERE so.customer_id = ?
              AND so.status IN ('agendado', 'processando')
              AND so.scheduled_date >= CURRENT_DATE
            ORDER BY so.scheduled_date ASC, so.scheduled_time ASC
            LIMIT 50
        ");
        $stmt->execute([$customerId]);
        $scheduled = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($scheduled as &$order) {
            $order['items'] = json_decode($order['items'], true) ?: [];
            $order['items_count'] = count($order['items']);
        }
        $result['scheduled'] = $scheduled;
    }

    if ($type === 'all' || $type === 'recurring') {
        // Active recurring orders
        $stmt = $db->prepare("
            SELECT ro.id, ro.frequency, ro.day_of_week, ro.day_of_month,
                   ro.preferred_time, ro.items, ro.is_active,
                   ro.last_generated_at, ro.next_scheduled_at, ro.created_at,
                   s.id as store_id, s.name as store_name, s.logo_url
            FROM om_market_recurring_orders ro
            JOIN om_market_stores s ON s.id = ro.store_id
            WHERE ro.customer_id = ? AND ro.is_active = true
            ORDER BY ro.next_scheduled_at ASC
        ");
        $stmt->execute([$customerId]);
        $recurring = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($recurring as &$rec) {
            $rec['items'] = json_decode($rec['items'], true) ?: [];
            $rec['items_count'] = count($rec['items']);

            // Calculate next delivery description
            $freq = $rec['frequency'];
            $dayNames = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
            if ($freq === 'semanal' && $rec['day_of_week'] !== null) {
                $rec['frequency_label'] = 'Toda ' . $dayNames[$rec['day_of_week']];
            } elseif ($freq === 'quinzenal') {
                $rec['frequency_label'] = 'A cada 2 semanas';
            } elseif ($freq === 'mensal' && $rec['day_of_month']) {
                $rec['frequency_label'] = 'Todo dia ' . $rec['day_of_month'];
            } elseif ($freq === 'diario') {
                $rec['frequency_label'] = 'Todo dia';
            } else {
                $rec['frequency_label'] = ucfirst($freq);
            }
        }
        $result['recurring'] = $recurring;
    }

    // Past scheduled orders (history)
    $stmt = $db->prepare("
        SELECT COUNT(*) as total_completed,
               COALESCE(SUM(total), 0) as total_spent
        FROM om_market_scheduled_orders
        WHERE customer_id = ? AND status = 'concluido'
    ");
    $stmt->execute([$customerId]);
    $result['stats'] = $stmt->fetch(PDO::FETCH_ASSOC);

    response(true, $result);
}

if ($method === 'POST') {
    $input = getInput();
    $action = $input['action'] ?? 'schedule';

    if ($action === 'schedule') {
        // Create a new scheduled order
        $storeId = (int)($input['store_id'] ?? 0);
        $scheduledDate = $input['scheduled_date'] ?? null;
        $scheduledTime = $input['scheduled_time'] ?? null;
        $items = $input['items'] ?? [];
        $addressId = $input['address_id'] ?? null;
        $paymentMethod = $input['payment_method'] ?? 'pix';
        $notes = trim($input['notes'] ?? '');

        if (!$storeId || !$scheduledDate || empty($items)) {
            response(false, null, 'Loja, data e itens são obrigatórios', 400);
        }

        // Verify store
        $stmt = $db->prepare("SELECT id, name FROM om_market_stores WHERE id = ? AND is_active = true");
        $stmt->execute([$storeId]);
        if (!$stmt->fetch()) {
            response(false, null, 'Loja não encontrada', 404);
        }

        // Calculate totals using server-side prices (never trust client prices)
        $subtotal = 0;
        foreach ($items as &$item) {
            $prodStmt = $db->prepare("SELECT price, sale_price FROM om_market_products WHERE product_id = ? AND status = 1");
            $prodStmt->execute([$item['product_id'] ?? 0]);
            $prod = $prodStmt->fetch();
            if ($prod) {
                $serverPrice = ((float)$prod['sale_price'] > 0 && (float)$prod['sale_price'] < (float)$prod['price'])
                    ? (float)$prod['sale_price'] : (float)$prod['price'];
                $item['price'] = $serverPrice;
            }
            $subtotal += ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
        }
        unset($item);
        $deliveryFee = (float)($input['delivery_fee'] ?? 0);
        $total = $subtotal + $deliveryFee;

        $stmt = $db->prepare("
            INSERT INTO om_market_scheduled_orders
                (customer_id, store_id, scheduled_date, scheduled_time, items,
                 subtotal, delivery_fee, total, address_id, payment_method, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([
            $customerId, $storeId, $scheduledDate, $scheduledTime,
            json_encode($items), $subtotal, $deliveryFee, $total,
            $addressId, $paymentMethod, $notes
        ]);
        $id = $stmt->fetchColumn();

        response(true, ['id' => $id, 'message' => 'Pedido agendado com sucesso']);
    }

    if ($action === 'recurring') {
        // Create recurring order
        $storeId = (int)($input['store_id'] ?? 0);
        $frequency = $input['frequency'] ?? 'semanal';
        $dayOfWeek = $input['day_of_week'] ?? null;
        $dayOfMonth = $input['day_of_month'] ?? null;
        $preferredTime = $input['preferred_time'] ?? null;
        $items = $input['items'] ?? [];
        $addressId = $input['address_id'] ?? null;
        $paymentMethod = $input['payment_method'] ?? 'pix';

        if (!$storeId || empty($items)) {
            response(false, null, 'Loja e itens são obrigatórios', 400);
        }

        $validFreqs = ['diario', 'semanal', 'quinzenal', 'mensal'];
        if (!in_array($frequency, $validFreqs)) {
            response(false, null, 'Frequência inválida', 400);
        }

        // Calculate next_scheduled_at
        $nextScheduled = new DateTime();
        switch ($frequency) {
            case 'diario':
                $nextScheduled->modify('+1 day');
                break;
            case 'semanal':
                if ($dayOfWeek !== null) {
                    $nextScheduled->modify('next ' . ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][$dayOfWeek]);
                } else {
                    $nextScheduled->modify('+7 days');
                }
                break;
            case 'quinzenal':
                $nextScheduled->modify('+14 days');
                break;
            case 'mensal':
                if ($dayOfMonth) {
                    $nextScheduled->modify('first day of next month');
                    $nextScheduled->setDate((int)$nextScheduled->format('Y'), (int)$nextScheduled->format('m'), min($dayOfMonth, (int)$nextScheduled->format('t')));
                } else {
                    $nextScheduled->modify('+1 month');
                }
                break;
        }

        $stmt = $db->prepare("
            INSERT INTO om_market_recurring_orders
                (customer_id, store_id, frequency, day_of_week, day_of_month,
                 preferred_time, items, address_id, payment_method, next_scheduled_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([
            $customerId, $storeId, $frequency, $dayOfWeek, $dayOfMonth,
            $preferredTime, json_encode($items), $addressId, $paymentMethod,
            $nextScheduled->format('Y-m-d H:i:s')
        ]);
        $id = $stmt->fetchColumn();

        response(true, ['id' => $id, 'message' => 'Pedido recorrente criado']);
    }

    response(false, null, 'Ação inválida', 400);
}

if ($method === 'PUT') {
    $input = getInput();
    $orderId = (int)($input['id'] ?? 0);
    $type = $input['type'] ?? 'scheduled';

    if (!$orderId) {
        response(false, null, 'ID obrigatório', 400);
    }

    if ($type === 'scheduled') {
        // Update scheduled order
        $stmt = $db->prepare("SELECT id FROM om_market_scheduled_orders WHERE id = ? AND customer_id = ? AND status = 'agendado'");
        $stmt->execute([$orderId, $customerId]);
        if (!$stmt->fetch()) {
            response(false, null, 'Pedido agendado não encontrado', 404);
        }

        $updates = [];
        $params = [];

        if (isset($input['scheduled_date'])) { $updates[] = "scheduled_date = ?"; $params[] = $input['scheduled_date']; }
        if (isset($input['scheduled_time'])) { $updates[] = "scheduled_time = ?"; $params[] = $input['scheduled_time']; }
        if (isset($input['items'])) { $updates[] = "items = ?"; $params[] = json_encode($input['items']); }
        if (isset($input['notes'])) { $updates[] = "notes = ?"; $params[] = $input['notes']; }

        if (empty($updates)) {
            response(false, null, 'Nenhum campo para atualizar', 400);
        }

        $updates[] = "updated_at = NOW()";
        $params[] = $orderId;
        $params[] = $customerId;

        $sql = "UPDATE om_market_scheduled_orders SET " . implode(', ', $updates) . " WHERE id = ? AND customer_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        response(true, ['message' => 'Pedido atualizado']);
    }

    if ($type === 'recurring') {
        // Update recurring config
        $stmt = $db->prepare("SELECT id FROM om_market_recurring_orders WHERE id = ? AND customer_id = ?");
        $stmt->execute([$orderId, $customerId]);
        if (!$stmt->fetch()) {
            response(false, null, 'Pedido recorrente não encontrado', 404);
        }

        $updates = [];
        $params = [];

        if (isset($input['frequency'])) { $updates[] = "frequency = ?"; $params[] = $input['frequency']; }
        if (isset($input['day_of_week'])) { $updates[] = "day_of_week = ?"; $params[] = $input['day_of_week']; }
        if (isset($input['preferred_time'])) { $updates[] = "preferred_time = ?"; $params[] = $input['preferred_time']; }
        if (isset($input['items'])) { $updates[] = "items = ?"; $params[] = json_encode($input['items']); }
        if (isset($input['is_active'])) { $updates[] = "is_active = ?"; $params[] = $input['is_active'] ? 'true' : 'false'; }

        if (empty($updates)) {
            response(false, null, 'Nenhum campo para atualizar', 400);
        }

        $updates[] = "updated_at = NOW()";
        $params[] = $orderId;
        $params[] = $customerId;

        $sql = "UPDATE om_market_recurring_orders SET " . implode(', ', $updates) . " WHERE id = ? AND customer_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        response(true, ['message' => 'Configuração atualizada']);
    }
}

if ($method === 'DELETE') {
    $orderId = (int)($_GET['id'] ?? 0);
    $type = $_GET['type'] ?? 'scheduled';

    if (!$orderId) {
        response(false, null, 'ID obrigatório', 400);
    }

    if ($type === 'scheduled') {
        $stmt = $db->prepare("
            UPDATE om_market_scheduled_orders
            SET status = 'cancelado', updated_at = NOW()
            WHERE id = ? AND customer_id = ? AND status = 'agendado'
        ");
        $stmt->execute([$orderId, $customerId]);

        if ($stmt->rowCount() === 0) {
            response(false, null, 'Pedido não encontrado ou já processado', 404);
        }
        response(true, ['message' => 'Pedido cancelado']);
    }

    if ($type === 'recurring') {
        $stmt = $db->prepare("
            UPDATE om_market_recurring_orders
            SET is_active = false, updated_at = NOW()
            WHERE id = ? AND customer_id = ?
        ");
        $stmt->execute([$orderId, $customerId]);
        response(true, ['message' => 'Pedido recorrente desativado']);
    }
}
