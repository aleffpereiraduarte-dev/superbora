<?php
/**
 * GET /admin/card-support/dashboard.php
 *
 * Stats for the card-support dashboard header.
 *
 * Response.data = {
 *   total_cards, active_cards, blocked_cards, cancelled_cards,
 *   overdue_bills, overdue_amount,
 *   today_transactions, today_volume,
 *   open_tickets, urgent_tickets,
 *   flagged_transactions, avg_resolution_hours
 * }
 */

require_once __DIR__ . "/_common.php";

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $ctx = bootstrapCardSupport();
    $db = $ctx['db'];

    $cardStats = $db->query("
        SELECT
            COUNT(*)                                                   AS total_cards,
            COUNT(*) FILTER (WHERE status = 'active')                  AS active_cards,
            COUNT(*) FILTER (WHERE status = 'blocked')                 AS blocked_cards,
            COUNT(*) FILTER (WHERE status = 'cancelled')               AS cancelled_cards,
            COUNT(*) FILTER (WHERE status = 'pre_approved')            AS pre_approved_cards,
            COALESCE(SUM(credit_limit) FILTER (WHERE status = 'active'), 0) AS total_limit,
            COALESCE(SUM(used_limit)   FILTER (WHERE status = 'active'), 0) AS total_used
        FROM om_credit_cards
    ")->fetch(PDO::FETCH_ASSOC);

    $billStats = $db->query("
        SELECT
            COUNT(*) FILTER (WHERE due_date < CURRENT_DATE AND status != 'paid')                         AS overdue_bills,
            COALESCE(SUM(total_amount - paid_amount) FILTER (WHERE due_date < CURRENT_DATE AND status != 'paid'), 0) AS overdue_amount,
            COUNT(*) FILTER (WHERE status = 'open')                                                      AS open_bills,
            COALESCE(SUM(total_amount) FILTER (WHERE status = 'open'), 0)                                AS open_amount
        FROM om_credit_card_bills
    ")->fetch(PDO::FETCH_ASSOC);

    $txStats = $db->query("
        SELECT
            COUNT(*) FILTER (WHERE transaction_date >= CURRENT_DATE)             AS today_transactions,
            COALESCE(SUM(amount) FILTER (WHERE transaction_date >= CURRENT_DATE), 0) AS today_volume,
            COUNT(*) FILTER (WHERE status = 'flagged')                            AS flagged_transactions,
            COUNT(*) FILTER (WHERE status = 'declined')                           AS declined_today,
            COUNT(*) FILTER (WHERE transaction_date >= CURRENT_DATE - INTERVAL '7 days') AS week_transactions
        FROM om_credit_card_transactions
    ")->fetch(PDO::FETCH_ASSOC);

    // Tickets
    try {
        $ticketStats = $db->query("
            SELECT
                COUNT(*) FILTER (WHERE status IN ('open','in_progress'))          AS open_tickets,
                COUNT(*) FILTER (WHERE priority = 'urgent' AND status != 'closed' AND status != 'resolved') AS urgent_tickets,
                COUNT(*) FILTER (WHERE status = 'resolved')                        AS resolved_tickets,
                COALESCE(
                    EXTRACT(EPOCH FROM AVG(resolved_at - created_at)) / 3600.0,
                    0
                ) FILTER (WHERE resolved_at IS NOT NULL)                           AS avg_resolution_hours
            FROM om_card_support_tickets
        ")->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $ticketStats = ['open_tickets' => 0, 'urgent_tickets' => 0, 'resolved_tickets' => 0, 'avg_resolution_hours' => 0];
    }

    // Recent events sample (last 24h)
    try {
        $eventStats = $db->query("
            SELECT
                COUNT(*) FILTER (WHERE event_type = 'blocked')      AS blocks_24h,
                COUNT(*) FILTER (WHERE event_type = 'limit_changed')AS limit_changes_24h,
                COUNT(*) FILTER (WHERE event_type = 'activated')    AS activations_24h
            FROM om_credit_card_events
            WHERE created_at >= NOW() - INTERVAL '24 hours'
        ")->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $eventStats = ['blocks_24h' => 0, 'limit_changes_24h' => 0, 'activations_24h' => 0];
    }

    $totalLimit = (float)($cardStats['total_limit'] ?? 0);
    $totalUsed  = (float)($cardStats['total_used']  ?? 0);

    response(true, [
        'total_cards'          => (int)($cardStats['total_cards']          ?? 0),
        'active_cards'         => (int)($cardStats['active_cards']         ?? 0),
        'blocked_cards'        => (int)($cardStats['blocked_cards']        ?? 0),
        'cancelled_cards'      => (int)($cardStats['cancelled_cards']      ?? 0),
        'pre_approved_cards'   => (int)($cardStats['pre_approved_cards']   ?? 0),
        'total_limit'          => $totalLimit,
        'total_used'           => $totalUsed,
        'utilization_rate'     => $totalLimit > 0 ? round(($totalUsed / $totalLimit) * 100, 1) : 0,

        'overdue_bills'        => (int)($billStats['overdue_bills']        ?? 0),
        'overdue_amount'       => (float)($billStats['overdue_amount']     ?? 0),
        'open_bills'           => (int)($billStats['open_bills']           ?? 0),
        'open_amount'          => (float)($billStats['open_amount']        ?? 0),

        'today_transactions'   => (int)($txStats['today_transactions']     ?? 0),
        'today_volume'         => (float)($txStats['today_volume']         ?? 0),
        'flagged_transactions' => (int)($txStats['flagged_transactions']   ?? 0),
        'declined_today'       => (int)($txStats['declined_today']         ?? 0),
        'week_transactions'    => (int)($txStats['week_transactions']      ?? 0),

        'open_tickets'         => (int)($ticketStats['open_tickets']       ?? 0),
        'urgent_tickets'       => (int)($ticketStats['urgent_tickets']     ?? 0),
        'resolved_tickets'     => (int)($ticketStats['resolved_tickets']   ?? 0),
        'avg_resolution_hours' => round((float)($ticketStats['avg_resolution_hours'] ?? 0), 1),

        'blocks_24h'           => (int)($eventStats['blocks_24h']          ?? 0),
        'limit_changes_24h'    => (int)($eventStats['limit_changes_24h']   ?? 0),
        'activations_24h'      => (int)($eventStats['activations_24h']     ?? 0),
    ]);
} catch (Exception $e) {
    error_log('[card-support-dashboard] ' . $e->getMessage());
    response(false, null, 'Erro ao carregar dashboard', 500);
}
