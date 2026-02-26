<?php
/**
 * POST /api/mercado/carrinho/remover.php
 * Body: { "session_id": "sess_xxx", "item_id": 1 }
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

    $cart_id = (int)($input["cart_id"] ?? $input["item_id"] ?? 0);
    $session_id = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($input["session_id"] ?? ''));
    $customer_id = $authCustomerId; // SECURITY: never trust client-supplied customer_id

    if (!$cart_id) {
        response(false, null, "item_id obrigatório", 400);
    }

    // SECURITY: Validate session_id format for anonymous carts (must be UUID-like)
    if (!$customer_id && $session_id) {
        if (!preg_match('/^[a-f0-9]{8}-?[a-f0-9]{4}-?[a-f0-9]{4}-?[a-f0-9]{4}-?[a-f0-9]{12}$/i', $session_id)
            && !preg_match('/^sess_[a-zA-Z0-9_-]{20,60}$/', $session_id)) {
            response(false, null, "session_id invalido", 400);
        }
    }

    // Build WHERE clause — authenticated users use customer_id only, anonymous use session_id
    if ($customer_id > 0) {
        $ownerClause = "customer_id = ?";
        $ownerParams = [$customer_id];
    } else {
        $ownerClause = "session_id = ?";
        $ownerParams = [$session_id];
    }

    // Verify ownership before deleting
    $checkStmt = $db->prepare("SELECT cart_id FROM om_market_cart WHERE cart_id = ? AND {$ownerClause}");
    $checkStmt->execute([$cart_id, ...$ownerParams]);
    if (!$checkStmt->fetch()) {
        response(false, null, "Item não encontrado", 404);
    }

    $stmt = $db->prepare("DELETE FROM om_market_cart WHERE cart_id = ? AND {$ownerClause}");
    $stmt->execute([$cart_id, ...$ownerParams]);

    response(true, null, "Item removido do carrinho");

} catch (Exception $e) {
    error_log("[carrinho/remover] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
