<?php
/**
 * /api/mercado/admin/cestas/receitas.php
 * Recipe management (admin only).
 *
 * GET  ?cesta_type_id=N             — Recipe for a basket type (with cost total)
 * GET  ?view=cost&cesta_type_id=N   — Just the cost breakdown
 * POST                              — Add ingredient line to recipe
 *     { cesta_type_id, ingrediente_id, quantity, variant_id?, is_optional? }
 * PUT                               — Update a recipe line (by id, or upsert by composite key)
 * DELETE ?id=N                      — Remove a recipe line
 *
 * cesta_type_id is om_subscription_boxes.id (the existing "basket type" PK).
 */
require_once __DIR__ . "/../../config/database.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requireAdmin();

    // ── Self-healing schema ──────────────────────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_cesta_recipe (
            id SERIAL PRIMARY KEY,
            cesta_type_id INTEGER NOT NULL,
            variant_id INTEGER,
            ingrediente_id INTEGER NOT NULL,
            quantity NUMERIC(12,2) NOT NULL,
            is_optional BOOLEAN DEFAULT false,
            created_at TIMESTAMP DEFAULT NOW()
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_cesta_recipe_unique
            ON om_cesta_recipe (cesta_type_id, COALESCE(variant_id, 0), ingrediente_id);
    ");

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // ── GET ──────────────────────────────────────────────────────
    if ($method === 'GET') {
        $view = $_GET['view'] ?? 'list';
        $cestaTypeId = isset($_GET['cesta_type_id']) ? (int)$_GET['cesta_type_id'] : 0;
        $variantId = isset($_GET['variant_id']) && $_GET['variant_id'] !== ''
                     ? (int)$_GET['variant_id'] : null;

        if (!$cestaTypeId) {
            // List all cesta types with recipe summary + costs
            $stmt = $db->query("
                SELECT b.id, b.name, b.category, b.base_price,
                       (SELECT COUNT(*) FROM om_cesta_recipe r WHERE r.cesta_type_id = b.id) AS ingredient_count,
                       COALESCE((
                           SELECT SUM(r.quantity * i.cost_per_unit)
                           FROM om_cesta_recipe r
                           JOIN om_cesta_ingredientes i ON i.id = r.ingrediente_id
                           WHERE r.cesta_type_id = b.id AND r.variant_id IS NULL
                       ), 0) AS recipe_cost
                FROM om_subscription_boxes b
                WHERE b.is_active = true
                ORDER BY b.sort_order ASC, b.name ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$r) {
                $r['base_price']     = (float)$r['base_price'];
                $r['ingredient_count'] = (int)$r['ingredient_count'];
                $r['recipe_cost']    = round((float)$r['recipe_cost'], 2);
                $r['estimated_profit'] = round($r['base_price'] - $r['recipe_cost'], 2);
                $r['estimated_margin'] = $r['base_price'] > 0
                    ? round(($r['estimated_profit'] / $r['base_price']) * 100, 1) : 0;
            }
            response(true, ['receitas' => $rows]);
        }

        // Fetch cesta type
        $stmt = $db->prepare("SELECT id, name, category, base_price FROM om_subscription_boxes WHERE id = ?");
        $stmt->execute([$cestaTypeId]);
        $cesta = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cesta) response(false, null, 'Tipo de cesta nao encontrado', 404);
        $cesta['base_price'] = (float)$cesta['base_price'];

        // Variants
        $stmt = $db->prepare("SELECT * FROM om_cesta_variants WHERE box_id = ? AND is_active = true ORDER BY sort_order ASC");
        $stmt->execute([$cestaTypeId]);
        $variants = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Recipe lines
        if ($variantId === null) {
            $stmt = $db->prepare("
                SELECT r.*, i.name AS ingrediente_name, i.unit AS ingrediente_unit,
                       i.category AS ingrediente_category, i.cost_per_unit,
                       v.name AS variant_name
                FROM om_cesta_recipe r
                JOIN om_cesta_ingredientes i ON i.id = r.ingrediente_id
                LEFT JOIN om_cesta_variants v ON v.id = r.variant_id
                WHERE r.cesta_type_id = ?
                ORDER BY r.variant_id NULLS FIRST, i.category ASC, i.name ASC
            ");
            $stmt->execute([$cestaTypeId]);
        } else {
            $stmt = $db->prepare("
                SELECT r.*, i.name AS ingrediente_name, i.unit AS ingrediente_unit,
                       i.category AS ingrediente_category, i.cost_per_unit,
                       v.name AS variant_name
                FROM om_cesta_recipe r
                JOIN om_cesta_ingredientes i ON i.id = r.ingrediente_id
                LEFT JOIN om_cesta_variants v ON v.id = r.variant_id
                WHERE r.cesta_type_id = ? AND (r.variant_id = ? OR r.variant_id IS NULL)
                ORDER BY r.variant_id NULLS FIRST, i.category ASC, i.name ASC
            ");
            $stmt->execute([$cestaTypeId, $variantId]);
        }
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $totalCost = 0;
        foreach ($lines as &$l) {
            $l['quantity']       = (float)$l['quantity'];
            $l['cost_per_unit']  = (float)$l['cost_per_unit'];
            $l['line_cost']      = round($l['quantity'] * $l['cost_per_unit'], 4);
            $l['is_optional']    = (bool)$l['is_optional'];
            if (!$l['is_optional']) {
                $totalCost += $l['line_cost'];
            }
        }

        $profit = $cesta['base_price'] - $totalCost;
        $margin = $cesta['base_price'] > 0 ? round(($profit / $cesta['base_price']) * 100, 1) : 0;

        if ($view === 'cost') {
            response(true, [
                'cesta' => $cesta,
                'total_cost' => round($totalCost, 2),
                'profit'     => round($profit, 2),
                'margin'     => $margin,
                'ingredient_count' => count($lines),
            ]);
        }

        response(true, [
            'cesta' => $cesta,
            'variants' => $variants,
            'recipe' => $lines,
            'total_cost' => round($totalCost, 2),
            'profit'     => round($profit, 2),
            'margin'     => $margin,
        ]);
    }

    // ── POST: Add line ───────────────────────────────────────────
    if ($method === 'POST') {
        $input = getInput();
        $cestaTypeId = (int)($input['cesta_type_id'] ?? 0);
        $ingredienteId = (int)($input['ingrediente_id'] ?? 0);
        $quantity = (float)($input['quantity'] ?? 0);
        $variantId = !empty($input['variant_id']) ? (int)$input['variant_id'] : null;
        $isOptional = (bool)($input['is_optional'] ?? false);

        if (!$cestaTypeId || !$ingredienteId) response(false, null, 'cesta_type_id e ingrediente_id obrigatorios', 400);
        if ($quantity <= 0) response(false, null, 'Quantidade deve ser maior que zero', 400);

        // Upsert via UNIQUE index on (cesta_type_id, COALESCE(variant_id, 0), ingrediente_id)
        $stmt = $db->prepare("
            INSERT INTO om_cesta_recipe (cesta_type_id, variant_id, ingrediente_id, quantity, is_optional)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT (cesta_type_id, COALESCE(variant_id, 0), ingrediente_id)
            DO UPDATE SET quantity = EXCLUDED.quantity, is_optional = EXCLUDED.is_optional
            RETURNING id
        ");
        $stmt->execute([$cestaTypeId, $variantId, $ingredienteId, $quantity, $isOptional ? 'true' : 'false']);
        $id = (int)$stmt->fetchColumn();
        response(true, ['id' => $id], 'Ingrediente adicionado a receita');
    }

    // ── PUT: Update line ─────────────────────────────────────────
    if ($method === 'PUT') {
        $input = getInput();
        $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
        if (!$id) response(false, null, 'ID obrigatorio', 400);

        $allowed = ['quantity','is_optional'];
        $fields = [];
        $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $input)) {
                $val = $input[$f];
                if ($f === 'is_optional') $val = $val ? 'true' : 'false';
                if ($f === 'quantity')    $val = (float)$val;
                $fields[] = "{$f} = ?";
                $params[] = $val;
            }
        }
        if (!$fields) response(false, null, 'Nenhum campo para atualizar', 400);
        $params[] = $id;
        $stmt = $db->prepare("UPDATE om_cesta_recipe SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) response(false, null, 'Linha nao encontrada', 404);
        response(true, null, 'Receita atualizada');
    }

    // ── DELETE ───────────────────────────────────────────────────
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) response(false, null, 'ID obrigatorio', 400);
        $stmt = $db->prepare("DELETE FROM om_cesta_recipe WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) response(false, null, 'Linha nao encontrada', 404);
        response(true, null, 'Ingrediente removido da receita');
    }

    response(false, null, 'Metodo nao permitido', 405);
} catch (Exception $e) {
    error_log('[cestas/receitas] ' . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
