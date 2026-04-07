<?php
/**
 * Cron: Corporate monthly report
 *
 * On the 1st of each month at 8 AM, generates a monthly summary for each
 * active corporate account: spending per department, top employees, top stores,
 * compared to previous month, with AI-written recommendations.
 *
 * Schedule: 0 8 1 * *
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';

$db = getDB();
$lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
$lastMonthEnd = date('Y-m-t', strtotime('first day of last month'));

$accounts = $db->query("SELECT id, company_name, monthly_budget FROM corporate_accounts WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
if (empty($accounts)) {
    if (PHP_SAPI === 'cli') fwrite(STDOUT, "[corporate-report] no active accounts\n");
    exit(0);
}

$generated = 0;

foreach ($accounts as $acc) {
    $accId = (int)$acc['id'];

    // Total spent
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(amount),0), COUNT(*)
         FROM corporate_orders
         WHERE account_id = :aid
           AND DATE(created_at) BETWEEN :s AND :e
           AND status = 'approved'"
    );
    $stmt->execute([':aid' => $accId, ':s' => $lastMonthStart, ':e' => $lastMonthEnd]);
    [$totalSpent, $orderCount] = $stmt->fetch(PDO::FETCH_NUM);

    if ((float)$totalSpent < 1) continue;

    // Per-department breakdown
    $stmt = $db->prepare(
        "SELECT d.name, COUNT(co.id) AS qty, COALESCE(SUM(co.amount),0) AS spent
         FROM corporate_orders co
         JOIN corporate_employees e ON e.id = co.employee_id
         LEFT JOIN corporate_departments d ON d.id = e.department_id
         WHERE co.account_id = :aid
           AND DATE(co.created_at) BETWEEN :s AND :e
           AND co.status = 'approved'
         GROUP BY d.name ORDER BY spent DESC"
    );
    $stmt->execute([':aid' => $accId, ':s' => $lastMonthStart, ':e' => $lastMonthEnd]);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Previous month comparison
    $prevStart = date('Y-m-01', strtotime('first day of -2 month'));
    $prevEnd = date('Y-m-t', strtotime('first day of -2 month'));
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(amount),0)
         FROM corporate_orders
         WHERE account_id = :aid
           AND DATE(created_at) BETWEEN :s AND :e
           AND status = 'approved'"
    );
    $stmt->execute([':aid' => $accId, ':s' => $prevStart, ':e' => $prevEnd]);
    $prevSpent = (float)$stmt->fetchColumn();

    $data = [
        'company' => $acc['company_name'],
        'month' => date('m/Y', strtotime($lastMonthStart)),
        'total_spent' => (float)$totalSpent,
        'order_count' => (int)$orderCount,
        'monthly_budget' => (float)$acc['monthly_budget'],
        'budget_used_pct' => $acc['monthly_budget'] > 0 ? round(((float)$totalSpent / (float)$acc['monthly_budget']) * 100, 1) : null,
        'prev_month_spent' => $prevSpent,
        'delta_pct' => $prevSpent > 0 ? round((((float)$totalSpent - $prevSpent) / $prevSpent) * 100, 1) : null,
        'departments' => $departments,
    ];

    $prompt = "Gere um relatorio mensal CURTO em pt-BR para o RH da empresa parceira do SuperBora. " .
              "Formato texto plano, max 1500 chars. Use bullets. " .
              "Inclua: total gasto, comparacao com mes anterior, top 3 departamentos, 1 insight, 1 recomendacao.\n\n" .
              "Dados:\n" . json_encode($data, JSON_UNESCAPED_UNICODE);

    $report = ClaudeClient::text(
        $prompt,
        'Voce eh o controller virtual do programa corporativo SuperBora.',
        1500
    );
    if (!$report) continue;

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS corporate_monthly_reports (
            id BIGSERIAL PRIMARY KEY,
            account_id INTEGER NOT NULL,
            month DATE NOT NULL,
            data_json JSONB,
            report_text TEXT,
            sent_at TIMESTAMPTZ,
            created_at TIMESTAMPTZ DEFAULT NOW(),
            UNIQUE(account_id, month)
        )");
        $db->prepare(
            "INSERT INTO corporate_monthly_reports (account_id, month, data_json, report_text)
             VALUES (:aid, :m, :d, :r)
             ON CONFLICT (account_id, month) DO UPDATE SET data_json = EXCLUDED.data_json, report_text = EXCLUDED.report_text"
        )->execute([
            ':aid' => $accId,
            ':m' => $lastMonthStart,
            ':d' => json_encode($data),
            ':r' => $report,
        ]);
        $generated++;
    } catch (Exception $e) {
        error_log('[corporate-report] ' . $e->getMessage());
    }
}

if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, "[corporate-report] generated {$generated} reports\n");
}
