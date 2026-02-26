<?php
/**
 * POST /api/mercado/vitrine/story-view.php
 * Registrar visualizacao de story
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $customerId = null;
    $token = om_auth()->getTokenFromRequest();
    if ($token) {
        $payload = om_auth()->validateToken($token);
        if ($payload && $payload['type'] === 'customer') {
            $customerId = (int)$payload['uid'];
        }
    }

    $input = getInput();
    $storyId = (int)($input['story_id'] ?? 0);

    if (!$storyId) {
        response(false, null, "ID do story obrigatorio", 400);
    }

    // Verificar se story existe e esta ativo
    $stmt = $db->prepare("
        SELECT id, partner_id FROM om_restaurant_stories
        WHERE id = ? AND status = 'active' AND expires_at > NOW()
    ");
    $stmt->execute([$storyId]);
    $story = $stmt->fetch();

    if (!$story) {
        response(false, null, "Story nao encontrado ou expirado", 404);
    }

    // Registrar visualizacao
    $viewerIp = $_SERVER['REMOTE_ADDR'] ?? null;

    // Verificar se ja visualizou (evitar duplicatas em curto periodo)
    if ($customerId) {
        $stmt = $db->prepare("
            SELECT id FROM om_story_views
            WHERE story_id = ? AND customer_id = ?
            AND viewed_at > NOW() - INTERVAL '1 hours'
        ");
        $stmt->execute([$storyId, $customerId]);
    } else {
        $stmt = $db->prepare("
            SELECT id FROM om_story_views
            WHERE story_id = ? AND viewer_ip = ?
            AND viewed_at > NOW() - INTERVAL '1 hours'
        ");
        $stmt->execute([$storyId, $viewerIp]);
    }

    $existingView = $stmt->fetch();

    if (!$existingView) {
        // Registrar nova visualizacao
        $stmt = $db->prepare("
            INSERT INTO om_story_views (story_id, customer_id, viewer_ip)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$storyId, $customerId, $viewerIp]);

        // Atualizar contador de views no story
        $stmt = $db->prepare("
            UPDATE om_restaurant_stories
            SET view_count = view_count + 1
            WHERE id = ?
        ");
        $stmt->execute([$storyId]);
    }

    response(true, ['viewed' => true]);

} catch (Exception $e) {
    error_log("[vitrine/story-view] Erro: " . $e->getMessage());
    response(false, null, "Erro ao registrar visualizacao", 500);
}
