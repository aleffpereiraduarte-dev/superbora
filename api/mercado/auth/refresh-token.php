<?php
/**
 * POST /api/mercado/auth/refresh-token.php
 * Refreshes an existing valid token, returning a new one with extended expiry.
 * Requires: Authorization: Bearer <token>
 * Returns: { success: true, token: "new_token" }
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

    // Validate current token
    $token = om_auth()->getTokenFromRequest();
    if (!$token) {
        response(false, null, "Token nao fornecido", 401);
    }

    $payload = om_auth()->validateToken($token);
    if (!$payload) {
        response(false, null, "Token invalido ou expirado", 401);
    }

    // Only refresh customer tokens
    if (($payload['type'] ?? '') !== 'customer') {
        response(false, null, "Tipo de token invalido", 400);
    }

    $customerId = (int)($payload['uid'] ?? 0);
    if (!$customerId) {
        response(false, null, "Token invalido", 401);
    }

    // Verify customer still exists and is active
    $stmt = $db->prepare("SELECT customer_id, name, email, is_active FROM om_customers WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();

    if (!$customer) {
        response(false, null, "Conta nao encontrada", 404);
    }

    if (!$customer['is_active']) {
        response(false, null, "Conta desativada", 403);
    }

    // Generate new token with fresh expiry
    $newToken = om_auth()->generateToken('customer', $customerId, [
        'name' => $customer['name'] ?? '',
        'email' => $customer['email'] ?? '',
    ]);

    // Update last_login
    $db->prepare("UPDATE om_customers SET last_login = NOW() WHERE customer_id = ?")->execute([$customerId]);

    response(true, [
        "token" => $newToken,
    ], "Token renovado!");

} catch (Exception $e) {
    error_log("[refresh-token] Erro: " . $e->getMessage());
    response(false, null, "Erro ao renovar token", 500);
}
