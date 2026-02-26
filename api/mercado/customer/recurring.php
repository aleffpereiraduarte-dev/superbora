<?php
/**
 * POST /api/mercado/customer/recurring.php — Criar pedido recorrente
 * GET — Listar pedidos recorrentes
 * PUT — Atualizar recorrencia
 * DELETE — Cancelar recorrencia
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
setCorsHeaders();

try {
    $db = getDB();
    $customerId = getCustomerIdFromToken();
    if (!$customerId) response(false, null, 'Nao autorizado', 401);

    // GET: listar recorrentes
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $db->prepare("SELECT r.*, p.trade_name as partner_name, p.logo as partner_logo
            FROM om_recurring_orders r
            LEFT JOIN om_market_partners p ON r.market_id = p.partner_id
            WHERE r.customer_id = ? AND r.is_active = 1
            ORDER BY r.next_order_date ASC");
        $stmt->execute([$customerId]);
        $recurring = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $freqLabels = ['weekly' => 'Semanal', 'biweekly' => 'Quinzenal', 'monthly' => 'Mensal'];
        $dayLabels = ['Domingo', 'Segunda', 'Terca', 'Quarta', 'Quinta', 'Sexta', 'Sabado'];

        $result = array_map(function($r) use ($freqLabels, $dayLabels) {
            return [
                'id' => (int)$r['recurring_id'],
                'partner_id' => (int)($r['market_id'] ?? 0),
                'partner_name' => $r['partner_name'] ?? '',
                'partner_logo' => $r['partner_logo'] ?? '',
                'name' => $r['name'] ?? '',
                'frequency' => $r['frequency'],
                'frequency_label' => $freqLabels[$r['frequency']] ?? $r['frequency'],
                'day_of_week' => (int)($r['day_of_week'] ?? 0),
                'day_label' => $dayLabels[$r['day_of_week'] ?? 0] ?? '',
                'preferred_time' => $r['preferred_time'] ?? '',
                'next_order_date' => $r['next_order_date'],
                'last_order_date' => $r['last_order_date'],
            ];
        }, $recurring);

        response(true, ['recurring' => $result]);
    }

    // POST: criar recorrencia
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $partnerId = (int)($input['partner_id'] ?? 0);
        $name = trim($input['name'] ?? 'Pedido Recorrente');
        $frequency = $input['frequency'] ?? 'weekly';
        $dayOfWeek = (int)($input['day_of_week'] ?? 1); // 0-6
        $preferredTime = $input['preferred_time'] ?? '10:00';

        if (!$partnerId) {
            response(false, null, 'partner_id obrigatorio', 400);
        }

        if (!in_array($frequency, ['weekly', 'biweekly', 'monthly'])) {
            response(false, null, 'Frequencia invalida', 400);
        }

        if ($dayOfWeek < 0 || $dayOfWeek > 6) {
            response(false, null, 'day_of_week deve ser entre 0 (Domingo) e 6 (Sabado)', 400);
        }

        // Calcular proximo pedido
        $now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        $nextOrder = clone $now;
        $loopGuard = 0;
        while ((int)$nextOrder->format('w') !== $dayOfWeek && $loopGuard < 7) {
            $nextOrder->modify('+1 day');
            $loopGuard++;
        }
        if ($nextOrder <= $now) $nextOrder->modify('+1 week');

        $stmt = $db->prepare("INSERT INTO om_recurring_orders
            (customer_id, market_id, name, frequency, day_of_week, preferred_time, next_order_date)
            VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING recurring_id");
        $stmt->execute([$customerId, $partnerId, $name, $frequency, $dayOfWeek, $preferredTime, $nextOrder->format('Y-m-d')]);
        $id = (int)$stmt->fetchColumn();

        response(true, ['id' => $id, 'next_order_date' => $nextOrder->format('Y-m-d'), 'message' => 'Pedido recorrente criado']);
    }

    // PUT: atualizar
    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);
        if (!$id) response(false, null, 'id obrigatorio', 400);

        $updates = [];
        $params = [];

        if (isset($input['name'])) { $updates[] = "name = ?"; $params[] = $input['name']; }
        if (isset($input['frequency'])) {
            if (!in_array($input['frequency'], ['weekly', 'biweekly', 'monthly'])) {
                response(false, null, 'Frequencia invalida', 400);
            }
            $updates[] = "frequency = ?"; $params[] = $input['frequency'];
        }
        if (isset($input['day_of_week'])) {
            $dow = (int)$input['day_of_week'];
            if ($dow < 0 || $dow > 6) {
                response(false, null, 'day_of_week deve ser entre 0 (Domingo) e 6 (Sabado)', 400);
            }
            $updates[] = "day_of_week = ?"; $params[] = $dow;
        }
        if (isset($input['preferred_time'])) { $updates[] = "preferred_time = ?"; $params[] = $input['preferred_time']; }
        if (isset($input['is_active'])) { $updates[] = "is_active = ?"; $params[] = (int)$input['is_active']; }

        if (empty($updates)) response(false, null, 'Nenhum campo para atualizar', 400);

        $params[] = $id;
        $params[] = $customerId;
        $db->prepare("UPDATE om_recurring_orders SET " . implode(', ', $updates) . " WHERE recurring_id = ? AND customer_id = ?")
            ->execute($params);

        response(true, null, 'Recorrencia atualizada');
    }

    // DELETE: cancelar
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) response(false, null, 'id obrigatorio', 400);

        $db->prepare("UPDATE om_recurring_orders SET is_active = 0 WHERE recurring_id = ? AND customer_id = ?")
            ->execute([$id, $customerId]);

        response(true, null, 'Recorrencia cancelada');
    }

} catch (Exception $e) {
    error_log("[Recurring] " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
