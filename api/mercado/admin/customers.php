<?php
/**
 * /api/mercado/admin/customers.php
 *
 * iFood-level customer management endpoint.
 *
 * GET (no customer_id): Search/list customers with pagination and order stats.
 *   Params: search, status, page, limit, sort_by (name/orders/spent/date)
 *   Returns: customer list + stats summary
 *
 * GET (?customer_id=X): Full customer profile with orders, cashback, disputes, etc.
 *
 * POST: Admin actions (ban, unban, add_note, adjust_cashback)
 *   Requires action field + admin_id audit trail.
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = (int)$payload['uid'];

    // =================== GET ===================
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        $customer_id = (int)($_GET['customer_id'] ?? 0);

        // ── Detail: full customer profile ──
        if ($customer_id > 0) {

            // Customer info (exclude sensitive fields)
            $stmt = $db->prepare("
                SELECT customer_id, name, email, phone, cpf, foto,
                       is_active, status, suspended_until, suspension_reason,
                       created_at, last_login, updated_at
                FROM om_customers
                WHERE customer_id = ?
            ");
            $stmt->execute([$customer_id]);
            $customer = $stmt->fetch();
            if (!$customer) response(false, null, "Cliente nao encontrado", 404);

            // Recent orders (last 20)
            $stmt = $db->prepare("
                SELECT o.order_id, o.status, o.total, o.delivery_fee,
                       o.payment_method, o.created_at, o.delivered_at,
                       p.name as partner_name, p.logo as partner_logo
                FROM om_market_orders o
                LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
                WHERE o.customer_id = ?
                ORDER BY o.created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$customer_id]);
            $recent_orders = $stmt->fetchAll();

            // Order stats
            $stmt = $db->prepare("
                SELECT COUNT(*) as total_orders,
                       COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','refunded') THEN total ELSE 0 END), 0) as total_spent,
                       COALESCE(AVG(CASE WHEN status NOT IN ('cancelled','refunded') THEN total END), 0) as avg_ticket,
                       MAX(created_at) as last_order_date,
                       SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
                       SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) as refunded_count
                FROM om_market_orders
                WHERE customer_id = ?
            ");
            $stmt->execute([$customer_id]);
            $order_stats = $stmt->fetch();

            // Cashback balance
            $cashback_balance = 0.0;
            try {
                $stmt = $db->prepare("SELECT COALESCE(balance, 0) as balance FROM om_cashback_wallet WHERE customer_id = ?");
                $stmt->execute([$customer_id]);
                $row = $stmt->fetch();
                $cashback_balance = round((float)($row['balance'] ?? 0), 2);
            } catch (Exception $e) {
                // Table may not exist
            }

            // Support tickets
            $support_tickets = [];
            try {
                $stmt = $db->prepare("
                    SELECT id, subject, status, priority, created_at, resolved_at
                    FROM om_support_tickets
                    WHERE customer_id = ?
                    ORDER BY created_at DESC
                    LIMIT 20
                ");
                $stmt->execute([$customer_id]);
                $support_tickets = $stmt->fetchAll();
            } catch (Exception $e) {
                // Table may not exist
            }

            // Disputes
            $disputes = [];
            try {
                $stmt = $db->prepare("
                    SELECT dispute_id, order_id, category, subcategory, severity, status,
                           requested_amount, approved_amount, credit_amount,
                           created_at, resolved_at
                    FROM om_order_disputes
                    WHERE customer_id = ?
                    ORDER BY created_at DESC
                    LIMIT 20
                ");
                $stmt->execute([$customer_id]);
                $disputes = $stmt->fetchAll();
            } catch (Exception $e) {
                // Table may not exist
            }

            // Addresses
            $addresses = [];
            try {
                $stmt = $db->prepare("
                    SELECT id, label, street, number, complement, neighborhood,
                           city, state, cep, latitude, longitude, is_default
                    FROM om_customer_addresses
                    WHERE customer_id = ?
                    ORDER BY is_default DESC, id DESC
                ");
                $stmt->execute([$customer_id]);
                $addresses = $stmt->fetchAll();
            } catch (Exception $e) {
                // Table may not exist
            }

            // Push tokens count
            $push_tokens_count = 0;
            try {
                $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM om_market_push_tokens WHERE user_id = ? AND user_type = 'customer'");
                $stmt->execute([$customer_id]);
                $push_tokens_count = (int)$stmt->fetch()['cnt'];
            } catch (Exception $e) {
                // Table may not exist
            }

            // Admin notes
            $admin_notes = [];
            try {
                $stmt = $db->prepare("
                    SELECT id, admin_id, note, created_at
                    FROM om_admin_notes
                    WHERE entity_type = 'customer' AND entity_id = ?
                    ORDER BY created_at DESC
                    LIMIT 20
                ");
                $stmt->execute([$customer_id]);
                $admin_notes = $stmt->fetchAll();
            } catch (Exception $e) {
                // Table may not exist
            }

            response(true, [
                'customer' => $customer,
                'order_stats' => [
                    'total_orders' => (int)$order_stats['total_orders'],
                    'total_spent' => round((float)$order_stats['total_spent'], 2),
                    'avg_ticket' => round((float)$order_stats['avg_ticket'], 2),
                    'last_order_date' => $order_stats['last_order_date'],
                    'cancelled_count' => (int)$order_stats['cancelled_count'],
                    'refunded_count' => (int)$order_stats['refunded_count'],
                ],
                'cashback_balance' => $cashback_balance,
                'recent_orders' => $recent_orders,
                'support_tickets' => $support_tickets,
                'disputes' => $disputes,
                'addresses' => $addresses,
                'push_tokens_count' => $push_tokens_count,
                'admin_notes' => $admin_notes,
            ], "Detalhes do cliente");
        }

        // ── List: search customers with pagination ──
        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $sort_by = $_GET['sort_by'] ?? 'date';
        $offset = ($page - 1) * $limit;

        // Whitelist sort_by
        $order_by = match ($sort_by) {
            'name' => 'c.name ASC',
            'orders' => 'total_orders DESC',
            'spent' => 'total_spent DESC',
            'date' => 'c.created_at DESC',
            default => 'c.created_at DESC',
        };

        $where = ["1=1"];
        $params = [];

        if ($search !== '') {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $where[] = "(c.name ILIKE ? OR c.email ILIKE ? OR c.phone ILIKE ?)";
            $s = "%{$escaped}%";
            $params = array_merge($params, [$s, $s, $s]);
        }

        if ($status !== '') {
            $allowed_statuses = ['active', 'suspended', 'banned'];
            if (in_array($status, $allowed_statuses, true)) {
                $where[] = "c.status = ?";
                $params[] = $status;
            } elseif (in_array($status, ['0', '1'], true)) {
                $where[] = "c.is_active = ?";
                $params[] = (int)$status;
            }
        }

        $where_sql = implode(' AND ', $where);

        // Count
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_customers c WHERE {$where_sql}");
        $stmt->execute($params);
        $total = (int)$stmt->fetch()['total'];

        // Fetch customers with order stats
        $stmt = $db->prepare("
            SELECT c.customer_id, c.name, c.email, c.phone, c.cpf, c.foto,
                   c.is_active, c.status, c.created_at, c.last_login,
                   COALESCE(os.total_orders, 0) as total_orders,
                   COALESCE(os.total_spent, 0) as total_spent,
                   COALESCE(os.avg_ticket, 0) as avg_ticket,
                   os.last_order_date,
                   COALESCE(cb.balance, 0) as cashback_balance
            FROM om_customers c
            LEFT JOIN LATERAL (
                SELECT COUNT(*) as total_orders,
                       COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelled','refunded') THEN o.total ELSE 0 END), 0) as total_spent,
                       COALESCE(AVG(CASE WHEN o.status NOT IN ('cancelled','refunded') THEN o.total END), 0) as avg_ticket,
                       MAX(o.created_at) as last_order_date
                FROM om_market_orders o
                WHERE o.customer_id = c.customer_id
            ) os ON TRUE
            LEFT JOIN om_cashback_wallet cb ON cb.customer_id = c.customer_id
            WHERE {$where_sql}
            ORDER BY {$order_by}
            LIMIT ? OFFSET ?
        ");
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $customers = $stmt->fetchAll();

        // Format numeric fields
        foreach ($customers as &$cust) {
            $cust['total_orders'] = (int)$cust['total_orders'];
            $cust['total_spent'] = round((float)$cust['total_spent'], 2);
            $cust['avg_ticket'] = round((float)$cust['avg_ticket'], 2);
            $cust['cashback_balance'] = round((float)$cust['cashback_balance'], 2);
        }
        unset($cust);

        // Stats summary
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM om_customers");
        $total_customers = (int)$stmt->fetch()['cnt'];

        $stmt = $db->query("
            SELECT COUNT(*) as cnt FROM om_customers
            WHERE created_at >= DATE_TRUNC('month', CURRENT_DATE)
        ");
        $new_this_month = (int)$stmt->fetch()['cnt'];

        $stmt = $db->query("
            SELECT COUNT(DISTINCT customer_id) as cnt
            FROM om_market_orders
            WHERE created_at >= CURRENT_DATE - INTERVAL '30 days'
        ");
        $active_30d = (int)$stmt->fetch()['cnt'];

        response(true, [
            'customers' => $customers,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit),
            ],
            'stats' => [
                'total_customers' => $total_customers,
                'new_this_month' => $new_this_month,
                'active_30d' => $active_30d,
            ],
        ], "Clientes listados");
    }

    // =================== POST ===================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = getInput();
        $action = trim($input['action'] ?? '');

        if (!$action) response(false, null, "Campo 'action' obrigatorio", 400);

        // ── Ban customer ──
        if ($action === 'ban') {
            $customer_id = (int)($input['customer_id'] ?? 0);
            $reason = strip_tags(trim($input['reason'] ?? ''));

            if (!$customer_id) response(false, null, "customer_id obrigatorio", 400);
            if (!$reason) response(false, null, "reason obrigatorio", 400);

            $stmt = $db->prepare("SELECT customer_id, name, status FROM om_customers WHERE customer_id = ?");
            $stmt->execute([$customer_id]);
            $customer = $stmt->fetch();
            if (!$customer) response(false, null, "Cliente nao encontrado", 404);

            $old_status = $customer['status'] ?? 'active';
            if ($old_status === 'banned') response(false, null, "Cliente ja esta banido", 400);

            $stmt = $db->prepare("
                UPDATE om_customers
                SET status = 'banned',
                    suspended_until = NULL,
                    suspension_reason = ?,
                    is_active = '0',
                    updated_at = NOW()
                WHERE customer_id = ?
            ");
            $stmt->execute([$reason, $customer_id]);

            om_audit()->log(
                'customer_ban',
                'customer',
                $customer_id,
                ['status' => $old_status],
                ['status' => 'banned', 'reason' => $reason],
                "Cliente '{$customer['name']}' banido. Motivo: {$reason}"
            );

            response(true, [
                'customer_id' => $customer_id,
                'action' => 'ban',
                'old_status' => $old_status,
                'new_status' => 'banned',
                'admin_id' => $admin_id,
            ], "Cliente banido com sucesso");
        }

        // ── Unban customer ──
        if ($action === 'unban') {
            $customer_id = (int)($input['customer_id'] ?? 0);

            if (!$customer_id) response(false, null, "customer_id obrigatorio", 400);

            $stmt = $db->prepare("SELECT customer_id, name, status FROM om_customers WHERE customer_id = ?");
            $stmt->execute([$customer_id]);
            $customer = $stmt->fetch();
            if (!$customer) response(false, null, "Cliente nao encontrado", 404);

            $old_status = $customer['status'] ?? 'active';
            if ($old_status === 'active') response(false, null, "Cliente ja esta ativo", 400);

            $stmt = $db->prepare("
                UPDATE om_customers
                SET status = 'active',
                    suspended_until = NULL,
                    suspension_reason = NULL,
                    is_active = '1',
                    updated_at = NOW()
                WHERE customer_id = ?
            ");
            $stmt->execute([$customer_id]);

            om_audit()->log(
                'customer_unban',
                'customer',
                $customer_id,
                ['status' => $old_status],
                ['status' => 'active'],
                "Cliente '{$customer['name']}' reativado"
            );

            response(true, [
                'customer_id' => $customer_id,
                'action' => 'unban',
                'old_status' => $old_status,
                'new_status' => 'active',
                'admin_id' => $admin_id,
            ], "Cliente reativado com sucesso");
        }

        // ── Add note ──
        if ($action === 'add_note') {
            $customer_id = (int)($input['customer_id'] ?? 0);
            $note = strip_tags(trim($input['note'] ?? ''));

            if (!$customer_id) response(false, null, "customer_id obrigatorio", 400);
            if (!$note) response(false, null, "note obrigatorio", 400);

            $stmt = $db->prepare("SELECT customer_id, name FROM om_customers WHERE customer_id = ?");
            $stmt->execute([$customer_id]);
            $customer = $stmt->fetch();
            if (!$customer) response(false, null, "Cliente nao encontrado", 404);

            $stmt = $db->prepare("
                INSERT INTO om_admin_notes (entity_type, entity_id, admin_id, note, created_at)
                VALUES ('customer', ?, ?, ?, NOW())
            ");
            $stmt->execute([$customer_id, $admin_id, $note]);
            $note_id = (int)$db->lastInsertId();

            om_audit()->log(
                'customer_add_note',
                'customer',
                $customer_id,
                null,
                ['note_id' => $note_id, 'note' => substr($note, 0, 200)],
                "Nota adicionada ao cliente '{$customer['name']}'"
            );

            response(true, [
                'note_id' => $note_id,
                'customer_id' => $customer_id,
                'admin_id' => $admin_id,
            ], "Nota adicionada com sucesso");
        }

        // ── Adjust cashback ──
        if ($action === 'adjust_cashback') {
            $customer_id = (int)($input['customer_id'] ?? 0);
            $amount = (float)($input['amount'] ?? 0);
            $reason = strip_tags(trim($input['reason'] ?? ''));

            if (!$customer_id) response(false, null, "customer_id obrigatorio", 400);
            if ($amount == 0) response(false, null, "amount deve ser diferente de zero", 400);
            if (!$reason) response(false, null, "reason obrigatorio", 400);

            $stmt = $db->prepare("SELECT customer_id, name FROM om_customers WHERE customer_id = ?");
            $stmt->execute([$customer_id]);
            $customer = $stmt->fetch();
            if (!$customer) response(false, null, "Cliente nao encontrado", 404);

            $db->beginTransaction();
            try {
                // Ensure wallet exists
                $db->prepare("
                    INSERT INTO om_cashback_wallet (customer_id, balance, total_earned, total_used, total_expired)
                    VALUES (?, 0, 0, 0, 0)
                    ON CONFLICT (customer_id) DO NOTHING
                ")->execute([$customer_id]);

                // Lock wallet row
                $stmt = $db->prepare("SELECT balance FROM om_cashback_wallet WHERE customer_id = ? FOR UPDATE");
                $stmt->execute([$customer_id]);
                $wallet = $stmt->fetch();
                $old_balance = (float)($wallet['balance'] ?? 0);

                $type = $amount > 0 ? 'credit' : 'debit';
                $abs_amount = abs($amount);

                if ($type === 'debit' && $abs_amount > $old_balance) {
                    $db->rollBack();
                    response(false, null, "Saldo insuficiente. Saldo atual: R$ " . number_format($old_balance, 2, ',', '.'), 400);
                }

                $new_balance = $type === 'credit' ? $old_balance + $abs_amount : $old_balance - $abs_amount;

                // Insert transaction
                $stmt = $db->prepare("
                    INSERT INTO om_cashback_transactions (customer_id, type, amount, balance_after, description, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$customer_id, $type, $abs_amount, $new_balance, "Ajuste admin: {$reason}"]);

                // Update wallet balance
                if ($type === 'credit') {
                    $stmt = $db->prepare("
                        UPDATE om_cashback_wallet
                        SET balance = balance + ?, total_earned = total_earned + ?, updated_at = NOW()
                        WHERE customer_id = ?
                    ");
                } else {
                    $stmt = $db->prepare("
                        UPDATE om_cashback_wallet
                        SET balance = GREATEST(balance - ?, 0), total_used = total_used + ?, updated_at = NOW()
                        WHERE customer_id = ?
                    ");
                }
                $stmt->execute([$abs_amount, $abs_amount, $customer_id]);

                $db->commit();

                om_audit()->log(
                    'cashback_adjust',
                    'customer',
                    $customer_id,
                    ['balance' => $old_balance],
                    ['balance' => $new_balance, 'amount' => $amount, 'reason' => $reason],
                    "Cashback ajustado para cliente '{$customer['name']}': R$ {$amount}. Motivo: {$reason}"
                );

                response(true, [
                    'customer_id' => $customer_id,
                    'old_balance' => round($old_balance, 2),
                    'new_balance' => round($new_balance, 2),
                    'adjustment' => $amount,
                    'admin_id' => $admin_id,
                ], "Cashback ajustado com sucesso");

            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
        }

        response(false, null, "Acao invalida: {$action}", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[admin/customers] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
