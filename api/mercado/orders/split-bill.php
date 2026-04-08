<?php
/**
 * POST/GET /api/mercado/orders/split-bill.php
 *
 * Split payment between friends for an order.
 *
 * POST action=create  - Create a bill split (auth: order owner)
 * POST action=pay     - Mark participant payment as complete (auth required)
 * GET  ?order_id=X    - Get split details for an order (auth required)
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/notify.php";
require_once __DIR__ . "/../helpers/ws-customer-broadcast.php";

setCorsHeaders();

try {
    $db = getDB();

    // Tables already exist with the schema:
    //   om_bill_splits: id, order_id, created_by, share_code, total_amount, split_type, status, expires_at, created_at, updated_at
    //   om_bill_split_participants: id, split_id, customer_id, name, email, phone, amount, status, paid_at, payment_reference, created_at
    // We deliberately do NOT run CREATE TABLE IF NOT EXISTS here — the
    // production schema diverged from this file's old definition (e.g.
    // creator_id vs created_by), so this code now uses the real column names.

    $method = $_SERVER['REQUEST_METHOD'];

    // ─── GET: Public lookup by share_code (friend opens link, no auth) ───
    if ($method === 'GET' && !empty($_GET['share_code'])) {
        $shareCode = strtoupper(trim($_GET['share_code']));
        if (!preg_match('/^[A-Z0-9]{4,10}$/', $shareCode)) {
            response(false, null, 'share_code invalido', 400);
        }

        // Look up the split + creator name + order info
        $stmt = $db->prepare("
            SELECT s.id, s.order_id, s.created_by AS creator_id, s.share_code, s.total_amount AS total, s.status, s.created_at,
                   o.order_number, o.total as order_total, o.created_at as order_date,
                   p.name as partner_name, p.logo as partner_logo,
                   c.name as creator_name
            FROM om_bill_splits s
            INNER JOIN om_market_orders o ON o.order_id = s.order_id
            LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
            LEFT JOIN om_customers c ON c.customer_id = s.created_by
            WHERE s.share_code = ? AND s.status != 'cancelled'
            LIMIT 1
        ");
        $stmt->execute([$shareCode]);
        $split = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$split) {
            response(false, null, 'Divisao nao encontrada ou cancelada', 404);
        }

        // Get all participants
        $stmt = $db->prepare("
            SELECT id, name, email, phone, amount, status, paid_at, payment_reference
            FROM om_bill_split_participants
            WHERE split_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$split['id']]);
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $paidTotal = 0;
        foreach ($participants as $p) {
            if ($p['status'] === 'paid') $paidTotal += (float)$p['amount'];
        }

        // Optional: focus on a specific participant if ?p= was provided
        $focusParticipantId = isset($_GET['p']) ? (int)$_GET['p'] : 0;
        $focusParticipant = null;
        if ($focusParticipantId) {
            foreach ($participants as $p) {
                if ((int)$p['id'] === $focusParticipantId) { $focusParticipant = $p; break; }
            }
        }

        response(true, [
            'split' => [
                'id'             => (int)$split['id'],
                'share_code'     => $split['share_code'],
                'total'          => (float)$split['total'],
                'paid_total'     => $paidTotal,
                'remaining'      => (float)$split['total'] - $paidTotal,
                'status'         => $split['status'],
                'created_at'     => $split['created_at'],
                'creator_name'   => $split['creator_name'] ?? 'Anfitriao',
            ],
            'order' => [
                'order_id'       => (int)$split['order_id'],
                'order_number'   => $split['order_number'],
                'partner_name'   => $split['partner_name'] ?? 'Loja',
                'partner_logo'   => $split['partner_logo'] ?? null,
                'order_date'     => $split['order_date'],
            ],
            'participants' => array_map(fn($p) => [
                'id'              => (int)$p['id'],
                'name'            => $p['name'],
                'amount'          => (float)$p['amount'],
                'status'          => $p['status'],
                'payment_method'  => $p['payment_reference'],
            ], $participants),
            'focus_participant' => $focusParticipant ? [
                'id'     => (int)$focusParticipant['id'],
                'name'   => $focusParticipant['name'],
                'amount' => (float)$focusParticipant['amount'],
                'status' => $focusParticipant['status'],
            ] : null,
        ]);
    }

    // ─── GET: Split details for an order ───
    if ($method === 'GET') {
        $customerId = requireCustomerAuth();
        $orderId = (int)($_GET['order_id'] ?? 0);

        if (!$orderId) {
            response(false, null, "order_id obrigatorio", 400);
        }

        // Verify order belongs to customer
        $stmt = $db->prepare("
            SELECT order_id, total FROM om_market_orders
            WHERE order_id = ? AND customer_id = ?
        ");
        $stmt->execute([$orderId, $customerId]);
        if (!$stmt->fetch()) {
            response(false, null, "Pedido nao encontrado", 404);
        }

        // Get the split (use real schema column names: created_by, total_amount)
        $stmt = $db->prepare("
            SELECT id, order_id, created_by AS creator_id, share_code, total_amount AS total, status, created_at
            FROM om_bill_splits
            WHERE order_id = ? AND status != 'cancelled'
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$orderId]);
        $split = $stmt->fetch();

        if (!$split) {
            response(false, null, "Nenhuma divisao encontrada para este pedido", 404);
        }

        // Get participants (real column is payment_reference, not payment_method)
        $stmt = $db->prepare("
            SELECT id, name, email, phone, amount, status, paid_at, payment_reference
            FROM om_bill_split_participants
            WHERE split_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$split['id']]);
        $participants = $stmt->fetchAll();

        $paidTotal = 0;
        foreach ($participants as $p) {
            if ($p['status'] === 'paid') {
                $paidTotal += (float)$p['amount'];
            }
        }

        response(true, [
            'split' => [
                'id' => (int)$split['id'],
                'order_id' => (int)$split['order_id'],
                'share_code' => $split['share_code'],
                'total' => (float)$split['total'],
                'status' => $split['status'],
                'paid_total' => $paidTotal,
                'remaining' => (float)$split['total'] - $paidTotal,
                'created_at' => $split['created_at'],
            ],
            'participants' => array_map(function ($p) {
                return [
                    'id' => (int)$p['id'],
                    'name' => $p['name'],
                    'email' => $p['email'],
                    'phone' => $p['phone'],
                    'amount' => (float)$p['amount'],
                    'amount_formatted' => 'R$ ' . number_format($p['amount'], 2, ',', '.'),
                    'status' => $p['status'],
                    'paid_at' => $p['paid_at'],
                    'payment_method' => $p['payment_reference'],
                ];
            }, $participants),
        ]);
    }

    // ─── POST: Create split or mark payment ───
    if ($method === 'POST') {
        $customerId = requireCustomerAuth();
        $input = getInput();
        $action = $input['action'] ?? '';

        // ── action=create ──
        if ($action === 'create') {
            $orderId = (int)($input['order_id'] ?? 0);
            $participants = $input['participants'] ?? [];

            if (!$orderId) {
                response(false, null, "order_id obrigatorio", 400);
            }
            if (empty($participants) || !is_array($participants)) {
                response(false, null, "participants obrigatorio (array de {name, amount})", 400);
            }

            // Verify order ownership
            $stmt = $db->prepare("
                SELECT order_id, total FROM om_market_orders
                WHERE order_id = ? AND customer_id = ?
            ");
            $stmt->execute([$orderId, $customerId]);
            $order = $stmt->fetch();

            if (!$order) {
                response(false, null, "Pedido nao encontrado ou voce nao e o dono", 404);
            }

            // Check no active split exists
            $stmt = $db->prepare("
                SELECT id FROM om_bill_splits
                WHERE order_id = ? AND status NOT IN ('cancelled', 'complete')
            ");
            $stmt->execute([$orderId]);
            if ($stmt->fetch()) {
                response(false, null, "Ja existe uma divisao ativa para este pedido", 400);
            }

            // Validate participant amounts sum up correctly
            $totalFromParticipants = 0;
            foreach ($participants as $p) {
                $amt = (float)($p['amount'] ?? 0);
                if ($amt <= 0) {
                    response(false, null, "Cada participante deve ter um amount maior que zero", 400);
                }
                $totalFromParticipants += $amt;
            }

            $orderTotal = (float)$order['total'];
            // Allow small rounding difference (up to 1 cent)
            if (abs($totalFromParticipants - $orderTotal) > 0.01) {
                response(false, null, "Soma dos valores (R$ " . number_format($totalFromParticipants, 2, ',', '.') . ") difere do total do pedido (R$ " . number_format($orderTotal, 2, ',', '.') . ")", 400);
            }

            // Generate unique 6-char share code
            $shareCode = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

            $db->beginTransaction();
            try {
                // Use real schema columns: created_by, total_amount, split_type
                $stmt = $db->prepare("
                    INSERT INTO om_bill_splits (order_id, created_by, share_code, total_amount, split_type, status)
                    VALUES (?, ?, ?, ?, 'custom', 'pending')
                    RETURNING id
                ");
                $stmt->execute([$orderId, $customerId, $shareCode, $orderTotal]);
                $splitId = (int)$stmt->fetchColumn();

                $stmtP = $db->prepare("
                    INSERT INTO om_bill_split_participants (split_id, name, email, phone, amount, status)
                    VALUES (?, ?, ?, ?, ?, 'pending')
                    RETURNING id
                ");

                $createdParticipants = [];
                foreach ($participants as $p) {
                    $stmtP->execute([
                        $splitId,
                        sanitizeOutput($p['name'] ?? 'Participante'),
                        isset($p['email']) ? sanitizeOutput($p['email']) : null,
                        isset($p['phone']) ? sanitizeOutput($p['phone']) : null,
                        (float)$p['amount'],
                    ]);
                    $pid = (int)$stmtP->fetchColumn();
                    $createdParticipants[] = [
                        'id' => $pid,
                        'name' => $p['name'] ?? 'Participante',
                        'amount' => (float)$p['amount'],
                        'payment_link' => "https://superbora.com.br/dividir/{$shareCode}?p={$pid}",
                    ];
                }

                $db->commit();

                response(true, [
                    'split_id' => $splitId,
                    'share_code' => $shareCode,
                    'total' => $orderTotal,
                    'participants' => $createdParticipants,
                ], "Divisao criada com sucesso");

            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        }

        // ── action=pay ──
        elseif ($action === 'pay') {
            $splitId = (int)($input['split_id'] ?? 0);
            $participantId = (int)($input['participant_id'] ?? 0);
            $paymentMethod = $input['payment_method'] ?? 'pix';

            if (!$splitId || !$participantId) {
                response(false, null, "split_id e participant_id obrigatorios", 400);
            }

            // Whitelist payment methods
            $allowedMethods = ['pix', 'cartao', 'dinheiro', 'cashback'];
            if (!in_array($paymentMethod, $allowedMethods, true)) {
                response(false, null, "Metodo de pagamento invalido", 400);
            }

            $db->beginTransaction();
            try {
                // SECURITY: Lock the participant row and verify the split belongs to the authenticated user's order
                $stmt = $db->prepare("
                    SELECT p.id, p.split_id, p.amount, p.status,
                           s.status as split_status, s.order_id, s.created_by AS creator_id
                    FROM om_bill_split_participants p
                    INNER JOIN om_bill_splits s ON p.split_id = s.id
                    WHERE p.id = ? AND p.split_id = ?
                    FOR UPDATE OF p
                ");
                $stmt->execute([$participantId, $splitId]);
                $participant = $stmt->fetch();

                if (!$participant) {
                    $db->rollBack();
                    response(false, null, "Participante nao encontrado", 404);
                }

                // SECURITY: Verify the order belongs to the authenticated user
                $stmtOrderAuth = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
                $stmtOrderAuth->execute([(int)$participant['order_id'], $customerId]);
                if (!$stmtOrderAuth->fetch()) {
                    $db->rollBack();
                    response(false, null, "Nao autorizado para este pedido", 403);
                }

                if ($participant['status'] === 'paid') {
                    $db->rollBack();
                    response(false, null, "Esta parte ja foi paga", 400);
                }

                if ($participant['split_status'] === 'cancelled') {
                    $db->rollBack();
                    response(false, null, "Esta divisao foi cancelada", 400);
                }

                // Mark as paid (real column is payment_reference, not payment_method)
                $db->prepare("
                    UPDATE om_bill_split_participants
                    SET status = 'paid', paid_at = NOW(), payment_reference = ?
                    WHERE id = ?
                ")->execute([$paymentMethod, $participantId]);

                // Check if all participants are now paid
                $stmt = $db->prepare("
                    SELECT COUNT(*) as total,
                           SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count
                    FROM om_bill_split_participants
                    WHERE split_id = ?
                ");
                $stmt->execute([$splitId]);
                $counts = $stmt->fetch();

                $allPaid = ((int)$counts['total'] === (int)$counts['paid_count']);

                if ($allPaid) {
                    $db->prepare("
                        UPDATE om_bill_splits SET status = 'complete' WHERE id = ?
                    ")->execute([$splitId]);
                }

                $db->commit();

                response(true, [
                    'participant_id' => $participantId,
                    'amount_paid' => (float)$participant['amount'],
                    'payment_method' => $paymentMethod,
                    'all_paid' => $allPaid,
                    'split_status' => $allPaid ? 'complete' : 'partial',
                ], $allPaid ? "Divisao completa! Todos pagaram." : "Pagamento registrado com sucesso");

            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        }

        // ── action=pay_with_wallet ──
        // The AUTHED user (a friend, NOT the creator) pays their share using
        // their SuperBora wallet balance. Money moves to the creator's wallet
        // through the same internal flow used by cashback-transfer.
        elseif ($action === 'pay_with_wallet') {
            $shareCode = strtoupper(trim($input['share_code'] ?? ''));
            $participantId = (int)($input['participant_id'] ?? 0);

            if (!$shareCode || !$participantId) {
                response(false, null, "share_code e participant_id obrigatorios", 400);
            }

            $db->beginTransaction();
            try {
                // Lock the participant + parent split
                $stmt = $db->prepare("
                    SELECT p.id, p.amount, p.status, p.split_id,
                           s.id AS split_id, s.created_by AS creator_id, s.share_code, s.status as split_status, s.order_id
                    FROM om_bill_split_participants p
                    INNER JOIN om_bill_splits s ON p.split_id = s.id
                    WHERE p.id = ? AND s.share_code = ?
                    FOR UPDATE OF p
                ");
                $stmt->execute([$participantId, $shareCode]);
                $participant = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$participant) {
                    $db->rollBack();
                    response(false, null, "Participante nao encontrado", 404);
                }
                if ($participant['status'] === 'paid') {
                    $db->rollBack();
                    response(false, null, "Sua parte ja foi paga", 400);
                }
                if ($participant['split_status'] === 'cancelled') {
                    $db->rollBack();
                    response(false, null, "Esta divisao foi cancelada", 400);
                }

                $amount = round((float)$participant['amount'], 2);
                $creatorId = (int)$participant['creator_id'];

                // Don't allow the creator to pay their own share via this flow
                if ($creatorId === $customerId) {
                    $db->rollBack();
                    response(false, null, "Voce e o criador da divisao — escolha outro metodo", 400);
                }

                // Read CALLER's available balance from the cashback ledger
                $balStmt = $db->prepare("
                    SELECT
                        COALESCE(SUM(CASE WHEN type IN ('earned','bonus') AND status = 'available' THEN amount ELSE 0 END), 0)
                          - COALESCE(SUM(CASE WHEN type = 'used' THEN amount ELSE 0 END), 0) AS available
                    FROM om_cashback WHERE customer_id = ?
                ");
                $balStmt->execute([$customerId]);
                $callerBalance = round(max(0, (float)$balStmt->fetchColumn()), 2);

                if ($amount > $callerBalance) {
                    $db->rollBack();
                    response(false, null, "Saldo insuficiente. Voce tem R$ " . number_format($callerBalance, 2, ',', '.'), 400);
                }

                // 1. Debit caller (insert 'used' row in caller's cashback ledger)
                $db->prepare("
                    INSERT INTO om_cashback (customer_id, type, amount, status, description, order_id, created_at)
                    VALUES (?, 'used', ?, 'used', ?, NULL, NOW())
                ")->execute([
                    $customerId,
                    $amount,
                    "Rachando conta - pedido #{$participant['order_id']}",
                ]);

                // 2. Credit creator (insert 'bonus' row in creator's cashback ledger)
                $db->prepare("
                    INSERT INTO om_cashback (customer_id, type, amount, status, description, order_id, created_at)
                    VALUES (?, 'bonus', ?, 'available', ?, NULL, NOW())
                ")->execute([
                    $creatorId,
                    $amount,
                    "Recebido de amigo - racha #{$participant['split_id']}",
                ]);

                // 3. Mark participant as paid
                $db->prepare("
                    UPDATE om_bill_split_participants
                    SET status = 'paid', paid_at = NOW(), payment_reference = 'sb_wallet'
                    WHERE id = ?
                ")->execute([$participantId]);

                // 4. Check if all participants are paid → complete the split
                $cntStmt = $db->prepare("
                    SELECT COUNT(*) AS total,
                           SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_count
                    FROM om_bill_split_participants
                    WHERE split_id = ?
                ");
                $cntStmt->execute([$participant['split_id']]);
                $cnt = $cntStmt->fetch(PDO::FETCH_ASSOC);
                $allPaid = ((int)$cnt['total'] === (int)$cnt['paid_count']);
                if ($allPaid) {
                    $db->prepare("UPDATE om_bill_splits SET status = 'complete' WHERE id = ?")
                       ->execute([$participant['split_id']]);
                }

                $db->commit();

                // Best-effort notifications (don't fail the transfer if this errors)
                $amountStr = 'R$ ' . number_format($amount, 2, ',', '.');
                $callerNameStmt = $db->prepare("SELECT name FROM om_customers WHERE customer_id = ?");
                $callerNameStmt->execute([$customerId]);
                $callerName = $callerNameStmt->fetchColumn() ?: 'Amigo';

                // Push to creator: "Joao pagou sua parte: R$ 25"
                try {
                    notifyCustomer(
                        $db, $creatorId,
                        "Racha de conta: {$amountStr} 💚",
                        "{$callerName} pagou a parte dele(a) na divisao do pedido",
                        '/carteira',
                        [
                            'type'          => 'split_payment_received',
                            'amount'        => $amount,
                            'sender_id'     => $customerId,
                            'sender_name'   => $callerName,
                            'split_id'      => $participant['split_id'],
                            'all_paid'      => $allPaid,
                            'route'         => '/carteira',
                        ]
                    );
                } catch (\Exception $e) { error_log('[split-bill] creator push: ' . $e->getMessage()); }

                // Push to caller: confirmation
                try {
                    notifyCustomer(
                        $db, $customerId,
                        "Sua parte foi paga ✅",
                        "{$amountStr} debitado pra rachar a conta",
                        '/carteira',
                        [
                            'type'          => 'split_payment_sent',
                            'amount'        => $amount,
                            'split_id'      => $participant['split_id'],
                            'all_paid'      => $allPaid,
                            'route'         => '/carteira',
                        ]
                    );
                } catch (\Exception $e) { error_log('[split-bill] caller push: ' . $e->getMessage()); }

                // WS broadcast
                try {
                    if (function_exists('wsBroadcastToCustomer')) {
                        wsBroadcastToCustomer($creatorId, 'split_payment_received', [
                            'amount'      => $amount,
                            'sender_name' => $callerName,
                            'split_id'    => $participant['split_id'],
                            'all_paid'    => $allPaid,
                        ]);
                        wsBroadcastToCustomer($customerId, 'split_payment_sent', [
                            'amount'   => $amount,
                            'split_id' => $participant['split_id'],
                            'all_paid' => $allPaid,
                        ]);
                    }
                } catch (\Exception $e) {}

                response(true, [
                    'amount_paid'  => $amount,
                    'split_status' => $allPaid ? 'complete' : 'partial',
                    'all_paid'     => $allPaid,
                ], $allPaid ? 'Divisao completa!' : 'Sua parte foi paga');

            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('[split-bill] pay_with_wallet error: ' . $e->getMessage());
                response(false, null, 'Erro ao processar pagamento', 500);
            }
        }

        else {
            response(false, null, "action invalida. Use 'create', 'pay' ou 'pay_with_wallet'", 400);
        }
    }

    // Method not allowed
    if (!in_array($method, ['GET', 'POST'])) {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[orders/split-bill] Error: " . $e->getMessage());
    response(false, null, "Erro ao processar divisao de conta", 500);
}
