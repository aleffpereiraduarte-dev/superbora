<?php
/**
 * /api/mercado/admin/partner-actions.php
 *
 * Painel administrativo - Acoes sobre parceiros (aprovar, suspender, banir, reativar, notas).
 *
 * GET  ?action=list&status=X&search=X&page=1       - Listar parceiros com filtros
 * GET  ?action=detail&partner_id=X                  - Detalhes completos do parceiro
 * GET  ?action=issues&partner_id=X                  - Listar problemas reportados pelo parceiro
 *
 * POST action=approve     { partner_id, note }                   - Aprovar parceiro pendente
 * POST action=suspend     { partner_id, reason, duration_days }  - Suspender parceiro
 * POST action=ban         { partner_id, reason }                 - Banir parceiro permanentemente
 * POST action=reactivate  { partner_id, note }                   - Reativar parceiro
 * POST action=add_note    { partner_id, note }                   - Adicionar nota administrativa
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = (int)$payload['uid'];

    // Garantir que as colunas auxiliares existem
    ensurePartnerSchema($db);

    $action = trim($_GET['action'] ?? $_POST['action'] ?? '');
    if (!$action) response(false, null, "Parametro 'action' obrigatorio", 400);

    // =================== GET ===================
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        // --- Listar parceiros com filtros ---
        if ($action === 'list') {
            $status = trim($_GET['status'] ?? '');
            $search = trim($_GET['search'] ?? '');
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;

            $where = ["1=1"];
            $params = [];

            // Filtro por status
            // Suporta tanto o formato texto (active, suspended, banned, pending)
            // quanto o formato numerico legado (0=pending, 1=active, 2=rejected, 3=suspended)
            if ($status !== '') {
                $status_map = [
                    'pending' => '0',
                    'active' => '1',
                    'rejected' => '2',
                    'suspended' => '3',
                    'banned' => '4'
                ];
                $db_status = $status_map[$status] ?? $status;
                $where[] = "p.status = ?";
                $params[] = $db_status;
            }

            // Busca por nome, email ou CNPJ
            if ($search !== '') {
                $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
                $where[] = "(p.name ILIKE ? OR p.email ILIKE ? OR p.cnpj ILIKE ?)";
                $s = "%{$escaped}%";
                $params = array_merge($params, [$s, $s, $s]);
            }

            $where_sql = implode(' AND ', $where);

            // Contagem total
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_market_partners p WHERE {$where_sql}");
            $stmt->execute($params);
            $total = (int)$stmt->fetch()['total'];

            // Buscar parceiros com estatisticas basicas
            $stmt = $db->prepare("
                SELECT p.partner_id, p.name, p.email, p.cnpj, p.phone,
                       p.status, p.suspended_until, p.suspension_reason,
                       p.logo, p.category, p.created_at,
                       (SELECT COUNT(*) FROM om_market_orders o WHERE o.partner_id = p.partner_id) as orders_count,
                       (SELECT COALESCE(SUM(o2.total), 0) FROM om_market_orders o2
                        WHERE o2.partner_id = p.partner_id AND o2.status NOT IN ('cancelled','refunded')) as total_revenue
                FROM om_market_partners p
                WHERE {$where_sql}
                ORDER BY p.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            $parceiros = $stmt->fetchAll();

            // Mapear status numerico para label legivel
            $status_labels = [
                '0' => 'pending', '1' => 'active', '2' => 'rejected',
                '3' => 'suspended', '4' => 'banned'
            ];
            foreach ($parceiros as &$p) {
                $p['status_label'] = $status_labels[$p['status']] ?? $p['status'];
            }
            unset($p);

            response(true, [
                'partners' => $parceiros,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => (int)ceil($total / $limit)
                ]
            ], "Parceiros listados");
        }

        // --- Detalhes completos do parceiro ---
        if ($action === 'detail') {
            $partner_id = (int)($_GET['partner_id'] ?? 0);
            if (!$partner_id) response(false, null, "partner_id obrigatorio", 400);

            // Dados do parceiro (explicit columns — excludes password_hash, password, salt, token)
            $stmt = $db->prepare("
                SELECT partner_id, name, trade_name, email, phone, cnpj,
                       address, city, state, neighborhood, zip_code,
                       logo, banner, category, description, status,
                       is_open, opening_hours, delivery_fee, min_order,
                       rating, rating_count, commission_rate,
                       suspended_until, suspension_reason,
                       created_at, updated_at
                FROM om_market_partners WHERE partner_id = ?
            ");
            $stmt->execute([$partner_id]);
            $partner = $stmt->fetch();
            if (!$partner) response(false, null, "Parceiro nao encontrado", 404);

            // Estatisticas de pedidos
            $stmt = $db->prepare("
                SELECT COUNT(*) as total_orders,
                       COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','refunded') THEN total ELSE 0 END), 0) as total_revenue,
                       COALESCE(AVG(CASE WHEN status NOT IN ('cancelled','refunded') THEN total END), 0) as avg_order,
                       MAX(created_at) as last_order_date,
                       SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
                       SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) as refunded_count
                FROM om_market_orders
                WHERE partner_id = ?
            ");
            $stmt->execute([$partner_id]);
            $order_stats = $stmt->fetch();

            // Estatisticas de disputas
            $dispute_stats = ['total' => 0, 'open' => 0, 'resolved' => 0, 'total_refunded' => 0];
            try {
                $stmt = $db->prepare("
                    SELECT COUNT(*) as total,
                           SUM(CASE WHEN status IN ('open','in_review','awaiting_evidence','escalated') THEN 1 ELSE 0 END) as open,
                           SUM(CASE WHEN status IN ('auto_resolved','resolved','closed') THEN 1 ELSE 0 END) as resolved,
                           COALESCE(SUM(approved_amount), 0) as total_refunded
                    FROM om_order_disputes
                    WHERE partner_id = ?
                ");
                $stmt->execute([$partner_id]);
                $dispute_stats = $stmt->fetch();
            } catch (Exception $e) {
                // Tabela pode nao existir
            }

            // Problemas reportados pelo parceiro (resumo)
            $issues_summary = ['total' => 0, 'open' => 0];
            try {
                $stmt = $db->prepare("
                    SELECT COUNT(*) as total,
                           SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open
                    FROM om_partner_issues
                    WHERE partner_id = ?
                ");
                $stmt->execute([$partner_id]);
                $issues_summary = $stmt->fetch();
            } catch (Exception $e) {
                // Tabela pode nao existir
            }

            // Receita por periodo (ultimos 30 dias vs 30 dias anteriores)
            $revenue = ['last_30_days' => 0, 'previous_30_days' => 0];
            try {
                $stmt = $db->prepare("
                    SELECT
                        COALESCE(SUM(CASE WHEN created_at >= NOW() - INTERVAL '30 days' THEN total ELSE 0 END), 0) as last_30_days,
                        COALESCE(SUM(CASE WHEN created_at >= NOW() - INTERVAL '60 days'
                                          AND created_at < NOW() - INTERVAL '30 days' THEN total ELSE 0 END), 0) as previous_30_days
                    FROM om_market_orders
                    WHERE partner_id = ? AND status NOT IN ('cancelled','refunded')
                ");
                $stmt->execute([$partner_id]);
                $revenue = $stmt->fetch();
            } catch (Exception $e) {}

            // Quantidade de produtos
            $products_count = 0;
            try {
                $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_market_products WHERE partner_id = ?");
                $stmt->execute([$partner_id]);
                $products_count = (int)$stmt->fetch()['total'];
            } catch (Exception $e) {}

            // Pedidos recentes (ultimos 10)
            $recent_orders = [];
            try {
                $stmt = $db->prepare("
                    SELECT o.order_id, o.status, o.total, o.created_at,
                           c.name as customer_name
                    FROM om_market_orders o
                    LEFT JOIN om_customers c ON o.customer_id = c.customer_id
                    WHERE o.partner_id = ?
                    ORDER BY o.created_at DESC LIMIT 10
                ");
                $stmt->execute([$partner_id]);
                $recent_orders = $stmt->fetchAll();
            } catch (Exception $e) {}

            // Notas administrativas
            $admin_notes = [];
            try {
                $stmt = $db->prepare("
                    SELECT id, admin_id, note, created_at
                    FROM om_admin_notes
                    WHERE entity_type = 'partner' AND entity_id = ?
                    ORDER BY created_at DESC
                    LIMIT 20
                ");
                $stmt->execute([$partner_id]);
                $admin_notes = $stmt->fetchAll();
            } catch (Exception $e) {}

            response(true, [
                'partner' => $partner,
                'order_stats' => $order_stats,
                'dispute_stats' => $dispute_stats,
                'issues_summary' => $issues_summary,
                'revenue' => $revenue,
                'products_count' => $products_count,
                'recent_orders' => $recent_orders,
                'admin_notes' => $admin_notes
            ], "Detalhes do parceiro");
        }

        // --- Listar problemas reportados pelo parceiro ---
        if ($action === 'issues') {
            $partner_id = (int)($_GET['partner_id'] ?? 0);
            if (!$partner_id) response(false, null, "partner_id obrigatorio", 400);

            // Verificar se parceiro existe
            $stmt = $db->prepare("SELECT partner_id, name FROM om_market_partners WHERE partner_id = ?");
            $stmt->execute([$partner_id]);
            if (!$stmt->fetch()) response(false, null, "Parceiro nao encontrado", 404);

            $status_filter = trim($_GET['status'] ?? '');
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;

            $where = "WHERE partner_id = ?";
            $params = [$partner_id];

            if ($status_filter) {
                $where .= " AND status = ?";
                $params[] = $status_filter;
            }

            $stmtCount = $db->prepare("SELECT COUNT(*) as total FROM om_partner_issues {$where}");
            $stmtCount->execute($params);
            $total = (int)$stmtCount->fetch()['total'];

            $params[] = $limit;
            $params[] = $offset;

            $stmt = $db->prepare("
                SELECT issue_id, partner_id, order_id, category, subcategory,
                       severity, description, status, resolution_note,
                       created_at, updated_at, resolved_at
                FROM om_partner_issues
                {$where}
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute($params);
            $issues = $stmt->fetchAll();

            response(true, [
                'issues' => $issues,
                'total' => $total,
                'page' => $page,
                'pages' => (int)ceil($total / $limit)
            ], "Problemas do parceiro listados");
        }

        response(false, null, "Acao GET invalida: {$action}", 400);
    }

    // =================== POST ===================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = getInput();
        $action = trim($input['action'] ?? $_GET['action'] ?? '');

        // --- Aprovar parceiro pendente ---
        if ($action === 'approve') {
            $partner_id = (int)($input['partner_id'] ?? 0);
            $note = trim($input['note'] ?? '');

            if (!$partner_id) response(false, null, "partner_id obrigatorio", 400);

            // Buscar parceiro atual
            $stmt = $db->prepare("SELECT partner_id, name, status FROM om_market_partners WHERE partner_id = ?");
            $stmt->execute([$partner_id]);
            $partner = $stmt->fetch();
            if (!$partner) response(false, null, "Parceiro nao encontrado", 404);

            $old_status = $partner['status'];
            // Status '0' = pendente; permitir aprovar parceiros pendentes ou rejeitados
            if ($old_status === '1') response(false, null, "Parceiro ja esta ativo", 400);

            $db->beginTransaction();

            $stmt = $db->prepare("
                UPDATE om_market_partners
                SET status = '1',
                    suspended_until = NULL,
                    suspension_reason = NULL,
                    updated_at = NOW()
                WHERE partner_id = ?
            ");
            $stmt->execute([$partner_id]);

            // Notificar parceiro
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, reference_type, reference_id, created_at)
                VALUES (?, 'partner', ?, ?, 'account_action', 'partner', ?, NOW())
            ")->execute([
                $partner_id,
                'Cadastro aprovado!',
                'Parabens! Seu cadastro foi aprovado. Voce ja pode comecar a receber pedidos na plataforma.',
                $partner_id
            ]);

            // Registrar nota se fornecida
            if ($note) {
                $db->prepare("
                    INSERT INTO om_admin_notes (entity_type, entity_id, admin_id, note, created_at)
                    VALUES ('partner', ?, ?, ?, NOW())
                ")->execute([$partner_id, $admin_id, "Aprovacao: {$note}"]);
            }

            $db->commit();

            // Registro de auditoria
            om_audit()->log(
                'partner_approve',
                'partner',
                $partner_id,
                ['status' => $old_status],
                ['status' => '1', 'note' => $note],
                "Parceiro '{$partner['name']}' aprovado pelo admin #{$admin_id}"
            );

            response(true, [
                'partner_id' => $partner_id,
                'action' => 'approve',
                'old_status' => $old_status,
                'new_status' => '1'
            ], "Parceiro aprovado com sucesso");
        }

        // --- Suspender parceiro ---
        if ($action === 'suspend') {
            $partner_id = (int)($input['partner_id'] ?? 0);
            $reason = trim($input['reason'] ?? '');
            $duration_days = max(1, (int)($input['duration_days'] ?? 7));

            if (!$partner_id) response(false, null, "partner_id obrigatorio", 400);
            if (!$reason) response(false, null, "reason obrigatorio", 400);

            // Buscar parceiro atual
            $stmt = $db->prepare("SELECT partner_id, name, status FROM om_market_partners WHERE partner_id = ?");
            $stmt->execute([$partner_id]);
            $partner = $stmt->fetch();
            if (!$partner) response(false, null, "Parceiro nao encontrado", 404);

            $old_status = $partner['status'];
            if ($old_status === '3') response(false, null, "Parceiro ja esta suspenso", 400);

            $suspended_until = date('Y-m-d H:i:s', strtotime("+{$duration_days} days"));

            $db->beginTransaction();

            // Suspender parceiro (status 3 = suspended)
            $stmt = $db->prepare("
                UPDATE om_market_partners
                SET status = '3',
                    suspended_until = ?,
                    suspension_reason = ?,
                    updated_at = NOW()
                WHERE partner_id = ?
            ");
            $stmt->execute([$suspended_until, $reason, $partner_id]);

            // Cancelar pedidos ativos do parceiro (pendentes e em preparo)
            $cancelled_orders = 0;
            try {
                $stmt = $db->prepare("
                    UPDATE om_market_orders
                    SET status = 'cancelled',
                        cancel_reason = 'Parceiro suspenso pela administracao',
                        updated_at = NOW()
                    WHERE partner_id = ? AND status IN ('pending', 'confirmed', 'preparing', 'ready')
                ");
                $stmt->execute([$partner_id]);
                $cancelled_orders = $stmt->rowCount();

                // Registrar timeline para cada pedido cancelado
                if ($cancelled_orders > 0) {
                    $stmtOrders = $db->prepare("
                        SELECT order_id FROM om_market_orders
                        WHERE partner_id = ? AND status = 'cancelled'
                        AND cancel_reason = 'Parceiro suspenso pela administracao'
                        AND updated_at >= NOW() - INTERVAL '1 minute'
                    ");
                    $stmtOrders->execute([$partner_id]);
                    $cancelledIds = $stmtOrders->fetchAll();

                    foreach ($cancelledIds as $co) {
                        try {
                            $db->prepare("
                                INSERT INTO om_order_timeline (order_id, status, description, actor_type, actor_id, created_at)
                                VALUES (?, 'cancelled', 'Cancelado automaticamente: parceiro suspenso', 'admin', ?, NOW())
                            ")->execute([$co['order_id'], $admin_id]);
                        } catch (Exception $e) {}
                    }
                }
            } catch (Exception $e) {
                error_log("[admin/partner-actions] Erro ao cancelar pedidos: " . $e->getMessage());
            }

            // Notificar parceiro
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, reference_type, reference_id, created_at)
                VALUES (?, 'partner', ?, ?, 'account_action', 'partner', ?, NOW())
            ")->execute([
                $partner_id,
                'Conta suspensa temporariamente',
                "Sua conta foi suspensa por {$duration_days} dias. Motivo: {$reason}. Pedidos ativos foram cancelados.",
                $partner_id
            ]);

            $db->commit();

            // Registro de auditoria
            om_audit()->log(
                'partner_suspend',
                'partner',
                $partner_id,
                ['status' => $old_status],
                ['status' => '3', 'reason' => $reason, 'duration_days' => $duration_days, 'suspended_until' => $suspended_until, 'cancelled_orders' => $cancelled_orders],
                "Parceiro '{$partner['name']}' suspenso por {$duration_days} dias. {$cancelled_orders} pedidos cancelados. Motivo: {$reason}"
            );

            response(true, [
                'partner_id' => $partner_id,
                'action' => 'suspend',
                'old_status' => $old_status,
                'new_status' => '3',
                'suspended_until' => $suspended_until,
                'duration_days' => $duration_days,
                'cancelled_orders' => $cancelled_orders
            ], "Parceiro suspenso. {$cancelled_orders} pedido(s) ativo(s) cancelado(s).");
        }

        // --- Banir parceiro permanentemente ---
        if ($action === 'ban') {
            $partner_id = (int)($input['partner_id'] ?? 0);
            $reason = trim($input['reason'] ?? '');

            if (!$partner_id) response(false, null, "partner_id obrigatorio", 400);
            if (!$reason) response(false, null, "reason obrigatorio", 400);

            // Buscar parceiro atual
            $stmt = $db->prepare("SELECT partner_id, name, status FROM om_market_partners WHERE partner_id = ?");
            $stmt->execute([$partner_id]);
            $partner = $stmt->fetch();
            if (!$partner) response(false, null, "Parceiro nao encontrado", 404);

            $old_status = $partner['status'];
            if ($old_status === '4') response(false, null, "Parceiro ja esta banido", 400);

            $db->beginTransaction();

            // Banir parceiro (status 4 = banned)
            $stmt = $db->prepare("
                UPDATE om_market_partners
                SET status = '4',
                    suspended_until = NULL,
                    suspension_reason = ?,
                    updated_at = NOW()
                WHERE partner_id = ?
            ");
            $stmt->execute([$reason, $partner_id]);

            // Cancelar todos os pedidos ativos
            $cancelled_orders = 0;
            try {
                $stmt = $db->prepare("
                    UPDATE om_market_orders
                    SET status = 'cancelled',
                        cancel_reason = 'Parceiro banido pela administracao',
                        updated_at = NOW()
                    WHERE partner_id = ? AND status IN ('pending', 'confirmed', 'preparing', 'ready')
                ");
                $stmt->execute([$partner_id]);
                $cancelled_orders = $stmt->rowCount();
            } catch (Exception $e) {
                error_log("[admin/partner-actions] Erro ao cancelar pedidos no ban: " . $e->getMessage());
            }

            // Notificar parceiro
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, reference_type, reference_id, created_at)
                VALUES (?, 'partner', ?, ?, 'account_action', 'partner', ?, NOW())
            ")->execute([
                $partner_id,
                'Conta banida permanentemente',
                "Sua conta foi permanentemente desativada. Motivo: {$reason}",
                $partner_id
            ]);

            $db->commit();

            // Registro de auditoria
            om_audit()->log(
                'partner_ban',
                'partner',
                $partner_id,
                ['status' => $old_status],
                ['status' => '4', 'reason' => $reason, 'cancelled_orders' => $cancelled_orders],
                "Parceiro '{$partner['name']}' banido permanentemente. {$cancelled_orders} pedidos cancelados. Motivo: {$reason}"
            );

            response(true, [
                'partner_id' => $partner_id,
                'action' => 'ban',
                'old_status' => $old_status,
                'new_status' => '4',
                'cancelled_orders' => $cancelled_orders
            ], "Parceiro banido permanentemente. {$cancelled_orders} pedido(s) cancelado(s).");
        }

        // --- Reativar parceiro ---
        if ($action === 'reactivate') {
            $partner_id = (int)($input['partner_id'] ?? 0);
            $note = trim($input['note'] ?? '');

            if (!$partner_id) response(false, null, "partner_id obrigatorio", 400);

            // Buscar parceiro atual
            $stmt = $db->prepare("SELECT partner_id, name, status FROM om_market_partners WHERE partner_id = ?");
            $stmt->execute([$partner_id]);
            $partner = $stmt->fetch();
            if (!$partner) response(false, null, "Parceiro nao encontrado", 404);

            $old_status = $partner['status'];
            if ($old_status === '1') response(false, null, "Parceiro ja esta ativo", 400);

            $db->beginTransaction();

            $stmt = $db->prepare("
                UPDATE om_market_partners
                SET status = '1',
                    suspended_until = NULL,
                    suspension_reason = NULL,
                    updated_at = NOW()
                WHERE partner_id = ?
            ");
            $stmt->execute([$partner_id]);

            // Notificar parceiro
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, reference_type, reference_id, created_at)
                VALUES (?, 'partner', ?, ?, 'account_action', 'partner', ?, NOW())
            ")->execute([
                $partner_id,
                'Conta reativada',
                'Sua conta foi reativada. Voce ja pode receber pedidos normalmente.',
                $partner_id
            ]);

            // Registrar nota se fornecida
            if ($note) {
                $db->prepare("
                    INSERT INTO om_admin_notes (entity_type, entity_id, admin_id, note, created_at)
                    VALUES ('partner', ?, ?, ?, NOW())
                ")->execute([$partner_id, $admin_id, "Reativacao: {$note}"]);
            }

            $db->commit();

            // Registro de auditoria
            om_audit()->log(
                'partner_reactivate',
                'partner',
                $partner_id,
                ['status' => $old_status],
                ['status' => '1', 'note' => $note],
                "Parceiro '{$partner['name']}' reativado. " . ($note ? "Nota: {$note}" : "")
            );

            response(true, [
                'partner_id' => $partner_id,
                'action' => 'reactivate',
                'old_status' => $old_status,
                'new_status' => '1'
            ], "Parceiro reativado com sucesso");
        }

        // --- Adicionar nota administrativa ---
        if ($action === 'add_note') {
            $partner_id = (int)($input['partner_id'] ?? 0);
            $note = trim($input['note'] ?? '');

            if (!$partner_id) response(false, null, "partner_id obrigatorio", 400);
            if (!$note) response(false, null, "note obrigatorio", 400);

            // Verificar se parceiro existe
            $stmt = $db->prepare("SELECT partner_id, name FROM om_market_partners WHERE partner_id = ?");
            $stmt->execute([$partner_id]);
            $partner = $stmt->fetch();
            if (!$partner) response(false, null, "Parceiro nao encontrado", 404);

            // Table om_admin_notes created via migration

            $stmt = $db->prepare("
                INSERT INTO om_admin_notes (entity_type, entity_id, admin_id, note, created_at)
                VALUES ('partner', ?, ?, ?, NOW())
            ");
            $stmt->execute([$partner_id, $admin_id, $note]);
            $note_id = (int)$db->lastInsertId();

            // Registro de auditoria
            om_audit()->log(
                'partner_add_note',
                'partner',
                $partner_id,
                null,
                ['note_id' => $note_id, 'note' => substr($note, 0, 200)],
                "Nota adicionada ao parceiro '{$partner['name']}'"
            );

            response(true, [
                'note_id' => $note_id,
                'partner_id' => $partner_id,
                'admin_id' => $admin_id,
                'note' => $note
            ], "Nota adicionada com sucesso");
        }

        response(false, null, "Acao POST invalida: {$action}", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[admin/partner-actions] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

// =================== FUNCOES AUXILIARES ===================

/**
 * Garante que as colunas auxiliares existem na tabela om_market_partners.
 * DDL idempotente - seguro para executar multiplas vezes.
 */
function ensurePartnerSchema(PDO $db): void {
    // Tables om_admin_notes and columns (suspended_until, suspension_reason, updated_at) created via migration
    return;
}
