<?php
/**
 * GET/POST/DELETE /api/mercado/admin/subscriptions.php
 * Gestao administrativa de assinaturas SuperBora Club
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();

    $method = $_SERVER['REQUEST_METHOD'];

    // Check if subscription tables exist
    $tableCheck = $db->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'om_subscriptions')");
    $tablesExist = $tableCheck->fetchColumn();

    if (!$tablesExist) {
        response(true, [
            'subscriptions' => [],
            'plans' => [],
            'stats' => ['subscriptions' => ['total_subscriptions' => 0, 'active' => 0], 'revenue' => ['total_revenue' => 0]],
            'message' => 'Modulo de assinaturas ainda nao configurado',
            'pagination' => ['page' => 1, 'limit' => 20, 'total' => 0, 'pages' => 0]
        ]);
    }

    // GET - Listar assinaturas ou planos
    if ($method === 'GET') {
        $view = $_GET['view'] ?? 'subscriptions';

        if ($view === 'plans') {
            // Listar planos — use only columns that exist in om_subscription_plans
            $stmt = $db->query("
                SELECT p.*,
                    (SELECT COUNT(*) FROM om_subscriptions WHERE plan_id = p.id AND status = 'active') as active_count,
                    (SELECT COUNT(*) FROM om_subscriptions WHERE plan_id = p.id) as total_count
                FROM om_subscription_plans p
                ORDER BY p.price ASC
            ");
            $plans = $stmt->fetchAll();

            response(true, ['plans' => $plans]);
        }

        if ($view === 'stats') {
            // Estatisticas gerais
            $stats = [];

            $stmt = $db->query("
                SELECT
                    COUNT(*) as total_subscriptions,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                    SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired
                FROM om_subscriptions
            ");
            $stats['subscriptions'] = $stmt->fetch();

            // om_subscription_payments table may not exist — return zero stats if missing
            $paymentsExist = $db->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'om_subscription_payments')")->fetchColumn();
            if ($paymentsExist) {
                $stmt = $db->query("
                    SELECT
                        COUNT(*) as total_payments,
                        SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as total_revenue,
                        SUM(CASE WHEN status = 'paid' AND EXTRACT(MONTH FROM paid_at) = EXTRACT(MONTH FROM NOW()) THEN amount ELSE 0 END) as month_revenue
                    FROM om_subscription_payments
                ");
                $stats['revenue'] = $stmt->fetch();
            } else {
                $stats['revenue'] = ['total_payments' => 0, 'total_revenue' => 0, 'month_revenue' => 0];
            }

            response(true, ['stats' => $stats]);
        }

        // Listar assinaturas
        $status = $_GET['status'] ?? null;
        $planId = (int)($_GET['plan_id'] ?? 0);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(10, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = "1=1";
        $params = [];

        if ($status) {
            $where .= " AND s.status = ?";
            $params[] = $status;
        }

        if ($planId) {
            $where .= " AND s.plan_id = ?";
            $params[] = $planId;
        }

        // Total
        $stmt = $db->prepare("SELECT COUNT(*) FROM om_subscriptions s WHERE $where");
        $stmt->execute($params);
        $total = $stmt->fetchColumn();

        // Assinaturas — om_market_customers uses name/phone (not nome/celular)
        $stmt = $db->prepare("
            SELECT s.*, p.name as plan_name, p.price as plan_price,
                   c.name as customer_name, c.email as customer_email, c.phone as customer_phone
            FROM om_subscriptions s
            INNER JOIN om_subscription_plans p ON s.plan_id = p.id
            INNER JOIN om_market_customers c ON s.customer_id = c.customer_id
            WHERE $where
            ORDER BY s.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$limit, $offset]));
        $subscriptions = $stmt->fetchAll();

        response(true, [
            'subscriptions' => $subscriptions,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int)$total,
                'pages' => ceil($total / $limit)
            ]
        ]);
    }

    // POST - Criar/editar plano ou ajustar assinatura
    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? 'update_plan';

        if ($action === 'create_plan' || $action === 'update_plan') {
            $planId = (int)($input['plan_id'] ?? 0);
            $name = trim($input['name'] ?? '');
            $slug = trim($input['slug'] ?? '');
            $price = (float)($input['price'] ?? 0);
            $freeDelivery = (int)($input['free_delivery'] ?? 0);
            $prioritySupport = (int)($input['priority_support'] ?? 0);

            if (empty($name) || empty($slug)) {
                response(false, null, "Nome e slug obrigatorios", 400);
            }

            if ($planId) {
                // Atualizar plano — only columns that exist: name, slug, price, free_delivery, priority_support, status
                $stmt = $db->prepare("
                    UPDATE om_subscription_plans SET
                        name = ?, slug = ?, price = ?,
                        free_delivery = ?, priority_support = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $name, $slug, $price,
                    $freeDelivery, $prioritySupport, $planId
                ]);

                response(true, ['message' => 'Plano atualizado!']);
            }

            // Criar plano — only existing columns
            $stmt = $db->prepare("
                INSERT INTO om_subscription_plans
                (name, slug, price, free_delivery, priority_support)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $name, $slug, $price,
                $freeDelivery, $prioritySupport
            ]);

            response(true, [
                'plan_id' => (int)$db->lastInsertId(),
                'message' => 'Plano criado!'
            ]);
        }

        if ($action === 'cancel_subscription') {
            $subscriptionId = (int)($input['subscription_id'] ?? 0);

            if (!$subscriptionId) {
                response(false, null, "ID da assinatura obrigatorio", 400);
            }

            // om_subscriptions has no cancelled_at or cancel_reason — update only status
            $stmt = $db->prepare("
                UPDATE om_subscriptions
                SET status = 'cancelled'
                WHERE id = ?
            ");
            $stmt->execute([$subscriptionId]);

            response(true, ['message' => 'Assinatura cancelada']);
        }

        if ($action === 'extend_subscription') {
            $subscriptionId = (int)($input['subscription_id'] ?? 0);
            $days = (int)($input['days'] ?? 30);

            if (!$subscriptionId) {
                response(false, null, "ID da assinatura obrigatorio", 400);
            }

            // om_subscriptions uses expires_at (not ends_at)
            $stmt = $db->prepare("
                UPDATE om_subscriptions
                SET expires_at = COALESCE(expires_at, NOW()) + (? || ' days')::INTERVAL,
                    status = 'active'
                WHERE id = ?
            ");
            $stmt->execute([$days, $subscriptionId]);

            response(true, ['message' => "Assinatura estendida em $days dias"]);
        }

        response(false, null, "Acao invalida", 400);
    }

    // DELETE - Desativar plano
    if ($method === 'DELETE') {
        $planId = (int)($_GET['plan_id'] ?? 0);

        if (!$planId) {
            response(false, null, "ID do plano obrigatorio", 400);
        }

        $stmt = $db->prepare("UPDATE om_subscription_plans SET status = 'inactive' WHERE id = ?");
        $stmt->execute([$planId]);

        response(true, ['message' => 'Plano desativado']);
    }

} catch (Exception $e) {
    error_log("[admin/subscriptions] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar assinaturas", 500);
}
