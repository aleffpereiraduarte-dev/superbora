<?php
/**
 * GET /api/mercado/vitrine/stories.php
 * Lista stories ativos de lojas (para vitrine do cliente)
 *
 * Returns partner "stories" for the home screen carousel.
 * If om_market_stories table exists, uses real stories.
 * Otherwise, falls back to active partners with their banner as story content.
 *
 * Query params:
 *   partner_id  — filter by specific partner (optional)
 *   limit       — max results, 10-50 (default 20)
 *
 * Response: array of story groups sorted by has_promo DESC, rating DESC
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

setCorsHeaders();

// ─── POST: story interactions (view, like, save) ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = getDB();
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = $input['action'] ?? '';
        $storyId = (int)($input['story_id'] ?? 0);

        if (!$storyId || !in_array($action, ['view', 'like', 'save'], true)) {
            response(true, ['ok' => true]); // graceful no-op
        }

        // Try to get customer ID (optional auth — fail silently)
        $customerId = null;
        try {
            require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
            OmAuth::getInstance()->setDb($db);
            $token = om_auth()->getTokenFromRequest();
            if ($token) {
                $payload = om_auth()->validateToken($token);
                if ($payload && ($payload['type'] ?? '') === 'customer') {
                    $customerId = (int)$payload['uid'];
                }
            }
        } catch (Exception $e) { /* auth optional */ }

        if ($action === 'view') {
            // Record view in om_story_views (table already exists)
            try {
                $db->prepare("
                    INSERT INTO om_story_views (story_id, customer_id, viewed_at)
                    VALUES (?, ?, NOW())
                    ON CONFLICT DO NOTHING
                ")->execute([$storyId, $customerId]);
            } catch (Exception $e) {
                // If om_story_views schema doesn't support ON CONFLICT, insert without
                try {
                    $db->prepare("INSERT INTO om_story_views (story_id, customer_id, viewed_at) VALUES (?, ?, NOW())")
                       ->execute([$storyId, $customerId]);
                } catch (Exception $e2) { /* non-critical */ }
            }
        }

        // like and save are tracked client-side (AsyncStorage) — backend just acknowledges
        response(true, ['ok' => true, 'action' => $action, 'story_id' => $storyId]);

    } catch (Exception $e) {
        error_log("[vitrine/stories POST] " . $e->getMessage());
        response(true, ['ok' => true]); // never fail the app for story interactions
    }
}

try {
    $dbInit = getDB();
    // Ensure stories table exists (idempotent - the endpoint also has fallbacks)
    $dbInit->exec("CREATE TABLE IF NOT EXISTS om_market_stories (
        id SERIAL PRIMARY KEY,
        partner_id INTEGER NOT NULL,
        media_url TEXT,
        thumbnail_url TEXT,
        caption VARCHAR(500),
        link_type VARCHAR(50) DEFAULT 'ver_loja',
        link_id INTEGER,
        link_url TEXT,
        is_active BOOLEAN DEFAULT true,
        expires_at TIMESTAMPTZ,
        created_at TIMESTAMPTZ DEFAULT NOW()
    )");

    $partnerId = (int)($_GET['partner_id'] ?? 0);
    $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));

    // Build cache key
    $cacheKey = "vitrine_stories_{$partnerId}_{$limit}";

    $data = CacheHelper::remember($cacheKey, 300, function () use ($partnerId, $limit) {
        $db = getDB();

        // Check if dedicated stories table exists
        $tableCheck = $db->query(
            "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'om_market_stories')"
        );
        $hasStoriesTable = $tableCheck->fetchColumn();

        if ($hasStoriesTable) {
            return fetchFromStoriesTable($db, $partnerId, $limit);
        }

        // Also check legacy table name
        $legacyCheck = $db->query(
            "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'om_restaurant_stories')"
        );
        $hasLegacyTable = $legacyCheck->fetchColumn();

        if ($hasLegacyTable) {
            return fetchFromLegacyStoriesTable($db, $partnerId, $limit);
        }

        // Fallback: use partners with their banner as story content
        return fetchPartnersAsStories($db, $partnerId, $limit);
    });

    response(true, $data);

} catch (Exception $e) {
    error_log("[vitrine/stories] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar stories", 500);
}

/**
 * Fetch from om_market_stories (new dedicated table)
 */
function fetchFromStoriesTable(PDO $db, int $partnerId, int $limit): array
{
    $params = [];
    $where = "s.is_active = true AND (s.expires_at IS NULL OR s.expires_at > NOW())";

    if ($partnerId) {
        $where .= " AND s.partner_id = ?";
        $params[] = $partnerId;
    }

    $stmt = $db->prepare("
        SELECT s.id, s.partner_id, s.media_url, s.thumbnail_url, s.caption,
               s.link_type, s.link_id, s.link_url, s.created_at,
               p.trade_name, p.name, p.logo, p.banner, p.rating, p.is_open,
               CASE WHEN EXISTS (
                   SELECT 1 FROM om_market_coupons c
                   WHERE c.status = 'active'
                     AND (c.valid_until IS NULL OR c.valid_until > NOW())
                     AND (
                       c.specific_partners IS NULL OR c.specific_partners = ''
                       OR c.specific_partners = '[]'
                       OR c.specific_partners::jsonb @> to_jsonb(ARRAY[p.partner_id])
                     )
               ) THEN true ELSE false END AS has_promo
        FROM om_market_stories s
        INNER JOIN om_market_partners p ON s.partner_id = p.partner_id
        WHERE {$where} AND p.status::text = '1'
        ORDER BY has_promo DESC, p.rating DESC NULLS LAST, s.created_at DESC
        LIMIT ?
    ");
    $stmt->execute(array_merge($params, [$limit]));
    $rows = $stmt->fetchAll();

    return groupStoryRows($rows, true);
}

/**
 * Fetch from om_restaurant_stories (legacy table)
 */
function fetchFromLegacyStoriesTable(PDO $db, int $partnerId, int $limit): array
{
    $params = [];
    $where = "s.status = 'active' AND s.expires_at > NOW()";

    if ($partnerId) {
        $where .= " AND s.partner_id = ?";
        $params[] = $partnerId;
    }

    $stmt = $db->prepare("
        SELECT s.id, s.partner_id, s.media_url, s.thumbnail_url, s.caption,
               s.link_type, s.link_id, s.link_url, s.created_at,
               p.trade_name, p.name, p.logo, p.banner, p.rating, p.is_open,
               false AS has_promo
        FROM om_restaurant_stories s
        INNER JOIN om_market_partners p ON s.partner_id = p.partner_id
        WHERE {$where} AND p.status::text = '1'
        ORDER BY p.rating DESC NULLS LAST, s.created_at DESC
        LIMIT ?
    ");
    $stmt->execute(array_merge($params, [$limit]));
    $rows = $stmt->fetchAll();

    return groupStoryRows($rows, true);
}

/**
 * Fallback: use active partners with their banner image as a "story"
 */
function fetchPartnersAsStories(PDO $db, int $partnerId, int $limit): array
{
    $params = [];
    $where = "p.status::text = '1' AND (p.banner IS NOT NULL AND p.banner != '')";

    if ($partnerId) {
        $where .= " AND p.partner_id = ?";
        $params[] = $partnerId;
    }

    // Check if coupons table exists for has_promo subquery
    $couponCheck = $db->query(
        "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'om_market_coupons')"
    );
    $hasCoupons = $couponCheck->fetchColumn();

    $promoSubquery = 'false';
    if ($hasCoupons) {
        $promoSubquery = "EXISTS (
            SELECT 1 FROM om_market_coupons c
            WHERE c.partner_id = p.partner_id
              AND c.is_active = true
              AND (c.expires_at IS NULL OR c.expires_at > NOW())
        )";
    }

    $stmt = $db->prepare("
        SELECT p.partner_id, p.trade_name, p.name, p.logo, p.banner,
               p.rating, p.is_open,
               ({$promoSubquery}) AS has_promo
        FROM om_market_partners p
        WHERE {$where}
        ORDER BY ({$promoSubquery}) DESC, p.rating DESC NULLS LAST
        LIMIT ?
    ");
    $stmt->execute(array_merge($params, [$limit]));
    $partners = $stmt->fetchAll();

    $result = [];
    foreach ($partners as $p) {
        $pid = (int)$p['partner_id'];
        $partnerName = $p['trade_name'] ?: $p['name'] ?: 'Loja';
        $isOpen = filter_var($p['is_open'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $hasPromo = filter_var($p['has_promo'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $result[] = [
            'partner_id'   => $pid,
            'partner_name' => sanitizeOutput($partnerName),
            'partner_logo' => $p['logo'] ?: null,
            'is_open'      => $isOpen,
            'has_promo'    => $hasPromo,
            'rating'       => round((float)($p['rating'] ?? 0), 1),
            'stories'      => [
                [
                    'id'        => $pid,
                    'image'     => $p['banner'],
                    'caption'   => null,
                    'cta_url'   => "/loja/{$pid}",
                    'cta_text'  => 'Ver loja',
                ],
            ],
        ];
    }

    return $result;
}

/**
 * Group story rows by partner
 */
function groupStoryRows(array $rows, bool $fromTable): array
{
    $groups = [];

    foreach ($rows as $r) {
        $pid = (int)$r['partner_id'];
        $partnerName = $r['trade_name'] ?: $r['name'] ?: 'Loja';
        $isOpen = filter_var($r['is_open'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $hasPromo = filter_var($r['has_promo'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!isset($groups[$pid])) {
            $groups[$pid] = [
                'partner_id'   => $pid,
                'partner_name' => sanitizeOutput($partnerName),
                'partner_logo' => $r['logo'] ?: null,
                'is_open'      => $isOpen,
                'has_promo'    => $hasPromo,
                'rating'       => round((float)($r['rating'] ?? 0), 1),
                'stories'      => [],
            ];
        }

        $groups[$pid]['stories'][] = [
            'id'        => (int)$r['id'],
            'image'     => $r['media_url'] ?: $r['banner'] ?: null,
            'thumbnail' => $r['thumbnail_url'] ?? null,
            'caption'   => $r['caption'] ?? null,
            'cta_url'   => $r['link_url'] ?? "/loja/{$pid}",
            'cta_text'  => $r['link_type'] ?? 'Ver loja',
        ];
    }

    return array_values($groups);
}
