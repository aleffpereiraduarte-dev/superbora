<?php
/**
 * GET /campaign/admin-qr-data.php?id=1&pin=SB2026
 * JSON endpoint: returns rotating QR data + stats.
 * Also returns recent redemptions for live feed.
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/rate-limit.php";

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store');

try {
    $campaignId = (int)($_GET['id'] ?? 0);
    $pin = trim($_GET['pin'] ?? '');

    if (!$campaignId || empty($pin)) {
        echo json_encode(['error' => 'Missing id or pin']);
        exit;
    }

    // Rate limit failed PIN attempts: 10 per 5 minutes per IP
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rlKey = "admin_qr_fail:{$campaignId}:{$ip}";

    $db = getDB();

    $stmt = $db->prepare("
        SELECT campaign_id, name, qr_secret, admin_pin, qr_rotation_seconds,
               max_redemptions, current_redemptions, status, start_date, end_date
        FROM om_campaigns WHERE campaign_id = ?
    ");
    $stmt->execute([$campaignId]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$campaign || !hash_equals($campaign['admin_pin'], $pin)) {
        // Count failed PIN attempt for rate limiting
        if (!checkRateLimit($rlKey, 10, 300)) {
            http_response_code(429);
            echo json_encode(['error' => 'Muitas tentativas. Aguarde 5 minutos.']);
            exit;
        }
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    // Generate QR data with current timestamp
    $timestamp = time();
    $hmac = hash_hmac('sha256', "{$campaignId}:{$timestamp}", $campaign['qr_secret']);
    $qrData = "superbora://camp/{$campaignId}/{$timestamp}/{$hmac}";

    // Last 10 redemptions
    $recentStmt = $db->prepare("
        SELECT redemption_code, customer_name, customer_phone, redeemed_at
        FROM om_campaign_redemptions
        WHERE campaign_id = ?
        ORDER BY redeemed_at DESC
        LIMIT 10
    ");
    $recentStmt->execute([$campaignId]);
    $recent = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

    // Mask phone numbers
    foreach ($recent as &$r) {
        if (!empty($r['customer_phone'])) {
            $r['customer_phone'] = substr($r['customer_phone'], 0, 4) . '****' . substr($r['customer_phone'], -2);
        }
    }

    echo json_encode([
        'qr_data' => $qrData,
        'campaign_name' => $campaign['name'],
        'remaining' => (int)$campaign['max_redemptions'] - (int)$campaign['current_redemptions'],
        'total_redeemed' => (int)$campaign['current_redemptions'],
        'max' => (int)$campaign['max_redemptions'],
        'status' => $campaign['status'],
        'recent_redemptions' => $recent,
        'rotation_seconds' => (int)($campaign['qr_rotation_seconds'] ?: 30),
        'timestamp' => $timestamp,
    ]);

} catch (Exception $e) {
    error_log("[admin-qr-data] Erro: " . $e->getMessage());
    echo json_encode(['error' => 'Internal error']);
}
