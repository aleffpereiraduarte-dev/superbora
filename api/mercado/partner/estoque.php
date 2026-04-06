<?php
/**
 * /api/mercado/partner/estoque.php
 * Smart Inventory Management with AI Analysis
 *
 * GET                          - Dashboard summary (stats, alerts, top sellers)
 * GET    action=products       - Full product list with stock, velocity, forecast
 * GET    action=movements      - Stock movement history
 * GET    action=alerts         - Products below minimum stock
 * POST   action=adjust         - Set absolute stock quantity
 * POST   action=movement       - Record stock movement (entrada/saida)
 * POST   action=config         - Update stock config per product (min stock, auto-pause)
 * POST   action=set_alert      - Configure alert threshold (alias for config)
 * POST   action=bulk_adjust    - Bulk stock adjustment
 * POST   action=ai_analysis    - AI-powered inventory analysis via Claude
 *
 * INGREDIENTS:
 * GET    action=ingredients            - List all ingredients with stock, alerts
 * GET    action=ingredient_detail&id=X - Single ingredient detail with movements
 * GET    action=ingredient_categories  - Distinct ingredient categories
 * GET    action=barcode_lookup&code=X  - Look up ingredient by barcode
 * GET    action=ingredient_costs       - Cost analysis per product
 * POST   action=add_ingredient         - Add new ingredient
 * POST   action=update_ingredient      - Update ingredient details
 * POST   action=ingredient_movement    - Record ingredient stock movement
 * POST   action=scan_barcode           - Process barcode scan
 * POST   action=link_product_ingredient - Link ingredient to product
 * POST   action=daily_closing          - Daily ingredient usage closing
 * POST   action=ai_ingredient_analysis - AI ingredient analysis
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partnerId = (int)$payload['uid'];

    // Ensure all stock tables exist
    ensureStockTables($db);

    // Determine product model (price table vs simple)
    $stmtCheck = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'om_market_products_price'");
    $stmtCheck->execute();
    $hasPriceTable = (int)$stmtCheck->fetchColumn() > 0;

    if ($hasPriceTable) {
        $stmtCheck2 = $db->prepare("SELECT COUNT(*) FROM om_market_products_price WHERE partner_id = ?");
        $stmtCheck2->execute([$partnerId]);
        $usesPriceModel = (int)$stmtCheck2->fetchColumn() > 0;
    } else {
        $usesPriceModel = false;
    }

    // ── GET requests ──
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';

        // ── GET (no action) — Dashboard summary ──
        if ($action === '' || $action === 'dashboard') {
            $dashboard = buildDashboard($db, $partnerId, $usesPriceModel);
            response(true, $dashboard);
        }

        // ── GET action=products — Full product list with stock info, velocity, forecast ──
        if ($action === 'products') {
            $search = trim($_GET['search'] ?? '');
            $limit = min(200, max(1, (int)($_GET['limit'] ?? 100)));
            $offset = max(0, (int)($_GET['offset'] ?? 0));
            $filter = $_GET['filter'] ?? ''; // low_stock, out_of_stock, excess
            $sort = $_GET['sort'] ?? 'name'; // name, stock_asc, stock_desc, velocity, forecast
            $categoryId = (int)($_GET['category_id'] ?? 0);

            $result = getProductsList($db, $partnerId, $usesPriceModel, $search, $limit, $offset, $filter, $sort, $categoryId);
            response(true, $result);
        }

        // ── GET action=movements — Movement history ──
        if ($action === 'movements') {
            $productId = (int)($_GET['product_id'] ?? 0);
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
            $offset = max(0, (int)($_GET['offset'] ?? 0));
            $tipo = $_GET['tipo'] ?? ''; // entrada, saida, ajuste, venda

            $where = ["m.partner_id = ?"];
            $params = [$partnerId];

            if ($productId > 0) {
                $where[] = "m.product_id = ?";
                $params[] = $productId;
            }
            if ($tipo !== '' && in_array($tipo, ['entrada', 'saida', 'ajuste', 'venda'])) {
                $where[] = "m.tipo = ?";
                $params[] = $tipo;
            }

            $whereSQL = implode(" AND ", $where);

            $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_market_stock_movements m WHERE {$whereSQL}");
            $stmtCount->execute($params);
            $total = (int)$stmtCount->fetchColumn();

            if ($usesPriceModel) {
                $stmt = $db->prepare("
                    SELECT m.*, pb.name as product_name
                    FROM om_market_stock_movements m
                    LEFT JOIN om_market_products_base pb ON pb.product_id = m.product_id
                    WHERE {$whereSQL}
                    ORDER BY m.created_at DESC
                    LIMIT ? OFFSET ?
                ");
            } else {
                $stmt = $db->prepare("
                    SELECT m.*, p.name as product_name
                    FROM om_market_stock_movements m
                    LEFT JOIN om_market_products p ON p.product_id = m.product_id
                    WHERE {$whereSQL}
                    ORDER BY m.created_at DESC
                    LIMIT ? OFFSET ?
                ");
            }
            $stmt->execute(array_merge($params, [$limit, $offset]));
            $movements = $stmt->fetchAll();

            foreach ($movements as &$mov) {
                $mov['id'] = (int)$mov['id'];
                $mov['product_id'] = (int)$mov['product_id'];
                $mov['quantidade_anterior'] = (int)$mov['quantidade_anterior'];
                $mov['quantidade_nova'] = (int)$mov['quantidade_nova'];
                $mov['quantidade_diff'] = (int)$mov['quantidade_diff'];
            }
            unset($mov);

            response(true, [
                "movements" => $movements,
                "total" => $total,
                "limit" => $limit,
                "offset" => $offset,
            ]);
        }

        // ── GET action=alerts — Products below minimum stock ──
        if ($action === 'alerts') {
            if ($usesPriceModel) {
                $stmt = $db->prepare("
                    SELECT pb.product_id, pb.name, pb.image,
                           pp.price, pp.stock as current_stock,
                           COALESCE(s.quantidade, pp.stock) as quantidade,
                           COALESCE(s.estoque_minimo, 0) as estoque_minimo,
                           COALESCE(s.auto_pausar, false) as auto_pausar,
                           s.ultima_movimentacao
                    FROM om_market_products_price pp
                    INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id
                    LEFT JOIN om_market_product_stock s ON s.product_id = pp.product_id AND s.partner_id = pp.partner_id
                    WHERE pp.partner_id = ?
                      AND COALESCE(s.quantidade, pp.stock) <= COALESCE(s.estoque_minimo, 0)
                      AND COALESCE(s.estoque_minimo, 0) > 0
                    ORDER BY COALESCE(s.quantidade, pp.stock) ASC
                ");
            } else {
                $stmt = $db->prepare("
                    SELECT p.product_id, p.name, p.image,
                           p.price,
                           COALESCE(p.quantity, p.stock, 0) as current_stock,
                           COALESCE(s.quantidade, COALESCE(p.quantity, p.stock, 0)) as quantidade,
                           COALESCE(s.estoque_minimo, 0) as estoque_minimo,
                           COALESCE(s.auto_pausar, false) as auto_pausar,
                           s.ultima_movimentacao
                    FROM om_market_products p
                    LEFT JOIN om_market_product_stock s ON s.product_id = p.product_id AND s.partner_id = p.partner_id
                    WHERE p.partner_id = ?
                      AND COALESCE(s.quantidade, COALESCE(p.quantity, p.stock, 0)) <= COALESCE(s.estoque_minimo, 0)
                      AND COALESCE(s.estoque_minimo, 0) > 0
                    ORDER BY COALESCE(s.quantidade, COALESCE(p.quantity, p.stock, 0)) ASC
                ");
            }
            $stmt->execute([$partnerId]);
            $alerts = $stmt->fetchAll();

            foreach ($alerts as &$a) {
                $a['product_id'] = (int)$a['product_id'];
                $a['price'] = (float)$a['price'];
                $a['current_stock'] = (int)$a['current_stock'];
                $a['quantidade'] = (int)$a['quantidade'];
                $a['estoque_minimo'] = (int)$a['estoque_minimo'];
                $a['auto_pausar'] = (bool)$a['auto_pausar'];
            }
            unset($a);

            response(true, ["alerts" => $alerts]);
        }

        // ── GET action=categories — Product categories for filter dropdown ──
        if ($action === 'categories') {
            if ($usesPriceModel) {
                $stmt = $db->prepare("
                    SELECT DISTINCT c.category_id, c.name
                    FROM om_market_categories c
                    INNER JOIN om_market_products_base pb ON pb.category_id = c.category_id
                    INNER JOIN om_market_products_price pp ON pp.product_id = pb.product_id AND pp.partner_id = ?
                    WHERE c.status = 1
                    ORDER BY c.name
                ");
            } else {
                $stmt = $db->prepare("
                    SELECT DISTINCT c.category_id, c.name
                    FROM om_market_categories c
                    INNER JOIN om_market_products p ON p.category_id = c.category_id AND p.partner_id = ?
                    WHERE c.status = 1
                    ORDER BY c.name
                ");
            }
            $stmt->execute([$partnerId]);
            response(true, ["categories" => $stmt->fetchAll()]);
        }

        // ── GET action=ingredients — List all ingredients ──
        if ($action === 'ingredients') {
            $search = trim($_GET['search'] ?? '');
            $category = trim($_GET['category'] ?? '');
            $filter = $_GET['filter'] ?? ''; // low_stock, expiring, all

            $where = ["i.partner_id = ?", "i.active = true"];
            $params = [$partnerId];

            if ($search !== '') {
                $searchEsc = str_replace(['%', '_'], ['\\%', '\\_'], $search);
                $where[] = "(i.name ILIKE ? OR i.barcode ILIKE ?)";
                $params[] = "%{$searchEsc}%";
                $params[] = "%{$searchEsc}%";
            }
            if ($category !== '') {
                $where[] = "i.category = ?";
                $params[] = $category;
            }
            if ($filter === 'low_stock') {
                $where[] = "i.min_stock > 0 AND i.current_stock <= i.min_stock";
            } elseif ($filter === 'expiring') {
                $where[] = "i.expires_at IS NOT NULL AND i.expires_at <= CURRENT_DATE + INTERVAL '7 days'";
            }

            $whereSQL = implode(" AND ", $where);

            $stmt = $db->prepare("
                SELECT i.*,
                       (SELECT COUNT(*) FROM om_ingredient_movements m WHERE m.ingredient_id = i.id AND m.partner_id = i.partner_id) as movement_count,
                       (SELECT SUM(m.quantity) FROM om_ingredient_movements m WHERE m.ingredient_id = i.id AND m.partner_id = i.partner_id AND m.type = 'saida' AND m.created_at >= NOW() - INTERVAL '30 days') as used_30d
                FROM om_partner_ingredients i
                WHERE {$whereSQL}
                ORDER BY i.name ASC
            ");
            $stmt->execute($params);
            $ingredients = $stmt->fetchAll();

            $totalCost = 0;
            $lowStockCount = 0;
            $expiringCount = 0;

            foreach ($ingredients as &$ing) {
                $ing['id'] = (int)$ing['id'];
                $ing['partner_id'] = (int)$ing['partner_id'];
                $ing['current_stock'] = (float)$ing['current_stock'];
                $ing['min_stock'] = (float)$ing['min_stock'];
                $ing['cost_per_unit'] = (float)$ing['cost_per_unit'];
                $ing['movement_count'] = (int)$ing['movement_count'];
                $ing['used_30d'] = (float)($ing['used_30d'] ?? 0);
                $ing['active'] = (bool)$ing['active'];
                $totalCost += $ing['current_stock'] * $ing['cost_per_unit'];
                if ($ing['min_stock'] > 0 && $ing['current_stock'] <= $ing['min_stock']) {
                    $lowStockCount++;
                }
                if ($ing['expires_at'] && strtotime($ing['expires_at']) <= strtotime('+7 days')) {
                    $expiringCount++;
                }
                // Status
                if ($ing['expires_at'] && strtotime($ing['expires_at']) < time()) {
                    $ing['status'] = 'vencido';
                } elseif ($ing['min_stock'] > 0 && $ing['current_stock'] <= $ing['min_stock']) {
                    $ing['status'] = 'baixo';
                } else {
                    $ing['status'] = 'ok';
                }
            }
            unset($ing);

            response(true, [
                "ingredients" => $ingredients,
                "summary" => [
                    "total" => count($ingredients),
                    "low_stock" => $lowStockCount,
                    "expiring_7d" => $expiringCount,
                    "total_cost" => round($totalCost, 2),
                ],
            ]);
        }

        // ── GET action=ingredient_detail — Single ingredient with movement history ──
        if ($action === 'ingredient_detail') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) response(false, null, "id obrigatorio", 400);

            $stmt = $db->prepare("SELECT * FROM om_partner_ingredients WHERE id = ? AND partner_id = ?");
            $stmt->execute([$id, $partnerId]);
            $ingredient = $stmt->fetch();
            if (!$ingredient) response(false, null, "Ingrediente nao encontrado", 404);

            $ingredient['id'] = (int)$ingredient['id'];
            $ingredient['current_stock'] = (float)$ingredient['current_stock'];
            $ingredient['min_stock'] = (float)$ingredient['min_stock'];
            $ingredient['cost_per_unit'] = (float)$ingredient['cost_per_unit'];

            // Movement history
            $stmt = $db->prepare("
                SELECT m.* FROM om_ingredient_movements m
                WHERE m.ingredient_id = ? AND m.partner_id = ?
                ORDER BY m.created_at DESC LIMIT 50
            ");
            $stmt->execute([$id, $partnerId]);
            $movements = $stmt->fetchAll();

            foreach ($movements as &$mov) {
                $mov['id'] = (int)$mov['id'];
                $mov['ingredient_id'] = (int)$mov['ingredient_id'];
                $mov['quantity'] = (float)$mov['quantity'];
                $mov['previous_stock'] = (float)($mov['previous_stock'] ?? 0);
                $mov['new_stock'] = (float)($mov['new_stock'] ?? 0);
                $mov['cost'] = (float)($mov['cost'] ?? 0);
                $mov['barcode_scanned'] = (bool)$mov['barcode_scanned'];
            }
            unset($mov);

            // Linked products
            $stmt = $db->prepare("
                SELECT pi.*, p.name as product_name, p.image as product_image
                FROM om_product_ingredients pi
                LEFT JOIN om_market_products p ON p.product_id = pi.product_id
                WHERE pi.ingredient_id = ? AND pi.partner_id = ?
            ");
            $stmt->execute([$id, $partnerId]);
            $linkedProducts = $stmt->fetchAll();

            foreach ($linkedProducts as &$lp) {
                $lp['id'] = (int)$lp['id'];
                $lp['product_id'] = (int)$lp['product_id'];
                $lp['ingredient_id'] = (int)$lp['ingredient_id'];
                $lp['quantity_used'] = (float)$lp['quantity_used'];
            }
            unset($lp);

            response(true, [
                "ingredient" => $ingredient,
                "movements" => $movements,
                "linked_products" => $linkedProducts,
            ]);
        }

        // ── GET action=ingredient_categories — Distinct categories ──
        if ($action === 'ingredient_categories') {
            $stmt = $db->prepare("
                SELECT DISTINCT category FROM om_partner_ingredients
                WHERE partner_id = ? AND active = true AND category IS NOT NULL AND category != ''
                ORDER BY category
            ");
            $stmt->execute([$partnerId]);
            $categories = array_column($stmt->fetchAll(), 'category');
            response(true, ["categories" => $categories]);
        }

        // ── GET action=barcode_lookup — Look up ingredient by barcode ──
        if ($action === 'barcode_lookup') {
            $code = trim($_GET['code'] ?? '');
            if (!$code) response(false, null, "code obrigatorio", 400);

            // Check local ingredients first
            $stmt = $db->prepare("SELECT * FROM om_partner_ingredients WHERE barcode = ? AND partner_id = ? AND active = true");
            $stmt->execute([$code, $partnerId]);
            $found = $stmt->fetch();

            if ($found) {
                $found['id'] = (int)$found['id'];
                $found['current_stock'] = (float)$found['current_stock'];
                $found['min_stock'] = (float)$found['min_stock'];
                $found['cost_per_unit'] = (float)$found['cost_per_unit'];
                response(true, ["found" => true, "source" => "local", "ingredient" => $found]);
            }

            // Try Open Food Facts API
            $suggestion = null;
            try {
                $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => 'User-Agent: SuperBora/1.0']]);
                $apiUrl = "https://world.openfoodfacts.org/api/v0/product/{$code}.json";
                $resp = @file_get_contents($apiUrl, false, $ctx);
                if ($resp) {
                    $data = json_decode($resp, true);
                    if (($data['status'] ?? 0) == 1 && isset($data['product'])) {
                        $p = $data['product'];
                        $suggestion = [
                            'name' => $p['product_name'] ?? $p['product_name_pt'] ?? '',
                            'barcode' => $code,
                            'image' => $p['image_url'] ?? $p['image_front_url'] ?? null,
                            'category' => $p['categories'] ?? '',
                            'brand' => $p['brands'] ?? '',
                            'quantity_text' => $p['quantity'] ?? '',
                        ];
                    }
                }
            } catch (Exception $e) {
                // Non-critical
            }

            response(true, [
                "found" => false,
                "source" => $suggestion ? "openfoodfacts" : "none",
                "suggestion" => $suggestion,
            ]);
        }

        // ── GET action=ingredient_costs — Cost analysis ──
        if ($action === 'ingredient_costs') {
            // Cost per product based on linked ingredients
            $stmt = $db->prepare("
                SELECT pi.product_id,
                       COALESCE(p.name, 'Produto #' || pi.product_id) as product_name,
                       p.price as sell_price,
                       SUM(pi.quantity_used * i.cost_per_unit) as ingredient_cost
                FROM om_product_ingredients pi
                INNER JOIN om_partner_ingredients i ON i.id = pi.ingredient_id AND i.partner_id = pi.partner_id
                LEFT JOIN om_market_products p ON p.product_id = pi.product_id
                WHERE pi.partner_id = ?
                GROUP BY pi.product_id, p.name, p.price
                ORDER BY ingredient_cost DESC
            ");
            $stmt->execute([$partnerId]);
            $productCosts = $stmt->fetchAll();

            foreach ($productCosts as &$pc) {
                $pc['product_id'] = (int)$pc['product_id'];
                $pc['sell_price'] = (float)($pc['sell_price'] ?? 0);
                $pc['ingredient_cost'] = round((float)$pc['ingredient_cost'], 2);
                $pc['margin'] = $pc['sell_price'] > 0
                    ? round((($pc['sell_price'] - $pc['ingredient_cost']) / $pc['sell_price']) * 100, 1)
                    : 0;
            }
            unset($pc);

            // Total monthly cost from movements
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(m.cost), 0) as monthly_cost
                FROM om_ingredient_movements m
                WHERE m.partner_id = ? AND m.type = 'entrada' AND m.created_at >= NOW() - INTERVAL '30 days'
            ");
            $stmt->execute([$partnerId]);
            $monthlyCost = round((float)$stmt->fetchColumn(), 2);

            // Top 10 most expensive ingredients by total spend
            $stmt = $db->prepare("
                SELECT i.id, i.name, i.unit, i.cost_per_unit, i.current_stock,
                       (i.current_stock * i.cost_per_unit) as total_value
                FROM om_partner_ingredients i
                WHERE i.partner_id = ? AND i.active = true
                ORDER BY total_value DESC LIMIT 10
            ");
            $stmt->execute([$partnerId]);
            $topCosts = $stmt->fetchAll();

            foreach ($topCosts as &$tc) {
                $tc['id'] = (int)$tc['id'];
                $tc['cost_per_unit'] = (float)$tc['cost_per_unit'];
                $tc['current_stock'] = (float)$tc['current_stock'];
                $tc['total_value'] = round((float)$tc['total_value'], 2);
            }
            unset($tc);

            response(true, [
                "product_costs" => $productCosts,
                "monthly_cost" => $monthlyCost,
                "top_ingredient_costs" => $topCosts,
            ]);
        }

        // Fallback for unknown GET with search (legacy: default product list)
        if ($action === '') {
            // Already handled above via dashboard
        }

        response(false, null, "Acao GET desconhecida: " . sanitizeOutput($action), 400);
    }

    // ── POST requests ──
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $input = getInput();
    $action = $input['action'] ?? '';

    // ── POST action=adjust — Set absolute stock quantity ──
    if ($action === 'adjust') {
        $productId = (int)($input['product_id'] ?? 0);
        $newQty = (int)($input['quantidade'] ?? $input['new_quantity'] ?? 0);
        $motivo = trim(substr($input['motivo'] ?? $input['reason'] ?? 'Ajuste manual', 0, 255));

        if (!$productId) {
            response(false, null, "product_id obrigatorio", 400);
        }
        if ($newQty < 0) {
            response(false, null, "Quantidade nao pode ser negativa", 400);
        }

        if (!verifyProductOwnership($db, $productId, $partnerId, $usesPriceModel)) {
            response(false, null, "Produto nao encontrado", 404);
        }

        $db->beginTransaction();
        try {
            $currentQty = getCurrentStock($db, $productId, $partnerId, $usesPriceModel);
            $diff = $newQty - $currentQty;

            $stmt = $db->prepare("
                INSERT INTO om_market_stock_movements
                (product_id, partner_id, tipo, quantidade_anterior, quantidade_nova, quantidade_diff, motivo, created_at)
                VALUES (?, ?, 'ajuste', ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$productId, $partnerId, $currentQty, $newQty, $diff, $motivo]);

            $stmt = $db->prepare("
                INSERT INTO om_market_product_stock (product_id, partner_id, quantidade, ultima_movimentacao, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
                ON CONFLICT (partner_id, product_id)
                DO UPDATE SET quantidade = EXCLUDED.quantidade,
                              ultima_movimentacao = NOW(),
                              updated_at = NOW()
            ");
            $stmt->execute([$productId, $partnerId, $newQty]);

            updateMainProductStock($db, $productId, $newQty, $usesPriceModel, $partnerId);
            checkAutoPause($db, $productId, $partnerId, $newQty, $usesPriceModel);

            $db->commit();

            response(true, [
                "product_id" => $productId,
                "quantidade_anterior" => $currentQty,
                "quantidade_nova" => $newQty,
                "diff" => $diff,
            ], "Estoque ajustado");

        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // ── POST action=movement — Record stock movement (entrada/saida) ──
    if ($action === 'movement') {
        $productId = (int)($input['product_id'] ?? 0);
        $tipo = trim($input['tipo'] ?? '');
        $qty = (int)($input['quantidade'] ?? 0);
        $motivo = trim(substr($input['motivo'] ?? '', 0, 255));

        if (!$productId) {
            response(false, null, "product_id obrigatorio", 400);
        }
        if (!in_array($tipo, ['entrada', 'saida'])) {
            response(false, null, "tipo deve ser 'entrada' ou 'saida'", 400);
        }
        if ($qty <= 0) {
            response(false, null, "quantidade deve ser maior que zero", 400);
        }
        if (!$motivo) {
            response(false, null, "motivo obrigatorio", 400);
        }

        if (!verifyProductOwnership($db, $productId, $partnerId, $usesPriceModel)) {
            response(false, null, "Produto nao encontrado", 404);
        }

        $db->beginTransaction();
        try {
            $currentQty = getCurrentStock($db, $productId, $partnerId, $usesPriceModel);

            if ($tipo === 'entrada') {
                $newQty = $currentQty + $qty;
            } else {
                $newQty = max(0, $currentQty - $qty);
            }
            $diff = $newQty - $currentQty;

            $stmt = $db->prepare("
                INSERT INTO om_market_stock_movements
                (product_id, partner_id, tipo, quantidade_anterior, quantidade_nova, quantidade_diff, motivo, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$productId, $partnerId, $tipo, $currentQty, $newQty, $diff, $motivo]);

            $stmt = $db->prepare("
                INSERT INTO om_market_product_stock (product_id, partner_id, quantidade, ultima_movimentacao, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
                ON CONFLICT (partner_id, product_id)
                DO UPDATE SET quantidade = EXCLUDED.quantidade,
                              ultima_movimentacao = NOW(),
                              updated_at = NOW()
            ");
            $stmt->execute([$productId, $partnerId, $newQty]);

            updateMainProductStock($db, $productId, $newQty, $usesPriceModel, $partnerId);
            checkAutoPause($db, $productId, $partnerId, $newQty, $usesPriceModel);

            $db->commit();

            response(true, [
                "product_id" => $productId,
                "tipo" => $tipo,
                "quantidade_anterior" => $currentQty,
                "quantidade_nova" => $newQty,
                "diff" => $diff,
            ], "Movimentacao registrada");

        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // ── POST action=config / set_alert — Update stock config per product ──
    if ($action === 'config' || $action === 'set_alert') {
        $productId = (int)($input['product_id'] ?? 0);
        $estoqueMinimo = max(0, (int)($input['estoque_minimo'] ?? $input['min_stock'] ?? 0));
        $autoPausar = (bool)($input['auto_pausar'] ?? false);
        $enabled = (bool)($input['enabled'] ?? true);

        if (!$productId) {
            response(false, null, "product_id obrigatorio", 400);
        }

        if (!verifyProductOwnership($db, $productId, $partnerId, $usesPriceModel)) {
            response(false, null, "Produto nao encontrado", 404);
        }

        $currentQty = getCurrentStock($db, $productId, $partnerId, $usesPriceModel);

        // If disabled, set min to 0
        if (!$enabled) {
            $estoqueMinimo = 0;
        }

        $stmt = $db->prepare("
            INSERT INTO om_market_product_stock (product_id, partner_id, quantidade, estoque_minimo, auto_pausar, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON CONFLICT (partner_id, product_id)
            DO UPDATE SET estoque_minimo = EXCLUDED.estoque_minimo,
                          auto_pausar = EXCLUDED.auto_pausar,
                          updated_at = NOW()
        ");
        $stmt->execute([$productId, $partnerId, $currentQty, $estoqueMinimo, $autoPausar ? 1 : 0]);

        // Also upsert into om_stock_alerts
        $stmt = $db->prepare("
            INSERT INTO om_stock_alerts (partner_id, product_id, min_stock, enabled, created_at)
            VALUES (?, ?, ?, ?, NOW())
            ON CONFLICT (partner_id, product_id)
            DO UPDATE SET min_stock = EXCLUDED.min_stock,
                          enabled = EXCLUDED.enabled
        ");
        $stmt->execute([$partnerId, $productId, $estoqueMinimo, $enabled ? 1 : 0]);

        if ($autoPausar) {
            checkAutoPause($db, $productId, $partnerId, $currentQty, $usesPriceModel);
        }

        response(true, [
            "product_id" => $productId,
            "estoque_minimo" => $estoqueMinimo,
            "auto_pausar" => $autoPausar,
            "enabled" => $enabled,
        ], "Configuracao de estoque atualizada");
    }

    // ── POST action=bulk_adjust — Bulk stock adjustment ──
    if ($action === 'bulk_adjust') {
        $items = $input['items'] ?? [];
        $motivo = trim(substr($input['motivo'] ?? 'Ajuste em lote', 0, 255));

        if (!is_array($items) || count($items) === 0) {
            response(false, null, "items obrigatorio (array de {product_id, quantidade})", 400);
        }
        if (count($items) > 200) {
            response(false, null, "Maximo de 200 itens por ajuste em lote", 400);
        }

        $db->beginTransaction();
        try {
            $results = [];

            foreach ($items as $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $newQty = (int)($item['quantidade'] ?? $item['quantity'] ?? 0);

                if (!$productId) continue;
                if ($newQty < 0) $newQty = 0;

                if (!verifyProductOwnership($db, $productId, $partnerId, $usesPriceModel)) {
                    $results[] = ["product_id" => $productId, "error" => "Produto nao encontrado"];
                    continue;
                }

                $currentQty = getCurrentStock($db, $productId, $partnerId, $usesPriceModel);
                $diff = $newQty - $currentQty;

                $stmt = $db->prepare("
                    INSERT INTO om_market_stock_movements
                    (product_id, partner_id, tipo, quantidade_anterior, quantidade_nova, quantidade_diff, motivo, created_at)
                    VALUES (?, ?, 'ajuste', ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$productId, $partnerId, $currentQty, $newQty, $diff, $motivo]);

                $stmt = $db->prepare("
                    INSERT INTO om_market_product_stock (product_id, partner_id, quantidade, ultima_movimentacao, updated_at)
                    VALUES (?, ?, ?, NOW(), NOW())
                    ON CONFLICT (partner_id, product_id)
                    DO UPDATE SET quantidade = EXCLUDED.quantidade,
                                  ultima_movimentacao = NOW(),
                                  updated_at = NOW()
                ");
                $stmt->execute([$productId, $partnerId, $newQty]);

                updateMainProductStock($db, $productId, $newQty, $usesPriceModel, $partnerId);
                checkAutoPause($db, $productId, $partnerId, $newQty, $usesPriceModel);

                $results[] = [
                    "product_id" => $productId,
                    "quantidade_anterior" => $currentQty,
                    "quantidade_nova" => $newQty,
                    "diff" => $diff,
                ];
            }

            $db->commit();

            response(true, [
                "updated" => count(array_filter($results, fn($r) => !isset($r['error']))),
                "errors" => count(array_filter($results, fn($r) => isset($r['error']))),
                "results" => $results,
            ], "Ajuste em lote concluido");

        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // ── POST action=ai_analysis — AI-powered inventory analysis ──
    if ($action === 'ai_analysis') {
        $aiResult = runAiAnalysis($db, $partnerId, $usesPriceModel);
        response(true, $aiResult);
    }

    // ══════════════════════════════════════════════
    //  INGREDIENT POST ACTIONS
    // ══════════════════════════════════════════════

    // ── POST action=add_ingredient — Add new ingredient ──
    if ($action === 'add_ingredient') {
        $name = trim(substr($input['name'] ?? '', 0, 255));
        $barcode = trim(substr($input['barcode'] ?? '', 0, 50)) ?: null;
        $unit = trim($input['unit'] ?? 'un');
        $currentStock = max(0, (float)($input['current_stock'] ?? $input['quantity'] ?? 0));
        $minStock = max(0, (float)($input['min_stock'] ?? 0));
        $costPerUnit = max(0, (float)($input['cost_per_unit'] ?? $input['cost'] ?? 0));
        $supplier = trim(substr($input['supplier'] ?? '', 0, 255)) ?: null;
        $category = trim(substr($input['category'] ?? '', 0, 100)) ?: null;
        $expiresAt = !empty($input['expires_at']) ? $input['expires_at'] : null;
        $notes = trim(substr($input['notes'] ?? '', 0, 2000)) ?: null;

        if (!$name) {
            response(false, null, "Nome do ingrediente obrigatorio", 400);
        }
        if (!in_array($unit, ['un', 'kg', 'g', 'L', 'ml', 'pacote'])) {
            $unit = 'un';
        }

        // Check duplicate barcode
        if ($barcode) {
            $stmt = $db->prepare("SELECT id FROM om_partner_ingredients WHERE barcode = ? AND partner_id = ? AND active = true");
            $stmt->execute([$barcode, $partnerId]);
            if ($stmt->fetch()) {
                response(false, null, "Ja existe um ingrediente com este codigo de barras", 400);
            }
        }

        $stmt = $db->prepare("
            INSERT INTO om_partner_ingredients
            (partner_id, name, barcode, unit, current_stock, min_stock, cost_per_unit, supplier, category, expires_at, notes, last_restock_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CASE WHEN ? > 0 THEN NOW() ELSE NULL END)
            RETURNING id
        ");
        $stmt->execute([$partnerId, $name, $barcode, $unit, $currentStock, $minStock, $costPerUnit, $supplier, $category, $expiresAt, $notes, $currentStock]);
        $newId = (int)$stmt->fetchColumn();

        // Record initial stock movement if quantity > 0
        if ($currentStock > 0) {
            $stmt = $db->prepare("
                INSERT INTO om_ingredient_movements
                (partner_id, ingredient_id, type, quantity, previous_stock, new_stock, reason, cost)
                VALUES (?, ?, 'entrada', ?, 0, ?, 'Estoque inicial', ?)
            ");
            $stmt->execute([$partnerId, $newId, $currentStock, $currentStock, $currentStock * $costPerUnit]);
        }

        response(true, ["id" => $newId, "name" => $name], "Ingrediente adicionado");
    }

    // ── POST action=update_ingredient — Update ingredient details ──
    if ($action === 'update_ingredient') {
        $id = (int)($input['id'] ?? 0);
        if (!$id) response(false, null, "id obrigatorio", 400);

        $stmt = $db->prepare("SELECT * FROM om_partner_ingredients WHERE id = ? AND partner_id = ?");
        $stmt->execute([$id, $partnerId]);
        $existing = $stmt->fetch();
        if (!$existing) response(false, null, "Ingrediente nao encontrado", 404);

        $name = trim(substr($input['name'] ?? $existing['name'], 0, 255));
        $barcode = isset($input['barcode']) ? (trim(substr($input['barcode'], 0, 50)) ?: null) : $existing['barcode'];
        $unit = trim($input['unit'] ?? $existing['unit']);
        $minStock = isset($input['min_stock']) ? max(0, (float)$input['min_stock']) : (float)$existing['min_stock'];
        $costPerUnit = isset($input['cost_per_unit']) ? max(0, (float)$input['cost_per_unit']) : (float)$existing['cost_per_unit'];
        $supplier = isset($input['supplier']) ? (trim(substr($input['supplier'], 0, 255)) ?: null) : $existing['supplier'];
        $category = isset($input['category']) ? (trim(substr($input['category'], 0, 100)) ?: null) : $existing['category'];
        $expiresAt = array_key_exists('expires_at', $input) ? ($input['expires_at'] ?: null) : $existing['expires_at'];
        $notes = isset($input['notes']) ? (trim(substr($input['notes'], 0, 2000)) ?: null) : $existing['notes'];
        $active = isset($input['active']) ? (bool)$input['active'] : (bool)$existing['active'];

        if (!in_array($unit, ['un', 'kg', 'g', 'L', 'ml', 'pacote'])) {
            $unit = $existing['unit'];
        }

        // Check barcode uniqueness if changed
        if ($barcode && $barcode !== $existing['barcode']) {
            $stmt = $db->prepare("SELECT id FROM om_partner_ingredients WHERE barcode = ? AND partner_id = ? AND id != ? AND active = true");
            $stmt->execute([$barcode, $partnerId, $id]);
            if ($stmt->fetch()) {
                response(false, null, "Ja existe outro ingrediente com este codigo de barras", 400);
            }
        }

        $stmt = $db->prepare("
            UPDATE om_partner_ingredients
            SET name = ?, barcode = ?, unit = ?, min_stock = ?, cost_per_unit = ?,
                supplier = ?, category = ?, expires_at = ?, notes = ?, active = ?, updated_at = NOW()
            WHERE id = ? AND partner_id = ?
        ");
        $stmt->execute([$name, $barcode, $unit, $minStock, $costPerUnit, $supplier, $category, $expiresAt, $notes, $active, $id, $partnerId]);

        response(true, ["id" => $id], "Ingrediente atualizado");
    }

    // ── POST action=ingredient_movement — Record ingredient stock movement ──
    if ($action === 'ingredient_movement') {
        $ingredientId = (int)($input['ingredient_id'] ?? 0);
        $type = trim($input['type'] ?? '');
        $quantity = (float)($input['quantity'] ?? 0);
        $reason = trim(substr($input['reason'] ?? '', 0, 500));
        $barcodeScanned = (bool)($input['barcode_scanned'] ?? false);
        $cost = isset($input['cost']) ? (float)$input['cost'] : null;

        if (!$ingredientId) response(false, null, "ingredient_id obrigatorio", 400);
        if (!in_array($type, ['entrada', 'saida', 'ajuste', 'perda'])) {
            response(false, null, "type deve ser 'entrada', 'saida', 'ajuste' ou 'perda'", 400);
        }
        if ($quantity <= 0) response(false, null, "quantity deve ser maior que zero", 400);

        $stmt = $db->prepare("SELECT * FROM om_partner_ingredients WHERE id = ? AND partner_id = ?");
        $stmt->execute([$ingredientId, $partnerId]);
        $ingredient = $stmt->fetch();
        if (!$ingredient) response(false, null, "Ingrediente nao encontrado", 404);

        $previousStock = (float)$ingredient['current_stock'];

        if ($type === 'entrada') {
            $newStock = $previousStock + $quantity;
        } elseif ($type === 'saida' || $type === 'perda') {
            $newStock = max(0, $previousStock - $quantity);
        } else { // ajuste
            $newStock = $quantity; // absolute
        }

        // Auto-calculate cost if not provided for entrada
        if ($cost === null && $type === 'entrada') {
            $cost = $quantity * (float)$ingredient['cost_per_unit'];
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                INSERT INTO om_ingredient_movements
                (partner_id, ingredient_id, type, quantity, previous_stock, new_stock, reason, cost, barcode_scanned, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$partnerId, $ingredientId, $type, $quantity, $previousStock, $newStock, $reason, $cost, $barcodeScanned, $partnerId]);

            $updateFields = "current_stock = ?, updated_at = NOW()";
            $updateParams = [$newStock];
            if ($type === 'entrada') {
                $updateFields .= ", last_restock_at = NOW()";
            }
            $updateParams[] = $ingredientId;
            $updateParams[] = $partnerId;

            $stmt = $db->prepare("UPDATE om_partner_ingredients SET {$updateFields} WHERE id = ? AND partner_id = ?");
            $stmt->execute($updateParams);

            $db->commit();

            response(true, [
                "ingredient_id" => $ingredientId,
                "type" => $type,
                "previous_stock" => $previousStock,
                "new_stock" => $newStock,
                "quantity" => $quantity,
            ], "Movimentacao registrada");
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // ── POST action=scan_barcode — Process barcode scan ──
    if ($action === 'scan_barcode') {
        $code = trim($input['code'] ?? '');
        if (!$code) response(false, null, "code obrigatorio", 400);

        // Check local
        $stmt = $db->prepare("SELECT * FROM om_partner_ingredients WHERE barcode = ? AND partner_id = ? AND active = true");
        $stmt->execute([$code, $partnerId]);
        $found = $stmt->fetch();

        if ($found) {
            $found['id'] = (int)$found['id'];
            $found['current_stock'] = (float)$found['current_stock'];
            $found['min_stock'] = (float)$found['min_stock'];
            $found['cost_per_unit'] = (float)$found['cost_per_unit'];
            response(true, ["found" => true, "source" => "local", "ingredient" => $found]);
        }

        // Try Open Food Facts
        $suggestion = null;
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => 'User-Agent: SuperBora/1.0']]);
            $apiUrl = "https://world.openfoodfacts.org/api/v0/product/{$code}.json";
            $resp = @file_get_contents($apiUrl, false, $ctx);
            if ($resp) {
                $data = json_decode($resp, true);
                if (($data['status'] ?? 0) == 1 && isset($data['product'])) {
                    $p = $data['product'];
                    $suggestion = [
                        'name' => $p['product_name'] ?? $p['product_name_pt'] ?? '',
                        'barcode' => $code,
                        'image' => $p['image_url'] ?? $p['image_front_url'] ?? null,
                        'category' => $p['categories'] ?? '',
                        'brand' => $p['brands'] ?? '',
                        'quantity_text' => $p['quantity'] ?? '',
                    ];
                }
            }
        } catch (Exception $e) {
            // Non-critical
        }

        response(true, [
            "found" => false,
            "source" => $suggestion ? "openfoodfacts" : "none",
            "suggestion" => $suggestion,
        ]);
    }

    // ── POST action=link_product_ingredient — Link ingredient to product ──
    if ($action === 'link_product_ingredient') {
        $productId = (int)($input['product_id'] ?? 0);
        $ingredientId = (int)($input['ingredient_id'] ?? 0);
        $quantityUsed = (float)($input['quantity_used'] ?? 0);

        if (!$productId || !$ingredientId) {
            response(false, null, "product_id e ingredient_id obrigatorios", 400);
        }
        if ($quantityUsed <= 0) {
            response(false, null, "quantity_used deve ser maior que zero", 400);
        }

        // Verify ingredient belongs to partner
        $stmt = $db->prepare("SELECT id FROM om_partner_ingredients WHERE id = ? AND partner_id = ?");
        $stmt->execute([$ingredientId, $partnerId]);
        if (!$stmt->fetch()) response(false, null, "Ingrediente nao encontrado", 404);

        // Verify product belongs to partner
        if (!verifyProductOwnership($db, $productId, $partnerId, $usesPriceModel)) {
            response(false, null, "Produto nao encontrado", 404);
        }

        $stmt = $db->prepare("
            INSERT INTO om_product_ingredients (partner_id, product_id, ingredient_id, quantity_used)
            VALUES (?, ?, ?, ?)
            ON CONFLICT (product_id, ingredient_id)
            DO UPDATE SET quantity_used = EXCLUDED.quantity_used
        ");
        $stmt->execute([$partnerId, $productId, $ingredientId, $quantityUsed]);

        response(true, [
            "product_id" => $productId,
            "ingredient_id" => $ingredientId,
            "quantity_used" => $quantityUsed,
        ], "Vinculo atualizado");
    }

    // ── POST action=unlink_product_ingredient — Remove link ──
    if ($action === 'unlink_product_ingredient') {
        $productId = (int)($input['product_id'] ?? 0);
        $ingredientId = (int)($input['ingredient_id'] ?? 0);

        if (!$productId || !$ingredientId) {
            response(false, null, "product_id e ingredient_id obrigatorios", 400);
        }

        $stmt = $db->prepare("DELETE FROM om_product_ingredients WHERE product_id = ? AND ingredient_id = ? AND partner_id = ?");
        $stmt->execute([$productId, $ingredientId, $partnerId]);

        response(true, null, "Vinculo removido");
    }

    // ── POST action=daily_closing — Daily stock closing ──
    if ($action === 'daily_closing') {
        $date = $input['date'] ?? date('Y-m-d');

        // Get today's delivered orders
        $stmt = $db->prepare("
            SELECT oi.product_id, SUM(oi.quantity) as qty_sold
            FROM om_market_order_items oi
            INNER JOIN om_market_orders o ON o.order_id = oi.order_id
            WHERE o.partner_id = ?
              AND o.status IN ('entregue', 'retirado', 'confirmado')
              AND DATE(o.created_at) = ?
            GROUP BY oi.product_id
        ");
        $stmt->execute([$partnerId, $date]);
        $soldItems = $stmt->fetchAll();

        if (empty($soldItems)) {
            response(true, ["deductions" => [], "message" => "Nenhum pedido entregue hoje"], "Sem movimentacoes para fechamento");
        }

        $deductions = [];
        $db->beginTransaction();
        try {
            foreach ($soldItems as $sold) {
                $productId = (int)$sold['product_id'];
                $qtySold = (int)$sold['qty_sold'];

                // Get linked ingredients for this product
                $stmt = $db->prepare("
                    SELECT pi.ingredient_id, pi.quantity_used, i.name, i.current_stock, i.unit, i.cost_per_unit
                    FROM om_product_ingredients pi
                    INNER JOIN om_partner_ingredients i ON i.id = pi.ingredient_id AND i.partner_id = pi.partner_id
                    WHERE pi.product_id = ? AND pi.partner_id = ?
                ");
                $stmt->execute([$productId, $partnerId]);
                $linkedIngredients = $stmt->fetchAll();

                foreach ($linkedIngredients as $li) {
                    $totalUsed = $qtySold * (float)$li['quantity_used'];
                    $previousStock = (float)$li['current_stock'];
                    $newStock = max(0, $previousStock - $totalUsed);
                    $cost = $totalUsed * (float)$li['cost_per_unit'];

                    // Record movement
                    $stmt = $db->prepare("
                        INSERT INTO om_ingredient_movements
                        (partner_id, ingredient_id, type, quantity, previous_stock, new_stock, reason, cost, created_by)
                        VALUES (?, ?, 'saida', ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$partnerId, (int)$li['ingredient_id'], $totalUsed, $previousStock, $newStock,
                        "Fechamento diario ({$date}) - {$qtySold}x produto #{$productId}", $cost, $partnerId]);

                    // Update stock
                    $stmt = $db->prepare("UPDATE om_partner_ingredients SET current_stock = ?, updated_at = NOW() WHERE id = ? AND partner_id = ?");
                    $stmt->execute([$newStock, (int)$li['ingredient_id'], $partnerId]);

                    $deductions[] = [
                        "ingredient_id" => (int)$li['ingredient_id'],
                        "ingredient_name" => $li['name'],
                        "product_id" => $productId,
                        "qty_sold" => $qtySold,
                        "quantity_used" => $totalUsed,
                        "unit" => $li['unit'],
                        "previous_stock" => $previousStock,
                        "new_stock" => $newStock,
                        "cost" => round($cost, 2),
                    ];
                }
            }

            $db->commit();

            response(true, [
                "date" => $date,
                "deductions" => $deductions,
                "total_deductions" => count($deductions),
                "total_cost" => round(array_sum(array_column($deductions, 'cost')), 2),
            ], "Fechamento diario concluido");
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // ── POST action=ai_ingredient_analysis — AI ingredient analysis ──
    if ($action === 'ai_ingredient_analysis') {
        $aiResult = runAiIngredientAnalysis($db, $partnerId);
        response(true, $aiResult);
    }

    if (!$action) {
        response(false, null, "action obrigatorio para POST", 400);
    }

    response(false, null, "action desconhecida: " . sanitizeOutput($action), 400);

} catch (Exception $e) {
    error_log("[partner/estoque] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar estoque", 500);
}


// ═══════════════════════════════════════════════════════
//  DASHBOARD BUILDER
// ═══════════════════════════════════════════════════════

function buildDashboard(PDO $db, int $partnerId, bool $usesPriceModel): array {
    // --- Total products + inventory value ---
    if ($usesPriceModel) {
        $stmt = $db->prepare("
            SELECT COUNT(*) as total,
                   SUM(pp.price * COALESCE(s.quantidade, pp.stock)) as inventory_value
            FROM om_market_products_price pp
            LEFT JOIN om_market_product_stock s ON s.product_id = pp.product_id AND s.partner_id = pp.partner_id
            WHERE pp.partner_id = ?
        ");
    } else {
        $stmt = $db->prepare("
            SELECT COUNT(*) as total,
                   SUM(p.price * COALESCE(s.quantidade, COALESCE(p.quantity, p.stock, 0))) as inventory_value
            FROM om_market_products p
            LEFT JOIN om_market_product_stock s ON s.product_id = p.product_id AND s.partner_id = p.partner_id
            WHERE p.partner_id = ?
        ");
    }
    $stmt->execute([$partnerId]);
    $totals = $stmt->fetch();
    $totalProducts = (int)$totals['total'];
    $inventoryValue = round((float)($totals['inventory_value'] ?? 0), 2);

    // --- Products by stock status ---
    if ($usesPriceModel) {
        $stmt = $db->prepare("
            SELECT COALESCE(s.quantidade, pp.stock) as qty,
                   COALESCE(s.estoque_minimo, 0) as min_alert
            FROM om_market_products_price pp
            LEFT JOIN om_market_product_stock s ON s.product_id = pp.product_id AND s.partner_id = pp.partner_id
            WHERE pp.partner_id = ?
        ");
    } else {
        $stmt = $db->prepare("
            SELECT COALESCE(s.quantidade, COALESCE(p.quantity, p.stock, 0)) as qty,
                   COALESCE(s.estoque_minimo, 0) as min_alert
            FROM om_market_products p
            LEFT JOIN om_market_product_stock s ON s.product_id = p.product_id AND s.partner_id = p.partner_id
            WHERE p.partner_id = ?
        ");
    }
    $stmt->execute([$partnerId]);
    $allStocks = $stmt->fetchAll();

    $outOfStock = 0;
    $lowStock = 0;
    $healthyStock = 0;
    foreach ($allStocks as $row) {
        $qty = (int)$row['qty'];
        $min = (int)$row['min_alert'];
        if ($qty <= 0) {
            $outOfStock++;
        } elseif ($min > 0 && $qty <= $min) {
            $lowStock++;
        } else {
            $healthyStock++;
        }
    }

    // --- Top 10 fastest selling (last 30 days) ---
    if ($usesPriceModel) {
        $stmt = $db->prepare("
            SELECT oi.product_id, pb.name, pb.image, pp.price,
                   SUM(oi.quantity) as total_sold,
                   ROUND(SUM(oi.quantity)::numeric / 30, 2) as avg_daily_sales,
                   COALESCE(s.quantidade, pp.stock) as current_stock
            FROM om_market_order_items oi
            INNER JOIN om_market_orders o ON o.order_id = oi.order_id
            INNER JOIN om_market_products_base pb ON pb.product_id = oi.product_id
            INNER JOIN om_market_products_price pp ON pp.product_id = oi.product_id AND pp.partner_id = o.partner_id
            LEFT JOIN om_market_product_stock s ON s.product_id = oi.product_id AND s.partner_id = o.partner_id
            WHERE o.partner_id = ?
              AND o.status IN ('entregue', 'retirado', 'confirmado', 'pronto', 'em_entrega')
              AND o.created_at >= NOW() - INTERVAL '30 days'
            GROUP BY oi.product_id, pb.name, pb.image, pp.price, s.quantidade, pp.stock
            ORDER BY total_sold DESC
            LIMIT 10
        ");
    } else {
        $stmt = $db->prepare("
            SELECT oi.product_id, COALESCE(p.name, oi.product_name) as name,
                   COALESCE(p.image, oi.product_image) as image,
                   COALESCE(p.price, oi.unit_price) as price,
                   SUM(oi.quantity) as total_sold,
                   ROUND(SUM(oi.quantity)::numeric / 30, 2) as avg_daily_sales,
                   COALESCE(s.quantidade, COALESCE(p.quantity, p.stock, 0)) as current_stock
            FROM om_market_order_items oi
            INNER JOIN om_market_orders o ON o.order_id = oi.order_id
            LEFT JOIN om_market_products p ON p.product_id = oi.product_id AND p.partner_id = o.partner_id
            LEFT JOIN om_market_product_stock s ON s.product_id = oi.product_id AND s.partner_id = o.partner_id
            WHERE o.partner_id = ?
              AND o.status IN ('entregue', 'retirado', 'confirmado', 'pronto', 'em_entrega')
              AND o.created_at >= NOW() - INTERVAL '30 days'
            GROUP BY oi.product_id, p.name, oi.product_name, p.image, oi.product_image, p.price, oi.unit_price, s.quantidade, p.quantity, p.stock
            ORDER BY total_sold DESC
            LIMIT 10
        ");
    }
    $stmt->execute([$partnerId]);
    $topSellers = $stmt->fetchAll();

    foreach ($topSellers as &$ts) {
        $ts['product_id'] = (int)$ts['product_id'];
        $ts['price'] = (float)$ts['price'];
        $ts['total_sold'] = (int)$ts['total_sold'];
        $ts['avg_daily_sales'] = (float)$ts['avg_daily_sales'];
        $ts['current_stock'] = (int)$ts['current_stock'];
        // Days until out
        $ts['days_until_out'] = $ts['avg_daily_sales'] > 0
            ? (int)floor($ts['current_stock'] / $ts['avg_daily_sales'])
            : null;
    }
    unset($ts);

    // --- Recent movements (last 20) ---
    if ($usesPriceModel) {
        $stmt = $db->prepare("
            SELECT m.*, pb.name as product_name
            FROM om_market_stock_movements m
            LEFT JOIN om_market_products_base pb ON pb.product_id = m.product_id
            WHERE m.partner_id = ?
            ORDER BY m.created_at DESC
            LIMIT 20
        ");
    } else {
        $stmt = $db->prepare("
            SELECT m.*, p.name as product_name
            FROM om_market_stock_movements m
            LEFT JOIN om_market_products p ON p.product_id = m.product_id
            WHERE m.partner_id = ?
            ORDER BY m.created_at DESC
            LIMIT 20
        ");
    }
    $stmt->execute([$partnerId]);
    $recentMovements = $stmt->fetchAll();

    foreach ($recentMovements as &$mov) {
        $mov['id'] = (int)$mov['id'];
        $mov['product_id'] = (int)$mov['product_id'];
        $mov['quantidade_anterior'] = (int)$mov['quantidade_anterior'];
        $mov['quantidade_nova'] = (int)$mov['quantidade_nova'];
        $mov['quantidade_diff'] = (int)$mov['quantidade_diff'];
    }
    unset($mov);

    // --- Stock alerts (products needing attention) ---
    if ($usesPriceModel) {
        $stmt = $db->prepare("
            SELECT pb.product_id, pb.name, pp.price,
                   COALESCE(s.quantidade, pp.stock) as quantidade,
                   COALESCE(s.estoque_minimo, 0) as estoque_minimo
            FROM om_market_products_price pp
            INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id
            LEFT JOIN om_market_product_stock s ON s.product_id = pp.product_id AND s.partner_id = pp.partner_id
            WHERE pp.partner_id = ?
              AND (
                COALESCE(s.quantidade, pp.stock) <= 0
                OR (COALESCE(s.estoque_minimo, 0) > 0 AND COALESCE(s.quantidade, pp.stock) <= COALESCE(s.estoque_minimo, 0))
              )
            ORDER BY COALESCE(s.quantidade, pp.stock) ASC
            LIMIT 20
        ");
    } else {
        $stmt = $db->prepare("
            SELECT p.product_id, p.name, p.price,
                   COALESCE(s.quantidade, COALESCE(p.quantity, p.stock, 0)) as quantidade,
                   COALESCE(s.estoque_minimo, 0) as estoque_minimo
            FROM om_market_products p
            LEFT JOIN om_market_product_stock s ON s.product_id = p.product_id AND s.partner_id = p.partner_id
            WHERE p.partner_id = ?
              AND (
                COALESCE(s.quantidade, COALESCE(p.quantity, p.stock, 0)) <= 0
                OR (COALESCE(s.estoque_minimo, 0) > 0 AND COALESCE(s.quantidade, COALESCE(p.quantity, p.stock, 0)) <= COALESCE(s.estoque_minimo, 0))
              )
            ORDER BY COALESCE(s.quantidade, COALESCE(p.quantity, p.stock, 0)) ASC
            LIMIT 20
        ");
    }
    $stmt->execute([$partnerId]);
    $stockAlerts = $stmt->fetchAll();

    foreach ($stockAlerts as &$sa) {
        $sa['product_id'] = (int)$sa['product_id'];
        $sa['price'] = (float)$sa['price'];
        $sa['quantidade'] = (int)$sa['quantidade'];
        $sa['estoque_minimo'] = (int)$sa['estoque_minimo'];
        $sa['severity'] = $sa['quantidade'] <= 0 ? 'critical' : 'warning';
    }
    unset($sa);

    return [
        "summary" => [
            "total_products" => $totalProducts,
            "out_of_stock" => $outOfStock,
            "low_stock" => $lowStock,
            "healthy_stock" => $healthyStock,
            "inventory_value" => $inventoryValue,
        ],
        "top_sellers" => $topSellers,
        "stock_alerts" => $stockAlerts,
        "recent_movements" => $recentMovements,
    ];
}


// ═══════════════════════════════════════════════════════
//  PRODUCTS LIST WITH VELOCITY & FORECAST
// ═══════════════════════════════════════════════════════

function getProductsList(PDO $db, int $partnerId, bool $usesPriceModel, string $search, int $limit, int $offset, string $filter, string $sort, int $categoryId): array {
    // Build sales velocity subquery (last 30 days)
    $velocitySql = "
        SELECT oi.product_id,
               SUM(oi.quantity) as total_sold_30d,
               ROUND(SUM(oi.quantity)::numeric / 30, 2) as avg_daily_sales,
               MAX(o.created_at) as last_sold_date
        FROM om_market_order_items oi
        INNER JOIN om_market_orders o ON o.order_id = oi.order_id
        WHERE o.partner_id = ?
          AND o.status IN ('entregue', 'retirado', 'confirmado', 'pronto', 'em_entrega')
          AND o.created_at >= NOW() - INTERVAL '30 days'
        GROUP BY oi.product_id
    ";

    if ($usesPriceModel) {
        $where = ["pp.partner_id = ?"];
        $params = [$partnerId, $partnerId]; // first for velocity CTE, second for main query

        if ($search !== '') {
            $searchEsc = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $where[] = "(pb.name ILIKE ? OR pb.barcode ILIKE ?)";
            $searchParam = "%{$searchEsc}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        if ($categoryId > 0) {
            $where[] = "pb.category_id = ?";
            $params[] = $categoryId;
        }

        $whereSQL = implode(" AND ", $where);

        // Count
        $countParams = array_slice($params, 1); // skip velocity param
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_market_products_price pp INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id WHERE {$whereSQL}");
        $stmtCount->execute($countParams);
        $total = (int)$stmtCount->fetchColumn();

        // Sort clause
        $orderBy = match($sort) {
            'stock_asc' => "quantidade ASC, pb.name ASC",
            'stock_desc' => "quantidade DESC, pb.name ASC",
            'velocity' => "COALESCE(v.avg_daily_sales, 0) DESC, pb.name ASC",
            'forecast' => "CASE WHEN COALESCE(v.avg_daily_sales, 0) > 0 THEN COALESCE(s.quantidade, pp.stock)::numeric / v.avg_daily_sales ELSE 999999 END ASC, pb.name ASC",
            'price_asc' => "pp.price ASC",
            'price_desc' => "pp.price DESC",
            default => "pb.name ASC",
        };

        // Apply stock filter
        $havingClause = "";
        if ($filter === 'low_stock') {
            $where[] = "COALESCE(s.estoque_minimo, 0) > 0 AND COALESCE(s.quantidade, pp.stock) <= COALESCE(s.estoque_minimo, 0) AND COALESCE(s.quantidade, pp.stock) > 0";
        } elseif ($filter === 'out_of_stock') {
            $where[] = "COALESCE(s.quantidade, pp.stock) <= 0";
        } elseif ($filter === 'excess') {
            // Excess: stock > 3x min_stock AND min_stock > 0 AND low sales velocity
            $where[] = "COALESCE(s.estoque_minimo, 0) > 0 AND COALESCE(s.quantidade, pp.stock) > COALESCE(s.estoque_minimo, 0) * 3";
        }
        $whereSQL = implode(" AND ", $where);
        $countParams = array_slice($params, 1);

        $stmt = $db->prepare("
            WITH velocity AS ({$velocitySql})
            SELECT pb.product_id, pb.name, pb.image, pb.barcode,
                   COALESCE(pb.category_id, 0) as category_id,
                   (SELECT c.name FROM om_market_categories c WHERE c.category_id = pb.category_id LIMIT 1) as category_name,
                   pp.price, pp.price_promo as promotional_price,
                   pp.stock as product_stock,
                   pp.status,
                   COALESCE(s.quantidade, pp.stock) as quantidade,
                   COALESCE(s.estoque_minimo, 0) as estoque_minimo,
                   COALESCE(s.auto_pausar, false) as auto_pausar,
                   s.ultima_movimentacao,
                   COALESCE(v.total_sold_30d, 0) as total_sold_30d,
                   COALESCE(v.avg_daily_sales, 0) as avg_daily_sales,
                   v.last_sold_date
            FROM om_market_products_price pp
            INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id
            LEFT JOIN om_market_product_stock s ON s.product_id = pp.product_id AND s.partner_id = pp.partner_id
            LEFT JOIN velocity v ON v.product_id = pp.product_id
            WHERE {$whereSQL}
            ORDER BY {$orderBy}
            LIMIT ? OFFSET ?
        ");
    } else {
        $where = ["p.partner_id = ?"];
        $params = [$partnerId, $partnerId]; // first for velocity CTE

        if ($search !== '') {
            $searchEsc = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $where[] = "(p.name ILIKE ? OR p.barcode ILIKE ?)";
            $searchParam = "%{$searchEsc}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        if ($categoryId > 0) {
            $where[] = "p.category_id = ?";
            $params[] = $categoryId;
        }

        $whereSQL = implode(" AND ", $where);

        $countParams = array_slice($params, 1);
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_market_products p WHERE {$whereSQL}");
        $stmtCount->execute($countParams);
        $total = (int)$stmtCount->fetchColumn();

        $orderBy = match($sort) {
            'stock_asc' => "quantidade ASC, p.name ASC",
            'stock_desc' => "quantidade DESC, p.name ASC",
            'velocity' => "COALESCE(v.avg_daily_sales, 0) DESC, p.name ASC",
            'forecast' => "CASE WHEN COALESCE(v.avg_daily_sales, 0) > 0 THEN COALESCE(s.quantidade, COALESCE(p.quantity, p.stock, 0))::numeric / v.avg_daily_sales ELSE 999999 END ASC, p.name ASC",
            'price_asc' => "p.price ASC",
            'price_desc' => "p.price DESC",
            default => "p.name ASC",
        };

        if ($filter === 'low_stock') {
            $where[] = "COALESCE(s.estoque_minimo, 0) > 0 AND COALESCE(s.quantidade, COALESCE(p.quantity, p.stock, 0)) <= COALESCE(s.estoque_minimo, 0) AND COALESCE(s.quantidade, COALESCE(p.quantity, p.stock, 0)) > 0";
        } elseif ($filter === 'out_of_stock') {
            $where[] = "COALESCE(s.quantidade, COALESCE(p.quantity, p.stock, 0)) <= 0";
        } elseif ($filter === 'excess') {
            $where[] = "COALESCE(s.estoque_minimo, 0) > 0 AND COALESCE(s.quantidade, COALESCE(p.quantity, p.stock, 0)) > COALESCE(s.estoque_minimo, 0) * 3";
        }
        $whereSQL = implode(" AND ", $where);
        $countParams = array_slice($params, 1);

        $stmt = $db->prepare("
            WITH velocity AS ({$velocitySql})
            SELECT p.product_id, p.name, p.image, p.barcode,
                   COALESCE(p.category_id, 0) as category_id,
                   COALESCE(p.category, (SELECT c.name FROM om_market_categories c WHERE c.category_id = p.category_id LIMIT 1)) as category_name,
                   p.price, p.special_price as promotional_price,
                   COALESCE(p.quantity, p.stock, 0) as product_stock,
                   COALESCE(p.status, 1) as status,
                   COALESCE(s.quantidade, COALESCE(p.quantity, p.stock, 0)) as quantidade,
                   COALESCE(s.estoque_minimo, 0) as estoque_minimo,
                   COALESCE(s.auto_pausar, false) as auto_pausar,
                   s.ultima_movimentacao,
                   COALESCE(v.total_sold_30d, 0) as total_sold_30d,
                   COALESCE(v.avg_daily_sales, 0) as avg_daily_sales,
                   v.last_sold_date
            FROM om_market_products p
            LEFT JOIN om_market_product_stock s ON s.product_id = p.product_id AND s.partner_id = p.partner_id
            LEFT JOIN velocity v ON v.product_id = p.product_id
            WHERE {$whereSQL}
            ORDER BY {$orderBy}
            LIMIT ? OFFSET ?
        ");
    }

    $stmt->execute(array_merge($params, [$limit, $offset]));
    $items = $stmt->fetchAll();

    $products = [];
    foreach ($items as $item) {
        $qty = (int)$item['quantidade'];
        $minAlert = (int)$item['estoque_minimo'];
        $avgDaily = (float)$item['avg_daily_sales'];

        $status = 'ok';
        if ($minAlert > 0) {
            if ($qty <= 0) {
                $status = 'esgotado';
            } elseif ($qty <= $minAlert) {
                $status = 'baixo';
            } elseif ($qty <= $minAlert * 1.5) {
                $status = 'atencao';
            } elseif ($qty > $minAlert * 3) {
                $status = 'excesso';
            }
        } else {
            if ($qty <= 0) {
                $status = 'esgotado';
            }
        }

        $daysUntilOut = null;
        if ($avgDaily > 0 && $qty > 0) {
            $daysUntilOut = (int)floor($qty / $avgDaily);
        }

        $products[] = [
            "product_id" => (int)$item['product_id'],
            "name" => $item['name'],
            "image" => $item['image'],
            "barcode" => $item['barcode'] ?? null,
            "category_id" => (int)$item['category_id'],
            "category_name" => $item['category_name'] ?? null,
            "price" => (float)$item['price'],
            "promotional_price" => $item['promotional_price'] ? (float)$item['promotional_price'] : null,
            "product_stock" => (int)$item['product_stock'],
            "status" => (int)$item['status'],
            "quantidade" => $qty,
            "estoque_minimo" => $minAlert,
            "auto_pausar" => (bool)$item['auto_pausar'],
            "ultima_movimentacao" => $item['ultima_movimentacao'],
            "total_sold_30d" => (int)$item['total_sold_30d'],
            "avg_daily_sales" => $avgDaily,
            "last_sold_date" => $item['last_sold_date'],
            "days_until_out" => $daysUntilOut,
            "stock_status" => $status,
        ];
    }

    return [
        "products" => $products,
        "total" => $total,
        "limit" => $limit,
        "offset" => $offset,
    ];
}


// ═══════════════════════════════════════════════════════
//  AI ANALYSIS
// ═══════════════════════════════════════════════════════

function runAiAnalysis(PDO $db, int $partnerId, bool $usesPriceModel): array {
    // Load Claude client
    $claudePath = __DIR__ . "/../helpers/claude-client.php";
    if (!file_exists($claudePath)) {
        return ["error" => "IA nao disponivel", "analysis" => null];
    }
    require_once $claudePath;

    $claude = new ClaudeClient();

    // Gather data: all products with stock
    if ($usesPriceModel) {
        $stmt = $db->prepare("
            SELECT pb.product_id, pb.name, pp.price,
                   COALESCE(s.quantidade, pp.stock) as stock,
                   COALESCE(s.estoque_minimo, 0) as min_stock
            FROM om_market_products_price pp
            INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id
            LEFT JOIN om_market_product_stock s ON s.product_id = pp.product_id AND s.partner_id = pp.partner_id
            WHERE pp.partner_id = ?
            ORDER BY pb.name
        ");
    } else {
        $stmt = $db->prepare("
            SELECT p.product_id, p.name, p.price,
                   COALESCE(s.quantidade, COALESCE(p.quantity, p.stock, 0)) as stock,
                   COALESCE(s.estoque_minimo, 0) as min_stock
            FROM om_market_products p
            LEFT JOIN om_market_product_stock s ON s.product_id = p.product_id AND s.partner_id = p.partner_id
            WHERE p.partner_id = ?
            ORDER BY p.name
        ");
    }
    $stmt->execute([$partnerId]);
    $products = $stmt->fetchAll();

    // Sales data last 90 days
    $stmt = $db->prepare("
        SELECT oi.product_id,
               COALESCE(p2.name, oi.product_name) as name,
               SUM(oi.quantity) as total_sold,
               COUNT(DISTINCT o.order_id) as order_count,
               SUM(oi.total_price) as total_revenue,
               MIN(o.created_at) as first_sale,
               MAX(o.created_at) as last_sale,
               -- Weekly breakdown
               SUM(CASE WHEN o.created_at >= NOW() - INTERVAL '7 days' THEN oi.quantity ELSE 0 END) as sold_7d,
               SUM(CASE WHEN o.created_at >= NOW() - INTERVAL '30 days' THEN oi.quantity ELSE 0 END) as sold_30d,
               SUM(CASE WHEN o.created_at >= NOW() - INTERVAL '60 days' AND o.created_at < NOW() - INTERVAL '30 days' THEN oi.quantity ELSE 0 END) as sold_30_60d
        FROM om_market_order_items oi
        INNER JOIN om_market_orders o ON o.order_id = oi.order_id
        LEFT JOIN om_market_products p2 ON p2.product_id = oi.product_id
        WHERE o.partner_id = ?
          AND o.status IN ('entregue', 'retirado', 'confirmado', 'pronto', 'em_entrega')
          AND o.created_at >= NOW() - INTERVAL '90 days'
        GROUP BY oi.product_id, p2.name, oi.product_name
        ORDER BY total_sold DESC
    ");
    $stmt->execute([$partnerId]);
    $salesData = $stmt->fetchAll();

    // Current alerts count
    $alertCount = 0;
    foreach ($products as $p) {
        if ((int)$p['stock'] <= 0 || ((int)$p['min_stock'] > 0 && (int)$p['stock'] <= (int)$p['min_stock'])) {
            $alertCount++;
        }
    }

    // Format data for Claude
    $productsSummary = [];
    foreach ($products as $p) {
        $productsSummary[] = [
            'id' => (int)$p['product_id'],
            'name' => $p['name'],
            'price' => (float)$p['price'],
            'stock' => (int)$p['stock'],
            'min_stock' => (int)$p['min_stock'],
        ];
    }

    $salesSummary = [];
    foreach ($salesData as $s) {
        $salesSummary[] = [
            'product_id' => (int)$s['product_id'],
            'name' => $s['name'],
            'total_sold_90d' => (int)$s['total_sold'],
            'sold_last_7d' => (int)$s['sold_7d'],
            'sold_last_30d' => (int)$s['sold_30d'],
            'sold_30_to_60d' => (int)$s['sold_30_60d'],
            'order_count' => (int)$s['order_count'],
            'revenue' => round((float)$s['total_revenue'], 2),
            'last_sale' => $s['last_sale'],
        ];
    }

    $systemPrompt = "Voce e um analista de estoque expert para um mercado/loja online brasileiro. Analise os dados de estoque e vendas e forneca recomendacoes praticas em portugues brasileiro. Retorne APENAS JSON valido, sem texto adicional.";

    $userMessage = "Analise o estoque desta loja e retorne um JSON com a seguinte estrutura:
{
  \"resumo\": \"string - resumo geral em 2-3 frases\",
  \"vai_acabar\": [
    {\"product_id\": int, \"name\": \"string\", \"stock_atual\": int, \"vendas_dia\": float, \"dias_restantes\": int, \"quantidade_sugerida\": int, \"urgencia\": \"critica|alta|media\"}
  ],
  \"excesso_estoque\": [
    {\"product_id\": int, \"name\": \"string\", \"stock_atual\": int, \"vendas_30d\": int, \"dias_cobertura\": int, \"sugestao\": \"string\"}
  ],
  \"tendencias\": [
    {\"observacao\": \"string\", \"tipo\": \"crescimento|queda|sazonal|oportunidade\"}
  ],
  \"recomendacoes\": [
    {\"titulo\": \"string\", \"descricao\": \"string\", \"prioridade\": \"alta|media|baixa\", \"acao\": \"repor|reduzir|ajustar_minimo|promocao\", \"product_id\": int|null, \"quantidade_sugerida\": int|null}
  ],
  \"score_saude\": int (0-100, saude geral do estoque)
}

Dados do estoque atual (" . count($productsSummary) . " produtos, " . $alertCount . " alertas ativos):
" . json_encode($productsSummary, JSON_UNESCAPED_UNICODE) . "

Dados de vendas dos ultimos 90 dias:
" . json_encode($salesSummary, JSON_UNESCAPED_UNICODE) . "

Regras importantes:
- Priorize produtos que vao acabar em menos de 7 dias como urgencia critica
- Produtos com estoque > 90 dias de cobertura podem ser excesso
- Considere que vendas_7d vs vendas_30d pode indicar tendencia de aceleracao ou desaceleracao
- Sugira quantidade de reposicao para 30 dias de cobertura
- Se nao ha dados de venda para um produto com estoque baixo, assuma venda media de 1/dia
- Maximo 10 itens em vai_acabar, 10 em excesso, 5 em tendencias, 8 em recomendacoes";

    $messages = [['role' => 'user', 'content' => $userMessage]];

    $result = $claude->send($systemPrompt, $messages, 4096);

    if (!$result['success']) {
        error_log("[estoque/ai] Claude error: " . ($result['error'] ?? 'unknown'));
        return [
            "error" => "Falha na analise de IA: " . ($result['error'] ?? 'erro desconhecido'),
            "analysis" => null,
        ];
    }

    $analysis = ClaudeClient::parseJson($result['text']);

    if (!$analysis) {
        error_log("[estoque/ai] Failed to parse Claude response: " . substr($result['text'], 0, 500));
        return [
            "error" => "Resposta da IA nao pode ser processada",
            "analysis" => null,
            "raw" => $result['text'],
        ];
    }

    return [
        "analysis" => $analysis,
        "tokens_used" => $result['total_tokens'] ?? 0,
        "model" => $result['model'] ?? 'unknown',
    ];
}


// ═══════════════════════════════════════════════════════
//  HELPER FUNCTIONS
// ═══════════════════════════════════════════════════════

function ensureStockTables(PDO $db): void {
    // Create om_stock_alerts if not exists (new table)
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = 'public' AND table_name = 'om_stock_alerts'
        ");
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS om_stock_alerts (
                    id SERIAL PRIMARY KEY,
                    partner_id INTEGER NOT NULL,
                    product_id INTEGER NOT NULL,
                    min_stock INTEGER DEFAULT 5,
                    enabled BOOLEAN DEFAULT TRUE,
                    notified_at TIMESTAMP,
                    created_at TIMESTAMP DEFAULT NOW(),
                    UNIQUE(partner_id, product_id)
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_stock_alerts_partner ON om_stock_alerts(partner_id)");
        }
    } catch (Exception $e) {
        error_log("[estoque] Failed to create om_stock_alerts: " . $e->getMessage());
    }

    // Ensure om_market_product_stock has correct unique constraint
    // (existing table may have product_id only unique instead of partner_id+product_id)
    // This is a no-op if already correct
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = 'public' AND table_name = 'om_market_product_stock'
        ");
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS om_market_product_stock (
                    id SERIAL PRIMARY KEY,
                    product_id INTEGER NOT NULL,
                    partner_id INTEGER NOT NULL,
                    quantidade INTEGER DEFAULT 0,
                    estoque_minimo INTEGER DEFAULT 0,
                    auto_pausar BOOLEAN DEFAULT false,
                    ultima_movimentacao TIMESTAMP,
                    created_at TIMESTAMP DEFAULT NOW(),
                    updated_at TIMESTAMP DEFAULT NOW(),
                    UNIQUE(partner_id, product_id)
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_stock_partner ON om_market_product_stock(partner_id)");
        }
    } catch (Exception $e) {
        // Table exists, that's fine
    }

    // Ensure om_partner_ingredients exists
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = 'public' AND table_name = 'om_partner_ingredients'
        ");
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS om_partner_ingredients (
                    id SERIAL PRIMARY KEY,
                    partner_id INTEGER NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    barcode VARCHAR(50),
                    unit VARCHAR(20) DEFAULT 'un',
                    current_stock DECIMAL(10,3) DEFAULT 0,
                    min_stock DECIMAL(10,3) DEFAULT 0,
                    cost_per_unit DECIMAL(10,2) DEFAULT 0,
                    supplier VARCHAR(255),
                    category VARCHAR(100),
                    last_restock_at TIMESTAMP,
                    expires_at DATE,
                    image VARCHAR(500),
                    notes TEXT,
                    active BOOLEAN DEFAULT TRUE,
                    created_at TIMESTAMP DEFAULT NOW(),
                    updated_at TIMESTAMP DEFAULT NOW()
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_ingredients_partner ON om_partner_ingredients(partner_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_ingredients_barcode ON om_partner_ingredients(barcode)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_ingredients_category ON om_partner_ingredients(partner_id, category)");
        }
    } catch (Exception $e) {
        error_log("[estoque] Failed to create om_partner_ingredients: " . $e->getMessage());
    }

    // Ensure om_ingredient_movements exists
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = 'public' AND table_name = 'om_ingredient_movements'
        ");
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS om_ingredient_movements (
                    id SERIAL PRIMARY KEY,
                    partner_id INTEGER NOT NULL,
                    ingredient_id INTEGER NOT NULL,
                    type VARCHAR(20) NOT NULL,
                    quantity DECIMAL(10,3) NOT NULL,
                    previous_stock DECIMAL(10,3),
                    new_stock DECIMAL(10,3),
                    reason TEXT,
                    cost DECIMAL(10,2),
                    barcode_scanned BOOLEAN DEFAULT FALSE,
                    created_by INTEGER,
                    created_at TIMESTAMP DEFAULT NOW()
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_ing_movements_partner ON om_ingredient_movements(partner_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_ing_movements_ingredient ON om_ingredient_movements(ingredient_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_ing_movements_created ON om_ingredient_movements(created_at DESC)");
        }
    } catch (Exception $e) {
        error_log("[estoque] Failed to create om_ingredient_movements: " . $e->getMessage());
    }

    // Ensure om_product_ingredients exists
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = 'public' AND table_name = 'om_product_ingredients'
        ");
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS om_product_ingredients (
                    id SERIAL PRIMARY KEY,
                    partner_id INTEGER NOT NULL,
                    product_id INTEGER NOT NULL,
                    ingredient_id INTEGER NOT NULL,
                    quantity_used DECIMAL(10,3) NOT NULL,
                    UNIQUE(product_id, ingredient_id)
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_prod_ingredients_partner ON om_product_ingredients(partner_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_prod_ingredients_product ON om_product_ingredients(product_id)");
        }
    } catch (Exception $e) {
        error_log("[estoque] Failed to create om_product_ingredients: " . $e->getMessage());
    }

    // Ensure om_market_stock_movements exists
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = 'public' AND table_name = 'om_market_stock_movements'
        ");
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS om_market_stock_movements (
                    id SERIAL PRIMARY KEY,
                    product_id INTEGER NOT NULL,
                    partner_id INTEGER NOT NULL,
                    tipo VARCHAR(20) NOT NULL,
                    quantidade_anterior INTEGER,
                    quantidade_nova INTEGER,
                    quantidade_diff INTEGER,
                    motivo VARCHAR(255),
                    created_at TIMESTAMP DEFAULT NOW()
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_movements_partner ON om_market_stock_movements(partner_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_movements_product ON om_market_stock_movements(product_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_movements_created ON om_market_stock_movements(created_at DESC)");
        }
    } catch (Exception $e) {
        // Table exists, that's fine
    }
}

function verifyProductOwnership(PDO $db, int $productId, int $partnerId, bool $usesPriceModel): bool {
    if ($usesPriceModel) {
        $stmt = $db->prepare("SELECT product_id FROM om_market_products_price WHERE product_id = ? AND partner_id = ?");
    } else {
        $stmt = $db->prepare("SELECT product_id FROM om_market_products WHERE product_id = ? AND partner_id = ?");
    }
    $stmt->execute([$productId, $partnerId]);
    return (bool)$stmt->fetch();
}

function getCurrentStock(PDO $db, int $productId, int $partnerId, bool $usesPriceModel): int {
    $stmt = $db->prepare("SELECT quantidade FROM om_market_product_stock WHERE product_id = ? AND partner_id = ? FOR UPDATE");
    $stmt->execute([$productId, $partnerId]);
    $row = $stmt->fetch();
    if ($row) {
        return (int)$row['quantidade'];
    }

    if ($usesPriceModel) {
        $stmt = $db->prepare("SELECT stock FROM om_market_products_price WHERE product_id = ? AND partner_id = ?");
        $stmt->execute([$productId, $partnerId]);
        $row = $stmt->fetch();
        return $row ? (int)$row['stock'] : 0;
    } else {
        $stmt = $db->prepare("SELECT COALESCE(quantity, stock, 0) as stock FROM om_market_products WHERE product_id = ? AND partner_id = ?");
        $stmt->execute([$productId, $partnerId]);
        $row = $stmt->fetch();
        return $row ? (int)$row['stock'] : 0;
    }
}

function updateMainProductStock(PDO $db, int $productId, int $newQty, bool $usesPriceModel, int $partnerId = 0): void {
    if ($usesPriceModel) {
        $stmt = $db->prepare("UPDATE om_market_products_price SET stock = ?, date_modified = NOW() WHERE product_id = ? AND partner_id = ?");
        $stmt->execute([$newQty, $productId, $partnerId]);
    } else {
        $stmt = $db->prepare("UPDATE om_market_products SET stock = ?, quantity = ?, date_modified = NOW() WHERE product_id = ? AND partner_id = ?");
        $stmt->execute([$newQty, $newQty, $productId, $partnerId]);
    }
}

function checkAutoPause(PDO $db, int $productId, int $partnerId, int $currentQty, bool $usesPriceModel): void {
    $stmt = $db->prepare("SELECT estoque_minimo, auto_pausar FROM om_market_product_stock WHERE product_id = ? AND partner_id = ?");
    $stmt->execute([$productId, $partnerId]);
    $config = $stmt->fetch();

    if (!$config || !$config['auto_pausar']) return;

    $minimo = (int)$config['estoque_minimo'];
    if ($minimo <= 0) return;

    if ($currentQty <= $minimo) {
        if ($usesPriceModel) {
            $stmt = $db->prepare("UPDATE om_market_products_price SET status = 0 WHERE product_id = ? AND partner_id = ?");
        } else {
            $stmt = $db->prepare("UPDATE om_market_products SET status = 0 WHERE product_id = ? AND partner_id = ?");
        }
        $stmt->execute([$productId, $partnerId]);
        error_log("[estoque] Auto-paused product {$productId} (stock={$currentQty}, min={$minimo})");
    } elseif ($currentQty > $minimo) {
        if ($usesPriceModel) {
            $stmt = $db->prepare("UPDATE om_market_products_price SET status = 1 WHERE product_id = ? AND partner_id = ? AND status = 0");
        } else {
            $stmt = $db->prepare("UPDATE om_market_products SET status = 1 WHERE product_id = ? AND partner_id = ? AND status = 0");
        }
        $stmt->execute([$productId, $partnerId]);
    }
}


// ═══════════════════════════════════════════════════════
//  AI INGREDIENT ANALYSIS
// ═══════════════════════════════════════════════════════

function runAiIngredientAnalysis(PDO $db, int $partnerId): array {
    $claudePath = __DIR__ . "/../helpers/claude-client.php";
    if (!file_exists($claudePath)) {
        return ["error" => "IA nao disponivel", "analysis" => null];
    }
    require_once $claudePath;
    $claude = new ClaudeClient();

    // Gather ingredients data
    $stmt = $db->prepare("
        SELECT i.id, i.name, i.unit, i.current_stock, i.min_stock, i.cost_per_unit,
               i.supplier, i.category, i.expires_at, i.last_restock_at
        FROM om_partner_ingredients i
        WHERE i.partner_id = ? AND i.active = true
        ORDER BY i.name
    ");
    $stmt->execute([$partnerId]);
    $ingredients = $stmt->fetchAll();

    if (empty($ingredients)) {
        return ["error" => null, "analysis" => ["resumo" => "Nenhum ingrediente cadastrado ainda. Adicione ingredientes para receber analises.", "recomendacoes" => []]];
    }

    // Movement data last 60 days
    $stmt = $db->prepare("
        SELECT m.ingredient_id, i.name,
               SUM(CASE WHEN m.type = 'entrada' THEN m.quantity ELSE 0 END) as total_entrada,
               SUM(CASE WHEN m.type IN ('saida', 'perda') THEN m.quantity ELSE 0 END) as total_saida,
               SUM(CASE WHEN m.type = 'perda' THEN m.quantity ELSE 0 END) as total_perda,
               SUM(CASE WHEN m.type = 'entrada' THEN COALESCE(m.cost, 0) ELSE 0 END) as total_spent,
               COUNT(*) as movement_count
        FROM om_ingredient_movements m
        INNER JOIN om_partner_ingredients i ON i.id = m.ingredient_id
        WHERE m.partner_id = ? AND m.created_at >= NOW() - INTERVAL '60 days'
        GROUP BY m.ingredient_id, i.name
    ");
    $stmt->execute([$partnerId]);
    $movementData = $stmt->fetchAll();

    // Product cost data
    $stmt = $db->prepare("
        SELECT pi.product_id, p.name as product_name, p.price,
               SUM(pi.quantity_used * i.cost_per_unit) as ingredient_cost
        FROM om_product_ingredients pi
        INNER JOIN om_partner_ingredients i ON i.id = pi.ingredient_id
        LEFT JOIN om_market_products p ON p.product_id = pi.product_id
        WHERE pi.partner_id = ?
        GROUP BY pi.product_id, p.name, p.price
    ");
    $stmt->execute([$partnerId]);
    $productCosts = $stmt->fetchAll();

    $ingredientsSummary = [];
    foreach ($ingredients as $i) {
        $ingredientsSummary[] = [
            'id' => (int)$i['id'],
            'name' => $i['name'],
            'unit' => $i['unit'],
            'stock' => (float)$i['current_stock'],
            'min_stock' => (float)$i['min_stock'],
            'cost' => (float)$i['cost_per_unit'],
            'supplier' => $i['supplier'],
            'category' => $i['category'],
            'expires_at' => $i['expires_at'],
        ];
    }

    $systemPrompt = "Voce e um consultor de custos e ingredientes expert para restaurantes e mercados brasileiros. Analise os dados e forneca recomendacoes praticas em portugues brasileiro. Lembre-se: o app SuperBora pode ter os mesmos ingredientes por precos menores para o parceiro comprar. Retorne APENAS JSON valido.";

    $userMessage = "Analise os ingredientes deste restaurante/loja e retorne JSON:
{
  \"resumo\": \"string - resumo geral em 2-3 frases\",
  \"gastos_mensais\": float,
  \"ingredientes_criticos\": [
    {\"id\": int, \"name\": \"string\", \"problema\": \"string\", \"sugestao\": \"string\", \"urgencia\": \"alta|media|baixa\"}
  ],
  \"economia_sugerida\": [
    {\"descricao\": \"string\", \"economia_estimada\": float, \"tipo\": \"fornecedor|quantidade|substituicao|reducao_perda\"}
  ],
  \"analise_desperdicio\": {
    \"total_perdas_60d\": float,
    \"percentual_perda\": float,
    \"itens_mais_desperdicados\": [{\"name\": \"string\", \"quantidade_perdida\": float, \"valor_perdido\": float}]
  },
  \"dica_superbora\": \"string - mencione que ingredientes basicos podem ser encontrados no SuperBora app com precos competitivos\",
  \"recomendacoes\": [
    {\"titulo\": \"string\", \"descricao\": \"string\", \"prioridade\": \"alta|media|baixa\", \"tipo\": \"comprar|substituir|reduzir_perda|negociar_fornecedor\"}
  ]
}

Ingredientes (" . count($ingredientsSummary) . "):
" . json_encode($ingredientsSummary, JSON_UNESCAPED_UNICODE) . "

Movimentacoes 60 dias:
" . json_encode($movementData, JSON_UNESCAPED_UNICODE) . "

Custo por produto:
" . json_encode($productCosts, JSON_UNESCAPED_UNICODE) . "

Regras:
- Ingredientes vencendo em 7 dias sao urgencia alta
- Perdas acima de 10% indicam problema de gestao
- Sugira alternativas mais baratas quando possivel
- Mencione que o app SuperBora pode ter ingredientes por bons precos
- Maximo 8 ingredientes_criticos, 5 economia_sugerida, 6 recomendacoes";

    $messages = [['role' => 'user', 'content' => $userMessage]];
    $result = $claude->send($systemPrompt, $messages, 4096);

    if (!$result['success']) {
        return ["error" => "Falha na analise: " . ($result['error'] ?? 'erro desconhecido'), "analysis" => null];
    }

    $analysis = ClaudeClient::parseJson($result['text']);
    if (!$analysis) {
        return ["error" => "Resposta da IA nao pode ser processada", "analysis" => null, "raw" => $result['text']];
    }

    return ["analysis" => $analysis, "tokens_used" => $result['total_tokens'] ?? 0];
}
