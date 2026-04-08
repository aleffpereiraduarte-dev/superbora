<?php
/**
 * GET/POST /api/mercado/customer/cashback-transfer.php
 * Cashback transfer to PIX key
 *
 * GET:                 Transfer config + saved PIX keys
 * GET action=history:  Transfer history
 * POST action=save_pix_key: Save/add customer PIX key
 * POST action=delete_pix_key: Delete a saved PIX key
 * POST action=transfer: Request cashback transfer to PIX
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . '/includes/classes/EfiClient.php';
require_once __DIR__ . '/../helpers/notify.php';
require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';

setCorsHeaders();

// Transfer config constants
define('MIN_TRANSFER', 0.01);    // Open: any amount, even cents (R$ 0,01)
define('MAX_TRANSFER', 5000.00); // Hard cap for fraud prevention
define('TRANSFER_FEE_PERCENT', 0); // No fee for now — can enable later
define('TRANSFER_FEE_FIXED', 0.00);
define('MAX_PIX_KEYS', 3);
define('MAX_TRANSFERS_PER_DAY', 3);

/**
 * Read the customer's available wallet balance from the cashback ledger.
 * Mirrors what /customer/cashback.php returns, so the carteira screen and
 * the transfer action always agree on the balance. This is the SOURCE OF
 * TRUTH — never read om_cashback_wallet.balance directly.
 */
function readWalletBalanceFromLedger(PDO $db, int $customerId): float {
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN type IN ('earned','bonus') AND status = 'available' THEN amount ELSE 0 END), 0)
              - COALESCE(SUM(CASE WHEN type = 'used' THEN amount ELSE 0 END), 0) AS available
        FROM om_cashback
        WHERE customer_id = ?
    ");
    $stmt->execute([$customerId]);
    return round(max(0, (float)($stmt->fetchColumn() ?: 0)), 2);
}

/**
 * Resolve a saved PIX key to a SuperBora customer.
 * Returns ['customer_id', 'name'] when the key belongs to one of OUR users,
 * or null when it's an external key (then we route via EFI/external PIX).
 *
 * Mirrors localCustomerLookup() in pix-key-validate.php but uses the stored
 * key_type+key_value shape from om_customer_pix_keys.
 */
function findInternalRecipient(PDO $db, string $keyType, string $keyValue): ?array {
    try {
        if ($keyType === 'cpf') {
            $digits = preg_replace('/\D/', '', $keyValue);
            $stmt = $db->prepare("SELECT customer_id, name, email, phone FROM om_customers WHERE cpf = ? AND is_active = '1' LIMIT 1");
            $stmt->execute([$digits]);
        } elseif ($keyType === 'email') {
            $stmt = $db->prepare("SELECT customer_id, name, email, phone FROM om_customers WHERE LOWER(email) = LOWER(?) AND is_active = '1' LIMIT 1");
            $stmt->execute([trim($keyValue)]);
        } elseif ($keyType === 'telefone' || $keyType === 'phone') {
            $bare = preg_replace('/\D/', '', $keyValue);
            if (substr($bare, 0, 2) === '55') $bare = substr($bare, 2);
            $stmt = $db->prepare("
                SELECT customer_id, name, email, phone FROM om_customers
                WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), '-', ''), '(', ''), ')', '') LIKE ?
                  AND is_active = '1' LIMIT 1
            ");
            $stmt->execute(['%' . $bare]);
        } else {
            return null;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Exception $e) {
        error_log('[cashback-transfer] findInternalRecipient: ' . $e->getMessage());
        return null;
    }
}

try {
    $db = getDB();
    $customerId = requireCustomerAuth();

    // Ensure tables exist
    ensureTables($db);

    $method = $_SERVER['REQUEST_METHOD'];

    // ======================== GET ========================
    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

        // --- Transfer history ---
        if ($action === 'history') {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(50, max(5, (int)($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;

            $stmt = dbQuery($db, "
                SELECT id, amount, fee, net_amount, pix_key_type, pix_key_value,
                       status, transaction_id, error_message,
                       requested_at, processed_at, completed_at
                FROM om_cashback_transfers
                WHERE customer_id = ?
                ORDER BY requested_at DESC
                LIMIT ? OFFSET ?
            ", [$customerId, $limit, $offset]);
            $transfers = $stmt->fetchAll();

            // Mask PIX keys in history
            foreach ($transfers as &$t) {
                $t['pix_key_masked'] = maskPixKey($t['pix_key_value'], $t['pix_key_type']);
                $t['amount'] = round((float)$t['amount'], 2);
                $t['fee'] = round((float)$t['fee'], 2);
                $t['net_amount'] = round((float)$t['net_amount'], 2);
                unset($t['pix_key_value']); // Don't expose full key in history
            }

            $stmtCount = dbQuery($db, "SELECT COUNT(*) FROM om_cashback_transfers WHERE customer_id = ?", [$customerId]);
            $total = (int)$stmtCount->fetchColumn();

            response(true, [
                'transfers' => $transfers,
                'total' => $total,
                'page' => $page,
                'pages' => max(1, ceil($total / $limit)),
            ], "Historico de transferencias");
        }

        // --- Default: config + saved keys ---
        $stmt = dbQuery($db, "
            SELECT id, key_type, key_value, is_primary, created_at
            FROM om_customer_pix_keys
            WHERE customer_id = ? AND deleted_at IS NULL
            ORDER BY is_primary DESC, created_at ASC
        ", [$customerId]);
        $keys = $stmt->fetchAll();

        $formattedKeys = [];
        foreach ($keys as $k) {
            $formattedKeys[] = [
                'id' => (int)$k['id'],
                'type' => $k['key_type'],
                'key_masked' => maskPixKey($k['key_value'], $k['key_type']),
                'key_preview' => getPixKeyPreview($k['key_value'], $k['key_type']),
                'is_primary' => (bool)$k['is_primary'],
                'created_at' => $k['created_at'],
            ];
        }

        // Get cashback balance from the LEDGER (same source as cashback.php).
        // SECURITY: never read om_cashback_wallet.balance — it drifts and can
        // show inflated balances that the user can't actually spend, leading
        // to "Saldo insuficiente" errors after they hit the transfer button.
        $balance = readWalletBalanceFromLedger($db, $customerId);

        // Recent transfers count (today)
        $stmtToday = dbQuery($db, "
            SELECT COUNT(*) FROM om_cashback_transfers
            WHERE customer_id = ? AND requested_at >= CURRENT_DATE
        ", [$customerId]);
        $todayCount = (int)$stmtToday->fetchColumn();

        response(true, [
            'config' => [
                'min_transfer' => MIN_TRANSFER,
                'max_transfer' => MAX_TRANSFER,
                'fee_percent' => TRANSFER_FEE_PERCENT,
                'fee_fixed' => TRANSFER_FEE_FIXED,
                'max_keys' => MAX_PIX_KEYS,
                'max_daily_transfers' => MAX_TRANSFERS_PER_DAY,
                'transfers_today' => $todayCount,
            ],
            'pix_keys' => $formattedKeys,
            'balance' => $balance,
            'pix_key_types' => [
                ['value' => 'cpf', 'label' => 'CPF'],
                ['value' => 'cnpj', 'label' => 'CNPJ'],
                ['value' => 'email', 'label' => 'E-mail'],
                ['value' => 'telefone', 'label' => 'Telefone'],
                ['value' => 'aleatoria', 'label' => 'Chave Aleatoria'],
            ],
        ], "Config de transferencia");
    }

    // ======================== POST ========================
    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? '';

        // --- Save PIX key ---
        if ($action === 'save_pix_key') {
            $type = trim($input['type'] ?? '');
            $key = trim($input['key'] ?? '');

            // Normalize type
            $allowedTypes = ['cpf', 'cnpj', 'email', 'telefone', 'aleatoria'];
            if (!in_array($type, $allowedTypes, true)) {
                response(false, null, "Tipo de chave PIX invalido", 400);
            }

            if (empty($key)) {
                response(false, null, "Chave PIX obrigatoria", 400);
            }

            // Normalize internal type for validation
            $internalType = $type;
            if ($type === 'telefone') $internalType = 'phone';
            if ($type === 'aleatoria') $internalType = 'random';

            if (!validatePixKey($key, $internalType)) {
                response(false, null, "Formato da chave PIX invalido para o tipo selecionado", 400);
            }

            $key = sanitizeOutput($key);

            // Check max keys
            $stmtCount = dbQuery($db, "
                SELECT COUNT(*) FROM om_customer_pix_keys
                WHERE customer_id = ? AND deleted_at IS NULL
            ", [$customerId]);
            $keyCount = (int)$stmtCount->fetchColumn();

            if ($keyCount >= MAX_PIX_KEYS) {
                response(false, null, "Limite de " . MAX_PIX_KEYS . " chaves PIX atingido. Remova uma antes de adicionar.", 400);
            }

            // Check for duplicate key
            $stmtDup = dbQuery($db, "
                SELECT id FROM om_customer_pix_keys
                WHERE customer_id = ? AND key_type = ? AND key_value = ? AND deleted_at IS NULL
            ", [$customerId, $type, $key]);
            if ($stmtDup->fetch()) {
                response(false, null, "Esta chave PIX ja esta cadastrada", 400);
            }

            // First key is primary
            $isPrimary = ($keyCount === 0);

            $stmt = dbQuery($db, "
                INSERT INTO om_customer_pix_keys (customer_id, key_type, key_value, is_primary, created_at)
                VALUES (?, ?, ?, ?, NOW())
                RETURNING id
            ", [$customerId, $type, $key, $isPrimary ? 1 : 0]);
            $newId = (int)$stmt->fetchColumn();

            response(true, [
                'id' => $newId,
                'type' => $type,
                'key_masked' => maskPixKey($key, $type),
                'key_preview' => getPixKeyPreview($key, $type),
                'is_primary' => $isPrimary,
            ], "Chave PIX salva com sucesso");
        }

        // --- Delete PIX key ---
        if ($action === 'delete_pix_key') {
            $keyId = (int)($input['key_id'] ?? 0);
            if (!$keyId) {
                response(false, null, "ID da chave obrigatorio", 400);
            }

            // Verify ownership
            $stmt = dbQuery($db, "
                SELECT id, is_primary FROM om_customer_pix_keys
                WHERE id = ? AND customer_id = ? AND deleted_at IS NULL
            ", [$keyId, $customerId]);
            $existingKey = $stmt->fetch();

            if (!$existingKey) {
                response(false, null, "Chave PIX nao encontrada", 404);
            }

            // Soft delete
            dbQuery($db, "
                UPDATE om_customer_pix_keys SET deleted_at = NOW() WHERE id = ?
            ", [$keyId]);

            // If deleted key was primary, make the next one primary
            if ($existingKey['is_primary']) {
                $stmtNext = dbQuery($db, "
                    SELECT id FROM om_customer_pix_keys
                    WHERE customer_id = ? AND deleted_at IS NULL
                    ORDER BY created_at ASC LIMIT 1
                ", [$customerId]);
                $nextKey = $stmtNext->fetch();
                if ($nextKey) {
                    dbQuery($db, "UPDATE om_customer_pix_keys SET is_primary = 1 WHERE id = ?", [$nextKey['id']]);
                }
            }

            response(true, null, "Chave PIX removida");
        }

        // --- Transfer cashback to PIX ---
        if ($action === 'transfer') {
            $amount = round((float)($input['amount'] ?? 0), 2);
            $pixKeyId = (int)($input['pix_key_id'] ?? 0);

            // Validate amount
            if ($amount < MIN_TRANSFER) {
                response(false, null, "Valor minimo para transferencia: R$ " . number_format(MIN_TRANSFER, 2, ',', '.'), 400);
            }
            if ($amount > MAX_TRANSFER) {
                response(false, null, "Valor maximo para transferencia: R$ " . number_format(MAX_TRANSFER, 2, ',', '.'), 400);
            }

            // Rate limiting: max transfers per day
            $stmtDaily = dbQuery($db, "
                SELECT COUNT(*) FROM om_cashback_transfers
                WHERE customer_id = ? AND requested_at >= CURRENT_DATE
            ", [$customerId]);
            if ((int)$stmtDaily->fetchColumn() >= MAX_TRANSFERS_PER_DAY) {
                response(false, null, "Limite de " . MAX_TRANSFERS_PER_DAY . " transferencias por dia atingido.", 429);
            }

            // Validate PIX key
            if (!$pixKeyId) {
                response(false, null, "Selecione uma chave PIX", 400);
            }

            $stmtKey = dbQuery($db, "
                SELECT id, key_type, key_value FROM om_customer_pix_keys
                WHERE id = ? AND customer_id = ? AND deleted_at IS NULL
            ", [$pixKeyId, $customerId]);
            $pixKeyData = $stmtKey->fetch();

            if (!$pixKeyData) {
                response(false, null, "Chave PIX nao encontrada", 404);
            }

            // ─────────────────────────────────────────────────────────────
            // SUPERBORA-TO-SUPERBORA detection — when the destination key
            // belongs to another customer in our database, we skip EFI/PIX
            // entirely and do an internal wallet transfer. Instant + free.
            // ─────────────────────────────────────────────────────────────
            $internalRecipient = findInternalRecipient($db, $pixKeyData['key_type'], $pixKeyData['key_value']);
            $isInternalTransfer = $internalRecipient && (int)$internalRecipient['customer_id'] !== $customerId;

            // Block self-transfer (when the key resolves to the sender themselves)
            if ($internalRecipient && (int)$internalRecipient['customer_id'] === $customerId) {
                response(false, null, "Voce nao pode transferir pra voce mesmo", 400);
            }

            // Calculate fee — internal transfers are FREE, external uses configured fee
            $fee = $isInternalTransfer
                ? 0
                : round(($amount * TRANSFER_FEE_PERCENT / 100) + TRANSFER_FEE_FIXED, 2);
            $netAmount = round($amount - $fee, 2);

            if ($netAmount <= 0) {
                response(false, null, "Valor liquido deve ser positivo apos taxas", 400);
            }

            // Normalize PIX key type for EFI
            $efiType = $pixKeyData['key_type'];
            if ($efiType === 'telefone') $efiType = 'phone';
            if ($efiType === 'aleatoria') $efiType = 'random';

            // Begin transaction - debit via cashback ledger (single source of truth)
            $db->beginTransaction();

            try {
                // Lock the customer's ledger rows so a parallel transfer can't double-spend
                $db->prepare("SELECT id FROM om_cashback WHERE customer_id = ? FOR UPDATE")
                    ->execute([$customerId]);

                // Compute current available balance from the ledger (NOT om_cashback_wallet)
                $balance = readWalletBalanceFromLedger($db, $customerId);

                if ($amount > $balance) {
                    $db->rollBack();
                    response(false, null, "Saldo insuficiente. Disponivel: R$ " . number_format($balance, 2, ',', '.'), 400);
                }

                // Debit by inserting a 'used' row in the ledger (mirrors confirmar-entrega.php)
                $debitDesc = "Transferencia PIX - " . maskPixKey($pixKeyData['key_value'], $pixKeyData['key_type']);
                $db->prepare("
                    INSERT INTO om_cashback (customer_id, type, amount, status, description, order_id, created_at)
                    VALUES (?, 'used', ?, 'used', ?, NULL, NOW())
                ")->execute([$customerId, $amount, $debitDesc]);

                // Recompute the new balance after the debit
                $newBalance = readWalletBalanceFromLedger($db, $customerId);

                // BACKWARDS COMPAT: also keep the legacy om_cashback_wallet aggregate
                // in sync, since some old reports/screens might still consult it.
                $db->prepare("
                    INSERT INTO om_cashback_wallet (customer_id, balance, total_earned, total_used, created_at, updated_at)
                    VALUES (?, ?, 0, ?, NOW(), NOW())
                    ON CONFLICT (customer_id) DO UPDATE
                       SET balance = EXCLUDED.balance,
                           total_used = om_cashback_wallet.total_used + ?,
                           updated_at = NOW()
                ")->execute([$customerId, $newBalance, $amount, $amount]);

                // Record cashback transaction (debit)
                $transferDesc = "Transferencia PIX - " . maskPixKey($pixKeyData['key_value'], $pixKeyData['key_type']);
                $stmtTx = $db->prepare("
                    INSERT INTO om_cashback_transactions
                    (customer_id, type, amount, balance_after, description)
                    VALUES (?, 'debit', ?, ?, ?)
                ");
                $stmtTx->execute([$customerId, $amount, $newBalance, $transferDesc]);

                // Create transfer record
                $stmtTransfer = $db->prepare("
                    INSERT INTO om_cashback_transfers
                    (customer_id, amount, fee, net_amount, pix_key_type, pix_key_value, status, requested_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
                    RETURNING id
                ");
                $stmtTransfer->execute([
                    $customerId, $amount, $fee, $netAmount,
                    $pixKeyData['key_type'], $pixKeyData['key_value']
                ]);
                $transferId = (int)$stmtTransfer->fetchColumn();

                $db->commit();

            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log("[cashback-transfer] DB error: " . $e->getMessage());
                response(false, null, "Erro ao processar transferencia", 500);
            }

            // ═════════════════════════════════════════════════════════════
            // INTERNAL TRANSFER FAST PATH — when recipient is a SuperBora
            // customer, credit their wallet directly and skip EFI entirely.
            // ═════════════════════════════════════════════════════════════
            if ($isInternalTransfer) {
                $recipientId = (int)$internalRecipient['customer_id'];
                $recipientName = $internalRecipient['name'] ?: 'Cliente SuperBora';

                // Get sender name for the recipient notification
                $senderStmt = $db->prepare("SELECT name FROM om_customers WHERE customer_id = ?");
                $senderStmt->execute([$customerId]);
                $senderName = $senderStmt->fetchColumn() ?: 'Cliente SuperBora';

                $internalTxId = 'sb_int_' . $customerId . '_' . $recipientId . '_' . $transferId . '_' . bin2hex(random_bytes(3));

                try {
                    $db->beginTransaction();

                    // Lock the recipient's ledger rows so a parallel credit is serialized
                    $db->prepare("SELECT id FROM om_cashback WHERE customer_id = ? FOR UPDATE")
                        ->execute([$recipientId]);

                    // 1. Credit recipient via the cashback ledger (single source of truth).
                    //    'bonus' rows count as available balance — same path used by referrals,
                    //    promotions, and the boleto refund flow.
                    $db->prepare("
                        INSERT INTO om_cashback (customer_id, type, amount, status, description, order_id, created_at)
                        VALUES (?, 'bonus', ?, 'available', ?, NULL, NOW())
                    ")->execute([
                        $recipientId,
                        $amount,
                        "Recebido via PIX SuperBora de " . $senderName,
                    ]);

                    // 2. Recompute recipient's balance for the response/notifications
                    $recipientNewBalance = readWalletBalanceFromLedger($db, $recipientId);

                    // 3. BACKWARDS COMPAT: keep om_cashback_wallet aggregate in sync
                    $db->prepare("
                        INSERT INTO om_cashback_wallet (customer_id, balance, total_earned, total_used, created_at, updated_at)
                        VALUES (?, ?, ?, 0, NOW(), NOW())
                        ON CONFLICT (customer_id) DO UPDATE
                           SET balance = EXCLUDED.balance,
                               total_earned = om_cashback_wallet.total_earned + ?,
                               updated_at = NOW()
                    ")->execute([$recipientId, $recipientNewBalance, $amount, $amount]);

                    // 4. Mark the transfer record as completed (not pending)
                    $db->prepare("
                        UPDATE om_cashback_transfers
                        SET status = 'completed',
                            transaction_id = ?,
                            processed_at = NOW(),
                            completed_at = NOW(),
                            error_message = NULL
                        WHERE id = ?
                    ")->execute([$internalTxId, $transferId]);

                    $db->commit();
                } catch (Exception $internalErr) {
                    if ($db->inTransaction()) $db->rollBack();
                    error_log('[cashback-transfer] internal flow failed for transfer ' . $transferId . ': ' . $internalErr->getMessage());

                    // Refund the sender — credit the ledger back (we debited at start of transfer)
                    try {
                        $db->beginTransaction();
                        // Insert a 'bonus' row to offset the 'used' debit
                        $db->prepare("
                            INSERT INTO om_cashback (customer_id, type, amount, status, description, order_id, created_at)
                            VALUES (?, 'bonus', ?, 'available', ?, NULL, NOW())
                        ")->execute([
                            $customerId,
                            $amount,
                            "Estorno - transferencia interna falhou",
                        ]);
                        // Keep wallet aggregate in sync
                        $db->prepare("
                            UPDATE om_cashback_wallet
                            SET balance = balance + ?, total_used = GREATEST(total_used - ?, 0), updated_at = NOW()
                            WHERE customer_id = ?
                        ")->execute([$amount, $amount, $customerId]);
                        $db->prepare("
                            INSERT INTO om_cashback_transactions (customer_id, type, amount, balance_after, description)
                            VALUES (?, 'credit', ?, ?, ?)
                        ")->execute([$customerId, $amount, round((float)$balance, 2), "Estorno - transferencia SuperBora interna falhou"]);
                        $db->prepare("
                            UPDATE om_cashback_transfers
                            SET status = 'failed', error_message = ?, processed_at = NOW()
                            WHERE id = ?
                        ")->execute([substr($internalErr->getMessage(), 0, 200), $transferId]);
                        $db->commit();
                    } catch (Exception $refundErr) {
                        if ($db->inTransaction()) $db->rollBack();
                        error_log('[cashback-transfer] CRITICAL: internal refund failed for ' . $transferId . ': ' . $refundErr->getMessage());
                    }
                    response(false, null, 'Erro ao processar transferencia interna. Saldo devolvido.', 500);
                }

                // ─── Best-effort notifications (do NOT fail the transfer) ───
                $amountStr = 'R$ ' . number_format($amount, 2, ',', '.');

                $recipientTitle = "PIX recebido: {$amountStr} 💚";
                $recipientBody  = "Voce recebeu {$amountStr} de {$senderName} via PIX SuperBora";
                $senderTitle    = "Transferencia enviada ✅";
                $senderBody     = "{$amountStr} enviado pra {$recipientName} via PIX SuperBora";

                // 1. In-app notification feed (so the bell list shows them)
                try {
                    $notifData = json_encode([
                        'transfer_id'  => $transferId,
                        'amount'       => $amount,
                        'sender_id'    => $customerId,
                        'sender_name'  => $senderName,
                    ]);
                    $db->prepare("
                        INSERT INTO om_market_notifications
                            (recipient_type, recipient_id, title, message, data, type, related_type, related_id, action_url, sent_at)
                        VALUES ('customer', ?, ?, ?, ?::jsonb, 'pix_received', 'transfer', ?, '/carteira', NOW())
                    ")->execute([$recipientId, $recipientTitle, $recipientBody, $notifData, $transferId]);

                    $notifData2 = json_encode([
                        'transfer_id'    => $transferId,
                        'amount'         => $amount,
                        'recipient_id'   => $recipientId,
                        'recipient_name' => $recipientName,
                    ]);
                    $db->prepare("
                        INSERT INTO om_market_notifications
                            (recipient_type, recipient_id, title, message, data, type, related_type, related_id, action_url, sent_at)
                        VALUES ('customer', ?, ?, ?, ?::jsonb, 'pix_sent', 'transfer', ?, '/carteira', NOW())
                    ")->execute([$customerId, $senderTitle, $senderBody, $notifData2, $transferId]);
                } catch (Exception $e) {
                    error_log('[cashback-transfer] in-app notification insert failed: ' . $e->getMessage());
                }

                // 2. Push to recipient via Expo
                try {
                    notifyCustomer(
                        $db,
                        $recipientId,
                        $recipientTitle,
                        $recipientBody,
                        '/carteira',
                        [
                            'type'           => 'pix_received',
                            'amount'         => $amount,
                            'sender_name'    => $senderName,
                            'sender_id'      => $customerId,
                            'transfer_id'    => $transferId,
                            'route'          => '/carteira',
                        ]
                    );
                } catch (Exception $e) {
                    error_log('[cashback-transfer] recipient push failed: ' . $e->getMessage());
                }

                // 3. Push to sender via Expo (confirmation)
                try {
                    notifyCustomer(
                        $db,
                        $customerId,
                        $senderTitle,
                        $senderBody,
                        '/carteira',
                        [
                            'type'           => 'pix_sent',
                            'amount'         => $amount,
                            'recipient_name' => $recipientName,
                            'recipient_id'   => $recipientId,
                            'transfer_id'    => $transferId,
                            'route'          => '/carteira',
                        ]
                    );
                } catch (Exception $e) {
                    error_log('[cashback-transfer] sender push failed: ' . $e->getMessage());
                }

                // WebSocket real-time updates for both sides
                try {
                    if (function_exists('wsBroadcastToCustomer')) {
                        wsBroadcastToCustomer($recipientId, 'pix_received', [
                            'amount'      => $amount,
                            'sender_name' => $senderName,
                            'sender_id'   => $customerId,
                            'new_balance' => $recipientNewBalance,
                            'transfer_id' => $transferId,
                        ]);
                        wsBroadcastToCustomer($customerId, 'pix_sent', [
                            'amount'         => $amount,
                            'recipient_name' => $recipientName,
                            'recipient_id'   => $recipientId,
                            'new_balance'    => $newBalance,
                            'transfer_id'    => $transferId,
                            'status'         => 'completed',
                        ]);
                    }
                } catch (Exception $e) {
                    error_log('[cashback-transfer] ws broadcast failed: ' . $e->getMessage());
                }

                response(true, [
                    'transfer_id'   => $transferId,
                    'status'        => 'completed',
                    'amount'        => $amount,
                    'fee'           => 0,
                    'net_amount'    => $amount,
                    'transaction_id' => $internalTxId,
                    'estimated_time' => 'Instantaneo',
                    'new_balance'   => $newBalance,
                    'route'         => 'internal',
                    'recipient_name' => $recipientName,
                ]);
            }

            // Try to execute PIX payment via EFI (outside transaction)
            $transferStatus = 'pending';
            $transactionId = null;
            $errorMessage = null;
            $estimatedTime = 'Ate 1 hora util';

            try {
                $efi = new EfiClient();
                if ($efi->isConfigured()) {
                    $correlationId = 'sb_cb_' . $customerId . '_' . $transferId . '_' . bin2hex(random_bytes(4));

                    $result = $efi->sendPix(
                        $netAmount,
                        $pixKeyData['key_value'],
                        $efiType,
                        "Cashback SuperBora - Cliente #$customerId",
                        $correlationId
                    );

                    if ($result['success']) {
                        $transactionId = $result['e2e_id'] ?? $result['transfer_id'] ?? $correlationId;
                        $transferStatus = 'processing';
                        $estimatedTime = 'Alguns minutos';

                        dbQuery($db, "
                            UPDATE om_cashback_transfers
                            SET status = 'processing', transaction_id = ?, processed_at = NOW()
                            WHERE id = ?
                        ", [$transactionId, $transferId]);
                    } else {
                        throw new \RuntimeException($result['error'] ?? 'EFI send failed');
                    }
                } else {
                    // EFI not configured — leave as pending for manual processing
                    error_log("[cashback-transfer] EFI not configured, transfer $transferId left as pending");
                }
            } catch (\Exception $efiErr) {
                // EFI failed — refund cashback
                error_log("[cashback-transfer] EFI error for transfer $transferId: " . $efiErr->getMessage());
                $errorMessage = 'Erro ao processar PIX. Saldo devolvido.';
                $transferStatus = 'failed';

                try {
                    $db->beginTransaction();

                    // Refund via cashback ledger (single source of truth)
                    $db->prepare("
                        INSERT INTO om_cashback (customer_id, type, amount, status, description, order_id, created_at)
                        VALUES (?, 'bonus', ?, 'available', ?, NULL, NOW())
                    ")->execute([
                        $customerId,
                        $amount,
                        "Estorno - PIX externo falhou (transfer #{$transferId})",
                    ]);

                    $refundedBalance = readWalletBalanceFromLedger($db, $customerId);

                    // Keep aggregate in sync
                    $db->prepare("
                        UPDATE om_cashback_wallet
                        SET balance = balance + ?, total_used = GREATEST(total_used - ?, 0), updated_at = NOW()
                        WHERE customer_id = ?
                    ")->execute([$amount, $amount, $customerId]);

                    // Record refund transaction
                    $db->prepare("
                        INSERT INTO om_cashback_transactions
                        (customer_id, type, amount, balance_after, description)
                        VALUES (?, 'credit', ?, ?, ?)
                    ")->execute([$customerId, $amount, $refundedBalance, "Estorno - transferencia PIX falhou"]);

                    // Update transfer record
                    $db->prepare("
                        UPDATE om_cashback_transfers
                        SET status = 'failed', error_message = ?, processed_at = NOW()
                        WHERE id = ?
                    ")->execute([$efiErr->getMessage(), $transferId]);

                    $db->commit();
                    $newBalance = $refundedBalance;
                } catch (Exception $refundErr) {
                    if ($db->inTransaction()) $db->rollBack();
                    error_log("[cashback-transfer] CRITICAL: refund failed for transfer $transferId: " . $refundErr->getMessage());
                    // Transfer is still marked pending — admin needs to fix
                }
            }

            response(true, [
                'transfer_id' => $transferId,
                'status' => $transferStatus,
                'amount' => $amount,
                'fee' => $fee,
                'net_amount' => $netAmount,
                'new_balance' => $newBalance,
                'transaction_id' => $transactionId,
                'error_message' => $errorMessage,
                'estimated_time' => $estimatedTime,
                'pix_key_masked' => maskPixKey($pixKeyData['key_value'], $pixKeyData['key_type']),
            ], $transferStatus === 'failed' ? $errorMessage : "Transferencia solicitada com sucesso");
        }

        // Unknown action
        if (!in_array($action, ['save_pix_key', 'delete_pix_key', 'transfer'], true)) {
            response(false, null, "Acao invalida", 400);
        }
    }

    // Method not allowed
    if (!in_array($method, ['GET', 'POST'], true)) {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[cashback-transfer] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

// ======================== Helper Functions ========================

/**
 * Ensure required tables exist
 */
function ensureTables(PDO $db): void
{
    // Customer PIX keys
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_customer_pix_keys (
            id SERIAL PRIMARY KEY,
            customer_id INTEGER NOT NULL,
            key_type VARCHAR(20) NOT NULL,
            key_value VARCHAR(255) NOT NULL,
            is_primary BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT NOW(),
            deleted_at TIMESTAMP
        )
    ");

    // Create index if not exists
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_customer_pix_keys_customer
        ON om_customer_pix_keys(customer_id) WHERE deleted_at IS NULL
    ");

    // Cashback transfers
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_cashback_transfers (
            id SERIAL PRIMARY KEY,
            customer_id INTEGER NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            fee DECIMAL(10,2) DEFAULT 0,
            net_amount DECIMAL(10,2) NOT NULL,
            pix_key_type VARCHAR(20),
            pix_key_value VARCHAR(255),
            status VARCHAR(20) DEFAULT 'pending',
            transaction_id VARCHAR(100),
            error_message TEXT,
            requested_at TIMESTAMP DEFAULT NOW(),
            processed_at TIMESTAMP,
            completed_at TIMESTAMP
        )
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_cashback_transfers_customer
        ON om_cashback_transfers(customer_id)
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_cashback_transfers_status
        ON om_cashback_transfers(status) WHERE status IN ('pending', 'processing')
    ");
}

/**
 * Mask PIX key for display
 */
function maskPixKey(?string $key, ?string $type): string
{
    if (empty($key)) return '';
    $len = strlen($key);
    if ($len <= 4) return str_repeat('*', $len);

    return match ($type) {
        'cpf' => substr(preg_replace('/\D/', '', $key), 0, 3) . '.***.***-' . substr(preg_replace('/\D/', '', $key), -2),
        'cnpj' => substr(preg_replace('/\D/', '', $key), 0, 2) . '.***.***/****-' . substr(preg_replace('/\D/', '', $key), -2),
        'email' => substr(explode('@', $key)[0], 0, 2) . '***@' . (explode('@', $key)[1] ?? '***'),
        'telefone', 'phone' => substr($key, 0, 4) . '****' . substr($key, -2),
        default => substr($key, 0, 4) . str_repeat('*', max(0, $len - 8)) . substr($key, -4),
    };
}

/**
 * Short preview for identification
 */
function getPixKeyPreview(?string $key, ?string $type): string
{
    if (empty($key)) return '';
    return match ($type) {
        'cpf' => '***.' . substr(preg_replace('/\D/', '', $key), -5, 3) . '.' . substr(preg_replace('/\D/', '', $key), -2),
        'cnpj' => '**.' . substr(preg_replace('/\D/', '', $key), -6, 3) . '.' . substr(preg_replace('/\D/', '', $key), -2),
        'email' => substr(explode('@', $key)[0], 0, 2) . '...@' . (explode('@', $key)[1] ?? ''),
        'telefone', 'phone' => '(' . substr(preg_replace('/\D/', '', $key), -11, 2) . ') ****-' . substr(preg_replace('/\D/', '', $key), -4),
        default => substr($key, 0, 8) . '...',
    };
}

/**
 * Validate PIX key format
 */
function validatePixKey(string $key, string $type): bool
{
    return match ($type) {
        'cpf' => (bool)preg_match('/^\d{11}$/', preg_replace('/\D/', '', $key)),
        'cnpj' => (bool)preg_match('/^\d{14}$/', preg_replace('/\D/', '', $key)),
        'email' => filter_var($key, FILTER_VALIDATE_EMAIL) !== false,
        'phone' => (bool)preg_match('/^\+?55?\d{10,11}$/', preg_replace('/\D/', '', $key)),
        'random' => (bool)preg_match('/^[a-zA-Z0-9\-]{32,36}$/', $key),
        default => true,
    };
}
