<?php
header("Content-Type: application/json; charset=utf-8");
session_start();
if (!isset($_SESSION["fin_user_id"])) { http_response_code(401); die(json_encode(["error" => "Não autenticado"])); }

$configPath = $_SERVER["DOCUMENT_ROOT"] . "/admin/config.php";
require_once $configPath;
$pdo = new PDO("mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$action = $_GET["action"] ?? $_POST["action"] ?? "";

switch ($action) {
case "getStats":
    $stats = [
        "total" => (int)$pdo->query("SELECT COUNT(*) FROM om_fin_receivables WHERE status != \"cancelado\"")->fetchColumn(),
        "previstos" => (int)$pdo->query("SELECT COUNT(*) FROM om_fin_receivables WHERE status = \"previsto\"")->fetchColumn(),
        "valor_previsto" => (float)$pdo->query("SELECT COALESCE(SUM(net_value - received_value), 0) FROM om_fin_receivables WHERE status = \"previsto\"")->fetchColumn(),
        "recebido_mes" => (float)$pdo->query("SELECT COALESCE(SUM(received_value), 0) FROM om_fin_receivables WHERE status = \"recebido\" AND MONTH(receipt_date) = MONTH(CURRENT_DATE)")->fetchColumn()
    ];
    echo json_encode(["success" => true, "stats" => $stats]);
    break;

case "list":
    $limit = (int)($_GET["limit"] ?? 50);
    $stmt = $pdo->query("SELECT * FROM om_fin_receivables ORDER BY due_date ASC LIMIT $limit");
    echo json_encode(["success" => true, "receivables" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;

default:
    echo json_encode(["success" => false, "error" => "Ação inválida"]);
}
?>