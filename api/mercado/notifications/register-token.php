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

    // Upsert: insert or update if token already exists (handles unique constraint on token column)
    $stmt = $db->prepare("
        INSERT INTO om_market_push_tokens (user_id, user_type, token, device_type, created_at, updated_at)
        VALUES (?, ?, ?, ?, NOW(), NOW())
        ON CONFLICT (token) DO UPDATE
        SET user_id = EXCLUDED.user_id,
            user_type = EXCLUDED.user_type,
            device_type = EXCLUDED.device_type,
            updated_at = NOW()
        RETURNING id
    ");
    $stmt->execute([$userId, $userType, $pushToken, $deviceType]);
    $row = $stmt->fetch();

    response(true, ['id' => (int)$row['id']], "Token registrado");

} catch (Exception $e) {
    error_log("[notifications/register-token] Erro: " . $e->getMessage());
    response(false, null, "Erro ao registrar token", 500);
}
