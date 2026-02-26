<?php
/**
 * POST /api/mercado/partner/wallet-withdraw.php
 * Saque manual via PIX usando Woovi (OpenPix)
 *
 * Body: {amount}
 * Usa chave PIX configurada em om_payout_config
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
require_once dirname(__DIR__, 3) . "/includes/classes/PusherService.php";
require_once dirname(__DIR__, 3) . "/includes/classes/WooviClient.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        response(false, null, "Metodo nao permitido", 405);
    }

    $input = getInput();
    $amount = (float)($input['amount'] ?? 0);

    // Validacoes basicas
    if ($amount <= 0) {
        response(false, null, "Valor deve ser maior que zero", 400);
    }

    $min_withdraw = 10.00;
    if ($amount < $min_withdraw) {
        response(false, null, "Valor minimo para saque: R$ " . number_format($min_withdraw, 2, ',', '.'), 400);
    }

    // Rate limiting: max 3 withdrawal requests per day
    $stmtDailyLimit = $db->prepare("
        SELECT COUNT(*) FROM om_woovi_payouts
        WHERE partner_id = ? AND created_at >= CURRENT_DATE
    ");
    $stmtDailyLimit->execute([$partner_id]);
    if ((int)$stmtDailyLimit->fetchColumn() >= 3) {
        response(false, null, "Limite de 3 saques por dia atingido. Tente novamente amanha.", 429);
    }

    // Buscar config PIX do parceiro
    $stmtConfig = $db->prepare("
        SELECT pix_key, pix_key_type, pix_key_validated
        FROM om_payout_config
        WHERE partner_id = ?
    ");
    $stmtConfig->execute([$partner_id]);
    $config = $stmtConfig->fetch();

    if (!$config || empty($config['pix_key'])) {
        response(false, null, "Configure sua chave PIX antes de solicitar saque", 400);
    }

    if (!$config['pix_key_validated']) {
        response(false, null, "Sua chave PIX precisa ser validada antes do saque", 400);
    }

    $pixKey = $config['pix_key'];
    $pixKeyType = $config['pix_key_type'];

    // Iniciar transacao
    $db->beginTransaction();

    // Verificar saldo disponivel com lock (fonte de verdade: om_mercado_saldo)
    $stmtSaldo = $db->prepare("
        SELECT saldo_disponivel, COALESCE(saldo_devedor, 0) as saldo_devedor
        FROM om_mercado_saldo
        WHERE partner_id = ?
        FOR UPDATE
    ");
    $stmtSaldo->execute([$partner_id]);
    $saldo = $stmtSaldo->fetch();

    if (!$saldo) {
        $db->rollBack();
        response(false, null, "Saldo nao encontrado", 404);
    }

    $saldoDisponivel = (float)$saldo['saldo_disponivel'];
    $saldoDevedor = (float)$saldo['saldo_devedor'];

    // Bloquear se tem divida
    if ($saldoDevedor > 0) {
        $db->rollBack();
        response(false, null, "Saque bloqueado: comissao pendente de R$ " . number_format($saldoDevedor, 2, ',', '.') . ". Sera descontada automaticamente.", 403);
    }

    if ($amount > $saldoDisponivel) {
        $db->rollBack();
        response(false, null, "Saldo insuficiente. Disponivel: R$ " . number_format($saldoDisponivel, 2, ',', '.'), 400);
    }

    // Verificar se ja tem payout pendente/processing (with lock to prevent race condition)
    $stmtPending = $db->prepare("
        SELECT COUNT(*) FROM om_woovi_payouts
        WHERE partner_id = ? AND status IN ('pending', 'processing')
        FOR UPDATE
    ");
    $stmtPending->execute([$partner_id]);
    if ((int)$stmtPending->fetchColumn() > 0) {
        $db->rollBack();
        response(false, null, "Voce ja tem um saque em processamento. Aguarde a conclusao.", 409);
    }

    // Gerar correlation ID unico
    $correlationId = 'sb_withdraw_' . $partner_id . '_' . time() . '_' . bin2hex(random_bytes(4));
    $amountCents = (int)round($amount * 100);

    // Debitar saldo ANTES de chamar API (anti double-spend)
    $stmtDebit = $db->prepare("
        UPDATE om_mercado_saldo
        SET saldo_disponivel = saldo_disponivel - ?,
            total_sacado = COALESCE(total_sacado, 0) + ?,
            updated_at = NOW()
        WHERE partner_id = ?
    ");
    $stmtDebit->execute([$amount, $amount, $partner_id]);

    // Registrar payout como pending
    $stmtInsert = $db->prepare("
        INSERT INTO om_woovi_payouts
            (partner_id, correlation_id, amount_cents, amount, pix_key, pix_key_type, status, type, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', 'manual', NOW())
    ");
    $stmtInsert->execute([$partner_id, $correlationId, $amountCents, $amount, $pixKey, $pixKeyType]);
    $payoutId = (int)$db->lastInsertId();

    // Log no wallet
    $stmtLog = $db->prepare("
        INSERT INTO om_mercado_wallet
            (partner_id, tipo, valor, saldo_anterior, saldo_atual, saldo_posterior, descricao, status, created_at)
        VALUES (?, 'saque', ?, ?, ?, ?, ?, 'processing', NOW())
    ");
    $newBalance = $saldoDisponivel - $amount;
    $stmtLog->execute([
        $partner_id,
        -$amount,
        $saldoDisponivel,
        $newBalance,
        $newBalance,
        "Saque PIX manual - " . substr($pixKey, 0, 4) . "****"
    ]);

    $db->commit();

    // Chamar Woovi API fora da transacao
    $wooviStatus = 'processing';
    $wooviError = null;
    try {
        $woovi = new WooviClient();
        $result = $woovi->createPayout(
            $amountCents,
            $correlationId,
            $pixKey,
            $pixKeyType,
            "Repasse SuperBora - Parceiro #$partner_id"
        );

        $wooviTxId = $result['data']['transaction']['transactionID']
            ?? $result['data']['correlationID']
            ?? '';

        // Atualizar com dados da Woovi
        $stmtUpd = $db->prepare("
            UPDATE om_woovi_payouts
            SET status = 'processing',
                woovi_transaction_id = ?,
                woovi_raw_response = ?
            WHERE id = ?
        ");
        $stmtUpd->execute([$wooviTxId, $result['raw'] ?? '', $payoutId]);

    } catch (\Exception $wooviErr) {
        $wooviError = $wooviErr->getMessage();
        error_log("[wallet-withdraw] Woovi API erro: $wooviError");

        // Devolver saldo
        $db->beginTransaction();
        $stmtRefund = $db->prepare("
            UPDATE om_mercado_saldo
            SET saldo_disponivel = saldo_disponivel + ?,
                total_sacado = GREATEST(0, COALESCE(total_sacado, 0) - ?),
                updated_at = NOW()
            WHERE partner_id = ?
        ");
        $stmtRefund->execute([$amount, $amount, $partner_id]);

        $stmtFail = $db->prepare("
            UPDATE om_woovi_payouts
            SET status = 'failed', failure_reason = ?
            WHERE id = ?
        ");
        $stmtFail->execute([$wooviError, $payoutId]);

        // Log estorno
        $stmtLogRefund = $db->prepare("
            INSERT INTO om_mercado_wallet
                (partner_id, tipo, valor, descricao, status, created_at)
            VALUES (?, 'saque_estornado', ?, ?, 'refunded', NOW())
        ");
        $stmtLogRefund->execute([$partner_id, $amount, "Saque falhou: $wooviError"]);

        $db->commit();

        $wooviStatus = 'failed';
    }

    // Audit log
    om_audit()->log(
        OmAudit::ACTION_PAY,
        'woovi_payout',
        $payoutId,
        ['saldo_disponivel' => $saldoDisponivel],
        ['amount' => $amount, 'status' => $wooviStatus, 'correlation_id' => $correlationId],
        "Saque PIX R$ " . number_format($amount, 2, ',', '.') . " - $wooviStatus",
        'partner',
        $partner_id
    );

    // Pusher
    try {
        PusherService::walletUpdate($partner_id, [
            'balance' => round($wooviStatus === 'failed' ? $saldoDisponivel : ($saldoDisponivel - $amount), 2),
            'transaction' => [
                'id' => $payoutId,
                'type' => 'withdraw',
                'amount' => $amount,
                'status' => $wooviStatus
            ]
        ]);
    } catch (\Exception $e) {
        error_log("[wallet-withdraw] Pusher erro: " . $e->getMessage());
    }

    if ($wooviStatus === 'failed') {
        response(false, null, "Erro ao processar saque: $wooviError. Saldo devolvido.", 502);
    }

    response(true, [
        "payout_id" => $payoutId,
        "correlation_id" => $correlationId,
        "amount" => round($amount, 2),
        "new_balance" => round($saldoDisponivel - $amount, 2),
        "status" => $wooviStatus
    ], "Saque PIX enviado! Voce recebera em instantes.");

} catch (\Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("[partner/wallet-withdraw] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
