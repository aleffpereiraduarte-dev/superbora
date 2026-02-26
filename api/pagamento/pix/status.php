<?php
/**
 * GET /api/pagamento/pix/status.php?payment_id=1 ou ?txid=ABC123
 */
require_once __DIR__ . "/../config/database.php";

try {
    $db = getDB();
    
    $payment_id = $_GET["payment_id"] ?? 0;
    $txid = $_GET["txid"] ?? "";
    
    // Prepared statements para prevenir SQL Injection
    if ($payment_id) {
        $stmt = $db->prepare("SELECT * FROM om_payments WHERE id = ?");
        $stmt->execute([(int)$payment_id]);
    } else {
        $stmt = $db->prepare("SELECT * FROM om_payments WHERE gateway_id = ?");
        $stmt->execute([$txid]);
    }
    $pagamento = $stmt->fetch();
    
    if (!$pagamento) {
        response(false, null, "Pagamento não encontrado", 404);
    }
    
    response(true, [
        "payment_id" => $pagamento["id"],
        "status" => $pagamento["status"],
        "valor" => floatval($pagamento["valor_bruto"]),
        "pago_em" => $pagamento["pago_em"]
    ]);
    
} catch (Exception $e) {
    error_log("[pagamento/pix/status] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
