<?php
/**
 * /api/mercado/admin/cupons.php
 *
 * iFood-level coupon management endpoint.
 *
 * GET: List coupons with pagination and usage stats.
 *   Params: status (active/expired/disabled), partner_id, page, limit
 *   Returns: coupon list + stats summary
 *
 * POST (action=create): Create new coupon with optional auto-generated code.
 * POST (action=update): Update existing coupon.
 * POST (action=delete): Soft-delete (disable) coupon.
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

        $status = trim($_GET['status'] ?? '');
        $partner_id = isset($_GET['partner_id']) ? (int)$_GET['partner_id'] : null;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = ["1=1"];
        $params = [];

        // Filter by status
        if ($status !== '') {
            $allowed_statuses = ['active', 'expired', 'disabled', '0', '1'];
            if (in_array($status, $allowed_statuses, true)) {
                if ($status === 'active') {
                    $where[] = "(c.status = 'active' OR c.status = '1')";
                    $where[] = "(c.valid_until IS NULL OR c.valid_until >= CURRENT_DATE)";
                } elseif ($status === 'expired') {
                    $where[] = "c.valid_until < CURRENT_DATE";
                } elseif ($status === 'disabled') {
                    $where[] = "(c.status = '0' OR c.status = 'disabled' OR c.status = 'inactive')";
                } else {
                    $where[] = "c.status = ?";
                    $params[] = $status;
                }
            }
        }

        // Filter by partner
        if ($partner_id !== null && $partner_id > 0) {
            $where[] = "c.partner_id = ?";
            $params[] = $partner_id;
        }

        $where_sql = implode(' AND ', $where);

        // Count
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_market_coupons c WHERE {$where_sql}");
        $stmt->execute($params);
        $total = (int)$stmt->fetch()['total'];

        // Fetch coupons with usage stats
        $stmt = $db->prepare("
            SELECT c.*,
                   COALESCE(c.current_uses, 0) as times_used,
                   COALESCE(us.total_discount, 0) as total_discount_given,
                   p.name as partner_name
            FROM om_market_coupons c
            LEFT JOIN om_market_partners p ON c.partner_id = p.partner_id
            LEFT JOIN LATERAL (
                SELECT COALESCE(SUM(CASE
                    WHEN c.discount_type = 'percent' THEN o.total * (c.discount_value / 100)
                    ELSE c.discount_value
                END), 0) as total_discount
                FROM om_market_orders o
                WHERE o.coupon_id = c.id
                  AND o.status NOT IN ('cancelled','refunded')
            ) us ON TRUE
            WHERE {$where_sql}
            ORDER BY c.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $coupons = $stmt->fetchAll();

        // Format numeric fields
        foreach ($coupons as &$cp) {
            $cp['times_used'] = (int)$cp['times_used'];
            $cp['total_discount_given'] = round((float)$cp['total_discount_given'], 2);
        }
        unset($cp);

        // Stats summary
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM om_market_coupons");
        $total_coupons = (int)$stmt->fetch()['cnt'];

        $stmt = $db->query("
            SELECT COUNT(*) as cnt FROM om_market_coupons
            WHERE (status = 'active' OR status = '1')
              AND (valid_until IS NULL OR valid_until >= CURRENT_DATE)
        ");
        $active_coupons = (int)$stmt->fetch()['cnt'];

        $stmt = $db->query("
            SELECT COUNT(*) as cnt FROM om_market_coupons
            WHERE valid_until IS NOT NULL AND valid_until < CURRENT_DATE
        ");
        $expired_coupons = (int)$stmt->fetch()['cnt'];

        response(true, [
            'coupons' => $coupons,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit),
            ],
            'stats' => [
                'total_coupons' => $total_coupons,
                'active' => $active_coupons,
                'expired' => $expired_coupons,
            ],
        ], "Cupons listados");
    }

    // =================== POST ===================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = getInput();
        $action = trim($input['action'] ?? '');

        if (!$action) response(false, null, "Campo 'action' obrigatorio", 400);

        // ── Create coupon ──
        if ($action === 'create') {
            $code = strtoupper(trim($input['code'] ?? ''));
            $type = trim($input['type'] ?? 'percent');
            $value = isset($input['value']) ? (float)$input['value'] : 0;
            $min_order = (float)($input['min_order'] ?? 0);
            $max_uses = (int)($input['max_uses'] ?? 0);
            $expires_at = trim($input['expires_at'] ?? '');
            $partner_id = isset($input['partner_id']) ? (int)$input['partner_id'] : null;
            $max_discount_value = isset($input['max_discount_value']) ? (float)$input['max_discount_value'] : null;

            // Auto-generate code if not provided
            if ($code === '') {
                $code = 'SB' . strtoupper(bin2hex(random_bytes(4)));
            }

            // Validate type
            $allowed_types = ['percent', 'fixed'];
            if (!in_array($type, $allowed_types, true)) {
                response(false, null, "type invalido. Permitidos: percent, fixed", 400);
            }

            // Validate value
            if ($value <= 0) {
                response(false, null, "value deve ser maior que zero", 400);
            }

            if ($type === 'percent' && $value > 100) {
                response(false, null, "Desconto percentual nao pode ser maior que 100", 400);
            }

            // Validate expires_at format if provided
            if ($expires_at !== '') {
                $d = DateTime::createFromFormat('Y-m-d', $expires_at);
                if (!$d || $d->format('Y-m-d') !== $expires_at) {
                    response(false, null, "expires_at invalido. Use formato YYYY-MM-DD", 400);
                }
            }

            // Check for duplicate active code
            $stmtDup = $db->prepare("SELECT id FROM om_market_coupons WHERE code = ? AND (status = 'active' OR status = '1') LIMIT 1");
            $stmtDup->execute([$code]);
            if ($stmtDup->fetch()) {
                response(false, null, "Ja existe um cupom ativo com o codigo '{$code}'", 400);
            }

            $stmt = $db->prepare("
                INSERT INTO om_market_coupons
                    (code, discount_type, discount_value, min_order_value, max_discount_value,
                     max_uses, current_uses, valid_until, partner_id, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 'active', NOW())
            ");
            $stmt->execute([
                $code,
                $type,
                $value,
                $min_order,
                $max_discount_value,
                $max_uses,
                $expires_at !== '' ? $expires_at : null,
                $partner_id && $partner_id > 0 ? $partner_id : null,
            ]);
            $new_id = (int)$db->lastInsertId();

            om_audit()->log(
                OmAudit::ACTION_CREATE,
                'coupon',
                $new_id,
                null,
                ['code' => $code, 'type' => $type, 'value' => $value],
                "Cupom '{$code}' criado"
            );

            response(true, [
                'id' => $new_id,
                'code' => $code,
                'admin_id' => $admin_id,
            ], "Cupom criado com sucesso");
        }

        // ── Update coupon ──
        if ($action === 'update') {
            $coupon_id = (int)($input['coupon_id'] ?? $input['id'] ?? 0);
            if (!$coupon_id) response(false, null, "coupon_id obrigatorio", 400);

            // Fetch existing coupon
            $stmt = $db->prepare("SELECT * FROM om_market_coupons WHERE id = ?");
            $stmt->execute([$coupon_id]);
            $existing = $stmt->fetch();
            if (!$existing) response(false, null, "Cupom nao encontrado", 404);

            // Build update fields dynamically (only update provided fields)
            $updates = [];
            $update_params = [];

            if (isset($input['code'])) {
                $new_code = strtoupper(trim($input['code']));
                if ($new_code !== '') {
                    // Check duplicate (exclude self)
                    $stmtDup = $db->prepare("SELECT id FROM om_market_coupons WHERE code = ? AND id != ? AND (status = 'active' OR status = '1') LIMIT 1");
                    $stmtDup->execute([$new_code, $coupon_id]);
                    if ($stmtDup->fetch()) {
                        response(false, null, "Ja existe outro cupom ativo com o codigo '{$new_code}'", 400);
                    }
                    $updates[] = "code = ?";
                    $update_params[] = $new_code;
                }
            }

            if (isset($input['type'])) {
                $allowed_types = ['percent', 'fixed'];
                if (!in_array($input['type'], $allowed_types, true)) {
                    response(false, null, "type invalido", 400);
                }
                $updates[] = "discount_type = ?";
                $update_params[] = $input['type'];
            }

            if (isset($input['value'])) {
                $val = (float)$input['value'];
                if ($val <= 0) response(false, null, "value deve ser maior que zero", 400);
                $updates[] = "discount_value = ?";
                $update_params[] = $val;
            }

            if (isset($input['min_order'])) {
                $updates[] = "min_order_value = ?";
                $update_params[] = (float)$input['min_order'];
            }

            if (isset($input['max_uses'])) {
                $updates[] = "max_uses = ?";
                $update_params[] = (int)$input['max_uses'];
            }

            if (isset($input['expires_at'])) {
                $exp = trim($input['expires_at']);
                if ($exp !== '') {
                    $d = DateTime::createFromFormat('Y-m-d', $exp);
                    if (!$d || $d->format('Y-m-d') !== $exp) {
                        response(false, null, "expires_at invalido. Use formato YYYY-MM-DD", 400);
                    }
                    $updates[] = "valid_until = ?";
                    $update_params[] = $exp;
                } else {
                    $updates[] = "valid_until = NULL";
                }
            }

            if (isset($input['status'])) {
                $allowed = ['active', 'disabled', '0', '1'];
                if (in_array($input['status'], $allowed, true)) {
                    $updates[] = "status = ?";
                    $update_params[] = $input['status'];
                }
            }

            if (isset($input['max_discount_value'])) {
                $updates[] = "max_discount_value = ?";
                $update_params[] = (float)$input['max_discount_value'];
            }

            if (empty($updates)) {
                response(false, null, "Nenhum campo para atualizar", 400);
            }

            $update_sql = implode(', ', $updates);
            $update_params[] = $coupon_id;

            $stmt = $db->prepare("UPDATE om_market_coupons SET {$update_sql} WHERE id = ?");
            $stmt->execute($update_params);

            om_audit()->log(
                OmAudit::ACTION_UPDATE,
                'coupon',
                $coupon_id,
                ['code' => $existing['code']],
                $input,
                "Cupom #{$coupon_id} atualizado"
            );

            response(true, [
                'coupon_id' => $coupon_id,
                'admin_id' => $admin_id,
            ], "Cupom atualizado com sucesso");
        }

        // ── Delete (soft-delete) coupon ──
        if ($action === 'delete') {
            $coupon_id = (int)($input['coupon_id'] ?? $input['id'] ?? 0);
            if (!$coupon_id) response(false, null, "coupon_id obrigatorio", 400);

            // Verify coupon exists
            $stmt = $db->prepare("SELECT id, code, status FROM om_market_coupons WHERE id = ?");
            $stmt->execute([$coupon_id]);
            $existing = $stmt->fetch();
            if (!$existing) response(false, null, "Cupom nao encontrado", 404);

            // Soft-delete: set status to disabled
            $stmt = $db->prepare("UPDATE om_market_coupons SET status = '0' WHERE id = ?");
            $stmt->execute([$coupon_id]);

            om_audit()->log(
                OmAudit::ACTION_DELETE,
                'coupon',
                $coupon_id,
                ['status' => $existing['status']],
                ['status' => '0', 'action' => 'soft_delete'],
                "Cupom '{$existing['code']}' desativado"
            );

            response(true, [
                'coupon_id' => $coupon_id,
                'admin_id' => $admin_id,
            ], "Cupom desativado com sucesso");
        }

        response(false, null, "Acao invalida: {$action}", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[admin/cupons] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
