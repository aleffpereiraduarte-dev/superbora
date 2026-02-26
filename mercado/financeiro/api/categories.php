<?php
header("Content-Type: application/json; charset=utf-8");
session_start();
if (!isset($_SESSION["fin_user_id"])) { http_response_code(401); die(json_encode(["error" => "Não autenticado"])); }

$configPath = $_SERVER["DOCUMENT_ROOT"] . "/admin/config.php";
require_once $configPath;
$pdo = new PDO("mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$action = $_GET["action"] ?? "";
$type = $_GET["type"] ?? "expense";

switch ($action) {
case "list":
    $table = $type == "income" ? "om_fin_income_categories" : "om_fin_expense_categories";
    $stmt = $pdo->query("SELECT * FROM $table WHERE is_active = 1 ORDER BY name");
    echo json_encode(["success" => true, "categories" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;
default:
    echo json_encode(["success" => false, "error" => "Ação inválida"]);
}
?>