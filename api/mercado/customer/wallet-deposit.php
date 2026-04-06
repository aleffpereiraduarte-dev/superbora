<?php
/**
 * POST /api/mercado/customer/wallet-deposit.php
 * Customer wallet deposit via PIX — add money to SuperBora wallet
 *
 * POST action=create   — Generate PIX QR code to deposit money
 * GET  action=status    — Check deposit status
 * GET  action=history   — Deposit history
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();
require_once dirname(__DIR__, 3) . "/includes/classes/EfiClient.php";

try {
    $db = getDB();
    $customerId = requireCustomerAuth();

    // Ensure table exists
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_wallet_deposits (
            id SERIAL PRIMARY KEY,
            customer_id INTEGER NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            txid VARCHAR(100),
            pix_code TEXT,
            pix_qr_code TEXT,
            status VARCHAR(20) DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT NOW(),
            paid_at TIMESTAMP,
            expires_at TIMESTAMP
        )
    ");
    // Index for webhook lookup by txid
    $db->exec("CREATE INDEX IF NOT EXISTS idx_wallet_deposits_txid ON om_wallet_deposits(txid) WHERE status = 'pending'");
    // Index for customer history
    $db->exec("CREATE INDEX IF NOT EXISTS idx_wallet_deposits_customer ON om_wallet_deposits(customer_id, created_at DESC)");

    $method = $_SERVER['REQUEST_METHOD'];

    // ======================== POST: Create deposit ========================
    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? 'create';

        if ($action === 'create') {
            $amount = (float)($input['amount'] ?? 0);

            // Validate amount
            if ($amount < 5) {
                response(false, null, "Valor minimo R\$ 5,00", 400);
            }
            if ($amount > 1000) {
                response(false, null, "Valor maximo R\$ 1.000,00", 400);
            }
            if (!is_finite($amount)) {
                response(false, null, "Valor invalido", 400);
            }

            // Expire any stale pending deposits for this customer
            $db->prepare("
                UPDATE om_wallet_deposits
                SET status = 'expired'
                WHERE customer_id = ? AND status = 'pending' AND expires_at < NOW()
            ")->execute([$customerId]);

            // Check for existing pending deposit with same amount (avoid duplicates)
            $existingStmt = $db->prepare("
                SELECT id, txid, pix_code, pix_qr_code, expires_at
                FROM om_wallet_deposits
                WHERE customer_id = ? AND status = 'pending' AND expires_at > NOW()
                AND amount = ?
                ORDER BY created_at DESC LIMIT 1
            ");
            $existingStmt->execute([$customerId, $amount]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                response(true, [
                    'deposit_id' => (int)$existing['id'],
                    'pix_code' => $existing['pix_code'],
                    'pix_qr_code' => $existing['pix_qr_code'],
                    'amount' => $amount,
                    'expires_at' => date('c', strtotime($existing['expires_at'])),
                ]);
            }

            // Get customer data for EFI
            $custStmt = $db->prepare("SELECT name, cpf FROM om_customers WHERE customer_id = ?");
            $custStmt->execute([$customerId]);
            $custData = $custStmt->fetch(PDO::FETCH_ASSOC);

            $efi = new EfiClient();
            if (!$efi->isConfigured()) {
                response(false, null, "PIX indisponivel no momento", 503);
            }

            $description = "SuperBora - Deposito carteira";
            $efiCustomer = ['nome' => $custData['name'] ?? 'Cliente SuperBora'];
            $cpf = preg_replace('/[^0-9]/', '', $custData['cpf'] ?? '');
            if (strlen($cpf) === 11) {
                $efiCustomer['cpf'] = $cpf;
            }

            // 10 minutes expiration
            $chargeResult = $efi->createPixCharge($amount, $description, 600, $efiCustomer);

            if (!$chargeResult['success']) {
                error_log("[wallet-deposit] EFI PIX charge failed: " . ($chargeResult['error'] ?? 'unknown'));
                response(false, null, "Erro ao gerar PIX. Tente novamente.", 503);
            }

            $txid = $chargeResult['txid'];
            $pixCode = $chargeResult['qrcode_text'] ?? '';
            $qrCodeUrl = $chargeResult['qrcode_image'] ?? '';

            if (empty($pixCode)) {
                error_log("[wallet-deposit] EFI returned no QR code text");
                response(false, null, "PIX indisponivel no momento. Tente novamente.", 503);
            }

            if (empty($qrCodeUrl)) {
                $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($pixCode);
            }

            $expiresAt = date('Y-m-d H:i:s', time() + 600);

            // Save deposit record
            $stmt = $db->prepare("
                INSERT INTO om_wallet_deposits (customer_id, amount, txid, pix_code, pix_qr_code, status, expires_at)
                VALUES (?, ?, ?, ?, ?, 'pending', ?)
                RETURNING id
            ");
            $stmt->execute([$customerId, $amount, $txid, $pixCode, $qrCodeUrl, $expiresAt]);
            $depositId = (int)$stmt->fetchColumn();

            error_log("[wallet-deposit] Created deposit #{$depositId} for customer #{$customerId}: R\$ {$amount} txid={$txid}");

            response(true, [
                'deposit_id' => $depositId,
                'pix_code' => $pixCode,
                'pix_qr_code' => $qrCodeUrl,
                'amount' => $amount,
                'expires_at' => date('c', strtotime($expiresAt)),
            ]);
        }

        response(false, null, "Acao invalida", 400);
    }

    // ======================== GET: Status or History ========================
    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

        if ($action === 'status') {
            $depositId = (int)($_GET['deposit_id'] ?? 0);
            if (!$depositId) {
                response(false, null, "deposit_id obrigatorio", 400);
            }

            $stmt = $db->prepare("
                SELECT id, amount, status, created_at, paid_at, expires_at, txid
                FROM om_wallet_deposits
                WHERE id = ? AND customer_id = ?
            ");
            $stmt->execute([$depositId, $customerId]);
            $deposit = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$deposit) {
                response(false, null, "Deposito nao encontrado", 404);
            }

            // Auto-expire if past deadline and still pending
            if ($deposit['status'] === 'pending' && strtotime($deposit['expires_at']) < time()) {
                $db->prepare("UPDATE om_wallet_deposits SET status = 'expired' WHERE id = ? AND status = 'pending'")
                   ->execute([$depositId]);
                $deposit['status'] = 'expired';
            }

            // If still pending, try checking EFI API directly as fallback
            if ($deposit['status'] === 'pending' && !empty($deposit['txid'])) {
                try {
                    $efi = new EfiClient();
                    $chargeStatus = $efi->checkChargeStatus($deposit['txid']);
                    if ($chargeStatus['success'] && $chargeStatus['paid']) {
                        // Credit wallet inline (webhook may have been delayed)
                        $db->beginTransaction();
                        $lockStmt = $db->prepare("SELECT status FROM om_wallet_deposits WHERE id = ? FOR UPDATE");
                        $lockStmt->execute([$depositId]);
                        $currentStatus = $lockStmt->fetchColumn();

                        if ($currentStatus === 'pending') {
                            $db->prepare("UPDATE om_wallet_deposits SET status = 'paid', paid_at = NOW() WHERE id = ?")
                               ->execute([$depositId]);

                            // Credit wallet balance
                            $db->prepare("
                                INSERT INTO om_cashback_wallet (customer_id, balance, total_earned)
                                VALUES (?, ?, ?)
                                ON CONFLICT (customer_id) DO UPDATE SET
                                    balance = om_cashback_wallet.balance + EXCLUDED.balance,
                                    total_earned = om_cashback_wallet.total_earned + EXCLUDED.total_earned
                            ")->execute([$customerId, $deposit['amount'], $deposit['amount']]);

                            // Record transaction
                            $db->prepare("
                                INSERT INTO om_cashback_transactions
                                (customer_id, type, amount, balance_after, description)
                                VALUES (?, 'credit', ?, (SELECT COALESCE(balance, 0) FROM om_cashback_wallet WHERE customer_id = ?), ?)
                            ")->execute([
                                $customerId,
                                $deposit['amount'],
                                $customerId,
                                'Deposito via PIX - R$ ' . number_format($deposit['amount'], 2, ',', '.')
                            ]);

                            $db->commit();
                            $deposit['status'] = 'paid';
                            error_log("[wallet-deposit] Fallback: deposit #{$depositId} confirmed via polling EFI");
                        } else {
                            $db->rollBack();
                            $deposit['status'] = $currentStatus;
                        }
                    }
                } catch (Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    error_log("[wallet-deposit] EFI fallback check error: " . $e->getMessage());
                }
            }

            // Get current wallet balance
            $balStmt = $db->prepare("SELECT COALESCE(balance, 0) FROM om_cashback_wallet WHERE customer_id = ?");
            $balStmt->execute([$customerId]);
            $balance = (float)$balStmt->fetchColumn();

            $remaining = max(0, strtotime($deposit['expires_at']) - time());

            response(true, [
                'deposit_id' => (int)$deposit['id'],
                'amount' => (float)$deposit['amount'],
                'status' => $deposit['status'],
                'paid' => $deposit['status'] === 'paid',
                'remaining_seconds' => $remaining,
                'expires_at' => date('c', strtotime($deposit['expires_at'])),
                'paid_at' => $deposit['paid_at'] ? date('c', strtotime($deposit['paid_at'])) : null,
                'new_balance' => $balance,
            ]);
        }

        if ($action === 'history') {
            $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
            $offset = max(0, (int)($_GET['offset'] ?? 0));

            $stmt = $db->prepare("
                SELECT id, amount, status, created_at, paid_at, expires_at
                FROM om_wallet_deposits
                WHERE customer_id = ?
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$customerId, $limit, $offset]);
            $deposits = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $formatted = array_map(function ($d) {
                return [
                    'id' => (int)$d['id'],
                    'amount' => (float)$d['amount'],
                    'status' => $d['status'],
                    'created_at' => date('c', strtotime($d['created_at'])),
                    'paid_at' => $d['paid_at'] ? date('c', strtotime($d['paid_at'])) : null,
                ];
            }, $deposits);

            response(true, ['deposits' => $formatted]);
        }

        response(false, null, "Acao invalida. Use action=status ou action=history", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[wallet-deposit] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar deposito", 500);
}
