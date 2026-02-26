<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = $payload['uid'];

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $status = $_GET['status'] ?? null;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $where = ["1=1"];
        $params = [];
        if ($status !== null) {
            $where[] = "status = ?";
            $params[] = $status;
        }
        $where_sql = implode(' AND ', $where);

        $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_market_coupons WHERE {$where_sql}");
        $stmt->execute($params);
        $total = (int)$stmt->fetch()['total'];

        $stmt = $db->prepare("
            SELECT * FROM om_market_coupons
            WHERE {$where_sql}
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = (int)$limit;
        $params[] = (int)$offset;
        $stmt->execute($params);
        $cupons = $stmt->fetchAll();

        response(true, [
            'cupons' => $cupons,
            'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => ceil($total / $limit)]
        ], "Cupons listados");

    } elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
        $input = getInput();
        $code = strtoupper(trim($input['code'] ?? ''));
        $discount_type = $input['discount_type'] ?? 'percent';
        $discount_value = (float)($input['discount_value'] ?? 0);
        $min_order = (float)($input['min_order'] ?? 0);
        $max_uses = (int)($input['max_uses'] ?? 0);
        $start_date = $input['start_date'] ?? date('Y-m-d');
        $end_date = $input['end_date'] ?? date('Y-m-d', strtotime('+30 days'));
        $coupon_id = (int)($input['id'] ?? 0);

        if (!$code) {
            response(false, null, "code obrigatorio", 400);
        }

        // Validate discount_type
        $allowed_discount_types = ['percent', 'fixed'];
        if (!in_array($discount_type, $allowed_discount_types, true)) {
            response(false, null, "discount_type invalido. Permitidos: percent, fixed", 400);
        }

        // Validate discount_value is numeric and > 0
        if (!is_numeric($input['discount_value'] ?? '') || $discount_value <= 0) {
            response(false, null, "discount_value deve ser numerico e maior que zero", 400);
        }

        // Validate dates if provided
        if (!empty($input['start_date'])) {
            $d = DateTime::createFromFormat('Y-m-d', $input['start_date']);
            if (!$d || $d->format('Y-m-d') !== $input['start_date']) {
                response(false, null, "start_date invalido. Use formato YYYY-MM-DD", 400);
            }
        }
        if (!empty($input['end_date'])) {
            $d = DateTime::createFromFormat('Y-m-d', $input['end_date']);
            if (!$d || $d->format('Y-m-d') !== $input['end_date']) {
                response(false, null, "end_date invalido. Use formato YYYY-MM-DD", 400);
            }
        }

        // Validate max_uses if provided (must be numeric and > 0, or 0 for unlimited)
        if (isset($input['max_uses']) && $input['max_uses'] !== '' && $input['max_uses'] !== 0 && $input['max_uses'] !== '0') {
            if (!is_numeric($input['max_uses']) || (int)$input['max_uses'] < 0) {
                response(false, null, "max_uses deve ser numerico e >= 0", 400);
            }
        }

        if ($coupon_id > 0) {
            // Update existing
            $stmt = $db->prepare("
                UPDATE om_market_coupons
                SET code=?, discount_type=?, discount_value=?, min_order_value=?,
                    max_uses=?, valid_from=?, valid_until=?, status='1'
                WHERE id=?
            ");
            $stmt->execute([$code, $discount_type, $discount_value, $min_order, $max_uses, $start_date, $end_date, $coupon_id]);

            om_audit()->log('update', 'coupon', $coupon_id, null, ['code' => $code]);
            response(true, ['id' => $coupon_id], "Cupom atualizado");
        } else {
            // SECURITY: Check for duplicate active coupon code before creating
            $stmtDup = $db->prepare("SELECT id FROM om_market_coupons WHERE code = ? AND status = '1' LIMIT 1");
            $stmtDup->execute([$code]);
            if ($stmtDup->fetch()) {
                response(false, null, "Ja existe um cupom ativo com este codigo", 400);
            }

            // Create new
            $stmt = $db->prepare("
                INSERT INTO om_market_coupons (code, discount_type, discount_value, min_order_value, max_uses, current_uses, valid_from, valid_until, status)
                VALUES (?, ?, ?, ?, ?, 0, ?, ?, '1')
            ");
            $stmt->execute([$code, $discount_type, $discount_value, $min_order, $max_uses, $start_date, $end_date]);
            $new_id = (int)$db->lastInsertId();

            om_audit()->log('create', 'coupon', $new_id, null, ['code' => $code]);
            response(true, ['id' => $new_id], "Cupom criado");
        }

    } elseif ($_SERVER["REQUEST_METHOD"] === "DELETE") {
        // SECURITY: Only manager/rh can delete coupons
        $admin_role = $payload['data']['role'] ?? $payload['type'] ?? '';
        if (!in_array($admin_role, ['manager', 'rh', 'superadmin'])) {
            http_response_code(403);
            response(false, null, "Apenas manager ou RH podem remover cupons", 403);
        }

        $id = (int)($_GET['id'] ?? 0);
        if (!$id) response(false, null, "ID obrigatorio", 400);

        // Soft delete instead of hard delete
        $stmt = $db->prepare("UPDATE om_market_coupons SET status = '0' WHERE id = ?");
        $stmt->execute([$id]);

        om_audit()->log('delete', 'coupon', $id, null, ['action' => 'soft_delete']);
        response(true, null, "Cupom desativado");
    } else {
        response(false, null, "Metodo nao permitido", 405);
    }
} catch (Exception $e) {
    error_log("[admin/cupons] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
