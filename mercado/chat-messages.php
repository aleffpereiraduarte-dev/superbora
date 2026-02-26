<?php
require_once __DIR__ . '/config/database.php';
header('Content-Type: application/json');

$worker_id = intval($_GET['worker_id'] ?? 0);
if (!$worker_id) exit(json_encode(['success' => false]));

$pdo = getPDO();

// Worker info
$stmt = $pdo->prepare("SELECT * FROM om_workers WHERE worker_id = ?");
$stmt->execute([$worker_id]);
$worker = $stmt->fetch(PDO::FETCH_ASSOC);

// Messages
$stmt = $pdo->prepare("SELECT * FROM om_worker_chat
                        WHERE worker_id = ? AND conversation_type = 'worker_suporte'
                        ORDER BY created_at ASC LIMIT 100");
$stmt->execute([$worker_id]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Marcar como lidas
$stmt = $pdo->prepare("UPDATE om_worker_chat SET is_read = 1, read_at = NOW()
              WHERE worker_id = ? AND sender_type = 'worker' AND is_read = 0");
$stmt->execute([$worker_id]);

echo json_encode(['success' => true, 'worker' => $worker, 'messages' => $messages]);
