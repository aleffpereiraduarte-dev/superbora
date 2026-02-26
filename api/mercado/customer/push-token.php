<?php
/**
 * ====================================================================
 * POST/DELETE /api/mercado/customer/push-token.php
 * ====================================================================
 *
 * Register or remove a push notification token (FCM) for a customer.
 *
 * POST - Register token:
 *   Body: { "token": "fcm_token_string", "platform": "web"|"android"|"ios" }
 *
 * DELETE - Remove token:
 *   Body/Query: { "token": "fcm_token_string" }
 *
 * Requires: Customer authentication (Bearer token)
 *
 * Table: om_market_push_tokens
 *   - id (PK)
 *   - user_id (FK to customer_id / partner_id / shopper_id)
 *   - user_type ('customer', 'partner', 'shopper')
 *   - token (FCM token string)
 *   - platform ('web', 'android', 'ios')
 *   - created_at
 *   - updated_at
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

// CORS
setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Auth check
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload) response(false, null, "Token invalido", 401);

    $userId = (int)$payload['uid'];
    $rawType = $payload['type']; // 'customer', 'partner', 'shopper'

    // This is a customer endpoint — only accept customer tokens
    if ($rawType !== 'customer') {
        response(false, null, "Este endpoint e exclusivo para clientes", 403);
    }
    $userType = 'customer';

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'POST') {
        // Register push token
        $input = getInput();
        $pushToken = trim($input['token'] ?? '');
        $platform = strtolower(trim($input['platform'] ?? 'web'));

        if (empty($pushToken)) {
            response(false, null, "Token e obrigatorio", 400);
        }
        if (!in_array($platform, ['web', 'android', 'ios'])) {
            $platform = 'web';
        }

        // SECURITY: Atomic upsert using ON CONFLICT to prevent race conditions
        $stmt = $db->prepare("
            INSERT INTO om_market_push_tokens (user_id, user_type, token, platform, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW())
            ON CONFLICT (token) DO UPDATE SET
                user_id = EXCLUDED.user_id,
                user_type = EXCLUDED.user_type,
                platform = EXCLUDED.platform,
                updated_at = NOW()
        ");
        $stmt->execute([$userId, $userType, $pushToken, $platform]);

        // Clean up: remove old tokens for same user (keep max 5 per user)
        cleanupOldTokens($db, $userId, $userType, 5);

        response(true, ['registered' => true], "Token registrado com sucesso");

    } elseif ($method === 'DELETE') {
        // Remove push token
        $input = getInput();
        if (empty($input)) {
            // Try query string
            $pushToken = trim($_GET['token'] ?? '');
        } else {
            $pushToken = trim($input['token'] ?? '');
        }

        if (empty($pushToken)) {
            response(false, null, "Token e obrigatorio", 400);
        }

        $stmt = $db->prepare("DELETE FROM om_market_push_tokens WHERE token = ? AND user_id = ? AND user_type = ?");
        $stmt->execute([$pushToken, $userId, $userType]);

        response(true, ['removed' => true], "Token removido com sucesso");

    } else {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[push-token] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar token", 500);
}

/**
 * Create push tokens table if not exists
 */
function ensurePushTokensTable(PDO $db): void
{
    // Table om_market_push_tokens created via migration
    return;
}

/**
 * Keep only the N most recent tokens per user to prevent accumulation
 */
function cleanupOldTokens(PDO $db, int $userId, string $userType, int $maxTokens): void
{
    try {
        $stmt = $db->prepare("
            SELECT id FROM om_market_push_tokens
            WHERE user_id = ? AND user_type = ?
            ORDER BY updated_at DESC
            LIMIT 999999 OFFSET ?
        ");
        $stmt->execute([$userId, $userType, $maxTokens]);
        $oldIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($oldIds)) {
            $placeholders = implode(',', array_fill(0, count($oldIds), '?'));
            $db->prepare("DELETE FROM om_market_push_tokens WHERE id IN ($placeholders)")->execute($oldIds);
        }
    } catch (Exception $e) {
        // Non-critical, ignore
    }
}
