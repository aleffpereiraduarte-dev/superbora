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

setCorsHeaders();

// Transfer config constants
define('MIN_TRANSFER', 10.00);
define('MAX_TRANSFER', 5000.00);
define('TRANSFER_FEE_PERCENT', 0); // No fee for now — can enable later
define('TRANSFER_FEE_FIXED', 0.00);
define('MAX_PIX_KEYS', 3);
define('MAX_TRANSFERS_PER_DAY', 3);

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

        // Get cashback balance
        $stmtBal = dbQuery($db, "
            SELECT COALESCE(balance, 0) as balance
            FROM om_cashback_wallet
            WHERE customer_id = ?
        ", [$customerId]);
        $balance = round((float)($stmtBal->fetchColumn() ?: 0), 2);

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

            // Calculate fee
            $fee = round(($amount * TRANSFER_FEE_PERCENT / 100) + TRANSFER_FEE_FIXED, 2);
            $netAmount = round($amount - $fee, 2);

            if ($netAmount <= 0) {
                response(false, null, "Valor liquido deve ser positivo apos taxas", 400);
            }

            // Normalize PIX key type for EFI
            $efiType = $pixKeyData['key_type'];
            if ($efiType === 'telefone') $efiType = 'phone';
            if ($efiType === 'aleatoria') $efiType = 'random';

            // Begin transaction - debit cashback wallet
            $db->beginTransaction();

            try {
                // Lock wallet balance
                $stmtBal = $db->prepare("
                    SELECT balance FROM om_cashback_wallet
                    WHERE customer_id = ? FOR UPDATE
                ");
                $stmtBal->execute([$customerId]);
                $balance = (float)($stmtBal->fetchColumn() ?: 0);

                if ($amount > $balance) {
                    $db->rollBack();
                    response(false, null, "Saldo insuficiente. Disponivel: R$ " . number_format($balance, 2, ',', '.'), 400);
                }

                // Debit from cashback wallet
                $stmtDebit = $db->prepare("
                    UPDATE om_cashback_wallet
                    SET balance = balance - ?, total_used = total_used + ?, updated_at = NOW()
                    WHERE customer_id = ? AND balance >= ?
                ");
                $stmtDebit->execute([$amount, $amount, $customerId, $amount]);

                if ($stmtDebit->rowCount() === 0) {
                    $db->rollBack();
                    response(false, null, "Saldo insuficiente", 400);
                }

                // Get new balance
                $stmtNewBal = $db->prepare("SELECT balance FROM om_cashback_wallet WHERE customer_id = ?");
                $stmtNewBal->execute([$customerId]);
                $newBalance = round((float)$stmtNewBal->fetchColumn(), 2);

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

                    // Refund balance
                    $db->prepare("
                        UPDATE om_cashback_wallet
                        SET balance = balance + ?, total_used = GREATEST(total_used - ?, 0), updated_at = NOW()
                        WHERE customer_id = ?
                    ")->execute([$amount, $amount, $customerId]);

                    // Get refunded balance
                    $stmtRefBal = $db->prepare("SELECT balance FROM om_cashback_wallet WHERE customer_id = ?");
                    $stmtRefBal->execute([$customerId]);
                    $refundedBalance = round((float)$stmtRefBal->fetchColumn(), 2);

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
