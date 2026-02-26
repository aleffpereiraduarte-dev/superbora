<?php
/**
 * GET/POST/DELETE /api/mercado/partner/promotions.php
 * GET: List promotions. Params: status, page
 * POST: Create/update promotion. Body: {id?, product_id, discount_value, start_date, end_date}
 * DELETE: Deactivate promotion. Params: id
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

    // ===== GET: Listar promocoes =====
    if ($method === "GET") {
        $status = $_GET['status'] ?? '';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = ["p.partner_id = ?"];
        $params = [$partner_id];

        if ($status !== '') {
            $where[] = "p.active = ?";
            $params[] = (int)$status;
        }

        $whereSQL = implode(" AND ", $where);

        $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_partner_promotions p WHERE {$whereSQL}");
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        $params[] = $limit;
        $params[] = $offset;
        $stmt = $db->prepare("
            SELECT
                p.id,
                p.product_id,
                p.discount_type,
                p.discount_value,
                p.start_date,
                p.end_date,
                p.active as status,
                p.created_at,
                pb.name as product_name,
                pb.image as product_image,
                pp.price as original_price
            FROM om_partner_promotions p
            LEFT JOIN om_market_products_base pb ON pb.product_id = p.product_id
            LEFT JOIN om_market_products_price pp ON pp.product_id = p.product_id AND pp.partner_id = p.partner_id
            WHERE {$whereSQL}
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $promos = $stmt->fetchAll();

        $items = [];
        foreach ($promos as $promo) {
            $originalPrice = (float)($promo['original_price'] ?? 0);
            $discountedPrice = $originalPrice > 0
                ? round($originalPrice * (1 - (float)$promo['discount_value'] / 100), 2)
                : 0;

            $items[] = [
                "id" => (int)$promo['id'],
                "product_id" => (int)$promo['product_id'],
                "product_name" => $promo['product_name'],
                "product_image" => $promo['product_image'],
                "original_price" => $originalPrice,
                "discounted_price" => $discountedPrice,
                "discount_type" => $promo['discount_type'] ?? 'percent',
                "discount_value" => (float)$promo['discount_value'],
                "start_date" => $promo['start_date'],
                "end_date" => $promo['end_date'],
                "status" => (int)$promo['status'],
                "is_active" => (int)$promo['status'] == 1 && $promo['end_date'] >= date('Y-m-d H:i:s'),
                "created_at" => $promo['created_at']
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
        ], "Promocoes listadas");
    }

    // ===== POST: Criar/atualizar promocao =====
    elseif ($method === "POST") {
        $input = getInput();

        $id = (int)($input['id'] ?? 0);
        $product_id = (int)($input['product_id'] ?? 0);
        $discount_value = (float)($input['discount_value'] ?? 0);
        $start_date = trim($input['start_date'] ?? '');
        $end_date = trim($input['end_date'] ?? '');

        // Validacoes
        if (!$product_id) {
            response(false, null, "product_id obrigatorio", 400);
        }

        if ($discount_value <= 0 || $discount_value > 100) {
            response(false, null, "Desconto deve ser entre 0.01 e 100", 400);
        }

        if (empty($start_date) || empty($end_date)) {
            response(false, null, "Datas de inicio e fim sao obrigatorias", 400);
        }

        if ($end_date < $start_date) {
            response(false, null, "Data fim deve ser maior que data inicio", 400);
        }

        // Verificar que o produto pertence ao parceiro
        $stmtCheck = $db->prepare("
            SELECT id FROM om_market_products_price
            WHERE product_id = ? AND partner_id = ?
        ");
        $stmtCheck->execute([$product_id, $partner_id]);
        if (!$stmtCheck->fetch()) {
            response(false, null, "Produto nao vinculado ao parceiro", 400);
        }

        if ($id > 0) {
            // Update existing
            $stmtCheckPromo = $db->prepare("SELECT id FROM om_partner_promotions WHERE id = ? AND partner_id = ?");
            $stmtCheckPromo->execute([$id, $partner_id]);
            if (!$stmtCheckPromo->fetch()) {
                response(false, null, "Promocao nao encontrada", 404);
            }

            $stmt = $db->prepare("
                UPDATE om_partner_promotions
                SET product_id = ?, discount_type = 'percent', discount_value = ?, start_date = ?, end_date = ?, active = 1
                WHERE id = ? AND partner_id = ?
            ");
            $stmt->execute([$product_id, $discount_value, $start_date, $end_date, $id, $partner_id]);

            om_audit()->log(OmAudit::ACTION_UPDATE, 'promotion', $id, null,
                ['discount_value' => $discount_value, 'start_date' => $start_date, 'end_date' => $end_date],
                "Promocao #{$id} atualizada", 'partner', $partner_id);

            response(true, ["id" => $id], "Promocao atualizada");
        } else {
            // Create new
            $stmt = $db->prepare("
                INSERT INTO om_partner_promotions
                    (partner_id, product_id, title, discount_type, discount_value, start_date, end_date, active, created_at)
                VALUES (?, ?, 'Promocao', 'percent', ?, ?, ?, 1, NOW())
            ");
            $stmt->execute([$partner_id, $product_id, $discount_value, $start_date, $end_date]);
            $newId = (int)$db->lastInsertId();

            om_audit()->log(OmAudit::ACTION_CREATE, 'promotion', $newId, null,
                ['product_id' => $product_id, 'discount_value' => $discount_value],
                "Promocao criada", 'partner', $partner_id);

            response(true, ["id" => $newId], "Promocao criada");
        }
    }

    // ===== DELETE: Desativar promocao =====
    elseif ($method === "DELETE") {
        $id = (int)($_GET['id'] ?? 0);

        if (!$id) {
            // Tentar pegar do body
            $input = getInput();
            $id = (int)($input['id'] ?? 0);
        }

        if (!$id) {
            response(false, null, "ID da promocao obrigatorio", 400);
        }

        // Verificar ownership
        $stmtCheck = $db->prepare("SELECT id FROM om_partner_promotions WHERE id = ? AND partner_id = ?");
        $stmtCheck->execute([$id, $partner_id]);
        if (!$stmtCheck->fetch()) {
            response(false, null, "Promocao nao encontrada", 404);
        }

        $stmt = $db->prepare("UPDATE om_partner_promotions SET active = 0 WHERE id = ? AND partner_id = ?");
        $stmt->execute([$id, $partner_id]);

        om_audit()->log(OmAudit::ACTION_DELETE, 'promotion', $id, null, null,
            "Promocao #{$id} desativada", 'partner', $partner_id);

        response(true, ["id" => $id], "Promocao desativada");
    }

    else {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[partner/promotions] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
