<?php
/**
 * GET /api/mercado/partner/wallet.php
 * Wallet info: saldo_disponivel, saldo_pendente, recent transactions
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];

    // Buscar saldo da carteira (fonte de verdade: om_mercado_saldo)
    $stmtWallet = $db->prepare("
        SELECT saldo_disponivel, saldo_pendente, COALESCE(saldo_devedor, 0) as saldo_devedor,
               COALESCE(total_recebido, 0) as total_recebido, COALESCE(total_sacado, 0) as total_sacado
        FROM om_mercado_saldo
        WHERE partner_id = ?
    ");
    $stmtWallet->execute([$partner_id]);
    $wallet = $stmtWallet->fetch();

    $saldo_disponivel = $wallet ? (float)$wallet['saldo_disponivel'] : 0;
    $saldo_pendente = $wallet ? (float)$wallet['saldo_pendente'] : 0;
    $saldo_devedor = $wallet ? (float)$wallet['saldo_devedor'] : 0;
    $total_recebido = $wallet ? (float)$wallet['total_recebido'] : 0;
    $total_sacado = $wallet ? (float)$wallet['total_sacado'] : 0;

    // Ultimas 20 transacoes do om_mercado_wallet
    $stmtTx = $db->prepare("
        SELECT
            id, tipo as type, valor as amount, descricao as description, status, created_at
        FROM om_mercado_wallet
        WHERE partner_id = ?
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmtTx->execute([$partner_id]);
    $transactions = $stmtTx->fetchAll();

    $txList = [];
    foreach ($transactions as $tx) {
        $txList[] = [
            "id" => (int)$tx['id'],
            "type" => $tx['type'],
            "amount" => (float)$tx['amount'],
            "description" => $tx['description'],
            "status" => $tx['status'],
            "created_at" => $tx['created_at']
        ];
    }

    // Config PIX do parceiro
    $stmtPix = $db->prepare("SELECT pix_key, pix_key_type, pix_key_validated FROM om_payout_config WHERE partner_id = ?");
    $stmtPix->execute([$partner_id]);
    $pixConfig = $stmtPix->fetch();

    response(true, [
        "saldo_disponivel" => round($saldo_disponivel, 2),
        "saldo_pendente" => round($saldo_pendente, 2),
        "saldo_devedor" => round($saldo_devedor, 2),
        "saldo_total" => round($saldo_disponivel + $saldo_pendente, 2),
        "total_recebido" => round($total_recebido, 2),
        "total_sacado" => round($total_sacado, 2),
        "pix_configured" => !empty($pixConfig['pix_key']),
        "pix_validated" => !empty($pixConfig['pix_key_validated']),
        "transactions" => $txList
    ], "Carteira carregada");

} catch (Exception $e) {
    error_log("[partner/wallet] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
