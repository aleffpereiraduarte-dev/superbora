<?php
/**
 * POST /api/mercado/pedidos/agendar.php — Agendar pedido para data/hora futura
 * GET  — Listar pedidos agendados do cliente
 * PUT  — Reagendar pedido
 * DELETE — Cancelar agendamento
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

try {
    $db = getDB();
    $customerId = getCustomerIdFromToken();
    if (!$customerId) response(false, null, 'Nao autorizado', 401);

    // GET: listar agendados
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $db->prepare("SELECT order_id, order_number, partner_id, subtotal, delivery_fee, total,
            schedule_date, schedule_time, scheduled_date, scheduled_time, status,
            (SELECT trade_name FROM om_market_partners WHERE partner_id = o.partner_id) as partner_name
            FROM om_market_orders o
            WHERE customer_id = ?
              AND (schedule_date IS NOT NULL OR scheduled_date IS NOT NULL)
              AND status NOT IN ('entregue', 'cancelado', 'cancelled')
            ORDER BY COALESCE(scheduled_date, schedule_date) ASC");
        $stmt->execute([$customerId]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = array_map(function($o) {
            $date = $o['scheduled_date'] ?: $o['schedule_date'];
            $time = $o['scheduled_time'] ?: $o['schedule_time'];
            return [
                'order_id' => (int)$o['order_id'],
                'order_number' => $o['order_number'],
                'partner_name' => $o['partner_name'],
                'total' => (float)$o['total'],
                'scheduled_date' => $date,
                'scheduled_time' => $time ? substr($time, 0, 5) : null,
                'status' => $o['status'],
            ];
        }, $orders);

        response(true, ['agendados' => $result]);
    }

    // POST: criar agendamento (ou marcar pedido como agendado)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $orderId = (int)($input['order_id'] ?? 0);
        $scheduleDate = $input['schedule_date'] ?? null; // YYYY-MM-DD
        $scheduleTime = $input['schedule_time'] ?? null; // HH:MM

        if (!$orderId || !$scheduleDate || !$scheduleTime) {
            response(false, null, 'order_id, schedule_date e schedule_time obrigatorios', 400);
        }

        // Validar que a data e futura
        $scheduledDT = new DateTime("{$scheduleDate} {$scheduleTime}", new DateTimeZone('America/Sao_Paulo'));
        $now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        if ($scheduledDT <= $now) {
            response(false, null, 'A data agendada deve ser no futuro', 400);
        }

        // Verificar que o pedido pertence ao cliente
        $stmt = $db->prepare("SELECT order_id, status FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
        $stmt->execute([$orderId, $customerId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) response(false, null, 'Pedido nao encontrado', 404);

        $db->prepare("UPDATE om_market_orders SET scheduled_date = ?, scheduled_time = ?, schedule_date = ?, schedule_time = ?, status = 'agendado' WHERE order_id = ?")
            ->execute([$scheduleDate, $scheduleTime, $scheduleDate, $scheduleTime, $orderId]);

        response(true, ['order_id' => $orderId, 'scheduled_date' => $scheduleDate, 'scheduled_time' => $scheduleTime]);
    }

    // PUT: reagendar
    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);
        $orderId = (int)($input['order_id'] ?? 0);
        $newDate = $input['schedule_date'] ?? null;
        $newTime = $input['schedule_time'] ?? null;

        if (!$orderId || !$newDate || !$newTime) {
            response(false, null, 'order_id, schedule_date e schedule_time obrigatorios', 400);
        }

        // Validar que a nova data e futura
        $scheduledDT = new DateTime("{$newDate} {$newTime}", new DateTimeZone('America/Sao_Paulo'));
        $now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        if ($scheduledDT <= $now) {
            response(false, null, 'A data agendada deve ser no futuro', 400);
        }

        $stmt = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND customer_id = ? AND status = 'agendado'");
        $stmt->execute([$orderId, $customerId]);
        if (!$stmt->fetch()) response(false, null, 'Pedido agendado nao encontrado', 404);

        $db->prepare("UPDATE om_market_orders SET scheduled_date = ?, scheduled_time = ?, schedule_date = ?, schedule_time = ? WHERE order_id = ?")
            ->execute([$newDate, $newTime, $newDate, $newTime, $orderId]);

        response(true, null, 'Reagendado com sucesso');
    }

    // DELETE: cancelar agendamento
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $orderId = (int)($_GET['order_id'] ?? 0);
        if (!$orderId) response(false, null, 'order_id obrigatorio', 400);

        $stmt = $db->prepare("SELECT order_id, coupon_id FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
        $stmt->execute([$orderId, $customerId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) response(false, null, 'Pedido nao encontrado', 404);

        $db->beginTransaction();
        try {
            // Cancelar o pedido
            $db->prepare("UPDATE om_market_orders SET scheduled_date = NULL, scheduled_time = NULL, schedule_date = NULL, schedule_time = NULL, status = 'cancelado', cancelled_at = NOW(), cancellation_reason = 'Agendamento cancelado pelo cliente' WHERE order_id = ?")
                ->execute([$orderId]);

            // Restaurar estoque dos itens do pedido
            $stmtItems = $db->prepare("SELECT product_id, quantity FROM om_market_order_items WHERE order_id = ?");
            $stmtItems->execute([$orderId]);
            foreach ($stmtItems->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?")
                    ->execute([$item['quantity'], $item['product_id']]);
            }

            // Restaurar uso do cupom se aplicavel
            if (!empty($order['coupon_id'])) {
                $db->prepare("UPDATE om_market_coupons SET current_uses = GREATEST(0, current_uses - 1) WHERE id = ?")
                    ->execute([$order['coupon_id']]);
                $db->prepare("DELETE FROM om_market_coupon_usage WHERE coupon_id = ? AND order_id = ?")
                    ->execute([$order['coupon_id'], $orderId]);
            }

            $db->commit();
        } catch (Exception $txEx) {
            if ($db->inTransaction()) $db->rollBack();
            throw $txEx;
        }

        response(true, null, 'Agendamento cancelado');
    }

} catch (Exception $e) {
    error_log("[Agendar] " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
