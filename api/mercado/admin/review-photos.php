<?php
/**
 * GET/POST /api/mercado/admin/review-photos.php
 * Moderacao de fotos de avaliacoes
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();

    $method = $_SERVER['REQUEST_METHOD'];

    // GET - Listar fotos de avaliacoes
    if ($method === 'GET') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        // Total
        $stmt = $db->query("SELECT COUNT(*) FROM om_review_photos");
        $total = $stmt->fetchColumn();

        // Fotos — join via review_id to get context
        $stmt = $db->prepare("
            SELECT rp.id, rp.review_id, rp.photo_url, rp.sort_order, rp.created_at,
                   r.rating, r.comment as review_comment,
                   r.customer_name, r.partner_id,
                   p.trade_name as partner_name
            FROM om_review_photos rp
            INNER JOIN om_market_reviews r ON rp.review_id = r.id
            LEFT JOIN om_market_partners p ON r.partner_id = p.partner_id
            ORDER BY rp.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $photos = $stmt->fetchAll();

        response(true, [
            'photos' => $photos,
            'stats' => ['total' => (int)$total],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int)$total,
                'pages' => (int)ceil($total / $limit) ?: 1
            ]
        ]);
    }

    // POST - Aprovar ou rejeitar foto
    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? 'approve';
        $photoId = (int)($input['photo_id'] ?? 0);

        if (!$photoId && $action !== 'bulk_delete') {
            response(false, null, "ID da foto obrigatorio", 400);
        }

        if ($action === 'delete' || $action === 'reject') {
            // Delete photo file and record
            $stmt = $db->prepare("SELECT photo_url FROM om_review_photos WHERE id = ?");
            $stmt->execute([$photoId]);
            $photo = $stmt->fetch();

            if ($photo && $photo['photo_url']) {
                // SECURITY: Validate resolved path to prevent path traversal
                $baseDir = realpath(dirname(__DIR__, 3) . '/uploads');
                $filepath = realpath(dirname(__DIR__, 3) . $photo['photo_url']);
                if ($filepath && $baseDir && strpos($filepath, $baseDir) === 0 && file_exists($filepath)) {
                    unlink($filepath);
                }
            }

            $stmt = $db->prepare("DELETE FROM om_review_photos WHERE id = ?");
            $stmt->execute([$photoId]);

            response(true, ['message' => 'Foto removida']);
        }

        // Bulk delete
        if ($action === 'bulk_delete') {
            $photoIds = $input['photo_ids'] ?? [];
            if (empty($photoIds) || !is_array($photoIds)) {
                response(false, null, "IDs das fotos obrigatorios", 400);
            }

            $placeholders = implode(',', array_fill(0, count($photoIds), '?'));
            $stmt = $db->prepare("DELETE FROM om_review_photos WHERE id IN ($placeholders)");
            $stmt->execute($photoIds);
            $count = $stmt->rowCount();

            response(true, ['message' => "$count fotos removidas"]);
        }

        response(false, null, "Acao invalida: use 'delete' ou 'bulk_delete'", 400);
    }

} catch (Exception $e) {
    error_log("[admin/review-photos] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar fotos", 500);
}
