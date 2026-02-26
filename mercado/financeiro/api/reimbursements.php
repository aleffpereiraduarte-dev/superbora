<?php
/**
 * API de Reembolsos - Sistema Financeiro
 */
header("Content-Type: application/json; charset=utf-8");
session_start();

if (!isset($_SESSION["fin_user_id"])) {
    http_response_code(401);
    die(json_encode(["success" => false, "error" => "Não autenticado"]));
}

require_once dirname(__DIR__) . "/../admin/config.php";
$pdo = new PDO("mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$userId = $_SESSION["fin_rh_user_id"];
$userName = $_SESSION["fin_user_name"];
$perms = $_SESSION["fin_permissions"];
$action = $_GET["action"] ?? $_POST["action"] ?? "";

switch ($action) {

case "list":
    $status = $_GET["status"] ?? "";
    $employee = $_GET["employee_id"] ?? "";
    
    $sql = "SELECT r.*, u.full_name as employee_name_full, cc.name as cost_center_name
            FROM om_fin_reimbursements r
            LEFT JOIN om_rh_users u ON u.rh_user_id = r.rh_user_id
            LEFT JOIN om_fin_cost_centers cc ON cc.cost_center_id = r.cost_center_id
            WHERE 1=1";
    $params = [];
    
    if ($status) { $sql .= " AND r.status = ?"; $params[] = $status; }
    if ($employee) { $sql .= " AND r.rh_user_id = ?"; $params[] = $employee; }
    
    $sql .= " ORDER BY r.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    echo json_encode(["success" => true, "reimbursements" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;

case "get":
    $id = (int)($_GET["id"] ?? 0);
    $stmt = $pdo->prepare("SELECT r.*, u.full_name FROM om_fin_reimbursements r LEFT JOIN om_rh_users u ON u.rh_user_id = r.rh_user_id WHERE r.reimbursement_id = ?");
    $stmt->execute([$id]);
    echo json_encode(["success" => true, "reimbursement" => $stmt->fetch(PDO::FETCH_ASSOC)]);
    break;

case "approve":
    if (!$perms["approve"]) {
        echo json_encode(["success" => false, "error" => "Sem permissão"]);
        exit;
    }
    
    $id = (int)($_POST["reimbursement_id"] ?? 0);
    $notes = $_POST["notes"] ?? "";
    
    $pdo->prepare("UPDATE om_fin_reimbursements SET status = \"aprovado_financeiro\", finance_approved_by = ?, finance_approved_at = NOW(), finance_notes = ? WHERE reimbursement_id = ?")
        ->execute([$userId, $notes, $id]);
    
    echo json_encode(["success" => true]);
    break;

case "reject":
    $id = (int)($_POST["reimbursement_id"] ?? 0);
    $reason = $_POST["reason"] ?? "";
    
    $pdo->prepare("UPDATE om_fin_reimbursements SET status = \"rejeitado\", rejection_reason = ? WHERE reimbursement_id = ?")
        ->execute([$reason, $id]);
    
    echo json_encode(["success" => true]);
    break;

case "pay":
    $id = (int)($_POST["reimbursement_id"] ?? 0);
    $paymentMethod = $_POST["payment_method"] ?? "pix";
    $bankAccountId = $_POST["bank_account_id"] ?? null;
    
    $stmt = $pdo->prepare("SELECT * FROM om_fin_reimbursements WHERE reimbursement_id = ?");
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Criar conta a pagar
    $pdo->prepare("INSERT INTO om_fin_payables (supplier_name, category_id, cost_center_id, gross_value, net_value, due_date, payment_method, status, description, created_by) VALUES (?,?,?,?,?,CURRENT_DATE,?,\"pago\",?,?)")
        ->execute(["Reembolso: " . $r["employee_name"], null, $r["cost_center_id"], $r["amount"], $r["amount"], $paymentMethod, $r["description"], $userId]);
    $payableId = $pdo->lastInsertId();
    
    // Atualizar reembolso
    $pdo->prepare("UPDATE om_fin_reimbursements SET status = \"pago\", payment_date = CURRENT_DATE, payment_method = ?, bank_account_id = ?, payable_id = ? WHERE reimbursement_id = ?")
        ->execute([$paymentMethod, $bankAccountId, $payableId, $id]);
    
    echo json_encode(["success" => true]);
    break;

case "getStats":
    $stats = [];
    $stats["pendentes"] = $pdo->query("SELECT COUNT(*) FROM om_fin_reimbursements WHERE status IN (\"solicitado\",\"em_analise\",\"aprovado_supervisor\")")->fetchColumn();
    $stats["aprovados"] = $pdo->query("SELECT COUNT(*) FROM om_fin_reimbursements WHERE status = \"aprovado_financeiro\"")->fetchColumn();
    $stats["valor_pendente"] = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM om_fin_reimbursements WHERE status IN (\"solicitado\",\"em_analise\",\"aprovado_supervisor\",\"aprovado_financeiro\")")->fetchColumn();
    echo json_encode(["success" => true, "stats" => $stats]);
    break;

default:
    echo json_encode(["success" => false, "error" => "Ação inválida"]);
}
?>