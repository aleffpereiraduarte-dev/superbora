<?php
/**
 * /api/mercado/admin/cestas/tipos.php
 * CRUD for basket types (admin only).
 *
 * GET    — List all basket types (with items + variants counts)
 * POST   — Create new basket type
 * PUT    — Update existing basket type
 * DELETE — Deactivate basket type
 */
require_once __DIR__ . "/../../config/database.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requireAdmin();

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // ── Ensure tables exist (self-healing) ────────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_cesta_items (
            id SERIAL PRIMARY KEY,
            box_id INT NOT NULL,
            product_name VARCHAR(200) NOT NULL,
            quantity NUMERIC(10,2) NOT NULL DEFAULT 1,
            unit VARCHAR(30) NOT NULL DEFAULT 'un',
            supplier VARCHAR(200),
            cost_price NUMERIC(10,2) DEFAULT 0,
            is_optional BOOLEAN DEFAULT false,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS om_cesta_variants (
            id SERIAL PRIMARY KEY,
            box_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            size_label VARCHAR(50),
            price_multiplier NUMERIC(4,2) DEFAULT 1.00,
            price_override NUMERIC(10,2),
            is_active BOOLEAN DEFAULT true,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT NOW()
        );
    ");

    // ── GET: List basket types ────────────────────────────────────
    if ($method === 'GET') {
        $category = $_GET['category'] ?? null;
        $includeInactive = ($_GET['include_inactive'] ?? '0') === '1';
        $search = $_GET['search'] ?? null;

        $where = [];
        $params = [];

        if (!$includeInactive) {
            $where[] = "b.is_active = true";
        }
        if ($category) {
            $where[] = "b.category = ?";
            $params[] = $category;
        }
        if ($search) {
            $where[] = "(b.name ILIKE ? OR b.description ILIKE ?)";
            $s = "%{$search}%";
            $params[] = $s;
            $params[] = $s;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $db->prepare("
            SELECT b.*,
                   (SELECT COUNT(*) FROM om_cesta_items i WHERE i.box_id = b.id) AS items_count,
                   (SELECT COUNT(*) FROM om_cesta_variants v WHERE v.box_id = b.id AND v.is_active = true) AS variants_count,
                   (SELECT COUNT(*) FROM om_box_subscriptions s WHERE s.box_id = b.id AND s.status = 'active') AS active_subscribers
            FROM om_subscription_boxes b
            {$whereSql}
            ORDER BY b.sort_order ASC, b.name ASC
        ");
        $stmt->execute($params);
        $types = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($types as &$t) {
            $t['base_price'] = (float)$t['base_price'];
            $t['full_price'] = $t['full_price'] !== null ? (float)$t['full_price'] : null;
            $t['items_count'] = (int)$t['items_count'];
            $t['variants_count'] = (int)$t['variants_count'];
            $t['active_subscribers'] = (int)$t['active_subscribers'];
            if (is_string($t['sample_items'])) {
                $t['sample_items'] = json_decode($t['sample_items'], true) ?: [];
            }

            // Fetch items
            $stmtItems = $db->prepare("SELECT * FROM om_cesta_items WHERE box_id = ? ORDER BY sort_order ASC, product_name ASC");
            $stmtItems->execute([$t['id']]);
            $t['items'] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
            foreach ($t['items'] as &$item) {
                $item['quantity'] = (float)$item['quantity'];
                $item['cost_price'] = (float)$item['cost_price'];
            }

            // Fetch variants
            $stmtVars = $db->prepare("SELECT * FROM om_cesta_variants WHERE box_id = ? ORDER BY sort_order ASC");
            $stmtVars->execute([$t['id']]);
            $t['variants'] = $stmtVars->fetchAll(PDO::FETCH_ASSOC);
            foreach ($t['variants'] as &$v) {
                $v['price_multiplier'] = (float)$v['price_multiplier'];
                $v['price_override'] = $v['price_override'] !== null ? (float)$v['price_override'] : null;
            }
        }

        response(true, ['types' => $types]);
    }

    // ── POST: Create basket type ──────────────────────────────────
    if ($method === 'POST') {
        $input = getInput();

        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');
        $category = trim($input['category'] ?? 'cafe_manha');
        $basePrice = (float)($input['base_price'] ?? 0);
        $fullPrice = isset($input['full_price']) ? (float)$input['full_price'] : null;
        $image = trim($input['image'] ?? '');
        $tagline = trim($input['tagline'] ?? '');
        $badge = trim($input['badge'] ?? '');
        $isSeasonal = (bool)($input['is_seasonal'] ?? false);
        $seasonalStart = $input['seasonal_start'] ?? null;
        $seasonalEnd = $input['seasonal_end'] ?? null;
        $sampleItems = $input['sample_items'] ?? [];
        $sortOrder = (int)($input['sort_order'] ?? 0);

        if (!$name) {
            response(false, null, 'Nome obrigatorio', 400);
        }
        if ($basePrice <= 0) {
            response(false, null, 'Preco base deve ser maior que zero', 400);
        }

        $db->beginTransaction();

        $stmt = $db->prepare("
            INSERT INTO om_subscription_boxes
            (name, description, category, base_price, full_price, image, tagline, badge,
             is_seasonal, seasonal_start, seasonal_end, sample_items, sort_order, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, true)
            RETURNING id
        ");
        $stmt->execute([
            $name, $description, $category, $basePrice, $fullPrice,
            $image ?: null, $tagline ?: null, $badge ?: null,
            $isSeasonal ? 'true' : 'false',
            $seasonalStart ?: null, $seasonalEnd ?: null,
            json_encode($sampleItems), $sortOrder
        ]);
        $boxId = (int)$stmt->fetchColumn();

        // Insert items if provided
        $items = $input['items'] ?? [];
        if ($items) {
            $stmtItem = $db->prepare("
                INSERT INTO om_cesta_items (box_id, product_name, quantity, unit, supplier, cost_price, is_optional, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($items as $idx => $item) {
                $stmtItem->execute([
                    $boxId,
                    trim($item['product_name'] ?? ''),
                    (float)($item['quantity'] ?? 1),
                    trim($item['unit'] ?? 'un'),
                    trim($item['supplier'] ?? '') ?: null,
                    (float)($item['cost_price'] ?? 0),
                    (bool)($item['is_optional'] ?? false) ? 'true' : 'false',
                    $idx
                ]);
            }
        }

        // Insert variants if provided
        $variants = $input['variants'] ?? [];
        if ($variants) {
            $stmtVar = $db->prepare("
                INSERT INTO om_cesta_variants (box_id, name, size_label, price_multiplier, price_override, sort_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($variants as $idx => $v) {
                $stmtVar->execute([
                    $boxId,
                    trim($v['name'] ?? ''),
                    trim($v['size_label'] ?? '') ?: null,
                    (float)($v['price_multiplier'] ?? 1.0),
                    isset($v['price_override']) ? (float)$v['price_override'] : null,
                    $idx
                ]);
            }
        }

        $db->commit();
        response(true, ['id' => $boxId], 'Tipo de cesta criado com sucesso');
    }

    // ── PUT: Update basket type ───────────────────────────────────
    if ($method === 'PUT') {
        $input = getInput();
        $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
        if (!$id) {
            response(false, null, 'ID obrigatorio', 400);
        }

        // Verify exists
        $stmt = $db->prepare("SELECT id FROM om_subscription_boxes WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            response(false, null, 'Tipo de cesta nao encontrado', 404);
        }

        $db->beginTransaction();

        // Update main fields
        $fields = [];
        $params = [];
        $allowed = ['name', 'description', 'category', 'base_price', 'full_price', 'image',
                     'tagline', 'badge', 'is_seasonal', 'seasonal_start', 'seasonal_end',
                     'sort_order', 'is_active'];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $input)) {
                $val = $input[$field];
                if ($field === 'is_seasonal' || $field === 'is_active') {
                    $val = $val ? 'true' : 'false';
                }
                $fields[] = "{$field} = ?";
                $params[] = $val;
            }
        }

        if (array_key_exists('sample_items', $input)) {
            $fields[] = "sample_items = ?::jsonb";
            $params[] = json_encode($input['sample_items']);
        }

        if ($fields) {
            $fields[] = "updated_at = NOW()";
            $params[] = $id;
            $db->prepare("UPDATE om_subscription_boxes SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        }

        // Replace items if provided
        if (array_key_exists('items', $input)) {
            $db->prepare("DELETE FROM om_cesta_items WHERE box_id = ?")->execute([$id]);
            $stmtItem = $db->prepare("
                INSERT INTO om_cesta_items (box_id, product_name, quantity, unit, supplier, cost_price, is_optional, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($input['items'] as $idx => $item) {
                $stmtItem->execute([
                    $id,
                    trim($item['product_name'] ?? ''),
                    (float)($item['quantity'] ?? 1),
                    trim($item['unit'] ?? 'un'),
                    trim($item['supplier'] ?? '') ?: null,
                    (float)($item['cost_price'] ?? 0),
                    (bool)($item['is_optional'] ?? false) ? 'true' : 'false',
                    $idx
                ]);
            }
        }

        // Replace variants if provided
        if (array_key_exists('variants', $input)) {
            $db->prepare("DELETE FROM om_cesta_variants WHERE box_id = ?")->execute([$id]);
            $stmtVar = $db->prepare("
                INSERT INTO om_cesta_variants (box_id, name, size_label, price_multiplier, price_override, sort_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($input['variants'] as $idx => $v) {
                $stmtVar->execute([
                    $id,
                    trim($v['name'] ?? ''),
                    trim($v['size_label'] ?? '') ?: null,
                    (float)($v['price_multiplier'] ?? 1.0),
                    isset($v['price_override']) ? (float)$v['price_override'] : null,
                    $idx
                ]);
            }
        }

        $db->commit();
        response(true, null, 'Tipo de cesta atualizado');
    }

    // ── DELETE: Deactivate basket type ────────────────────────────
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            response(false, null, 'ID obrigatorio', 400);
        }

        $stmt = $db->prepare("UPDATE om_subscription_boxes SET is_active = false, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            response(false, null, 'Tipo de cesta nao encontrado', 404);
        }

        response(true, null, 'Tipo de cesta desativado');
    }

    response(false, null, 'Metodo nao permitido', 405);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[cestas/tipos] ' . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
