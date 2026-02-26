<?php
/**
 * GET /api/mercado/partner/busy-mode.php - Status do modo ocupado
 * POST /api/mercado/partner/busy-mode.php - Ativar/desativar modo ocupado
 *
 * Body POST: { "action": "activate|deactivate", "duration_minutes": 30, "prep_time_increase": 15 }
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
        $stmt = $db->prepare("
            SELECT busy_mode, busy_mode_until, current_prep_time, default_prep_time, max_orders_per_hour, auto_accept_orders
            FROM om_market_partners WHERE partner_id = ?
        ");
        $stmt->execute([$partnerId]);
        $partner = $stmt->fetch(PDO::FETCH_ASSOC);

        // Check current hour orders
        $stmt = $db->prepare("
            SELECT COUNT(*) as count FROM om_market_orders
            WHERE partner_id = ? AND created_at >= NOW() - INTERVAL '1 hours'
        ");
        $stmt->execute([$partnerId]);
        $ordersThisHour = $stmt->fetch()['count'];

        // Auto-disable busy mode if expired
        if ($partner['busy_mode'] && $partner['busy_mode_until'] && strtotime($partner['busy_mode_until']) < time()) {
            $db->prepare("UPDATE om_market_partners SET busy_mode = 0, busy_mode_until = NULL, current_prep_time = NULL WHERE partner_id = ?")->execute([$partnerId]);
            $partner['busy_mode'] = 0;
            $partner['busy_mode_until'] = null;
            $partner['current_prep_time'] = null;
        }

        response(true, [
            'busy_mode' => (bool)$partner['busy_mode'],
            'busy_mode_until' => $partner['busy_mode_until'],
            'current_prep_time' => $partner['current_prep_time'] ?? $partner['default_prep_time'],
            'default_prep_time' => $partner['default_prep_time'] ?? 30,
            'max_orders_per_hour' => $partner['max_orders_per_hour'] ?? 50,
            'orders_this_hour' => (int)$ordersThisHour,
            'auto_accept_orders' => (bool)$partner['auto_accept_orders'],
            'capacity_percentage' => min(100, round(($ordersThisHour / max(1, $partner['max_orders_per_hour'])) * 100)),
        ]);
    }

    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? '';

        if ($action === 'activate') {
            $duration = intval($input['duration_minutes'] ?? 30);
            $prepIncrease = intval($input['prep_time_increase'] ?? 15);

            $busyUntil = $duration > 0 ? date('Y-m-d H:i:s', strtotime("+{$duration} minutes")) : null;

            $stmt = $db->prepare("
                UPDATE om_market_partners
                SET busy_mode = 1,
                    busy_mode_until = ?,
                    current_prep_time = COALESCE(default_prep_time, 30) + ?
                WHERE partner_id = ?
            ");
            $stmt->execute([$busyUntil, $prepIncrease, $partnerId]);

            response(true, [
                'busy_mode' => true,
                'busy_mode_until' => $busyUntil,
                'prep_time_increase' => $prepIncrease,
            ], "Modo ocupado ativado!");

        } elseif ($action === 'deactivate') {
            $stmt = $db->prepare("
                UPDATE om_market_partners
                SET busy_mode = 0, busy_mode_until = NULL, current_prep_time = NULL
                WHERE partner_id = ?
            ");
            $stmt->execute([$partnerId]);

            response(true, ['busy_mode' => false], "Modo ocupado desativado!");

        } elseif ($action === 'update_settings') {
            $defaultPrep = intval($input['default_prep_time'] ?? 30);
            $maxOrders = intval($input['max_orders_per_hour'] ?? 50);
            $autoAccept = isset($input['auto_accept_orders']) ? (int)$input['auto_accept_orders'] : null;

            $sets = ["default_prep_time = ?", "max_orders_per_hour = ?"];
            $params = [$defaultPrep, $maxOrders];

            if ($autoAccept !== null) {
                $sets[] = "auto_accept_orders = ?";
                $params[] = $autoAccept;
            }

            $params[] = $partnerId;
            $stmt = $db->prepare("UPDATE om_market_partners SET " . implode(', ', $sets) . " WHERE partner_id = ?");
            $stmt->execute($params);

            response(true, null, "Configuracoes atualizadas!");

        } else {
            response(false, null, "Acao invalida", 400);
        }
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/busy-mode] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
