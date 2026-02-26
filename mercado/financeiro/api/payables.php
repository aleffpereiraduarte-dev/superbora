<?php
header("Content-Type: application/json; charset=utf-8");
session_start();
if (!isset($_SESSION["fin_user_id"])) { http_response_code(401); die(json_encode(["error" => "Não autenticado"])); }

$configPath = $_SERVER["DOCUMENT_ROOT"] . "/admin/config.php";
require_once $configPath;
$pdo = new PDO("mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$userId = $_SESSION["fin_rh_user_id"];
$perms = $_SESSION["fin_permissions"];
$action = $_GET["action"] ?? $_POST["action"] ?? "";

switch ($action) {
case "getStats":
    $stats = [
        "total" => (int)$pdo->query("SELECT COUNT(*) FROM om_fin_payables WHERE status != \"cancelado\"")->fetchColumn(),
        "pendentes" => (int)$pdo->query("SELECT COUNT(*) FROM om_fin_payables WHERE status IN (\"pendente\",\"aguardando_aprovacao\")")->fetchColumn(),
        "atrasados" => (int)$pdo->query("SELECT COUNT(*) FROM om_fin_payables WHERE due_date < CURRENT_DATE AND status IN (\"pendente\",\"aprovado\")")->fetchColumn(),
        "valor_pendente" => (float)$pdo->query("SELECT COALESCE(SUM(net_value - paid_value), 0) FROM om_fin_payables WHERE status IN (\"pendente\",\"aprovado\",\"aguardando_aprovacao\")")->fetchColumn(),
        "valor_atrasado" => (float)$pdo->query("SELECT COALESCE(SUM(net_value - paid_value), 0) FROM om_fin_payables WHERE due_date < CURRENT_DATE AND status IN (\"pendente\",\"aprovado\")")->fetchColumn(),
        "pago_mes" => (float)$pdo->query("SELECT COALESCE(SUM(paid_value), 0) FROM om_fin_payables WHERE status = \"pago\" AND MONTH(payment_date) = MONTH(CURRENT_DATE) AND YEAR(payment_date) = YEAR(CURRENT_DATE)")->fetchColumn()
    ];
    echo json_encode(["success" => true, "stats" => $stats]);
    break;

case "list":
    $status = $_GET["status"] ?? "";
    $limit = (int)($_GET["limit"] ?? 50);
    $sql = "SELECT p.*, s.company_name as supplier_company FROM om_fin_payables p LEFT JOIN om_fin_suppliers s ON s.supplier_id = p.supplier_id WHERE 1=1";
    $params = [];
    if ($status) { $sql .= " AND p.status = ?"; $params[] = $status; }
    $sql .= " ORDER BY p.due_date ASC LIMIT $limit";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(["success" => true, "payables" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;

case "save":
    if (!$perms["create"]) { echo json_encode(["success" => false, "error" => "Sem permissão"]); exit; }
    $data = json_decode(file_get_contents("php://input"), true);
    $grossValue = (float)($data["gross_value"] ?? 0);
    $discount = (float)($data["discount"] ?? 0);
    $netValue = $grossValue - $discount;
    
    $approvalLimit = (float)($pdo->query("SELECT COALESCE(setting_value, 5000) FROM om_fin_settings WHERE setting_key = \"approval_required_above\"")->fetchColumn() ?: 5000);
    $status = $netValue >= $approvalLimit ? "aguardando_aprovacao" : "pendente";
    
    $stmt = $pdo->prepare("INSERT INTO om_fin_payables (supplier_name, document_number, category_id, cost_center_id, gross_value, discount, net_value, due_date, description, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $data["supplier_name"] ?? "", $data["document_number"] ?? "", $data["category_id"] ?: null, $data["cost_center_id"] ?: null,
        $grossValue, $discount, $netValue, $data["due_date"], $data["description"] ?? "", $status, $userId
    ]);
    echo json_encode(["success" => true, "payable_id" => $pdo->lastInsertId()]);
    break;

case "approve":
    if (!$perms["approve"]) { echo json_encode(["success" => false, "error" => "Sem permissão"]); exit; }
    $id = (int)($_POST["payable_id"] ?? 0);
    $pdo->prepare("UPDATE om_fin_payables SET status = \"aprovado\", approved_by = ?, approved_at = NOW() WHERE payable_id = ?")->execute([$userId, $id]);
    echo json_encode(["success" => true]);
    break;

case "pay":
    if (!$perms["edit"]) { echo json_encode(["success" => false, "error" => "Sem permissão"]); exit; }
    $id = (int)($_POST["payable_id"] ?? 0);
    $paidValue = (float)($_POST["paid_value"] ?? 0);
    $paymentDate = $_POST["payment_date"] ?? date("Y-m-d");
    $bankAccountId = $_POST["bank_account_id"] ?: null;
    
    $stmt = $pdo->prepare("SELECT net_value, paid_value FROM om_fin_payables WHERE payable_id = ?");
    $stmt->execute([$id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $newPaid = $p["paid_value"] + $paidValue;
    $newStatus = $newPaid >= $p["net_value"] ? "pago" : "pago_parcial";
    
    $pdo->prepare("UPDATE om_fin_payables SET paid_value = ?, payment_date = ?, bank_account_id = ?, status = ? WHERE payable_id = ?")
        ->execute([$newPaid, $paymentDate, $bankAccountId, $newStatus, $id]);
    
    if ($bankAccountId) {
        $pdo->prepare("UPDATE om_fin_bank_accounts SET current_balance = current_balance - ? WHERE bank_account_id = ?")->execute([$paidValue, $bankAccountId]);
    }
    
    echo json_encode(["success" => true]);
    break;

default:
    echo json_encode(["success" => false, "error" => "Ação inválida"]);
}
?>