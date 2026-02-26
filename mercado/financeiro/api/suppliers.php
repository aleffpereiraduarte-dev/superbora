<?php
header("Content-Type: application/json; charset=utf-8");
session_start();
if (!isset($_SESSION["fin_user_id"])) { http_response_code(401); die(json_encode(["error" => "Não autenticado"])); }

$configPath = $_SERVER["DOCUMENT_ROOT"] . "/admin/config.php";
require_once $configPath;
$pdo = new PDO("mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$action = $_GET["action"] ?? $_POST["action"] ?? "";

switch ($action) {
case "list":
    $search = $_GET["search"] ?? "";
    $sql = "SELECT * FROM om_fin_suppliers WHERE is_active = 1";
    $params = [];
    if ($search) {
        $sql .= " AND (company_name LIKE ? OR trade_name LIKE ? OR cpf_cnpj LIKE ?)";
        $s = "%$search%";
        $params = [$s, $s, $s];
    }
    $sql .= " ORDER BY company_name LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(["success" => true, "suppliers" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;

case "get":
    $id = (int)($_GET["id"] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM om_fin_suppliers WHERE supplier_id = ?");
    $stmt->execute([$id]);
    echo json_encode(["success" => true, "supplier" => $stmt->fetch(PDO::FETCH_ASSOC)]);
    break;

default:
    echo json_encode(["success" => false, "error" => "Ação inválida"]);
}
?>