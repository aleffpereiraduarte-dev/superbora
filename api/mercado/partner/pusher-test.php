<?php
/**
 * POST /api/mercado/partner/pusher-test.php - Test Pusher notifications
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/PusherService.php";

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
        // Return Pusher config for frontend
        response(true, [
            'app_key' => PusherService::getAppKey(),
            'cluster' => PusherService::getCluster(),
            'channel' => "partner-{$partnerId}",
        ]);
    }

    if ($method === 'POST') {
        $input = getInput();
        $type = $input['type'] ?? 'notification';

        switch ($type) {
            case 'new_order':
                $result = PusherService::newOrder($partnerId, [
                    'id' => rand(1000, 9999),
                    'order_number' => 'TEST-' . rand(100, 999),
                    'total' => rand(50, 200) + (rand(0, 99) / 100),
                    'items_count' => rand(1, 5),
                    'customer_name' => 'Cliente Teste',
                ]);
                break;

            case 'order_update':
                $result = PusherService::orderUpdate($partnerId, [
                    'id' => $input['order_id'] ?? rand(1000, 9999),
                    'order_number' => $input['order_number'] ?? 'ORD-' . rand(100, 999),
                    'status' => $input['status'] ?? 'preparando',
                ]);
                break;

            case 'chat':
                $result = PusherService::chatMessage($partnerId, [
                    'order_id' => $input['order_id'] ?? rand(1000, 9999),
                    'customer_name' => 'Cliente Teste',
                    'message' => $input['message'] ?? 'Ola, tudo bem?',
                ]);
                break;

            case 'notification':
            default:
                $result = PusherService::notify(
                    $partnerId,
                    $input['title'] ?? 'Teste de Notificacao',
                    $input['message'] ?? 'Esta e uma notificacao de teste via Pusher!',
                    $input['level'] ?? 'info'
                );
                break;

            case 'alert':
                $result = PusherService::alert(
                    $partnerId,
                    $input['title'] ?? 'Alerta de Teste',
                    $input['message'] ?? 'Este e um alerta urgente de teste!'
                );
                break;
        }

        if ($result) {
            response(true, null, "Notificacao enviada via Pusher!");
        } else {
            response(false, null, "Erro ao enviar notificacao", 500);
        }
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/pusher-test] Erro: " . $e->getMessage());
    response(false, null, "Erro interno ao processar requisicao", 500);
}
