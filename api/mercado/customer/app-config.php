<?php
/**
 * Feature Flags endpoint
 * GET: Returns active flags for a customer (considers rollout %, segment)
 * POST: Admin create/update flag (requires admin auth)
 */
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Public endpoint — returns flags active for this customer
    $customerId = intval($_GET['customer_id'] ?? 0);
    $city = trim($_GET['city'] ?? '');

    $stmt = $db->query("SELECT flag_key, rollout_percent, user_segment, metadata FROM om_feature_flags WHERE enabled = TRUE");
    $flags = $stmt->fetchAll();

    $active = [];
    foreach ($flags as $flag) {
        $key = $flag['flag_key'];
        $rollout = intval($flag['rollout_percent']);
        $segment = $flag['user_segment'];
        $meta = json_decode($flag['metadata'] ?: '{}', true);

        // Rollout check: use customer_id as deterministic hash
        if ($rollout < 100) {
            $hash = $customerId > 0 ? (crc32($key . $customerId) % 100) : rand(0, 99);
            if ($hash >= $rollout) continue;
        }

        // Segment check
        if ($segment) {
            if (strpos($segment, 'city:') === 0) {
                $flagCity = substr($segment, 5);
                if ($city && strtolower($city) !== strtolower($flagCity)) continue;
            }
            // Add more segment types as needed
        }

        $active[$key] = $meta ?: true;
    }

    response(true, ['flags' => $active], 'Feature flags loaded');

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Admin-only: create or update flag
    require_once __DIR__ . '/../config/auth.php';
    $auth = OmAuth::getInstance();
    $token = $auth->getTokenFromRequest();
    $decoded = $token ? $auth->validateToken($token) : null;
    $userType = $decoded['user_type'] ?? '';
    if ($userType !== 'admin') {
        http_response_code(403);
        response(false, null, 'Admin access required');
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $flagKey = trim($input['flag_key'] ?? '');
    if (!$flagKey) {
        response(false, null, 'flag_key is required');
        exit;
    }

    $enabled = isset($input['enabled']) ? ($input['enabled'] ? true : false) : true;
    $rollout = intval($input['rollout_percent'] ?? 100);
    $segment = trim($input['user_segment'] ?? '') ?: null;
    $description = trim($input['description'] ?? '') ?: null;
    $metadata = isset($input['metadata']) ? json_encode($input['metadata']) : '{}';

    $stmt = $db->prepare("
        INSERT INTO om_feature_flags (flag_key, description, enabled, rollout_percent, user_segment, metadata, updated_at)
        VALUES (:key, :desc, :enabled, :rollout, :segment, :meta, NOW())
        ON CONFLICT (flag_key) DO UPDATE SET
            description = COALESCE(EXCLUDED.description, om_feature_flags.description),
            enabled = EXCLUDED.enabled,
            rollout_percent = EXCLUDED.rollout_percent,
            user_segment = EXCLUDED.user_segment,
            metadata = EXCLUDED.metadata,
            updated_at = NOW()
        RETURNING *
    ");
    $stmt->execute([
        ':key' => $flagKey,
        ':desc' => $description,
        ':enabled' => $enabled ? 'true' : 'false',
        ':rollout' => max(0, min(100, $rollout)),
        ':segment' => $segment,
        ':meta' => $metadata,
    ]);
    $flag = $stmt->fetch();

    response(true, ['flag' => $flag], 'Flag saved');
}
