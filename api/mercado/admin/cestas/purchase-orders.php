<?php
/**
 * /api/mercado/admin/cestas/purchase-orders.php
 * Purchase orders to suppliers (admin only).
 *
 * GET                           — List POs (filters: status, fornecedor_id, date_from, date_to)
 * GET ?id=N                     — Single PO with line items
 * POST                          — Create new PO with items
 *     { fornecedor_id, expected_at?, notes?, items: [{ ingrediente_id, quantity_ordered, unit_price }] }
 * PUT ?id=N action=send|receive|pay|cancel
 *     - receive: also accepts items array with quantity_received to update per line,
 *       creates stock_movements + estoque rows, updates ingredient cost_per_unit to latest.
 * DELETE ?id=N                  — Cancel a pending PO (soft: status = cancelled)
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
        CREATE TABLE IF NOT EXISTS om_cesta_purchase_orders (
            id SERIAL PRIMARY KEY,
            fornecedor_id INTEGER NOT NULL,
            order_number VARCHAR(50) UNIQUE,
            status VARCHAR(30) DEFAULT 'pending',
            total_cost NUMERIC(12,2) DEFAULT 0,
            expected_at TIMESTAMP,
            received_at TIMESTAMP,
            paid_at TIMESTAMP,
            notes TEXT,
            created_by INTEGER,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS om_cesta_purchase_order_items (
            id SERIAL PRIMARY KEY,
            purchase_order_id INTEGER NOT NULL REFERENCES om_cesta_purchase_orders(id) ON DELETE CASCADE,
            ingrediente_id INTEGER NOT NULL,
            quantity_ordered NUMERIC(12,2) NOT NULL,
            quantity_received NUMERIC(12,2) DEFAULT 0,
            unit_price NUMERIC(12,4) NOT NULL,
            subtotal NUMERIC(12,2) NOT NULL
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
    ");

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // ── Helper: generate PO number "PO-YYYY-NNNN" ────────────────
    $genOrderNumber = function() use ($db) {
        $year = date('Y');
        $stmt = $db->prepare("
            SELECT COUNT(*) + 1 AS next
            FROM om_cesta_purchase_orders
            WHERE order_number LIKE ?
        ");
        $stmt->execute(["PO-{$year}-%"]);
        $next = (int)$stmt->fetchColumn();
        return sprintf('PO-%s-%04d', $year, $next);
    };

    // ── GET ──────────────────────────────────────────────────────
    if ($method === 'GET') {
        if (!empty($_GET['id'])) {
            $id = (int)$_GET['id'];
            $stmt = $db->prepare("
                SELECT po.*, f.name AS fornecedor_name, f.phone AS fornecedor_phone,
                       f.contact_person AS fornecedor_contact, f.payment_terms
                FROM om_cesta_purchase_orders po
                LEFT JOIN om_cesta_fornecedores f ON f.id = po.fornecedor_id
                WHERE po.id = ?
            ");
            $stmt->execute([$id]);
            $po = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$po) response(false, null, 'Pedido de compra nao encontrado', 404);
            $po['total_cost'] = (float)$po['total_cost'];

            $stmt = $db->prepare("
                SELECT poi.*, i.name AS ingrediente_name, i.unit, i.category
                FROM om_cesta_purchase_order_items poi
                JOIN om_cesta_ingredientes i ON i.id = poi.ingrediente_id
                WHERE poi.purchase_order_id = ?
                ORDER BY i.category ASC, i.name ASC
            ");
            $stmt->execute([$id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($items as &$it) {
                $it['quantity_ordered']  = (float)$it['quantity_ordered'];
                $it['quantity_received'] = (float)$it['quantity_received'];
                $it['unit_price']        = (float)$it['unit_price'];
                $it['subtotal']          = (float)$it['subtotal'];
            }
            $po['items'] = $items;
            response(true, ['purchase_order' => $po]);
        }

        $status = $_GET['status'] ?? null;
        $fornecedorId = isset($_GET['fornecedor_id']) ? (int)$_GET['fornecedor_id'] : null;
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];
        if ($status)       { $where[] = 'po.status = ?';        $params[] = $status; }
        if ($fornecedorId) { $where[] = 'po.fornecedor_id = ?'; $params[] = $fornecedorId; }
        if ($dateFrom)     { $where[] = 'po.created_at::date >= ?'; $params[] = $dateFrom; }
        if ($dateTo)       { $where[] = 'po.created_at::date <= ?'; $params[] = $dateTo; }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmtC = $db->prepare("SELECT COUNT(*) FROM om_cesta_purchase_orders po {$whereSql}");
        $stmtC->execute($params);
        $total = (int)$stmtC->fetchColumn();

        $fetchParams = $params;
        $fetchParams[] = $limit;
        $fetchParams[] = $offset;
        $stmt = $db->prepare("
            SELECT po.*, f.name AS fornecedor_name,
                   (SELECT COUNT(*) FROM om_cesta_purchase_order_items poi WHERE poi.purchase_order_id = po.id) AS items_count
            FROM om_cesta_purchase_orders po
            LEFT JOIN om_cesta_fornecedores f ON f.id = po.fornecedor_id
            {$whereSql}
            ORDER BY po.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($fetchParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['total_cost']  = (float)$r['total_cost'];
            $r['items_count'] = (int)$r['items_count'];
        }

        // Summary
        $stmtS = $db->prepare("
            SELECT
                COUNT(*)                                              AS total,
                COUNT(*) FILTER (WHERE status = 'pending')            AS pending,
                COUNT(*) FILTER (WHERE status = 'sent')               AS sent,
                COUNT(*) FILTER (WHERE status = 'received')           AS received,
                COUNT(*) FILTER (WHERE status = 'paid')               AS paid,
                COUNT(*) FILTER (WHERE status = 'cancelled')          AS cancelled,
                COALESCE(SUM(total_cost) FILTER (WHERE status IN ('received','paid')), 0) AS total_spent,
                COALESCE(SUM(total_cost) FILTER (WHERE status IN ('pending','sent')), 0)  AS pending_value
            FROM om_cesta_purchase_orders po
            {$whereSql}
        ");
        $stmtS->execute($params);
        $summary = $stmtS->fetch(PDO::FETCH_ASSOC) ?: [];

        response(true, [
            'purchase_orders' => $rows,
            'summary' => [
                'total' => (int)($summary['total'] ?? 0),
                'pending' => (int)($summary['pending'] ?? 0),
                'sent' => (int)($summary['sent'] ?? 0),
                'received' => (int)($summary['received'] ?? 0),
                'paid' => (int)($summary['paid'] ?? 0),
                'cancelled' => (int)($summary['cancelled'] ?? 0),
                'total_spent' => round((float)($summary['total_spent'] ?? 0), 2),
                'pending_value' => round((float)($summary['pending_value'] ?? 0), 2),
            ],
            'pagination' => [
                'page' => $page, 'limit' => $limit, 'total' => $total,
                'pages' => max(1, (int)ceil($total / $limit)),
            ],
        ]);
    }

    // ── POST: Create PO ──────────────────────────────────────────
    if ($method === 'POST') {
        $input = getInput();
        $fornecedorId = (int)($input['fornecedor_id'] ?? 0);
        $items = $input['items'] ?? [];
        $notes = trim($input['notes'] ?? '');
        $expectedAt = !empty($input['expected_at']) ? $input['expected_at'] : null;

        if (!$fornecedorId) response(false, null, 'Fornecedor obrigatorio', 400);
        if (!is_array($items) || count($items) === 0) response(false, null, 'Pelo menos um item obrigatorio', 400);

        $db->beginTransaction();

        $orderNumber = $genOrderNumber();
        $stmt = $db->prepare("
            INSERT INTO om_cesta_purchase_orders
            (fornecedor_id, order_number, status, total_cost, expected_at, notes, created_by)
            VALUES (?, ?, 'pending', 0, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([$fornecedorId, $orderNumber, $expectedAt, $notes ?: null, $adminId ?: null]);
        $poId = (int)$stmt->fetchColumn();

        $totalCost = 0.0;
        $stmtItem = $db->prepare("
            INSERT INTO om_cesta_purchase_order_items
            (purchase_order_id, ingrediente_id, quantity_ordered, unit_price, subtotal)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($items as $it) {
            $ingId = (int)($it['ingrediente_id'] ?? 0);
            $qty   = (float)($it['quantity_ordered'] ?? 0);
            $price = (float)($it['unit_price'] ?? 0);
            if (!$ingId || $qty <= 0) continue;
            $subtotal = round($qty * $price, 2);
            $totalCost += $subtotal;
            $stmtItem->execute([$poId, $ingId, $qty, $price, $subtotal]);
        }

        $db->prepare("UPDATE om_cesta_purchase_orders SET total_cost = ?, updated_at = NOW() WHERE id = ?")
           ->execute([round($totalCost, 2), $poId]);

        $db->commit();
        response(true, ['id' => $poId, 'order_number' => $orderNumber, 'total_cost' => round($totalCost, 2)], 'Pedido de compra criado');
    }

    // ── PUT: action=send|receive|pay|cancel ──────────────────────
    if ($method === 'PUT') {
        $input = getInput();
        $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
        $action = $input['action'] ?? $_GET['action'] ?? '';
        if (!$id || !$action) response(false, null, 'ID e action obrigatorios', 400);

        $stmt = $db->prepare("SELECT * FROM om_cesta_purchase_orders WHERE id = ?");
        $stmt->execute([$id]);
        $po = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$po) response(false, null, 'Pedido nao encontrado', 404);

        if ($action === 'send') {
            if ($po['status'] !== 'pending') {
                response(false, null, 'So e possivel enviar pedidos pendentes', 400);
            }
            $db->prepare("UPDATE om_cesta_purchase_orders SET status = 'sent', updated_at = NOW() WHERE id = ?")->execute([$id]);
            response(true, null, 'Pedido enviado ao fornecedor');
        }

        if ($action === 'pay') {
            if (!in_array($po['status'], ['received','sent'], true)) {
                response(false, null, 'So e possivel pagar pedidos enviados ou recebidos', 400);
            }
            $db->prepare("UPDATE om_cesta_purchase_orders SET status = 'paid', paid_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$id]);
            response(true, null, 'Pedido marcado como pago');
        }

        if ($action === 'cancel') {
            if (in_array($po['status'], ['received','paid'], true)) {
                response(false, null, 'Nao e possivel cancelar pedidos recebidos ou pagos', 400);
            }
            $db->prepare("UPDATE om_cesta_purchase_orders SET status = 'cancelled', updated_at = NOW() WHERE id = ?")->execute([$id]);
            response(true, null, 'Pedido cancelado');
        }

        if ($action === 'receive') {
            if ($po['status'] === 'received' || $po['status'] === 'paid') {
                response(false, null, 'Pedido ja foi recebido', 400);
            }
            if ($po['status'] === 'cancelled') {
                response(false, null, 'Pedido cancelado', 400);
            }

            $db->beginTransaction();

            // Load items
            $stmt = $db->prepare("
                SELECT poi.*, i.cost_per_unit AS current_cost
                FROM om_cesta_purchase_order_items poi
                JOIN om_cesta_ingredientes i ON i.id = poi.ingrediente_id
                WHERE poi.purchase_order_id = ?
            ");
            $stmt->execute([$id]);
            $poItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Optional per-line quantity_received update
            $received = [];
            if (!empty($input['items']) && is_array($input['items'])) {
                foreach ($input['items'] as $r) {
                    if (!empty($r['id'])) {
                        $received[(int)$r['id']] = (float)($r['quantity_received'] ?? 0);
                    } elseif (!empty($r['ingrediente_id'])) {
                        $received['ing_' . (int)$r['ingrediente_id']] = (float)($r['quantity_received'] ?? 0);
                    }
                }
            }

            foreach ($poItems as $it) {
                $qtyReceived = null;
                if (isset($received[(int)$it['id']])) {
                    $qtyReceived = $received[(int)$it['id']];
                } elseif (isset($received['ing_' . (int)$it['ingrediente_id']])) {
                    $qtyReceived = $received['ing_' . (int)$it['ingrediente_id']];
                }
                // Default: full order quantity
                if ($qtyReceived === null) {
                    $qtyReceived = (float)$it['quantity_ordered'];
                }
                if ($qtyReceived <= 0) continue;

                // Update line
                $db->prepare("UPDATE om_cesta_purchase_order_items SET quantity_received = ? WHERE id = ?")
                   ->execute([$qtyReceived, (int)$it['id']]);

                // Create new stock lot
                $db->prepare("
                    INSERT INTO om_cesta_estoque
                    (ingrediente_id, quantity, lot_number, received_at, purchase_price, purchase_order_id, location)
                    VALUES (?, ?, ?, NOW(), ?, ?,
                        (SELECT COALESCE(storage_location, 'despensa') FROM om_cesta_ingredientes WHERE id = ?)
                    )
                ")->execute([
                    (int)$it['ingrediente_id'],
                    $qtyReceived,
                    $po['order_number'] . '-' . (int)$it['id'],
                    (float)$it['unit_price'],
                    $id,
                    (int)$it['ingrediente_id'],
                ]);

                // Stock movement
                $db->prepare("
                    INSERT INTO om_cesta_stock_movements
                    (ingrediente_id, movement_type, quantity, cost, reference_type, reference_id, notes, created_by)
                    VALUES (?, 'in', ?, ?, 'purchase_order', ?, ?, ?)
                ")->execute([
                    (int)$it['ingrediente_id'],
                    $qtyReceived,
                    round($qtyReceived * (float)$it['unit_price'], 2),
                    $id,
                    'Recebimento PO ' . $po['order_number'],
                    $adminId ?: null,
                ]);

                // Update ingredient cost_per_unit to latest (weighted-average could go here, keep simple: last price wins)
                $db->prepare("UPDATE om_cesta_ingredientes SET cost_per_unit = ?, updated_at = NOW() WHERE id = ?")
                   ->execute([(float)$it['unit_price'], (int)$it['ingrediente_id']]);
            }

            // Mark PO as received
            $db->prepare("
                UPDATE om_cesta_purchase_orders
                SET status = 'received', received_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ")->execute([$id]);

            $db->commit();
            response(true, null, 'Pedido recebido e estoque atualizado');
        }

        response(false, null, 'Acao invalida. Use send, receive, pay ou cancel', 400);
    }

    // ── DELETE: soft cancel ──────────────────────────────────────
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) response(false, null, 'ID obrigatorio', 400);
        $stmt = $db->prepare("
            UPDATE om_cesta_purchase_orders
            SET status = 'cancelled', updated_at = NOW()
            WHERE id = ? AND status IN ('pending','sent')
        ");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) response(false, null, 'Nao foi possivel cancelar (ja recebido/pago)', 400);
        response(true, null, 'Pedido cancelado');
    }

    response(false, null, 'Metodo nao permitido', 405);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('[cestas/purchase-orders] ' . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
