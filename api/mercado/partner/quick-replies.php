<?php
/**
 * Quick Replies API for Partner Chat
 *
 * GET /api/mercado/partner/quick-replies.php - Listar respostas rapidas
 * POST /api/mercado/partner/quick-replies.php - Criar/atualizar resposta {id?, title, message}
 * DELETE /api/mercado/partner/quick-replies.php?id=X - Remover resposta
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

// Default quick replies for new partners
const DEFAULT_QUICK_REPLIES = [
    ['title' => 'Ola', 'message' => 'Ola! Como posso ajudar?'],
    ['title' => 'Preparando', 'message' => 'Seu pedido esta sendo preparado'],
    ['title' => 'Saiu entrega', 'message' => 'Pedido saiu para entrega'],
    ['title' => 'Obrigado', 'message' => 'Obrigado pela preferencia!']
];

/**
 * Table om_partner_quick_replies must exist (created via migration)
 */
function ensureTable(PDO $db): void {
    // No-op: table created via migration
}

/**
 * Create default quick replies for a partner if they have none
 */
function createDefaultReplies(PDO $db, int $partnerId): void {
    $stmt = $db->prepare("SELECT COUNT(*) FROM om_partner_quick_replies WHERE partner_id = ?");
    $stmt->execute([$partnerId]);
    $count = (int)$stmt->fetchColumn();

    if ($count === 0) {
        $insertStmt = $db->prepare("
            INSERT INTO om_partner_quick_replies (partner_id, title, message, sort_order)
            VALUES (?, ?, ?, ?)
        ");

        foreach (DEFAULT_QUICK_REPLIES as $index => $reply) {
            $insertStmt->execute([$partnerId, $reply['title'], $reply['message'], $index + 1]);
        }
    }
}

try {
    $db = getDB();

    // Ensure table exists on first request
    ensureTable($db);

    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = (int)$payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    // GET - List quick replies
    if ($method === 'GET') {
        // Create default replies if partner has none
        createDefaultReplies($db, $partnerId);

        $stmt = $db->prepare("
            SELECT id, title, message, sort_order
            FROM om_partner_quick_replies
            WHERE partner_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute([$partnerId]);
        $replies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        response(true, ['replies' => $replies]);
    }

    // POST - Create or update reply
    if ($method === 'POST') {
        $input = getInput();
        $replyId = (int)($input['id'] ?? 0);
        $title = strip_tags(trim($input['title'] ?? ''));
        $message = strip_tags(trim($input['message'] ?? ''));

        if (empty($title) || empty($message)) {
            response(false, null, "Titulo e mensagem sao obrigatorios", 400);
        }

        if (strlen($title) > 100) {
            response(false, null, "Titulo deve ter no maximo 100 caracteres", 400);
        }

        if ($replyId > 0) {
            // Update existing
            $stmt = $db->prepare("
                UPDATE om_partner_quick_replies
                SET title = ?, message = ?
                WHERE id = ? AND partner_id = ?
            ");
            $stmt->execute([$title, $message, $replyId, $partnerId]);

            if ($stmt->rowCount() === 0) {
                response(false, null, "Resposta nao encontrada", 404);
            }
        } else {
            // Create new
            $stmt = $db->prepare("
                SELECT COALESCE(MAX(sort_order), 0) + 1
                FROM om_partner_quick_replies
                WHERE partner_id = ?
            ");
            $stmt->execute([$partnerId]);
            $nextOrder = (int)$stmt->fetchColumn();

            $stmt = $db->prepare("
                INSERT INTO om_partner_quick_replies (partner_id, title, message, sort_order)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$partnerId, $title, $message, $nextOrder]);
            $replyId = (int)$db->lastInsertId();
        }

        response(true, ['id' => $replyId], "Resposta salva!");
    }

    // DELETE - Remove reply
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            response(false, null, "ID obrigatorio", 400);
        }

        $stmt = $db->prepare("
            DELETE FROM om_partner_quick_replies
            WHERE id = ? AND partner_id = ?
        ");
        $stmt->execute([$id, $partnerId]);

        if ($stmt->rowCount() === 0) {
            response(false, null, "Resposta nao encontrada", 404);
        }

        response(true, null, "Resposta removida!");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/quick-replies] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
