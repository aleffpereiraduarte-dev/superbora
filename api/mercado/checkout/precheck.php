<?php
/**
 * POST /api/mercado/checkout/precheck.php
 *
 * Pre-flight check BEFORE payment: confirms the partner is still open and
 * every cart item is still available + in stock. Keeps the front-end from
 * charging the card and then failing on processar.php.
 *
 * Body:
 *   { partner_id: int, items: [{ product_id, quantity }] }
 *
 * Responses (always HTTP 200 when auth is OK, so the client can branch on
 * `reason` without tripping generic error handling):
 *   { success: true }
 *   { success: false, reason: 'store_closed',  message: ... }
 *   { success: false, reason: 'out_of_stock',  unavailable: [...], message: ... }
 *   { success: false, reason: 'partner_invalid', message: ... }
 *   { success: false, reason: 'empty_cart', message: ... }
 *
 * This is a best-effort check — if it errors/timeouts on the client, the
 * caller MUST continue to processar.php (which re-validates everything).
 */

require_once __DIR__ . "/../config/database.php";
setCorsHeaders();
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";
require_once __DIR__ . "/../helpers/availability.php";

// Rate limit: precheck é chamado repetidamente durante checkout. 30 req/min/IP
// é confortável pro user real e bloqueia scan de catálogo / enumeration.
if (!RateLimiter::check(30, 60)) {
    response(false, ['reason' => 'rate_limited'], 'Muitas requisições, aguarde', 429);
}

try {
    $input = getInput();
    $db = getDB();

    // Auth required — we look up real customer context server-side
    $customer_id = requireCustomerAuth();

    $partner_id = (int)($input['partner_id'] ?? 0);
    $items_raw = $input['items'] ?? [];

    if ($partner_id <= 0) {
        response(false, ['reason' => 'partner_invalid'], 'partner_id obrigatório', 200);
    }
    if (!is_array($items_raw) || empty($items_raw)) {
        response(false, ['reason' => 'empty_cart', 'message' => 'Nenhum item enviado'], 'Nenhum item enviado', 200);
    }

    // Normalize items — tolerate { product_id | id, quantity | qty }
    $items = [];
    foreach ($items_raw as $it) {
        if (!is_array($it)) continue;
        $pid = (int)($it['product_id'] ?? $it['id'] ?? 0);
        $qty = (int)($it['quantity'] ?? $it['qty'] ?? 1);
        if ($pid <= 0) continue;
        if ($qty <= 0) $qty = 1;
        // Aggregate duplicates (same product_id appearing twice)
        if (isset($items[$pid])) {
            $items[$pid] += $qty;
        } else {
            $items[$pid] = $qty;
        }
    }
    if (empty($items)) {
        response(false, ['reason' => 'empty_cart', 'message' => 'Nenhum item válido'], 'Nenhum item válido', 200);
    }

    // ── 1) Partner open/active check ─────────────────────────────────────
    $stmtP = $db->prepare("
        SELECT partner_id, name, status, is_open, pause_until
        FROM om_market_partners
        WHERE partner_id = ?
        LIMIT 1
    ");
    $stmtP->execute([$partner_id]);
    $partner = $stmtP->fetch(PDO::FETCH_ASSOC);

    if (!$partner || (string)$partner['status'] !== '1') {
        response(false, [
            'reason' => 'store_closed',
            'message' => 'Loja indisponível. Tente outra.',
        ], 'Loja indisponível', 200);
    }

    $lojaFechada = (int)($partner['is_open'] ?? 0) !== 1;
    $lojaPausada = !empty($partner['pause_until']) && strtotime($partner['pause_until']) > time();
    if ($lojaFechada || $lojaPausada) {
        response(false, [
            'reason' => 'store_closed',
            'message' => 'Loja fechou. Tente outra.',
        ], 'Loja fechada', 200);
    }

    // ── 2) Per-item availability + stock check ───────────────────────────
    $productIds = array_keys($items);
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmtI = $db->prepare("
        SELECT product_id, name,
               COALESCE(quantity, stock, 0) AS estoque,
               COALESCE(status, 1) AS status,
               availability_schedule
        FROM om_market_products
        WHERE product_id IN ($placeholders)
    ");
    $stmtI->execute($productIds);
    $found = [];
    foreach ($stmtI->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $found[(int)$row['product_id']] = $row;
    }

    $unavailable = [];
    foreach ($items as $pid => $qty) {
        $row = $found[$pid] ?? null;
        if (!$row) {
            $unavailable[] = [
                'product_id' => $pid,
                'name' => 'Item removido',
                'reason' => 'not_found',
            ];
            continue;
        }
        // Status = 0 means disabled/hidden
        if ((int)$row['status'] !== 1) {
            $unavailable[] = [
                'product_id' => $pid,
                'name' => $row['name'],
                'reason' => 'disabled',
            ];
            continue;
        }
        // Schedule-gated availability (e.g., breakfast only 06:00-11:00)
        $schedule = $row['availability_schedule'] ?? null;
        if ($schedule && !isProductAvailable($schedule)) {
            $unavailable[] = [
                'product_id' => $pid,
                'name' => $row['name'],
                'reason' => 'schedule',
            ];
            continue;
        }
        // Stock
        $estoque = (int)$row['estoque'];
        if ($estoque < $qty) {
            $unavailable[] = [
                'product_id' => $pid,
                'name' => $row['name'],
                'reason' => 'out_of_stock',
                'available' => $estoque,
                'requested' => $qty,
            ];
            continue;
        }
    }

    if (!empty($unavailable)) {
        response(false, [
            'reason' => 'out_of_stock',
            'unavailable' => $unavailable,
            'message' => count($unavailable) === 1
                ? "'{$unavailable[0]['name']}' não está disponível."
                : 'Alguns itens acabaram.',
        ], 'Alguns itens acabaram', 200);
    }

    response(true, ['success' => true], null, 200);

} catch (Throwable $e) {
    error_log('[precheck] ' . $e->getMessage());
    // Best-effort endpoint — return 500 so client knows to skip and proceed
    response(false, ['reason' => 'error'], 'Erro interno', 500);
}
