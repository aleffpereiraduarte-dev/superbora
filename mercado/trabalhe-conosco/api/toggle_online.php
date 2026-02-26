<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['worker_id'])) exit(json_encode(['error' => 'Não autorizado']));

$data = json_decode(file_get_contents('php://input'), true);
$online = $data['online'] ? 1 : 0;
$worker_id = intval($_SESSION['worker_id']);
$availability = $online ? 'disponivel' : 'offline';

$pdo = getPDO();
$stmt = $pdo->prepare("UPDATE om_workers SET is_online = ?, availability = ? WHERE worker_id = ?");
$stmt->execute([$online, $availability, $worker_id]);

echo json_encode(['success' => true, 'online' => $online]);
