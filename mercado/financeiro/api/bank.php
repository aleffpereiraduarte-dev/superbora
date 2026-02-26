<?php
header("Content-Type: application/json; charset=utf-8");
session_start();
if (!isset($_SESSION["fin_user_id"])) { http_response_code(401); die(json_encode(["error" => "Não autenticado"])); }

$configPath = $_SERVER["DOCUMENT_ROOT"] . "/admin/config.php";
require_once $configPath;
$pdo = new PDO("mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$action = $_GET["action"] ?? $_POST["action"] ?? "";

switch ($action) {
case "getAccounts":
    $stmt = $pdo->query("SELECT * FROM om_fin_bank_accounts WHERE is_active = 1 ORDER BY is_main DESC, account_name");
    echo json_encode(["success" => true, "accounts" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;

case "getSummary":
    $accounts = $pdo->query("SELECT bank_account_id, account_name, bank_name, current_balance FROM om_fin_bank_accounts WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    $total = array_sum(array_column($accounts, "current_balance"));
    echo json_encode(["success" => true, "accounts" => $accounts, "total_balance" => $total]);
    break;

default:
    echo json_encode(["success" => false, "error" => "Ação inválida"]);
}
?>