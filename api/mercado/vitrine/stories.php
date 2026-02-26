<?php
/**
 * GET /api/mercado/vitrine/stories.php
 * Lista stories ativos de lojas (para vitrine do cliente)
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

try {
    $db = getDB();

    $partnerId = (int)($_GET['partner_id'] ?? 0);
    $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));

    // Check if table exists first (PostgreSQL syntax)
    $tableCheck = $db->query("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'om_restaurant_stories')");
    $exists = $tableCheck->fetchColumn();
    if (!$exists) {
        // Table doesn't exist, return empty array
        response(true, ['stores_with_stories' => []]);
        exit;
    }

    $params = [];
    $where = "s.status = 'active' AND s.expires_at > NOW()";

    if ($partnerId) {
        $where .= " AND s.partner_id = ?";
        $params[] = $partnerId;
    }

    $stmt = $db->prepare("
        SELECT s.*, p.trade_name as partner_name, p.logo as partner_logo
        FROM om_restaurant_stories s
        INNER JOIN om_market_partners p ON s.partner_id = p.partner_id
        WHERE $where
        ORDER BY s.created_at DESC
        LIMIT ?
    ");
    $stmt->execute(array_merge($params, [$limit]));
    $stories = $stmt->fetchAll();

    // Agrupar por parceiro
    $groupedStories = [];
    foreach ($stories as $s) {
        $pid = $s['partner_id'];
        if (!isset($groupedStories[$pid])) {
            $groupedStories[$pid] = [
                'partner_id' => (int)$pid,
                'partner_name' => $s['partner_name'],
                'partner_logo' => $s['partner_logo'],
                'stories' => []
            ];
        }

        $groupedStories[$pid]['stories'][] = [
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
            'created_at' => $s['created_at'],
        ];
    }

    response(true, [
        'stores_with_stories' => array_values($groupedStories)
    ]);

} catch (Exception $e) {
    error_log("[vitrine/stories] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar stories", 500);
}
