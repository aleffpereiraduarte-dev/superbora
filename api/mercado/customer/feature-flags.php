<?php
/**
 * GET /api/mercado/customer/feature-flags.php
 * Returns enabled feature flags for the current user (auth optional).
 * Mobile calls once at app boot + on auth change.
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/feature-flags.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

header('Cache-Control: private, max-age=30');

$customerId = 0;
try {
    OmAuth::getInstance()->setDb(getDB());
    $token = om_auth()->getTokenFromRequest();
    if ($token) {
        $payload = om_auth()->validateToken($token);
        if ($payload && ($payload['type'] ?? '') === 'customer') {
            $customerId = (int)$payload['uid'];
        }
    }
} catch (Exception $e) { /* anonymous */ }

$flags = featureFlagAll($customerId);
response(true, [
    'flags' => $flags,
    'customer_id' => $customerId ?: null,
]);
