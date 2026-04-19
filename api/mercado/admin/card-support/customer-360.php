<?php
/**
 * GET /admin/card-support/customer-360.php?customer_id=123
 *
 * Ultra-detailed customer view. Extends customer-detail.php with:
 *  - Payment history (per-payment details)
 *  - Risk history (score over time)
 *  - All cards with ALL tx counts
 *  - Cash flow summary
 *
 * Response.data: { ...same as customer-detail, plus payments, payments_summary, score_history, disputes }
 */

require_once __DIR__ . "/_common.php";

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $ctx = bootstrapCardSupport();
    $db = $ctx['db'];

    $customerId = (int)($_GET['customer_id'] ?? 0);
    if ($customerId <= 0) response(false, null, 'customer_id obrigatorio', 400);

    // Reuse the existing detail payload
    $_GET['customer_id'] = $customerId;
    ob_start();
    include __DIR__ . '/customer-detail.php';
    $detailJson = ob_get_clean();
    $detailData = json_decode($detailJson, true);
    if (!$detailData || !isset($detailData['data'])) {
        response(false, null, 'Erro ao carregar base', 500);
    }
    $data = $detailData['data'];

    // Payments from bills paid
    $payments = [];
    try {
        $stmt = $db->prepare("
            SELECT b.id AS bill_id, b.card_id, b.paid_amount, b.paid_at, b.total_amount
            FROM om_credit_card_bills b
            WHERE b.customer_id = ? AND b.paid_amount > 0
            ORDER BY b.paid_at DESC NULLS LAST
            LIMIT 50
        ");
        $stmt->execute([$customerId]);
        $payments = array_map(function ($r) {
            return [
                'bill_id'     => (int)$r['bill_id'],
                'card_id'     => (int)$r['card_id'],
                'amount'      => (float)$r['paid_amount'],
                'total'       => (float)$r['total_amount'],
                'paid_at'     => $r['paid_at'],
                'method'      => 'PIX',  // simplified until payment-method-tracking exists
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) { /* ignore */ }

    $paymentsSummary = [
        'on_time'   => 0,
        'late'      => 0,
        'total_paid'=> 0.0,
    ];
    try {
        $row = $db->query("
            SELECT
                COUNT(*) FILTER (WHERE paid_at IS NOT NULL AND (paid_at::date <= due_date)) AS on_time,
                COUNT(*) FILTER (WHERE paid_at IS NOT NULL AND (paid_at::date > due_date))  AS late,
                COALESCE(SUM(paid_amount), 0) AS total_paid
            FROM om_credit_card_bills
            WHERE customer_id = " . (int)$customerId . "
        ")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $paymentsSummary = [
                'on_time'   => (int)$row['on_time'],
                'late'      => (int)$row['late'],
                'total_paid'=> (float)$row['total_paid'],
            ];
        }
    } catch (Exception $e) { /* ignore */ }

    // Score history from evaluations
    $scoreHistory = [];
    try {
        $stmt = $db->prepare("
            SELECT overall_score, evaluated_at, ai_decision
            FROM om_credit_evaluations
            WHERE customer_id = ?
            ORDER BY evaluated_at ASC
            LIMIT 50
        ");
        $stmt->execute([$customerId]);
        $scoreHistory = array_map(function ($r) {
            return [
                'score'      => (int)$r['overall_score'],
                'at'         => $r['evaluated_at'],
                'decision'   => $r['ai_decision'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) { /* ignore */ }

    // Disputes (all fraud-category tickets)
    $disputes = [];
    try {
        $stmt = $db->prepare("
            SELECT id, subject, status, priority, created_at, resolved_at, resolution, dispute_reason_code
            FROM om_card_support_tickets
            WHERE customer_id = ? AND (category = 'fraud' OR dispute_reason_code IS NOT NULL)
            ORDER BY created_at DESC
        ");
        $stmt->execute([$customerId]);
        $disputes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) { /* ignore */ }

    $data['payments']         = $payments;
    $data['payments_summary'] = $paymentsSummary;
    $data['score_history']    = $scoreHistory;
    $data['disputes']         = $disputes;

    response(true, $data);
} catch (Exception $e) {
    error_log('[card-support-customer-360] ' . $e->getMessage());
    response(false, null, 'Erro no 360: ' . $e->getMessage(), 500);
}
