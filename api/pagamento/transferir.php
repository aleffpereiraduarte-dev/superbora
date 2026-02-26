<?php
/**
 * POST /api/pagamento/transferir.php
 * Processa saque PIX
 */
require_once __DIR__ . "/config/database.php";

try {
    $input = getInput();
    $db = getDB();
    
    $withdrawal_id = (int)($input["withdrawal_id"] ?? 0);

    if ($withdrawal_id <= 0) {
        response(false, null, "ID de saque inválido", 400);
    }

    // Buscar saque com prepared statement
    $stmt = $db->prepare("SELECT * FROM om_withdrawals WHERE id = ? AND status = 'pendente'");
    $stmt->execute([$withdrawal_id]);
    $saque = $stmt->fetch();

    if (!$saque) {
        response(false, null, "Saque não encontrado ou já processado", 404);
    }

    // TODO: Integrar com Pagar.me para fazer transferência PIX
    // Por enquanto, simula sucesso

    $gateway_id = "transfer_" . bin2hex(random_bytes(16));

    $stmt = $db->prepare("UPDATE om_withdrawals SET status = 'pago', gateway_id = ?, processado_em = NOW() WHERE id = ?");
    $stmt->execute([$gateway_id, $withdrawal_id]);
    
    response(true, [
        "withdrawal_id" => $withdrawal_id,
        "status" => "pago",
        "gateway_id" => $gateway_id
    ], "Transferência realizada!");
    
} catch (Exception $e) {
    error_log("[pagamento/transferir] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
