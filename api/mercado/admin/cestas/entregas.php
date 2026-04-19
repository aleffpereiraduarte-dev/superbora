<?php
/**
 * /api/mercado/admin/cestas/entregas.php
 * Delivery management (admin only).
 *
 * GET  — List deliveries for a date with status filters
 * PUT  — Update delivery status / assign driver / upload proof photo
 * POST — Batch operations (assign driver to zone, mark batch as delivered)
 */
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../helpers/cesta-notify.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requireAdmin();

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // ── GET: List deliveries ─────────────────────────────────────
    if ($method === 'GET') {
        $date = $_GET['date'] ?? date('Y-m-d');
        $status = $_GET['status'] ?? null;
        $driverId = isset($_GET['driver_id']) ? (int)$_GET['driver_id'] : null;
        $city = $_GET['city'] ?? null;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $where = ["o.delivery_date = ?"];
        $params = [$date];

        if ($status) {
            $where[] = "o.status = ?";
            $params[] = $status;
        }
        if ($driverId) {
            $where[] = "o.driver_id = ?";
            $params[] = $driverId;
        }
        if ($city) {
            $where[] = "s.city ILIKE ?";
            $params[] = "%{$city}%";
        }

        $whereSql = implode(' AND ', $where);

        // Count
        $countParams = $params;
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM om_cesta_orders o
            LEFT JOIN om_box_subscriptions s ON o.subscription_id = s.id
            WHERE {$whereSql}
        ");
        $stmt->execute($countParams);
        $total = (int)$stmt->fetchColumn();

        // Fetch
        $fetchParams = $params;
        $fetchParams[] = $limit;
        $fetchParams[] = $offset;

        $stmt = $db->prepare("
            SELECT o.id, o.subscription_id, o.customer_id, o.box_id, o.variant_id,
                   o.delivery_date, o.status, o.assembly_location, o.driver_id,
                   o.driver_name, o.delivery_photo, o.customer_confirmed,
                   o.customer_confirmed_at, o.delivered_at, o.notes,
                   o.created_at, o.updated_at,
                   c.name AS customer_name, c.phone AS customer_phone,
                   c.email AS customer_email,
                   b.name AS box_name, b.image AS box_image,
                   v.name AS variant_name,
                   s.delivery_time_pref, s.delivery_instructions,
                   s.city, s.neighborhood, s.address_id
            FROM om_cesta_orders o
            LEFT JOIN om_customers c ON o.customer_id = c.customer_id
            LEFT JOIN om_subscription_boxes b ON o.box_id = b.id
            LEFT JOIN om_cesta_variants v ON o.variant_id = v.id
            LEFT JOIN om_box_subscriptions s ON o.subscription_id = s.id
            WHERE {$whereSql}
            ORDER BY
                CASE o.status
                    WHEN 'out_for_delivery' THEN 1
                    WHEN 'ready' THEN 2
                    WHEN 'preparing' THEN 3
                    WHEN 'scheduled' THEN 4
                    WHEN 'delivered' THEN 5
                    WHEN 'failed' THEN 6
                    WHEN 'cancelled' THEN 7
                END,
                s.city ASC, s.neighborhood ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($fetchParams);
        $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Status summary for the date
        $stmt = $db->prepare("
            SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE status = 'scheduled') AS scheduled,
                COUNT(*) FILTER (WHERE status = 'preparing') AS preparing,
                COUNT(*) FILTER (WHERE status = 'ready') AS ready,
                COUNT(*) FILTER (WHERE status = 'out_for_delivery') AS out_for_delivery,
                COUNT(*) FILTER (WHERE status = 'delivered') AS delivered,
                COUNT(*) FILTER (WHERE status = 'failed') AS failed,
                COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled
            FROM om_cesta_orders
            WHERE delivery_date = ?
        ");
        $stmt->execute([$date]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);

        response(true, [
            'deliveries' => $deliveries,
            'summary' => $summary,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => max(1, ceil($total / $limit)),
            ],
            'date' => $date,
        ]);
    }

    // ── PUT: Update single delivery ──────────────────────────────
    if ($method === 'PUT') {
        $input = getInput();
        $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
        if (!$id) {
            response(false, null, 'ID obrigatorio', 400);
        }

        // Verify order exists (pull extra fields so we can notify after update)
        $stmt = $db->prepare("
            SELECT o.id, o.status, o.customer_id, o.box_id, o.driver_name,
                   b.name AS box_name
            FROM om_cesta_orders o
            LEFT JOIN om_subscription_boxes b ON o.box_id = b.id
            WHERE o.id = ?
        ");
        $stmt->execute([$id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            response(false, null, 'Pedido nao encontrado', 404);
        }

        $fields = [];
        $params = [];

        // Status update
        if (isset($input['status'])) {
            $validStatuses = ['scheduled', 'preparing', 'ready', 'out_for_delivery', 'delivered', 'failed', 'cancelled'];
            if (!in_array($input['status'], $validStatuses, true)) {
                response(false, null, 'Status invalido', 400);
            }
            $fields[] = "status = ?";
            $params[] = $input['status'];

            if ($input['status'] === 'delivered') {
                $fields[] = "delivered_at = NOW()";
            }
        }

        // Assign driver
        if (isset($input['driver_id'])) {
            $fields[] = "driver_id = ?";
            $params[] = (int)$input['driver_id'];
        }
        if (isset($input['driver_name'])) {
            $fields[] = "driver_name = ?";
            $params[] = trim($input['driver_name']);
        }

        // Delivery photo
        if (isset($input['delivery_photo'])) {
            $fields[] = "delivery_photo = ?";
            $params[] = trim($input['delivery_photo']);
        }

        // Customer confirmation
        if (isset($input['customer_confirmed'])) {
            $fields[] = "customer_confirmed = ?";
            $params[] = (bool)$input['customer_confirmed'] ? 'true' : 'false';
            if ($input['customer_confirmed']) {
                $fields[] = "customer_confirmed_at = NOW()";
            }
        }

        // Notes
        if (isset($input['notes'])) {
            $fields[] = "notes = ?";
            $params[] = trim($input['notes']);
        }

        // Assembly location
        if (isset($input['assembly_location'])) {
            $fields[] = "assembly_location = ?";
            $params[] = trim($input['assembly_location']);
        }

        if (!$fields) {
            response(false, null, 'Nenhum campo para atualizar', 400);
        }

        $fields[] = "updated_at = NOW()";
        $params[] = $id;

        $stmt = $db->prepare("UPDATE om_cesta_orders SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($params);

        // Fire customer notifications for key status transitions
        if (isset($input['status'])) {
            $newStatus = $input['status'];
            $prevStatus = $order['status'] ?? '';
            $customerId = (int)($order['customer_id'] ?? 0);
            $boxName    = (string)($order['box_name'] ?? 'SuperBora');

            if ($customerId && $newStatus !== $prevStatus) {
                try {
                    if ($newStatus === 'out_for_delivery') {
                        $driverName = trim((string)($input['driver_name'] ?? $order['driver_name'] ?? ''));
                        // ETA default: 30min; could be refined via eta-calculator later
                        cestaNotifyOnTheWay($db, $customerId, $boxName, 30, $id, $driverName);
                    } elseif ($newStatus === 'delivered') {
                        cestaNotifyDelivered($db, $customerId, $boxName, $id);
                    }
                } catch (\Throwable $e) {
                    error_log('[cestas/entregas] status notify error: ' . $e->getMessage());
                }
            }
        }

        response(true, null, 'Entrega atualizada');
    }

    // ── POST: Batch operations ───────────────────────────────────
    if ($method === 'POST') {
        $input = getInput();
        $action = trim($input['action'] ?? '');

        // Batch assign driver to all orders in a zone/date
        if ($action === 'assign_driver_zone') {
            $date = trim($input['date'] ?? '');
            $city = trim($input['city'] ?? '');
            $neighborhood = trim($input['neighborhood'] ?? '');
            $driverId = (int)($input['driver_id'] ?? 0);
            $driverName = trim($input['driver_name'] ?? '');

            if (!$date || !$driverName) {
                response(false, null, 'date e driver_name obrigatorios', 400);
            }

            $where = ["o.delivery_date = ?", "o.status NOT IN ('delivered', 'cancelled', 'failed')"];
            $params = [$date];

            if ($city) {
                $where[] = "s.city ILIKE ?";
                $params[] = "%{$city}%";
            }
            if ($neighborhood) {
                $where[] = "s.neighborhood ILIKE ?";
                $params[] = "%{$neighborhood}%";
            }

            $params[] = $driverId ?: null;
            $params[] = $driverName;

            $whereSql = implode(' AND ', $where);
            $stmt = $db->prepare("
                UPDATE om_cesta_orders o SET driver_id = ?, driver_name = ?, updated_at = NOW()
                FROM om_box_subscriptions s
                WHERE o.subscription_id = s.id AND {$whereSql}
            ");
            $stmt->execute($params);

            response(true, ['updated' => $stmt->rowCount()], 'Motorista atribuido');
        }

        // Batch update status
        if ($action === 'batch_status') {
            $orderIds = $input['order_ids'] ?? [];
            $newStatus = trim($input['status'] ?? '');

            if (empty($orderIds) || !$newStatus) {
                response(false, null, 'order_ids e status obrigatorios', 400);
            }

            $validStatuses = ['scheduled', 'preparing', 'ready', 'out_for_delivery', 'delivered', 'failed', 'cancelled'];
            if (!in_array($newStatus, $validStatuses, true)) {
                response(false, null, 'Status invalido', 400);
            }

            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $intIds = array_map('intval', $orderIds);
            $params = [$newStatus];
            if ($newStatus === 'delivered') {
                $stmt = $db->prepare("UPDATE om_cesta_orders SET status = ?, delivered_at = NOW(), updated_at = NOW() WHERE id IN ({$placeholders})");
            } else {
                $stmt = $db->prepare("UPDATE om_cesta_orders SET status = ?, updated_at = NOW() WHERE id IN ({$placeholders})");
            }
            $params = array_merge($params, $intIds);
            $stmt->execute($params);

            // Batch notify for key transitions (out_for_delivery / delivered)
            if (in_array($newStatus, ['out_for_delivery', 'delivered'], true)) {
                try {
                    $notifyStmt = $db->prepare("
                        SELECT o.id, o.customer_id, o.driver_name, b.name AS box_name
                        FROM om_cesta_orders o
                        LEFT JOIN om_subscription_boxes b ON o.box_id = b.id
                        WHERE o.id IN ({$placeholders})
                    ");
                    $notifyStmt->execute($intIds);
                    foreach ($notifyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $cid = (int)($row['customer_id'] ?? 0);
                        if (!$cid) continue;
                        $boxName = (string)($row['box_name'] ?? 'SuperBora');
                        try {
                            if ($newStatus === 'out_for_delivery') {
                                cestaNotifyOnTheWay($db, $cid, $boxName, 30, (int)$row['id'], (string)($row['driver_name'] ?? ''));
                            } else {
                                cestaNotifyDelivered($db, $cid, $boxName, (int)$row['id']);
                            }
                        } catch (\Throwable $e) {
                            error_log('[cestas/entregas] batch notify error: ' . $e->getMessage());
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('[cestas/entregas] batch notify fetch error: ' . $e->getMessage());
                }
            }

            response(true, ['updated' => $stmt->rowCount()], 'Status atualizado em lote');
        }

        response(false, null, 'Acao invalida', 400);
    }

    response(false, null, 'Metodo nao permitido', 405);
} catch (Exception $e) {
    error_log('[cestas/entregas] ' . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
