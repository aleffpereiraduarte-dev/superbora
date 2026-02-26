<?php
/**
 * GET /api/mercado/customer/payment-eligibility.php?partner_id=101
 * Retorna se o cliente pode usar dinheiro/maquininha na entrega.
 *
 * Regras:
 * 1. Cliente precisa ter 1+ pedido entregue com pagamento online
 * 2. Parceiro precisa aceitar retirada ou entrega propria
 * 3. Cash nao permitido com BoraUm (motorista externo)
 * 4. Teto de R$200 para dinheiro
 */

require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

try {
    $db = getDB();
    $customer_id = requireCustomerAuth();

    $partner_id = (int)($_GET['partner_id'] ?? 0);

    // 1. Contar pedidos entregues com pagamento online
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM om_market_orders
        WHERE customer_id = ?
        AND status IN ('entregue','retirado','completed')
        AND payment_method NOT IN ('dinheiro','cartao_entrega')
    ");
    $stmt->execute([$customer_id]);
    $completedOnline = (int)$stmt->fetchColumn();

    $allowsCash = $completedOnline >= 1;
    $reason = $allowsCash ? null : "Faca seu 1o pedido com PIX ou cartao para desbloquear pagamento na entrega.";

    // 2. Parceiro aceita retirada ou entrega propria?
    $partnerAcceptsCash = false;
    if ($partner_id) {
        $stmt = $db->prepare("SELECT aceita_retirada, entrega_propria FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$partner_id]);
        $p = $stmt->fetch();
        if ($p) {
            $partnerAcceptsCash = (bool)$p['aceita_retirada'] || (bool)$p['entrega_propria'];
        }
    }

    // Final eligibility: both customer AND partner must allow cash
    $finalAllowsCash = $allowsCash && $partnerAcceptsCash;
    if ($allowsCash && !$partnerAcceptsCash && $partner_id) {
        $reason = "Este estabelecimento nao aceita pagamento na entrega.";
    }

    response(true, [
        "completed_orders" => $completedOnline,
        "allows_cash" => $finalAllowsCash,
        "allows_card_machine" => $finalAllowsCash,
        "cash_limit" => 200,
        "partner_accepts_cash" => $partnerAcceptsCash,
        "customer_eligible" => $allowsCash,
        "reason" => $finalAllowsCash ? null : $reason
    ]);

} catch (Exception $e) {
    error_log("[payment-eligibility] Erro: " . $e->getMessage());
    response(false, null, "Erro ao verificar elegibilidade", 500);
}
