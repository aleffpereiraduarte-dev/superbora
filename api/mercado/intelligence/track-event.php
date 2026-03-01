<?php
/**
 * User Behavior Tracking — fire-and-forget
 * POST: {event_type, entity_type, entity_id, session_id?, metadata?}
 * event_type: view_product, search, add_to_cart, view_store, view_category, purchase, remove_from_cart
 */
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { response(false, null, 'Method not allowed', 405); }

$input = json_decode(file_get_contents('php://input'), true);
$eventType = $input['event_type'] ?? '';
$entityType = $input['entity_type'] ?? '';
$entityId = intval($input['entity_id'] ?? 0);
$metadata = $input['metadata'] ?? [];
$sessionId = $input['session_id'] ?? '';

$validTypes = ['view_product', 'search', 'add_to_cart', 'view_store', 'view_category', 'purchase', 'remove_from_cart'];
if (!in_array($eventType, $validTypes)) { response(false, null, 'Invalid event_type', 400); }

// Validate entity_type whitelist
$validEntityTypes = ['product', 'store', 'category', 'order', 'search', ''];
if ($entityType && !in_array($entityType, $validEntityTypes)) { $entityType = ''; }

// Size limits to prevent storage abuse
$sessionId = substr($sessionId, 0, 128);
$metadataJson = json_encode($metadata ?: new stdClass());
if (strlen($metadataJson) > 4096) { $metadata = ['truncated' => true]; }

// Optional auth — get customer_id if logged in
$customerId = null;
try {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/', $authHeader, $m)) {
        require_once dirname(__DIR__, 3) . '/includes/classes/OmAuth.php';
        $db = getDB();
        $auth = OmAuth::getInstance();
        $auth->setDb($db);
        $payload = $auth->validateToken($m[1]);
        if ($payload && ($payload['type'] ?? '') === 'customer') {
            $customerId = intval($payload['sub'] ?? $payload['uid'] ?? 0);
        }
    }
} catch (Exception $e) { /* anonymous event */ }

if (!isset($db)) { $db = getDB(); }

$stmt = $db->prepare("INSERT INTO om_user_events (customer_id, session_id, event_type, entity_type, entity_id, metadata) VALUES (?, ?, ?, ?, ?, ?::jsonb)");
$stmt->execute([
    $customerId,
    $sessionId ?: null,
    $eventType,
    $entityType ?: null,
    $entityId ?: null,
    json_encode($metadata),
]);

response(true);
