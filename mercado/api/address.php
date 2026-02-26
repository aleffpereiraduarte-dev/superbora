<?php
require_once dirname(__DIR__) . '/config/database.php';
header("Content-Type: application/json");
if (session_status() === PHP_SESSION_NONE) session_start();

try {
    $pdo = getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(["success" => false]);
    exit;
}

$customer_id = 0;
$ocsessid = $_COOKIE["OCSESSID"] ?? "";
if ($ocsessid) {
    $stmt = $pdo->prepare("SELECT data FROM oc_session WHERE session_id = ?");
    $stmt->execute([$ocsessid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && $row["data"]) {
        $sd = json_decode($row["data"], true);
        $customer_id = (int)($sd["customer_id"] ?? 0);
    }
}

$action = $_GET["action"] ?? "list";

if ($action === "list" && $customer_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM oc_address WHERE customer_id = ? ORDER BY address_id DESC");
    $stmt->execute([$customer_id]);
    $addrs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $current = $addrs[0] ?? null;
    echo json_encode(["success" => true, "addresses" => $addrs, "current" => $current]);
} else {
    echo json_encode(["success" => true, "addresses" => [], "current" => null]);
}