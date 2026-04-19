<?php
/**
 * POST /api/mercado/admin/auth/verify-token.php
 * Verify a JWT token and return its payload (admin tool).
 */
require_once __DIR__ . "/../../config/database.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(['error' => 'Method not allowed'], 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $token = trim($input['token'] ?? '');

    if ($token === '') {
        response(['error' => 'Token is required'], 400);
    }

    $decoded = om_auth()->validateToken($token);
    if (!$decoded) {
        response(['valid' => false, 'error' => 'Token is invalid or expired']);
    }

    response([
        'valid' => true,
        'payload' => $decoded,
        'uid' => $decoded['uid'] ?? null,
        'role' => $decoded['role'] ?? null,
        'exp' => $decoded['exp'] ?? null,
    ]);

} catch (Exception $e) {
    response(['error' => $e->getMessage()], 500);
}
