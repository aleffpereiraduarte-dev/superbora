<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$notifications = getNotifications(getWorkerId());
$unread = getUnreadNotifications(getWorkerId());

echo json_encode([
    'success' => true,
    'notifications' => $notifications,
    'unread_count' => $unread
]);