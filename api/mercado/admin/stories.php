<?php
/**
 * GET/POST /api/mercado/admin/stories.php
 * Moderacao de stories de restaurantes
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();

    $method = $_SERVER['REQUEST_METHOD'];

    // GET - Listar stories para moderacao
    if ($method === 'GET') {
        $status = $_GET['status'] ?? 'pending';
        $partnerId = (int)($_GET['partner_id'] ?? 0);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = "1=1";
        $params = [];

        if ($status !== 'all') {
            $where .= " AND s.status = ?";
            $params[] = $status;
        }

        if ($partnerId) {
            $where .= " AND s.partner_id = ?";
            $params[] = $partnerId;
        }

        // Total
        $stmt = $db->prepare("SELECT COUNT(*) FROM om_restaurant_stories s WHERE $where");
        $stmt->execute($params);
        $total = $stmt->fetchColumn();

        // Stories
        $stmt = $db->prepare("
            SELECT s.*, p.trade_name as partner_name, p.logo as partner_logo,
                   (SELECT COUNT(*) FROM om_story_views WHERE story_id = s.id) as view_count
            FROM om_restaurant_stories s
            INNER JOIN om_market_partners p ON s.partner_id = p.partner_id
            WHERE $where
            ORDER BY s.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$limit, $offset]));
        $stories = $stmt->fetchAll();

        // Estatisticas
        $stats = [];
        $stmt = $db->query("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired
            FROM om_restaurant_stories
        ");
        $stats = $stmt->fetch();

        response(true, [
            'stories' => $stories,
            'stats' => $stats,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int)$total,
                'pages' => ceil($total / $limit)
            ]
        ]);
    }

    // POST - Aprovar, rejeitar ou remover story
    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? 'approve';
        $storyId = (int)($input['story_id'] ?? 0);

        if (!$storyId) {
            response(false, null, "ID do story obrigatorio", 400);
        }

        if ($action === 'approve') {
            $stmt = $db->prepare("
                UPDATE om_restaurant_stories
                SET status = 'active', moderated_at = NOW(), moderated_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$payload['uid'], $storyId]);

            response(true, ['message' => 'Story aprovado!']);
        }

        if ($action === 'reject') {
            $reason = trim($input['reason'] ?? 'Conteudo inadequado');

            $stmt = $db->prepare("
                UPDATE om_restaurant_stories
                SET status = 'rejected', rejection_reason = ?, moderated_at = NOW(), moderated_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$reason, $payload['uid'], $storyId]);

            response(true, ['message' => 'Story rejeitado']);
        }

        if ($action === 'delete') {
            // Deletar arquivo de midia
            $stmt = $db->prepare("SELECT media_url FROM om_restaurant_stories WHERE id = ?");
            $stmt->execute([$storyId]);
            $story = $stmt->fetch();

            if ($story && $story['media_url']) {
                $filepath = dirname(__DIR__, 3) . $story['media_url'];
                // SECURITY: Prevent path traversal — verify resolved path is within allowed directory
                $realPath = realpath($filepath);
                $allowedDir = realpath(dirname(__DIR__, 3) . '/uploads');
                if ($realPath && $allowedDir && strpos($realPath, $allowedDir) === 0 && file_exists($realPath)) {
                    unlink($realPath);
                }
            }

            $stmt = $db->prepare("DELETE FROM om_restaurant_stories WHERE id = ?");
            $stmt->execute([$storyId]);

            response(true, ['message' => 'Story deletado']);
        }

        // Acao em lote
        if ($action === 'bulk_approve' || $action === 'bulk_reject') {
            $storyIds = $input['story_ids'] ?? [];

            if (empty($storyIds) || !is_array($storyIds)) {
                response(false, null, "IDs dos stories obrigatorios", 400);
            }

            $placeholders = implode(',', array_fill(0, count($storyIds), '?'));
            $newStatus = $action === 'bulk_approve' ? 'active' : 'rejected';
            $reason = $action === 'bulk_reject' ? ($input['reason'] ?? 'Rejeitado em lote') : null;

            $stmt = $db->prepare("
                UPDATE om_restaurant_stories
                SET status = ?, rejection_reason = ?, moderated_at = NOW(), moderated_by = ?
                WHERE id IN ($placeholders)
            ");
            $stmt->execute(array_merge([$newStatus, $reason, $payload['uid']], $storyIds));

            $count = $stmt->rowCount();
            response(true, ['message' => "$count stories atualizados"]);
        }

        response(false, null, "Acao invalida", 400);
    }

} catch (Exception $e) {
    error_log("[admin/stories] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar stories", 500);
}
