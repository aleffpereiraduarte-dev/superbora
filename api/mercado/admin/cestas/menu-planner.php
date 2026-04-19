<?php
/**
 * /api/mercado/admin/cestas/menu-planner.php
 * Breakfast menu planner (admin only).
 *
 * GET ?view=available              — Basket types with cost, price, margin, recipe readiness
 * GET ?view=forecast&weeks=4       — Production forecast for upcoming weeks
 * GET ?view=templates              — Saved menu templates
 * POST ?action=template            — Save a weekly menu template
 *     { name, items: [{ weekday:0-6, cesta_type_id, variant_id?, quantity }] }
 * DELETE ?template_id=N            — Delete a template
 */
require_once __DIR__ . "/../../config/database.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requireAdmin();
    $adminId = (int)($payload['uid'] ?? $payload['user_id'] ?? 0);

    $db->exec("
        CREATE TABLE IF NOT EXISTS om_cesta_menu_templates (
            id SERIAL PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            description TEXT,
            is_active BOOLEAN DEFAULT true,
            created_by INTEGER,
            created_at TIMESTAMP DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS om_cesta_menu_template_items (
            id SERIAL PRIMARY KEY,
            template_id INTEGER NOT NULL REFERENCES om_cesta_menu_templates(id) ON DELETE CASCADE,
            weekday INTEGER NOT NULL,        -- 0=Sun..6=Sat
            cesta_type_id INTEGER NOT NULL,
            variant_id INTEGER,
            quantity INTEGER NOT NULL DEFAULT 1,
            notes TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_menu_template_items_tpl ON om_cesta_menu_template_items(template_id);
    ");

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // ── GET ──────────────────────────────────────────────────────
    if ($method === 'GET') {
        $view = $_GET['view'] ?? 'available';

        if ($view === 'available') {
            // All active cesta types with cost calc + recipe readiness
            $stmt = $db->query("
                SELECT b.id, b.name, b.description, b.category, b.base_price, b.image, b.badge,
                       (SELECT COUNT(*) FROM om_cesta_recipe r WHERE r.cesta_type_id = b.id) AS ingredient_count,
                       COALESCE((
                           SELECT SUM(r.quantity * i.cost_per_unit)
                           FROM om_cesta_recipe r
                           JOIN om_cesta_ingredientes i ON i.id = r.ingrediente_id
                           WHERE r.cesta_type_id = b.id
                             AND r.variant_id IS NULL
                             AND r.is_optional = false
                       ), 0) AS items_cost,
                       (SELECT COUNT(*) FROM om_box_subscriptions s WHERE s.box_id = b.id AND s.status = 'active') AS active_subscribers
                FROM om_subscription_boxes b
                WHERE b.is_active = true
                ORDER BY b.sort_order ASC, b.name ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Fixed assumptions for packaging + delivery cost
            $PACKAGING_COST = 3.50;
            $DELIVERY_COST  = 8.00;

            foreach ($rows as &$r) {
                $r['base_price']       = (float)$r['base_price'];
                $r['ingredient_count'] = (int)$r['ingredient_count'];
                $r['items_cost']       = round((float)$r['items_cost'], 2);
                $r['packaging_cost']   = $PACKAGING_COST;
                $r['delivery_cost']    = $DELIVERY_COST;
                $r['total_cost']       = round($r['items_cost'] + $PACKAGING_COST + $DELIVERY_COST, 2);
                $r['profit']           = round($r['base_price'] - $r['total_cost'], 2);
                $r['margin']           = $r['base_price'] > 0
                    ? round(($r['profit'] / $r['base_price']) * 100, 1) : 0;
                $r['active_subscribers'] = (int)$r['active_subscribers'];
                $r['recipe_ready']     = $r['ingredient_count'] > 0;
            }

            response(true, [
                'baskets' => $rows,
                'assumptions' => [
                    'packaging_cost' => $PACKAGING_COST,
                    'delivery_cost'  => $DELIVERY_COST,
                ],
            ]);
        }

        if ($view === 'forecast') {
            $weeks = max(1, min(12, (int)($_GET['weeks'] ?? 4)));
            $days = $weeks * 7;

            // Forecast by day = number of scheduled + preparing orders
            $stmt = $db->prepare("
                SELECT o.delivery_date AS date,
                       COUNT(*) AS total_orders,
                       COUNT(*) FILTER (WHERE o.status = 'scheduled') AS scheduled,
                       COUNT(*) FILTER (WHERE o.status IN ('preparing','ready')) AS in_progress,
                       COUNT(*) FILTER (WHERE o.status = 'delivered') AS delivered
                FROM om_cesta_orders o
                WHERE o.delivery_date BETWEEN CURRENT_DATE AND (CURRENT_DATE + (? || ' days')::INTERVAL)
                GROUP BY o.delivery_date
                ORDER BY o.delivery_date ASC
            ");
            $stmt->execute([$days]);
            $byDay = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Forecast by basket type
            $stmt = $db->prepare("
                SELECT b.id, b.name, b.base_price,
                       COUNT(o.id) AS total_orders,
                       COALESCE((
                           SELECT SUM(r.quantity * i.cost_per_unit) * COUNT(o.id)
                           FROM om_cesta_recipe r
                           JOIN om_cesta_ingredientes i ON i.id = r.ingrediente_id
                           WHERE r.cesta_type_id = b.id AND r.variant_id IS NULL AND r.is_optional = false
                       ), 0) AS projected_cost,
                       COALESCE(b.base_price * COUNT(o.id), 0) AS projected_revenue
                FROM om_subscription_boxes b
                LEFT JOIN om_cesta_orders o ON o.box_id = b.id
                    AND o.delivery_date BETWEEN CURRENT_DATE AND (CURRENT_DATE + (? || ' days')::INTERVAL)
                WHERE b.is_active = true
                GROUP BY b.id, b.name, b.base_price
                ORDER BY total_orders DESC
            ");
            $stmt->execute([$days]);
            $byType = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($byType as &$t) {
                $t['base_price']        = (float)$t['base_price'];
                $t['total_orders']      = (int)$t['total_orders'];
                $t['projected_cost']    = round((float)$t['projected_cost'], 2);
                $t['projected_revenue'] = round((float)$t['projected_revenue'], 2);
                $t['projected_profit']  = round($t['projected_revenue'] - $t['projected_cost'], 2);
            }

            // Shopping forecast: aggregate ingredient needs
            $stmt = $db->prepare("
                SELECT i.id, i.name, i.unit, i.category,
                       COALESCE(SUM(r.quantity), 0) AS total_needed,
                       COALESCE((SELECT SUM(e.quantity) FROM om_cesta_estoque e WHERE e.ingrediente_id = i.id), 0) AS current_stock,
                       i.cost_per_unit,
                       f.name AS fornecedor_name
                FROM om_cesta_ingredientes i
                LEFT JOIN om_cesta_fornecedores f ON f.id = i.fornecedor_id
                LEFT JOIN om_cesta_recipe r ON r.ingrediente_id = i.id AND r.variant_id IS NULL AND r.is_optional = false
                LEFT JOIN om_cesta_orders o ON o.box_id = r.cesta_type_id
                    AND o.delivery_date BETWEEN CURRENT_DATE AND (CURRENT_DATE + (? || ' days')::INTERVAL)
                    AND o.status NOT IN ('cancelled','failed')
                WHERE i.is_active = true
                GROUP BY i.id, i.name, i.unit, i.category, i.cost_per_unit, f.name
                HAVING COALESCE(SUM(r.quantity), 0) > 0
                ORDER BY i.category ASC, i.name ASC
            ");
            $stmt->execute([$days]);
            $shopping = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($shopping as &$s) {
                $s['total_needed']  = (float)$s['total_needed'];
                $s['current_stock'] = (float)$s['current_stock'];
                $s['to_purchase']   = max(0, round($s['total_needed'] - $s['current_stock'], 2));
                $s['cost_per_unit'] = (float)$s['cost_per_unit'];
                $s['purchase_cost'] = round($s['to_purchase'] * $s['cost_per_unit'], 2);
            }

            $totalRevenue = 0; $totalCost = 0; $totalOrders = 0;
            foreach ($byType as $t) {
                $totalRevenue += $t['projected_revenue'];
                $totalCost    += $t['projected_cost'];
                $totalOrders  += $t['total_orders'];
            }

            response(true, [
                'weeks'          => $weeks,
                'by_day'         => $byDay,
                'by_type'        => $byType,
                'shopping_list'  => $shopping,
                'summary' => [
                    'total_orders'      => $totalOrders,
                    'projected_revenue' => round($totalRevenue, 2),
                    'projected_cost'    => round($totalCost, 2),
                    'projected_profit'  => round($totalRevenue - $totalCost, 2),
                    'projected_margin'  => $totalRevenue > 0
                        ? round((($totalRevenue - $totalCost) / $totalRevenue) * 100, 1) : 0,
                ],
            ]);
        }

        if ($view === 'templates') {
            $stmt = $db->query("
                SELECT t.*,
                       (SELECT COUNT(*) FROM om_cesta_menu_template_items i WHERE i.template_id = t.id) AS items_count
                FROM om_cesta_menu_templates t
                WHERE t.is_active = true
                ORDER BY t.created_at DESC
            ");
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($templates as &$t) $t['items_count'] = (int)$t['items_count'];

            // Load items for each
            foreach ($templates as &$t) {
                $stmt = $db->prepare("
                    SELECT mti.*, b.name AS cesta_name, v.name AS variant_name
                    FROM om_cesta_menu_template_items mti
                    LEFT JOIN om_subscription_boxes b ON b.id = mti.cesta_type_id
                    LEFT JOIN om_cesta_variants v ON v.id = mti.variant_id
                    WHERE mti.template_id = ?
                    ORDER BY mti.weekday ASC
                ");
                $stmt->execute([$t['id']]);
                $t['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            response(true, ['templates' => $templates]);
        }

        response(false, null, 'View invalida. Use: available, forecast, templates', 400);
    }

    // ── POST: save template ──────────────────────────────────────
    if ($method === 'POST') {
        $action = $_GET['action'] ?? 'template';
        $input = getInput();

        if ($action === 'template') {
            $name = trim($input['name'] ?? '');
            $items = $input['items'] ?? [];
            if ($name === '') response(false, null, 'Nome do template obrigatorio', 400);
            if (!is_array($items) || count($items) === 0) response(false, null, 'Adicione pelo menos um item', 400);

            $db->beginTransaction();

            $stmt = $db->prepare("
                INSERT INTO om_cesta_menu_templates (name, description, created_by)
                VALUES (?, ?, ?) RETURNING id
            ");
            $stmt->execute([$name, trim($input['description'] ?? '') ?: null, $adminId ?: null]);
            $tplId = (int)$stmt->fetchColumn();

            $stmtItem = $db->prepare("
                INSERT INTO om_cesta_menu_template_items
                (template_id, weekday, cesta_type_id, variant_id, quantity, notes)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($items as $it) {
                $weekday = (int)($it['weekday'] ?? 0);
                $cestaTypeId = (int)($it['cesta_type_id'] ?? 0);
                $qty = max(1, (int)($it['quantity'] ?? 1));
                if (!$cestaTypeId) continue;
                $stmtItem->execute([
                    $tplId, $weekday, $cestaTypeId,
                    !empty($it['variant_id']) ? (int)$it['variant_id'] : null,
                    $qty,
                    trim($it['notes'] ?? '') ?: null,
                ]);
            }

            $db->commit();
            response(true, ['id' => $tplId], 'Template salvo');
        }

        response(false, null, 'Acao invalida', 400);
    }

    // ── DELETE template ──────────────────────────────────────────
    if ($method === 'DELETE') {
        $id = (int)($_GET['template_id'] ?? 0);
        if (!$id) response(false, null, 'template_id obrigatorio', 400);
        $stmt = $db->prepare("UPDATE om_cesta_menu_templates SET is_active = false WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) response(false, null, 'Template nao encontrado', 404);
        response(true, null, 'Template removido');
    }

    response(false, null, 'Metodo nao permitido', 405);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('[cestas/menu-planner] ' . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
