<?php
/**
 * /api/mercado/partner/disponibilidade.php
 * Availability & Pause Management
 *
 * GET                              - Return full availability status
 * POST action=pause_store          - Pause entire store { duracao_minutos, motivo }
 * POST action=resume_store         - Resume (reopen) store
 * POST action=pause_product        - Pause a single product { product_id, motivo }
 * POST action=resume_product       - Resume a single product { product_id }
 * POST action=pause_category       - Pause a category + all its products { category_id, motivo }
 * POST action=resume_category      - Resume a category + all its products { category_id }
 * POST action=queue_config         - Set order queue limits { max_pedidos_simultaneos, tempo_estimado_extra_minutos }
 * POST action=schedule_pause       - Schedule a future pause { inicio, fim, motivo }
 * POST action=delete_scheduled     - Delete a scheduled pause { pause_id }
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/cache.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = (int)$payload['uid'];

    // Tables om_market_partner_pauses and om_market_partner_queue_config created via migration
    // Columns pause_until and pause_reason on om_market_partners created via migration

    // ── GET: Return full availability status ──
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        // 1) Store status
        $stmt = $db->prepare("SELECT is_open, pause_until, pause_reason, opens_at, closes_at FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$partnerId]);
        $partner = $stmt->fetch();
        if (!$partner) response(false, null, "Parceiro nao encontrado", 404);

        $isOpen = (bool)$partner['is_open'];
        $isPaused = !$isOpen && !empty($partner['pause_until']);
        $remainingMinutes = 0;
        $pauseUntil = $partner['pause_until'];

        if ($isPaused && $pauseUntil) {
            $now = new DateTime();
            $until = new DateTime($pauseUntil);
            if ($until > $now) {
                $diff = $now->diff($until);
                $remainingMinutes = ($diff->days * 1440) + ($diff->h * 60) + $diff->i + ($diff->s > 0 ? 1 : 0);
            } else {
                // Expired pause - auto-reopen
                $stmtResume = $db->prepare("UPDATE om_market_partners SET is_open = 1, pause_until = NULL, pause_reason = NULL, updated_at = NOW() WHERE partner_id = ?");
                $stmtResume->execute([$partnerId]);
                $isOpen = true;
                $isPaused = false;
                $pauseUntil = null;
                om_cache()->flush('store_');
            }
        }

        // Determine store_status string
        if ($isOpen) {
            $storeStatus = 'open';
        } elseif ($isPaused) {
            $storeStatus = 'paused';
        } else {
            $storeStatus = 'closed';
        }

        // 2) Paused products (status='0') — support both data models
        $pausedProducts = [];

        // Check if partner uses price table model
        $stmtCheck = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'om_market_products_price'");
        $stmtCheck->execute();
        $hasPriceTable = (int)$stmtCheck->fetchColumn() > 0;

        if ($hasPriceTable) {
            $stmtCheckPartner = $db->prepare("SELECT COUNT(*) FROM om_market_products_price WHERE partner_id = ?");
            $stmtCheckPartner->execute([$partnerId]);
            $usesPriceModel = (int)$stmtCheckPartner->fetchColumn() > 0;
        } else {
            $usesPriceModel = false;
        }

        if ($usesPriceModel) {
            $stmtPaused = $db->prepare("
                SELECT pp.product_id, pb.name, pp.status, pb.category_id,
                       cat.name as category_name
                FROM om_market_products_price pp
                INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id
                LEFT JOIN om_market_categories cat ON cat.category_id = pb.category_id
                WHERE pp.partner_id = ? AND pp.status = '0'
                ORDER BY pb.name ASC
            ");
            $stmtPaused->execute([$partnerId]);
        } else {
            $stmtPaused = $db->prepare("
                SELECT p.product_id, p.name, p.status, p.category_id,
                       COALESCE(c.name, p.category) as category_name
                FROM om_market_products p
                LEFT JOIN om_market_categories c ON c.category_id = p.category_id
                WHERE p.partner_id = ? AND COALESCE(p.status, '1') = '0'
                ORDER BY p.name ASC
            ");
            $stmtPaused->execute([$partnerId]);
        }
        $pausedProducts = $stmtPaused->fetchAll();
        foreach ($pausedProducts as &$pp) {
            $pp['product_id'] = (int)$pp['product_id'];
            $pp['category_id'] = (int)$pp['category_id'];
        }
        unset($pp);

        // 3) Paused categories - categories used by this partner's products that have status='0'
        $stmtPausedCats = $db->prepare("
            SELECT DISTINCT c.category_id, c.name
            FROM om_market_categories c
            INNER JOIN om_market_products p ON p.category_id = c.category_id AND p.partner_id = ?
            WHERE c.status = '0'
            ORDER BY c.name ASC
        ");
        $stmtPausedCats->execute([$partnerId]);
        $pausedCategories = $stmtPausedCats->fetchAll();
        foreach ($pausedCategories as &$pc) {
            $pc['category_id'] = (int)$pc['category_id'];
        }
        unset($pc);

        // 4) Queue config
        $stmtQueue = $db->prepare("SELECT max_pedidos_simultaneos, tempo_estimado_extra FROM om_market_partner_queue_config WHERE partner_id = ?");
        $stmtQueue->execute([$partnerId]);
        $queueConfig = $stmtQueue->fetch();
        if (!$queueConfig) {
            $queueConfig = ['max_pedidos_simultaneos' => 0, 'tempo_estimado_extra' => 0];
        }
        $queueConfig['max_pedidos_simultaneos'] = (int)$queueConfig['max_pedidos_simultaneos'];
        $queueConfig['tempo_estimado_extra'] = (int)$queueConfig['tempo_estimado_extra'];

        // 5) Active orders count
        $stmtActive = $db->prepare("
            SELECT COUNT(*) FROM om_market_orders
            WHERE partner_id = ? AND status IN ('pendente','aceito','preparando','pronto','em_entrega')
        ");
        $stmtActive->execute([$partnerId]);
        $activeOrders = (int)$stmtActive->fetchColumn();

        // 6) All products grouped by category (for toggles UI)
        $allProducts = [];
        if ($usesPriceModel) {
            $stmtAll = $db->prepare("
                SELECT pp.product_id, pb.name, pp.status, pb.category_id,
                       cat.name as category_name
                FROM om_market_products_price pp
                INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id
                LEFT JOIN om_market_categories cat ON cat.category_id = pb.category_id
                WHERE pp.partner_id = ?
                ORDER BY cat.name ASC NULLS LAST, pb.name ASC
            ");
            $stmtAll->execute([$partnerId]);
        } else {
            $stmtAll = $db->prepare("
                SELECT p.product_id, p.name, COALESCE(p.status, '1') as status, p.category_id,
                       COALESCE(c.name, p.category) as category_name
                FROM om_market_products p
                LEFT JOIN om_market_categories c ON c.category_id = p.category_id
                WHERE p.partner_id = ?
                ORDER BY c.name ASC NULLS LAST, p.name ASC
            ");
            $stmtAll->execute([$partnerId]);
        }
        $allProductsRaw = $stmtAll->fetchAll();
        foreach ($allProductsRaw as &$ap) {
            $ap['product_id'] = (int)$ap['product_id'];
            $ap['category_id'] = (int)$ap['category_id'];
            $ap['status'] = (int)$ap['status'];
            $ap['ativo'] = $ap['status'] === 1;
        }
        unset($ap);

        // 7) Scheduled pauses (future, still active)
        $stmtScheduled = $db->prepare("
            SELECT id, tipo, motivo, inicio, fim, ativo, created_at
            FROM om_market_partner_pauses
            WHERE partner_id = ? AND tipo = 'scheduled' AND ativo = 1 AND fim > NOW()
            ORDER BY inicio ASC
        ");
        $stmtScheduled->execute([$partnerId]);
        $scheduledPauses = $stmtScheduled->fetchAll();
        foreach ($scheduledPauses as &$sp) {
            $sp['id'] = (int)$sp['id'];
            $sp['ativo'] = (bool)$sp['ativo'];
        }
        unset($sp);

        // 8) Pause history (last 20)
        $stmtHistory = $db->prepare("
            SELECT id, tipo, motivo, inicio, fim, ativo, created_at
            FROM om_market_partner_pauses
            WHERE partner_id = ?
            ORDER BY created_at DESC
            LIMIT 20
        ");
        $stmtHistory->execute([$partnerId]);
        $pauseHistory = $stmtHistory->fetchAll();
        foreach ($pauseHistory as &$ph) {
            $ph['id'] = (int)$ph['id'];
            $ph['ativo'] = (bool)$ph['ativo'];
        }
        unset($ph);

        // 9) All categories for the partner
        $stmtAllCats = $db->prepare("
            SELECT category_id, name, status
            FROM om_market_categories
            WHERE status IN (0, 1) AND (created_by_partner_id = ? OR created_by_partner_id IS NULL)
            ORDER BY name ASC
        ");
        $stmtAllCats->execute([$partnerId]);
        $allCategories = $stmtAllCats->fetchAll();
        foreach ($allCategories as &$ac) {
            $ac['category_id'] = (int)$ac['category_id'];
            $ac['ativo'] = $ac['status'] === '1';
        }
        unset($ac);

        response(true, [
            "store_status" => $storeStatus,
            "is_open" => $isOpen,
            "is_paused" => $isPaused,
            "pause_until" => $pauseUntil,
            "pause_reason" => $partner['pause_reason'],
            "remaining_minutes" => $remainingMinutes,
            "horario_abertura" => $partner['opens_at'],
            "horario_fechamento" => $partner['closes_at'],
            "paused_products" => $pausedProducts,
            "paused_products_count" => count($pausedProducts),
            "paused_categories" => $pausedCategories,
            "paused_categories_count" => count($pausedCategories),
            "queue_config" => $queueConfig,
            "active_orders" => $activeOrders,
            "all_products" => $allProductsRaw,
            "all_categories" => $allCategories,
            "scheduled_pauses" => $scheduledPauses,
            "pause_history" => $pauseHistory,
        ]);
    }

    // ── POST: Actions ──
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $input = getInput();
    $action = trim($input['action'] ?? '');

    // ── PAUSE STORE ──
    if ($action === 'pause_store') {
        $duracaoMinutos = isset($input['duracao_minutos']) ? (int)$input['duracao_minutos'] : null;
        $motivo = trim(substr($input['motivo'] ?? 'Pausado pelo parceiro', 0, 255));

        // Validate duration
        $allowedDurations = [30, 60, 120, null];
        if ($duracaoMinutos !== null && $duracaoMinutos !== 0 && !in_array($duracaoMinutos, [30, 60, 120], true)) {
            // Allow any positive value up to 24h
            if ($duracaoMinutos < 1 || $duracaoMinutos > 1440) {
                response(false, null, "Duracao invalida. Use 30, 60, 120 minutos ou null para indefinido.", 400);
            }
        }

        $pauseUntil = null;
        if ($duracaoMinutos && $duracaoMinutos > 0) {
            $until = new DateTime();
            $until->modify("+{$duracaoMinutos} minutes");
            $pauseUntil = $until->format('Y-m-d H:i:s');
        }

        $db->beginTransaction();
        try {
            // Update partner
            $stmt = $db->prepare("
                UPDATE om_market_partners
                SET is_open = 0, pause_until = ?, pause_reason = ?, updated_at = NOW()
                WHERE partner_id = ?
            ");
            $stmt->execute([$pauseUntil, $motivo, $partnerId]);

            // Record in pause history
            $stmtPause = $db->prepare("
                INSERT INTO om_market_partner_pauses (partner_id, tipo, motivo, inicio, fim, ativo)
                VALUES (?, 'manual', ?, NOW(), ?, 1)
            ");
            $stmtPause->execute([$partnerId, $motivo, $pauseUntil]);

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        om_cache()->flush('store_');
        om_cache()->flush('admin_');

        response(true, [
            "is_open" => false,
            "is_paused" => true,
            "pause_until" => $pauseUntil,
            "duracao_minutos" => $duracaoMinutos,
        ], $duracaoMinutos ? "Loja pausada por {$duracaoMinutos} minutos" : "Loja pausada indefinidamente");
    }

    // ── RESUME STORE ──
    if ($action === 'resume_store') {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                UPDATE om_market_partners
                SET is_open = 1, pause_until = NULL, pause_reason = NULL, updated_at = NOW()
                WHERE partner_id = ?
            ");
            $stmt->execute([$partnerId]);

            // Deactivate current active pauses
            $stmtDeactivate = $db->prepare("
                UPDATE om_market_partner_pauses
                SET ativo = 0, fim = NOW()
                WHERE partner_id = ? AND ativo = 1 AND tipo IN ('manual', 'scheduled')
            ");
            $stmtDeactivate->execute([$partnerId]);

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        om_cache()->flush('store_');
        om_cache()->flush('admin_');

        response(true, [
            "is_open" => true,
            "is_paused" => false,
        ], "Loja reaberta com sucesso");
    }

    // ── PAUSE PRODUCT ──
    if ($action === 'pause_product') {
        $productId = (int)($input['product_id'] ?? 0);
        $motivo = trim(substr($input['motivo'] ?? '', 0, 255));

        if (!$productId) {
            response(false, null, "product_id obrigatorio", 400);
        }

        // Check which model
        $stmtCheck = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'om_market_products_price'");
        $stmtCheck->execute();
        $hasPriceTable = (int)$stmtCheck->fetchColumn() > 0;

        $updated = false;
        if ($hasPriceTable) {
            $stmtCheckOwner = $db->prepare("SELECT COUNT(*) FROM om_market_products_price WHERE product_id = ? AND partner_id = ?");
            $stmtCheckOwner->execute([$productId, $partnerId]);
            if ((int)$stmtCheckOwner->fetchColumn() > 0) {
                $stmt = $db->prepare("UPDATE om_market_products_price SET status = '0' WHERE product_id = ? AND partner_id = ?");
                $stmt->execute([$productId, $partnerId]);
                $updated = true;
            }
        }

        if (!$updated) {
            // Try simple model
            $stmtCheckOwner = $db->prepare("SELECT COUNT(*) FROM om_market_products WHERE product_id = ? AND partner_id = ?");
            $stmtCheckOwner->execute([$productId, $partnerId]);
            if ((int)$stmtCheckOwner->fetchColumn() === 0) {
                response(false, null, "Produto nao encontrado", 404);
            }
            $stmt = $db->prepare("UPDATE om_market_products SET status = '0' WHERE product_id = ? AND partner_id = ?");
            $stmt->execute([$productId, $partnerId]);
        }

        om_cache()->flush('store_');

        response(true, [
            "product_id" => $productId,
            "ativo" => false,
        ], "Produto pausado" . ($motivo ? ": {$motivo}" : ""));
    }

    // ── RESUME PRODUCT ──
    if ($action === 'resume_product') {
        $productId = (int)($input['product_id'] ?? 0);

        if (!$productId) {
            response(false, null, "product_id obrigatorio", 400);
        }

        // Check which model
        $stmtCheck = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'om_market_products_price'");
        $stmtCheck->execute();
        $hasPriceTable = (int)$stmtCheck->fetchColumn() > 0;

        $updated = false;
        if ($hasPriceTable) {
            $stmtCheckOwner = $db->prepare("SELECT COUNT(*) FROM om_market_products_price WHERE product_id = ? AND partner_id = ?");
            $stmtCheckOwner->execute([$productId, $partnerId]);
            if ((int)$stmtCheckOwner->fetchColumn() > 0) {
                $stmt = $db->prepare("UPDATE om_market_products_price SET status = '1' WHERE product_id = ? AND partner_id = ?");
                $stmt->execute([$productId, $partnerId]);
                $updated = true;
            }
        }

        if (!$updated) {
            $stmtCheckOwner = $db->prepare("SELECT COUNT(*) FROM om_market_products WHERE product_id = ? AND partner_id = ?");
            $stmtCheckOwner->execute([$productId, $partnerId]);
            if ((int)$stmtCheckOwner->fetchColumn() === 0) {
                response(false, null, "Produto nao encontrado", 404);
            }
            $stmt = $db->prepare("UPDATE om_market_products SET status = '1' WHERE product_id = ? AND partner_id = ?");
            $stmt->execute([$productId, $partnerId]);
        }

        om_cache()->flush('store_');

        response(true, [
            "product_id" => $productId,
            "ativo" => true,
        ], "Produto reativado");
    }

    // ── PAUSE CATEGORY ──
    if ($action === 'pause_category') {
        $categoryId = (int)($input['category_id'] ?? 0);
        $motivo = trim(substr($input['motivo'] ?? '', 0, 255));

        if (!$categoryId) {
            response(false, null, "category_id obrigatorio", 400);
        }

        // Verify category exists
        $stmtCat = $db->prepare("SELECT category_id, name FROM om_market_categories WHERE category_id = ?");
        $stmtCat->execute([$categoryId]);
        $cat = $stmtCat->fetch();
        if (!$cat) {
            response(false, null, "Categoria nao encontrada", 404);
        }

        $db->beginTransaction();
        try {
            // NOTE: Do NOT deactivate the shared category record — it affects all partners.
            // Only deactivate this partner's products in this category.

            // Deactivate all products in this category belonging to this partner
            $stmtCheck = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'om_market_products_price'");
            $stmtCheck->execute();
            $hasPriceTable = (int)$stmtCheck->fetchColumn() > 0;

            $productCount = 0;
            if ($hasPriceTable) {
                $stmtCheckPartner = $db->prepare("SELECT COUNT(*) FROM om_market_products_price WHERE partner_id = ?");
                $stmtCheckPartner->execute([$partnerId]);
                if ((int)$stmtCheckPartner->fetchColumn() > 0) {
                    $stmtProducts = $db->prepare("
                        UPDATE om_market_products_price pp
                        SET status = '0'
                        WHERE pp.partner_id = ? AND pp.product_id IN (
                            SELECT pb.product_id FROM om_market_products_base pb WHERE pb.category_id = ?
                        )
                    ");
                    $stmtProducts->execute([$partnerId, $categoryId]);
                    $productCount = $stmtProducts->rowCount();
                }
            }

            if ($productCount === 0) {
                $stmtProducts = $db->prepare("
                    UPDATE om_market_products SET status = '0'
                    WHERE partner_id = ? AND category_id = ?
                ");
                $stmtProducts->execute([$partnerId, $categoryId]);
                $productCount = $stmtProducts->rowCount();
            }

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        om_cache()->flush('store_');

        response(true, [
            "category_id" => $categoryId,
            "ativo" => false,
            "products_paused" => $productCount,
        ], "Categoria '{$cat['name']}' e {$productCount} produto(s) pausados");
    }

    // ── RESUME CATEGORY ──
    if ($action === 'resume_category') {
        $categoryId = (int)($input['category_id'] ?? 0);

        if (!$categoryId) {
            response(false, null, "category_id obrigatorio", 400);
        }

        $stmtCat = $db->prepare("SELECT category_id, name FROM om_market_categories WHERE category_id = ?");
        $stmtCat->execute([$categoryId]);
        $cat = $stmtCat->fetch();
        if (!$cat) {
            response(false, null, "Categoria nao encontrada", 404);
        }

        $db->beginTransaction();
        try {
            // Reactivate category for this partner only (not global)
            $stmt = $db->prepare("UPDATE om_market_partner_categories SET status = '1' WHERE category_id = ? AND partner_id = ?");
            $stmt->execute([$categoryId, $partnerId]);
            if ($stmt->rowCount() === 0) {
                // Fallback: insert partner-specific override if none exists
                $db->prepare("INSERT INTO om_market_partner_categories (category_id, partner_id, status) VALUES (?, ?, '1') ON CONFLICT (category_id, partner_id) DO UPDATE SET status = '1'")
                   ->execute([$categoryId, $partnerId]);
            }

            // Reactivate all products in this category belonging to this partner
            $stmtCheck = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'om_market_products_price'");
            $stmtCheck->execute();
            $hasPriceTable = (int)$stmtCheck->fetchColumn() > 0;

            $productCount = 0;
            if ($hasPriceTable) {
                $stmtCheckPartner = $db->prepare("SELECT COUNT(*) FROM om_market_products_price WHERE partner_id = ?");
                $stmtCheckPartner->execute([$partnerId]);
                if ((int)$stmtCheckPartner->fetchColumn() > 0) {
                    $stmtProducts = $db->prepare("
                        UPDATE om_market_products_price pp
                        SET status = '1'
                        WHERE pp.partner_id = ? AND pp.product_id IN (
                            SELECT pb.product_id FROM om_market_products_base pb WHERE pb.category_id = ?
                        )
                    ");
                    $stmtProducts->execute([$partnerId, $categoryId]);
                    $productCount = $stmtProducts->rowCount();
                }
            }

            if ($productCount === 0) {
                $stmtProducts = $db->prepare("
                    UPDATE om_market_products SET status = '1'
                    WHERE partner_id = ? AND category_id = ?
                ");
                $stmtProducts->execute([$partnerId, $categoryId]);
                $productCount = $stmtProducts->rowCount();
            }

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        om_cache()->flush('store_');

        response(true, [
            "category_id" => $categoryId,
            "ativo" => true,
            "products_resumed" => $productCount,
        ], "Categoria '{$cat['name']}' e {$productCount} produto(s) reativados");
    }

    // ── QUEUE CONFIG ──
    if ($action === 'queue_config') {
        $maxPedidos = max(0, min(50, (int)($input['max_pedidos_simultaneos'] ?? 0)));
        $tempoExtra = max(0, min(120, (int)($input['tempo_estimado_extra_minutos'] ?? 0)));

        $stmt = $db->prepare("
            INSERT INTO om_market_partner_queue_config (partner_id, max_pedidos_simultaneos, tempo_estimado_extra, updated_at)
            VALUES (?, ?, ?, NOW())
            ON CONFLICT (partner_id) DO UPDATE SET
                max_pedidos_simultaneos = EXCLUDED.max_pedidos_simultaneos,
                tempo_estimado_extra = EXCLUDED.tempo_estimado_extra,
                updated_at = NOW()
        ");
        $stmt->execute([$partnerId, $maxPedidos, $tempoExtra]);

        response(true, [
            "max_pedidos_simultaneos" => $maxPedidos,
            "tempo_estimado_extra" => $tempoExtra,
        ], "Configuracao de fila atualizada");
    }

    // ── SCHEDULE PAUSE ──
    if ($action === 'schedule_pause') {
        $inicio = trim($input['inicio'] ?? '');
        $fim = trim($input['fim'] ?? '');
        $motivo = trim(substr($input['motivo'] ?? 'Pausa agendada', 0, 255));

        if (!$inicio || !$fim) {
            response(false, null, "inicio e fim sao obrigatorios", 400);
        }

        // Validate datetime format
        $dtInicio = DateTime::createFromFormat('Y-m-d\TH:i', $inicio) ?: DateTime::createFromFormat('Y-m-d H:i:s', $inicio) ?: DateTime::createFromFormat('Y-m-d H:i', $inicio);
        $dtFim = DateTime::createFromFormat('Y-m-d\TH:i', $fim) ?: DateTime::createFromFormat('Y-m-d H:i:s', $fim) ?: DateTime::createFromFormat('Y-m-d H:i', $fim);

        if (!$dtInicio || !$dtFim) {
            response(false, null, "Formato de data invalido. Use YYYY-MM-DD HH:MM", 400);
        }

        if ($dtFim <= $dtInicio) {
            response(false, null, "Data de fim deve ser posterior a data de inicio", 400);
        }

        if ($dtInicio < new DateTime()) {
            response(false, null, "Data de inicio deve ser no futuro", 400);
        }

        // Max 7 days pause
        $diffDays = $dtInicio->diff($dtFim)->days;
        if ($diffDays > 7) {
            response(false, null, "Pausa agendada nao pode exceder 7 dias", 400);
        }

        $stmt = $db->prepare("
            INSERT INTO om_market_partner_pauses (partner_id, tipo, motivo, inicio, fim, ativo)
            VALUES (?, 'scheduled', ?, ?, ?, 1)
            RETURNING id
        ");
        $stmt->execute([$partnerId, $motivo, $dtInicio->format('Y-m-d H:i:s'), $dtFim->format('Y-m-d H:i:s')]);
        $pauseId = (int)$stmt->fetchColumn();

        response(true, [
            "pause_id" => $pauseId,
            "inicio" => $dtInicio->format('Y-m-d H:i:s'),
            "fim" => $dtFim->format('Y-m-d H:i:s'),
            "motivo" => $motivo,
        ], "Pausa agendada com sucesso");
    }

    // ── DELETE SCHEDULED PAUSE ──
    if ($action === 'delete_scheduled') {
        $pauseId = (int)($input['pause_id'] ?? 0);

        if (!$pauseId) {
            response(false, null, "pause_id obrigatorio", 400);
        }

        $stmt = $db->prepare("
            DELETE FROM om_market_partner_pauses
            WHERE id = ? AND partner_id = ? AND tipo = 'scheduled' AND ativo = 1
        ");
        $stmt->execute([$pauseId, $partnerId]);

        if ($stmt->rowCount() === 0) {
            response(false, null, "Pausa agendada nao encontrada", 404);
        }

        response(true, ["pause_id" => $pauseId], "Pausa agendada removida");
    }

    if (!$action) {
        response(false, null, "action obrigatorio", 400);
    }

    // Unknown action
    response(false, null, "Acao desconhecida: {$action}", 400);

} catch (Exception $e) {
    error_log("[partner/disponibilidade] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar disponibilidade", 500);
}
