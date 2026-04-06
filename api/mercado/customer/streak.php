<?php
/**
 * GET /api/mercado/customer/streak.php
 * Returns current streak (consecutive days with delivered orders),
 * longest streak, streak calendar (last 7 days), and next reward milestone.
 *
 * Streak rewards:
 *   3 days  = 50 bonus points
 *   7 days  = 150 pts + free delivery coupon
 *   14 days = 500 pts
 *   30 days = 1000 pts + R$10 cashback
 */

require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    $customerId = requireCustomerAuth();

    // ── 1. Get all distinct dates with at least one delivered order ──
    // Uses PostgreSQL date_trunc to group by calendar day
    $stmt = dbQuery($db, "
        SELECT DISTINCT date_trunc('day', COALESCE(delivered_at, date_added))::date AS order_date
        FROM om_market_orders
        WHERE customer_id = ?
          AND status IN ('delivered', 'entregue', 'retirado')
        ORDER BY order_date DESC
    ", [$customerId]);
    $orderDates = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // ── 2. Calculate current streak ──
    // Walk backwards from today counting consecutive days
    $today = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    $todayStr = $today->format('Y-m-d');
    $yesterdayStr = (clone $today)->modify('-1 day')->format('Y-m-d');

    $dateSet = array_flip($orderDates); // hash set for O(1) lookup

    $currentStreak = 0;
    $isActive = false;

    if (isset($dateSet[$todayStr])) {
        // Streak includes today
        $isActive = true;
        $checkDate = clone $today;
    } elseif (isset($dateSet[$yesterdayStr])) {
        // Last order was yesterday — streak still alive but not active today yet
        $isActive = false;
        $checkDate = (clone $today)->modify('-1 day');
    } else {
        // No order today or yesterday — streak is broken
        $checkDate = null;
    }

    if ($checkDate !== null) {
        while (isset($dateSet[$checkDate->format('Y-m-d')])) {
            $currentStreak++;
            $checkDate->modify('-1 day');
        }
    }

    // ── 3. Calculate longest streak ever ──
    $longestStreak = 0;
    if (count($orderDates) > 0) {
        // orderDates are DESC sorted, reverse for forward iteration
        $sortedDates = array_reverse($orderDates);
        $streak = 1;
        for ($i = 1; $i < count($sortedDates); $i++) {
            $prev = new DateTime($sortedDates[$i - 1]);
            $curr = new DateTime($sortedDates[$i]);
            $diff = (int)$curr->diff($prev)->days;
            if ($diff === 1) {
                $streak++;
            } else {
                $longestStreak = max($longestStreak, $streak);
                $streak = 1;
            }
        }
        $longestStreak = max($longestStreak, $streak);
    }

    // ── 4. Streak calendar (last 7 days) ──
    $calendar = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = (clone $today)->modify("-{$i} day");
        $dateStr = $d->format('Y-m-d');
        $calendar[] = [
            'date' => $dateStr,
            'weekday' => $d->format('D'), // Mon, Tue, ...
            'has_order' => isset($dateSet[$dateStr]),
        ];
    }

    // ── 5. Streak rewards definition + next milestone ──
    $rewardMilestones = [
        ['days' => 3,  'points' => 50,   'label' => '50 pontos bonus',                       'extra' => null],
        ['days' => 7,  'points' => 150,  'label' => '150 pts + frete gratis',                'extra' => 'free_delivery'],
        ['days' => 14, 'points' => 500,  'label' => '500 pontos bonus',                      'extra' => null],
        ['days' => 30, 'points' => 1000, 'label' => '1000 pts + R$10 cashback',              'extra' => 'cashback_10'],
    ];

    // Find earned rewards and next milestone
    $earnedRewards = [];
    $nextReward = null;
    foreach ($rewardMilestones as $milestone) {
        if ($currentStreak >= $milestone['days']) {
            $earnedRewards[] = $milestone;
        } elseif ($nextReward === null) {
            $nextReward = [
                'days' => $milestone['days'],
                'points' => $milestone['points'],
                'label' => $milestone['label'],
                'days_remaining' => $milestone['days'] - $currentStreak,
            ];
        }
    }

    // If user passed all milestones, no next reward
    if ($nextReward === null && $currentStreak >= 30) {
        $nextReward = null; // All milestones achieved
    }

    // ── 6. Total delivered orders (for context) ──
    $stmt = dbQuery($db, "
        SELECT COUNT(*) as total
        FROM om_market_orders
        WHERE customer_id = ? AND status IN ('delivered', 'entregue', 'retirado')
    ", [$customerId]);
    $totalOrders = (int)$stmt->fetchColumn();

    response(true, [
        'current_streak' => $currentStreak,
        'longest_streak' => $longestStreak,
        'is_active' => $isActive,
        'total_order_days' => count($orderDates),
        'total_orders' => $totalOrders,
        'calendar' => $calendar,
        'earned_rewards' => $earnedRewards,
        'next_reward' => $nextReward,
        'milestones' => $rewardMilestones,
    ]);

} catch (Exception $e) {
    error_log("[customer/streak] Erro: " . $e->getMessage());
    response(false, null, "Erro ao calcular sequencia de pedidos", 500);
}
