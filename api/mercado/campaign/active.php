<?php
/**
 * GET /campaign/active.php?city=Governador+Valadares
 * Returns active campaigns for a given city.
 * Auth optional: if logged in, includes already_redeemed status.
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

try {
    $db = getDB();

    $city = trim($_GET['city'] ?? '');
    $campaignId = isset($_GET['id']) ? (int)$_GET['id'] : null;

    if (empty($city) && !$campaignId) {
        response(true, ['campaigns' => []]);
    }

    $now = date('Y-m-d H:i:s');

    // If specific campaign ID requested, fetch by ID (for detail screen)
    if ($campaignId) {
        $stmt = $db->prepare("
            SELECT campaign_id, slug, name, description, reward_text,
                   banner_title, banner_subtitle, banner_gradient, banner_icon,
                   max_redemptions, current_redemptions, new_customers_only,
                   start_date, end_date, city
            FROM om_campaigns
            WHERE campaign_id = ?
              AND status = 'active'
              AND start_date <= ?
              AND end_date >= ?
            LIMIT 1
        ");
        $stmt->execute([$campaignId, $now, $now]);
    } else {
        $stmt = $db->prepare("
            SELECT campaign_id, slug, name, description, reward_text,
                   banner_title, banner_subtitle, banner_gradient, banner_icon,
                   max_redemptions, current_redemptions, new_customers_only,
                   start_date, end_date
            FROM om_campaigns
            WHERE status = 'active'
              AND city ILIKE ?
              AND start_date <= ?
              AND end_date >= ?
              AND current_redemptions < max_redemptions
            ORDER BY start_date
            LIMIT 5
        ");
        $stmt->execute([$city, $now, $now]);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        response(true, ['campaigns' => []]);
    }

    // Optional auth: check if user already redeemed
    $customerId = null;
    $customerOrderCount = null;
    try {
        require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
        $token = om_auth()->getTokenFromRequest();
        if ($token) {
            $payload = om_auth()->validateToken($token);
            if ($payload && isset($payload['uid'])) {
                $customerId = (int)$payload['uid'];

                // Count completed orders for "new customer" check
                $orderStmt = $db->prepare("
                    SELECT COUNT(*) FROM om_market_orders
                    WHERE customer_id = ? AND status NOT IN ('cancelado', 'pendente')
                ");
                $orderStmt->execute([$customerId]);
                $customerOrderCount = (int)$orderStmt->fetchColumn();
            }
        }
    } catch (Exception $e) {
        // Auth failure is not critical for this endpoint
    }

    // Check redemptions for this user
    $redeemedCampaigns = [];
    if ($customerId) {
        $campaignIds = array_map(fn($r) => $r['campaign_id'], $rows);
        $ph = implode(',', array_fill(0, count($campaignIds), '?'));
        $rStmt = $db->prepare("
            SELECT campaign_id, redemption_code FROM om_campaign_redemptions
            WHERE customer_id = ? AND campaign_id IN ($ph)
        ");
        $rStmt->execute(array_merge([$customerId], $campaignIds));
        foreach ($rStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $redeemedCampaigns[$r['campaign_id']] = $r['redemption_code'];
        }
    }

    $campaigns = [];
    foreach ($rows as $row) {
        $cid = (int)$row['campaign_id'];
        $remaining = (int)$row['max_redemptions'] - (int)$row['current_redemptions'];
        $alreadyRedeemed = isset($redeemedCampaigns[$cid]);
        $isNewCustomerOnly = (bool)$row['new_customers_only'];

        // If new_customers_only and user has orders, mark as ineligible
        $eligible = true;
        $ineligibleReason = null;
        if ($customerId && $isNewCustomerOnly && $customerOrderCount > 0) {
            $eligible = false;
            $ineligibleReason = 'existing_customer';
        }

        $campaigns[] = [
            'campaign_id' => $cid,
            'slug' => $row['slug'],
            'name' => $row['name'],
            'description' => $row['description'],
            'reward_text' => $row['reward_text'],
            'banner_title' => $row['banner_title'],
            'banner_subtitle' => $row['banner_subtitle'],
            'banner_gradient' => json_decode($row['banner_gradient'] ?? '["#FF6B00","#E65100"]', true),
            'banner_icon' => $row['banner_icon'] ?? 'Gift',
            'remaining' => $remaining,
            'new_customers_only' => $isNewCustomerOnly,
            'already_redeemed' => $alreadyRedeemed,
            'redemption_code' => $redeemedCampaigns[$cid] ?? null,
            'eligible' => $eligible,
            'ineligible_reason' => $ineligibleReason,
            'start_date' => $row['start_date'],
            'end_date' => $row['end_date'],
        ];
    }

    response(true, ['campaigns' => $campaigns]);

} catch (Exception $e) {
    error_log("[campaign/active] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar campanhas", 500);
}
