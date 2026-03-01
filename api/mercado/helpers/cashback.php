<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * CASHBACK HELPER
 * Funcoes para gerenciar cashback: creditar, debitar, verificar saldo
 * ══════════════════════════════════════════════════════════════════════════════
 */

/**
 * Obter configuracao de cashback para um parceiro
 * Retorna config especifica da loja ou config global
 */
function getCashbackConfig(PDO $db, ?int $partnerId = null): ?array {
    // Tentar config especifica do parceiro
    if ($partnerId) {
        $stmt = $db->prepare("
            SELECT * FROM om_cashback_config
            WHERE partner_id = ? AND status = '1'
        ");
        $stmt->execute([$partnerId]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($config) {
            return $config;
        }
    }

    // Fallback para config global
    $stmt = $db->prepare("
        SELECT * FROM om_cashback_config
        WHERE partner_id IS NULL AND status = '1'
        LIMIT 1
    ");
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Calcular cashback para um pedido
 */
function calculateCashback(PDO $db, int $partnerId, float $orderTotal): array {
    $config = getCashbackConfig($db, $partnerId);

    if (!$config) {
        return [
            'eligible' => false,
            'cashback_amount' => 0,
            'reason' => 'Cashback nao configurado'
        ];
    }

    // Verificar valor minimo
    if ($orderTotal < (float)$config['min_order_value']) {
        return [
            'eligible' => false,
            'cashback_amount' => 0,
            'reason' => 'Pedido abaixo do valor minimo (R$ ' . number_format($config['min_order_value'], 2, ',', '.') . ')',
            'min_order_value' => (float)$config['min_order_value']
        ];
    }

    // Calcular cashback
    $cashbackPercent = (float)$config['cashback_percent'];
    $maxCashback = (float)$config['max_cashback'];

    $cashbackAmount = $orderTotal * ($cashbackPercent / 100);

    // Aplicar limite maximo
    if ($maxCashback > 0 && $cashbackAmount > $maxCashback) {
        $cashbackAmount = $maxCashback;
    }

    return [
        'eligible' => true,
        'cashback_amount' => round($cashbackAmount, 2),
        'cashback_percent' => $cashbackPercent,
        'max_cashback' => $maxCashback,
        'valid_days' => (int)$config['valid_days'],
        'expires_at' => date('Y-m-d', strtotime('+' . $config['valid_days'] . ' days'))
    ];
}

/**
 * Creditar cashback na wallet do cliente apos pedido entregue
 */
function creditCashback(PDO $db, int $customerId, int $orderId, int $partnerId, float $orderTotal): array {
    // Calcular cashback antes da transacao (read-only)
    $calc = calculateCashback($db, $partnerId, $orderTotal);

    if (!$calc['eligible']) {
        return [
            'success' => false,
            'message' => $calc['reason']
        ];
    }

    $amount = $calc['cashback_amount'];
    $expiresAt = $calc['expires_at'];

    try {
        $db->beginTransaction();

        // Buscar nome da loja e numero do pedido (read-only, antes do lock)
        $stmt = $db->prepare("SELECT trade_name FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$partnerId]);
        $partnerName = $stmt->fetchColumn() ?: 'Loja';

        $stmt = $db->prepare("SELECT order_number FROM om_market_orders WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $orderNumber = $stmt->fetchColumn() ?: $orderId;

        // SECURITY: Idempotency via INSERT ON CONFLICT DO NOTHING
        // REQUIRES partial unique index: CREATE UNIQUE INDEX IF NOT EXISTS idx_cashback_tx_credit_per_order
        //   ON om_cashback_transactions(order_id) WHERE type='credit' AND expired=false;
        // Verify index exists at startup or migration — without it, duplicates silently pass
        $description = "Cashback do pedido #{$orderNumber} - {$partnerName}";

        // Defensive check: verify no existing credit for this order (belt-and-suspenders)
        $checkStmt = $db->prepare("SELECT id FROM om_cashback_transactions WHERE order_id = ? AND type = 'credit' AND expired = 0 LIMIT 1");
        $checkStmt->execute([$orderId]);
        if ($checkStmt->fetch()) {
            $db->rollBack();
            return ['success' => false, 'message' => 'Cashback ja creditado para este pedido'];
        }
        $stmt = $db->prepare("
            INSERT INTO om_cashback_transactions
            (customer_id, order_id, partner_id, type, amount, balance_after, description, expires_at)
            VALUES (?, ?, ?, 'credit', ?, 0, ?, ?)
            ON CONFLICT DO NOTHING
        ");
        $stmt->execute([$customerId, $orderId, $partnerId, $amount, $description, $expiresAt]);

        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            return [
                'success' => false,
                'message' => 'Cashback ja creditado para este pedido'
            ];
        }

        // Garantir que wallet existe e creditar (PostgreSQL syntax)
        $stmt = $db->prepare("
            INSERT INTO om_cashback_wallet (customer_id, balance, total_earned)
            VALUES (?, ?, ?)
            ON CONFLICT (customer_id) DO UPDATE SET
                balance = om_cashback_wallet.balance + EXCLUDED.balance,
                total_earned = om_cashback_wallet.total_earned + EXCLUDED.total_earned
        ");
        $stmt->execute([$customerId, $amount, $amount]);

        // Obter saldo atualizado e corrigir balance_after na transacao
        $stmt = $db->prepare("SELECT balance FROM om_cashback_wallet WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        $newBalance = (float)$stmt->fetchColumn();

        $stmt = $db->prepare("
            UPDATE om_cashback_transactions
            SET balance_after = ?
            WHERE order_id = ? AND type = 'credit' AND expired = 0
        ");
        $stmt->execute([$newBalance, $orderId]);

        // Atualizar pedido com cashback ganho
        $stmt = $db->prepare("
            UPDATE om_market_orders SET cashback_earned = ? WHERE order_id = ?
        ");
        $stmt->execute([$amount, $orderId]);

        $db->commit();

        return [
            'success' => true,
            'amount' => $amount,
            'amount_formatted' => 'R$ ' . number_format($amount, 2, ',', '.'),
            'new_balance' => $newBalance,
            'expires_at' => $expiresAt,
            'message' => "Voce ganhou R$ " . number_format($amount, 2, ',', '.') . " de cashback!"
        ];

    } catch (Exception $e) {
        $db->rollBack();
        error_log("[creditCashback] Erro: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Erro ao creditar cashback'
        ];
    }
}

/**
 * Obter saldo disponivel de cashback do cliente
 */
function getCashbackBalance(PDO $db, int $customerId): float {
    $stmt = $db->prepare("
        SELECT COALESCE(balance, 0) as balance
        FROM om_cashback_wallet
        WHERE customer_id = ?
    ");
    $stmt->execute([$customerId]);
    return (float)$stmt->fetchColumn();
}

/**
 * Debitar cashback da wallet do cliente (uso no checkout)
 */
function debitCashback(PDO $db, int $customerId, int $orderId, int $partnerId, float $amount): array {
    if ($amount <= 0) {
        return ['success' => false, 'message' => 'Valor invalido'];
    }

    try {
        $db->beginTransaction();

        // Verificar saldo com lock FOR UPDATE para evitar race condition
        $stmtBal = $db->prepare("SELECT balance FROM om_cashback_wallet WHERE customer_id = ? FOR UPDATE");
        $stmtBal->execute([$customerId]);
        $balance = (float)($stmtBal->fetchColumn() ?: 0);

        if ($amount > $balance) {
            $db->rollBack();
            return [
                'success' => false,
                'message' => 'Saldo insuficiente. Disponivel: R$ ' . number_format($balance, 2, ',', '.'),
                'available' => $balance
            ];
        }

        // Buscar numero do pedido (read-only)
        $stmt = $db->prepare("SELECT order_number FROM om_market_orders WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $orderNumber = $stmt->fetchColumn() ?: $orderId;

        // SECURITY: Idempotency via INSERT ON CONFLICT DO NOTHING
        // Uses partial unique index idx_cashback_tx_debit_per_order (order_id WHERE type='debit' AND expired=false)
        $description = "Usado no pedido #{$orderNumber}";
        $stmt = $db->prepare("
            INSERT INTO om_cashback_transactions
            (customer_id, order_id, partner_id, type, amount, balance_after, description)
            VALUES (?, ?, ?, 'debit', ?, 0, ?)
            ON CONFLICT DO NOTHING
        ");
        $stmt->execute([$customerId, $orderId, $partnerId, $amount, $description]);

        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            return ['success' => false, 'message' => 'Cashback ja debitado para este pedido'];
        }

        // Debitar da wallet
        $stmt = $db->prepare("
            UPDATE om_cashback_wallet
            SET balance = balance - ?, total_used = total_used + ?
            WHERE customer_id = ? AND balance >= ?
        ");
        $stmt->execute([$amount, $amount, $customerId, $amount]);

        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            return ['success' => false, 'message' => 'Saldo insuficiente'];
        }

        // Obter novo saldo e corrigir balance_after na transacao
        $stmt = $db->prepare("SELECT balance FROM om_cashback_wallet WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        $newBalance = (float)$stmt->fetchColumn();

        $stmt = $db->prepare("
            UPDATE om_cashback_transactions
            SET balance_after = ?
            WHERE order_id = ? AND type = 'debit' AND expired = 0
        ");
        $stmt->execute([$newBalance, $orderId]);

        $db->commit();

        return [
            'success' => true,
            'amount' => $amount,
            'new_balance' => $newBalance,
            'message' => 'Cashback aplicado com sucesso!'
        ];

    } catch (Exception $e) {
        $db->rollBack();
        error_log("[debitCashback] Erro: " . $e->getMessage());
        return ['success' => false, 'message' => 'Erro ao debitar cashback'];
    }
}

/**
 * Estornar cashback (cancelamento de pedido)
 */
function refundCashback(PDO $db, int $orderId): array {
    // Buscar apenas transacoes ATIVAS do pedido (ignorar ja expiradas)
    $stmt = $db->prepare("
        SELECT * FROM om_cashback_transactions
        WHERE order_id = ? AND expired = 0 AND type IN ('credit', 'debit')
    ");
    $stmt->execute([$orderId]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($transactions)) {
        return ['success' => true, 'message' => 'Nenhum cashback para estornar'];
    }

    try {
        // Support being called inside an existing transaction (e.g. from cancelar.php, recusar.php)
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        $customerId = $transactions[0]['customer_id'];

        // SECURITY: Idempotency via INSERT ON CONFLICT DO NOTHING
        // Uses partial unique index idx_cashback_tx_refund_per_order (order_id WHERE type='expired' AND amount=0)
        // Insert refund marker FIRST — if it already exists, another refund already ran
        $stmt = $db->prepare("
            INSERT INTO om_cashback_transactions
            (customer_id, order_id, type, amount, balance_after, description)
            VALUES (?, ?, 'expired', 0, 0, 'Pedido cancelado - cashback estornado')
            ON CONFLICT DO NOTHING
        ");
        $stmt->execute([$customerId, $orderId]);

        if ($stmt->rowCount() === 0) {
            if ($ownTransaction) $db->rollBack();
            return ['success' => false, 'message' => 'Cashback ja estornado para este pedido'];
        }

        foreach ($transactions as $t) {
            $txCustomerId = $t['customer_id'];
            $amount = (float)$t['amount'];

            if ($t['type'] === 'credit') {
                // Estornar credito (remover da wallet)
                $stmt = $db->prepare("
                    UPDATE om_cashback_wallet
                    SET balance = GREATEST(balance - ?, 0), total_earned = GREATEST(total_earned - ?, 0)
                    WHERE customer_id = ?
                ");
                $stmt->execute([$amount, $amount, $txCustomerId]);

            } elseif ($t['type'] === 'debit') {
                // Estornar debito (devolver para wallet)
                $stmt = $db->prepare("
                    UPDATE om_cashback_wallet
                    SET balance = balance + ?, total_used = GREATEST(total_used - ?, 0)
                    WHERE customer_id = ?
                ");
                $stmt->execute([$amount, $amount, $txCustomerId]);
            }

            // Marcar transacao como expirada (soft delete)
            $stmt = $db->prepare("UPDATE om_cashback_transactions SET expired = 1 WHERE id = ?");
            $stmt->execute([$t['id']]);
        }

        // Atualizar balance_after no registro de estorno
        $stmt = $db->prepare("SELECT balance FROM om_cashback_wallet WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        $newBalance = (float)$stmt->fetchColumn();

        $stmt = $db->prepare("
            UPDATE om_cashback_transactions
            SET balance_after = ?
            WHERE order_id = ? AND type = 'expired' AND amount = 0
        ");
        $stmt->execute([$newBalance, $orderId]);

        if ($ownTransaction) $db->commit();

        return ['success' => true, 'message' => 'Cashback estornado'];

    } catch (Exception $e) {
        if ($ownTransaction && $db->inTransaction()) $db->rollBack();
        error_log("[refundCashback] Erro: " . $e->getMessage());
        return ['success' => false, 'message' => 'Erro ao estornar cashback'];
    }
}

/**
 * Preview de cashback para exibir no carrinho/checkout
 */
function previewCashback(PDO $db, int $partnerId, float $orderTotal): array {
    $calc = calculateCashback($db, $partnerId, $orderTotal);

    if (!$calc['eligible']) {
        return [
            'will_earn' => false,
            'amount' => 0,
            'amount_formatted' => 'R$ 0,00',
            'message' => $calc['reason'] ?? 'Cashback nao disponivel',
            'min_order_value' => $calc['min_order_value'] ?? 0
        ];
    }

    return [
        'will_earn' => true,
        'amount' => $calc['cashback_amount'],
        'amount_formatted' => 'R$ ' . number_format($calc['cashback_amount'], 2, ',', '.'),
        'percent' => $calc['cashback_percent'],
        'expires_at' => $calc['expires_at'],
        'message' => "Ganhe R$ " . number_format($calc['cashback_amount'], 2, ',', '.') . " de cashback!"
    ];
}
