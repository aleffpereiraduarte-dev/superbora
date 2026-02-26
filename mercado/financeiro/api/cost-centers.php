<?php
header("Content-Type: application/json; charset=utf-8");
session_start();
if (!isset($_SESSION["fin_user_id"])) { http_response_code(401); die(json_encode(["error" => "Não autenticado"])); }

$configPath = $_SERVER["DOCUMENT_ROOT"] . "/admin/config.php";
require_once $configPath;
$pdo = new PDO("mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$action = $_GET["action"] ?? "";

switch ($action) {
case "list":
    $stmt = $pdo->query("SELECT * FROM om_fin_cost_centers WHERE is_active = 1 ORDER BY code");
    echo json_encode(["success" => true, "cost_centers" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;
default:
    echo json_encode(["success" => false, "error" => "Ação inválida"]);
}
?>