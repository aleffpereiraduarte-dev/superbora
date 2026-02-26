<?php
/**
 * POST /api/mercado/notifications/register-token.php
 * Registra token FCM/push do dispositivo
 * Body: { "token": "...", "device_type": "web|android|ios", "user_type": "customer|partner|shopper" }
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $authToken = om_auth()->getTokenFromRequest();
    if (!$authToken) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($authToken);
    if (!$payload) response(false, null, "Token invalido", 401);

    $userId = (int)$payload['uid'];
    $userType = $payload['type'];

    $input = getInput();
    $pushToken = trim($input['token'] ?? '');
    $deviceType = trim($input['device_type'] ?? 'web');

    if (!$pushToken) response(false, null, "Token de push obrigatorio", 400);
    if (!in_array($deviceType, ['web', 'android', 'ios'])) $deviceType = 'web';

    // Verificar se token ja existe
    $stmt = $db->prepare("SELECT id FROM om_market_push_tokens WHERE token = ? AND user_id = ?");
    $stmt->execute([$pushToken, $userId]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Atualizar
        $db->prepare("UPDATE om_market_push_tokens SET device_type = ?, updated_at = NOW() WHERE id = ?")->execute([$deviceType, $existing['id']]);
        response(true, ['id' => (int)$existing['id']], "Token atualizado");
    }

    // Inserir novo
    $stmt = $db->prepare("
        INSERT INTO om_market_push_tokens (user_id, user_type, token, device_type, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$userId, $userType, $pushToken, $deviceType]);

    response(true, ['id' => (int)$db->lastInsertId()], "Token registrado");

} catch (Exception $e) {
    error_log("[notifications/register-token] Erro: " . $e->getMessage());
    response(false, null, "Erro ao registrar token", 500);
}
