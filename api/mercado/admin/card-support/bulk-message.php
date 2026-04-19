<?php
/**
 * POST /admin/card-support/bulk-message.php
 *
 * Send a message to many customers at once.
 *
 * Body:
 *   customer_ids: [int,...]
 *   OR target:    'overdue'|'high_util'|'pre_approved'|'all_active'
 *   title:        string
 *   message:      string
 *   channels:     [push|whatsapp|email]
 *
 * Response.data: { sent, skipped, customers_targeted }
 */

require_once __DIR__ . "/_common.php";

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $ctx = bootstrapCardSupport();
    $db = $ctx['db'];
    $adminId = $ctx['admin_id'];

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $customerIds = $body['customer_ids'] ?? null;
    $target = (string)($body['target'] ?? '');
    $title = trim((string)($body['title'] ?? 'SuperBora'));
    $message = trim((string)($body['message'] ?? ''));
    $channels = $body['channels'] ?? ['push'];

    if ($message === '') response(false, null, 'message obrigatorio', 400);

    if (!$customerIds && $target !== '') {
        // Derive customer IDs from target
        $sql = null;
        switch ($target) {
            case 'overdue':
                $sql = "SELECT DISTINCT customer_id FROM om_credit_card_bills WHERE due_date < CURRENT_DATE AND status != 'paid'";
                break;
            case 'high_util':
                $sql = "SELECT DISTINCT customer_id FROM om_credit_cards WHERE status='active' AND credit_limit > 0 AND (used_limit/credit_limit) >= 0.9";
                break;
            case 'pre_approved':
                $sql = "SELECT DISTINCT customer_id FROM om_credit_cards WHERE status='pre_approved' AND accepted_at IS NULL AND declined_at IS NULL";
                break;
            case 'all_active':
                $sql = "SELECT DISTINCT customer_id FROM om_credit_cards WHERE status = 'active'";
                break;
            default:
                response(false, null, 'target invalido', 400);
        }
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        $customerIds = array_map('intval', $rows);
    }

    if (!is_array($customerIds) || empty($customerIds)) {
        response(false, null, 'customer_ids ou target obrigatorio', 400);
    }

    $sent = 0; $skipped = 0;
    foreach ($customerIds as $cid) {
        $cid = (int)$cid;
        if ($cid <= 0) { $skipped++; continue; }
        try {
            logCardSupportEvent($db, 0, $cid, 'bulk_message', $adminId, [
                'title'    => $title,
                'message'  => $message,
                'channels' => $channels,
            ], 'Mensagem em lote');
            $sent++;
        } catch (Exception $e) {
            $skipped++;
        }
    }

    response(true, [
        'sent'               => $sent,
        'skipped'            => $skipped,
        'customers_targeted' => count($customerIds),
    ]);
} catch (Exception $e) {
    error_log('[card-support-bulk-message] ' . $e->getMessage());
    response(false, null, 'Erro em bulk-message: ' . $e->getMessage(), 500);
}
