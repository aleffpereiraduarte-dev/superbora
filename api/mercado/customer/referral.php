<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * REFERRAL PROGRAM - "Indique e Ganhe"
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * GET  — Returns referral stats, code, earnings, friend list, reward history
 * POST action=generate — Generate referral code (if customer doesn't have one)
 * POST action=apply    — New customer applies a friend's referral code
 *
 * Rewards:
 *   Referrer:  R$10 cashback when referred friend completes first order
 *   Referred:  R$15 discount coupon on first order
 *   Bonus:     R$5 extra for every 5th completed referral
 *
 * Status: pending (signed up) -> completed (first order) -> expired (30 days no order)
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/cashback.php";
setCorsHeaders();

// ─── Constants ───────────────────────────────────────────────────────────────
const REFERRER_REWARD   = 10.00;  // R$10 for referrer
const REFERRED_DISCOUNT = 15.00;  // R$15 off for referred friend
const MILESTONE_BONUS   = 5.00;   // R$5 extra every 5th referral
const MILESTONE_EVERY   = 5;      // Every N completed referrals
const EXPIRY_DAYS       = 30;     // Days before pending referral expires

try {
    $db = getDB();
    $method = $_SERVER['REQUEST_METHOD'];

    // ═════════════════════════════════════════════════════════════════════════
    // GET — Referral dashboard data
    // ═════════════════════════════════════════════════════════════════════════
    if ($method === 'GET') {
        $customerId = requireCustomerAuth();

        // Get or create referral code
        $code = getOrCreateReferralCode($db, $customerId);

        // Stats: total, completed, pending, expired
        $stats = getReferralStats($db, $customerId);

        // Earnings breakdown
        $earnings = getReferralEarnings($db, $customerId);

        // Friends list (last 30)
        $friends = getReferralFriends($db, $customerId);

        // Reward history (cashback transactions from referrals)
        $rewards = getRewardHistory($db, $customerId);

        // Milestone progress
        $completedCount = (int)$stats['completed'];
        $nextMilestone = (int)(ceil(($completedCount + 1) / MILESTONE_EVERY) * MILESTONE_EVERY);
        $milestonesReached = (int)floor($completedCount / MILESTONE_EVERY);
        $progressToNext = $completedCount % MILESTONE_EVERY;

        response(true, [
            'code'           => $code,
            'referral_code'  => $code,
            'share_url'      => "https://superbora.com.br/mercado/vitrine/?ref={$code}",
            'link'           => "https://superbora.com.br/mercado/vitrine/?ref={$code}",

            // Stats
            'total_referrals'    => (int)$stats['total'],
            'completed_referrals'=> (int)$stats['completed'],
            'pending_referrals'  => (int)$stats['pending'],
            'expired_referrals'  => (int)$stats['expired'],

            // Earnings
            'total_earned'       => (float)$earnings['total_earned'],
            'total_cashback'     => (float)$earnings['total_earned'],
            'pending_earnings'   => (float)$earnings['pending_earnings'],
            'this_month_earned'  => (float)$earnings['this_month'],
            'bonus_earned'       => (float)$earnings['bonus_earned'],

            // Milestone
            'milestone' => [
                'current_count'     => $completedCount,
                'next_milestone'    => $nextMilestone,
                'progress'          => $progressToNext,
                'milestone_every'   => MILESTONE_EVERY,
                'bonus_amount'      => MILESTONE_BONUS,
                'milestones_reached'=> $milestonesReached,
            ],

            // Reward config
            'reward_per_referral' => REFERRER_REWARD,
            'referred_discount'   => REFERRED_DISCOUNT,

            // Lists
            'referrals' => $friends,
            'rewards'   => $rewards,
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // POST — Actions
    // ═════════════════════════════════════════════════════════════════════════
    elseif ($method === 'POST') {
        $customerId = requireCustomerAuth();
        $input = getInput();
        $action = $input['action'] ?? '';

        switch ($action) {
            // ─── Generate referral code ──────────────────────────────────
            case 'generate':
                $code = getOrCreateReferralCode($db, $customerId);
                response(true, [
                    'code' => $code,
                    'share_url' => "https://superbora.com.br/mercado/vitrine/?ref={$code}",
                ], 'Codigo gerado com sucesso');
                break;

            // ─── Apply referral code (new customer uses friend's code) ──
            case 'apply':
                $referralCode = strtoupper(trim($input['referral_code'] ?? $input['code'] ?? ''));
                if (empty($referralCode)) {
                    response(false, null, 'Codigo de indicacao obrigatorio', 400);
                }
                $result = applyReferralCode($db, $customerId, $referralCode);
                if ($result['success']) {
                    response(true, $result['data'], $result['message']);
                } else {
                    response(false, null, $result['message'], $result['code'] ?? 400);
                }
                break;

            default:
                response(false, null, 'Acao invalida. Use: generate, apply', 400);
        }
    } else {
        response(false, null, 'Metodo nao permitido', 405);
    }

} catch (Exception $e) {
    error_log("[referral] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}

// ═════════════════════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Get or create a unique referral code for the customer.
 * Code format: SB + 8 hex chars (e.g. SB1A2B3C4D)
 */
function getOrCreateReferralCode(PDO $db, int $customerId): string {
    // Check om_referrals for existing code (row with no referred_id = the "seed" row)
    $stmt = $db->prepare("
        SELECT referral_code FROM om_referrals
        WHERE referrer_id = ? AND referred_id IS NULL
        LIMIT 1
    ");
    $stmt->execute([$customerId]);
    $existing = $stmt->fetchColumn();
    if ($existing) return $existing;

    // Also check om_customers.referral_code
    $stmt = $db->prepare("SELECT referral_code FROM om_customers WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $customerCode = $stmt->fetchColumn();

    if ($customerCode) {
        // Ensure it exists in om_referrals too
        $stmt = $db->prepare("
            INSERT INTO om_referrals (referrer_id, referral_code, status)
            VALUES (?, ?, 'active')
            ON CONFLICT DO NOTHING
        ");
        $stmt->execute([$customerId, $customerCode]);
        return $customerCode;
    }

    // Generate new code: SB + 8 random hex chars
    $maxRetries = 5;
    for ($i = 0; $i < $maxRetries; $i++) {
        $code = 'SB' . strtoupper(bin2hex(random_bytes(4)));

        // Check uniqueness
        $stmt = $db->prepare("SELECT COUNT(*) FROM om_referrals WHERE referral_code = ?");
        $stmt->execute([$code]);
        if ((int)$stmt->fetchColumn() === 0) {
            // Insert seed row (no referred_id = this is the owner's code)
            $stmt = $db->prepare("
                INSERT INTO om_referrals (referrer_id, referral_code, status, created_at)
                VALUES (?, ?, 'active', NOW())
            ");
            $stmt->execute([$customerId, $code]);

            // Also store on om_customers for backward compat
            $stmt = $db->prepare("UPDATE om_customers SET referral_code = ? WHERE customer_id = ?");
            $stmt->execute([$code, $customerId]);

            return $code;
        }
    }

    // Fallback: use customer ID based code
    $code = 'SB' . strtoupper(base_convert($customerId, 10, 36)) . strtoupper(bin2hex(random_bytes(3)));
    $stmt = $db->prepare("
        INSERT INTO om_referrals (referrer_id, referral_code, status, created_at)
        VALUES (?, ?, 'active', NOW())
    ");
    $stmt->execute([$customerId, $code]);
    $stmt = $db->prepare("UPDATE om_customers SET referral_code = ? WHERE customer_id = ?");
    $stmt->execute([$code, $customerId]);

    return $code;
}

/**
 * Get referral statistics for a customer.
 */
function getReferralStats(PDO $db, int $customerId): array {
    $stmt = $db->prepare("
        SELECT
            COUNT(CASE WHEN referred_id IS NOT NULL THEN 1 END) as total,
            COUNT(CASE WHEN status = 'completed' AND referred_id IS NOT NULL THEN 1 END) as completed,
            COUNT(CASE WHEN status = 'pending' AND referred_id IS NOT NULL THEN 1 END) as pending,
            COUNT(CASE WHEN status = 'expired' AND referred_id IS NOT NULL THEN 1 END) as expired
        FROM om_referrals
        WHERE referrer_id = ?
    ");
    $stmt->execute([$customerId]);
    return $stmt->fetch() ?: ['total' => 0, 'completed' => 0, 'pending' => 0, 'expired' => 0];
}

/**
 * Get earnings breakdown from referrals.
 */
function getReferralEarnings(PDO $db, int $customerId): array {
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(referrer_reward), 0) as total_earned,
            COALESCE(SUM(CASE WHEN status = 'pending' AND referred_id IS NOT NULL THEN " . REFERRER_REWARD . " ELSE 0 END), 0) as pending_earnings,
            COALESCE(SUM(CASE WHEN status = 'completed' AND completed_at >= date_trunc('month', NOW()) THEN referrer_reward ELSE 0 END), 0) as this_month,
            COALESCE(SUM(bonus_amount), 0) as bonus_earned
        FROM om_referrals
        WHERE referrer_id = ?
    ");
    $stmt->execute([$customerId]);
    return $stmt->fetch() ?: ['total_earned' => 0, 'pending_earnings' => 0, 'this_month' => 0, 'bonus_earned' => 0];
}

/**
 * Get list of referred friends with status and details.
 */
function getReferralFriends(PDO $db, int $customerId): array {
    $stmt = $db->prepare("
        SELECT
            r.referral_id,
            r.referred_id,
            r.status,
            r.referrer_reward,
            r.referred_reward,
            r.bonus_amount,
            r.created_at,
            r.completed_at,
            c.name as referred_name,
            c.phone as referred_phone
        FROM om_referrals r
        LEFT JOIN om_customers c ON c.customer_id = r.referred_id
        WHERE r.referrer_id = ? AND r.referred_id IS NOT NULL
        ORDER BY r.created_at DESC
        LIMIT 30
    ");
    $stmt->execute([$customerId]);
    $rows = $stmt->fetchAll();

    $friends = [];
    foreach ($rows as $row) {
        // Mask name: first name + initial
        $fullName = $row['referred_name'] ?? 'Amigo';
        $parts = explode(' ', $fullName);
        $displayName = $parts[0];
        if (count($parts) > 1) {
            $displayName .= ' ' . strtoupper(substr(end($parts), 0, 1)) . '.';
        }

        // Mask phone
        $phone = $row['referred_phone'] ?? '';
        $maskedPhone = $phone ? substr($phone, 0, 5) . '****' . substr($phone, -2) : '';

        $statusLabel = match($row['status']) {
            'completed' => 'Completou',
            'expired' => 'Expirou',
            default => 'Pendente',
        };

        $friends[] = [
            'id'            => (int)$row['referral_id'],
            'name'          => $displayName,
            'referred_name' => $displayName,
            'phone'         => $maskedPhone,
            'status'        => $row['status'],
            'status_label'  => $statusLabel,
            'cashback'      => (float)($row['referrer_reward'] ?? 0),
            'bonus'         => (float)($row['bonus_amount'] ?? 0),
            'created_at'    => $row['created_at'],
            'completed_at'  => $row['completed_at'],
        ];
    }

    return $friends;
}

/**
 * Get reward history (all cashback earned from referrals).
 */
function getRewardHistory(PDO $db, int $customerId): array {
    $stmt = $db->prepare("
        SELECT
            r.referral_id,
            r.referrer_reward,
            r.bonus_amount,
            r.completed_at,
            r.status,
            c.name as referred_name
        FROM om_referrals r
        LEFT JOIN om_customers c ON c.customer_id = r.referred_id
        WHERE r.referrer_id = ?
            AND r.status = 'completed'
            AND r.referrer_reward > 0
        ORDER BY r.completed_at DESC
        LIMIT 20
    ");
    $stmt->execute([$customerId]);
    $rows = $stmt->fetchAll();

    $rewards = [];
    foreach ($rows as $row) {
        $name = explode(' ', $row['referred_name'] ?? 'Amigo')[0];
        $amount = (float)$row['referrer_reward'] + (float)$row['bonus_amount'];

        $rewards[] = [
            'id'          => (int)$row['referral_id'],
            'description' => "Indicacao de {$name}",
            'amount'      => $amount,
            'cashback'    => (float)$row['referrer_reward'],
            'bonus'       => (float)$row['bonus_amount'],
            'date'        => $row['completed_at'],
            'type'        => (float)$row['bonus_amount'] > 0 ? 'bonus' : 'referral',
        ];
    }

    return $rewards;
}

/**
 * Apply a referral code: a new customer uses a friend's code.
 * Creates the referral link and generates a discount coupon.
 */
function applyReferralCode(PDO $db, int $referredId, string $referralCode): array {
    // 1. Validate the code exists and belongs to someone
    $stmt = $db->prepare("
        SELECT referrer_id, referral_code
        FROM om_referrals
        WHERE referral_code = ? AND referred_id IS NULL
        LIMIT 1
    ");
    $stmt->execute([$referralCode]);
    $seed = $stmt->fetch();

    if (!$seed) {
        return ['success' => false, 'message' => 'Codigo de indicacao invalido ou expirado', 'code' => 404];
    }

    $referrerId = (int)$seed['referrer_id'];

    // 2. Can't refer yourself
    if ($referrerId === $referredId) {
        return ['success' => false, 'message' => 'Voce nao pode usar seu proprio codigo', 'code' => 400];
    }

    // 3. Check if this customer was already referred by anyone
    $stmt = $db->prepare("
        SELECT referral_id FROM om_referrals
        WHERE referred_id = ?
        LIMIT 1
    ");
    $stmt->execute([$referredId]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'Voce ja foi indicado por alguem', 'code' => 409];
    }

    // 4. Check if this customer already has orders (not a new customer)
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM om_market_orders
        WHERE customer_id = ? AND status NOT IN ('cancelado', 'recusado')
    ");
    $stmt->execute([$referredId]);
    if ((int)$stmt->fetchColumn() > 0) {
        return ['success' => false, 'message' => 'Indicacao disponivel apenas para novos clientes', 'code' => 400];
    }

    try {
        $db->beginTransaction();

        // 5. Create referral record (pending until first order)
        $stmt = $db->prepare("
            INSERT INTO om_referrals (referrer_id, referred_id, referral_code, status, referrer_reward, referred_reward, created_at)
            VALUES (?, ?, ?, 'pending', 0, ?, NOW())
        ");
        $stmt->execute([$referrerId, $referredId, $referralCode, REFERRED_DISCOUNT]);

        $referralId = (int)$db->lastInsertId('om_referrals_referral_id_seq');

        // 6. Generate discount coupon for the referred friend
        $couponCode = 'REF' . strtoupper(bin2hex(random_bytes(4)));
        $stmt = $db->prepare("
            INSERT INTO om_referral_coupons (referral_id, referred_id, coupon_code, discount_amount, expires_at, created_at)
            VALUES (?, ?, ?, ?, NOW() + INTERVAL '30 days', NOW())
        ");
        $stmt->execute([$referralId, $referredId, $couponCode, REFERRED_DISCOUNT]);

        $db->commit();

        return [
            'success' => true,
            'message' => 'Codigo aplicado! Voce ganhou R$ ' . number_format(REFERRED_DISCOUNT, 2, ',', '.') . ' de desconto na primeira compra!',
            'data' => [
                'coupon_code'     => $couponCode,
                'discount_amount' => REFERRED_DISCOUNT,
                'expires_in_days' => EXPIRY_DAYS,
                'referrer_name'   => getCustomerFirstName($db, $referrerId),
            ],
        ];

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("[referral:apply] Erro: " . $e->getMessage());
        return ['success' => false, 'message' => 'Erro ao aplicar codigo', 'code' => 500];
    }
}

/**
 * Complete a referral: called when a referred customer finishes their first order.
 * Awards cashback to the referrer + checks milestone bonuses.
 *
 * This should be called from confirmar-entrega.php or the order completion flow.
 */
function completeReferral(PDO $db, int $referredCustomerId, int $orderId): array {
    // Find pending referral for this customer
    $stmt = $db->prepare("
        SELECT referral_id, referrer_id, referral_code
        FROM om_referrals
        WHERE referred_id = ? AND status = 'pending'
        LIMIT 1
    ");
    $stmt->execute([$referredCustomerId]);
    $referral = $stmt->fetch();

    if (!$referral) {
        return ['success' => false, 'message' => 'Nenhuma indicacao pendente'];
    }

    $referralId = (int)$referral['referral_id'];
    $referrerId = (int)$referral['referrer_id'];

    try {
        $db->beginTransaction();

        // Lock the referral row
        $stmt = $db->prepare("
            SELECT referral_id, status FROM om_referrals
            WHERE referral_id = ? FOR UPDATE
        ");
        $stmt->execute([$referralId]);
        $locked = $stmt->fetch();

        if (!$locked || $locked['status'] !== 'pending') {
            $db->rollBack();
            return ['success' => false, 'message' => 'Indicacao ja processada'];
        }

        // Count completed referrals to check milestone
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM om_referrals
            WHERE referrer_id = ? AND status = 'completed'
        ");
        $stmt->execute([$referrerId]);
        $completedCount = (int)$stmt->fetchColumn();
        $newCount = $completedCount + 1;

        // Calculate bonus
        $bonusAmount = 0;
        if ($newCount > 0 && $newCount % MILESTONE_EVERY === 0) {
            $bonusAmount = MILESTONE_BONUS;
        }

        $totalReward = REFERRER_REWARD + $bonusAmount;

        // Mark referral as completed
        $stmt = $db->prepare("
            UPDATE om_referrals
            SET status = 'completed',
                referrer_reward = ?,
                bonus_amount = ?,
                referred_order_id = ?,
                completed_at = NOW()
            WHERE referral_id = ?
        ");
        $stmt->execute([$totalReward, $bonusAmount, $orderId, $referralId]);

        // Credit cashback to referrer's wallet
        // Use ON CONFLICT for idempotency
        $stmt = $db->prepare("
            INSERT INTO om_cashback_wallet (customer_id, balance, total_earned)
            VALUES (?, ?, ?)
            ON CONFLICT (customer_id) DO UPDATE SET
                balance = om_cashback_wallet.balance + EXCLUDED.balance,
                total_earned = om_cashback_wallet.total_earned + EXCLUDED.total_earned
        ");
        $stmt->execute([$referrerId, $totalReward, $totalReward]);

        // Record cashback transaction
        $referredName = getCustomerFirstName($db, $referredCustomerId);
        $description = "Indicacao de {$referredName} - R$ " . number_format(REFERRER_REWARD, 2, ',', '.');
        if ($bonusAmount > 0) {
            $description .= " + Bonus {$newCount}a indicacao R$ " . number_format($bonusAmount, 2, ',', '.');
        }

        // Get updated balance
        $stmt = $db->prepare("SELECT balance FROM om_cashback_wallet WHERE customer_id = ?");
        $stmt->execute([$referrerId]);
        $newBalance = (float)$stmt->fetchColumn();

        $stmt = $db->prepare("
            INSERT INTO om_cashback_transactions
            (customer_id, order_id, type, amount, balance_after, description)
            VALUES (?, ?, 'credit', ?, ?, ?)
        ");
        $stmt->execute([$referrerId, $orderId, $totalReward, $newBalance, $description]);

        // Send notification to referrer
        try {
            require_once __DIR__ . "/../config/notify.php";
            $bonusText = $bonusAmount > 0
                ? " (incluindo R$ " . number_format($bonusAmount, 2, ',', '.') . " de bonus!)"
                : "";
            sendNotification(
                $db,
                $referrerId,
                'customer',
                'Indicacao confirmada!',
                "{$referredName} fez a primeira compra! Voce ganhou R$ " . number_format($totalReward, 2, ',', '.') . " de cashback{$bonusText}",
                ['type' => 'referral_completed', 'amount' => $totalReward]
            );
        } catch (Exception $e) {
            error_log("[referral:notify] Erro ao notificar referrer: " . $e->getMessage());
            // Non-fatal: don't fail the referral completion
        }

        // Mark referral coupon as used
        $stmt = $db->prepare("
            UPDATE om_referral_coupons
            SET used = true, used_at = NOW(), order_id = ?
            WHERE referral_id = ? AND referred_id = ?
        ");
        $stmt->execute([$orderId, $referralId, $referredCustomerId]);

        $db->commit();

        return [
            'success' => true,
            'referrer_id' => $referrerId,
            'reward' => $totalReward,
            'bonus' => $bonusAmount,
            'milestone_reached' => $bonusAmount > 0 ? $newCount : 0,
            'message' => "Referral completed: R$ " . number_format($totalReward, 2, ',', '.') . " credited to referrer #{$referrerId}",
        ];

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("[referral:complete] Erro: " . $e->getMessage());
        return ['success' => false, 'message' => 'Erro ao completar indicacao'];
    }
}

/**
 * Get customer first name for display.
 */
function getCustomerFirstName(PDO $db, int $customerId): string {
    $stmt = $db->prepare("SELECT name FROM om_customers WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $name = $stmt->fetchColumn() ?: 'Amigo';
    return explode(' ', $name)[0];
}
