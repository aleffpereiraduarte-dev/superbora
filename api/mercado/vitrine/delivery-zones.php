<?php
/**
 * GET /api/mercado/vitrine/delivery-zones.php
 * Lista zonas de entrega de uma loja (publico)
 *
 * Parameters:
 *   partner_id (required) - Store ID
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

header('Cache-Control: public, max-age=300');

try {
    $db = getDB();

    $partnerId = (int)($_GET['partner_id'] ?? 0);

    if (!$partnerId) {
        response(false, null, "partner_id obrigatorio", 400);
    }

    // Check if table exists (PostgreSQL syntax)
    $tableCheck = $db->query("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'om_partner_delivery_zones')");
    if (!$tableCheck->fetchColumn()) {
        // Table doesn't exist, return empty with store's default fee
        $stmt = $db->prepare("SELECT delivery_fee, delivery_time_min, delivery_time_max FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$partnerId]);
        $partner = $stmt->fetch();

        if ($partner) {
            response(true, [
                'zones' => [[
                    'id' => 0,
                    'label' => 'Area de entrega',
                    'radius_min_km' => 0,
                    'radius_max_km' => 10,
                    'fee' => (float)($partner['delivery_fee'] ?? 0),
                    'estimated_time' => ($partner['delivery_time_min'] ?? '30') . '-' . ($partner['delivery_time_max'] ?? '45') . ' min',
                ]],
                'has_zones' => false
            ]);
        } else {
            response(true, ['zones' => [], 'has_zones' => false]);
        }
        exit;
    }

    // Get delivery zones
    $stmt = $db->prepare("
        SELECT
            id,
            label,
            radius_min_km,
            radius_max_km,
            fee,
            estimated_time
        FROM om_partner_delivery_zones
        WHERE partner_id = ? AND status = '1'
        ORDER BY radius_min_km ASC
    ");
    $stmt->execute([$partnerId]);
    $zones = $stmt->fetchAll();

    // If no zones, try to get from partner's default settings
    if (empty($zones)) {
        $stmt = $db->prepare("SELECT delivery_fee, delivery_time_min, delivery_time_max FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$partnerId]);
        $partner = $stmt->fetch();

        if ($partner && ($partner['delivery_fee'] !== null || $partner['delivery_time'])) {
            $zones = [[
                'id' => 0,
                'label' => 'Area de entrega',
                'radius_min_km' => 0,
                'radius_max_km' => 10,
                'fee' => (float)($partner['delivery_fee'] ?? 0),
                'estimated_time' => ($partner['delivery_time_min'] ?? '30') . '-' . ($partner['delivery_time_max'] ?? '45') . ' min',
            ]];
        }
    }

    // Format zones
    $formattedZones = array_map(function($zone) {
        return [
            'id' => (int)$zone['id'],
            'label' => $zone['label'],
            'radius_min_km' => (float)$zone['radius_min_km'],
            'radius_max_km' => (float)$zone['radius_max_km'],
            'fee' => (float)$zone['fee'],
            'estimated_time' => $zone['estimated_time'],
            'fee_text' => $zone['fee'] > 0
                ? 'R$ ' . number_format($zone['fee'], 2, ',', '.')
                : 'Gratis',
            'range_text' => $zone['radius_min_km'] > 0
                ? number_format($zone['radius_min_km'], 1) . ' - ' . number_format($zone['radius_max_km'], 1) . ' km'
                : 'Ate ' . number_format($zone['radius_max_km'], 1) . ' km',
        ];
    }, $zones);

    response(true, [
        'zones' => $formattedZones,
        'has_zones' => count($zones) > 0
    ]);

} catch (Exception $e) {
    error_log("[vitrine/delivery-zones] Erro: " . $e->getMessage());
    response(true, ['zones' => [], 'has_zones' => false]);
}
