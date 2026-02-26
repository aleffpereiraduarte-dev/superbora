<?php
/**
 * GET/POST/DELETE /api/mercado/partner/stories.php
 * Gestao de stories do parceiro
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = (int)$payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    // GET - Listar stories
    if ($method === 'GET') {
        $status = $_GET['status'] ?? 'active';

        $where = "partner_id = ?";
        $params = [$partnerId];

        if ($status !== 'all') {
            $where .= " AND status = ?";
            $params[] = $status;
        }

        $stmt = $db->prepare("
            SELECT id, media_type, media_url, thumbnail_url, caption,
                   link_type, link_id, link_url, view_count, click_count,
                   expires_at, status, created_at
            FROM om_restaurant_stories
            WHERE $where
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $stmt->execute($params);
        $stories = $stmt->fetchAll();

        $formattedStories = array_map(function($s) {
            $expiresAt = new DateTime($s['expires_at']);
            $now = new DateTime();
            $hoursRemaining = max(0, (int)(($expiresAt->getTimestamp() - $now->getTimestamp()) / 3600));

            return [
                'id' => (int)$s['id'],
                'media_type' => $s['media_type'],
                'media_url' => $s['media_url'],
                'thumbnail_url' => $s['thumbnail_url'],
                'caption' => $s['caption'],
                'link' => [
                    'type' => $s['link_type'],
                    'id' => $s['link_id'],
                    'url' => $s['link_url'],
                ],
                'stats' => [
                    'views' => (int)$s['view_count'],
                    'clicks' => (int)$s['click_count'],
                ],
                'expires_at' => $s['expires_at'],
                'hours_remaining' => $hoursRemaining,
                'status' => $s['status'],
                'created_at' => $s['created_at'],
            ];
        }, $stories);

        response(true, ['stories' => $formattedStories]);
    }

    // POST - Criar story
    if ($method === 'POST') {
        $input = getInput();

        $mediaType = $input['media_type'] ?? 'image';
        $mediaUrl = trim($input['media_url'] ?? '');
        $caption = strip_tags(trim($input['caption'] ?? ''));
        $linkType = $input['link_type'] ?? 'none';
        $linkId = (int)($input['link_id'] ?? 0);
        $linkUrl = trim($input['link_url'] ?? '');
        $durationHours = (int)($input['duration_hours'] ?? 24);

        if (empty($mediaUrl)) {
            response(false, null, "URL da midia obrigatoria", 400);
        }

        if (!preg_match('#^https?://#i', $mediaUrl)) {
            response(false, null, "URL da midia invalida", 400);
        }

        // SECURITY: Validate media_url against allowed domains (prevent SSRF)
        $allowedMediaDomains = [
            'superbora.com.br', 'www.superbora.com.br',
            'onemundo.com.br', 'www.onemundo.com.br',
            'res.cloudinary.com', 'cdn.superbora.com.br',
            'storage.googleapis.com', 's3.amazonaws.com',
        ];
        $parsedMediaUrl = parse_url($mediaUrl);
        $mediaHost = $parsedMediaUrl['host'] ?? '';
        $isAllowedMedia = false;
        foreach ($allowedMediaDomains as $domain) {
            if ($mediaHost === $domain || str_ends_with($mediaHost, '.' . $domain)) {
                $isAllowedMedia = true;
                break;
            }
        }
        // Also allow relative URLs from the upload endpoint
        if (!$isAllowedMedia && strpos($mediaUrl, '/uploads/') === 0) {
            $isAllowedMedia = true;
        }
        if (!$isAllowedMedia) {
            response(false, null, "URL da midia deve apontar para dominio permitido. Use o endpoint de upload.", 400);
        }

        if ($linkUrl && !preg_match('#^https?://#i', $linkUrl)) {
            response(false, null, "URL do link invalida", 400);
        }

        if (!in_array($mediaType, ['image', 'video'])) {
            response(false, null, "Tipo de midia invalido", 400);
        }

        if ($durationHours < 1 || $durationHours > 72) {
            response(false, null, "Duracao deve ser entre 1 e 72 horas", 400);
        }

        // Limite de stories ativos
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM om_restaurant_stories
            WHERE partner_id = ? AND status = 'active'
        ");
        $stmt->execute([$partnerId]);
        if ($stmt->fetchColumn() >= 10) {
            response(false, null, "Limite de 10 stories ativos atingido", 400);
        }

        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$durationHours} hours"));

        $stmt = $db->prepare("
            INSERT INTO om_restaurant_stories
            (partner_id, media_type, media_url, caption, link_type, link_id, link_url, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $partnerId,
            $mediaType,
            $mediaUrl,
            $caption ?: null,
            $linkType,
            $linkId ?: null,
            $linkUrl ?: null,
            $expiresAt
        ]);

        response(true, [
            'story_id' => (int)$db->lastInsertId(),
            'expires_at' => $expiresAt,
            'message' => 'Story publicado!'
        ]);
    }

    // DELETE - Remover story
    if ($method === 'DELETE') {
        $storyId = (int)($_GET['id'] ?? 0);

        if (!$storyId) {
            response(false, null, "ID do story obrigatorio", 400);
        }

        $stmt = $db->prepare("
            UPDATE om_restaurant_stories
            SET status = 'deleted'
            WHERE id = ? AND partner_id = ?
        ");
        $stmt->execute([$storyId, $partnerId]);

        if ($stmt->rowCount() === 0) {
            response(false, null, "Story nao encontrado", 404);
        }

        response(true, ['message' => 'Story removido']);
    }

} catch (Exception $e) {
    error_log("[partner/stories] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar stories", 500);
}
