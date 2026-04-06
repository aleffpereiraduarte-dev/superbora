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

setCorsHeaders();

try {
    $db = getDB();

    // Ensure tables exist
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_bill_splits (
            id SERIAL PRIMARY KEY,
            order_id INT NOT NULL,
            creator_id INT NOT NULL,
            share_code VARCHAR(6) UNIQUE,
            total NUMERIC(10,2),
            status VARCHAR(20) DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS om_bill_split_participants (
            id SERIAL PRIMARY KEY,
            split_id INT NOT NULL,
            name VARCHAR(255),
            email VARCHAR(255),
            phone VARCHAR(20),
            amount NUMERIC(10,2),
            status VARCHAR(20) DEFAULT 'pending',
            paid_at TIMESTAMP,
            payment_method VARCHAR(50)
        );
    ");

    $method = $_SERVER['REQUEST_METHOD'];

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

        // Get the split
        $stmt = $db->prepare("
            SELECT id, order_id, creator_id, share_code, total, status, created_at
            FROM om_bill_splits
            WHERE order_id = ? AND status != 'cancelled'
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$orderId]);
        $split = $stmt->fetch();

        if (!$split) {
            response(false, null, "Nenhuma divisao encontrada para este pedido", 404);
        }

        // Get participants
        $stmt = $db->prepare("
            SELECT id, name, email, phone, amount, status, paid_at, payment_method
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
                    'payment_method' => $p['payment_method'],
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
                $stmt = $db->prepare("
                    INSERT INTO om_bill_splits (order_id, creator_id, share_code, total, status)
                    VALUES (?, ?, ?, ?, 'pending')
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
                           s.status as split_status, s.order_id, s.creator_id
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

                // Mark as paid
                $db->prepare("
                    UPDATE om_bill_split_participants
                    SET status = 'paid', paid_at = NOW(), payment_method = ?
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

        else {
            response(false, null, "action invalida. Use 'create' ou 'pay'", 400);
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
