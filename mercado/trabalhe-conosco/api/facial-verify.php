<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) exit(json_encode(['success' => false]));

$data = json_decode(file_get_contents('php://input'), true);
$worker_id = intval($_SESSION['worker_id']);

$pdo = getPDO();

// Salvar foto da verificação
$upload_dir = __DIR__ . '/../../uploads/workers/' . $worker_id . '/verifications/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

$relative_path = null;
$photo_data = $data['photo'] ?? '';
if (preg_match('/^data:image\/(\w+);base64,/', $photo_data, $matches)) {
    $ext = $matches[1];
    $photo_data = substr($photo_data, strpos($photo_data, ',') + 1);
    $photo_data = base64_decode($photo_data);
    $filename = 'verify_' . date('Ymd_His') . '.' . $ext;
    file_put_contents($upload_dir . $filename, $photo_data);

    $relative_path = 'uploads/workers/' . $worker_id . '/verifications/' . $filename;
}

// Registrar verificação (simular sucesso)
$stmt = $pdo->prepare("INSERT INTO om_facial_verifications (worker_id, verification_type, success, confidence, photo_path) VALUES (?, 'diaria', 1, 0.95, ?)");
$stmt->execute([$worker_id, $relative_path]);

// Atualizar worker
$stmt = $pdo->prepare("UPDATE om_workers SET last_facial_verification = NOW(), facial_verification_count = facial_verification_count + 1, is_online = 1, availability = 'disponivel' WHERE worker_id = ?");
$stmt->execute([$worker_id]);

echo json_encode(['success' => true, 'confidence' => 0.95]);
