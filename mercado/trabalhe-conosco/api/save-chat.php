<?php
header('Content-Type: application/json');
session_start();

$input = json_decode(file_get_contents('php://input'), true);

$orderId = $input['order_id'] ?? '';
$message = $input['message'] ?? '';
$chatType = $input['chat_type'] ?? 'general'; // shopper_delivery, delivery_shopper, delivery_client
$senderId = $_SESSION['worker_id'] ?? 0;
$senderName = $_SESSION['worker_name'] ?? 'Usuário';

if (empty($orderId) || empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
    exit;
}

// Em produção: salvar no banco de dados
/*
$db = new PDO(...);
$stmt = $db->prepare("
    INSERT INTO om_order_chats (order_id, chat_type, sender_id, sender_name, message, created_at)
    VALUES (?, ?, ?, ?, ?, NOW())
");
$stmt->execute([$orderId, $chatType, $senderId, $senderName, $message]);
*/

// Log para controle (em produção seria banco de dados)
$logFile = __DIR__ . '/../logs/chats.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

$logEntry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'order_id' => $orderId,
    'chat_type' => $chatType,
    'sender_id' => $senderId,
    'sender_name' => $senderName,
    'message' => $message
];

@file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND);

echo json_encode([
    'success' => true,
    'message_id' => time(),
    'saved_at' => date('Y-m-d H:i:s')
]);
