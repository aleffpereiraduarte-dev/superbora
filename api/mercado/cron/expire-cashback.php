<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * CRON: Expirar Cashback Vencido
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Executa diariamente para expirar cashback vencido
 * Crontab: 0 2 * * * /usr/bin/php /var/www/html/api/mercado/cron/expire-cashback.php
 *
 * Este script:
 * 1. Busca todos os creditos de cashback expirados (expires_at < hoje)
 * 2. Para cada credito expirado, verifica se ainda ha saldo disponivel
 * 3. Cria transacao de expiracao e atualiza wallet
 * 4. Notifica cliente (opcional)
 */

// Rodar apenas via CLI ou com header especial
$secret = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: '';
if (empty($secret)) { http_response_code(503); echo json_encode(['error' => 'Cron secret not configured']); exit; }
if (php_sapi_name() !== 'cli' && (!isset($_SERVER['HTTP_X_CRON_KEY']) || !hash_equals($secret, $_SERVER['HTTP_X_CRON_KEY']))) {
    http_response_code(403);
    die('Acesso negado');
}

require_once dirname(__DIR__) . "/config/database.php";

// Global lock to prevent concurrent executions
$lockFile = sys_get_temp_dir() . '/superbora_expire_cashback.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] SKIP: Another instance is running\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Iniciando expiracao de cashback...\n";

try {
    $db = getDB();

    // Buscar creditos de cashback vencidos e nao processados
    // Use FOR UPDATE SKIP LOCKED to prevent concurrent processing of same rows
    $db->beginTransaction();
    $stmt = $db->prepare("
        SELECT
            ct.id,
            ct.customer_id,
            ct.amount,
            ct.order_id,
            ct.partner_id,
            ct.description,
            ct.expires_at
        FROM om_cashback_transactions ct
        WHERE ct.type = 'credit'
        AND ct.expired = 0
        AND ct.expires_at IS NOT NULL
        AND ct.expires_at < CURRENT_DATE
        ORDER BY ct.expires_at ASC
        LIMIT 1000
        FOR UPDATE SKIP LOCKED
    ");
    $stmt->execute();
    $expiredCredits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $db->commit();

    $totalExpired = 0;
    $totalAmount = 0;
    $customersAffected = [];

    foreach ($expiredCredits as $credit) {
        $customerId = (int)$credit['customer_id'];
        $amount = (float)$credit['amount'];

        try {
            $db->beginTransaction();

            // Lock the credit row to prevent double-processing
            $stmt = $db->prepare("SELECT id, expired FROM om_cashback_transactions WHERE id = ? FOR UPDATE SKIP LOCKED");
            $stmt->execute([$credit['id']]);
            $lockedCredit = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$lockedCredit || (int)$lockedCredit['expired'] === 1) {
                $db->rollBack();
                continue;
            }

            // Read fresh wallet balance inside the transaction
            $stmt = $db->prepare("SELECT balance FROM om_cashback_wallet WHERE customer_id = ? FOR UPDATE");
            $stmt->execute([$customerId]);
            $currentBalance = (float)($stmt->fetchColumn() ?: 0);

            // Calcular quanto realmente expirar (pode ter usado parte)
            // Se saldo atual < amount, so expira o que ainda tem
            $amountToExpire = min($amount, max($currentBalance, 0));

            // Marcar credito original como expirado
            $stmt = $db->prepare("UPDATE om_cashback_transactions SET expired = 1 WHERE id = ?");
            $stmt->execute([$credit['id']]);

            if ($amountToExpire <= 0) {
                // Ja foi usado, apenas marcar como expirado
                $db->commit();
                continue;
            }

            // Atualizar wallet
            $stmt = $db->prepare("
                UPDATE om_cashback_wallet
                SET balance = GREATEST(balance - ?, 0),
                    total_expired = total_expired + ?
                WHERE customer_id = ?
            ");
            $stmt->execute([$amountToExpire, $amountToExpire, $customerId]);

            // Obter novo saldo
            $stmt = $db->prepare("SELECT balance FROM om_cashback_wallet WHERE customer_id = ?");
            $stmt->execute([$customerId]);
            $newBalance = (float)$stmt->fetchColumn();

            // Criar transacao de expiracao
            $stmt = $db->prepare("
                INSERT INTO om_cashback_transactions
                (customer_id, order_id, partner_id, type, amount, balance_after, description)
                VALUES (?, ?, ?, 'expired', ?, ?, ?)
            ");
            $description = "Cashback expirado (venceu em " . date('d/m/Y', strtotime($credit['expires_at'])) . ")";
            $stmt->execute([
                $customerId,
                $credit['order_id'],
                $credit['partner_id'],
                $amountToExpire,
                $newBalance,
                $description
            ]);

            $db->commit();

            $totalExpired++;
            $totalAmount += $amountToExpire;
            $customersAffected[$customerId] = true;

            echo "  [OK] Cliente #$customerId: R$ " . number_format($amountToExpire, 2) . " expirado\n";

        } catch (Exception $e) {
            $db->rollBack();
            echo "  [ERRO] ID #{$credit['id']}: " . $e->getMessage() . "\n";
        }
    }

    echo "\n[" . date('Y-m-d H:i:s') . "] Resumo:\n";
    echo "  - Transacoes expiradas: $totalExpired\n";
    echo "  - Valor total expirado: R$ " . number_format($totalAmount, 2) . "\n";
    echo "  - Clientes afetados: " . count($customersAffected) . "\n";

    // Notificar clientes sobre cashback prestes a expirar (proximos 3 dias)
    echo "\n[" . date('Y-m-d H:i:s') . "] Verificando cashback prestes a expirar...\n";

    $stmt = $db->prepare("
        SELECT
            ct.customer_id,
            SUM(ct.amount) as expiring_amount,
            MIN(ct.expires_at) as earliest_expiry
        FROM om_cashback_transactions ct
        WHERE ct.type = 'credit'
        AND ct.expired = 0
        AND ct.expires_at IS NOT NULL
        AND ct.expires_at BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '3 days'
        GROUP BY ct.customer_id
        HAVING SUM(ct.amount) > 0
    ");
    $stmt->execute();
    $expiringCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($expiringCustomers as $customer) {
        $amount = (float)$customer['expiring_amount'];
        $expireDate = date('d/m', strtotime($customer['earliest_expiry']));

        echo "  [AVISO] Cliente #{$customer['customer_id']}: R$ " . number_format($amount, 2) . " expira em $expireDate\n";

        // Criar notificacao (opcional - se tiver sistema de notificacoes)
        try {
            $stmt = $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, data, created_at)
                VALUES (?, 'customer', ?, ?, ?, NOW())
            ");
            $title = "Cashback expirando!";
            $body = "Voce tem R$ " . number_format($amount, 2) . " de cashback que expira em $expireDate. Use antes que expire!";
            $data = json_encode(['type' => 'cashback_expiring', 'amount' => $amount]);
            $stmt->execute([$customer['customer_id'], $title, $body, $data]);
        } catch (Exception $e) {
            // Tabela de notificacoes pode nao existir, ignorar
        }
    }

    echo "\n[" . date('Y-m-d H:i:s') . "] Cron finalizado com sucesso!\n";

} catch (Exception $e) {
    echo "[ERRO FATAL] " . $e->getMessage() . "\n";
    error_log("[expire-cashback] Erro: " . $e->getMessage());
    exit(1);
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    @unlink($lockFile);
}
