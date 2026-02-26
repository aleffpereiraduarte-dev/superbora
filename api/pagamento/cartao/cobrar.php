<?php
/**
 * POST /api/pagamento/cartao/cobrar.php
 * Cobra cartão de crédito (integrar com Pagar.me)
 */
require_once __DIR__ . "/../config/database.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") exit;

try {
    $input = getInput();
    $db = getDB();
    
    $tipo = $input["tipo"] ?? "corrida";
    $origem_id = $input["origem_id"] ?? 0;
    $valor = $input["valor"] ?? 0;
    $customer_id = $input["customer_id"] ?? 0;
    $card_token = $input["card_token"] ?? "";
    $parcelas = $input["parcelas"] ?? 1;
    
    // TODO: Integrar com Pagar.me para cobrar cartão
    // Por enquanto, simula sucesso
    
    $txid = "card_" . bin2hex(random_bytes(16));
    
    $sql = "INSERT INTO om_payments (tipo_origem, origem_id, customer_id, valor_bruto, valor_liquido, metodo, parcelas, gateway, gateway_id, cartao_token, status, pago_em, created_at)
            VALUES (?, ?, ?, ?, ?, \"credito\", ?, \"pagarme\", ?, ?, \"pago\", NOW(), NOW())";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$tipo, $origem_id, $customer_id, $valor, $valor, $parcelas, $txid, $card_token]);
    
    $payment_id = $stmt->fetchColumn();
    
    response(true, [
        "payment_id" => $payment_id,
        "status" => "pago",
        "valor" => $valor,
        "parcelas" => $parcelas
    ], "Pagamento aprovado!");
    
} catch (Exception $e) {
    error_log("[pagamento/cartao/cobrar] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
