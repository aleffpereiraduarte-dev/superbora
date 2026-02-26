<?php
header("Content-Type: application/json; charset=utf-8");
session_start();
if (!isset($_SESSION["fin_user_id"])) { http_response_code(401); die(json_encode(["error" => "Não autenticado"])); }
require_once dirname(__DIR__) . "/../admin/config.php";
$pdo = new PDO("mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD);
$action = $_GET["action"] ?? "";

switch ($action) {
case "list":
    $type = $_GET["type"] ?? "";
    $analytical = $_GET["analytical"] ?? "";
    $sql = "SELECT * FROM om_fin_chart_accounts WHERE is_active = 1";
    if ($type) $sql .= " AND account_type = \"$type\"";
    if ($analytical !== "") $sql .= " AND is_analytical = $analytical";
    $sql .= " ORDER BY account_code";
    echo json_encode(["success" => true, "accounts" => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)]);
    break;
default:
    echo json_encode(["error" => "Ação inválida"]);
}
?>