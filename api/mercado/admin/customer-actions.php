<?php
/**
 * /api/mercado/admin/customer-actions.php
 *
 * Painel administrativo - Acoes sobre clientes (suspender, banir, reativar, notas, flags).
 *
 * GET  ?action=list&status=X&search=X&flagged=1&page=1    - Listar clientes com filtros
 * GET  ?action=detail&customer_id=X                        - Detalhes completos do cliente
 * GET  ?action=flags&customer_id=X                         - Listar flags de fraude do cliente
 *
 * POST action=suspend     { customer_id, reason, duration_days }  - Suspender cliente
 * POST action=ban         { customer_id, reason }                 - Banir cliente permanentemente
 * POST action=reactivate  { customer_id, note }                   - Reativar cliente
 * POST action=add_note    { customer_id, note }                   - Adicionar nota interna
 * POST action=resolve_flag { flag_id, resolution_note }           - Resolver flag de fraude
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

    // Garantir que as colunas e tabelas auxiliares existem
    ensureCustomerSchema($db);

    $action = trim($_GET['action'] ?? $_POST['action'] ?? '');
    if (!$action) response(false, null, "Parametro 'action' obrigatorio", 400);

    // =================== GET ===================
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        // --- Listar clientes com filtros ---
        if ($action === 'list') {
            $status = trim($_GET['status'] ?? '');
            $search = trim($_GET['search'] ?? '');
            $flagged = (int)($_GET['flagged'] ?? 0);
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;

            $where = ["1=1"];
            $params = [];

            // Filtro por status (active, suspended, banned)
            if ($status !== '') {
                $where[] = "c.status = ?";
                $params[] = $status;
            }

            // Busca por nome, email ou telefone
            if ($search !== '') {
                $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
                $where[] = "(c.name ILIKE ? OR c.email ILIKE ? OR c.phone ILIKE ?)";
                $s = "%{$escaped}%";
                $params = array_merge($params, [$s, $s, $s]);
            }

            // Filtro por clientes com flags de fraude ativas
            if ($flagged) {
                $where[] = "EXISTS (SELECT 1 FROM om_customer_fraud_flags f WHERE f.customer_id = c.customer_id AND f.resolved = FALSE)";
            }

            $where_sql = implode(' AND ', $where);

            // Contagem total
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_customers c WHERE {$where_sql}");
            $stmt->execute($params);
            $total = (int)$stmt->fetch()['total'];

            // Buscar clientes com estatisticas
            $stmt = $db->prepare("
                SELECT c.customer_id, c.name, c.email, c.phone, c.cpf,
                       c.is_active, c.status, c.suspended_until, c.suspension_reason,
                       c.created_at, c.last_login,
                       (SELECT COUNT(*) FROM om_market_orders o WHERE o.customer_id = c.customer_id) as orders_count,
                       (SELECT COALESCE(SUM(o2.total), 0) FROM om_market_orders o2
                        WHERE o2.customer_id = c.customer_id AND o2.status NOT IN ('cancelled','refunded')) as total_spent,
                       (SELECT COUNT(*) FROM om_customer_fraud_flags f
                        WHERE f.customer_id = c.customer_id AND f.resolved = FALSE) as active_flags
                FROM om_customers c
                WHERE {$where_sql}
                ORDER BY c.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            $clientes = $stmt->fetchAll();

            response(true, [
                'customers' => $clientes,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => (int)ceil($total / $limit)
                ]
            ], "Clientes listados");
        }

        // --- Detalhes completos do cliente ---
        if ($action === 'detail') {
            $customer_id = (int)($_GET['customer_id'] ?? 0);
            if (!$customer_id) response(false, null, "customer_id obrigatorio", 400);

            // Dados do cliente (explicit columns — excludes password_hash, password, salt, token)
            $stmt = $db->prepare("
                SELECT customer_id, name, email, phone, cpf, is_active, status,
                       suspended_until, suspension_reason, wallet_balance,
                       created_at, last_login, updated_at
                FROM om_customers WHERE customer_id = ?
            ");
            $stmt->execute([$customer_id]);
            $customer = $stmt->fetch();
            if (!$customer) response(false, null, "Cliente nao encontrado", 404);

            // Historico de pedidos (ultimos 50)
            $stmt = $db->prepare("
                SELECT o.order_id, o.status, o.total, o.delivery_fee, o.created_at,
                       o.delivered_at, p.name as partner_name
                FROM om_market_orders o
                LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
                WHERE o.customer_id = ?
                ORDER BY o.created_at DESC
                LIMIT 50
            ");
            $stmt->execute([$customer_id]);
            $orders = $stmt->fetchAll();

            // Estatisticas de pedidos
            $stmt = $db->prepare("
                SELECT COUNT(*) as total_orders,
                       COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','refunded') THEN total ELSE 0 END), 0) as total_spent,
                       COALESCE(AVG(CASE WHEN status NOT IN ('cancelled','refunded') THEN total END), 0) as avg_order,
                       MAX(created_at) as last_order_date,
                       SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
                       SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) as refunded_count
                FROM om_market_orders
                WHERE customer_id = ?
            ");
            $stmt->execute([$customer_id]);
            $order_stats = $stmt->fetch();

            // Historico de disputas
            $disputes = [];
            try {
                $stmt = $db->prepare("
                    SELECT dispute_id, order_id, category, subcategory, severity, status,
                           requested_amount, approved_amount, credit_amount,
                           auto_resolved, is_suspicious, created_at, resolved_at
                    FROM om_order_disputes
                    WHERE customer_id = ?
                    ORDER BY created_at DESC
                    LIMIT 30
                ");
                $stmt->execute([$customer_id]);
                $disputes = $stmt->fetchAll();
            } catch (Exception $e) {
                // Tabela pode nao existir ainda
            }

            // Historico de reembolsos
            $refunds = [];
            try {
                $stmt = $db->prepare("
                    SELECT id, order_id, amount, reason, status, created_at, reviewed_at
                    FROM om_market_refunds
                    WHERE customer_id = ?
                    ORDER BY created_at DESC
                    LIMIT 30
                ");
                $stmt->execute([$customer_id]);
                $refunds = $stmt->fetchAll();
            } catch (Exception $e) {
                // Tabela pode nao existir ainda
            }

            // Saldo da carteira
            $wallet_balance = 0;
            try {
                $stmt = $db->prepare("SELECT COALESCE(balance, 0) as balance FROM om_customer_wallet WHERE customer_id = ?");
                $stmt->execute([$customer_id]);
                $row = $stmt->fetch();
                $wallet_balance = (float)($row['balance'] ?? 0);
            } catch (Exception $e) {
                // Tabela pode nao existir
            }

            // Flags de fraude ativas
            $fraud_flags = [];
            try {
                $stmt = $db->prepare("
                    SELECT id, customer_id, flag_type, description, severity, source,
                           reference_type, reference_id, resolved, resolved_by,
                           resolution_note, resolved_at, created_at
                    FROM om_customer_fraud_flags
                    WHERE customer_id = ?
                    ORDER BY created_at DESC
                    LIMIT 20
                ");
                $stmt->execute([$customer_id]);
                $fraud_flags = $stmt->fetchAll();
            } catch (Exception $e) {
                // Tabela pode nao existir
            }

            // Notas administrativas
            $admin_notes = [];
            try {
                $stmt = $db->prepare("
                    SELECT id, admin_id, note, created_at
                    FROM om_admin_notes
                    WHERE entity_type = 'customer' AND entity_id = ?
                    ORDER BY created_at DESC
                    LIMIT 20
                ");
                $stmt->execute([$customer_id]);
                $admin_notes = $stmt->fetchAll();
            } catch (Exception $e) {
                // Tabela pode nao existir
            }

            response(true, [
                'customer' => $customer,
                'orders' => $orders,
                'order_stats' => $order_stats,
                'disputes' => $disputes,
                'refunds' => $refunds,
                'wallet_balance' => $wallet_balance,
                'fraud_flags' => $fraud_flags,
                'admin_notes' => $admin_notes
            ], "Detalhes do cliente");
        }

        // --- Listar flags de fraude ---
        if ($action === 'flags') {
            $customer_id = (int)($_GET['customer_id'] ?? 0);
            if (!$customer_id) response(false, null, "customer_id obrigatorio", 400);

            // Verificar se cliente existe
            $stmt = $db->prepare("SELECT customer_id, name FROM om_customers WHERE customer_id = ?");
            $stmt->execute([$customer_id]);
            if (!$stmt->fetch()) response(false, null, "Cliente nao encontrado", 404);

            $stmt = $db->prepare("
                SELECT f.id, f.customer_id, f.flag_type, f.description, f.severity, f.source,
                       f.reference_type, f.reference_id, f.resolved, f.resolved_by,
                       f.resolution_note, f.resolved_at, f.created_at,
                       CASE WHEN f.resolved THEN 'resolved' ELSE 'active' END as flag_status
                FROM om_customer_fraud_flags f
                WHERE f.customer_id = ?
                ORDER BY f.resolved ASC, f.created_at DESC
            ");
            $stmt->execute([$customer_id]);
            $flags = $stmt->fetchAll();

            response(true, [
                'flags' => $flags,
                'total' => count($flags),
                'active_count' => count(array_filter($flags, fn($f) => !$f['resolved']))
            ], "Flags de fraude listadas");
        }

        response(false, null, "Acao GET invalida: {$action}", 400);
    }

    // =================== POST ===================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = getInput();
        $action = trim($input['action'] ?? $_GET['action'] ?? '');

        // --- Suspender cliente ---
        if ($action === 'suspend') {
            $customer_id = (int)($input['customer_id'] ?? 0);
            $reason = strip_tags(trim($input['reason'] ?? ''));
            $duration_days = max(1, (int)($input['duration_days'] ?? 7));

            if (!$customer_id) response(false, null, "customer_id obrigatorio", 400);
            if (!$reason) response(false, null, "reason obrigatorio", 400);

            // Buscar cliente atual
            $stmt = $db->prepare("SELECT customer_id, name, status FROM om_customers WHERE customer_id = ?");
            $stmt->execute([$customer_id]);
            $customer = $stmt->fetch();
            if (!$customer) response(false, null, "Cliente nao encontrado", 404);

            $old_status = $customer['status'] ?? 'active';
            if ($old_status === 'suspended') response(false, null, "Cliente ja esta suspenso", 400);

            $suspended_until = date('Y-m-d H:i:s', strtotime("+{$duration_days} days"));

            $db->beginTransaction();

            $stmt = $db->prepare("
                UPDATE om_customers
                SET status = 'suspended',
                    suspended_until = ?,
                    suspension_reason = ?,
                    is_active = '0',
                    updated_at = NOW()
                WHERE customer_id = ?
            ");
            $stmt->execute([$suspended_until, $reason, $customer_id]);

            // Notificar cliente
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, reference_type, reference_id, created_at)
                VALUES (?, 'customer', ?, ?, 'account_action', 'customer', ?, NOW())
            ")->execute([
                $customer_id,
                'Conta suspensa temporariamente',
                "Sua conta foi suspensa por {$duration_days} dias. Motivo: {$reason}",
                $customer_id
            ]);

            $db->commit();

            // Registro de auditoria
            om_audit()->log(
                'customer_suspend',
                'customer',
                $customer_id,
                ['status' => $old_status],
                ['status' => 'suspended', 'reason' => $reason, 'duration_days' => $duration_days, 'suspended_until' => $suspended_until],
                "Cliente '{$customer['name']}' suspenso por {$duration_days} dias. Motivo: {$reason}"
            );

            response(true, [
                'customer_id' => $customer_id,
                'action' => 'suspend',
                'old_status' => $old_status,
                'new_status' => 'suspended',
                'suspended_until' => $suspended_until,
                'duration_days' => $duration_days
            ], "Cliente suspenso com sucesso");
        }

        // --- Banir cliente permanentemente ---
        if ($action === 'ban') {
            $customer_id = (int)($input['customer_id'] ?? 0);
            $reason = strip_tags(trim($input['reason'] ?? ''));

            if (!$customer_id) response(false, null, "customer_id obrigatorio", 400);
            if (!$reason) response(false, null, "reason obrigatorio", 400);

            // Buscar cliente atual
            $stmt = $db->prepare("SELECT customer_id, name, status FROM om_customers WHERE customer_id = ?");
            $stmt->execute([$customer_id]);
            $customer = $stmt->fetch();
            if (!$customer) response(false, null, "Cliente nao encontrado", 404);

            $old_status = $customer['status'] ?? 'active';
            if ($old_status === 'banned') response(false, null, "Cliente ja esta banido", 400);

            $db->beginTransaction();

            $stmt = $db->prepare("
                UPDATE om_customers
                SET status = 'banned',
                    suspended_until = NULL,
                    suspension_reason = ?,
                    is_active = '0',
                    updated_at = NOW()
                WHERE customer_id = ?
            ");
            $stmt->execute([$reason, $customer_id]);

            // Notificar cliente
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, reference_type, reference_id, created_at)
                VALUES (?, 'customer', ?, ?, 'account_action', 'customer', ?, NOW())
            ")->execute([
                $customer_id,
                'Conta banida permanentemente',
                "Sua conta foi permanentemente desativada. Motivo: {$reason}",
                $customer_id
            ]);

            $db->commit();

            // Registro de auditoria
            om_audit()->log(
                'customer_ban',
                'customer',
                $customer_id,
                ['status' => $old_status],
                ['status' => 'banned', 'reason' => $reason],
                "Cliente '{$customer['name']}' banido permanentemente. Motivo: {$reason}"
            );

            response(true, [
                'customer_id' => $customer_id,
                'action' => 'ban',
                'old_status' => $old_status,
                'new_status' => 'banned'
            ], "Cliente banido permanentemente");
        }

        // --- Reativar cliente ---
        if ($action === 'reactivate') {
            $customer_id = (int)($input['customer_id'] ?? 0);
            $note = strip_tags(trim($input['note'] ?? ''));

            if (!$customer_id) response(false, null, "customer_id obrigatorio", 400);

            // Buscar cliente atual
            $stmt = $db->prepare("SELECT customer_id, name, status FROM om_customers WHERE customer_id = ?");
            $stmt->execute([$customer_id]);
            $customer = $stmt->fetch();
            if (!$customer) response(false, null, "Cliente nao encontrado", 404);

            $old_status = $customer['status'] ?? 'active';
            if ($old_status === 'active') response(false, null, "Cliente ja esta ativo", 400);

            $db->beginTransaction();

            $stmt = $db->prepare("
                UPDATE om_customers
                SET status = 'active',
                    suspended_until = NULL,
                    suspension_reason = NULL,
                    is_active = '1',
                    updated_at = NOW()
                WHERE customer_id = ?
            ");
            $stmt->execute([$customer_id]);

            // Notificar cliente
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, reference_type, reference_id, created_at)
                VALUES (?, 'customer', ?, ?, 'account_action', 'customer', ?, NOW())
            ")->execute([
                $customer_id,
                'Conta reativada',
                'Sua conta foi reativada. Voce ja pode usar a plataforma normalmente.',
                $customer_id
            ]);

            $db->commit();

            // Registro de auditoria
            om_audit()->log(
                'customer_reactivate',
                'customer',
                $customer_id,
                ['status' => $old_status],
                ['status' => 'active', 'note' => $note],
                "Cliente '{$customer['name']}' reativado. " . ($note ? "Nota: {$note}" : "")
            );

            response(true, [
                'customer_id' => $customer_id,
                'action' => 'reactivate',
                'old_status' => $old_status,
                'new_status' => 'active'
            ], "Cliente reativado com sucesso");
        }

        // --- Adicionar nota administrativa ---
        if ($action === 'add_note') {
            $customer_id = (int)($input['customer_id'] ?? 0);
            $note = strip_tags(trim($input['note'] ?? ''));

            if (!$customer_id) response(false, null, "customer_id obrigatorio", 400);
            if (!$note) response(false, null, "note obrigatorio", 400);

            // Verificar se cliente existe
            $stmt = $db->prepare("SELECT customer_id, name FROM om_customers WHERE customer_id = ?");
            $stmt->execute([$customer_id]);
            $customer = $stmt->fetch();
            if (!$customer) response(false, null, "Cliente nao encontrado", 404);

            $stmt = $db->prepare("
                INSERT INTO om_admin_notes (entity_type, entity_id, admin_id, note, created_at)
                VALUES ('customer', ?, ?, ?, NOW())
            ");
            $stmt->execute([$customer_id, $admin_id, $note]);
            $note_id = (int)$db->lastInsertId();

            // Registro de auditoria
            om_audit()->log(
                'customer_add_note',
                'customer',
                $customer_id,
                null,
                ['note_id' => $note_id, 'note' => substr($note, 0, 200)],
                "Nota adicionada ao cliente '{$customer['name']}'"
            );

            response(true, [
                'note_id' => $note_id,
                'customer_id' => $customer_id,
                'admin_id' => $admin_id,
                'note' => $note
            ], "Nota adicionada com sucesso");
        }

        // --- Resolver flag de fraude ---
        if ($action === 'resolve_flag') {
            $flag_id = (int)($input['flag_id'] ?? 0);
            $resolution_note = strip_tags(trim($input['resolution_note'] ?? ''));

            if (!$flag_id) response(false, null, "flag_id obrigatorio", 400);
            if (!$resolution_note) response(false, null, "resolution_note obrigatorio", 400);

            // Buscar flag
            $stmt = $db->prepare("SELECT id, customer_id, flag_type, description, severity, resolved FROM om_customer_fraud_flags WHERE id = ?");
            $stmt->execute([$flag_id]);
            $flag = $stmt->fetch();
            if (!$flag) response(false, null, "Flag nao encontrada", 404);
            if ($flag['resolved']) response(false, null, "Flag ja foi resolvida", 400);

            $stmt = $db->prepare("
                UPDATE om_customer_fraud_flags
                SET resolved = TRUE,
                    resolved_by = ?,
                    resolution_note = ?,
                    resolved_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$admin_id, $resolution_note, $flag_id]);

            // Registro de auditoria
            om_audit()->log(
                'customer_resolve_flag',
                'customer',
                (int)$flag['customer_id'],
                ['flag_id' => $flag_id, 'resolved' => false],
                ['flag_id' => $flag_id, 'resolved' => true, 'resolution_note' => $resolution_note],
                "Flag #{$flag_id} resolvida para cliente #{$flag['customer_id']}. Tipo: {$flag['flag_type']}"
            );

            response(true, [
                'flag_id' => $flag_id,
                'customer_id' => (int)$flag['customer_id'],
                'resolved' => true
            ], "Flag resolvida com sucesso");
        }

        response(false, null, "Acao POST invalida: {$action}", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[admin/customer-actions] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

// =================== FUNCOES AUXILIARES ===================

/**
 * Garante que as colunas e tabelas necessarias existem no banco.
 * Executado uma vez por requisicao; DDL idempotente.
 */
function ensureCustomerSchema(PDO $db): void {
    // Tables om_admin_notes, om_customer_fraud_flags and columns (status, suspended_until, suspension_reason) created via migration
    return;
}
