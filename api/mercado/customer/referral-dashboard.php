<?php
/**
 * GET /api/mercado/customer/referral-dashboard.php
 * Returns detailed referral dashboard data for the authenticated customer.
 *
 * Response:
 *   referral_code    - Customer's unique referral code
 *   share_url        - Direct URL for sharing
 *   total_referrals  - Total people referred
 *   pending          - Signed up but haven't ordered yet
 *   completed        - Signed up AND made first order (reward given)
 *   total_points     - Total loyalty points earned from referrals
 *   recent_activity  - Last 10 referral events
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
setCorsHeaders();

header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Authenticate customer
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token nao fornecido", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Nao autorizado", 401);
    $customerId = (int)$payload['uid'];

    // NOTE: om_market_referrals table and om_customers.referral_code column
    // should exist from migrations. Removed runtime MySQL DDL that was
    // incompatible with PostgreSQL. If tables are missing, run migrations.

    // Get or generate referral code
    $stmt = $db->prepare("SELECT referral_code FROM om_customers WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $row = $stmt->fetch();
    $code = $row['referral_code'] ?? null;

    if (empty($code)) {
        $maxRetries = 5;
        $base = strtoupper(base_convert($customerId, 10, 36));
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $rand = strtoupper(substr(bin2hex(random_bytes(8)), 0, 2 + $attempt));
            $code = 'SUPER' . $base . $rand;

            // Check uniqueness before saving
            $stmtCheck = $db->prepare("SELECT COUNT(*) FROM om_customers WHERE referral_code = ? AND customer_id != ?");
            $stmtCheck->execute([$code, $customerId]);
            if ((int)$stmtCheck->fetchColumn() === 0) {
                $stmtUp = $db->prepare("UPDATE om_customers SET referral_code = ? WHERE customer_id = ?");
                $stmtUp->execute([$code, $customerId]);
                break;
            }
            // Collision detected, retry with longer random suffix
            if ($attempt === $maxRetries - 1) {
                error_log("[referral-dashboard] Failed to generate unique referral code after $maxRetries attempts for customer $customerId");
                response(false, null, "Erro ao gerar codigo de indicacao. Tente novamente.", 500);
            }
        }
    }

    $shareUrl = "https://superbora.com.br/mercado/vitrine/login?ref={$code}";

    // Total referrals
    $stmtTotal = $db->prepare("SELECT COUNT(*) as total FROM om_market_referrals WHERE referrer_customer_id = ?");
    $stmtTotal->execute([$customerId]);
    $totalReferrals = (int)$stmtTotal->fetch()['total'];

    // Completed referrals (reward_given = 1, meaning referred user made first order)
    $stmtCompleted = $db->prepare("SELECT COUNT(*) as completed FROM om_market_referrals WHERE referrer_customer_id = ? AND status = 'converted'");
    $stmtCompleted->execute([$customerId]);
    $completed = (int)$stmtCompleted->fetch()['completed'];

    // Pending referrals (signed up but reward not given yet)
    $pending = $totalReferrals - $completed;

    // Total points earned from referrals (500 per completed referral)
    $totalPoints = $completed * 500;

    // Recent referral activity (last 10) - join with om_customers to get referred user name
    $stmtRecent = $db->prepare("
        SELECT
            r.id,
            r.referee_customer_id as referred_id,
            CASE WHEN r.status = 'converted' THEN 1 ELSE 0 END as reward_given,
            r.created_at,
            c.name as referred_name,
            c.email as referred_email
        FROM om_market_referrals r
        LEFT JOIN om_customers c ON c.customer_id = r.referee_customer_id
        WHERE r.referrer_customer_id = ?
        ORDER BY r.created_at DESC
        LIMIT 10
    ");
    $stmtRecent->execute([$customerId]);
    $recentActivity = [];

    while ($row = $stmtRecent->fetch()) {
        // Mask name for privacy: show first name + initial of last
        $nameParts = explode(' ', $row['referred_name'] ?? 'Usuario');
        $displayName = $nameParts[0];
        if (count($nameParts) > 1) {
            $displayName .= ' ' . substr($nameParts[count($nameParts) - 1], 0, 1) . '.';
        }

        $recentActivity[] = [
            'id' => (int)$row['id'],
            'referred_name' => $displayName,
            'status' => $row['reward_given'] ? 'completed' : 'pending',
            'status_label' => $row['reward_given'] ? 'Completo' : 'Pendente',
            'points_earned' => $row['reward_given'] ? 500 : 0,
            'created_at' => $row['created_at'],
        ];
    }

    response(true, [
        'referral_code' => $code,
        'share_url' => $shareUrl,
        'total_referrals' => $totalReferrals,
        'pending' => $pending,
        'completed' => $completed,
        'total_points' => $totalPoints,
        'recent_activity' => $recentActivity,
    ]);

} catch (Exception $e) {
    error_log("[API Referral Dashboard] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar painel de indicacoes", 500);
}
