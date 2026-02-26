<?php
/**
 * CRON: Processar payouts automaticos via Woovi
 *
 * Roda diariamente (recomendado: 0 8 * * *)
 * Para cada parceiro com auto_payout=TRUE cujo dia/frequencia bateu:
 *   - Verifica saldo_disponivel >= min_payout
 *   - Verifica saldo_devedor = 0
 *   - Verifica chave PIX validada
 *   - Verifica que nao tem payout pendente/processing
 *   - Debita saldo, cria om_woovi_payouts, chama Woovi API
 *   - Se falhar: devolve saldo
 *
 * Executar: php /var/www/html/api/mercado/cron/processar-payouts.php
 * Ou via curl: curl -s https://superbora.com.br/api/mercado/cron/processar-payouts.php?key=CRON_KEY
 */

require_once __DIR__ . '/../config/database.php';
require_once dirname(__DIR__, 3) . '/includes/classes/WooviClient.php';
require_once dirname(__DIR__, 3) . '/includes/classes/PusherService.php';

// SECURITY: Verify cron key via HTTP header only (not GET param), constant-time comparison
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    $cronKey = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: '';
    $providedKey = $_SERVER['HTTP_X_CRON_KEY'] ?? '';
    if (empty($cronKey) || empty($providedKey) || !hash_equals($cronKey, $providedKey)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

$startTime = microtime(true);
$processed = 0;
$failed = 0;
$skipped = 0;

try {
    $db = getDB();
    $woovi = new WooviClient();

    $today = new DateTime();
    $dayOfWeek = (int)$today->format('N'); // 1=Mon 7=Sun
    $dayOfMonth = (int)$today->format('j');

    // Buscar parceiros com auto_payout habilitado e PIX validado
    $stmt = $db->prepare("
        SELECT
            pc.partner_id,
            pc.payout_frequency,
            pc.payout_day,
            pc.min_payout,
            pc.pix_key,
            pc.pix_key_type,
            ms.saldo_disponivel,
            ms.saldo_devedor
        FROM om_payout_config pc
        INNER JOIN om_mercado_saldo ms ON ms.partner_id = pc.partner_id
        WHERE pc.auto_payout = 1
          AND pc.pix_key_validated = 1
          AND pc.pix_key IS NOT NULL
          AND ms.saldo_disponivel >= pc.min_payout
          AND COALESCE(ms.saldo_devedor, 0) <= 0
    ");
    $stmt->execute();
    $partners = $stmt->fetchAll();

    foreach ($partners as $partner) {
        $partnerId = (int)$partner['partner_id'];
        $freq = $partner['payout_frequency'];
        $payDay = (int)$partner['payout_day'];

        // Verificar se hoje e o dia do payout
        $shouldPay = false;
        switch ($freq) {
            case 'daily':
                $shouldPay = true;
                break;
            case 'weekly':
                $shouldPay = ($dayOfWeek === $payDay);
                break;
            case 'biweekly':
                $weekNum = (int)$today->format('W');
                $shouldPay = ($dayOfWeek === $payDay && $weekNum % 2 === 0);
                break;
            case 'monthly':
                $shouldPay = ($dayOfMonth === $payDay);
                break;
        }

        if (!$shouldPay) {
            $skipped++;
            continue;
        }

        // Verificar payout pendente
        $stmtPending = $db->prepare("
            SELECT COUNT(*) FROM om_woovi_payouts
            WHERE partner_id = ? AND status IN ('pending', 'processing')
        ");
        $stmtPending->execute([$partnerId]);
        if ((int)$stmtPending->fetchColumn() > 0) {
            error_log("[cron-payouts] Parceiro $partnerId ja tem payout pendente, pulando");
            $skipped++;
            continue;
        }

        $amount = (float)$partner['saldo_disponivel'];
        $amountCents = (int)round($amount * 100);
        $pixKey = $partner['pix_key'];
        $pixKeyType = $partner['pix_key_type'];
        $correlationId = 'sb_auto_' . $partnerId . '_' . date('Ymd') . '_' . bin2hex(random_bytes(4));

        try {
            $db->beginTransaction();

            // Debitar saldo com lock
            $stmtLock = $db->prepare("
                SELECT saldo_disponivel FROM om_mercado_saldo
                WHERE partner_id = ? FOR UPDATE
            ");
            $stmtLock->execute([$partnerId]);
            $currentSaldo = (float)$stmtLock->fetchColumn();

            if ($currentSaldo < (float)$partner['min_payout']) {
                $db->rollBack();
                $skipped++;
                continue;
            }

            // Usar saldo atual (pode ter mudado)
            $amount = $currentSaldo;
            $amountCents = (int)round($amount * 100);

            $stmtDebit = $db->prepare("
                UPDATE om_mercado_saldo
                SET saldo_disponivel = 0, updated_at = NOW()
                WHERE partner_id = ?
            ");
            $stmtDebit->execute([$partnerId]);

            // Registrar payout
            $stmtInsert = $db->prepare("
                INSERT INTO om_woovi_payouts
                    (partner_id, correlation_id, amount_cents, amount, pix_key, pix_key_type, status, type, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', 'auto', NOW())
            ");
            $stmtInsert->execute([$partnerId, $correlationId, $amountCents, $amount, $pixKey, $pixKeyType]);
            $payoutId = (int)$db->lastInsertId();

            // Log no wallet
            $stmtLog = $db->prepare("
                INSERT INTO om_mercado_wallet
                    (partner_id, tipo, valor, saldo_anterior, saldo_atual, descricao, status, created_at)
                VALUES (?, 'saque_auto', ?, ?, 0, ?, 'processing', NOW())
            ");
            $stmtLog->execute([$partnerId, $amount, $currentSaldo, "Repasse automatico $freq - Woovi"]);

            $db->commit();

            // Chamar Woovi API
            try {
                $result = $woovi->createPayout(
                    $amountCents,
                    $correlationId,
                    $pixKey,
                    $pixKeyType,
                    "Repasse auto SuperBora - Parceiro #$partnerId"
                );

                $wooviTxId = $result['data']['transaction']['transactionID']
                    ?? $result['data']['correlationID']
                    ?? '';

                $stmtUpd = $db->prepare("
                    UPDATE om_woovi_payouts
                    SET status = 'processing', woovi_transaction_id = ?, woovi_raw_response = ?
                    WHERE id = ?
                ");
                $stmtUpd->execute([$wooviTxId, $result['raw'] ?? '', $payoutId]);

                $processed++;
                error_log("[cron-payouts] Parceiro $partnerId: R$ " . number_format($amount, 2) . " enviado via Woovi ($correlationId)");

            } catch (\Exception $apiErr) {
                // Woovi falhou - devolver saldo
                error_log("[cron-payouts] Woovi API erro parceiro $partnerId: " . $apiErr->getMessage());

                $db->beginTransaction();
                $stmtRefund = $db->prepare("
                    UPDATE om_mercado_saldo
                    SET saldo_disponivel = saldo_disponivel + ?, updated_at = NOW()
                    WHERE partner_id = ?
                ");
                $stmtRefund->execute([$amount, $partnerId]);

                $stmtFail = $db->prepare("
                    UPDATE om_woovi_payouts
                    SET status = 'failed', failure_reason = ?
                    WHERE id = ?
                ");
                $stmtFail->execute([$apiErr->getMessage(), $payoutId]);

                $stmtLogRefund = $db->prepare("
                    INSERT INTO om_mercado_wallet
                        (partner_id, tipo, valor, descricao, status, created_at)
                    VALUES (?, 'saque_estornado', ?, ?, 'refunded', NOW())
                ");
                $stmtLogRefund->execute([$partnerId, $amount, "Auto payout falhou: " . $apiErr->getMessage()]);

                $db->commit();
                $failed++;
            }

            // Notificar parceiro
            try {
                PusherService::walletUpdate($partnerId, [
                    'balance' => 0,
                    'transaction' => [
                        'id' => $payoutId,
                        'type' => 'auto_withdraw',
                        'amount' => $amount,
                        'status' => 'processing'
                    ]
                ]);
            } catch (\Exception $e) {
                // Pusher nao e critico
            }

        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("[cron-payouts] Erro parceiro $partnerId: " . $e->getMessage());
            $failed++;
        }
    }

} catch (\Exception $e) {
    error_log("[cron-payouts] Erro fatal: " . $e->getMessage());
}

$elapsed = round(microtime(true) - $startTime, 2);
$summary = "[cron-payouts] Concluido em {$elapsed}s: $processed enviados, $failed falhas, $skipped ignorados";
error_log($summary);

if (php_sapi_name() === 'cli') {
    echo $summary . "\n";
} else {
    echo json_encode([
        'success' => true,
        'processed' => $processed,
        'failed' => $failed,
        'skipped' => $skipped,
        'elapsed' => $elapsed
    ]);
}
