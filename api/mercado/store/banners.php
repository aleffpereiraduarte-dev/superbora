<?php
/**
 * GET /api/mercado/store/banners.php?partner_id=X
 * Lista banners promocionais de uma loja
 */

require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

try {
    $db = getDB();

    $partnerId = (int)($_GET['partner_id'] ?? 0);
    if (!$partnerId) response(false, null, "partner_id obrigatorio", 400);

    // Try om_market_partner_banners first (partner-specific banners)
    $stmt = $db->prepare("
        SELECT
            banner_id as id,
            title,
            '' as subtitle,
            image,
            link,
            sort_order
        FROM om_market_partner_banners
        WHERE partner_id = ? AND status = '1'
        ORDER BY sort_order ASC, created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$partnerId]);
    $banners = $stmt->fetchAll();

    // If no partner banners, try om_market_banners with partner_id
    if (empty($banners)) {
        $stmt = $db->prepare("
            SELECT
                banner_id as id,
                title,
                subtitle,
                image,
                link,
                sort_order,
                icon,
                bg_color
            FROM om_market_banners
            WHERE partner_id = ? AND status = '1'
            ORDER BY sort_order ASC, created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$partnerId]);
        $banners = $stmt->fetchAll();
    }

    response(true, ['banners' => $banners]);

} catch (Exception $e) {
    error_log("[store/banners] Erro: " . $e->getMessage());
    response(false, ['banners' => []], "Erro ao carregar banners", 500);
}
