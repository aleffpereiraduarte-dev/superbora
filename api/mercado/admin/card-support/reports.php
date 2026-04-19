<?php
/**
 * GET /admin/card-support/reports.php?type=...
 *
 * Pre-built reports:
 *   revenue_monthly|new_vs_cancelled|churn|ticket_avg|utilization_by_score|default_rate|fraud|payments_ontime
 *
 * Query: type, months (default 12), format=json|csv
 */

require_once __DIR__ . "/_common.php";

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $ctx = bootstrapCardSupport();
    $db = $ctx['db'];

    $type   = (string)($_GET['type'] ?? 'revenue_monthly');
    $months = min(24, max(1, (int)($_GET['months'] ?? 12)));
    $format = (string)($_GET['format'] ?? 'json');

    $rows = [];
    $columns = [];

    switch ($type) {
        case 'revenue_monthly':
            $columns = ['month','interest','iof','total_bills_count'];
            $r = $db->query("
                SELECT to_char(date_trunc('month', b.period_start), 'YYYY-MM') AS month,
                       COALESCE(SUM(b.total_amount - COALESCE((
                           SELECT SUM(t.amount) FROM om_credit_card_transactions t
                           WHERE t.bill_id = b.id AND t.status = 'approved'
                       ), 0)), 0) AS interest,
                       COALESCE(SUM(b.total_amount) * 0.0068, 0) AS iof,
                       COUNT(*) AS total_bills_count
                FROM om_credit_card_bills b
                WHERE b.period_start >= date_trunc('month', CURRENT_DATE) - (INTERVAL '1 month' * $months)
                GROUP BY 1
                ORDER BY 1
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($r as $x) {
                $rows[] = [
                    'month' => $x['month'],
                    'interest' => (float)$x['interest'],
                    'iof' => (float)$x['iof'],
                    'total_bills_count' => (int)$x['total_bills_count'],
                ];
            }
            break;

        case 'new_vs_cancelled':
            $columns = ['month','new_cards','cancelled_cards','net'];
            $r = $db->query("
                SELECT to_char(date_trunc('month', created_at), 'YYYY-MM') AS month,
                       COUNT(*) FILTER (WHERE status NOT IN ('cancelled','offer_expired','rejected')) AS new_cards,
                       COUNT(*) FILTER (WHERE status IN ('cancelled','offer_expired','rejected')) AS cancelled_cards
                FROM om_credit_cards
                WHERE created_at >= date_trunc('month', CURRENT_DATE) - (INTERVAL '1 month' * $months)
                GROUP BY 1
                ORDER BY 1
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($r as $x) {
                $n = (int)$x['new_cards']; $c = (int)$x['cancelled_cards'];
                $rows[] = ['month' => $x['month'], 'new_cards' => $n, 'cancelled_cards' => $c, 'net' => $n - $c];
            }
            break;

        case 'churn':
            $columns = ['month','cancelled','active_at_start','rate_pct'];
            $r = $db->query("
                SELECT to_char(date_trunc('month', cancelled_at), 'YYYY-MM') AS month,
                       COUNT(*) AS cancelled
                FROM om_credit_cards
                WHERE cancelled_at IS NOT NULL
                  AND cancelled_at >= date_trunc('month', CURRENT_DATE) - (INTERVAL '1 month' * $months)
                GROUP BY 1 ORDER BY 1
            ")->fetchAll(PDO::FETCH_ASSOC);
            $activeTotal = (int)$db->query("SELECT COUNT(*) FROM om_credit_cards WHERE status = 'active'")->fetchColumn();
            foreach ($r as $x) {
                $c = (int)$x['cancelled'];
                $rate = $activeTotal > 0 ? round(($c / $activeTotal) * 100, 2) : 0;
                $rows[] = ['month' => $x['month'], 'cancelled' => $c, 'active_at_start' => $activeTotal, 'rate_pct' => $rate];
            }
            break;

        case 'ticket_avg':
            $columns = ['month','bill_count','avg_ticket','min_ticket','max_ticket'];
            $r = $db->query("
                SELECT to_char(date_trunc('month', b.period_start), 'YYYY-MM') AS month,
                       COUNT(*) AS bill_count,
                       COALESCE(AVG(b.total_amount), 0) AS avg_ticket,
                       COALESCE(MIN(b.total_amount), 0) AS min_ticket,
                       COALESCE(MAX(b.total_amount), 0) AS max_ticket
                FROM om_credit_card_bills b
                WHERE b.period_start >= date_trunc('month', CURRENT_DATE) - (INTERVAL '1 month' * $months)
                GROUP BY 1 ORDER BY 1
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($r as $x) {
                $rows[] = [
                    'month'      => $x['month'],
                    'bill_count' => (int)$x['bill_count'],
                    'avg_ticket' => (float)$x['avg_ticket'],
                    'min_ticket' => (float)$x['min_ticket'],
                    'max_ticket' => (float)$x['max_ticket'],
                ];
            }
            break;

        case 'utilization_by_score':
            $columns = ['band','avg_utilization','active_cards'];
            $r = $db->query("
                SELECT
                    CASE
                        WHEN score IS NULL OR score < 400 THEN 'Critico'
                        WHEN score < 550 THEN 'Baixo'
                        WHEN score < 700 THEN 'Regular'
                        WHEN score < 850 THEN 'Bom'
                        ELSE 'Excelente'
                    END AS band,
                    COALESCE(AVG(used_limit / NULLIF(credit_limit,0)) * 100, 0) AS avg_utilization,
                    COUNT(*) AS active_cards
                FROM om_credit_cards
                WHERE status = 'active'
                GROUP BY 1
                ORDER BY 1
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($r as $x) {
                $rows[] = [
                    'band'            => $x['band'],
                    'avg_utilization' => round((float)$x['avg_utilization'], 1),
                    'active_cards'    => (int)$x['active_cards'],
                ];
            }
            break;

        case 'default_rate':
            $columns = ['month','total_bills','defaulted','rate_pct'];
            $r = $db->query("
                SELECT to_char(date_trunc('month', b.due_date), 'YYYY-MM') AS month,
                       COUNT(*) AS total_bills,
                       COUNT(*) FILTER (WHERE b.status != 'paid' AND b.due_date < CURRENT_DATE - INTERVAL '90 days') AS defaulted
                FROM om_credit_card_bills b
                WHERE b.due_date >= date_trunc('month', CURRENT_DATE) - (INTERVAL '1 month' * $months)
                GROUP BY 1 ORDER BY 1
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($r as $x) {
                $tb = (int)$x['total_bills']; $d = (int)$x['defaulted'];
                $rate = $tb > 0 ? round(($d / $tb) * 100, 2) : 0;
                $rows[] = ['month' => $x['month'], 'total_bills' => $tb, 'defaulted' => $d, 'rate_pct' => $rate];
            }
            break;

        case 'fraud':
            $columns = ['month','flagged','declined','total_tx','false_positive_rate'];
            $r = $db->query("
                SELECT to_char(date_trunc('month', transaction_date), 'YYYY-MM') AS month,
                       COUNT(*) FILTER (WHERE status = 'flagged')  AS flagged,
                       COUNT(*) FILTER (WHERE status = 'declined') AS declined,
                       COUNT(*) AS total_tx
                FROM om_credit_card_transactions
                WHERE transaction_date >= date_trunc('month', CURRENT_DATE) - (INTERVAL '1 month' * $months)
                GROUP BY 1 ORDER BY 1
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($r as $x) {
                $rows[] = [
                    'month'               => $x['month'],
                    'flagged'             => (int)$x['flagged'],
                    'declined'            => (int)$x['declined'],
                    'total_tx'            => (int)$x['total_tx'],
                    'false_positive_rate' => 0.0,  // needs feedback loop
                ];
            }
            break;

        case 'payments_ontime':
            $columns = ['month','on_time','late','on_time_pct'];
            $r = $db->query("
                SELECT to_char(date_trunc('month', paid_at), 'YYYY-MM') AS month,
                       COUNT(*) FILTER (WHERE paid_at::date <= due_date) AS on_time,
                       COUNT(*) FILTER (WHERE paid_at::date >  due_date) AS late
                FROM om_credit_card_bills
                WHERE paid_at IS NOT NULL
                  AND paid_at >= date_trunc('month', CURRENT_DATE) - (INTERVAL '1 month' * $months)
                GROUP BY 1 ORDER BY 1
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($r as $x) {
                $ot = (int)$x['on_time']; $l = (int)$x['late'];
                $total = $ot + $l;
                $pct = $total > 0 ? round(($ot / $total) * 100, 1) : 0;
                $rows[] = ['month' => $x['month'], 'on_time' => $ot, 'late' => $l, 'on_time_pct' => $pct];
            }
            break;

        default:
            response(false, null, 'Tipo de relatorio invalido', 400);
    }

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="report_' . $type . '_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $columns);
        foreach ($rows as $r) {
            $line = [];
            foreach ($columns as $col) { $line[] = $r[$col] ?? ''; }
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }

    response(true, [
        'type'    => $type,
        'columns' => $columns,
        'rows'    => $rows,
    ]);
} catch (Exception $e) {
    error_log('[card-support-reports] ' . $e->getMessage());
    response(false, null, 'Erro no relatorio: ' . $e->getMessage(), 500);
}
