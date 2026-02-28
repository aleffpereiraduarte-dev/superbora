<?php
/**
 * GET /api/mercado/carrinho/listar.php?session_id=xxx
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();
require_once dirname(__DIR__, 3) . "/includes/classes/OmPricing.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

try {
    $db = getDB();

    // SECURITY: Use authenticated customer_id when available (ignore client input)
    OmAuth::getInstance()->setDb($db);
    $authCustomerId = 0;
    try {
        $token = om_auth()->getTokenFromRequest();
        if ($token) {
            $payload = om_auth()->validateToken($token);
            if ($payload && $payload['type'] === 'customer') {
                $authCustomerId = (int)$payload['uid'];
            }
        }
    } catch (Exception $e) {
        // Auth is optional for cart listing (anonymous sessions allowed)
    }

    $session_id = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_GET["session_id"] ?? ''));
    $customer_id = $authCustomerId; // SECURITY: never trust client-supplied customer_id
    $routeMode = (int)($_GET["route_mode"] ?? 0);
    $primaryPartnerId = (int)($_GET["primary_partner_id"] ?? 0);

    if (!$session_id && !$customer_id) {
        response(true, ["itens" => [], "subtotal" => 0, "taxa_entrega" => 0, "total" => 0]);
    }

    // SECURITY: Validate session_id format for anonymous carts (must be UUID-like)
    if (!$customer_id && $session_id) {
        if (!preg_match('/^[a-f0-9]{8}-?[a-f0-9]{4}-?[a-f0-9]{4}-?[a-f0-9]{4}-?[a-f0-9]{12}$/i', $session_id)
            && !preg_match('/^sess_[a-zA-Z0-9_-]{20,60}$/', $session_id)) {
            response(true, ["itens" => [], "subtotal" => 0, "taxa_entrega" => 0, "total" => 0]);
        }
    }

    // Build WHERE clause — authenticated users use customer_id only, anonymous use session_id
    if ($customer_id > 0) {
        $whereClause = "c.customer_id = ?";
        $whereParams = [$customer_id];
    } else {
        $whereClause = "c.session_id = ?";
        $whereParams = [$session_id];
    }

    $sql = "SELECT c.cart_id, c.product_id, c.partner_id, c.quantity, p.price, c.notes,
                   p.name, p.image, p.unit, p.special_price, p.quantity as estoque,
                   pr.name as parceiro_nome, pr.delivery_fee, pr.free_delivery_above, pr.entrega_propria,
                   pr.is_open, pr.pause_until
            FROM om_market_cart c
            INNER JOIN om_market_products p ON c.product_id = p.product_id
            INNER JOIN om_market_partners pr ON c.partner_id = pr.partner_id
            WHERE {$whereClause}
            ORDER BY c.cart_id ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($whereParams);
    $itens = $stmt->fetchAll();

    if (empty($itens)) {
        response(true, ["itens" => [], "subtotal" => 0, "taxa_entrega" => 0, "total" => 0]);
    }

    // Calculate subtotal and per-partner fees
    $subtotal = 0;
    $partnerSubtotals = [];
    $partnerFees = [];

    foreach ($itens as $i) {
        $preco = ($i["special_price"] && floatval($i["special_price"]) > 0 && floatval($i["special_price"]) < floatval($i["price"]))
            ? floatval($i["special_price"]) : floatval($i["price"]);
        $itemTotal = $preco * $i["quantity"];
        $subtotal += $itemTotal;

        $pid = $i["partner_id"];
        $partnerSubtotals[$pid] = ($partnerSubtotals[$pid] ?? 0) + $itemTotal;
        if (!isset($partnerFees[$pid])) {
            $fee = floatval($i['delivery_fee'] ?? 0);
            // BoraUm minimum: via OmPricing (fonte unica)
            if (!$i['entrega_propria'] && $fee > 0 && $fee < OmPricing::BORAUM_MINIMO) {
                $fee = OmPricing::BORAUM_MINIMO;
            }
            $isOpen = (int)($i['is_open'] ?? 0) === 1;
            $isPaused = !empty($i['pause_until']) && strtotime($i['pause_until']) > time();
            $partnerFees[$pid] = [
                'nome' => $i['parceiro_nome'],
                'delivery_fee' => $fee,
                'free_delivery_above' => floatval($i['free_delivery_above'] ?? 0),
                'loja_aberta' => $isOpen && !$isPaused,
            ];
        }
    }

    // Calculate total delivery fee
    $taxa_entrega = 0;
    foreach ($partnerFees as $pid => $pf) {
        $fee = $pf['delivery_fee'];
        if ($pf['free_delivery_above'] > 0 && ($partnerSubtotals[$pid] ?? 0) >= $pf['free_delivery_above']) {
            $fee = 0;
        }
        // Multi-stop route: lojas secundarias tem frete zero
        if ($routeMode && $primaryPartnerId && (int)$pid !== $primaryPartnerId) {
            $fee = 0;
        }
        $taxa_entrega += $fee;
    }

    // Build partner info array
    $parceiros = [];
    foreach ($partnerFees as $pid => $pf) {
        $fee = $pf['delivery_fee'];
        $freeAbove = $pf['free_delivery_above'];
        $storeSub = $partnerSubtotals[$pid] ?? 0;
        $isFree = $freeAbove > 0 && $storeSub >= $freeAbove;

        $isRouteStore = $routeMode && $primaryPartnerId && (int)$pid !== $primaryPartnerId;
        $parceiros[] = [
            'id' => (int)$pid,
            'nome' => $pf['nome'],
            'taxa_entrega' => round($isRouteStore ? 0 : ($isFree ? 0 : $fee), 2),
            'taxa_entrega_base' => round($fee, 2),
            'entrega_gratis_acima' => $freeAbove > 0 ? round($freeAbove, 2) : null,
            'subtotal' => round($storeSub, 2),
            'route_store' => $isRouteStore,
            'loja_aberta' => $pf['loja_aberta'],
        ];
    }

    response(true, [
        "parceiro" => [
            "id" => $itens[0]["partner_id"],
            "nome" => $itens[0]["parceiro_nome"],
            "loja_aberta" => $partnerFees[$itens[0]["partner_id"]]['loja_aberta'] ?? true
        ],
        "parceiros" => $parceiros,
        "itens" => array_map(function($i) {
            $preco = floatval($i["price"]);
            $promoPreco = ($i["special_price"] && floatval($i["special_price"]) > 0 && floatval($i["special_price"]) < $preco)
                ? floatval($i["special_price"]) : null;
            return [
                "id" => $i["cart_id"],
                "cart_id" => $i["cart_id"],
                "product_id" => $i["product_id"],
                "partner_id" => $i["partner_id"],
                "parceiro_nome" => $i["parceiro_nome"],
                "nome" => $i["name"],
                "imagem" => $i["image"],
                "preco" => $preco,
                "preco_promo" => $promoPreco,
                "quantidade" => (int)$i["quantity"],
                "subtotal" => round(($promoPreco ?? $preco) * $i["quantity"], 2),
                "notas" => $i["notes"]
            ];
        }, $itens),
        "subtotal" => round($subtotal, 2),
        "taxa_entrega" => round($taxa_entrega, 2),
        "total" => round($subtotal + $taxa_entrega, 2)
    ]);

} catch (Exception $e) {
    error_log("[carrinho/listar] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
