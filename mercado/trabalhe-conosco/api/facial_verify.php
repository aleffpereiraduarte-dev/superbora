<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['worker_id'])) exit(json_encode(['error' => 'Não autorizado']));

$data = json_decode(file_get_contents('php://input'), true);
$worker_id = intval($_SESSION['worker_id']);

$pdo = getPDO();

// Salvar foto
if (!empty($data['photo'])) {
    $upload_dir = dirname(__DIR__) . '/uploads/workers/' . $worker_id;
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $photo_data = $data['photo'];
    if (preg_match('/^data:image\/(\w+);base64,/', $photo_data, $type)) {
        $photo_data = substr($photo_data, strpos($photo_data, ',') + 1);
        $photo_data = base64_decode($photo_data);
        $filename = 'facial_verify_' . date('Ymd_His') . '.jpg';
        file_put_contents($upload_dir . '/' . $filename, $photo_data);
    }
}

// Registrar verificação
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
$stmt = $pdo->prepare("INSERT INTO om_facial_verifications (worker_id, verification_type, success, ip_address) VALUES (?, 'diaria', 1, ?)");
$stmt->execute([$worker_id, $ip_address]);

$stmt = $pdo->prepare("UPDATE om_workers SET last_facial_verification = NOW(), is_online = 1, availability = 'disponivel' WHERE worker_id = ?");
$stmt->execute([$worker_id]);

echo json_encode(['success' => true]);
