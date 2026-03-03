<?php
/**
 * /api/mercado/admin/parceiros.php
 *
 * iFood-level partner management endpoint.
 *
 * GET (no partner_id): List partners with stats and pagination.
 *   Params: search, status, category, page, limit
 *   Returns: partner list + stats summary
 *
 * GET (?partner_id=X): Full partner profile with orders, commission, ratings.
 *
 * POST: Admin actions (approve, suspend, reactivate, set_commission_rate)
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

        $partner_id = (int)($_GET['partner_id'] ?? 0);

        // ── Detail: full partner profile ──
        if ($partner_id > 0) {

            // Partner info
            $stmt = $db->prepare("
                SELECT partner_id, name, trade_name, email, phone, cnpj,
                       address, city, state, neighborhood, cep,
                       logo, banner, categoria, description, status,
                       is_open, open_time, close_time, weekly_hours,
                       delivery_fee, min_order, min_order_value,
                       delivery_time_min, delivery_time_max,
                       rating, commission_rate, store_type,
                       latitude, longitude,
                       created_at, updated_at
                FROM om_market_partners
                WHERE partner_id = ?
            ");
            $stmt->execute([$partner_id]);
            $partner = $stmt->fetch();
            if (!$partner) response(false, null, "Parceiro nao encontrado", 404);

            // Order stats aggregated
            $stmt = $db->prepare("
                SELECT COUNT(*) as total_orders,
                       COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','refunded') THEN total ELSE 0 END), 0) as gmv,
                       COALESCE(AVG(CASE WHEN status NOT IN ('cancelled','refunded') THEN total END), 0) as avg_ticket,
                       COALESCE(SUM(COALESCE(platform_fee, service_fee, 0)), 0) as total_commission,
                       MAX(created_at) as last_order_date,
                       SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
                       SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) as refunded_count,
                       SUM(CASE WHEN status = 'entregue' THEN 1 ELSE 0 END) as delivered_count
                FROM om_market_orders
                WHERE partner_id = ?
            ");
            $stmt->execute([$partner_id]);
            $order_stats = $stmt->fetch();

            // Format order stats
            $order_stats['total_orders'] = (int)$order_stats['total_orders'];
            $order_stats['gmv'] = round((float)$order_stats['gmv'], 2);
            $order_stats['avg_ticket'] = round((float)$order_stats['avg_ticket'], 2);
            $order_stats['total_commission'] = round((float)$order_stats['total_commission'], 2);
            $order_stats['cancelled_count'] = (int)$order_stats['cancelled_count'];
            $order_stats['refunded_count'] = (int)$order_stats['refunded_count'];
            $order_stats['delivered_count'] = (int)$order_stats['delivered_count'];

            // Recent orders (last 20)
            $stmt = $db->prepare("
                SELECT o.order_id, o.status, o.total, o.delivery_fee,
                       o.payment_method, o.created_at, o.delivered_at,
                       c.name as customer_name
                FROM om_market_orders o
                LEFT JOIN om_customers c ON o.customer_id = c.customer_id
                WHERE o.partner_id = ?
                ORDER BY o.created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$partner_id]);
            $recent_orders = $stmt->fetchAll();

            // Commission history (monthly aggregation, last 6 months)
            $stmt = $db->prepare("
                SELECT DATE_TRUNC('month', created_at) as month,
                       COUNT(*) as orders,
                       COALESCE(SUM(total), 0) as gmv,
                       COALESCE(SUM(COALESCE(platform_fee, service_fee, 0)), 0) as commission
                FROM om_market_orders
                WHERE partner_id = ?
                  AND status NOT IN ('cancelled','refunded')
                  AND created_at >= CURRENT_DATE - INTERVAL '6 months'
                GROUP BY DATE_TRUNC('month', created_at)
                ORDER BY month DESC
            ");
            $stmt->execute([$partner_id]);
            $commission_history = $stmt->fetchAll();

            foreach ($commission_history as &$ch) {
                $ch['orders'] = (int)$ch['orders'];
                $ch['gmv'] = round((float)$ch['gmv'], 2);
                $ch['commission'] = round((float)$ch['commission'], 2);
            }
            unset($ch);

            // Rating breakdown
            $rating_breakdown = ['avg' => 0, 'count' => 0, 'distribution' => []];
            try {
                $stmt = $db->prepare("
                    SELECT COALESCE(AVG(rating), 0) as avg_rating,
                           COUNT(*) as total_ratings,
                           SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as stars_5,
                           SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as stars_4,
                           SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as stars_3,
                           SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as stars_2,
                           SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as stars_1
                    FROM om_market_ratings
                    WHERE partner_id = ?
                ");
                $stmt->execute([$partner_id]);
                $rb = $stmt->fetch();
                $rating_breakdown = [
                    'avg' => round((float)$rb['avg_rating'], 2),
                    'count' => (int)$rb['total_ratings'],
                    'distribution' => [
                        '5' => (int)($rb['stars_5'] ?? 0),
                        '4' => (int)($rb['stars_4'] ?? 0),
                        '3' => (int)($rb['stars_3'] ?? 0),
                        '2' => (int)($rb['stars_2'] ?? 0),
                        '1' => (int)($rb['stars_1'] ?? 0),
                    ],
                ];
            } catch (Exception $e) {
                // Table may not exist
            }

            // Products count
            $products_count = 0;
            try {
                $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM om_market_products WHERE partner_id = ?");
                $stmt->execute([$partner_id]);
                $products_count = (int)$stmt->fetch()['cnt'];
            } catch (Exception $e) {
                // Table may not exist
            }

            // Admin notes
            $admin_notes = [];
            try {
                $stmt = $db->prepare("
                    SELECT id, admin_id, note, created_at
                    FROM om_admin_notes
                    WHERE entity_type = 'partner' AND entity_id = ?
                    ORDER BY created_at DESC
                    LIMIT 20
                ");
                $stmt->execute([$partner_id]);
                $admin_notes = $stmt->fetchAll();
            } catch (Exception $e) {
                // Table may not exist
            }

            response(true, [
                'partner' => $partner,
                'order_stats' => $order_stats,
                'recent_orders' => $recent_orders,
                'commission_history' => $commission_history,
                'rating_breakdown' => $rating_breakdown,
                'products_count' => $products_count,
                'admin_notes' => $admin_notes,
            ], "Detalhes do parceiro");
        }

        // ── List: search partners with pagination ──
        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $category = trim($_GET['category'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = ["1=1"];
        $params = [];

        if ($search !== '') {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $where[] = "(p.name ILIKE ? OR p.trade_name ILIKE ? OR p.email ILIKE ? OR p.cnpj ILIKE ?)";
            $s = "%{$escaped}%";
            $params = array_merge($params, [$s, $s, $s, $s]);
        }

        if ($status !== '') {
            // Support both text statuses and numeric legacy statuses
            $status_map = [
                'pending' => '0',
                'active' => '1',
                'rejected' => '2',
                'suspended' => '3',
                'banned' => '4',
            ];
            if (isset($status_map[$status])) {
                $where[] = "p.status = ?";
                $params[] = $status_map[$status];
            } elseif (in_array($status, ['0', '1', '2', '3', '4'], true)) {
                $where[] = "p.status = ?";
                $params[] = $status;
            }
        }

        if ($category !== '') {
            $escaped_cat = str_replace(['%', '_'], ['\\%', '\\_'], $category);
            $where[] = "p.categoria ILIKE ?";
            $params[] = "%{$escaped_cat}%";
        }

        $where_sql = implode(' AND ', $where);

        // Count
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_market_partners p WHERE {$where_sql}");
        $stmt->execute($params);
        $total = (int)$stmt->fetch()['total'];

        // Fetch partners with order stats
        $stmt = $db->prepare("
            SELECT p.partner_id, p.name, p.trade_name, p.email, p.phone, p.cnpj,
                   p.logo, p.city, p.state, p.categoria, p.status,
                   p.is_open, p.rating, p.commission_rate,
                   p.created_at,
                   COALESCE(os.total_orders, 0) as total_orders,
                   COALESCE(os.gmv, 0) as gmv,
                   COALESCE(os.avg_rating, 0) as avg_rating
            FROM om_market_partners p
            LEFT JOIN LATERAL (
                SELECT COUNT(*) as total_orders,
                       COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelled','refunded') THEN o.total ELSE 0 END), 0) as gmv,
                       COALESCE(AVG(CASE WHEN o.status NOT IN ('cancelled','refunded') THEN o.total END), 0) as avg_rating
                FROM om_market_orders o
                WHERE o.partner_id = p.partner_id
            ) os ON TRUE
            WHERE {$where_sql}
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $partners = $stmt->fetchAll();

        // Format numeric fields
        foreach ($partners as &$pt) {
            $pt['total_orders'] = (int)$pt['total_orders'];
            $pt['gmv'] = round((float)$pt['gmv'], 2);
            $pt['avg_rating'] = round((float)$pt['avg_rating'], 2);
        }
        unset($pt);

        // Stats summary
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM om_market_partners");
        $total_partners = (int)$stmt->fetch()['cnt'];

        $stmt = $db->query("SELECT COUNT(*) as cnt FROM om_market_partners WHERE status = '1'");
        $active_partners = (int)$stmt->fetch()['cnt'];

        $stmt = $db->query("SELECT COUNT(*) as cnt FROM om_market_partners WHERE status = '0'");
        $pending_approval = (int)$stmt->fetch()['cnt'];

        $stmt = $db->query("SELECT COUNT(*) as cnt FROM om_market_partners WHERE status IN ('3', '4')");
        $suspended_partners = (int)$stmt->fetch()['cnt'];

        response(true, [
            'partners' => $partners,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit),
            ],
            'stats' => [
                'total_partners' => $total_partners,
                'active' => $active_partners,
                'pending_approval' => $pending_approval,
                'suspended' => $suspended_partners,
            ],
        ], "Parceiros listados");
    }

    // =================== POST ===================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = getInput();
        $action = trim($input['action'] ?? '');

        if (!$action) response(false, null, "Campo 'action' obrigatorio", 400);

        // Helper: fetch partner or 404
        $get_partner = function(int $pid) use ($db): array {
            $stmt = $db->prepare("SELECT partner_id, name, status, commission_rate FROM om_market_partners WHERE partner_id = ?");
            $stmt->execute([$pid]);
            $p = $stmt->fetch();
            if (!$p) response(false, null, "Parceiro nao encontrado", 404);
            return $p;
        };

        // ── Approve partner ──
        if ($action === 'approve') {
            $partner_id = (int)($input['partner_id'] ?? 0);
            if (!$partner_id) response(false, null, "partner_id obrigatorio", 400);

            $partner = $get_partner($partner_id);
            $old_status = $partner['status'];

            if ($old_status === '1') response(false, null, "Parceiro ja esta ativo", 400);

            $stmt = $db->prepare("
                UPDATE om_market_partners
                SET status = '1', updated_at = NOW()
                WHERE partner_id = ?
            ");
            $stmt->execute([$partner_id]);

            om_audit()->log(
                OmAudit::ACTION_APPROVE,
                OmAudit::ENTITY_PARTNER,
                $partner_id,
                ['status' => $old_status],
                ['status' => '1'],
                "Parceiro '{$partner['name']}' aprovado"
            );

            response(true, [
                'partner_id' => $partner_id,
                'action' => 'approve',
                'old_status' => $old_status,
                'new_status' => '1',
                'admin_id' => $admin_id,
            ], "Parceiro aprovado com sucesso");
        }

        // ── Suspend partner ──
        if ($action === 'suspend') {
            $partner_id = (int)($input['partner_id'] ?? 0);
            $reason = strip_tags(trim($input['reason'] ?? ''));

            if (!$partner_id) response(false, null, "partner_id obrigatorio", 400);
            if (!$reason) response(false, null, "reason obrigatorio", 400);

            $partner = $get_partner($partner_id);
            $old_status = $partner['status'];

            if ($old_status === '3') response(false, null, "Parceiro ja esta suspenso", 400);

            $stmt = $db->prepare("
                UPDATE om_market_partners
                SET status = '3', is_open = 0, updated_at = NOW()
                WHERE partner_id = ?
            ");
            $stmt->execute([$partner_id]);

            om_audit()->log(
                OmAudit::ACTION_SUSPEND,
                OmAudit::ENTITY_PARTNER,
                $partner_id,
                ['status' => $old_status],
                ['status' => '3', 'reason' => $reason],
                "Parceiro '{$partner['name']}' suspenso. Motivo: {$reason}"
            );

            response(true, [
                'partner_id' => $partner_id,
                'action' => 'suspend',
                'old_status' => $old_status,
                'new_status' => '3',
                'admin_id' => $admin_id,
            ], "Parceiro suspenso com sucesso");
        }

        // ── Reactivate partner ──
        if ($action === 'reactivate') {
            $partner_id = (int)($input['partner_id'] ?? 0);
            if (!$partner_id) response(false, null, "partner_id obrigatorio", 400);

            $partner = $get_partner($partner_id);
            $old_status = $partner['status'];

            if ($old_status === '1') response(false, null, "Parceiro ja esta ativo", 400);

            $stmt = $db->prepare("
                UPDATE om_market_partners
                SET status = '1', updated_at = NOW()
                WHERE partner_id = ?
            ");
            $stmt->execute([$partner_id]);

            om_audit()->log(
                'partner_reactivate',
                OmAudit::ENTITY_PARTNER,
                $partner_id,
                ['status' => $old_status],
                ['status' => '1'],
                "Parceiro '{$partner['name']}' reativado"
            );

            response(true, [
                'partner_id' => $partner_id,
                'action' => 'reactivate',
                'old_status' => $old_status,
                'new_status' => '1',
                'admin_id' => $admin_id,
            ], "Parceiro reativado com sucesso");
        }

        // ── Set commission rate ──
        if ($action === 'set_commission_rate') {
            $partner_id = (int)($input['partner_id'] ?? 0);
            $commission_rate = isset($input['commission_rate']) ? (float)$input['commission_rate'] : null;

            if (!$partner_id) response(false, null, "partner_id obrigatorio", 400);
            if ($commission_rate === null || $commission_rate < 0 || $commission_rate > 100) {
                response(false, null, "commission_rate deve estar entre 0 e 100", 400);
            }

            $partner = $get_partner($partner_id);
            $old_rate = (float)($partner['commission_rate'] ?? 0);

            $stmt = $db->prepare("
                UPDATE om_market_partners
                SET commission_rate = ?, updated_at = NOW()
                WHERE partner_id = ?
            ");
            $stmt->execute([$commission_rate, $partner_id]);

            om_audit()->log(
                OmAudit::ACTION_UPDATE,
                OmAudit::ENTITY_PARTNER,
                $partner_id,
                ['commission_rate' => $old_rate],
                ['commission_rate' => $commission_rate],
                "Taxa de comissao do parceiro '{$partner['name']}' alterada de {$old_rate}% para {$commission_rate}%"
            );

            response(true, [
                'partner_id' => $partner_id,
                'action' => 'set_commission_rate',
                'old_rate' => $old_rate,
                'new_rate' => $commission_rate,
                'admin_id' => $admin_id,
            ], "Taxa de comissao atualizada com sucesso");
        }

        response(false, null, "Acao invalida: {$action}", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[admin/parceiros] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
