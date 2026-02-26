<?php
/**
 * /api/mercado/orders/recurring.php
 * CRUD for recurring (subscription) orders
 *
 * GET    - List customer's recurring orders
 * POST   - Create a recurring order from an existing completed order
 * PUT    - Update a recurring order (frequency, status)
 * DELETE - Cancel a recurring order (soft delete)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Auth
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Token invalido", 401);
    $customerId = (int)$payload['uid'];

    // NOTE: Table om_market_recurring_orders must be created via migration, not at runtime.
    // See: sql/recurring_orders.sql for PostgreSQL-compatible DDL:
    //   CREATE TABLE IF NOT EXISTS om_market_recurring_orders (
    //       id SERIAL PRIMARY KEY,
    //       customer_id INT NOT NULL,
    //       partner_id INT NOT NULL,
    //       frequency TEXT NOT NULL DEFAULT 'weekly',
    //       day_of_week SMALLINT NOT NULL DEFAULT 1,
    //       time_slot VARCHAR(20) NOT NULL DEFAULT '09:00-10:00',
    //       items_json JSONB NOT NULL,
    //       delivery_address_json JSONB,
    //       status TEXT NOT NULL DEFAULT 'active',
    //       next_delivery DATE,
    //       source_order_id INT,
    //       created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    //       updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    //   );
    //   CREATE INDEX IF NOT EXISTS idx_recurring_customer ON om_market_recurring_orders(customer_id);
    //   CREATE INDEX IF NOT EXISTS idx_recurring_status ON om_market_recurring_orders(status);
    //   CREATE INDEX IF NOT EXISTS idx_recurring_next ON om_market_recurring_orders(next_delivery);

    $method = $_SERVER['REQUEST_METHOD'];

    // ─── GET: List recurring orders ──────────────────────────────────────
    if ($method === 'GET') {
        $stmt = $db->prepare("
            SELECT r.*, p.trade_name, p.logo as partner_logo
            FROM om_market_recurring_orders r
            LEFT JOIN om_market_partners p ON r.partner_id = p.partner_id
            WHERE r.customer_id = ? AND r.status != 'cancelled'
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$customerId]);
        $rows = $stmt->fetchAll();

        $recurring = [];
        foreach ($rows as $row) {
            $items = json_decode($row['items_json'], true) ?: [];
            $itemsSummary = array_map(function($i) {
                return ($i['quantity'] ?? 1) . 'x ' . ($i['name'] ?? '');
            }, array_slice($items, 0, 3));
            if (count($items) > 3) {
                $itemsSummary[] = '+' . (count($items) - 3) . ' mais';
            }

            $frequencyLabels = [
                'weekly' => 'Semanal',
                'biweekly' => 'Quinzenal',
                'monthly' => 'Mensal',
            ];

            $dayLabels = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab'];

            $recurring[] = [
                'id' => (int)$row['id'],
                'partner' => [
                    'id' => (int)$row['partner_id'],
                    'name' => $row['trade_name'] ?: '',
                    'logo' => $row['partner_logo'] ?: null,
                ],
                'items_summary' => implode(', ', $itemsSummary),
                'items_count' => count($items),
                'items' => $items,
                'frequency' => $row['frequency'],
                'frequency_label' => $frequencyLabels[$row['frequency']] ?? $row['frequency'],
                'day_of_week' => (int)$row['day_of_week'],
                'day_label' => $dayLabels[(int)$row['day_of_week']] ?? '',
                'time_slot' => $row['time_slot'],
                'status' => $row['status'],
                'next_delivery' => $row['next_delivery'],
                'created_at' => $row['created_at'],
            ];
        }

        response(true, ['recurring_orders' => $recurring]);
    }

    // ─── POST: Create recurring order ────────────────────────────────────
    elseif ($method === 'POST') {
        $input = getInput();

        $orderId = (int)($input['order_id'] ?? 0);
        $frequency = $input['frequency'] ?? 'weekly';
        $dayOfWeek = (int)($input['day_of_week'] ?? 1);
        $timeSlot = trim($input['time_slot'] ?? '09:00-10:00');

        if (!$orderId) response(false, null, "ID do pedido obrigatorio", 400);
        if (!in_array($frequency, ['weekly', 'biweekly', 'monthly'])) {
            response(false, null, "Frequencia invalida. Use: weekly, biweekly, monthly", 400);
        }
        if ($dayOfWeek < 0 || $dayOfWeek > 6) {
            response(false, null, "Dia da semana invalido (0-6)", 400);
        }
        if (!preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $timeSlot)) {
            response(false, null, "Horario invalido. Use formato HH:MM-HH:MM", 400);
        }

        // Fetch the source order
        $stmtOrder = $db->prepare("
            SELECT o.*, p.trade_name
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.order_id = ? AND o.customer_id = ?
        ");
        $stmtOrder->execute([$orderId, $customerId]);
        $order = $stmtOrder->fetch();

        if (!$order) response(false, null, "Pedido nao encontrado", 404);

        // Fetch order items
        $stmtItems = $db->prepare("
            SELECT product_id, product_name as name, quantity, price, product_image as image
            FROM om_market_order_items WHERE order_id = ?
        ");
        $stmtItems->execute([$orderId]);
        $items = $stmtItems->fetchAll();

        if (empty($items)) response(false, null, "Pedido sem itens", 400);

        // Build items JSON
        $itemsJson = json_encode($items);

        // Build delivery address JSON
        $addressJson = json_encode([
            'address' => $order['delivery_address'] ?: $order['shipping_address'],
            'neighborhood' => $order['shipping_neighborhood'] ?? '',
            'city' => $order['shipping_city'] ?? '',
            'state' => $order['shipping_state'] ?? '',
            'cep' => $order['shipping_cep'] ?? '',
        ]);

        // Calculate next delivery date
        $nextDelivery = calculateNextDelivery($frequency, $dayOfWeek);

        $stmt = $db->prepare("
            INSERT INTO om_market_recurring_orders
                (customer_id, partner_id, frequency, day_of_week, time_slot, items_json, delivery_address_json, status, next_delivery, source_order_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, NOW())
        ");
        $stmt->execute([
            $customerId,
            (int)$order['partner_id'],
            $frequency,
            $dayOfWeek,
            $timeSlot,
            $itemsJson,
            $addressJson,
            $nextDelivery,
            $orderId,
        ]);

        $recurringId = (int)$db->lastInsertId();

        $frequencyLabels = [
            'weekly' => 'Semanal',
            'biweekly' => 'Quinzenal',
            'monthly' => 'Mensal',
        ];

        response(true, [
            'id' => $recurringId,
            'frequency' => $frequency,
            'frequency_label' => $frequencyLabels[$frequency] ?? $frequency,
            'day_of_week' => $dayOfWeek,
            'time_slot' => $timeSlot,
            'next_delivery' => $nextDelivery,
            'status' => 'active',
        ], "Pedido recorrente criado com sucesso!");
    }

    // ─── PUT: Update recurring order ─────────────────────────────────────
    elseif ($method === 'PUT') {
        $input = getInput();
        $id = (int)($input['id'] ?? 0);

        if (!$id) response(false, null, "ID obrigatorio", 400);

        // Verify ownership
        $stmtCheck = $db->prepare("SELECT * FROM om_market_recurring_orders WHERE id = ? AND customer_id = ?");
        $stmtCheck->execute([$id, $customerId]);
        $existing = $stmtCheck->fetch();

        if (!$existing) response(false, null, "Pedido recorrente nao encontrado", 404);

        $updates = [];
        $params = [];

        if (isset($input['frequency']) && in_array($input['frequency'], ['weekly', 'biweekly', 'monthly'])) {
            $updates[] = "frequency = ?";
            $params[] = $input['frequency'];
        }

        if (isset($input['day_of_week']) && $input['day_of_week'] >= 0 && $input['day_of_week'] <= 6) {
            $updates[] = "day_of_week = ?";
            $params[] = (int)$input['day_of_week'];
        }

        if (isset($input['time_slot']) && preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $input['time_slot'])) {
            $updates[] = "time_slot = ?";
            $params[] = $input['time_slot'];
        }

        if (isset($input['status']) && in_array($input['status'], ['active', 'paused', 'cancelled'])) {
            $updates[] = "status = ?";
            $params[] = $input['status'];
        }

        if (empty($updates)) response(false, null, "Nenhuma alteracao informada", 400);

        // Recalculate next delivery if frequency or day changed
        $newFreq = $input['frequency'] ?? $existing['frequency'];
        $newDay = isset($input['day_of_week']) ? (int)$input['day_of_week'] : (int)$existing['day_of_week'];
        $newStatus = $input['status'] ?? $existing['status'];

        if ($newStatus === 'active') {
            $updates[] = "next_delivery = ?";
            $params[] = calculateNextDelivery($newFreq, $newDay);
        } elseif ($newStatus === 'paused' || $newStatus === 'cancelled') {
            $updates[] = "next_delivery = NULL";
        }

        $params[] = $id;
        $params[] = $customerId;

        $sql = "UPDATE om_market_recurring_orders SET " . implode(', ', $updates) . " WHERE id = ? AND customer_id = ?";
        $db->prepare($sql)->execute($params);

        response(true, ['id' => $id, 'status' => $newStatus], "Pedido recorrente atualizado!");
    }

    // ─── DELETE: Cancel recurring order ──────────────────────────────────
    elseif ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) response(false, null, "ID obrigatorio", 400);

        // Verify ownership
        $stmtCheck = $db->prepare("SELECT id FROM om_market_recurring_orders WHERE id = ? AND customer_id = ?");
        $stmtCheck->execute([$id, $customerId]);
        if (!$stmtCheck->fetch()) response(false, null, "Pedido recorrente nao encontrado", 404);

        $db->prepare("UPDATE om_market_recurring_orders SET status = 'cancelled', next_delivery = NULL WHERE id = ? AND customer_id = ?")
            ->execute([$id, $customerId]);

        response(true, ['id' => $id], "Pedido recorrente cancelado!");
    }

    // ─── OPTIONS: CORS preflight ─────────────────────────────────────────
    elseif ($method === 'OPTIONS') {
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");
        http_response_code(204);
        exit;
    }

    else {
        response(false, null, "Metodo nao suportado", 405);
    }

} catch (Exception $e) {
    error_log("[API Recurring Orders] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar pedido recorrente", 500);
}

/**
 * Calculate the next delivery date based on frequency and day of week
 */
function calculateNextDelivery(string $frequency, int $dayOfWeek): string {
    $spTz = new DateTimeZone('America/Sao_Paulo');
    $now = new DateTime('now', $spTz);

    // Days of week: 0=Sun, 1=Mon, 2=Tue, ...
    $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $targetDay = $dayNames[$dayOfWeek] ?? 'Monday';

    // Find the next occurrence of the target day
    $next = new DateTime('next ' . $targetDay, $spTz);

    // If biweekly, add one more week
    if ($frequency === 'biweekly') {
        $next->modify('+1 week');
    }
    // If monthly, find the first occurrence of the target day next month
    elseif ($frequency === 'monthly') {
        $next->modify('+3 weeks'); // approximately 1 month from the next occurrence
        // Adjust to the correct day of week
        while ((int)$next->format('w') !== $dayOfWeek) {
            $next->modify('-1 day');
        }
    }

    return $next->format('Y-m-d');
}
