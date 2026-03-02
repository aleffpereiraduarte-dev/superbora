<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * GET/POST/PUT/DELETE /api/mercado/partner/boost.php
 *
 * Ads/Boost system — partners can pay to boost store visibility.
 *
 * GET    — List partner's boosts with aggregate stats
 * POST   — Create new boost (deducts initial daily budget from wallet)
 * PUT    — Update boost (pause/resume/cancel, adjust budget)
 * DELETE — Cancel boost and refund remaining daily budget
 * ══════════════════════════════════════════════════════════════════════════════
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];
    $method = $_SERVER["REQUEST_METHOD"];

    // ═════════════════════════════════════════════════════════════════
    // GET: List partner boosts with stats
    // ═════════════════════════════════════════════════════════════════
    if ($method === "GET") {
        $status_filter = trim($_GET['status'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = ["b.partner_id = ?"];
        $params = [$partner_id];

        if ($status_filter !== '') {
            $where[] = "b.status = ?";
            $params[] = $status_filter;
        }

        $whereSQL = implode(" AND ", $where);

        // Count total
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_partner_boosts b WHERE {$whereSQL}");
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        // Fetch boosts
        $params[] = $limit;
        $params[] = $offset;
        $stmt = $db->prepare("
            SELECT
                b.boost_id,
                b.boost_type,
                b.status,
                b.budget_daily,
                b.budget_spent,
                b.budget_total,
                b.bid_amount,
                b.impressions,
                b.clicks,
                b.orders_from_boost,
                b.revenue_from_boost,
                b.target_cities,
                b.target_categories,
                b.start_date,
                b.end_date,
                b.created_at,
                b.updated_at
            FROM om_partner_boosts b
            WHERE {$whereSQL}
            ORDER BY b.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $boosts = [];
        foreach ($rows as $r) {
            $boosts[] = [
                "boost_id" => (int)$r['boost_id'],
                "boost_type" => $r['boost_type'],
                "status" => $r['status'],
                "budget_daily" => (float)$r['budget_daily'],
                "budget_spent" => (float)$r['budget_spent'],
                "budget_total" => (float)$r['budget_total'],
                "bid_amount" => (float)$r['bid_amount'],
                "impressions" => (int)$r['impressions'],
                "clicks" => (int)$r['clicks'],
                "orders_from_boost" => (int)$r['orders_from_boost'],
                "revenue_from_boost" => (float)$r['revenue_from_boost'],
                "target_cities" => $r['target_cities'] ? json_decode($r['target_cities'], true) : null,
                "target_categories" => $r['target_categories'] ? json_decode($r['target_categories'], true) : null,
                "start_date" => $r['start_date'],
                "end_date" => $r['end_date'],
                "created_at" => $r['created_at'],
                "updated_at" => $r['updated_at'],
            ];
        }

        // Aggregate stats across all boosts for this partner
        $stmtStats = $db->prepare("
            SELECT
                COALESCE(SUM(budget_total), 0) as total_spent,
                COALESCE(SUM(impressions), 0) as total_impressions,
                COALESCE(SUM(clicks), 0) as total_clicks,
                COALESCE(SUM(orders_from_boost), 0) as total_orders,
                COALESCE(SUM(revenue_from_boost), 0) as total_revenue
            FROM om_partner_boosts
            WHERE partner_id = ?
        ");
        $stmtStats->execute([$partner_id]);
        $s = $stmtStats->fetch();

        $total_spent = (float)$s['total_spent'];
        $total_clicks = (int)$s['total_clicks'];
        $total_revenue = (float)$s['total_revenue'];

        $stats = [
            "total_spent" => round($total_spent, 2),
            "total_impressions" => (int)$s['total_impressions'],
            "total_clicks" => $total_clicks,
            "total_orders" => (int)$s['total_orders'],
            "avg_cpc" => $total_clicks > 0 ? round($total_spent / $total_clicks, 2) : 0,
            "roi" => $total_spent > 0 ? round($total_revenue / $total_spent, 2) : 0,
        ];

        $pages = $total > 0 ? (int)ceil($total / $limit) : 1;

        response(true, [
            "boosts" => $boosts,
            "stats" => $stats,
            "pagination" => [
                "total" => $total,
                "page" => $page,
                "pages" => $pages,
                "limit" => $limit,
            ],
        ], "Boosts listados");
    }

    // ═════════════════════════════════════════════════════════════════
    // POST: Create new boost
    // ═════════════════════════════════════════════════════════════════
    elseif ($method === "POST") {
        $input = getInput();

        $boost_type = trim($input['boost_type'] ?? '');
        $budget_daily = (float)($input['budget_daily'] ?? 0);
        $start_date = trim($input['start_date'] ?? '');
        $end_date = trim($input['end_date'] ?? '');
        $target_cities = $input['target_cities'] ?? null;
        $target_categories = $input['target_categories'] ?? null;

        // Validate boost type
        $valid_types = ['destaque', 'topo', 'banner', 'busca'];
        if (!in_array($boost_type, $valid_types, true)) {
            response(false, null, "Tipo de boost invalido. Tipos validos: " . implode(', ', $valid_types), 400);
        }

        // Validate daily budget (min R$5)
        if ($budget_daily < 5.00) {
            response(false, null, "Orcamento diario minimo e R\$5,00", 400);
        }

        // Validate start date
        if (empty($start_date)) {
            response(false, null, "Data de inicio e obrigatoria", 400);
        }

        // End date is optional but must be >= start_date if provided
        if (!empty($end_date) && $end_date < $start_date) {
            response(false, null, "Data fim deve ser maior ou igual a data inicio", 400);
        }

        // Calculate bid amount based on boost type
        $bid_amounts = [
            'destaque' => 0.50,
            'topo' => 0.80,
            'banner' => 1.00,
            'busca' => 2.00, // "Super Boost" — all placements
        ];
        $bid_amount = $bid_amounts[$boost_type] ?? 0.50;

        // Check partner wallet balance
        $stmtWallet = $db->prepare("
            SELECT saldo_disponivel
            FROM om_mercado_saldo
            WHERE partner_id = ?
        ");
        $stmtWallet->execute([$partner_id]);
        $wallet = $stmtWallet->fetch();
        $saldo = $wallet ? (float)$wallet['saldo_disponivel'] : 0;

        if ($saldo < $budget_daily) {
            response(false, [
                "saldo_disponivel" => round($saldo, 2),
                "budget_required" => $budget_daily,
            ], "Saldo insuficiente. Seu saldo e R\$" . number_format($saldo, 2, ',', '.') . " e o orcamento diario e R\$" . number_format($budget_daily, 2, ',', '.'), 400);
        }

        // Serialize target arrays
        $target_cities_json = is_array($target_cities) ? json_encode($target_cities) : null;
        $target_categories_json = is_array($target_categories) ? json_encode($target_categories) : null;

        // Begin transaction: create boost + deduct wallet + log transaction
        $db->beginTransaction();
        try {
            // Create the boost
            $stmtInsert = $db->prepare("
                INSERT INTO om_partner_boosts
                    (partner_id, boost_type, status, budget_daily, budget_spent, budget_total, bid_amount,
                     target_cities, target_categories, start_date, end_date, created_at, updated_at)
                VALUES (?, ?, 'active', ?, 0, 0, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmtInsert->execute([
                $partner_id,
                $boost_type,
                $budget_daily,
                $bid_amount,
                $target_cities_json,
                $target_categories_json,
                $start_date,
                $end_date ?: null,
            ]);
            $boost_id = (int)$db->lastInsertId('om_partner_boosts_boost_id_seq');

            // Deduct initial daily budget from wallet
            $stmtDeduct = $db->prepare("
                UPDATE om_mercado_saldo
                SET saldo_disponivel = saldo_disponivel - ?
                WHERE partner_id = ? AND saldo_disponivel >= ?
            ");
            $stmtDeduct->execute([$budget_daily, $partner_id, $budget_daily]);

            if ($stmtDeduct->rowCount() === 0) {
                throw new Exception("Falha ao debitar saldo");
            }

            // Log wallet transaction
            $stmtWalletTx = $db->prepare("
                INSERT INTO om_mercado_wallet (partner_id, tipo, valor, descricao, status, created_at)
                VALUES (?, 'debito', ?, ?, 'completed', NOW())
            ");
            $stmtWalletTx->execute([
                $partner_id,
                $budget_daily,
                "Boost #{$boost_id} - {$boost_type} - Orcamento diario"
            ]);

            // Log boost transaction
            $stmtBoostTx = $db->prepare("
                INSERT INTO om_boost_transactions (boost_id, partner_id, amount, transaction_type, description, created_at)
                VALUES (?, ?, ?, 'charge', ?, NOW())
            ");
            $stmtBoostTx->execute([
                $boost_id,
                $partner_id,
                $budget_daily,
                "Cobranca inicial - Orcamento diario {$boost_type}"
            ]);

            $db->commit();

            om_audit()->log(OmAudit::ACTION_CREATE, 'boost', $boost_id, null,
                ['boost_type' => $boost_type, 'budget_daily' => $budget_daily],
                "Boost #{$boost_id} criado ({$boost_type})", 'partner', $partner_id);

            response(true, [
                "boost_id" => $boost_id,
                "boost_type" => $boost_type,
                "status" => "active",
                "budget_daily" => $budget_daily,
                "bid_amount" => $bid_amount,
                "start_date" => $start_date,
                "end_date" => $end_date ?: null,
            ], "Boost criado com sucesso");

        } catch (Exception $e) {
            $db->rollBack();
            error_log("[partner/boost] Create transaction failed: " . $e->getMessage());
            response(false, null, "Erro ao criar boost: " . $e->getMessage(), 500);
        }
    }

    // ═════════════════════════════════════════════════════════════════
    // PUT: Update boost (pause/resume/cancel, adjust budget)
    // ═════════════════════════════════════════════════════════════════
    elseif ($method === "PUT") {
        $input = getInput();
        $boost_id = (int)($input['boost_id'] ?? 0);

        if (!$boost_id) {
            response(false, null, "boost_id e obrigatorio", 400);
        }

        // Fetch the boost with ownership check
        $stmtFetch = $db->prepare("
            SELECT boost_id, status, budget_daily, budget_spent, boost_type
            FROM om_partner_boosts
            WHERE boost_id = ? AND partner_id = ?
            FOR UPDATE
        ");

        $db->beginTransaction();
        try {
            $stmtFetch->execute([$boost_id, $partner_id]);
            $boost = $stmtFetch->fetch();

            if (!$boost) {
                $db->rollBack();
                response(false, null, "Boost nao encontrado", 404);
            }

            $current_status = $boost['status'];

            // Can't modify expired or cancelled boosts
            if (in_array($current_status, ['expired', 'cancelled'], true)) {
                $db->rollBack();
                response(false, null, "Nao e possivel modificar um boost {$current_status}", 400);
            }

            $updates = [];
            $update_params = [];
            $audit_changes = [];

            // Handle action: pause, resume, cancel
            $action = trim($input['action'] ?? '');
            if ($action === 'pause' && $current_status === 'active') {
                $updates[] = "status = 'paused'";
                $audit_changes['status'] = 'paused';
            } elseif ($action === 'resume' && $current_status === 'paused') {
                $updates[] = "status = 'active'";
                $audit_changes['status'] = 'active';
            } elseif ($action === 'cancel') {
                $updates[] = "status = 'cancelled'";
                $audit_changes['status'] = 'cancelled';

                // Refund remaining daily budget (budget_daily - budget_spent)
                $refund_amount = max(0, (float)$boost['budget_daily'] - (float)$boost['budget_spent']);
                if ($refund_amount > 0) {
                    $stmtRefund = $db->prepare("
                        UPDATE om_mercado_saldo
                        SET saldo_disponivel = saldo_disponivel + ?
                        WHERE partner_id = ?
                    ");
                    $stmtRefund->execute([$refund_amount, $partner_id]);

                    // Log wallet credit
                    $stmtWalletTx = $db->prepare("
                        INSERT INTO om_mercado_wallet (partner_id, tipo, valor, descricao, status, created_at)
                        VALUES (?, 'credito', ?, ?, 'completed', NOW())
                    ");
                    $stmtWalletTx->execute([
                        $partner_id,
                        $refund_amount,
                        "Reembolso Boost #{$boost_id} - Cancelamento"
                    ]);

                    // Log boost refund transaction
                    $stmtBoostTx = $db->prepare("
                        INSERT INTO om_boost_transactions (boost_id, partner_id, amount, transaction_type, description, created_at)
                        VALUES (?, ?, ?, 'refund', ?, NOW())
                    ");
                    $stmtBoostTx->execute([
                        $boost_id,
                        $partner_id,
                        $refund_amount,
                        "Reembolso por cancelamento"
                    ]);

                    $audit_changes['refund_amount'] = $refund_amount;
                }
            }

            // Handle budget adjustment
            $new_budget = isset($input['budget_daily']) ? (float)$input['budget_daily'] : null;
            if ($new_budget !== null && $action !== 'cancel') {
                if ($new_budget < 5.00) {
                    $db->rollBack();
                    response(false, null, "Orcamento diario minimo e R\$5,00", 400);
                }
                $updates[] = "budget_daily = ?";
                $update_params[] = $new_budget;
                $audit_changes['budget_daily'] = $new_budget;
            }

            // Handle end_date adjustment
            if (isset($input['end_date'])) {
                $new_end = trim($input['end_date']);
                if ($new_end === '' || $new_end === 'null') {
                    $updates[] = "end_date = NULL";
                } else {
                    $updates[] = "end_date = ?";
                    $update_params[] = $new_end;
                }
                $audit_changes['end_date'] = $new_end ?: null;
            }

            if (empty($updates)) {
                $db->rollBack();
                response(false, null, "Nenhuma alteracao informada", 400);
            }

            $updates[] = "updated_at = NOW()";
            $updateSQL = implode(", ", $updates);
            $update_params[] = $boost_id;
            $update_params[] = $partner_id;

            $stmtUpdate = $db->prepare("
                UPDATE om_partner_boosts
                SET {$updateSQL}
                WHERE boost_id = ? AND partner_id = ?
            ");
            $stmtUpdate->execute($update_params);

            $db->commit();

            om_audit()->log(OmAudit::ACTION_UPDATE, 'boost', $boost_id, null,
                $audit_changes, "Boost #{$boost_id} atualizado", 'partner', $partner_id);

            response(true, [
                "boost_id" => $boost_id,
                "changes" => $audit_changes,
            ], "Boost atualizado com sucesso");

        } catch (Exception $e) {
            $db->rollBack();
            error_log("[partner/boost] Update failed: " . $e->getMessage());
            response(false, null, "Erro ao atualizar boost", 500);
        }
    }

    // ═════════════════════════════════════════════════════════════════
    // DELETE: Cancel boost and refund
    // ═════════════════════════════════════════════════════════════════
    elseif ($method === "DELETE") {
        $boost_id = (int)($_GET['boost_id'] ?? 0);
        if (!$boost_id) {
            $input = getInput();
            $boost_id = (int)($input['boost_id'] ?? 0);
        }

        if (!$boost_id) {
            response(false, null, "boost_id e obrigatorio", 400);
        }

        $db->beginTransaction();
        try {
            $stmtFetch = $db->prepare("
                SELECT boost_id, status, budget_daily, budget_spent
                FROM om_partner_boosts
                WHERE boost_id = ? AND partner_id = ?
                FOR UPDATE
            ");
            $stmtFetch->execute([$boost_id, $partner_id]);
            $boost = $stmtFetch->fetch();

            if (!$boost) {
                $db->rollBack();
                response(false, null, "Boost nao encontrado", 404);
            }

            if ($boost['status'] === 'cancelled') {
                $db->rollBack();
                response(false, null, "Boost ja foi cancelado", 400);
            }

            // Cancel the boost
            $stmtCancel = $db->prepare("
                UPDATE om_partner_boosts
                SET status = 'cancelled', updated_at = NOW()
                WHERE boost_id = ? AND partner_id = ?
            ");
            $stmtCancel->execute([$boost_id, $partner_id]);

            // Refund remaining daily budget
            $refund_amount = max(0, (float)$boost['budget_daily'] - (float)$boost['budget_spent']);
            if ($refund_amount > 0) {
                $stmtRefund = $db->prepare("
                    UPDATE om_mercado_saldo
                    SET saldo_disponivel = saldo_disponivel + ?
                    WHERE partner_id = ?
                ");
                $stmtRefund->execute([$refund_amount, $partner_id]);

                $stmtWalletTx = $db->prepare("
                    INSERT INTO om_mercado_wallet (partner_id, tipo, valor, descricao, status, created_at)
                    VALUES (?, 'credito', ?, ?, 'completed', NOW())
                ");
                $stmtWalletTx->execute([
                    $partner_id,
                    $refund_amount,
                    "Reembolso Boost #{$boost_id} - Cancelamento"
                ]);

                $stmtBoostTx = $db->prepare("
                    INSERT INTO om_boost_transactions (boost_id, partner_id, amount, transaction_type, description, created_at)
                    VALUES (?, ?, ?, 'refund', ?, NOW())
                ");
                $stmtBoostTx->execute([
                    $boost_id,
                    $partner_id,
                    $refund_amount,
                    "Reembolso por cancelamento via DELETE"
                ]);
            }

            $db->commit();

            om_audit()->log(OmAudit::ACTION_DELETE, 'boost', $boost_id, null,
                ['refund_amount' => $refund_amount],
                "Boost #{$boost_id} cancelado", 'partner', $partner_id);

            response(true, [
                "boost_id" => $boost_id,
                "refund_amount" => round($refund_amount, 2),
            ], "Boost cancelado. Reembolso de R\$" . number_format($refund_amount, 2, ',', '.'));

        } catch (Exception $e) {
            $db->rollBack();
            error_log("[partner/boost] Delete failed: " . $e->getMessage());
            response(false, null, "Erro ao cancelar boost", 500);
        }
    }

    else {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[partner/boost] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
