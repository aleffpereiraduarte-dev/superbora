<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) response(false, null, "Nao autorizado", 401);
    $pid = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // om_market_coupons uses specific_partners JSONB array instead of partner_id column
        $stmt = $db->prepare("
            SELECT id, code, description, discount_type, discount_value,
                   min_order_value AS min_order, max_discount, max_uses,
                   valid_from, valid_until, status, first_order_only,
                   created_at
            FROM om_market_coupons
            WHERE specific_partners::jsonb @> ?::jsonb
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $stmt->execute([json_encode([$pid])]);
        response(true, ['coupons' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $code = strtoupper(trim($body['code'] ?? ''));
        if (!$code) response(false, null, "code obrigatorio", 400);

        $stmt = $db->prepare("
            INSERT INTO om_market_coupons
                (code, description, discount_type, discount_value,
                 min_order_value, max_uses, valid_until,
                 status, specific_partners, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?::jsonb, NOW())
            RETURNING id
        ");
        $stmt->execute([
            $code,
            $body['description'] ?? '',
            $body['discount_type'] ?? 'percentage',
            (float)($body['discount_value'] ?? 0),
            (float)($body['min_order'] ?? 0),
            (int)($body['max_uses'] ?? 100),
            $body['expires_at'] ?? null,
            json_encode([$pid]),
        ]);
        response(true, ['id' => $stmt->fetchColumn()]);
    }
} catch (\Throwable $e) {
    error_log("[partner/cupons] " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
