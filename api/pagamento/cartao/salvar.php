<?php
/**
 * POST /api/pagamento/cartao/salvar.php
 * Salva cartão tokenizado
 */
require_once __DIR__ . "/../config/database.php";

try {
    $input = getInput();
    $db = getDB();
    
    $customer_id = $input["customer_id"] ?? 0;
    $card_token = $input["card_token"] ?? "";
    $ultimos_digitos = $input["ultimos_digitos"] ?? "";
    $bandeira = $input["bandeira"] ?? "";
    $nome_titular = $input["nome_titular"] ?? "";
    $validade = $input["validade"] ?? "";
    $apelido = $input["apelido"] ?? "Meu Cartão";
    
    $sql = "INSERT INTO om_payment_methods (customer_id, tipo, token, bandeira, ultimos_digitos, nome_titular, validade, apelido, ativo)
            VALUES (?, \"cartao_credito\", ?, ?, ?, ?, ?, ?, 1)";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$customer_id, $card_token, $bandeira, $ultimos_digitos, $nome_titular, $validade, $apelido]);
    
    response(true, ["id" => $stmt->fetchColumn()], "Cartão salvo!");
    
} catch (Exception $e) {
    error_log("[pagamento/cartao/salvar] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
