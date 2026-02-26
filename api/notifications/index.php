<?php
/**
 * OneMundo - API de Notificações
 *
 * Endpoints:
 * GET  /api/notifications/ - Lista notificações do usuário
 * GET  /api/notifications/unread - Conta não lidas
 * POST /api/notifications/read/{id} - Marca como lida
 * POST /api/notifications/read-all - Marca todas como lidas
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/om_notifications.php';

$eco = om_ecosystem();
$notifications = om_notifications();

// Verifica autenticação
if (!$eco->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$user = $eco->getCurrentUser();
$userId = $user['id'];
$userType = $user['primary_type'];

// Roteamento simples
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = str_replace('/api/notifications', '', $path);
$path = trim($path, '/');

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch (true) {
        // Lista notificações
        case $method === 'GET' && $path === '':
            $limit = (int)($_GET['limit'] ?? 20);
            $unreadOnly = isset($_GET['unread']);
            $result = $notifications->getForUser($userId, $userType, $limit, $unreadOnly);
            echo json_encode(['success' => true, 'notifications' => $result]);
            break;

        // Conta não lidas
        case $method === 'GET' && $path === 'unread':
            $count = $notifications->countUnread($userId, $userType);
            echo json_encode(['success' => true, 'count' => $count]);
            break;

        // Marca como lida
        case $method === 'POST' && preg_match('/^read\/(\d+)$/', $path, $matches):
            $notifId = $matches[1];
            $notifications->markAsRead($notifId, $userId);
            echo json_encode(['success' => true]);
            break;

        // Marca todas como lidas
        case $method === 'POST' && $path === 'read-all':
            $notifications->markAllAsRead($userId, $userType);
            echo json_encode(['success' => true]);
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint não encontrado']);
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log("[notifications/index] Erro: " . $e->getMessage());
    echo json_encode(['error' => 'Erro interno do servidor']);
}
