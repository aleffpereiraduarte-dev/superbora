<?php
/**
 * GET /admin/card-support/export-data.php?customer_id=123&format=json|csv
 *
 * LGPD-style data export for a customer's credit card data.
 *
 * format=json (default): one JSON payload
 * format=csv:           returns a CSV bundle (transactions + bills)
 */

require_once __DIR__ . "/_common.php";

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $ctx = bootstrapCardSupport();
    $db  = $ctx['db'];
    $adminId = $ctx['admin_id'];

    $customerId = (int)($_GET['customer_id'] ?? 0);
    $format     = strtolower((string)($_GET['format'] ?? 'json'));
    if ($customerId <= 0) response(false, null, 'customer_id obrigatorio', 400);

    $stmt = $db->prepare("SELECT customer_id, name, email, phone, cpf, created_at FROM om_customers WHERE customer_id = ? LIMIT 1");
    $stmt->execute([$customerId]);
    $cust = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cust) response(false, null, 'Cliente nao encontrado', 404);

    // Cards (no encrypted number exposed)
    $stmt = $db->prepare("
        SELECT id, card_brand, card_last4, expires_at, credit_limit, used_limit,
               status, virtual, score, closing_day, due_day,
               created_at, approved_at, activated_at, blocked_at, cancelled_at
        FROM om_credit_cards WHERE customer_id = ?
    ");
    $stmt->execute([$customerId]);
    $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Transactions
    $stmt = $db->prepare("
        SELECT id, card_id, amount, merchant, category, installments, installment_number,
               transaction_date, status
        FROM om_credit_card_transactions
        WHERE customer_id = ?
        ORDER BY transaction_date DESC
    ");
    $stmt->execute([$customerId]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Bills
    $stmt = $db->prepare("
        SELECT id, card_id, period_start, period_end, due_date, total_amount,
               minimum_amount, paid_amount, status, closed_at, paid_at
        FROM om_credit_card_bills WHERE customer_id = ?
        ORDER BY period_start DESC
    ");
    $stmt->execute([$customerId]);
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Events
    $stmt = $db->prepare("
        SELECT id, card_id, event_type, actor_type, created_at, notes
        FROM om_credit_card_events WHERE customer_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$customerId]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Record the export in events for LGPD audit
    $auditStmt = $db->prepare("
        INSERT INTO om_credit_card_events (card_id, customer_id, event_type, actor_type, actor_id, payload, notes)
        VALUES (?, ?, 'lgpd_export', 'admin', ?, ?, ?)
    ");
    $firstCardId = !empty($cards) ? (int)$cards[0]['id'] : 0;
    $auditStmt->execute([
        $firstCardId,
        $customerId,
        $adminId,
        json_encode(['format' => $format], JSON_UNESCAPED_UNICODE),
        'LGPD data export gerado por admin ' . $ctx['admin_name'],
    ]);

    $maskedCpf = cardSupportMaskCpf($cust['cpf']);

    if ($format === 'csv') {
        // Emit a CSV bundle (transactions + bills)
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="card-export-' . $customerId . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['# Customer Card Data Export']);
        fputcsv($out, ['customer_id', $customerId]);
        fputcsv($out, ['name',        $cust['name']]);
        fputcsv($out, ['email',       $cust['email']]);
        fputcsv($out, ['phone',       $cust['phone']]);
        fputcsv($out, ['cpf_masked',  $maskedCpf]);
        fputcsv($out, ['member_since', $cust['created_at']]);
        fputcsv($out, []);

        fputcsv($out, ['# Cards']);
        fputcsv($out, ['id','brand','last4','expires','credit_limit','used_limit','status','virtual','score','created_at','activated_at','blocked_at','cancelled_at']);
        foreach ($cards as $c) {
            fputcsv($out, [
                $c['id'], $c['card_brand'], $c['card_last4'], $c['expires_at'],
                $c['credit_limit'], $c['used_limit'], $c['status'], $c['virtual'] ? '1' : '0',
                $c['score'], $c['created_at'], $c['activated_at'], $c['blocked_at'], $c['cancelled_at'],
            ]);
        }
        fputcsv($out, []);

        fputcsv($out, ['# Transactions']);
        fputcsv($out, ['id','card_id','date','merchant','category','amount','installments','installment_number','status']);
        foreach ($transactions as $t) {
            fputcsv($out, [
                $t['id'], $t['card_id'], $t['transaction_date'],
                $t['merchant'], $t['category'], $t['amount'],
                $t['installments'], $t['installment_number'], $t['status'],
            ]);
        }
        fputcsv($out, []);

        fputcsv($out, ['# Bills']);
        fputcsv($out, ['id','card_id','period_start','period_end','due_date','total_amount','minimum','paid','status']);
        foreach ($bills as $b) {
            fputcsv($out, [
                $b['id'], $b['card_id'], $b['period_start'], $b['period_end'],
                $b['due_date'], $b['total_amount'], $b['minimum_amount'], $b['paid_amount'], $b['status'],
            ]);
        }
        fclose($out);
        exit;
    }

    // JSON default
    response(true, [
        'exported_at'     => date('Y-m-d H:i:s'),
        'exported_by'     => ['admin_id' => $adminId, 'admin_name' => $ctx['admin_name']],
        'customer'        => [
            'customer_id'  => (int)$cust['customer_id'],
            'name'         => $cust['name'],
            'email'        => $cust['email'],
            'phone'        => $cust['phone'],
            'cpf_masked'   => $maskedCpf,
            'member_since' => $cust['created_at'],
        ],
        'cards'           => $cards,
        'transactions'    => $transactions,
        'bills'           => $bills,
        'events'          => $events,
        'stats'           => [
            'n_cards'        => count($cards),
            'n_transactions' => count($transactions),
            'n_bills'        => count($bills),
            'n_events'       => count($events),
        ],
    ]);
} catch (Exception $e) {
    error_log('[card-support-export-data] ' . $e->getMessage());
    response(false, null, 'Erro ao exportar dados', 500);
}
