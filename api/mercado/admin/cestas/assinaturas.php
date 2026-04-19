<?php
/**
 * /api/mercado/admin/cestas/assinaturas.php
 * Subscription management (admin only).
 *
 * GET  — List subscriptions with filters (status, type, city, search)
 * POST — Create subscription for a customer (admin-initiated)
 * PUT  — Update subscription status/details
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

    // ── GET: List subscriptions ──────────────────────────────────
    if ($method === 'GET') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $status = $_GET['status'] ?? null;
        $boxId = isset($_GET['box_id']) ? (int)$_GET['box_id'] : null;
        $frequency = $_GET['frequency'] ?? null;
        $city = $_GET['city'] ?? null;
        $paymentStatus = $_GET['payment_status'] ?? null;
        $search = $_GET['search'] ?? null;
        $sort = $_GET['sort'] ?? 'newest';

        $where = ["1=1"];
        $params = [];

        if ($status) {
            $where[] = "s.status = ?";
            $params[] = $status;
        }
        if ($boxId) {
            $where[] = "s.box_id = ?";
            $params[] = $boxId;
        }
        if ($frequency) {
            $where[] = "s.frequency = ?";
            $params[] = $frequency;
        }
        if ($city) {
            $where[] = "s.city ILIKE ?";
            $params[] = "%{$city}%";
        }
        if ($paymentStatus) {
            $where[] = "s.payment_status = ?";
            $params[] = $paymentStatus;
        }
        if ($search) {
            $where[] = "(c.name ILIKE ? OR c.email ILIKE ? OR c.phone ILIKE ?)";
            $s = "%{$search}%";
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }

        $whereSql = implode(' AND ', $where);

        $orderBy = match ($sort) {
            'oldest' => 's.created_at ASC',
            'name' => 'c.name ASC',
            'next_delivery' => 's.next_delivery ASC NULLS LAST',
            'price' => 'b.base_price DESC',
            default => 's.created_at DESC'
        };

        // Count total
        $countParams = $params;
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM om_box_subscriptions s
            LEFT JOIN om_customers c ON s.customer_id = c.customer_id
            LEFT JOIN om_subscription_boxes b ON s.box_id = b.id
            WHERE {$whereSql}
        ");
        $stmt->execute($countParams);
        $total = (int)$stmt->fetchColumn();

        // Fetch page
        $fetchParams = $params;
        $fetchParams[] = $limit;
        $fetchParams[] = $offset;

        $stmt = $db->prepare("
            SELECT s.id, s.customer_id, s.box_id, s.frequency, s.delivery_day,
                   s.status, s.payment_status, s.payment_method,
                   s.next_delivery, s.variant_id, s.delivery_time_pref,
                   s.delivery_instructions, s.city, s.neighborhood,
                   s.renewal_count, s.price_at_subscribe,
                   s.created_at, s.cancelled_at, s.paused_at, s.pause_reason,
                   c.name AS customer_name, c.email AS customer_email,
                   c.phone AS customer_phone,
                   b.name AS box_name, b.base_price, b.image AS box_image,
                   v.name AS variant_name, v.size_label AS variant_size,
                   (SELECT COUNT(*) FROM om_cesta_orders o WHERE o.subscription_id = s.id) AS total_orders,
                   (SELECT COUNT(*) FROM om_cesta_orders o WHERE o.subscription_id = s.id AND o.status = 'delivered') AS delivered_orders,
                   (SELECT COALESCE(SUM(p.amount), 0) FROM om_cesta_payments p WHERE p.subscription_id = s.id AND p.status = 'paid') AS total_paid
            FROM om_box_subscriptions s
            LEFT JOIN om_customers c ON s.customer_id = c.customer_id
            LEFT JOIN om_subscription_boxes b ON s.box_id = b.id
            LEFT JOIN om_cesta_variants v ON s.variant_id = v.id
            WHERE {$whereSql}
            ORDER BY {$orderBy}
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($fetchParams);
        $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($subs as &$sub) {
            $sub['base_price'] = (float)$sub['base_price'];
            $sub['price_at_subscribe'] = $sub['price_at_subscribe'] !== null ? (float)$sub['price_at_subscribe'] : null;
            $sub['total_orders'] = (int)$sub['total_orders'];
            $sub['delivered_orders'] = (int)$sub['delivered_orders'];
            $sub['total_paid'] = (float)$sub['total_paid'];
            $sub['renewal_count'] = (int)$sub['renewal_count'];
        }

        // Summary counts
        $stmt = $db->query("
            SELECT
                COUNT(*) FILTER (WHERE status = 'active') AS active_count,
                COUNT(*) FILTER (WHERE status = 'paused') AS paused_count,
                COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled_count,
                COUNT(*) AS total_count
            FROM om_box_subscriptions
        ");
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);

        response(true, [
            'subscriptions' => $subs,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => max(1, ceil($total / $limit)),
            ],
            'summary' => [
                'active' => (int)$summary['active_count'],
                'paused' => (int)$summary['paused_count'],
                'cancelled' => (int)$summary['cancelled_count'],
                'total' => (int)$summary['total_count'],
            ],
        ]);
    }

    // ── POST: Create subscription (admin-initiated) ──────────────
    if ($method === 'POST') {
        $input = getInput();

        $customerId = (int)($input['customer_id'] ?? 0);
        $boxId = (int)($input['box_id'] ?? 0);
        $frequency = strtolower(trim($input['frequency'] ?? 'weekly'));
        $deliveryDay = (int)($input['delivery_day'] ?? 1);
        $variantId = isset($input['variant_id']) ? (int)$input['variant_id'] : null;
        $addressId = isset($input['address_id']) ? (int)$input['address_id'] : null;
        $paymentMethod = trim($input['payment_method'] ?? 'credit_card');
        $timePref = trim($input['delivery_time_pref'] ?? 'morning');
        $instructions = trim($input['delivery_instructions'] ?? '');
        $city = trim($input['city'] ?? '');
        $neighborhood = trim($input['neighborhood'] ?? '');

        if (!$customerId || !$boxId) {
            response(false, null, 'customer_id e box_id obrigatorios', 400);
        }
        if (!in_array($frequency, ['daily', '3x_week', 'weekly', 'biweekly', 'monthly'], true)) {
            response(false, null, 'Frequencia invalida', 400);
        }

        // Verify box exists
        $stmt = $db->prepare("SELECT id, base_price FROM om_subscription_boxes WHERE id = ? AND is_active = true");
        $stmt->execute([$boxId]);
        $box = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$box) {
            response(false, null, 'Cesta nao encontrada', 404);
        }

        // Calculate next delivery
        $today = new DateTime();
        $todayDow = (int)$today->format('w');
        $daysAhead = ($deliveryDay - $todayDow + 7) % 7;
        if ($daysAhead === 0) $daysAhead = 7;
        $next = (clone $today)->modify("+{$daysAhead} days")->format('Y-m-d');

        $stmt = $db->prepare("
            INSERT INTO om_box_subscriptions
            (customer_id, box_id, frequency, delivery_day, address_id, payment_method,
             variant_id, delivery_time_pref, delivery_instructions, city, neighborhood,
             status, payment_status, next_delivery, price_at_subscribe)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 'pending', ?, ?)
            RETURNING id
        ");
        $stmt->execute([
            $customerId, $boxId, $frequency, $deliveryDay, $addressId,
            $paymentMethod, $variantId, $timePref,
            $instructions ?: null, $city ?: null, $neighborhood ?: null,
            $next, (float)$box['base_price']
        ]);
        $subId = (int)$stmt->fetchColumn();

        // Fire-and-forget welcome notification
        try {
            $stmtBox = $db->prepare("SELECT name FROM om_subscription_boxes WHERE id = ?");
            $stmtBox->execute([$boxId]);
            $boxName = (string)($stmtBox->fetchColumn() ?: 'SuperBora');
            cestaNotifyWelcome($db, $customerId, $boxName, $next, $subId);
        } catch (\Throwable $e) {
            error_log('[cestas/assinaturas] welcome notify error: ' . $e->getMessage());
        }

        response(true, ['subscription_id' => $subId], 'Assinatura criada com sucesso');
    }

    // ── PUT: Update subscription ─────────────────────────────────
    if ($method === 'PUT') {
        $input = getInput();
        $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
        if (!$id) {
            response(false, null, 'ID obrigatorio', 400);
        }

        // Action-based updates
        $action = trim($input['action'] ?? '');

        if ($action === 'pause') {
            $reason = trim($input['reason'] ?? '');
            $resumeDate = trim($input['resume_date'] ?? '') ?: null;
            $stmt = $db->prepare("
                UPDATE om_box_subscriptions
                SET status = 'paused', paused_at = NOW(), pause_reason = ?
                WHERE id = ? AND status = 'active'
            ");
            $stmt->execute([$reason ?: null, $id]);
            if ($stmt->rowCount() === 0) {
                response(false, null, 'Assinatura nao encontrada ou nao esta ativa', 404);
            }

            try {
                $stmtCust = $db->prepare("SELECT customer_id FROM om_box_subscriptions WHERE id = ?");
                $stmtCust->execute([$id]);
                $customerId = (int)($stmtCust->fetchColumn() ?: 0);
                if ($customerId) {
                    cestaNotifyPaused($db, $customerId, $resumeDate, $id);
                }
            } catch (\Throwable $e) {
                error_log('[cestas/assinaturas] pause notify error: ' . $e->getMessage());
            }

            response(true, null, 'Assinatura pausada');
        }

        if ($action === 'resume') {
            // Recalculate next delivery
            $stmt = $db->prepare("SELECT delivery_day FROM om_box_subscriptions WHERE id = ?");
            $stmt->execute([$id]);
            $sub = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sub) {
                response(false, null, 'Assinatura nao encontrada', 404);
            }

            $today = new DateTime();
            $todayDow = (int)$today->format('w');
            $daysAhead = ($sub['delivery_day'] - $todayDow + 7) % 7;
            if ($daysAhead === 0) $daysAhead = 7;
            $next = (clone $today)->modify("+{$daysAhead} days")->format('Y-m-d');

            $stmt = $db->prepare("
                UPDATE om_box_subscriptions
                SET status = 'active', paused_at = NULL, pause_reason = NULL, next_delivery = ?
                WHERE id = ? AND status = 'paused'
            ");
            $stmt->execute([$next, $id]);
            if ($stmt->rowCount() === 0) {
                response(false, null, 'Assinatura nao encontrada ou nao esta pausada', 404);
            }

            try {
                $stmtCust = $db->prepare("
                    SELECT s.customer_id, b.name AS box_name
                    FROM om_box_subscriptions s
                    LEFT JOIN om_subscription_boxes b ON s.box_id = b.id
                    WHERE s.id = ?
                ");
                $stmtCust->execute([$id]);
                $info = $stmtCust->fetch(PDO::FETCH_ASSOC);
                if ($info && (int)$info['customer_id']) {
                    cestaNotifyResumed($db, (int)$info['customer_id'], (string)($info['box_name'] ?? 'SuperBora'), $next, $id);
                }
            } catch (\Throwable $e) {
                error_log('[cestas/assinaturas] resume notify error: ' . $e->getMessage());
            }

            response(true, ['next_delivery' => $next], 'Assinatura reativada');
        }

        if ($action === 'cancel') {
            // Capture customer + box name BEFORE update so we can notify
            $stmtCust = $db->prepare("
                SELECT s.customer_id, b.name AS box_name
                FROM om_box_subscriptions s
                LEFT JOIN om_subscription_boxes b ON s.box_id = b.id
                WHERE s.id = ?
            ");
            $stmtCust->execute([$id]);
            $info = $stmtCust->fetch(PDO::FETCH_ASSOC);

            $stmt = $db->prepare("
                UPDATE om_box_subscriptions
                SET status = 'cancelled', cancelled_at = NOW()
                WHERE id = ? AND status IN ('active', 'paused')
            ");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                response(false, null, 'Assinatura nao encontrada ou ja cancelada', 404);
            }

            try {
                if ($info && (int)$info['customer_id']) {
                    cestaNotifyCancelled($db, (int)$info['customer_id'], (string)($info['box_name'] ?? 'sua cesta'), $id);
                }
            } catch (\Throwable $e) {
                error_log('[cestas/assinaturas] cancel notify error: ' . $e->getMessage());
            }

            response(true, null, 'Assinatura cancelada');
        }

        // General field update
        $fields = [];
        $params = [];
        $allowed = ['frequency', 'delivery_day', 'variant_id', 'delivery_time_pref',
                     'delivery_instructions', 'city', 'neighborhood', 'payment_method',
                     'payment_status', 'next_delivery'];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $input)) {
                $fields[] = "{$field} = ?";
                $params[] = $input[$field];
            }
        }

        if (!$fields) {
            response(false, null, 'Nenhum campo para atualizar', 400);
        }

        $params[] = $id;
        $stmt = $db->prepare("UPDATE om_box_subscriptions SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            response(false, null, 'Assinatura nao encontrada', 404);
        }

        // Payment-issue notification (when payment_status flips to a failure state)
        if (isset($input['payment_status'])) {
            $ps = strtolower((string)$input['payment_status']);
            if (in_array($ps, ['failed', 'overdue', 'past_due', 'chargeback'], true)) {
                try {
                    $stmtCust = $db->prepare("SELECT customer_id FROM om_box_subscriptions WHERE id = ?");
                    $stmtCust->execute([$id]);
                    $cid = (int)($stmtCust->fetchColumn() ?: 0);
                    if ($cid) {
                        cestaNotifyExpiring($db, $cid, 0, $id, 'payment');
                    }
                } catch (\Throwable $e) {
                    error_log('[cestas/assinaturas] payment notify error: ' . $e->getMessage());
                }
            }
        }

        response(true, null, 'Assinatura atualizada');
    }

    response(false, null, 'Metodo nao permitido', 405);
} catch (Exception $e) {
    error_log('[cestas/assinaturas] ' . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
