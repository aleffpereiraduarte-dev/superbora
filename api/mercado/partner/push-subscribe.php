<?php
/**
 * Push Notification Subscription API
 *
 * POST - Subscribe to push notifications
 * DELETE - Unsubscribe from push notifications
 */

require_once __DIR__ . '/../config/database.php';
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

header('Content-Type: application/json');
setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Verify authentication using standard OmAuth system
    $token = om_auth()->getTokenFromRequest();
    if (!$token) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized', 'message' => 'Token ausente']);
        exit;
    }
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized', 'message' => 'Token invalido']);
        exit;
    }

    $partnerId = (int)$payload['uid'];
    $userType = 'partner';
    $pdo = $db; // Use standard DB connection for all queries

    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    // Handle unsubscribe action in POST
    if ($method === 'POST' && isset($input['action']) && $input['action'] === 'unsubscribe') {
        $method = 'DELETE';
    }

    switch ($method) {
        case 'POST':
            // Subscribe
            $endpoint = $input['endpoint'] ?? null;
            $p256dh = $input['p256dh'] ?? null;
            $authKey = $input['auth'] ?? null;

            if (!$endpoint) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing endpoint']);
                exit;
            }

            // Check if subscription already exists
            $stmt = $pdo->prepare("
                SELECT id FROM om_push_subscriptions
                WHERE user_id = ? AND user_type = ? AND endpoint = ?
            ");
            $stmt->execute([$partnerId, $userType, $endpoint]);
            $existing = $stmt->fetch();

            if ($existing) {
                // Update existing subscription
                $stmt = $pdo->prepare("
                    UPDATE om_push_subscriptions
                    SET p256dh = ?, auth = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$p256dh, $authKey, $existing['id']]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Subscription updated',
                    'subscription_id' => $existing['id']
                ]);
            } else {
                // Create new subscription
                $stmt = $pdo->prepare("
                    INSERT INTO om_push_subscriptions
                    (user_id, user_type, endpoint, p256dh, auth, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$partnerId, $userType, $endpoint, $p256dh, $authKey]);
                $subscriptionId = $pdo->lastInsertId();

                echo json_encode([
                    'success' => true,
                    'message' => 'Subscription created',
                    'subscription_id' => $subscriptionId
                ]);
            }
            break;

        case 'DELETE':
            // Unsubscribe
            $endpoint = $input['endpoint'] ?? $_GET['endpoint'] ?? null;

            if ($endpoint) {
                // Delete specific subscription
                $stmt = $pdo->prepare("
                    DELETE FROM om_push_subscriptions
                    WHERE user_id = ? AND user_type = ? AND endpoint = ?
                ");
                $stmt->execute([$partnerId, $userType, $endpoint]);
            } else {
                // Delete all subscriptions for this user
                $stmt = $pdo->prepare("
                    DELETE FROM om_push_subscriptions
                    WHERE user_id = ? AND user_type = ?
                ");
                $stmt->execute([$partnerId, $userType]);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Subscription(s) removed'
            ]);
            break;

        case 'GET':
            // Get current subscriptions
            $stmt = $pdo->prepare("
                SELECT id, endpoint, created_at
                FROM om_push_subscriptions
                WHERE user_id = ? AND user_type = ?
                ORDER BY created_at DESC
            ");
            $stmt->execute([$partnerId, $userType]);
            $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'subscriptions' => $subscriptions,
                'count' => count($subscriptions)
            ]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }

} catch (Exception $e) {
    error_log("Push subscribe error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Erro interno'
    ]);
}
