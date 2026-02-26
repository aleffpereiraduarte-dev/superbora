<?php
/**
 * POST /api/mercado/partner/toggle-status.php
 * Toggle ou setar is_open do parceiro
 * Body: { "is_open": true/false } ou vazio (toggle)
 */

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/cache.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    response(false, null, "Metodo nao permitido", 405);
}

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
    $input = getInput();

    if (isset($input['is_open'])) {
        $newStatus = $input['is_open'] ? 1 : 0;
    } else {
        // Toggle: inverte o valor atual
        $stmt = $db->prepare("SELECT is_open FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$partnerId]);
        $current = $stmt->fetchColumn();
        $newStatus = $current ? 0 : 1;
    }

    $stmt = $db->prepare("UPDATE om_market_partners SET is_open = ?, updated_at = NOW() WHERE partner_id = ?");
    $stmt->execute([$newStatus, $partnerId]);

    // Invalidar caches relacionados
    om_cache()->flush('store_');
    om_cache()->flush('admin_');

    response(true, [
        "is_open" => (bool)$newStatus,
        "message" => $newStatus ? "Loja aberta" : "Loja fechada"
    ]);

} catch (Exception $e) {
    error_log("[partner/toggle-status] Erro: " . $e->getMessage());
    response(false, null, "Erro ao atualizar status", 500);
}
