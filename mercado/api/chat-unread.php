<?php
/**
 * API: Chat Unread - Conta mensagens não lidas
 * GET: /api/chat-unread.php?order_id=X&reader_type=customer
 */
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

require_once dirname(__DIR__) . "/config/database.php";

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    echo json_encode(["success" => false]); exit;
}

$order_id = (int)($_GET["order_id"] ?? 0);
$reader_type = $_GET["reader_type"] ?? "customer";

if (!$order_id) {
    echo json_encode(["success" => false, "error" => "order_id required"]); exit;
}

$stmt = $pdo->prepare("
    SELECT COUNT(*) as count 
    FROM om_market_chat 
    WHERE order_id = ? AND sender_type != ? AND is_read = 0
");
$stmt->execute([$order_id, $reader_type]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    "success" => true,
    "count" => (int)$result['count']
]);
