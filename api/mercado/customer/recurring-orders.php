<?php
/**
 * GET/POST/DELETE /api/mercado/customer/recurring-orders.php
 * Gestao de pedidos recorrentes
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') {
        response(false, null, "Nao autorizado", 401);
    }

    $customerId = (int)$payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    // GET - Listar pedidos recorrentes
    if ($method === 'GET') {
        $stmt = $db->prepare("
            SELECT r.recurring_id as id, r.customer_id, r.market_id as partner_id, r.name, r.frequency,
                   r.day_of_week, r.day_of_month, r.preferred_time, r.next_order_date as next_order_at,
                   r.is_active, r.created_at,
                   p.trade_name as partner_name, p.logo as partner_logo
            FROM om_recurring_orders r
            INNER JOIN om_market_partners p ON r.market_id = p.partner_id
            WHERE r.customer_id = ? AND r.is_active = true
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$customerId]);
        $orders = $stmt->fetchAll();

        $formattedOrders = array_map(function($o) {
            $nextOrder = $o['next_order_at'] ? new DateTime($o['next_order_at']) : null;
            $now = new DateTime();

            return [
                'id' => (int)$o['id'],
                'name' => $o['name'],
                'partner' => [
                    'id' => (int)$o['partner_id'],
                    'name' => $o['partner_name'],
                    'logo' => $o['partner_logo'],
                ],
                'frequency' => $o['frequency'],
                'frequency_label' => match($o['frequency']) {
                    'daily' => 'Diario',
                    'weekly' => 'Semanal',
                    'biweekly' => 'Quinzenal',
                    'monthly' => 'Mensal',
                    default => $o['frequency']
                },
                'day_of_week' => $o['day_of_week'],
                'preferred_time' => $o['preferred_time'],
                'next_order_at' => $o['next_order_at'],
                'days_until_next' => $nextOrder ? max(0, (int)$now->diff($nextOrder)->days) : null,
                'status' => $o['is_active'] ? 'active' : 'paused',
            ];
        }, $orders);

        response(true, ['recurring_orders' => $formattedOrders]);
    }

    // POST - Criar ou atualizar
    if ($method === 'POST') {
        $input = getInput();

        $id = (int)($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        $partnerId = (int)($input['partner_id'] ?? 0);
        $items = $input['items'] ?? [];
        $frequency = $input['frequency'] ?? 'weekly';
        $dayOfWeek = isset($input['day_of_week']) ? (int)$input['day_of_week'] : null;
        $dayOfMonth = isset($input['day_of_month']) ? (int)$input['day_of_month'] : null;
        $preferredTime = $input['preferred_time'] ?? '12:00';
        $addressId = (int)($input['address_id'] ?? 0);
        $paymentMethod = $input['payment_method'] ?? 'pix';

        if (empty($name)) {
            response(false, null, "Nome do pedido obrigatorio", 400);
        }

        if (!$partnerId || empty($items)) {
            response(false, null, "Parceiro e itens obrigatorios", 400);
        }

        if (!in_array($frequency, ['daily', 'weekly', 'biweekly', 'monthly'])) {
            response(false, null, "Frequencia invalida", 400);
        }

        // Validar que address_id pertence ao cliente autenticado
        if ($addressId) {
            $stmtAddr = $db->prepare("SELECT address_id FROM om_customer_addresses WHERE address_id = ? AND customer_id = ?");
            $stmtAddr->execute([$addressId, $customerId]);
            if (!$stmtAddr->fetch()) {
                response(false, null, "Endereco nao pertence a este cliente", 403);
            }
        }

        // Calcular proxima data
        $now = new DateTime();
        $nextOrder = clone $now;

        switch ($frequency) {
            case 'daily':
                $nextOrder->modify('+1 day');
                break;
            case 'weekly':
                if ($dayOfWeek !== null) {
                    $daysUntil = ($dayOfWeek - (int)$now->format('w') + 7) % 7;
                    if ($daysUntil === 0) $daysUntil = 7;
                    $nextOrder->modify("+{$daysUntil} days");
                } else {
                    $nextOrder->modify('+1 week');
                }
                break;
            case 'biweekly':
                $nextOrder->modify('+2 weeks');
                break;
            case 'monthly':
                if ($dayOfMonth !== null) {
                    $targetMonth = (int)$nextOrder->format('m');
                    $targetYear = (int)$nextOrder->format('Y');
                    $lastDay = (int)(new DateTime("$targetYear-$targetMonth-01"))->format('t');
                    $nextOrder->setDate($targetYear, $targetMonth, min($dayOfMonth, $lastDay));
                    if ($nextOrder <= $now) {
                        $nextOrder->modify('+1 month');
                        $nextMonth = (int)$nextOrder->format('m');
                        $nextYear = (int)$nextOrder->format('Y');
                        $lastDayNext = (int)(new DateTime("$nextYear-$nextMonth-01"))->format('t');
                        $nextOrder->setDate($nextYear, $nextMonth, min($dayOfMonth, $lastDayNext));
                    }
                } else {
                    $nextOrder->modify('+1 month');
                }
                break;
        }

        $nextOrderAt = $nextOrder->format('Y-m-d') . ' ' . $preferredTime . ':00';
        $itemsJson = json_encode($items);

        if ($id) {
            // Atualizar
            $stmt = $db->prepare("
                UPDATE om_recurring_orders SET
                    name = ?, frequency = ?, day_of_week = ?,
                    day_of_month = ?, preferred_time = ?, delivery_address_id = ?,
                    next_order_date = ?
                WHERE recurring_id = ? AND customer_id = ?
            ");
            $stmt->execute([
                $name, $frequency, $dayOfWeek,
                $dayOfMonth, $preferredTime, $addressId ?: null,
                $nextOrderAt, $id, $customerId
            ]);

            if ($stmt->rowCount() === 0) {
                response(false, null, "Pedido recorrente nao encontrado", 404);
            }

            response(true, ['message' => 'Pedido recorrente atualizado!']);
        }

        // Criar novo
        $stmt = $db->prepare("
            INSERT INTO om_recurring_orders
            (customer_id, market_id, name, frequency, day_of_week,
             day_of_month, preferred_time, delivery_address_id, next_order_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $customerId, $partnerId, $name, $frequency, $dayOfWeek,
            $dayOfMonth, $preferredTime, $addressId ?: null, $nextOrderAt
        ]);

        response(true, [
            'id' => (int)$db->lastInsertId(),
            'next_order_at' => $nextOrderAt,
            'message' => 'Pedido recorrente criado!'
        ]);
    }

    // DELETE ou pausar
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        $action = $_GET['action'] ?? 'cancel';

        if (!$id) {
            response(false, null, "ID obrigatorio", 400);
        }

        if ($action === 'pause') {
            $db->prepare("
                UPDATE om_recurring_orders
                SET is_active = false
                WHERE recurring_id = ? AND customer_id = ?
            ")->execute([$id, $customerId]);

            response(true, ['message' => 'Pedido recorrente pausado']);
        }

        if ($action === 'resume') {
            $db->prepare("
                UPDATE om_recurring_orders
                SET is_active = true
                WHERE recurring_id = ? AND customer_id = ?
            ")->execute([$id, $customerId]);

            response(true, ['message' => 'Pedido recorrente reativado']);
        }

        // Cancelar
        $db->prepare("
            UPDATE om_recurring_orders
            SET is_active = false
            WHERE recurring_id = ? AND customer_id = ?
        ")->execute([$id, $customerId]);

        response(true, ['message' => 'Pedido recorrente cancelado']);
    }

} catch (Exception $e) {
    error_log("[customer/recurring-orders] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar pedido recorrente", 500);
}
