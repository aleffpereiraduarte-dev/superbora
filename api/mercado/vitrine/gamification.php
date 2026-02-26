<?php
/**
 * GET /api/mercado/vitrine/gamification.php
 * Gamification system - badges, levels, streaks, progress
 *
 * Returns user's gamification data including:
 * - Current level and points
 * - Progress to next level
 * - Current streak
 * - Earned and available badges
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

// Level thresholds
define('LEVEL_THRESHOLDS', [
    'bronze' => ['min' => 0, 'max' => 499, 'name' => 'Bronze', 'icon' => "\xF0\x9F\xA5\x89", 'color' => '#CD7F32'],
    'silver' => ['min' => 500, 'max' => 1999, 'name' => 'Prata', 'icon' => "\xF0\x9F\xA5\x88", 'color' => '#C0C0C0'],
    'gold' => ['min' => 2000, 'max' => 4999, 'name' => 'Ouro', 'icon' => "\xF0\x9F\xA5\x87", 'color' => '#FFD700'],
    'diamond' => ['min' => 5000, 'max' => PHP_INT_MAX, 'name' => 'Diamante', 'icon' => "\xF0\x9F\x92\x8E", 'color' => '#B9F2FF'],
]);

try {
    $db = getDB();

    // Dual-auth: simple token first, OmAuth fallback
    $customerId = getCustomerIdFromToken();
    if (!$customerId) {
        try {
            require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
            OmAuth::getInstance()->setDb($db);
            $token = om_auth()->getTokenFromRequest();
            if ($token) {
                $payload = om_auth()->validateToken($token);
                if ($payload && $payload['type'] === 'customer') {
                    $customerId = (int)$payload['uid'];
                }
            }
        } catch (Exception $e) {}
    }
    if (!$customerId) {
        response(false, null, "Autenticacao necessaria", 401);
    }

    // Schema fixes (ALTER TABLE) moved to migration

    // Ensure customer has a level record
    ensureCustomerLevel($db, $customerId);

    // Update stats from order history
    updateCustomerStats($db, $customerId);

    // Check and award new badges
    $newBadges = checkAndAwardBadges($db, $customerId);

    // Get current level data (handle both 'points' and 'total_points' columns)
    // Use GREATEST to pick the non-zero value (avoid double-counting legacy + new columns)
    $stmt = $db->prepare("
        SELECT level, GREATEST(COALESCE(total_points, 0), COALESCE(points, 0)) as total_points,
               total_orders, current_streak, best_streak,
               last_order_date, total_spent, total_reviews,
               COALESCE(photo_reviews, 0) as photo_reviews
        FROM om_customer_levels
        WHERE customer_id = ?
    ");
    $stmt->execute([$customerId]);
    $levelData = $stmt->fetch();

    // Get earned badges
    $stmt = $db->prepare("
        SELECT cb.badge_id, cb.earned_at, bd.name, bd.description, bd.icon, bd.category, bd.points_reward
        FROM om_customer_badges cb
        INNER JOIN om_badge_definitions bd ON cb.badge_id = bd.badge_id
        WHERE cb.customer_id = ?
        ORDER BY cb.earned_at DESC
    ");
    $stmt->execute([$customerId]);
    $earnedBadges = $stmt->fetchAll();

    // Get all available badges
    $stmt = $db->prepare("
        SELECT badge_id, name, description, icon, category, points_reward, requirement_value, sort_order
        FROM om_badge_definitions
        WHERE is_active = 1
        ORDER BY sort_order ASC
    ");
    $stmt->execute();
    $allBadges = $stmt->fetchAll();

    // Build badges response with earned status and progress
    $earnedIds = array_column($earnedBadges, 'badge_id');
    $badges = [];

    foreach ($allBadges as $badge) {
        $isEarned = in_array($badge['badge_id'], $earnedIds);
        $earnedInfo = null;

        if ($isEarned) {
            foreach ($earnedBadges as $eb) {
                if ($eb['badge_id'] === $badge['badge_id']) {
                    $earnedInfo = $eb['earned_at'];
                    break;
                }
            }
        }

        // Calculate progress for unearned badges
        $progress = 0;
        $progressText = '';

        if (!$isEarned) {
            $progress = calculateBadgeProgress($badge, $levelData);
            $progressText = getProgressText($badge, $levelData, $progress);
        }

        $badges[] = [
            'id' => $badge['badge_id'],
            'name' => $badge['name'],
            'description' => $badge['description'],
            'icon' => $badge['icon'],
            'category' => $badge['category'],
            'points_reward' => (int)$badge['points_reward'],
            'earned' => $isEarned,
            'earned_at' => $earnedInfo,
            'progress' => $progress,
            'progress_text' => $progressText,
        ];
    }

    // Calculate level progress
    $currentLevel = $levelData['level'];
    $totalPoints = (int)$levelData['total_points'];
    $levelInfo = LEVEL_THRESHOLDS[$currentLevel] ?? LEVEL_THRESHOLDS['bronze'];

    $nextLevel = null;
    $progressToNext = 100;
    $pointsToNext = 0;

    $levelOrder = ['bronze', 'silver', 'gold', 'diamond'];
    $currentIndex = array_search($currentLevel, $levelOrder);

    if ($currentIndex < count($levelOrder) - 1) {
        $nextLevelKey = $levelOrder[$currentIndex + 1];
        $nextLevel = LEVEL_THRESHOLDS[$nextLevelKey];
        $pointsToNext = $nextLevel['min'] - $totalPoints;
        $levelRange = $nextLevel['min'] - $levelInfo['min'];
        $pointsInRange = $totalPoints - $levelInfo['min'];
        $progressToNext = min(100, max(0, ($pointsInRange / max(1, $levelRange)) * 100));
    }

    // Update level if needed
    $newLevel = calculateLevel($totalPoints);
    if ($newLevel !== $currentLevel) {
        $stmt = $db->prepare("UPDATE om_customer_levels SET level = ? WHERE customer_id = ?");
        $stmt->execute([$newLevel, $customerId]);
        $currentLevel = $newLevel;
        $levelInfo = LEVEL_THRESHOLDS[$currentLevel];
    }

    // Group badges by category
    $badgesByCategory = [];
    foreach ($badges as $badge) {
        $cat = $badge['category'];
        if (!isset($badgesByCategory[$cat])) {
            $badgesByCategory[$cat] = [
                'name' => getCategoryName($cat),
                'badges' => []
            ];
        }
        $badgesByCategory[$cat]['badges'][] = $badge;
    }

    response(true, [
        'level' => [
            'current' => $currentLevel,
            'name' => $levelInfo['name'],
            'icon' => $levelInfo['icon'],
            'color' => $levelInfo['color'],
        ],
        'points' => [
            'total' => $totalPoints,
            'to_next_level' => max(0, $pointsToNext),
            'progress_percent' => round($progressToNext, 1),
        ],
        'next_level' => $nextLevel ? [
            'name' => $nextLevel['name'],
            'icon' => $nextLevel['icon'],
            'min_points' => $nextLevel['min'],
        ] : null,
        'streak' => [
            'current' => (int)$levelData['current_streak'],
            'best' => (int)$levelData['best_streak'],
            'is_active' => isStreakActive($levelData['last_order_date']),
        ],
        'stats' => [
            'total_orders' => (int)$levelData['total_orders'],
            'total_spent' => (float)$levelData['total_spent'],
            'total_reviews' => (int)$levelData['total_reviews'],
            'photo_reviews' => (int)$levelData['photo_reviews'],
        ],
        'badges' => [
            'total_earned' => count($earnedBadges),
            'total_available' => count($allBadges),
            'by_category' => $badgesByCategory,
            'recently_earned' => array_slice(array_filter($badges, fn($b) => $b['earned']), 0, 3),
        ],
        'new_badges' => $newBadges,
    ]);

} catch (Exception $e) {
    error_log("[vitrine/gamification] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar gamificacao", 500);
}

// ─────────────────────────────────────────────────────────────────────────────
// Helper functions
// ─────────────────────────────────────────────────────────────────────────────

function ensureCustomerLevel(PDO $db, int $customerId): void {
    // PostgreSQL: ON CONFLICT instead of INSERT IGNORE
    $stmt = $db->prepare("INSERT INTO om_customer_levels (customer_id) VALUES (?) ON CONFLICT (customer_id) DO NOTHING");
    $stmt->execute([$customerId]);
}

function updateCustomerStats(PDO $db, int $customerId): void {
    // Get order stats
    $stmt = $db->prepare("
        SELECT
            COUNT(*) as total_orders,
            COALESCE(SUM(total), 0) as total_spent,
            MAX(DATE(COALESCE(date_added, created_at))) as last_order_date
        FROM om_market_orders
        WHERE customer_id = ? AND status = 'entregue'
    ");
    $stmt->execute([$customerId]);
    $orderStats = $stmt->fetch();

    // Get review stats (safely handle missing table)
    $reviewStats = ['total_reviews' => 0, 'photo_reviews' => 0];
    try {
        $stmt = $db->prepare("
            SELECT
                COUNT(*) as total_reviews,
                SUM(CASE WHEN photo IS NOT NULL AND photo != '' THEN 1 ELSE 0 END) as photo_reviews
            FROM om_market_order_reviews
            WHERE customer_id = ?
        ");
        $stmt->execute([$customerId]);
        $reviewStats = $stmt->fetch() ?: $reviewStats;
    } catch (Exception $e) {}

    // Calculate streak
    $streak = calculateStreak($db, $customerId);

    // Calculate total points
    $totalPoints = calculateTotalPoints($db, $customerId, $orderStats, $reviewStats);

    // Update level record
    $stmt = $db->prepare("
        UPDATE om_customer_levels SET
            total_orders = ?,
            total_spent = ?,
            total_reviews = ?,
            photo_reviews = ?,
            current_streak = ?,
            best_streak = GREATEST(best_streak, ?),
            last_order_date = ?,
            total_points = ?
        WHERE customer_id = ?
    ");
    $stmt->execute([
        (int)$orderStats['total_orders'],
        (float)$orderStats['total_spent'],
        (int)($reviewStats['total_reviews'] ?? 0),
        (int)($reviewStats['photo_reviews'] ?? 0),
        $streak,
        $streak,
        $orderStats['last_order_date'],
        $totalPoints,
        $customerId
    ]);
}

function calculateStreak(PDO $db, int $customerId): int {
    $stmt = $db->prepare("
        SELECT DISTINCT DATE(COALESCE(date_added, created_at)) as order_date
        FROM om_market_orders
        WHERE customer_id = ? AND status = 'entregue'
        ORDER BY order_date DESC
        LIMIT 60
    ");
    $stmt->execute([$customerId]);
    $dates = array_column($stmt->fetchAll(), 'order_date');

    if (empty($dates)) return 0;

    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    // Streak must include today or yesterday
    if ($dates[0] !== $today && $dates[0] !== $yesterday) {
        return 0;
    }

    $streak = 1;
    for ($i = 1; $i < count($dates); $i++) {
        $expected = date('Y-m-d', strtotime($dates[$i - 1] . ' -1 day'));
        if ($dates[$i] === $expected) {
            $streak++;
        } else {
            break;
        }
    }

    return $streak;
}

function calculateTotalPoints(PDO $db, int $customerId, array $orderStats, array $reviewStats): int {
    // Base points: 1 point per R$1 spent
    $points = (int)$orderStats['total_spent'];

    // Bonus points from badges
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(bd.points_reward), 0) as badge_points
            FROM om_customer_badges cb
            INNER JOIN om_badge_definitions bd ON cb.badge_id = bd.badge_id
            WHERE cb.customer_id = ?
        ");
        $stmt->execute([$customerId]);
        $badgePoints = $stmt->fetch();
        $points += (int)($badgePoints['badge_points'] ?? 0);
    } catch (Exception $e) {}

    // Review bonus: 10 points per review
    $points += ((int)($reviewStats['total_reviews'] ?? 0)) * 10;

    return $points;
}

function checkAndAwardBadges(PDO $db, int $customerId): array {
    $newBadges = [];

    // Get current stats
    $stmt = $db->prepare("SELECT * FROM om_customer_levels WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $stats = $stmt->fetch();

    if (!$stats) return [];

    // Get earned badge IDs
    $stmt = $db->prepare("SELECT badge_id FROM om_customer_badges WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $earnedIds = array_column($stmt->fetchAll(), 'badge_id');

    // Check order milestones
    $orderMilestones = [
        'first_order' => 1,
        'orders_5' => 5,
        'orders_10' => 10,
        'orders_25' => 25,
        'orders_50' => 50,
        'orders_100' => 100,
    ];

    foreach ($orderMilestones as $badgeId => $required) {
        if (!in_array($badgeId, $earnedIds) && $stats['total_orders'] >= $required) {
            if (awardBadge($db, $customerId, $badgeId)) {
                $newBadges[] = $badgeId;
            }
        }
    }

    // Check streak milestones
    $streakMilestones = [
        'streak_3' => 3,
        'streak_7' => 7,
        'streak_14' => 14,
        'streak_30' => 30,
    ];

    foreach ($streakMilestones as $badgeId => $required) {
        if (!in_array($badgeId, $earnedIds) && $stats['best_streak'] >= $required) {
            if (awardBadge($db, $customerId, $badgeId)) {
                $newBadges[] = $badgeId;
            }
        }
    }

    // Check review badges
    if (!in_array('reviewer', $earnedIds) && $stats['total_reviews'] >= 1) {
        if (awardBadge($db, $customerId, 'reviewer')) {
            $newBadges[] = 'reviewer';
        }
    }

    if (!in_array('photo_reviewer', $earnedIds) && $stats['photo_reviews'] >= 1) {
        if (awardBadge($db, $customerId, 'photo_reviewer')) {
            $newBadges[] = 'photo_reviewer';
        }
    }

    if (!in_array('super_reviewer', $earnedIds) && $stats['total_reviews'] >= 10) {
        if (awardBadge($db, $customerId, 'super_reviewer')) {
            $newBadges[] = 'super_reviewer';
        }
    }

    // Check spending badges
    if (!in_array('vip_shopper', $earnedIds) && $stats['total_spent'] >= 1000) {
        if (awardBadge($db, $customerId, 'vip_shopper')) {
            $newBadges[] = 'vip_shopper';
        }
    }

    // Check for time-based badges (from recent orders)
    // PostgreSQL: use ::time and EXTRACT(DOW FROM ...) instead of TIME()/DAYOFWEEK()
    try {
        $stmt = $db->prepare("
            SELECT
                created_at::time as order_time,
                EXTRACT(DOW FROM created_at)::int as day_of_week,
                total
            FROM om_market_orders
            WHERE customer_id = ? AND status = 'entregue'
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$customerId]);
        $recentOrders = $stmt->fetchAll();
    } catch (Exception $e) {
        $recentOrders = [];
    }

    foreach ($recentOrders as $order) {
        // Night owl (after 10pm)
        if (!in_array('night_owl', $earnedIds) && $order['order_time'] >= '22:00:00') {
            if (awardBadge($db, $customerId, 'night_owl')) {
                $newBadges[] = 'night_owl';
                $earnedIds[] = 'night_owl';
            }
        }

        // Early bird (before 8am)
        if (!in_array('early_bird', $earnedIds) && $order['order_time'] < '08:00:00') {
            if (awardBadge($db, $customerId, 'early_bird')) {
                $newBadges[] = 'early_bird';
                $earnedIds[] = 'early_bird';
            }
        }

        // Big spender (single order > R$200)
        if (!in_array('big_spender', $earnedIds) && $order['total'] >= 200) {
            if (awardBadge($db, $customerId, 'big_spender')) {
                $newBadges[] = 'big_spender';
                $earnedIds[] = 'big_spender';
            }
        }
    }

    // Weekend warrior (5 weekend orders)
    // PostgreSQL EXTRACT(DOW): 0=Sunday, 6=Saturday
    if (!in_array('weekend_warrior', $earnedIds)) {
        $weekendOrders = array_filter($recentOrders, fn($o) => in_array((int)$o['day_of_week'], [0, 6]));
        if (count($weekendOrders) >= 5) {
            if (awardBadge($db, $customerId, 'weekend_warrior')) {
                $newBadges[] = 'weekend_warrior';
            }
        }
    }

    // Loyal customer (active for 6 months)
    if (!in_array('loyal_customer', $earnedIds)) {
        try {
            $stmt = $db->prepare("
                SELECT (CURRENT_DATE - MIN(created_at)::date) as days_active
                FROM om_market_orders
                WHERE customer_id = ? AND status = 'entregue'
            ");
            $stmt->execute([$customerId]);
            $daysActive = $stmt->fetch();
            if ($daysActive && $daysActive['days_active'] >= 180) {
                if (awardBadge($db, $customerId, 'loyal_customer')) {
                    $newBadges[] = 'loyal_customer';
                }
            }
        } catch (Exception $e) {}
    }

    // Return newly awarded badges with details
    if (!empty($newBadges)) {
        $placeholders = implode(',', array_fill(0, count($newBadges), '?'));
        $stmt = $db->prepare("
            SELECT badge_id, name, description, icon, points_reward
            FROM om_badge_definitions
            WHERE badge_id IN ($placeholders)
        ");
        $stmt->execute($newBadges);
        return $stmt->fetchAll();
    }

    return [];
}

function awardBadge(PDO $db, int $customerId, string $badgeId): bool {
    try {
        // PostgreSQL: ON CONFLICT instead of INSERT IGNORE
        $stmt = $db->prepare("
            INSERT INTO om_customer_badges (customer_id, badge_id)
            VALUES (?, ?)
            ON CONFLICT (customer_id, badge_id) DO NOTHING
        ");
        $stmt->execute([$customerId, $badgeId]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function calculateLevel(int $points): string {
    foreach (array_reverse(LEVEL_THRESHOLDS, true) as $level => $info) {
        if ($points >= $info['min']) {
            return $level;
        }
    }
    return 'bronze';
}

function isStreakActive(?string $lastOrderDate): bool {
    if (!$lastOrderDate) return false;

    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    return $lastOrderDate === $today || $lastOrderDate === $yesterday;
}

function calculateBadgeProgress(array $badge, array $stats): float {
    $current = 0;
    $required = (int)$badge['requirement_value'];

    switch ($badge['category']) {
        case 'orders':
            $current = (int)$stats['total_orders'];
            break;
        case 'streak':
            $current = (int)$stats['best_streak'];
            break;
        case 'reviews':
            if (strpos($badge['badge_id'], 'photo') !== false) {
                $current = (int)$stats['photo_reviews'];
            } else {
                $current = (int)$stats['total_reviews'];
            }
            break;
        case 'spending':
            if ($badge['badge_id'] === 'vip_shopper') {
                $current = (int)$stats['total_spent'];
            }
            break;
    }

    if ($required <= 0) return 0;
    return min(100, ($current / $required) * 100);
}

function getProgressText(array $badge, array $stats, float $progress): string {
    if ($progress >= 100) return 'Concluido!';

    $current = 0;
    $required = (int)$badge['requirement_value'];

    switch ($badge['category']) {
        case 'orders':
            $current = (int)$stats['total_orders'];
            return "{$current}/{$required} pedidos";
        case 'streak':
            $current = (int)$stats['best_streak'];
            return "{$current}/{$required} dias";
        case 'reviews':
            if (strpos($badge['badge_id'], 'photo') !== false) {
                $current = (int)$stats['photo_reviews'];
            } else {
                $current = (int)$stats['total_reviews'];
            }
            return "{$current}/{$required} avaliacoes";
        case 'spending':
            if ($badge['badge_id'] === 'vip_shopper') {
                $current = (int)$stats['total_spent'];
                return "R$ " . number_format($current, 0, ',', '.') . "/R$ " . number_format($required, 0, ',', '.');
            }
            break;
    }

    return '';
}

function getCategoryName(string $category): string {
    $names = [
        'orders' => 'Pedidos',
        'streak' => 'Sequencia',
        'reviews' => 'Avaliacoes',
        'special' => 'Especiais',
        'spending' => 'Compras',
    ];
    return $names[$category] ?? ucfirst($category);
}
