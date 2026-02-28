<?php
/**
 * GET/POST/DELETE /api/mercado/partner/promotions-advanced.php
 *
 * Gerenciamento de promocoes avancadas: Happy Hour, BOGO, Desconto por Quantidade
 *
 * GET: Listar promocoes. Params: type, status, page, limit
 * POST: Criar/atualizar promocao
 * DELETE: Desativar promocao
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

    // ===== GET: Listar promocoes avancadas =====
    if ($method === "GET") {
        $type = $_GET['type'] ?? '';
        $status = $_GET['status'] ?? '';
        $active_now = isset($_GET['active_now']) ? (bool)$_GET['active_now'] : false;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $where = ["partner_id = ?"];
        $params = [$partner_id];

        // Filtro por tipo
        if ($type && in_array($type, ['happy_hour', 'bogo', 'quantity_discount', 'combo_deal'])) {
            $where[] = "type = ?";
            $params[] = $type;
        }

        // Filtro por status
        if ($status !== '') {
            $where[] = "status = ?";
            $params[] = (int)$status;
        }

        // Filtro por promocoes ativas agora
        if ($active_now) {
            $spTz = new DateTimeZone('America/Sao_Paulo');
            $now = new DateTime('now', $spTz);
            $currentDate = $now->format('Y-m-d');
            $currentTime = $now->format('H:i:s');
            $dayOfWeek = ((int)$now->format('w') + 1); // 1=Dom, 2=Seg, ..., 7=Sab

            $where[] = "status = '1'";
            $where[] = "(valid_from IS NULL OR valid_from <= ?)";
            $where[] = "(valid_until IS NULL OR valid_until >= ?)";
            $params[] = $currentDate;
            $params[] = $currentDate;
        }

        $whereSQL = implode(" AND ", $where);

        // Contar total
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_promotions_advanced WHERE {$whereSQL}");
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        // Buscar promocoes
        $stmt = $db->prepare("
            SELECT id, partner_id, type, name, description, badge_text, badge_color,
                   discount_percent, start_time, end_time, days_of_week,
                   buy_quantity, get_quantity, get_discount_percent,
                   min_quantity, quantity_discount_percent,
                   applies_to, product_ids, category_ids,
                   valid_from, valid_until,
                   max_uses, max_uses_per_customer, current_uses,
                   priority, status, created_at, updated_at
            FROM om_promotions_advanced
            WHERE {$whereSQL}
            ORDER BY priority DESC, created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$limit, $offset]));
        $promotions = $stmt->fetchAll();

        // Formatar resposta
        $items = [];
        $spTz = new DateTimeZone('America/Sao_Paulo');
        $now = new DateTime('now', $spTz);
        $currentTime = $now->format('H:i:s');
        $dayOfWeek = ((int)$now->format('w') + 1);

        foreach ($promotions as $promo) {
            $isActiveNow = isPromotionActiveNow($promo, $currentTime, $dayOfWeek);

            $items[] = [
                "id" => (int)$promo['id'],
                "type" => $promo['type'],
                "name" => $promo['name'],
                "description" => $promo['description'],
                "badge_text" => $promo['badge_text'],
                "badge_color" => $promo['badge_color'],

                // Happy Hour
                "discount_percent" => (float)$promo['discount_percent'],
                "start_time" => $promo['start_time'],
                "end_time" => $promo['end_time'],
                "days_of_week" => $promo['days_of_week'],

                // BOGO
                "buy_quantity" => (int)$promo['buy_quantity'],
                "get_quantity" => (int)$promo['get_quantity'],
                "get_discount_percent" => (float)$promo['get_discount_percent'],

                // Quantity Discount
                "min_quantity" => (int)$promo['min_quantity'],
                "quantity_discount_percent" => (float)$promo['quantity_discount_percent'],

                // Aplicabilidade
                "applies_to" => $promo['applies_to'],
                "product_ids" => $promo['product_ids'] ? json_decode($promo['product_ids'], true) : [],
                "category_ids" => $promo['category_ids'] ? json_decode($promo['category_ids'], true) : [],

                // Validade
                "valid_from" => $promo['valid_from'],
                "valid_until" => $promo['valid_until'],

                // Limites
                "max_uses" => $promo['max_uses'] ? (int)$promo['max_uses'] : null,
                "max_uses_per_customer" => $promo['max_uses_per_customer'] ? (int)$promo['max_uses_per_customer'] : null,
                "current_uses" => (int)$promo['current_uses'],

                // Status
                "status" => (int)$promo['status'],
                "is_active_now" => $isActiveNow,
                "priority" => (int)$promo['priority'],
                "created_at" => $promo['created_at'],
                "updated_at" => $promo['updated_at'],
            ];
        }

        // Estatisticas
        $stmtStats = $db->prepare("
            SELECT
                type,
                COUNT(*) as total,
                SUM(CASE WHEN status = '1' THEN 1 ELSE 0 END) as active
            FROM om_promotions_advanced
            WHERE partner_id = ?
            GROUP BY type
        ");
        $stmtStats->execute([$partner_id]);
        $statsRaw = $stmtStats->fetchAll();

        $stats = [
            'happy_hour' => ['total' => 0, 'active' => 0],
            'bogo' => ['total' => 0, 'active' => 0],
            'quantity_discount' => ['total' => 0, 'active' => 0],
            'combo_deal' => ['total' => 0, 'active' => 0],
        ];
        foreach ($statsRaw as $s) {
            $stats[$s['type']] = [
                'total' => (int)$s['total'],
                'active' => (int)$s['active']
            ];
        }

        $pages = $total > 0 ? ceil($total / $limit) : 1;

        response(true, [
            "promotions" => $items,
            "stats" => $stats,
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
        $type = trim($input['type'] ?? '');
        $name = strip_tags(trim(substr($input['name'] ?? '', 0, 100)));
        $description = strip_tags(trim(substr($input['description'] ?? '', 0, 1000)));
        $badge_text = strip_tags(trim(substr($input['badge_text'] ?? '', 0, 50)));
        $badge_color = trim($input['badge_color'] ?? '#FF5722');

        // Validacoes basicas
        if (!in_array($type, ['happy_hour', 'bogo', 'quantity_discount', 'combo_deal'])) {
            response(false, null, "Tipo invalido. Use: happy_hour, bogo, quantity_discount, combo_deal", 400);
        }

        if (empty($name)) {
            response(false, null, "Nome da promocao obrigatorio", 400);
        }

        // Campos especificos por tipo
        $discount_percent = 0;
        $start_time = null;
        $end_time = null;
        $days_of_week = '1,2,3,4,5,6,7';
        $buy_quantity = 1;
        $get_quantity = 1;
        $get_discount_percent = 100;
        $min_quantity = 1;
        $quantity_discount_percent = 0;

        if ($type === 'happy_hour') {
            $discount_percent = max(0, min(100, (float)($input['discount_percent'] ?? 0)));
            $start_time = trim($input['start_time'] ?? '');
            $end_time = trim($input['end_time'] ?? '');
            $days_of_week = trim($input['days_of_week'] ?? '1,2,3,4,5,6,7');

            if ($discount_percent <= 0) {
                response(false, null, "Desconto deve ser maior que 0", 400);
            }
            if (empty($start_time) || empty($end_time)) {
                response(false, null, "Horario de inicio e fim obrigatorios para Happy Hour", 400);
            }

            // Gerar badge automatico
            if (empty($badge_text)) {
                $badge_text = "Happy Hour -{$discount_percent}%";
            }
        }

        elseif ($type === 'bogo') {
            $buy_quantity = max(1, (int)($input['buy_quantity'] ?? 1));
            $get_quantity = max(1, (int)($input['get_quantity'] ?? 1));
            $get_discount_percent = max(0, min(100, (float)($input['get_discount_percent'] ?? 100)));

            // Gerar badge automatico
            if (empty($badge_text)) {
                if ($get_discount_percent >= 100) {
                    $badge_text = "Leve " . ($buy_quantity + $get_quantity) . " Pague " . $buy_quantity;
                } else {
                    $badge_text = "Compre {$buy_quantity}, +{$get_quantity} com {$get_discount_percent}% OFF";
                }
            }
        }

        elseif ($type === 'quantity_discount') {
            $min_quantity = max(2, (int)($input['min_quantity'] ?? 2));
            $quantity_discount_percent = max(0, min(100, (float)($input['quantity_discount_percent'] ?? 0)));

            if ($quantity_discount_percent <= 0) {
                response(false, null, "Desconto deve ser maior que 0", 400);
            }

            // Gerar badge automatico
            if (empty($badge_text)) {
                $badge_text = "{$min_quantity}+ = -{$quantity_discount_percent}%";
            }
        }

        // Aplicabilidade
        $applies_to = trim($input['applies_to'] ?? 'all');
        if (!in_array($applies_to, ['all', 'category', 'products'])) {
            $applies_to = 'all';
        }

        $product_ids = null;
        $category_ids = null;

        if ($applies_to === 'products' && !empty($input['product_ids'])) {
            $product_ids = json_encode(array_map('intval', (array)$input['product_ids']));
        }

        if ($applies_to === 'category' && !empty($input['category_ids'])) {
            $category_ids = json_encode(array_map('intval', (array)$input['category_ids']));
        }

        // Validade
        $valid_from = !empty($input['valid_from']) ? trim($input['valid_from']) : null;
        $valid_until = !empty($input['valid_until']) ? trim($input['valid_until']) : null;

        // Limites
        $max_uses = !empty($input['max_uses']) ? (int)$input['max_uses'] : null;
        $max_uses_per_customer = !empty($input['max_uses_per_customer']) ? (int)$input['max_uses_per_customer'] : null;

        // Status e prioridade
        $status = isset($input['status']) ? (int)$input['status'] : 1;
        $priority = (int)($input['priority'] ?? 0);

        // ==== UPDATE ====
        if ($id > 0) {
            $stmtCheck = $db->prepare("SELECT id FROM om_promotions_advanced WHERE id = ? AND partner_id = ?");
            $stmtCheck->execute([$id, $partner_id]);
            if (!$stmtCheck->fetch()) {
                response(false, null, "Promocao nao encontrada", 404);
            }

            $stmt = $db->prepare("
                UPDATE om_promotions_advanced SET
                    type = ?, name = ?, description = ?, badge_text = ?, badge_color = ?,
                    discount_percent = ?, start_time = ?, end_time = ?, days_of_week = ?,
                    buy_quantity = ?, get_quantity = ?, get_discount_percent = ?,
                    min_quantity = ?, quantity_discount_percent = ?,
                    applies_to = ?, product_ids = ?, category_ids = ?,
                    valid_from = ?, valid_until = ?,
                    max_uses = ?, max_uses_per_customer = ?,
                    status = ?, priority = ?,
                    updated_at = NOW()
                WHERE id = ? AND partner_id = ?
            ");
            $stmt->execute([
                $type, $name, $description, $badge_text, $badge_color,
                $discount_percent, $start_time, $end_time, $days_of_week,
                $buy_quantity, $get_quantity, $get_discount_percent,
                $min_quantity, $quantity_discount_percent,
                $applies_to, $product_ids, $category_ids,
                $valid_from, $valid_until,
                $max_uses, $max_uses_per_customer,
                $status, $priority,
                $id, $partner_id
            ]);

            om_audit()->log(OmAudit::ACTION_UPDATE, 'promotion_advanced', $id, null,
                ['type' => $type, 'name' => $name],
                "Promocao avancada #{$id} atualizada: {$name}", 'partner', $partner_id);

            response(true, ["id" => $id], "Promocao atualizada com sucesso");
        }

        // ==== CREATE ====
        else {
            $stmt = $db->prepare("
                INSERT INTO om_promotions_advanced (
                    partner_id, type, name, description, badge_text, badge_color,
                    discount_percent, start_time, end_time, days_of_week,
                    buy_quantity, get_quantity, get_discount_percent,
                    min_quantity, quantity_discount_percent,
                    applies_to, product_ids, category_ids,
                    valid_from, valid_until,
                    max_uses, max_uses_per_customer,
                    status, priority, current_uses,
                    created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?, 0,
                    NOW()
                )
            ");
            $stmt->execute([
                $partner_id, $type, $name, $description, $badge_text, $badge_color,
                $discount_percent, $start_time, $end_time, $days_of_week,
                $buy_quantity, $get_quantity, $get_discount_percent,
                $min_quantity, $quantity_discount_percent,
                $applies_to, $product_ids, $category_ids,
                $valid_from, $valid_until,
                $max_uses, $max_uses_per_customer,
                $status, $priority
            ]);
            $newId = (int)$db->lastInsertId();

            om_audit()->log(OmAudit::ACTION_CREATE, 'promotion_advanced', $newId, null,
                ['type' => $type, 'name' => $name],
                "Promocao avancada criada: {$name} ({$type})", 'partner', $partner_id);

            response(true, ["id" => $newId], "Promocao criada com sucesso");
        }
    }

    // ===== PATCH: Toggle status =====
    elseif ($method === "PATCH") {
        $input = getInput();
        $id = (int)($input['id'] ?? $_GET['id'] ?? 0);

        if (!$id) {
            response(false, null, "ID da promocao obrigatorio", 400);
        }

        $stmtCheck = $db->prepare("SELECT id, status, name FROM om_promotions_advanced WHERE id = ? AND partner_id = ?");
        $stmtCheck->execute([$id, $partner_id]);
        $promo = $stmtCheck->fetch();

        if (!$promo) {
            response(false, null, "Promocao nao encontrada", 404);
        }

        $newStatus = $promo['status'] == 1 ? 0 : 1;

        $stmt = $db->prepare("UPDATE om_promotions_advanced SET status = ?, updated_at = NOW() WHERE id = ? AND partner_id = ?");
        $stmt->execute([$newStatus, $id, $partner_id]);

        $statusText = $newStatus == 1 ? 'ativada' : 'desativada';

        om_audit()->log(OmAudit::ACTION_UPDATE, 'promotion_advanced', $id, null,
            ['status' => $newStatus],
            "Promocao #{$id} {$statusText}", 'partner', $partner_id);

        response(true, ["id" => $id, "status" => $newStatus], "Promocao {$statusText}");
    }

    // ===== DELETE: Desativar/excluir promocao =====
    elseif ($method === "DELETE") {
        $id = (int)($_GET['id'] ?? 0);
        $permanent = isset($_GET['permanent']) && $_GET['permanent'] === '1';

        if (!$id) {
            $input = getInput();
            $id = (int)($input['id'] ?? 0);
            $permanent = (bool)($input['permanent'] ?? false);
        }

        if (!$id) {
            response(false, null, "ID da promocao obrigatorio", 400);
        }

        $stmtCheck = $db->prepare("SELECT id, name FROM om_promotions_advanced WHERE id = ? AND partner_id = ?");
        $stmtCheck->execute([$id, $partner_id]);
        $promo = $stmtCheck->fetch();

        if (!$promo) {
            response(false, null, "Promocao nao encontrada", 404);
        }

        if ($permanent) {
            // Excluir permanentemente
            $db->prepare("DELETE FROM om_promotions_advanced WHERE id = ? AND partner_id = ?")->execute([$id, $partner_id]);
            $action = "excluida permanentemente";
        } else {
            // Apenas desativar
            $db->prepare("UPDATE om_promotions_advanced SET status = '0', updated_at = NOW() WHERE id = ? AND partner_id = ?")->execute([$id, $partner_id]);
            $action = "desativada";
        }

        om_audit()->log(OmAudit::ACTION_DELETE, 'promotion_advanced', $id, null, null,
            "Promocao #{$id} ({$promo['name']}) {$action}", 'partner', $partner_id);

        response(true, ["id" => $id], "Promocao {$action}");
    }

    else {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[partner/promotions-advanced] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

/**
 * Verifica se uma promocao esta ativa no momento atual
 */
function isPromotionActiveNow(array $promo, string $currentTime, int $dayOfWeek): bool
{
    // Verificar status
    if ((int)$promo['status'] !== 1) {
        return false;
    }

    // Verificar datas de validade
    $today = date('Y-m-d');
    if ($promo['valid_from'] && $promo['valid_from'] > $today) {
        return false;
    }
    if ($promo['valid_until'] && $promo['valid_until'] < $today) {
        return false;
    }

    // Verificar limite de usos
    if ($promo['max_uses'] && (int)$promo['current_uses'] >= (int)$promo['max_uses']) {
        return false;
    }

    // Para Happy Hour, verificar horario e dia da semana
    if ($promo['type'] === 'happy_hour') {
        // Verificar dia da semana
        $allowedDays = array_map('intval', explode(',', $promo['days_of_week'] ?? '1,2,3,4,5,6,7'));
        if (!in_array($dayOfWeek, $allowedDays)) {
            return false;
        }

        // Verificar horario
        $startTime = $promo['start_time'];
        $endTime = $promo['end_time'];

        if ($startTime && $endTime) {
            // Suporte para horarios que cruzam meia-noite
            if ($endTime < $startTime) {
                // Ex: 22:00 - 02:00
                if ($currentTime < $startTime && $currentTime >= $endTime) {
                    return false;
                }
            } else {
                // Horario normal
                if ($currentTime < $startTime || $currentTime >= $endTime) {
                    return false;
                }
            }
        }
    }

    return true;
}
