<?php
/**
 * /api/mercado/customer/favoritos.php
 * Customer store favorites
 *
 * GET               - list favorite stores
 * POST { partner_id } - add store to favorites
 * DELETE { partner_id } - remove store from favorites
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Nao autorizado", 401);

    $customerId = (int)$payload['uid'];
    $method = $_SERVER["REQUEST_METHOD"];

    // ── GET: list favorites ──
    if ($method === "GET") {
        $stmt = $db->prepare("
            SELECT f.partner_id, f.created_at,
                   p.name AS partner_name, p.logo,
                   p.delivery_fee, p.delivery_time_min, p.delivery_time_max,
                   p.rating, p.is_open, p.categoria AS category
            FROM om_customer_favorites f
            LEFT JOIN om_market_partners p ON p.partner_id = f.partner_id
            WHERE f.customer_id = ? AND f.partner_id IS NOT NULL
            ORDER BY f.created_at DESC
        ");
        $stmt->execute([$customerId]);
        $favorites = $stmt->fetchAll();

        response(true, ['favorites' => $favorites, 'count' => count($favorites)]);
    }

    // ── POST: add or remove favorite ──
    if ($method === "POST") {
        $input = getInput();
        $partnerId = intval($input['partner_id'] ?? 0);
        if (!$partnerId) {
            response(false, null, "partner_id obrigatorio");
            exit;
        }

        $action = $input['action'] ?? 'add';

        if ($action === 'remove') {
            $stmt = $db->prepare("
                DELETE FROM om_customer_favorites
                WHERE customer_id = ? AND partner_id = ?
            ");
            $stmt->execute([$customerId, $partnerId]);
            response(true, ['partner_id' => $partnerId, 'favorited' => false], "Loja removida dos favoritos");
        } else {
            $stmt = $db->prepare("
                INSERT INTO om_customer_favorites (customer_id, partner_id, created_at)
                VALUES (?, ?, NOW())
                ON CONFLICT (customer_id, partner_id) DO NOTHING
            ");
            $stmt->execute([$customerId, $partnerId]);
            response(true, ['partner_id' => $partnerId, 'favorited' => true], "Loja adicionada aos favoritos");
        }
    }

    // ── DELETE: remove favorite (REST alternative) ──
    if ($method === "DELETE") {
        $input = getInput();
        $partnerId = intval($input['partner_id'] ?? $_GET['partner_id'] ?? 0);
        if (!$partnerId) {
            response(false, null, "partner_id obrigatorio");
            exit;
        }

        $stmt = $db->prepare("
            DELETE FROM om_customer_favorites
            WHERE customer_id = ? AND partner_id = ?
        ");
        $stmt->execute([$customerId, $partnerId]);

        response(true, ['partner_id' => $partnerId], "Loja removida dos favoritos");
    }

} catch (Exception $e) {
    error_log("[Favoritos] Error: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
