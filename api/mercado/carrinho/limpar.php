<?php
/**
 * POST /api/mercado/carrinho/limpar.php
 * Body: { "session_id": "sess_xxx" }
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

try {
    $input = getInput();
    $db = getDB();

    // SECURITY: Use authenticated customer_id when available
    OmAuth::getInstance()->setDb($db);
    $authCustomerId = 0;
    try {
        $token = om_auth()->getTokenFromRequest();
        if ($token) {
            $payload = om_auth()->validateToken($token);
            if ($payload && $payload['type'] === 'customer') {
                $authCustomerId = (int)$payload['uid'];
            }
        }
    } catch (Exception $e) { /* auth optional */ }

    $session_id = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($input["session_id"] ?? ''));
    $customer_id = $authCustomerId; // SECURITY: never trust client-supplied customer_id

    // SECURITY: Validate session_id format for anonymous carts (must be UUID-like)
    if (!$customer_id && $session_id) {
        if (!preg_match('/^[a-f0-9]{8}-?[a-f0-9]{4}-?[a-f0-9]{4}-?[a-f0-9]{4}-?[a-f0-9]{12}$/i', $session_id)
            && !preg_match('/^sess_[a-zA-Z0-9_-]{20,60}$/', $session_id)) {
            response(false, null, "session_id invalido", 400);
        }
    }

    if ($customer_id > 0) {
        $stmt = $db->prepare("DELETE FROM om_market_cart WHERE customer_id = ?");
        $stmt->execute([$customer_id]);
    } elseif ($session_id) {
        $stmt = $db->prepare("DELETE FROM om_market_cart WHERE session_id = ?");
        $stmt->execute([$session_id]);
    } else {
        response(false, null, "session_id obrigatório", 400);
    }

    response(true, null, "Carrinho limpo");

} catch (Exception $e) {
    error_log("Carrinho limpar error: " . $e->getMessage());
    response(false, null, "Erro ao limpar carrinho", 500);
}
