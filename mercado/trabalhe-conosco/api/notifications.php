<?php
/**
 * API: Notificações
 * GET /api/notifications.php - Listar notificações
 * PUT /api/notifications.php - Marcar como lida
 * DELETE /api/notifications.php - Limpar notificações
 */
require_once 'db.php';

$workerId = requireAuth();
$db = getDB();

if (!$db) {
    jsonError('Erro de conexão', 500);
}

// GET - Listar notificações
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $unreadOnly = isset($_GET['unread']);
    $limit = intval($_GET['limit'] ?? 50);

    try {
        $where = $unreadOnly ? "AND is_read = 0" : "";

        $stmt = $db->prepare("
            SELECT id, type, title, message, data, is_read, created_at
            FROM " . table('notifications') . "
            WHERE worker_id = ? $where
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$workerId, $limit]);
        $notifications = $stmt->fetchAll();

        // Contar não lidas
        $stmt = $db->prepare("
            SELECT COUNT(*) as unread_count
            FROM " . table('notifications') . "
            WHERE worker_id = ? AND is_read = 0
        ");
        $stmt->execute([$workerId]);
        $unread = $stmt->fetch();

        jsonSuccess([
            'notifications' => $notifications,
            'unread_count' => $unread['unread_count']
        ]);

    } catch (Exception $e) {
        error_log("Notifications GET error: " . $e->getMessage());
        jsonError('Erro ao buscar notificações', 500);
    }
}

// PUT - Marcar como lida
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input = getJsonInput();
    $notificationId = $input['id'] ?? null;
    $markAll = $input['mark_all'] ?? false;

    try {
        if ($markAll) {
            $stmt = $db->prepare("
                UPDATE " . table('notifications') . "
                SET is_read = 1, read_at = NOW()
                WHERE worker_id = ? AND is_read = 0
            ");
            $stmt->execute([$workerId]);
        } elseif ($notificationId) {
            $stmt = $db->prepare("
                UPDATE " . table('notifications') . "
                SET is_read = 1, read_at = NOW()
                WHERE id = ? AND worker_id = ?
            ");
            $stmt->execute([$notificationId, $workerId]);
        } else {
            jsonError('ID ou mark_all é obrigatório');
        }

        jsonSuccess([], 'Notificações atualizadas');

    } catch (Exception $e) {
        error_log("Notifications PUT error: " . $e->getMessage());
        jsonError('Erro ao atualizar notificações', 500);
    }
}

// DELETE - Limpar notificações antigas
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    try {
        // Limpar lidas com mais de 30 dias
        $stmt = $db->prepare("
            DELETE FROM " . table('notifications') . "
            WHERE worker_id = ? AND is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$workerId]);

        jsonSuccess(['deleted' => $stmt->rowCount()], 'Notificações antigas removidas');

    } catch (Exception $e) {
        error_log("Notifications DELETE error: " . $e->getMessage());
        jsonError('Erro ao limpar notificações', 500);
    }
}

jsonError('Método não permitido', 405);
