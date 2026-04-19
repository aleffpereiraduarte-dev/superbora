<?php
/**
 * POST /admin/card-support/bills-bulk-action.php
 *
 * Bulk actions on bills.
 *
 * Body: {
 *   action:    send_reminder|mark_reminder_sent|export_csv|waive_interest,
 *   bill_ids?: [int, ...]  // if missing, uses filter
 *   filter?:   { status, month, year, ... }  // same as bills-list.php
 *   channels?: [push|whatsapp|email]  // for send_reminder
 *   message?:  string
 * }
 *
 * Response.data: { affected, ... }
 */

require_once __DIR__ . "/_common.php";

try {
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, ['POST','GET'])) {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $ctx = bootstrapCardSupport();
    $db = $ctx['db'];
    $adminId = $ctx['admin_id'];

    $body = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
    $action = (string)($body['action'] ?? $_GET['action'] ?? '');
    $billIds = $body['bill_ids'] ?? null;
    $channels = $body['channels'] ?? ['push'];
    $messageBody = (string)($body['message'] ?? '');

    if ($action === 'export_csv' || $method === 'GET') {
        // CSV export: streams bills
        $filter = $body['filter'] ?? $_GET;
        $status = (string)($filter['status'] ?? 'all');
        $month = (int)($filter['month'] ?? 0);
        $year = (int)($filter['year'] ?? 0);

        $conditions = ['1=1']; $params = [];
        if ($status !== 'all' && $status !== '') {
            if ($status === 'overdue') {
                $conditions[] = "b.due_date < CURRENT_DATE AND b.status != 'paid'";
            } else {
                $conditions[] = 'b.status = ?';
                $params[] = $status;
            }
        }
        if ($month > 0 && $year >= 2020) {
            $conditions[] = 'EXTRACT(MONTH FROM b.period_start) = ? AND EXTRACT(YEAR FROM b.period_start) = ?';
            $params[] = $month; $params[] = $year;
        }
        $whereSql = implode(' AND ', $conditions);
        $stmt = $db->prepare("
            SELECT b.id, b.due_date, b.total_amount, b.paid_amount, b.status,
                   c.name, c.email, c.phone, cc.card_last4
            FROM om_credit_card_bills b
            LEFT JOIN om_customers c ON c.customer_id = b.customer_id
            LEFT JOIN om_credit_cards cc ON cc.id = b.card_id
            WHERE {$whereSql}
            ORDER BY b.due_date DESC LIMIT 5000
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="faturas_export_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Bill ID','Cliente','Email','Telefone','Last4','Vencimento','Total','Pago','Saldo','Status']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'],
                $r['name'],
                $r['email'],
                $r['phone'],
                $r['card_last4'],
                $r['due_date'],
                $r['total_amount'],
                $r['paid_amount'],
                (float)$r['total_amount'] - (float)$r['paid_amount'],
                $r['status'],
            ]);
        }
        fclose($out);
        exit;
    }

    if ($action === 'send_reminder') {
        if (!is_array($billIds) || empty($billIds)) {
            response(false, null, 'bill_ids obrigatorio', 400);
        }
        $billIds = array_values(array_filter(array_map('intval', $billIds), fn($i) => $i > 0));
        if (!$billIds) response(false, null, 'bill_ids invalidos', 400);

        $ph = implode(',', array_fill(0, count($billIds), '?'));
        $stmt = $db->prepare("
            SELECT b.id, b.card_id, b.customer_id, b.total_amount, b.paid_amount, b.due_date,
                   c.name, c.phone
            FROM om_credit_card_bills b
            LEFT JOIN om_customers c ON c.customer_id = b.customer_id
            WHERE b.id IN ($ph)
        ");
        $stmt->execute($billIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sent = 0;
        foreach ($rows as $r) {
            try {
                // Log a support event; real notification dispatch is out of scope here
                logCardSupportEvent($db, (int)$r['card_id'], (int)$r['customer_id'], 'reminder_sent', $adminId, [
                    'bill_id'  => (int)$r['id'],
                    'channels' => $channels,
                    'message'  => $messageBody,
                ], 'Lembrete de cobranca enviado');
                $sent++;
            } catch (Exception $e) { /* ignore individual failures */ }
        }
        response(true, ['affected' => $sent, 'total' => count($rows)]);
    }

    if ($action === 'waive_interest') {
        if (!is_array($billIds) || empty($billIds)) response(false, null, 'bill_ids obrigatorio', 400);
        $billIds = array_values(array_filter(array_map('intval', $billIds), fn($i) => $i > 0));
        if (!$billIds) response(false, null, 'bill_ids invalidos', 400);
        $ph = implode(',', array_fill(0, count($billIds), '?'));
        // We model waive_interest as marking bill amount = paid_amount (0 owed)
        $stmt = $db->prepare("
            UPDATE om_credit_card_bills
            SET total_amount = paid_amount, status = 'paid', paid_at = NOW()
            WHERE id IN ($ph) AND status != 'paid'
        ");
        $stmt->execute($billIds);
        $affected = $stmt->rowCount();

        foreach ($billIds as $bid) {
            try {
                $info = $db->prepare("SELECT card_id, customer_id FROM om_credit_card_bills WHERE id = ?");
                $info->execute([$bid]);
                $r = $info->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    logCardSupportEvent($db, (int)$r['card_id'], (int)$r['customer_id'], 'interest_waived', $adminId, ['bill_id' => $bid], 'Juros perdoados via suporte');
                }
            } catch (Exception $e) { /* ignore */ }
        }
        response(true, ['affected' => $affected]);
    }

    response(false, null, 'Acao desconhecida', 400);
} catch (Exception $e) {
    error_log('[card-support-bills-bulk] ' . $e->getMessage());
    response(false, null, 'Erro na acao em lote: ' . $e->getMessage(), 500);
}
