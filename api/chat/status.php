<?php
/**
 * API de Status - Chat Cliente-Vendedor
 *
 * POST /api/chat/status.php - Atualizar status online/digitando
 * GET /api/chat/status.php?user_id=1&user_type=customer - Verificar status
 * GET /api/chat/status.php?sellers_online=1 - Verificar vendedores online
 */

require_once __DIR__ . '/config.php';

try {
    $pdo = getChatDB();
    $method = $_SERVER['REQUEST_METHOD'];

    // ========== GET - Verificar status ==========
    if ($method === 'GET') {
        // Verificar vendedores online
        if (isset($_GET['sellers_online'])) {
            $stmt = $pdo->query("
                SELECT COUNT(*) as count
                FROM om_chat_online_status
                WHERE user_type = 'seller' AND is_online = 1
                AND last_seen > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ");
            $result = $stmt->fetch();

            jsonResponse(true, [
                'sellers_online' => (int)$result['count'],
                'available' => $result['count'] > 0
            ]);
        }

        // Status de um usuário específico
        $userId = (int)($_GET['user_id'] ?? 0);
        $userType = $_GET['user_type'] ?? 'customer';

        if (!$userId) {
            jsonResponse(false, null, 'user_id obrigatório', 400);
        }

        $stmt = $pdo->prepare("
            SELECT is_online, is_typing_in, last_seen
            FROM om_chat_online_status
            WHERE user_id = ? AND user_type = ?
        ");
        $stmt->execute([$userId, $userType]);
        $status = $stmt->fetch();

        if (!$status) {
            jsonResponse(true, [
                'is_online' => false,
                'is_typing' => false,
                'last_seen' => null
            ]);
        }

        // Verificar se ainda está online (última atividade < 5 min)
        $lastSeen = strtotime($status['last_seen']);
        $isReallyOnline = $status['is_online'] && (time() - $lastSeen < 300);

        jsonResponse(true, [
            'is_online' => $isReallyOnline,
            'is_typing' => (bool)$status['is_typing_in'],
            'typing_in' => $status['is_typing_in'],
            'last_seen' => $status['last_seen']
        ]);
    }

    // ========== POST - Atualizar status ==========
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $userId = (int)($input['user_id'] ?? 0);
        $userType = $input['user_type'] ?? 'customer';
        $isOnline = isset($input['is_online']) ? (bool)$input['is_online'] : true;
        $typingIn = isset($input['typing_in']) ? (int)$input['typing_in'] : null;

        if (!$userId) {
            jsonResponse(false, null, 'user_id obrigatório', 400);
        }

        // Upsert status
        $stmt = $pdo->prepare("
            INSERT INTO om_chat_online_status (user_id, user_type, is_online, is_typing_in, last_seen)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                is_online = VALUES(is_online),
                is_typing_in = VALUES(is_typing_in),
                last_seen = NOW()
        ");
        $stmt->execute([$userId, $userType, $isOnline ? 1 : 0, $typingIn]);

        jsonResponse(true, [
            'is_online' => $isOnline,
            'typing_in' => $typingIn
        ]);
    }

} catch (Exception $e) {
    jsonResponse(false, null, $e->getMessage(), 500);
}
