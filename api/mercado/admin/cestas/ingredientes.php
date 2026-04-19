<?php
/**
 * /api/mercado/admin/cestas/ingredientes.php
 * CRUD + analytics for ingredient SKUs (admin only).
 *
 * GET            — List ingredients (filters: category, fornecedor, low_stock, search)
 * GET ?id=N      — Single ingredient with purchase history + consumption rate
 * POST           — Create
 * PUT            — Update
 * DELETE         — Soft delete
 */
require_once __DIR__ . "/../../config/database.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requireAdmin();

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // ── Self-healing schema ──────────────────────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_cesta_ingredientes (
            id SERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            unit VARCHAR(20) NOT NULL,
            category VARCHAR(50),
            fornecedor_id INTEGER,
            cost_per_unit NUMERIC(12,4) NOT NULL DEFAULT 0,
            minimum_stock NUMERIC(12,2) DEFAULT 0,
            shelf_life_days INTEGER DEFAULT 7,
            storage_location VARCHAR(100),
            image_url TEXT,
            barcode VARCHAR(50),
            is_active BOOLEAN DEFAULT true,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS om_cesta_estoque (
            id SERIAL PRIMARY KEY,
            ingrediente_id INTEGER NOT NULL,
            quantity NUMERIC(12,2) NOT NULL DEFAULT 0,
            lot_number VARCHAR(50),
            expires_at DATE,
            received_at TIMESTAMP DEFAULT NOW(),
            purchase_price NUMERIC(12,2) DEFAULT 0,
            purchase_order_id INTEGER,
            location VARCHAR(100)
        );
        CREATE TABLE IF NOT EXISTS om_cesta_stock_movements (
            id SERIAL PRIMARY KEY,
            ingrediente_id INTEGER NOT NULL,
            movement_type VARCHAR(20) NOT NULL,
            quantity NUMERIC(12,2) NOT NULL,
            cost NUMERIC(12,2),
            reference_type VARCHAR(50),
            reference_id INTEGER,
            notes TEXT,
            created_by INTEGER,
            created_at TIMESTAMP DEFAULT NOW()
        );
    ");

    // ── GET ──────────────────────────────────────────────────────
    if ($method === 'GET') {
        // Detail
        if (!empty($_GET['id'])) {
            $id = (int)$_GET['id'];
            $stmt = $db->prepare("
                SELECT i.*, f.name AS fornecedor_name, f.phone AS fornecedor_phone
                FROM om_cesta_ingredientes i
                LEFT JOIN om_cesta_fornecedores f ON f.id = i.fornecedor_id
                WHERE i.id = ?
            ");
            $stmt->execute([$id]);
            $ing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$ing) response(false, null, 'Ingrediente nao encontrado', 404);

            $ing['cost_per_unit'] = (float)$ing['cost_per_unit'];
            $ing['minimum_stock'] = (float)$ing['minimum_stock'];
            $ing['shelf_life_days'] = (int)$ing['shelf_life_days'];
            $ing['is_active'] = (bool)$ing['is_active'];

            // Current total stock
            $stmt = $db->prepare("SELECT COALESCE(SUM(quantity), 0) AS total FROM om_cesta_estoque WHERE ingrediente_id = ?");
            $stmt->execute([$id]);
            $ing['current_stock'] = (float)$stmt->fetchColumn();
            $ing['is_low_stock'] = $ing['current_stock'] < $ing['minimum_stock'];

            // Recent purchases (last 10)
            $stmt = $db->prepare("
                SELECT poi.id, poi.quantity_ordered, poi.quantity_received, poi.unit_price,
                       poi.subtotal, po.order_number, po.status, po.created_at,
                       po.received_at, f.name AS fornecedor_name
                FROM om_cesta_purchase_order_items poi
                JOIN om_cesta_purchase_orders po ON po.id = poi.purchase_order_id
                LEFT JOIN om_cesta_fornecedores f ON f.id = po.fornecedor_id
                WHERE poi.ingrediente_id = ?
                ORDER BY po.created_at DESC
                LIMIT 10
            ");
            try { $stmt->execute([$id]); $ing['recent_purchases'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []; }
            catch (Exception $e) { $ing['recent_purchases'] = []; }

            // Consumption rate (last 30 days, sum of 'out' movements)
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(quantity), 0) AS total_out
                FROM om_cesta_stock_movements
                WHERE ingrediente_id = ?
                  AND movement_type = 'out'
                  AND created_at >= NOW() - INTERVAL '30 days'
            ");
            $stmt->execute([$id]);
            $totalOut = (float)$stmt->fetchColumn();
            $ing['consumption_30d'] = round($totalOut, 2);
            $ing['avg_daily_consumption'] = round($totalOut / 30, 3);

            // Days of stock remaining (if consumption > 0)
            $ing['days_remaining'] = $ing['avg_daily_consumption'] > 0
                ? round($ing['current_stock'] / $ing['avg_daily_consumption'], 1)
                : null;

            // Recent movements (last 20)
            $stmt = $db->prepare("
                SELECT id, movement_type, quantity, cost, reference_type, reference_id, notes, created_at
                FROM om_cesta_stock_movements
                WHERE ingrediente_id = ?
                ORDER BY created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$id]);
            $ing['recent_movements'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($ing['recent_movements'] as &$m) {
                $m['quantity'] = (float)$m['quantity'];
                $m['cost'] = $m['cost'] !== null ? (float)$m['cost'] : null;
            }

            response(true, ['ingrediente' => $ing]);
        }

        $category = $_GET['category'] ?? null;
        $fornecedorId = isset($_GET['fornecedor_id']) ? (int)$_GET['fornecedor_id'] : null;
        $lowStock = ($_GET['low_stock'] ?? '0') === '1';
        $includeInactive = ($_GET['include_inactive'] ?? '0') === '1';
        $search = trim($_GET['search'] ?? '');

        $where = [];
        $params = [];

        if (!$includeInactive) {
            $where[] = 'i.is_active = true';
        }
        if ($category) {
            $where[] = 'i.category = ?';
            $params[] = $category;
        }
        if ($fornecedorId) {
            $where[] = 'i.fornecedor_id = ?';
            $params[] = $fornecedorId;
        }
        if ($search !== '') {
            $where[] = '(i.name ILIKE ? OR i.barcode ILIKE ?)';
            $s = "%{$search}%";
            $params[] = $s; $params[] = $s;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $db->prepare("
            SELECT i.*,
                   f.name AS fornecedor_name,
                   COALESCE((SELECT SUM(e.quantity) FROM om_cesta_estoque e WHERE e.ingrediente_id = i.id), 0) AS current_stock
            FROM om_cesta_ingredientes i
            LEFT JOIN om_cesta_fornecedores f ON f.id = i.fornecedor_id
            {$whereSql}
            ORDER BY i.category ASC, i.name ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['cost_per_unit'] = (float)$r['cost_per_unit'];
            $r['minimum_stock'] = (float)$r['minimum_stock'];
            $r['shelf_life_days'] = (int)$r['shelf_life_days'];
            $r['is_active'] = (bool)$r['is_active'];
            $r['current_stock'] = (float)$r['current_stock'];
            $r['is_low_stock'] = $r['current_stock'] < $r['minimum_stock'];
            $r['stock_value'] = round($r['current_stock'] * $r['cost_per_unit'], 2);
        }

        if ($lowStock) {
            $rows = array_values(array_filter($rows, fn($r) => $r['is_low_stock']));
        }

        // Aggregate stats
        $totalValue = 0.0; $lowCount = 0;
        foreach ($rows as $r) {
            $totalValue += $r['stock_value'];
            if ($r['is_low_stock']) $lowCount++;
        }

        response(true, [
            'ingredientes' => $rows,
            'summary' => [
                'total' => count($rows),
                'low_stock_count' => $lowCount,
                'total_stock_value' => round($totalValue, 2),
            ],
        ]);
    }

    // ── POST: Create ─────────────────────────────────────────────
    if ($method === 'POST') {
        $input = getInput();
        $name = trim($input['name'] ?? '');
        $unit = trim($input['unit'] ?? '');
        if ($name === '' || $unit === '') {
            response(false, null, 'Nome e unidade sao obrigatorios', 400);
        }

        $stmt = $db->prepare("
            INSERT INTO om_cesta_ingredientes
            (name, unit, category, fornecedor_id, cost_per_unit, minimum_stock,
             shelf_life_days, storage_location, image_url, barcode, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, true)
            RETURNING id
        ");
        $stmt->execute([
            $name, $unit,
            trim($input['category'] ?? '') ?: null,
            !empty($input['fornecedor_id']) ? (int)$input['fornecedor_id'] : null,
            (float)($input['cost_per_unit'] ?? 0),
            (float)($input['minimum_stock'] ?? 0),
            (int)($input['shelf_life_days'] ?? 7),
            trim($input['storage_location'] ?? '') ?: null,
            trim($input['image_url'] ?? '') ?: null,
            trim($input['barcode'] ?? '') ?: null,
        ]);
        $id = (int)$stmt->fetchColumn();
        response(true, ['id' => $id], 'Ingrediente criado com sucesso');
    }

    // ── PUT: Update ──────────────────────────────────────────────
    if ($method === 'PUT') {
        $input = getInput();
        $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
        if (!$id) response(false, null, 'ID obrigatorio', 400);

        $stmt = $db->prepare("SELECT id FROM om_cesta_ingredientes WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) response(false, null, 'Ingrediente nao encontrado', 404);

        $allowed = ['name','unit','category','fornecedor_id','cost_per_unit',
                    'minimum_stock','shelf_life_days','storage_location',
                    'image_url','barcode','is_active'];
        $fields = [];
        $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $input)) {
                $val = $input[$f];
                if ($f === 'is_active') $val = $val ? 'true' : 'false';
                if ($f === 'fornecedor_id') $val = $val ? (int)$val : null;
                if (in_array($f, ['cost_per_unit','minimum_stock'], true)) $val = (float)$val;
                if ($f === 'shelf_life_days') $val = (int)$val;
                $fields[] = "{$f} = ?";
                $params[] = $val;
            }
        }
        if (!$fields) response(false, null, 'Nenhum campo para atualizar', 400);
        $fields[] = 'updated_at = NOW()';
        $params[] = $id;
        $db->prepare("UPDATE om_cesta_ingredientes SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        response(true, null, 'Ingrediente atualizado');
    }

    // ── DELETE: Soft delete ──────────────────────────────────────
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) response(false, null, 'ID obrigatorio', 400);
        $stmt = $db->prepare("UPDATE om_cesta_ingredientes SET is_active = false, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) response(false, null, 'Ingrediente nao encontrado', 404);
        response(true, null, 'Ingrediente desativado');
    }

    response(false, null, 'Metodo nao permitido', 405);
} catch (Exception $e) {
    error_log('[cestas/ingredientes] ' . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
