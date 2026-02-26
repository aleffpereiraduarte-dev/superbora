<?php
/**
 * GET/POST/DELETE /api/mercado/partner/coupons.php
 * GET: List coupons for partner
 * POST: Create/update coupon. Body: {id?, code, discount_type, discount_value, min_order, max_uses, start_date, end_date}
 * DELETE: Deactivate coupon. Params: id
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];
    $method = $_SERVER["REQUEST_METHOD"];

    // ===== GET: Listar cupons =====
    if ($method === "GET") {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $status = $_GET['status'] ?? '';

        $where = ["partner_id = ?"];
        $params = [$partner_id];

        if ($status !== '') {
            $where[] = "status = ?";
            $params[] = $status;
        }

        $whereSQL = implode(" AND ", $where);

        $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_partner_coupons WHERE {$whereSQL}");
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        
        $stmt = $db->prepare("
            SELECT id, code, discount_type, discount_value, min_order,
                   max_uses, used_count, start_date, end_date,
                   status, created_at
            FROM om_partner_coupons
            WHERE {$whereSQL}
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$limit, $offset]));
        $coupons = $stmt->fetchAll();

        $items = [];
        foreach ($coupons as $c) {
            $items[] = [
                "id" => (int)$c['id'],
                "code" => $c['code'],
                "discount_type" => $c['discount_type'],
                "discount_value" => (float)$c['discount_value'],
                "min_order" => (float)$c['min_order'],
                "max_uses" => (int)$c['max_uses'],
                "used_count" => (int)$c['used_count'],
                "start_date" => $c['start_date'],
                "end_date" => $c['end_date'],
                "status" => $c['status'],
                "is_active" => $c['status'] === 'active' && ($c['end_date'] >= date('Y-m-d') || empty($c['end_date'])),
                "remaining_uses" => (int)$c['max_uses'] > 0 ? max(0, (int)$c['max_uses'] - (int)$c['used_count']) : null,
                "created_at" => $c['created_at'] ?? null
            ];
        }

        $pages = $total > 0 ? ceil($total / $limit) : 1;

        response(true, [
            "items" => $items,
            "pagination" => [
                "total" => $total,
                "page" => $page,
                "pages" => (int)$pages,
                "limit" => $limit
            ]
        ], "Cupons listados");
    }

    // ===== POST: Criar/atualizar cupom =====
    elseif ($method === "POST") {
        $input = getInput();

        $id = (int)($input['id'] ?? 0);
        $code = strtoupper(trim($input['code'] ?? ''));
        $discount_type = trim($input['discount_type'] ?? 'percent');
        $discount_value = (float)($input['discount_value'] ?? 0);
        $min_order = (float)($input['min_order'] ?? 0);
        $max_uses = (int)($input['max_uses'] ?? 0);
        $start_date = trim($input['start_date'] ?? date('Y-m-d'));
        $end_date = trim($input['end_date'] ?? '');

        // Validacoes
        if (empty($code)) {
            response(false, null, "Codigo do cupom obrigatorio", 400);
        }

        if (strlen($code) < 3 || strlen($code) > 30) {
            response(false, null, "Codigo deve ter entre 3 e 30 caracteres", 400);
        }

        if (!preg_match('/^[A-Z0-9_-]+$/', $code)) {
            response(false, null, "Codigo deve conter apenas letras, numeros, hifen e underscore", 400);
        }

        if (!in_array($discount_type, ['percent', 'fixed'])) {
            response(false, null, "Tipo de desconto invalido (percent ou fixed)", 400);
        }

        if ($discount_value <= 0) {
            response(false, null, "Valor do desconto deve ser maior que zero", 400);
        }

        if ($discount_type === 'percent' && $discount_value > 100) {
            response(false, null, "Desconto percentual nao pode ser maior que 100%", 400);
        }

        if ($discount_type === 'fixed' && $discount_value > 500) {
            response(false, null, "Desconto fixo nao pode ser maior que R$ 500,00", 400);
        }

        if (!empty($end_date) && $end_date < $start_date) {
            response(false, null, "Data fim deve ser maior que data inicio", 400);
        }

        // Wrap in transaction to prevent race condition on duplicate check
        $db->beginTransaction();
        try {
            // Verificar duplicidade de codigo (with lock to prevent race)
            $stmtDup = $db->prepare("
                SELECT id FROM om_partner_coupons
                WHERE partner_id = ? AND code = ? AND id != ? AND status = 'active'
                FOR UPDATE
            ");
            $stmtDup->execute([$partner_id, $code, $id]);
            if ($stmtDup->fetch()) {
                $db->rollBack();
                response(false, null, "Ja existe um cupom ativo com esse codigo", 400);
            }

            if ($id > 0) {
                // Update
                $stmtCheck = $db->prepare("SELECT id FROM om_partner_coupons WHERE id = ? AND partner_id = ? FOR UPDATE");
                $stmtCheck->execute([$id, $partner_id]);
                if (!$stmtCheck->fetch()) {
                    $db->rollBack();
                    response(false, null, "Cupom nao encontrado", 404);
                }

                $stmt = $db->prepare("
                    UPDATE om_partner_coupons
                    SET code = ?, discount_type = ?, discount_value = ?,
                        min_order = ?, max_uses = ?, start_date = ?,
                        end_date = ?, status = 'active'
                    WHERE id = ? AND partner_id = ?
                ");
                $stmt->execute([$code, $discount_type, $discount_value, $min_order, $max_uses,
                    $start_date, $end_date ?: null, $id, $partner_id]);

                $db->commit();

                om_audit()->log(OmAudit::ACTION_UPDATE, 'coupon', $id, null,
                    ['code' => $code, 'discount_type' => $discount_type, 'discount_value' => $discount_value],
                    "Cupom #{$id} atualizado", 'partner', $partner_id);

                response(true, ["id" => $id, "code" => $code], "Cupom atualizado");
            } else {
                // Create
                $stmt = $db->prepare("
                    INSERT INTO om_partner_coupons
                        (partner_id, code, discount_type, discount_value, min_order, max_uses, used_count, start_date, end_date, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 'active', NOW())
                    RETURNING id
                ");
                $stmt->execute([$partner_id, $code, $discount_type, $discount_value, $min_order, $max_uses,
                    $start_date, $end_date ?: null]);
                $newId = (int)$stmt->fetchColumn();

                $db->commit();

                om_audit()->log(OmAudit::ACTION_CREATE, 'coupon', $newId, null,
                    ['code' => $code, 'discount_type' => $discount_type, 'discount_value' => $discount_value],
                    "Cupom criado: {$code}", 'partner', $partner_id);

                response(true, ["id" => $newId, "code" => $code], "Cupom criado com sucesso");
            }
        } catch (Exception $txErr) {
            if ($db->inTransaction()) $db->rollBack();
            throw $txErr;
        }
    }

    // ===== DELETE: Desativar cupom =====
    elseif ($method === "DELETE") {
        $id = (int)($_GET['id'] ?? 0);

        if (!$id) {
            $input = getInput();
            $id = (int)($input['id'] ?? 0);
        }

        if (!$id) {
            response(false, null, "ID do cupom obrigatorio", 400);
        }

        $stmtCheck = $db->prepare("SELECT id, code FROM om_partner_coupons WHERE id = ? AND partner_id = ?");
        $stmtCheck->execute([$id, $partner_id]);
        $coupon = $stmtCheck->fetch();

        if (!$coupon) {
            response(false, null, "Cupom nao encontrado", 404);
        }

        $stmt = $db->prepare("UPDATE om_partner_coupons SET status = 'inactive' WHERE id = ? AND partner_id = ?");
        $stmt->execute([$id, $partner_id]);

        om_audit()->log(OmAudit::ACTION_DELETE, 'coupon', $id, null, null,
            "Cupom #{$id} ({$coupon['code']}) desativado", 'partner', $partner_id);

        response(true, ["id" => $id], "Cupom desativado");
    }

    else {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[partner/coupons] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
