<?php
/**
 * GET/POST /api/mercado/partner/express-config.php
 * Configuracao de entrega expressa do parceiro
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

    $partnerId = (int)$payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    // GET - Obter configuracao
    if ($method === 'GET') {
        $stmt = $db->prepare("
            SELECT * FROM om_express_delivery_config
            WHERE partner_id = ?
        ");
        $stmt->execute([$partnerId]);
        $config = $stmt->fetch();

        // Se nao tem config especifica, pegar a global
        if (!$config) {
            $stmt = $db->prepare("
                SELECT * FROM om_express_delivery_config
                WHERE partner_id IS NULL
            ");
            $stmt->execute();
            $config = $stmt->fetch();
            $config['is_custom'] = false;
        } else {
            $config['is_custom'] = true;
        }

        response(true, [
            'config' => [
                'enabled' => (bool)$config['enabled'],
                'is_custom' => $config['is_custom'] ?? false,
                'express_fee' => (float)$config['express_fee'],
                'express_time_minutes' => (int)$config['express_time_minutes'],
                'normal_time_minutes' => (int)$config['normal_time_minutes'],
                'max_distance_km' => (float)$config['max_distance_km'],
                'available_from' => $config['available_from'],
                'available_until' => $config['available_until'],
                'max_orders_per_hour' => (int)$config['max_orders_per_hour'],
            ]
        ]);
    }

    // POST - Atualizar configuracao
    if ($method === 'POST') {
        $input = getInput();

        $enabled = (bool)($input['enabled'] ?? true);
        $expressFee = (float)($input['express_fee'] ?? 9.90);
        $expressTime = (int)($input['express_time_minutes'] ?? 20);
        $maxDistance = (float)($input['max_distance_km'] ?? 5);
        $availableFrom = $input['available_from'] ?? '10:00:00';
        $availableUntil = $input['available_until'] ?? '22:00:00';
        $maxOrdersPerHour = (int)($input['max_orders_per_hour'] ?? 10);

        // Validacoes
        if ($expressFee < 0 || $expressFee > 50) {
            response(false, null, "Taxa expressa deve ser entre R$ 0 e R$ 50", 400);
        }

        if ($expressTime < 10 || $expressTime > 60) {
            response(false, null, "Tempo expresso deve ser entre 10 e 60 minutos", 400);
        }

        // Upsert
        $stmt = $db->prepare("
            INSERT INTO om_express_delivery_config
            (partner_id, enabled, express_fee, express_time_minutes, max_distance_km,
             available_from, available_until, max_orders_per_hour)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (partner_id) DO UPDATE SET
                enabled = EXCLUDED.enabled,
                express_fee = EXCLUDED.express_fee,
                express_time_minutes = EXCLUDED.express_time_minutes,
                max_distance_km = EXCLUDED.max_distance_km,
                available_from = EXCLUDED.available_from,
                available_until = EXCLUDED.available_until,
                max_orders_per_hour = EXCLUDED.max_orders_per_hour,
                updated_at = NOW()
        ");
        $stmt->execute([
            $partnerId,
            $enabled ? 1 : 0,
            $expressFee,
            $expressTime,
            $maxDistance,
            $availableFrom,
            $availableUntil,
            $maxOrdersPerHour
        ]);

        response(true, ['message' => 'Configuracao de entrega expressa salva!']);
    }

} catch (Exception $e) {
    error_log("[partner/express-config] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar configuracao", 500);
}
