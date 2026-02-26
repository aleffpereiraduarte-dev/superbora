<?php
header("Content-Type: application/json; charset=utf-8");
session_start();
if (!isset($_SESSION["fin_user_id"])) { http_response_code(401); die(json_encode(["error" => "Não autenticado"])); }

$configPath = $_SERVER["DOCUMENT_ROOT"] . "/admin/config.php";
require_once $configPath;
$pdo = new PDO("mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$action = $_GET["action"] ?? "";

switch ($action) {
case "getSummary":
    $month = $_GET["month"] ?? date("Y-m");
    $data = [];
    
    // Saldo bancário
    $data["saldo_bancario"] = (float)($pdo->query("SELECT COALESCE(SUM(current_balance), 0) FROM om_fin_bank_accounts WHERE is_active = 1")->fetchColumn() ?: 0);
    
    // Receitas do mês
    $data["receitas_mes"] = (float)($pdo->query("SELECT COALESCE(SUM(received_value), 0) FROM om_fin_receivables WHERE DATE_FORMAT(receipt_date, \"%Y-%m\") = \"$month\"")->fetchColumn() ?: 0);
    
    // Despesas do mês
    $data["despesas_mes"] = (float)($pdo->query("SELECT COALESCE(SUM(paid_value), 0) FROM om_fin_payables WHERE DATE_FORMAT(payment_date, \"%Y-%m\") = \"$month\"")->fetchColumn() ?: 0);
    
    // Resultado
    $data["resultado_mes"] = $data["receitas_mes"] - $data["despesas_mes"];
    
    // A pagar hoje
    $data["pagar_hoje"] = (float)($pdo->query("SELECT COALESCE(SUM(net_value - paid_value), 0) FROM om_fin_payables WHERE due_date = CURRENT_DATE AND status IN (\"pendente\",\"aprovado\")")->fetchColumn() ?: 0);
    
    // A receber hoje
    $data["receber_hoje"] = (float)($pdo->query("SELECT COALESCE(SUM(net_value - received_value), 0) FROM om_fin_receivables WHERE due_date = CURRENT_DATE AND status = \"previsto\"")->fetchColumn() ?: 0);
    
    // Atrasados
    $data["pagar_atrasado"] = (float)($pdo->query("SELECT COALESCE(SUM(net_value - paid_value), 0) FROM om_fin_payables WHERE due_date < CURRENT_DATE AND status IN (\"pendente\",\"aprovado\")")->fetchColumn() ?: 0);
    $data["receber_atrasado"] = (float)($pdo->query("SELECT COALESCE(SUM(net_value - received_value), 0) FROM om_fin_receivables WHERE due_date < CURRENT_DATE AND status = \"previsto\"")->fetchColumn() ?: 0);
    
    // Aguardando aprovação
    $data["aguardando_aprovacao"] = (int)($pdo->query("SELECT COUNT(*) FROM om_fin_payables WHERE status = \"aguardando_aprovacao\"")->fetchColumn() ?: 0);
    $data["valor_aguardando"] = (float)($pdo->query("SELECT COALESCE(SUM(net_value), 0) FROM om_fin_payables WHERE status = \"aguardando_aprovacao\"")->fetchColumn() ?: 0);
    
    echo json_encode(["success" => true, "data" => $data]);
    break;

case "getCashFlow":
    $days = (int)($_GET["days"] ?? 15);
    $flow = [];
    for ($i = 0; $i < $days; $i++) {
        $date = date("Y-m-d", strtotime("+$i days"));
        $toPay = (float)($pdo->query("SELECT COALESCE(SUM(net_value - paid_value), 0) FROM om_fin_payables WHERE due_date = \"$date\" AND status IN (\"pendente\",\"aprovado\")")->fetchColumn() ?: 0);
        $toReceive = (float)($pdo->query("SELECT COALESCE(SUM(net_value - received_value), 0) FROM om_fin_receivables WHERE due_date = \"$date\" AND status = \"previsto\"")->fetchColumn() ?: 0);
        $flow[] = ["date" => $date, "to_pay" => $toPay, "to_receive" => $toReceive, "net" => $toReceive - $toPay];
    }
    echo json_encode(["success" => true, "cash_flow" => $flow]);
    break;

case "getExpensesByCategory":
    $month = $_GET["month"] ?? date("Y-m");
    $stmt = $pdo->query("SELECT COALESCE(c.name, \"Outros\") as category, COALESCE(SUM(p.paid_value), 0) as total FROM om_fin_payables p LEFT JOIN om_fin_expense_categories c ON c.category_id = p.category_id WHERE DATE_FORMAT(p.payment_date, \"%Y-%m\") = \"$month\" AND p.status = \"pago\" GROUP BY p.category_id ORDER BY total DESC LIMIT 10");
    echo json_encode(["success" => true, "expenses" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;

case "getMonthlyComparison":
    $months = (int)($_GET["months"] ?? 6);
    $comparison = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $m = date("Y-m", strtotime("-$i months"));
        $income = (float)($pdo->query("SELECT COALESCE(SUM(received_value), 0) FROM om_fin_receivables WHERE DATE_FORMAT(receipt_date, \"%Y-%m\") = \"$m\"")->fetchColumn() ?: 0);
        $expense = (float)($pdo->query("SELECT COALESCE(SUM(paid_value), 0) FROM om_fin_payables WHERE DATE_FORMAT(payment_date, \"%Y-%m\") = \"$m\"")->fetchColumn() ?: 0);
        $comparison[] = ["month" => $m, "income" => $income, "expense" => $expense, "result" => $income - $expense];
    }
    echo json_encode(["success" => true, "comparison" => $comparison]);
    break;

case "getRecentActivity":
    $limit = (int)($_GET["limit"] ?? 10);
    $stmt = $pdo->query("SELECT * FROM om_fin_audit_log ORDER BY created_at DESC LIMIT $limit");
    echo json_encode(["success" => true, "activities" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;

default:
    echo json_encode(["success" => false, "error" => "Ação inválida"]);
}
?>