<?php
require_once __DIR__ . '/config/database.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$worker_id = intval($data['worker_id'] ?? 0);
$message = trim($data['message'] ?? '');
$sender_type = $data['sender_type'] ?? 'admin';

if (!$worker_id || !$message) {
    exit(json_encode(['success' => false, 'error' => 'Dados inválidos']));
}

$conn = getMySQLi();
$conn->set_charset('utf8mb4');

$stmt = $conn->prepare("INSERT INTO om_worker_chat 
    (conversation_type, worker_id, sender_type, sender_id, message, message_type) 
    VALUES ('worker_suporte', ?, ?, 0, ?, 'text')");
$stmt->bind_param("iss", $worker_id, $sender_type, $message);
$stmt->execute();

echo json_encode(['success' => true, 'message_id' => $conn->insert_id]);
$conn->close();
