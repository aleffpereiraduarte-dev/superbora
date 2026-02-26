<?php
/**
 * GET /api/mercado/partner/smart-coupons.php - Listar cupons avancados
 * POST /api/mercado/partner/smart-coupons.php - CRUD cupons
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
        $status = $_GET['status'] ?? 'active';

        $where = "partner_id = ?";
        $params = [$partnerId];

        if ($status === 'active') {
            $where .= " AND status = '1' AND (valid_until IS NULL OR valid_until >= NOW())";
        } elseif ($status === 'expired') {
            $where .= " AND (status = '0' OR valid_until < NOW())";
        }

        $stmt = $db->prepare("SELECT id, partner_id, code, name, discount_type, discount_value, min_order_value, max_discount, target_segment, usage_limit, per_customer_limit, usage_count, valid_from, valid_until, valid_hours_start, valid_hours_end, valid_days, status, created_at FROM om_partner_coupons_v2 WHERE $where ORDER BY created_at DESC");
        $stmt->execute($params);
        $coupons = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get usage stats for each coupon
        foreach ($coupons as &$coupon) {
            $coupon['remaining_uses'] = $coupon['usage_limit'] ? ($coupon['usage_limit'] - $coupon['usage_count']) : 'unlimited';
        }

        // Segment stats for smart recommendations
        $stmt = $db->prepare("
            SELECT
                COUNT(DISTINCT CASE WHEN order_count = 1 THEN customer_id END) as new_customers,
                COUNT(DISTINCT CASE WHEN order_count > 1 THEN customer_id END) as returning_customers,
                COUNT(DISTINCT CASE WHEN last_order < NOW() - INTERVAL '30 days' THEN customer_id END) as inactive_customers
            FROM (
                SELECT customer_id, COUNT(*) as order_count, MAX(created_at) as last_order
                FROM om_market_orders WHERE partner_id = ? GROUP BY customer_id
            ) sub
        ");
        $stmt->execute([$partnerId]);
        $segments = $stmt->fetch(PDO::FETCH_ASSOC);

        response(true, [
            'coupons' => $coupons,
            'segments' => $segments,
        ]);
    }

    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? 'create';

        if ($action === 'create' || $action === 'update') {
            $couponId = intval($input['id'] ?? 0);
            $code = strtoupper(trim($input['code'] ?? ''));
            $name = trim($input['name'] ?? '');
            $discountType = $input['discount_type'] ?? 'percent';
            $discountValue = floatval($input['discount_value'] ?? 10);
            $minOrderValue = floatval($input['min_order_value'] ?? 0);
            $maxDiscount = !empty($input['max_discount']) ? floatval($input['max_discount']) : null;
            $targetSegment = $input['target_segment'] ?? 'all';
            $usageLimit = !empty($input['usage_limit']) ? intval($input['usage_limit']) : null;
            $perCustomerLimit = intval($input['per_customer_limit'] ?? 1);
            $validFrom = !empty($input['valid_from']) ? $input['valid_from'] : null;
            $validUntil = !empty($input['valid_until']) ? $input['valid_until'] : null;
            $validHoursStart = !empty($input['valid_hours_start']) ? $input['valid_hours_start'] : null;
            $validHoursEnd = !empty($input['valid_hours_end']) ? $input['valid_hours_end'] : null;
            $validDays = !empty($input['valid_days']) ? (is_array($input['valid_days']) ? implode(',', $input['valid_days']) : $input['valid_days']) : null;

            if (empty($code) || empty($name)) {
                response(false, null, "Codigo e nome sao obrigatorios", 400);
            }

            if ($couponId > 0) {
                // Update
                $stmt = $db->prepare("
                    UPDATE om_partner_coupons_v2 SET
                        code = ?, name = ?, discount_type = ?, discount_value = ?,
                        min_order_value = ?, max_discount = ?, target_segment = ?,
                        usage_limit = ?, per_customer_limit = ?, valid_from = ?, valid_until = ?,
                        valid_hours_start = ?, valid_hours_end = ?, valid_days = ?
                    WHERE id = ? AND partner_id = ?
                ");
                $stmt->execute([
                    $code, $name, $discountType, $discountValue,
                    $minOrderValue, $maxDiscount, $targetSegment,
                    $usageLimit, $perCustomerLimit, $validFrom, $validUntil,
                    $validHoursStart, $validHoursEnd, $validDays,
                    $couponId, $partnerId
                ]);
                response(true, ['id' => $couponId], "Cupom atualizado!");
            } else {
                // Check duplicate code
                $stmt = $db->prepare("SELECT id FROM om_partner_coupons_v2 WHERE partner_id = ? AND code = ?");
                $stmt->execute([$partnerId, $code]);
                if ($stmt->fetch()) {
                    response(false, null, "Ja existe um cupom com este codigo", 400);
                }

                // Create
                $stmt = $db->prepare("
                    INSERT INTO om_partner_coupons_v2
                    (partner_id, code, name, discount_type, discount_value, min_order_value, max_discount,
                     target_segment, usage_limit, per_customer_limit, valid_from, valid_until,
                     valid_hours_start, valid_hours_end, valid_days)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $partnerId, $code, $name, $discountType, $discountValue,
                    $minOrderValue, $maxDiscount, $targetSegment,
                    $usageLimit, $perCustomerLimit, $validFrom, $validUntil,
                    $validHoursStart, $validHoursEnd, $validDays
                ]);
                response(true, ['id' => $db->lastInsertId()], "Cupom criado!");
            }
        }

        if ($action === 'toggle_status') {
            $couponId = intval($input['id'] ?? 0);
            if (!$couponId) response(false, null, "ID obrigatorio", 400);

            $stmt = $db->prepare("UPDATE om_partner_coupons_v2 SET status = CASE WHEN status::text = '1' THEN '0' ELSE '1' END WHERE id = ? AND partner_id = ?");
            $stmt->execute([$couponId, $partnerId]);
            response(true, null, "Status alterado!");
        }

        if ($action === 'delete') {
            $couponId = intval($input['id'] ?? 0);
            if (!$couponId) response(false, null, "ID obrigatorio", 400);

            $stmt = $db->prepare("DELETE FROM om_partner_coupons_v2 WHERE id = ? AND partner_id = ?");
            $stmt->execute([$couponId, $partnerId]);
            response(true, null, "Cupom removido!");
        }

        if ($action === 'generate_smart') {
            // Auto-generate smart coupons based on data
            $segment = $input['segment'] ?? 'inactive';
            $budget = floatval($input['budget'] ?? 100);

            // Validate budget bounds
            if ($budget < 10 || $budget > 10000) {
                response(false, null, "Orcamento deve ser entre R$10 e R$10.000", 400);
            }

            // SECURITY: Use cryptographically secure random for coupon code
            $code = strtoupper(substr($segment, 0, 3) . bin2hex(random_bytes(3)));
            $discountValue = $segment === 'new' ? 15 : ($segment === 'inactive' ? 20 : 10);

            // Cap usage_limit to reasonable bounds
            $usageLimit = min(1000, max(1, intval($budget / ($discountValue * 0.5))));

            $stmt = $db->prepare("
                INSERT INTO om_partner_coupons_v2
                (partner_id, code, name, discount_type, discount_value, target_segment, usage_limit, valid_until)
                VALUES (?, ?, ?, 'percent', ?, ?, ?, NOW() + INTERVAL '7 days')
            ");
            $stmt->execute([
                $partnerId,
                $code,
                "Smart Promo - " . ucfirst($segment),
                $discountValue,
                $segment,
                $usageLimit
            ]);

            response(true, ['code' => $code, 'discount' => $discountValue], "Cupom inteligente criado!");
        }

        response(false, null, "Acao invalida", 400);
    }

    if ($method === 'DELETE') {
        $id = intval($_GET['id'] ?? 0);
        if (!$id) response(false, null, "ID obrigatorio", 400);

        $stmt = $db->prepare("DELETE FROM om_partner_coupons_v2 WHERE id = ? AND partner_id = ?");
        $stmt->execute([$id, $partnerId]);
        response(true, null, "Cupom removido!");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/smart-coupons] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
