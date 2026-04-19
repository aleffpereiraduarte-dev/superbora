<?php
/**
 * /api/mercado/admin/cestas/fornecedores.php
 * CRUD for basket suppliers (admin only).
 *
 * GET    — List suppliers (filters: active, city, rating, search)
 * POST   — Create new supplier
 * PUT    — Update supplier (requires id)
 * DELETE — Soft delete (sets is_active = false)
 */
require_once __DIR__ . "/../../config/database.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requireAdmin();
    $adminId = (int)($payload['uid'] ?? $payload['user_id'] ?? 0);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // ── Self-healing schema ──────────────────────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_cesta_fornecedores (
            id SERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            cnpj VARCHAR(18),
            phone VARCHAR(20),
            email VARCHAR(255),
            address TEXT,
            city VARCHAR(100),
            state VARCHAR(2),
            contact_person VARCHAR(255),
            payment_terms VARCHAR(100),
            delivery_days VARCHAR(100),
            notes TEXT,
            rating NUMERIC(3,1) DEFAULT 5.0,
            is_active BOOLEAN DEFAULT true,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        );
    ");

    // ── GET: List ────────────────────────────────────────────────
    if ($method === 'GET') {
        // Detail view
        if (!empty($_GET['id'])) {
            $id = (int)$_GET['id'];
            $stmt = $db->prepare("SELECT * FROM om_cesta_fornecedores WHERE id = ?");
            $stmt->execute([$id]);
            $f = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$f) response(false, null, 'Fornecedor nao encontrado', 404);
            $f['rating'] = (float)$f['rating'];
            $f['is_active'] = (bool)$f['is_active'];

            // Recent POs
            $stmt = $db->prepare("
                SELECT id, order_number, status, total_cost, created_at, received_at
                FROM om_cesta_purchase_orders
                WHERE fornecedor_id = ?
                ORDER BY created_at DESC
                LIMIT 10
            ");
            $stmt->execute([$id]);
            $f['recent_purchase_orders'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Ingredients supplied
            $stmt = $db->prepare("
                SELECT id, name, unit, category, cost_per_unit, is_active
                FROM om_cesta_ingredientes
                WHERE fornecedor_id = ?
                ORDER BY name ASC
            ");
            $stmt->execute([$id]);
            $f['ingredientes'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            response(true, ['fornecedor' => $f]);
        }

        $includeInactive = ($_GET['include_inactive'] ?? '0') === '1';
        $city = $_GET['city'] ?? null;
        $minRating = isset($_GET['min_rating']) ? (float)$_GET['min_rating'] : null;
        $search = trim($_GET['search'] ?? '');

        $where = [];
        $params = [];

        if (!$includeInactive) {
            $where[] = 'f.is_active = true';
        }
        if ($city) {
            $where[] = 'f.city ILIKE ?';
            $params[] = $city;
        }
        if ($minRating !== null) {
            $where[] = 'f.rating >= ?';
            $params[] = $minRating;
        }
        if ($search !== '') {
            $where[] = '(f.name ILIKE ? OR f.cnpj ILIKE ? OR f.contact_person ILIKE ?)';
            $s = "%{$search}%";
            $params[] = $s; $params[] = $s; $params[] = $s;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $db->prepare("
            SELECT f.*,
                   (SELECT COUNT(*) FROM om_cesta_ingredientes i WHERE i.fornecedor_id = f.id AND i.is_active = true) AS ingredientes_count,
                   (SELECT COUNT(*) FROM om_cesta_purchase_orders po WHERE po.fornecedor_id = f.id) AS orders_count,
                   (SELECT COALESCE(SUM(po.total_cost), 0) FROM om_cesta_purchase_orders po WHERE po.fornecedor_id = f.id AND po.status IN ('received','paid')) AS total_spent
            FROM om_cesta_fornecedores f
            {$whereSql}
            ORDER BY f.is_active DESC, f.name ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['rating'] = (float)$r['rating'];
            $r['is_active'] = (bool)$r['is_active'];
            $r['ingredientes_count'] = (int)$r['ingredientes_count'];
            $r['orders_count'] = (int)$r['orders_count'];
            $r['total_spent'] = round((float)$r['total_spent'], 2);
        }

        response(true, ['fornecedores' => $rows]);
    }

    // ── POST: Create ─────────────────────────────────────────────
    if ($method === 'POST') {
        $input = getInput();
        $name = trim($input['name'] ?? '');
        if ($name === '') {
            response(false, null, 'Nome obrigatorio', 400);
        }

        $stmt = $db->prepare("
            INSERT INTO om_cesta_fornecedores
            (name, cnpj, phone, email, address, city, state, contact_person,
             payment_terms, delivery_days, notes, rating, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, true)
            RETURNING id
        ");
        $stmt->execute([
            $name,
            trim($input['cnpj'] ?? '') ?: null,
            trim($input['phone'] ?? '') ?: null,
            trim($input['email'] ?? '') ?: null,
            trim($input['address'] ?? '') ?: null,
            trim($input['city'] ?? '') ?: null,
            strtoupper(trim($input['state'] ?? '')) ?: null,
            trim($input['contact_person'] ?? '') ?: null,
            trim($input['payment_terms'] ?? '') ?: null,
            trim($input['delivery_days'] ?? '') ?: null,
            trim($input['notes'] ?? '') ?: null,
            isset($input['rating']) ? (float)$input['rating'] : 5.0,
        ]);
        $id = (int)$stmt->fetchColumn();
        response(true, ['id' => $id], 'Fornecedor criado com sucesso');
    }

    // ── PUT: Update ──────────────────────────────────────────────
    if ($method === 'PUT') {
        $input = getInput();
        $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
        if (!$id) response(false, null, 'ID obrigatorio', 400);

        $stmt = $db->prepare("SELECT id FROM om_cesta_fornecedores WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) response(false, null, 'Fornecedor nao encontrado', 404);

        $allowed = ['name','cnpj','phone','email','address','city','state',
                    'contact_person','payment_terms','delivery_days','notes',
                    'rating','is_active'];
        $fields = [];
        $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $input)) {
                $val = $input[$f];
                if ($f === 'is_active') $val = $val ? 'true' : 'false';
                if ($f === 'rating')    $val = (float)$val;
                if ($f === 'state' && is_string($val)) $val = strtoupper(trim($val)) ?: null;
                $fields[] = "{$f} = ?";
                $params[] = $val;
            }
        }
        if (!$fields) response(false, null, 'Nenhum campo para atualizar', 400);

        $fields[] = 'updated_at = NOW()';
        $params[] = $id;
        $db->prepare("UPDATE om_cesta_fornecedores SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        response(true, null, 'Fornecedor atualizado');
    }

    // ── DELETE: Soft delete ──────────────────────────────────────
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) response(false, null, 'ID obrigatorio', 400);
        $stmt = $db->prepare("UPDATE om_cesta_fornecedores SET is_active = false, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) response(false, null, 'Fornecedor nao encontrado', 404);
        response(true, null, 'Fornecedor desativado');
    }

    response(false, null, 'Metodo nao permitido', 405);
} catch (Exception $e) {
    error_log('[cestas/fornecedores] ' . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
