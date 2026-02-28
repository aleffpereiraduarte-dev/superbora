<?php
/**
 * /api/mercado/partner/estoque.php
 * Stock management API for partner panel
 *
 * GET                          - List all products with stock info
 * GET    action=movements      - Stock movement history
 * GET    action=alerts         - Products below minimum stock
 * POST   action=adjust         - Set absolute stock quantity
 * POST   action=movement       - Record stock movement (entrada/saida)
 * POST   action=config         - Update stock config per product
 * POST   action=bulk_adjust    - Bulk stock adjustment
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partnerId = (int)$payload['uid'];

    // Ensure stock tables exist
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

        // GET action=movements — Movement history
        if ($action === 'movements') {
            $productId = (int)($_GET['product_id'] ?? 0);
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
            $offset = max(0, (int)($_GET['offset'] ?? 0));

            $where = ["m.partner_id = ?"];
            $params = [$partnerId];

            if ($productId > 0) {
                $where[] = "m.product_id = ?";
                $params[] = $productId;
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

        // GET action=alerts — Products below minimum stock
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

        // Default GET — List all products with stock info
        $search = trim($_GET['search'] ?? '');
        $limit = min(200, max(1, (int)($_GET['limit'] ?? 100)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        if ($usesPriceModel) {
            $where = ["pp.partner_id = ?"];
            $params = [$partnerId];

            if ($search !== '') {
                $searchEsc = str_replace(['%', '_'], ['\\%', '\\_'], $search);
                $where[] = "(pb.name ILIKE ? OR pb.barcode ILIKE ?)";
                $searchParam = "%{$searchEsc}%";
                $params[] = $searchParam;
                $params[] = $searchParam;
            }

            $whereSQL = implode(" AND ", $where);

            $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_market_products_price pp INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id WHERE {$whereSQL}");
            $stmtCount->execute($params);
            $total = (int)$stmtCount->fetchColumn();

            $stmt = $db->prepare("
                SELECT pb.product_id, pb.name, pb.image, pb.barcode,
                       pp.price, pp.price_promo as promotional_price,
                       pp.stock as product_stock,
                       pp.status,
                       COALESCE(s.quantidade, pp.stock) as quantidade,
                       COALESCE(s.estoque_minimo, 0) as estoque_minimo,
                       COALESCE(s.auto_pausar, false) as auto_pausar,
                       s.ultima_movimentacao
                FROM om_market_products_price pp
                INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id
                LEFT JOIN om_market_product_stock s ON s.product_id = pp.product_id AND s.partner_id = pp.partner_id
                WHERE {$whereSQL}
                ORDER BY pb.name ASC
                LIMIT ? OFFSET ?
            ");
        } else {
            $where = ["p.partner_id = ?"];
            $params = [$partnerId];

            if ($search !== '') {
                $searchEsc = str_replace(['%', '_'], ['\\%', '\\_'], $search);
                $where[] = "(p.name ILIKE ? OR p.barcode ILIKE ?)";
                $searchParam = "%{$searchEsc}%";
                $params[] = $searchParam;
                $params[] = $searchParam;
            }

            $whereSQL = implode(" AND ", $where);

            $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_market_products p WHERE {$whereSQL}");
            $stmtCount->execute($params);
            $total = (int)$stmtCount->fetchColumn();

            $stmt = $db->prepare("
                SELECT p.product_id, p.name, p.image, p.barcode,
                       p.price, p.special_price as promotional_price,
                       COALESCE(p.quantity, p.stock, 0) as product_stock,
                       COALESCE(p.status, 1) as status,
                       COALESCE(s.quantidade, COALESCE(p.quantity, p.stock, 0)) as quantidade,
                       COALESCE(s.estoque_minimo, 0) as estoque_minimo,
                       COALESCE(s.auto_pausar, false) as auto_pausar,
                       s.ultima_movimentacao
                FROM om_market_products p
                LEFT JOIN om_market_product_stock s ON s.product_id = p.product_id AND s.partner_id = p.partner_id
                WHERE {$whereSQL}
                ORDER BY p.name ASC
                LIMIT ? OFFSET ?
            ");
        }

        $stmt->execute(array_merge($params, [$limit, $offset]));
        $items = $stmt->fetchAll();

        $products = [];
        foreach ($items as $item) {
            $qty = (int)$item['quantidade'];
            $minAlert = (int)$item['estoque_minimo'];
            $status = 'ok';
            if ($minAlert > 0) {
                if ($qty <= 0) {
                    $status = 'esgotado';
                } elseif ($qty <= $minAlert) {
                    $status = 'baixo';
                } elseif ($qty <= $minAlert * 1.5) {
                    $status = 'atencao';
                }
            } else {
                if ($qty <= 0) {
                    $status = 'esgotado';
                }
            }

            $products[] = [
                "product_id" => (int)$item['product_id'],
                "name" => $item['name'],
                "image" => $item['image'],
                "barcode" => $item['barcode'],
                "price" => (float)$item['price'],
                "promotional_price" => $item['promotional_price'] ? (float)$item['promotional_price'] : null,
                "product_stock" => (int)$item['product_stock'],
                "status" => (int)$item['status'],
                "quantidade" => $qty,
                "estoque_minimo" => $minAlert,
                "auto_pausar" => (bool)$item['auto_pausar'],
                "ultima_movimentacao" => $item['ultima_movimentacao'],
                "stock_status" => $status,
            ];
        }

        response(true, [
            "products" => $products,
            "total" => $total,
            "limit" => $limit,
            "offset" => $offset,
        ]);
    }

    // ── POST requests ──
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $input = getInput();
    $action = $input['action'] ?? '';

    // POST action=adjust — Set absolute stock quantity
    if ($action === 'adjust') {
        $productId = (int)($input['product_id'] ?? 0);
        $newQty = (int)($input['quantidade'] ?? 0);
        $motivo = trim(substr($input['motivo'] ?? 'Ajuste manual', 0, 255));

        if (!$productId) {
            response(false, null, "product_id obrigatorio", 400);
        }
        if ($newQty < 0) {
            response(false, null, "Quantidade nao pode ser negativa", 400);
        }

        // Verify product belongs to partner
        if (!verifyProductOwnership($db, $productId, $partnerId, $usesPriceModel)) {
            response(false, null, "Produto nao encontrado", 404);
        }

        $db->beginTransaction();
        try {
            // Get current quantity
            $currentQty = getCurrentStock($db, $productId, $partnerId, $usesPriceModel);

            // Record movement
            $diff = $newQty - $currentQty;
            $stmt = $db->prepare("
                INSERT INTO om_market_stock_movements
                (product_id, partner_id, tipo, quantidade_anterior, quantidade_nova, quantidade_diff, motivo, created_at)
                VALUES (?, ?, 'ajuste', ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$productId, $partnerId, $currentQty, $newQty, $diff, $motivo]);

            // Upsert stock record
            $stmt = $db->prepare("
                INSERT INTO om_market_product_stock (product_id, partner_id, quantidade, ultima_movimentacao, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
                ON CONFLICT (partner_id, product_id)
                DO UPDATE SET quantidade = EXCLUDED.quantidade,
                              ultima_movimentacao = NOW(),
                              updated_at = NOW()
            ");
            $stmt->execute([$productId, $partnerId, $newQty]);

            // Also update the main product table stock
            updateMainProductStock($db, $productId, $newQty, $usesPriceModel, $partnerId);

            // Check auto-pause
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

    // POST action=movement — Record stock movement (entrada/saida)
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

        // Verify product belongs to partner
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

            // Record movement
            $stmt = $db->prepare("
                INSERT INTO om_market_stock_movements
                (product_id, partner_id, tipo, quantidade_anterior, quantidade_nova, quantidade_diff, motivo, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$productId, $partnerId, $tipo, $currentQty, $newQty, $diff, $motivo]);

            // Upsert stock record
            $stmt = $db->prepare("
                INSERT INTO om_market_product_stock (product_id, partner_id, quantidade, ultima_movimentacao, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
                ON CONFLICT (partner_id, product_id)
                DO UPDATE SET quantidade = EXCLUDED.quantidade,
                              ultima_movimentacao = NOW(),
                              updated_at = NOW()
            ");
            $stmt->execute([$productId, $partnerId, $newQty]);

            // Update main product table
            updateMainProductStock($db, $productId, $newQty, $usesPriceModel, $partnerId);

            // Check auto-pause
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

    // POST action=config — Update stock config per product
    if ($action === 'config') {
        $productId = (int)($input['product_id'] ?? 0);
        $estoqueMinimo = max(0, (int)($input['estoque_minimo'] ?? 0));
        $autoPausar = (bool)($input['auto_pausar'] ?? false);

        if (!$productId) {
            response(false, null, "product_id obrigatorio", 400);
        }

        // Verify product belongs to partner
        if (!verifyProductOwnership($db, $productId, $partnerId, $usesPriceModel)) {
            response(false, null, "Produto nao encontrado", 404);
        }

        // Get current stock to include in upsert
        $currentQty = getCurrentStock($db, $productId, $partnerId, $usesPriceModel);

        $stmt = $db->prepare("
            INSERT INTO om_market_product_stock (product_id, partner_id, quantidade, estoque_minimo, auto_pausar, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON CONFLICT (partner_id, product_id)
            DO UPDATE SET estoque_minimo = EXCLUDED.estoque_minimo,
                          auto_pausar = EXCLUDED.auto_pausar,
                          updated_at = NOW()
        ");
        $stmt->execute([$productId, $partnerId, $currentQty, $estoqueMinimo, $autoPausar ? 1 : 0]);

        // If auto-pause is now active, check current stock
        if ($autoPausar) {
            checkAutoPause($db, $productId, $partnerId, $currentQty, $usesPriceModel);
        }

        response(true, [
            "product_id" => $productId,
            "estoque_minimo" => $estoqueMinimo,
            "auto_pausar" => $autoPausar,
        ], "Configuracao de estoque atualizada");
    }

    // POST action=bulk_adjust — Bulk stock adjustment
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
                $newQty = (int)($item['quantidade'] ?? 0);

                if (!$productId) continue;
                if ($newQty < 0) $newQty = 0;

                // Verify ownership
                if (!verifyProductOwnership($db, $productId, $partnerId, $usesPriceModel)) {
                    $results[] = ["product_id" => $productId, "error" => "Produto nao encontrado"];
                    continue;
                }

                $currentQty = getCurrentStock($db, $productId, $partnerId, $usesPriceModel);
                $diff = $newQty - $currentQty;

                // Record movement
                $stmt = $db->prepare("
                    INSERT INTO om_market_stock_movements
                    (product_id, partner_id, tipo, quantidade_anterior, quantidade_nova, quantidade_diff, motivo, created_at)
                    VALUES (?, ?, 'ajuste', ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$productId, $partnerId, $currentQty, $newQty, $diff, $motivo]);

                // Upsert stock
                $stmt = $db->prepare("
                    INSERT INTO om_market_product_stock (product_id, partner_id, quantidade, ultima_movimentacao, updated_at)
                    VALUES (?, ?, ?, NOW(), NOW())
                    ON CONFLICT (partner_id, product_id)
                    DO UPDATE SET quantidade = EXCLUDED.quantidade,
                                  ultima_movimentacao = NOW(),
                                  updated_at = NOW()
                ");
                $stmt->execute([$productId, $partnerId, $newQty]);

                // Update main product table
                updateMainProductStock($db, $productId, $newQty, $usesPriceModel, $partnerId);

                // Check auto-pause
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

    if (!$action) {
        response(false, null, "action obrigatorio para POST", 400);
    }

    // Unknown action
    response(false, null, "action desconhecida: " . sanitizeOutput($action), 400);

} catch (Exception $e) {
    error_log("[partner/estoque] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar estoque", 500);
}

// ── Helper Functions ──

function ensureStockTables(PDO $db): void {
    // No-op: tables created via migration
    return;
    // Check if tables exist first to avoid unnecessary DDL
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
                UNIQUE(partner_id, product_id)  -- MIGRATION NOTE: existing tables need: DROP CONSTRAINT ... ADD CONSTRAINT ... UNIQUE(partner_id, product_id)
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_stock_partner ON om_market_product_stock(partner_id)");
    }

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
    // First check stock management table (locked with FOR UPDATE to prevent read-then-write race)
    $stmt = $db->prepare("SELECT quantidade FROM om_market_product_stock WHERE product_id = ? AND partner_id = ? FOR UPDATE");
    $stmt->execute([$productId, $partnerId]);
    $row = $stmt->fetch();
    if ($row) {
        return (int)$row['quantidade'];
    }

    // Fall back to main product table
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

    // If stock is at or below minimum, pause the product
    if ($currentQty <= $minimo) {
        if ($usesPriceModel) {
            $stmt = $db->prepare("UPDATE om_market_products_price SET status = 0 WHERE product_id = ? AND partner_id = ?");
        } else {
            $stmt = $db->prepare("UPDATE om_market_products SET status = 0 WHERE product_id = ? AND partner_id = ?");
        }
        $stmt->execute([$productId, $partnerId]);
        error_log("[estoque] Auto-paused product {$productId} (stock={$currentQty}, min={$minimo})");
    }
    // If stock is above minimum and product was auto-paused, re-enable it
    elseif ($currentQty > $minimo) {
        if ($usesPriceModel) {
            $stmt = $db->prepare("UPDATE om_market_products_price SET status = 1 WHERE product_id = ? AND partner_id = ? AND status = 0");
        } else {
            $stmt = $db->prepare("UPDATE om_market_products SET status = 1 WHERE product_id = ? AND partner_id = ? AND status = 0");
        }
        $stmt->execute([$productId, $partnerId]);
    }
}
