<?php
/**
 * POST /api/mercado/admin/bulk-actions.php
 *
 * Mass operation endpoint for admin panel.
 *
 * Actions:
 *   bulk_cancel       — Cancel ALL non-final orders for a partner.
 *     Body: { action: "bulk_cancel", partner_id, reason, notify_customers?: bool }
 *
 *   maintenance_mode  — Toggle platform maintenance mode.
 *     Body: { action: "maintenance_mode", enabled: bool, message?: string }
 *
 *   mass_incident     — Create incident record and optionally notify affected customers.
 *     Body: { action: "mass_incident", title, message, affected_area?: string,
 *             notify_customers?: bool, partner_ids?: int[] }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
require_once __DIR__ . "/../helpers/notify.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') response(false, null, "Metodo nao permitido", 405);

    $input = getInput();
    $action = trim($input['action'] ?? '');

    if (!$action) response(false, null, "Campo 'action' obrigatorio", 400);

    // =================== BULK CANCEL ===================
    if ($action === 'bulk_cancel') {
        $partner_id = (int)($input['partner_id'] ?? 0);
        $reason = strip_tags(trim($input['reason'] ?? ''));
        $notify_customers = (bool)($input['notify_customers'] ?? true);

        if (!$partner_id) response(false, null, "partner_id obrigatorio", 400);
        if (!$reason) response(false, null, "reason obrigatorio", 400);

        // Verify partner exists
        $stmt = $db->prepare("SELECT partner_id, name FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$partner_id]);
        $partner = $stmt->fetch();
        if (!$partner) response(false, null, "Parceiro nao encontrado", 404);

        $final_statuses = ['entregue', 'delivered', 'cancelado', 'cancelled', 'refunded'];
        $placeholders = implode(',', array_fill(0, count($final_statuses), '?'));

        $db->beginTransaction();
        try {
            // Lock all non-final orders for this partner
            $stmt = $db->prepare("
                SELECT order_id, status, customer_id, order_number
                FROM om_market_orders
                WHERE partner_id = ? AND status NOT IN ({$placeholders})
                FOR UPDATE
            ");
            $stmt->execute(array_merge([$partner_id], $final_statuses));
            $orders = $stmt->fetchAll();

            if (empty($orders)) {
                $db->rollBack();
                response(true, ['cancelled_count' => 0], "Nenhum pedido ativo para cancelar");
            }

            $cancelled_count = 0;
            $affected_customers = [];

            foreach ($orders as $order) {
                $oid = (int)$order['order_id'];
                $orderNum = $order['order_number'] ?? "#{$oid}";

                // Cancel the order
                $stmt = $db->prepare("
                    UPDATE om_market_orders
                    SET status = 'cancelado',
                        cancelado_por = 'admin',
                        cancelamento_motivo = ?,
                        cancelamento_categoria = 'bulk_cancel',
                        cancelled_at = NOW(),
                        updated_at = NOW()
                    WHERE order_id = ?
                ");
                $stmt->execute([$reason, $oid]);

                // Timeline entry
                $desc = "Cancelamento em massa (parceiro {$partner['name']}). Motivo: {$reason}";
                $stmt = $db->prepare("
                    INSERT INTO om_order_timeline (order_id, status, description, actor_type, actor_id, created_at)
                    VALUES (?, 'cancelado', ?, 'admin', ?, NOW())
                ");
                $stmt->execute([$oid, $desc, $admin_id]);

                $cancelled_count++;

                // Track affected customers for notification
                $cid = (int)$order['customer_id'];
                if ($cid && $notify_customers) {
                    $affected_customers[$cid][] = $orderNum;
                }
            }

            // Audit log
            om_audit()->log(
                'bulk_cancel',
                'partner',
                $partner_id,
                null,
                ['cancelled_count' => $cancelled_count, 'reason' => $reason],
                "Cancelamento em massa de {$cancelled_count} pedidos do parceiro '{$partner['name']}'. Motivo: {$reason}"
            );

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        // Notifications (outside transaction — non-critical)
        if ($notify_customers && !empty($affected_customers)) {
            foreach ($affected_customers as $cid => $orderNums) {
                try {
                    $orderList = implode(', ', $orderNums);
                    notifyCustomer(
                        $db,
                        $cid,
                        "Pedidos cancelados",
                        "Seus pedidos ({$orderList}) de {$partner['name']} foram cancelados. Motivo: {$reason}",
                        "/pedidos",
                        ['type' => 'orders_cancelled', 'partner_id' => $partner_id]
                    );
                } catch (Exception $e) {
                    error_log("[bulk-actions/bulk_cancel] Notify customer {$cid} error: " . $e->getMessage());
                }
            }
        }

        response(true, [
            'cancelled_count' => $cancelled_count,
            'partner_id' => $partner_id,
            'partner_name' => $partner['name'],
            'notified_customers' => $notify_customers ? count($affected_customers) : 0,
        ], "{$cancelled_count} pedidos cancelados com sucesso");
    }

    // =================== MAINTENANCE MODE ===================
    if ($action === 'maintenance_mode') {
        $enabled = (bool)($input['enabled'] ?? false);
        $message = strip_tags(trim($input['message'] ?? ''));

        $value = json_encode([
            'enabled' => $enabled,
            'message' => $message ?: ($enabled ? 'Plataforma em manutencao. Voltamos em breve.' : ''),
            'updated_by' => $admin_id,
            'updated_at' => date('c'),
        ], JSON_UNESCAPED_UNICODE);

        // Upsert into om_platform_config
        $stmt = $db->prepare("
            SELECT id FROM om_platform_config WHERE config_key = 'maintenance_mode'
        ");
        $stmt->execute();
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $db->prepare("
                UPDATE om_platform_config
                SET config_value = ?, updated_by = ?, updated_at = NOW()
                WHERE config_key = 'maintenance_mode'
            ");
            $stmt->execute([$value, $admin_id]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO om_platform_config (config_key, config_value, updated_by, created_at, updated_at)
                VALUES ('maintenance_mode', ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$value, $admin_id]);
        }

        // Also update om_config for backward compatibility
        try {
            $stmt = $db->prepare("SELECT id FROM om_config WHERE chave = 'maintenance_mode'");
            $stmt->execute();
            if ($stmt->fetch()) {
                $db->prepare("UPDATE om_config SET valor = ?, updated_by = ?, updated_at = NOW() WHERE chave = 'maintenance_mode'")
                    ->execute([$enabled ? '1' : '0', $admin_id]);
            } else {
                $db->prepare("INSERT INTO om_config (chave, valor, updated_by, updated_at) VALUES ('maintenance_mode', ?, ?, NOW())")
                    ->execute([$enabled ? '1' : '0', $admin_id]);
            }
        } catch (Exception $e) {
            // om_config may not have this key — non-critical
            error_log("[bulk-actions/maintenance] om_config update error: " . $e->getMessage());
        }

        om_audit()->log(
            'maintenance_mode',
            'platform',
            0,
            null,
            ['enabled' => $enabled, 'message' => $message],
            "Modo manutencao " . ($enabled ? 'ATIVADO' : 'DESATIVADO') . " pelo admin"
        );

        response(true, [
            'enabled' => $enabled,
            'message' => $message,
        ], "Modo manutencao " . ($enabled ? "ativado" : "desativado"));
    }

    // =================== MASS INCIDENT ===================
    if ($action === 'mass_incident') {
        $title = strip_tags(trim($input['title'] ?? ''));
        $message = strip_tags(trim($input['message'] ?? ''));
        $affected_area = strip_tags(trim($input['affected_area'] ?? ''));
        $notify_customers = (bool)($input['notify_customers'] ?? false);
        $partner_ids = $input['partner_ids'] ?? [];

        if (!$title) response(false, null, "title obrigatorio", 400);
        if (!$message) response(false, null, "message obrigatorio", 400);

        // Sanitize partner_ids
        if (!is_array($partner_ids)) $partner_ids = [];
        $partner_ids = array_map('intval', $partner_ids);
        $partner_ids = array_filter($partner_ids, fn($id) => $id > 0);

        $db->beginTransaction();
        try {
            // Create incident record
            $stmt = $db->prepare("
                INSERT INTO om_platform_incidents
                    (title, message, affected_area, partner_ids, status, created_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'active', ?, NOW(), NOW())
                RETURNING id
            ");
            $partnerIdsJson = !empty($partner_ids) ? json_encode($partner_ids) : null;
            $stmt->execute([$title, $message, $affected_area ?: null, $partnerIdsJson, $admin_id]);
            $row = $stmt->fetch();
            $incident_id = $row ? (int)$row['id'] : 0;

            om_audit()->log(
                'mass_incident',
                'platform',
                $incident_id,
                null,
                ['title' => $title, 'affected_area' => $affected_area, 'partner_ids' => $partner_ids],
                "Incidente criado: {$title}"
            );

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        // Notify affected customers (outside transaction)
        $notified_count = 0;
        if ($notify_customers) {
            try {
                // Find customers with active orders in the affected area/partners
                $where_parts = ["o.status NOT IN ('entregue','delivered','cancelado','cancelled','refunded')"];
                $params = [];

                if (!empty($partner_ids)) {
                    $placeholders = implode(',', array_fill(0, count($partner_ids), '?'));
                    $where_parts[] = "o.partner_id IN ({$placeholders})";
                    $params = array_merge($params, $partner_ids);
                }

                if ($affected_area) {
                    $where_parts[] = "(o.delivery_address ILIKE ? OR p.city ILIKE ?)";
                    $area_like = "%{$affected_area}%";
                    $params[] = $area_like;
                    $params[] = $area_like;
                }

                $where_sql = implode(' AND ', $where_parts);

                $stmt = $db->prepare("
                    SELECT DISTINCT o.customer_id
                    FROM om_market_orders o
                    LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
                    WHERE {$where_sql}
                ");
                $stmt->execute($params);
                $customers = $stmt->fetchAll();

                foreach ($customers as $c) {
                    $cid = (int)$c['customer_id'];
                    if (!$cid) continue;
                    try {
                        notifyCustomer(
                            $db,
                            $cid,
                            $title,
                            $message,
                            "/pedidos",
                            ['type' => 'platform_incident', 'incident_id' => $incident_id]
                        );
                        $notified_count++;
                    } catch (Exception $e) {
                        error_log("[bulk-actions/incident] Notify customer {$cid} error: " . $e->getMessage());
                    }
                }
            } catch (Exception $e) {
                error_log("[bulk-actions/incident] Customer notification query error: " . $e->getMessage());
            }
        }

        response(true, [
            'incident_id' => $incident_id,
            'title' => $title,
            'affected_area' => $affected_area,
            'partner_ids' => $partner_ids,
            'notified_customers' => $notified_count,
        ], "Incidente criado com sucesso");
    }

    response(false, null, "Acao invalida: {$action}", 400);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[admin/bulk-actions] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
