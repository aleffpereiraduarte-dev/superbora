<?php
/**
 * GET /api/mercado/partner/loyalty.php - Configuracoes e dados de fidelidade
 * POST /api/mercado/partner/loyalty.php - Configurar programa de fidelidade
 */
require_once __DIR__ . "/../config/database.php";
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

    $partnerId = $payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        // Get partner loyalty settings
        $stmt = $db->prepare("
            SELECT loyalty_enabled, loyalty_points_per_real, loyalty_min_redeem
            FROM om_market_partners WHERE partner_id = ?
        ");
        $stmt->execute([$partnerId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get rewards
        $stmt = $db->prepare("
            SELECT r.*, p.name as product_name
            FROM om_loyalty_rewards r
            LEFT JOIN om_market_products p ON p.product_id = r.product_id
            WHERE r.partner_id = ? AND r.status = '1'
            ORDER BY r.points_required ASC
        ");
        $stmt->execute([$partnerId]);
        $rewards = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get loyalty members stats (customers who ordered from this partner)
        $stmt = $db->prepare("
            SELECT
                COUNT(DISTINCT o.customer_id) as total_members,
                COUNT(o.order_id) as total_loyalty_orders,
                SUM(o.total) as total_loyalty_revenue
            FROM om_market_orders o
            WHERE o.partner_id = ? AND o.status NOT IN ('cancelado','cancelled')
        ");
        $stmt->execute([$partnerId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get average orders per customer
        $avgOrders = $stats['total_members'] > 0 ? round($stats['total_loyalty_orders'] / $stats['total_members'], 1) : 0;

        // Get top loyal customers (based on orders from this partner)
        $stmt = $db->prepare("
            SELECT
                o.customer_id,
                MAX(COALESCE(u.name, o.customer_name)) as customer_name,
                MAX(COALESCE(u.email, o.customer_email)) as customer_email,
                COUNT(o.order_id) as total_orders,
                SUM(o.total) as total_spent
            FROM om_market_orders o
            LEFT JOIN om_customers u ON u.customer_id = o.customer_id
            WHERE o.partner_id = ? AND o.status NOT IN ('cancelado','cancelled')
            GROUP BY o.customer_id
            ORDER BY total_spent DESC
            LIMIT 10
        ");
        $stmt->execute([$partnerId]);
        $topCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        response(true, [
            'settings' => [
                'enabled' => (bool)$settings['loyalty_enabled'],
                'points_per_real' => (float)$settings['loyalty_points_per_real'],
                'min_redeem' => (int)$settings['loyalty_min_redeem'],
            ],
            'rewards' => $rewards,
            'stats' => [
                'total_members' => (int)$stats['total_members'],
                'total_points_outstanding' => 0,
                'total_loyalty_orders' => (int)$stats['total_loyalty_orders'],
                'total_loyalty_revenue' => (float)$stats['total_loyalty_revenue'],
                'avg_orders_per_member' => $avgOrders,
            ],
            'top_customers' => $topCustomers,
        ]);
    }

    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? 'update_settings';

        if ($action === 'update_settings') {
            $enabled = isset($input['enabled']) ? (int)$input['enabled'] : null;
            $pointsPerReal = isset($input['points_per_real']) ? (float)$input['points_per_real'] : null;
            $minRedeem = isset($input['min_redeem']) ? (int)$input['min_redeem'] : null;

            $sets = [];
            $params = [];

            if ($enabled !== null) {
                $sets[] = "loyalty_enabled = ?";
                $params[] = $enabled;
            }
            if ($pointsPerReal !== null) {
                $sets[] = "loyalty_points_per_real = ?";
                $params[] = $pointsPerReal;
            }
            if ($minRedeem !== null) {
                $sets[] = "loyalty_min_redeem = ?";
                $params[] = $minRedeem;
            }

            if (!empty($sets)) {
                $params[] = $partnerId;
                $stmt = $db->prepare("UPDATE om_market_partners SET " . implode(', ', $sets) . " WHERE partner_id = ?");
                $stmt->execute($params);
            }

            response(true, null, "Configuracoes atualizadas!");
        }

        if ($action === 'add_reward') {
            $name = trim($input['name'] ?? '');
            $description = trim($input['description'] ?? '');
            $pointsRequired = intval($input['points_required'] ?? 100);
            $rewardType = $input['reward_type'] ?? 'discount_percent';
            $rewardValue = floatval($input['reward_value'] ?? 10);
            $productId = !empty($input['product_id']) ? intval($input['product_id']) : null;

            if (empty($name) || $pointsRequired <= 0) {
                response(false, null, "Nome e pontos sao obrigatorios", 400);
            }

            $stmt = $db->prepare("
                INSERT INTO om_loyalty_rewards (partner_id, name, description, points_required, reward_type, reward_value, product_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$partnerId, $name, $description, $pointsRequired, $rewardType, $rewardValue, $productId]);

            response(true, ['id' => $db->lastInsertId()], "Recompensa criada!");
        }

        if ($action === 'update_reward') {
            $rewardId = intval($input['reward_id'] ?? 0);
            if (!$rewardId) response(false, null, "ID obrigatorio", 400);

            // Validate status field against allowed values
            if (isset($input['status']) && !in_array((string)$input['status'], ['0', '1'], true)) {
                response(false, null, "Status invalido. Use '0' (inativo) ou '1' (ativo)", 400);
            }

            // Validate reward_type if provided
            $validRewardTypes = ['discount_percent', 'discount_fixed', 'free_product', 'free_delivery'];
            if (isset($input['reward_type']) && !in_array($input['reward_type'], $validRewardTypes, true)) {
                response(false, null, "Tipo de recompensa invalido", 400);
            }

            $sets = [];
            $params = [];
            $fields = ['name', 'description', 'points_required', 'reward_type', 'reward_value', 'product_id', 'status'];

            foreach ($fields as $field) {
                if (isset($input[$field])) {
                    $sets[] = "$field = ?";
                    $params[] = $input[$field];
                }
            }

            if (!empty($sets)) {
                $params[] = $rewardId;
                $params[] = $partnerId;
                $stmt = $db->prepare("UPDATE om_loyalty_rewards SET " . implode(', ', $sets) . " WHERE id = ? AND partner_id = ?");
                $stmt->execute($params);
            }

            response(true, null, "Recompensa atualizada!");
        }

        if ($action === 'delete_reward') {
            $rewardId = intval($input['reward_id'] ?? 0);
            if (!$rewardId) response(false, null, "ID obrigatorio", 400);

            $stmt = $db->prepare("UPDATE om_loyalty_rewards SET status = '0' WHERE id = ? AND partner_id = ?");
            $stmt->execute([$rewardId, $partnerId]);

            response(true, null, "Recompensa removida!");
        }

        response(false, null, "Acao invalida", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/loyalty] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
