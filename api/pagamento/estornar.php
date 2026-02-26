<?php
/**
 * POST /api/pagamento/estornar.php
 * ADMIN ONLY — Estorna um pagamento
 */
require_once __DIR__ . "/config/database.php";
require_once dirname(__DIR__, 2) . '/includes/classes/OmAuth.php';

try {
    $input = getInput();
    $db = getDB();

    // SECURITY: Somente admin pode estornar pagamentos
    OmAuth::getInstance()->setDb($db);
    $token = OmAuth::getInstance()->getTokenFromRequest();
    if (!$token) {
        response(false, null, "Autenticacao obrigatoria", 401);
    }
    $payload = OmAuth::getInstance()->validateToken($token);
    if (!$payload || !in_array($payload['type'] ?? '', ['admin', 'superadmin'])) {
        response(false, null, "Apenas administradores podem estornar pagamentos", 403);
    }

    $payment_id = (int)($input["payment_id"] ?? 0);
    $motivo = trim(substr($input["motivo"] ?? "", 0, 500));

    if ($payment_id <= 0) {
        response(false, null, "ID de pagamento invalido", 400);
    }

    if (empty($motivo)) {
        response(false, null, "Motivo do estorno obrigatorio", 400);
    }

    $stmt = $db->prepare("UPDATE om_payments SET status = 'estornado', motivo_estorno = ? WHERE id = ? AND status != 'estornado'");
    $stmt->execute([$motivo, $payment_id]);

    if ($stmt->rowCount() === 0) {
        response(false, null, "Pagamento nao encontrado ou ja estornado", 404);
    }

    error_log("[estornar] Pagamento #$payment_id estornado por admin #{$payload['uid']}. Motivo: $motivo");

    response(true, ["payment_id" => $payment_id, "status" => "estornado"]);

} catch (Exception $e) {
    error_log("[pagamento/estornar] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
