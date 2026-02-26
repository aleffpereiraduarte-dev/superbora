<?php
/**
 * GET /api/mercado/partner/menu-schedules.php - Listar agendamentos de cardapio
 * POST /api/mercado/partner/menu-schedules.php - Gerenciar agendamentos
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
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = $payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        // Get all schedules
        $stmt = $db->prepare("
            SELECT ms.id, ms.partner_id, ms.name, ms.schedule_type, ms.start_time, ms.end_time,
                   ms.days_of_week, ms.start_date, ms.end_date, ms.status, ms.created_at,
                (SELECT COUNT(*) FROM om_product_schedule_links psl WHERE psl.schedule_id = ms.id) as product_count
            FROM om_menu_schedules ms
            WHERE ms.partner_id = ? AND ms.status = '1'
            ORDER BY ms.name
        ");
        $stmt->execute([$partnerId]);
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get products for each schedule
        foreach ($schedules as &$schedule) {
            $stmt = $db->prepare("
                SELECT p.product_id, p.name, p.price
                FROM om_product_schedule_links psl
                JOIN om_market_products p ON p.product_id = psl.product_id
                WHERE psl.schedule_id = ?
            ");
            $stmt->execute([$schedule['id']]);
            $schedule['products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $schedule['days'] = $schedule['days_of_week'] ? explode(',', $schedule['days_of_week']) : [];
        }

        // Get all products
        $stmt = $db->prepare("
            SELECT product_id, name, price FROM om_market_products
            WHERE partner_id = ? AND status = '1' ORDER BY name
        ");
        $stmt->execute([$partnerId]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        response(true, [
            'schedules' => $schedules,
            'products' => $products,
            'schedule_types' => [
                'time_based' => 'Horario (ex: cafe da manha)',
                'day_based' => 'Dia da semana (ex: feijoada sabado)',
                'seasonal' => 'Temporario (ex: menu de natal)',
            ],
        ]);
    }

    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? 'create';

        if ($action === 'create' || $action === 'update') {
            $scheduleId = intval($input['id'] ?? 0);
            $name = trim($input['name'] ?? '');
            $scheduleType = $input['schedule_type'] ?? 'time_based';
            $startTime = $input['start_time'] ?? null;
            $endTime = $input['end_time'] ?? null;
            $daysOfWeek = $input['days_of_week'] ?? null;
            $startDate = $input['start_date'] ?? null;
            $endDate = $input['end_date'] ?? null;

            if (empty($name)) response(false, null, "Nome obrigatorio", 400);

            // Format days
            if (is_array($daysOfWeek)) {
                $daysOfWeek = implode(',', $daysOfWeek);
            }

            if ($scheduleId > 0) {
                // Update
                $stmt = $db->prepare("
                    UPDATE om_menu_schedules SET
                        name = ?, schedule_type = ?, start_time = ?, end_time = ?,
                        days_of_week = ?, start_date = ?, end_date = ?
                    WHERE id = ? AND partner_id = ?
                ");
                $stmt->execute([
                    $name, $scheduleType, $startTime, $endTime,
                    $daysOfWeek, $startDate, $endDate,
                    $scheduleId, $partnerId
                ]);
                response(true, ['id' => $scheduleId], "Agendamento atualizado!");
            } else {
                // Create
                $stmt = $db->prepare("
                    INSERT INTO om_menu_schedules
                    (partner_id, name, schedule_type, start_time, end_time, days_of_week, start_date, end_date)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $partnerId, $name, $scheduleType, $startTime, $endTime,
                    $daysOfWeek, $startDate, $endDate
                ]);
                response(true, ['id' => $db->lastInsertId()], "Agendamento criado!");
            }
        }

        if ($action === 'delete') {
            $scheduleId = intval($input['id'] ?? 0);
            if (!$scheduleId) response(false, null, "ID obrigatorio", 400);

            // Verify schedule belongs to partner BEFORE deleting links
            $stmt = $db->prepare("SELECT id FROM om_menu_schedules WHERE id = ? AND partner_id = ?");
            $stmt->execute([$scheduleId, $partnerId]);
            if (!$stmt->fetch()) response(false, null, "Agendamento nao encontrado", 404);

            // Remove links
            $stmt = $db->prepare("DELETE FROM om_product_schedule_links WHERE schedule_id = ?");
            $stmt->execute([$scheduleId]);

            // Soft delete schedule
            $stmt = $db->prepare("UPDATE om_menu_schedules SET status = '0' WHERE id = ? AND partner_id = ?");
            $stmt->execute([$scheduleId, $partnerId]);

            response(true, null, "Agendamento removido!");
        }

        if ($action === 'add_products') {
            $scheduleId = intval($input['schedule_id'] ?? 0);
            $productIds = $input['product_ids'] ?? [];

            if (!$scheduleId) response(false, null, "ID do agendamento obrigatorio", 400);
            if (empty($productIds)) response(false, null, "Produtos obrigatorios", 400);

            // Verify schedule belongs to partner
            $stmt = $db->prepare("SELECT id FROM om_menu_schedules WHERE id = ? AND partner_id = ?");
            $stmt->execute([$scheduleId, $partnerId]);
            if (!$stmt->fetch()) response(false, null, "Agendamento nao encontrado", 404);

            $added = 0;
            foreach ($productIds as $productId) {
                $productId = intval($productId);

                // Verify product belongs to partner
                $stmt = $db->prepare("SELECT product_id FROM om_market_products WHERE product_id = ? AND partner_id = ?");
                $stmt->execute([$productId, $partnerId]);
                if (!$stmt->fetch()) continue;

                // Insert with conflict handling to prevent race condition duplicates
                $stmt = $db->prepare("INSERT INTO om_product_schedule_links (schedule_id, product_id) VALUES (?, ?) ON CONFLICT (schedule_id, product_id) DO NOTHING");
                $stmt->execute([$scheduleId, $productId]);
                $added += $stmt->rowCount();
            }

            response(true, ['added' => $added], "Produtos adicionados!");
        }

        if ($action === 'remove_product') {
            $scheduleId = intval($input['schedule_id'] ?? 0);
            $productId = intval($input['product_id'] ?? 0);

            if (!$scheduleId || !$productId) response(false, null, "IDs obrigatorios", 400);

            // Verify schedule belongs to partner
            $stmt = $db->prepare("SELECT id FROM om_menu_schedules WHERE id = ? AND partner_id = ?");
            $stmt->execute([$scheduleId, $partnerId]);
            if (!$stmt->fetch()) response(false, null, "Agendamento nao encontrado", 404);

            $stmt = $db->prepare("DELETE FROM om_product_schedule_links WHERE schedule_id = ? AND product_id = ?");
            $stmt->execute([$scheduleId, $productId]);

            response(true, null, "Produto removido do agendamento!");
        }

        response(false, null, "Acao invalida", 400);
    }

    if ($method === 'DELETE') {
        $id = intval($_GET['id'] ?? 0);
        if (!$id) response(false, null, "ID obrigatorio", 400);

        // Verify schedule belongs to partner BEFORE deleting links
        $stmt = $db->prepare("SELECT id FROM om_menu_schedules WHERE id = ? AND partner_id = ?");
        $stmt->execute([$id, $partnerId]);
        if (!$stmt->fetch()) response(false, null, "Agendamento nao encontrado", 404);

        $stmt = $db->prepare("DELETE FROM om_product_schedule_links WHERE schedule_id = ?");
        $stmt->execute([$id]);

        $stmt = $db->prepare("UPDATE om_menu_schedules SET status = '0' WHERE id = ? AND partner_id = ?");
        $stmt->execute([$id, $partnerId]);

        response(true, null, "Agendamento removido!");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/menu-schedules] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
