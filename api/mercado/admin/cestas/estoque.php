<?php
/**
 * /api/mercado/admin/cestas/estoque.php
 * Stock levels & movements (admin only).
 *
 * GET ?view=levels                        — Current stock per ingredient + low-stock alerts
 * GET ?view=movements&ingrediente_id=N    — Movement history for an ingredient
 * GET ?view=expiring&days=7               — Lots expiring in next N days
 * POST ?action=adjust                     — Manual stock adjustment with reason
 *     body: { ingrediente_id, quantity (delta, can be negative), reason, movement_type }
 */
require_once __DIR__ . "/../../config/database.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requireAdmin();
    $adminId = (int)($payload['uid'] ?? $payload['user_id'] ?? 0);

    // ── Self-healing schema ──────────────────────────────────────
    $db->exec("
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

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // ── GET ──────────────────────────────────────────────────────
    if ($method === 'GET') {
        $view = $_GET['view'] ?? 'levels';

        if ($view === 'levels') {
            $onlyLow = ($_GET['only_low'] ?? '0') === '1';
            $stmt = $db->query("
                SELECT i.id, i.name, i.unit, i.category, i.cost_per_unit,
                       i.minimum_stock, i.storage_location,
                       f.name AS fornecedor_name,
                       COALESCE((SELECT SUM(quantity) FROM om_cesta_estoque e WHERE e.ingrediente_id = i.id), 0) AS current_stock,
                       (SELECT MIN(expires_at) FROM om_cesta_estoque e WHERE e.ingrediente_id = i.id AND e.expires_at IS NOT NULL AND e.quantity > 0) AS next_expiry
                FROM om_cesta_ingredientes i
                LEFT JOIN om_cesta_fornecedores f ON f.id = i.fornecedor_id
                WHERE i.is_active = true
                ORDER BY i.category ASC, i.name ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$r) {
                $r['cost_per_unit']  = (float)$r['cost_per_unit'];
                $r['minimum_stock']  = (float)$r['minimum_stock'];
                $r['current_stock']  = (float)$r['current_stock'];
                $r['stock_value']    = round($r['current_stock'] * $r['cost_per_unit'], 2);
                $r['is_low_stock']   = $r['current_stock'] < $r['minimum_stock'];
                $r['is_critical']    = $r['current_stock'] <= 0;
            }

            if ($onlyLow) {
                $rows = array_values(array_filter($rows, fn($r) => $r['is_low_stock']));
            }

            $totalValue = 0; $low = 0; $critical = 0;
            foreach ($rows as $r) {
                $totalValue += $r['stock_value'];
                if ($r['is_low_stock']) $low++;
                if ($r['is_critical']) $critical++;
            }

            response(true, [
                'levels' => $rows,
                'summary' => [
                    'total_items' => count($rows),
                    'low_stock_items' => $low,
                    'critical_items' => $critical,
                    'total_stock_value' => round($totalValue, 2),
                ],
            ]);
        }

        if ($view === 'movements') {
            $ingredienteId = isset($_GET['ingrediente_id']) ? (int)$_GET['ingrediente_id'] : null;
            $movementType = $_GET['movement_type'] ?? null;
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;

            $where = [];
            $params = [];
            if ($ingredienteId) { $where[] = 'm.ingrediente_id = ?'; $params[] = $ingredienteId; }
            if ($movementType)  { $where[] = 'm.movement_type = ?';  $params[] = $movementType; }
            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            $stmtC = $db->prepare("SELECT COUNT(*) FROM om_cesta_stock_movements m {$whereSql}");
            $stmtC->execute($params);
            $total = (int)$stmtC->fetchColumn();

            $fetchParams = $params;
            $fetchParams[] = $limit;
            $fetchParams[] = $offset;
            $stmt = $db->prepare("
                SELECT m.*, i.name AS ingrediente_name, i.unit AS ingrediente_unit
                FROM om_cesta_stock_movements m
                LEFT JOIN om_cesta_ingredientes i ON i.id = m.ingrediente_id
                {$whereSql}
                ORDER BY m.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute($fetchParams);
            $movements = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($movements as &$m) {
                $m['quantity'] = (float)$m['quantity'];
                $m['cost'] = $m['cost'] !== null ? (float)$m['cost'] : null;
            }

            response(true, [
                'movements' => $movements,
                'pagination' => [
                    'page' => $page, 'limit' => $limit, 'total' => $total,
                    'pages' => max(1, (int)ceil($total / $limit)),
                ],
            ]);
        }

        if ($view === 'expiring') {
            $days = max(1, min(180, (int)($_GET['days'] ?? 7)));
            $stmt = $db->prepare("
                SELECT e.id, e.lot_number, e.quantity, e.expires_at, e.location,
                       i.id AS ingrediente_id, i.name AS ingrediente_name, i.unit, i.category
                FROM om_cesta_estoque e
                JOIN om_cesta_ingredientes i ON i.id = e.ingrediente_id
                WHERE e.quantity > 0
                  AND e.expires_at IS NOT NULL
                  AND e.expires_at <= (CURRENT_DATE + (? || ' days')::INTERVAL)
                ORDER BY e.expires_at ASC
            ");
            $stmt->execute([$days]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$r) $r['quantity'] = (float)$r['quantity'];
            response(true, ['expiring' => $rows, 'days' => $days]);
        }

        if ($view === 'lots') {
            $ingredienteId = isset($_GET['ingrediente_id']) ? (int)$_GET['ingrediente_id'] : null;
            if (!$ingredienteId) response(false, null, 'ingrediente_id obrigatorio', 400);
            $stmt = $db->prepare("
                SELECT e.*, po.order_number AS purchase_order_number
                FROM om_cesta_estoque e
                LEFT JOIN om_cesta_purchase_orders po ON po.id = e.purchase_order_id
                WHERE e.ingrediente_id = ?
                ORDER BY e.received_at DESC
            ");
            $stmt->execute([$ingredienteId]);
            $lots = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($lots as &$l) {
                $l['quantity'] = (float)$l['quantity'];
                $l['purchase_price'] = (float)$l['purchase_price'];
            }
            response(true, ['lots' => $lots]);
        }

        response(false, null, 'View invalida. Use: levels, movements, expiring, lots', 400);
    }

    // ── POST: Adjust stock ───────────────────────────────────────
    if ($method === 'POST') {
        $action = $_GET['action'] ?? 'adjust';
        $input = getInput();

        if ($action === 'adjust') {
            $ingredienteId = (int)($input['ingrediente_id'] ?? 0);
            $delta = (float)($input['quantity'] ?? 0);   // can be + or -
            $reason = trim($input['reason'] ?? '');
            $movementType = in_array($input['movement_type'] ?? '', ['in','out','adjust','waste'], true)
                            ? $input['movement_type'] : 'adjust';

            if (!$ingredienteId) response(false, null, 'ingrediente_id obrigatorio', 400);
            if ($delta == 0)      response(false, null, 'quantity nao pode ser zero', 400);
            if ($reason === '')    response(false, null, 'Motivo obrigatorio', 400);

            // Verify ingredient exists
            $stmt = $db->prepare("SELECT id, cost_per_unit FROM om_cesta_ingredientes WHERE id = ?");
            $stmt->execute([$ingredienteId]);
            $ing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$ing) response(false, null, 'Ingrediente nao encontrado', 404);

            $db->beginTransaction();

            if ($delta > 0) {
                // Create a new lot
                $db->prepare("
                    INSERT INTO om_cesta_estoque (ingrediente_id, quantity, lot_number, received_at, purchase_price, location)
                    VALUES (?, ?, ?, NOW(), ?, 'ajuste manual')
                ")->execute([
                    $ingredienteId, $delta,
                    'ADJ-' . date('Ymd-His'),
                    (float)$ing['cost_per_unit'],
                ]);
            } else {
                // Deduct from existing lots (FIFO, earliest first)
                $needed = abs($delta);
                $stmt = $db->prepare("
                    SELECT id, quantity FROM om_cesta_estoque
                    WHERE ingrediente_id = ? AND quantity > 0
                    ORDER BY received_at ASC
                    FOR UPDATE
                ");
                $stmt->execute([$ingredienteId]);
                $lots = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($lots as $l) {
                    if ($needed <= 0) break;
                    $take = min($needed, (float)$l['quantity']);
                    $db->prepare("UPDATE om_cesta_estoque SET quantity = quantity - ? WHERE id = ?")
                       ->execute([$take, $l['id']]);
                    $needed -= $take;
                }
                if ($needed > 0) {
                    $db->rollBack();
                    response(false, null, "Estoque insuficiente (faltam {$needed} unidades)", 400);
                }
            }

            // Record movement
            $db->prepare("
                INSERT INTO om_cesta_stock_movements
                (ingrediente_id, movement_type, quantity, cost, reference_type, notes, created_by)
                VALUES (?, ?, ?, ?, 'manual', ?, ?)
            ")->execute([
                $ingredienteId, $movementType, $delta,
                abs($delta) * (float)$ing['cost_per_unit'],
                $reason, $adminId ?: null,
            ]);

            $db->commit();
            response(true, null, 'Estoque ajustado com sucesso');
        }

        response(false, null, 'Acao invalida. Use action=adjust', 400);
    }

    response(false, null, 'Metodo nao permitido', 405);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('[cestas/estoque] ' . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
