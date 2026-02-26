<?php
/**
 * API de Orçamento - Sistema Financeiro
 */
header("Content-Type: application/json; charset=utf-8");
session_start();

if (!isset($_SESSION["fin_user_id"])) {
    http_response_code(401);
    die(json_encode(["success" => false, "error" => "Não autenticado"]));
}

require_once dirname(__DIR__) . "/../admin/config.php";
$pdo = new PDO("mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$action = $_GET["action"] ?? $_POST["action"] ?? "";

switch ($action) {

case "list":
    $year = $_GET["year"] ?? date("Y");
    $costCenter = $_GET["cost_center_id"] ?? "";
    
    $sql = "SELECT b.*, cc.name as cost_center_name, c.name as category_name
            FROM om_fin_budgets b
            LEFT JOIN om_fin_cost_centers cc ON cc.cost_center_id = b.cost_center_id
            LEFT JOIN om_fin_expense_categories c ON c.category_id = b.category_id
            WHERE b.year = ?";
    $params = [$year];
    
    if ($costCenter) { $sql .= " AND b.cost_center_id = ?"; $params[] = $costCenter; }
    
    $sql .= " ORDER BY b.cost_center_id, b.category_id, b.month";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    echo json_encode(["success" => true, "budgets" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;

case "save":
    $data = json_decode(file_get_contents("php://input"), true);
    
    $pdo->prepare("INSERT INTO om_fin_budgets (year, month, cost_center_id, category_id, budget_value, notes, created_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE budget_value = VALUES(budget_value), notes = VALUES(notes)")
        ->execute([$data["year"], $data["month"] ?? null, $data["cost_center_id"] ?? null, $data["category_id"] ?? null, $data["budget_value"], $data["notes"] ?? null, $_SESSION["fin_rh_user_id"]]);
    
    echo json_encode(["success" => true]);
    break;

case "getComparison":
    $year = $_GET["year"] ?? date("Y");
    $month = $_GET["month"] ?? date("m");
    $costCenter = $_GET["cost_center_id"] ?? "";
    
    $sql = "SELECT 
                b.cost_center_id, cc.name as cost_center_name,
                b.category_id, c.name as category_name,
                b.budget_value,
                COALESCE((SELECT SUM(paid_value) FROM om_fin_payables p WHERE p.cost_center_id = b.cost_center_id AND (b.category_id IS NULL OR p.category_id = b.category_id) AND YEAR(p.payment_date) = b.year AND (b.month IS NULL OR MONTH(p.payment_date) = b.month) AND p.status = \"pago\"), 0) as realized_value
            FROM om_fin_budgets b
            LEFT JOIN om_fin_cost_centers cc ON cc.cost_center_id = b.cost_center_id
            LEFT JOIN om_fin_expense_categories c ON c.category_id = b.category_id
            WHERE b.year = ? AND (b.month IS NULL OR b.month = ?)";
    $params = [$year, $month];
    
    if ($costCenter) { $sql .= " AND b.cost_center_id = ?"; $params[] = $costCenter; }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($results as &$r) {
        $r["variance"] = $r["budget_value"] - $r["realized_value"];
        $r["percentage"] = $r["budget_value"] > 0 ? round(($r["realized_value"] / $r["budget_value"]) * 100, 1) : 0;
    }
    
    echo json_encode(["success" => true, "comparison" => $results]);
    break;

default:
    echo json_encode(["success" => false, "error" => "Ação inválida"]);
}
?>