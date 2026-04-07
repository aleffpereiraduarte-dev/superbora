<?php
/**
 * GET/POST /api/mercado/customer/data-export.php
 * LGPD - Data portability (Art. 18). Generates a JSON+ZIP with all customer data.
 *
 * POST -> Generates the export immediately (synchronous, fast for typical accounts).
 *         Returns { download_url, expires_at, size_bytes }.
 * GET  -> Returns the URL of the latest export if it still exists.
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
setCorsHeaders();

const EXPORT_DIR = '/var/www/html/storage/exports';
const EXPORT_TTL_HOURS = 72;

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $customerId = 0;
    $token = om_auth()->getTokenFromRequest();
    if ($token) {
        $payload = om_auth()->validateToken($token);
        if ($payload && ($payload['type'] ?? '') === 'customer') {
            $customerId = (int)$payload['uid'];
        }
    }
    if (!$customerId) {
        response(false, null, 'Nao autorizado', 401);
    }

    if (!is_dir(EXPORT_DIR)) {
        @mkdir(EXPORT_DIR, 0750, true);
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        // Find latest export for this customer that hasn't expired
        $files = glob(EXPORT_DIR . "/customer_{$customerId}_*.zip");
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        foreach ($files as $f) {
            $age = time() - filemtime($f);
            if ($age < EXPORT_TTL_HOURS * 3600) {
                $base = basename($f);
                response(true, [
                    'available' => true,
                    'download_url' => "https://superbora.com.br/storage/exports/$base",
                    'size_bytes' => filesize($f),
                    'expires_at' => date('c', filemtime($f) + EXPORT_TTL_HOURS * 3600),
                ]);
            }
        }
        response(true, ['available' => false]);
    }

    if ($method !== 'POST') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    // ============= Generate export =============
    $data = ['exported_at' => date('c'), 'customer_id' => $customerId];

    // 1) Personal data
    $stmt = $db->prepare(
        "SELECT customer_id, email, phone, name, cpf, birth_date, gender,
                created_at, last_login_at
         FROM om_customers WHERE customer_id = :cid"
    );
    $stmt->execute([':cid' => $customerId]);
    $data['personal'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // 2) Addresses
    $stmt = $db->prepare("SELECT * FROM om_customer_addresses WHERE customer_id = :cid");
    try { $stmt->execute([':cid' => $customerId]); $data['addresses'] = $stmt->fetchAll(PDO::FETCH_ASSOC); }
    catch (Exception $e) { $data['addresses'] = []; }

    // 3) Orders (last 5 years)
    $stmt = $db->prepare(
        "SELECT order_id, partner_id, total, status, payment_method,
                date_added, date_modified
         FROM om_market_orders
         WHERE customer_id = :cid
         ORDER BY date_added DESC LIMIT 1000"
    );
    try { $stmt->execute([':cid' => $customerId]); $data['orders'] = $stmt->fetchAll(PDO::FETCH_ASSOC); }
    catch (Exception $e) { $data['orders'] = []; }

    // 4) Ratings/reviews
    try {
        $stmt = $db->prepare("SELECT * FROM om_market_ratings WHERE customer_id = :cid ORDER BY created_at DESC");
        $stmt->execute([':cid' => $customerId]);
        $data['ratings'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $data['ratings'] = []; }

    // 5) Saved cards (masked)
    try {
        $stmt = $db->prepare("SELECT card_id, brand, last4, exp_month, exp_year, created_at FROM om_customer_cards WHERE customer_id = :cid");
        $stmt->execute([':cid' => $customerId]);
        $data['payment_methods'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $data['payment_methods'] = []; }

    // 6) Notifications history
    try {
        $stmt = $db->prepare("SELECT * FROM om_market_notifications WHERE customer_id = :cid ORDER BY created_at DESC LIMIT 500");
        $stmt->execute([':cid' => $customerId]);
        $data['notifications'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $data['notifications'] = []; }

    // 7) Consent history
    try {
        $stmt = $db->prepare("SELECT consent_type, granted, granted_at, revoked_at, source FROM om_customer_consent WHERE customer_id = :cid ORDER BY granted_at DESC");
        $stmt->execute([':cid' => $customerId]);
        $data['consent_history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $data['consent_history'] = []; }

    // Cleanup old exports for this customer
    foreach (glob(EXPORT_DIR . "/customer_{$customerId}_*.zip") as $old) {
        @unlink($old);
    }

    // Write JSON + zip
    $stamp = date('Ymd_His');
    $jsonPath = EXPORT_DIR . "/customer_{$customerId}_{$stamp}.json";
    $zipPath = EXPORT_DIR . "/customer_{$customerId}_{$stamp}.zip";

    file_put_contents($jsonPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
            $zip->addFile($jsonPath, "superbora_dados_{$customerId}.json");
            $zip->close();
            @unlink($jsonPath);
        }
    } else {
        // Fall back to .json file directly
        rename($jsonPath, $zipPath);
    }

    @chmod($zipPath, 0640);

    response(true, [
        'download_url' => 'https://superbora.com.br/storage/exports/' . basename($zipPath),
        'size_bytes' => filesize($zipPath),
        'expires_at' => date('c', time() + EXPORT_TTL_HOURS * 3600),
        'expires_in_hours' => EXPORT_TTL_HOURS,
    ]);
} catch (Exception $e) {
    error_log('[data-export] ' . $e->getMessage());
    response(false, null, 'Erro ao gerar exportacao', 500);
}
