<?php
/**
 * POST /api/mercado/auth/logout.php
 * Logout: revoga o token JWT atual e opcionalmente remove push token
 *
 * Body (optional): { "push_token": "ExponentPushToken[...]" }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Requer autenticacao
    $token = om_auth()->getTokenFromRequest();
    if (!$token) {
        response(false, null, "Autenticacao necessaria", 401);
    }

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') {
        response(false, null, "Token invalido", 401);
    }

    $customerId = (int)$payload['uid'];
    $jti = $payload['jti'] ?? '';

    // Revogar o token atual
    if (!empty($jti)) {
        om_auth()->revokeToken($jti);
    }

    // Se push_token foi enviado no body, remover do banco
    $input = getInput();
    $pushToken = trim($input['push_token'] ?? $input['pushToken'] ?? '');

    if (!empty($pushToken)) {
        try {
            $stmt = $db->prepare("DELETE FROM om_market_push_tokens WHERE token = ? AND user_id = ? AND user_type = 'customer'");
            $stmt->execute([$pushToken, $customerId]);
        } catch (Exception $e) {
            // Nao critico - logar e continuar
            error_log("[auth/logout] Erro ao remover push token: " . $e->getMessage());
        }
    }

    response(true, null, "Logout realizado com sucesso");

} catch (Exception $e) {
    error_log("[API Auth Logout] Erro: " . $e->getMessage());
    response(false, null, "Erro ao realizar logout", 500);
}
