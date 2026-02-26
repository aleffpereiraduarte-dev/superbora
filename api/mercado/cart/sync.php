<?php
/**
 * GET/POST /api/mercado/cart/sync.php
 * GET - retorna carrinho da sessao do cliente
 * POST - salva/sincroniza carrinho do React com backend
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Tentar obter customer_id do token (opcional)
    $customerId = null;
    $token = om_auth()->getTokenFromRequest();
    if ($token) {
        $payload = om_auth()->validateToken($token);
        if ($payload && $payload['type'] === 'customer') {
            $customerId = (int)$payload['uid'];
        }
    }

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        // Retornar carrinho do banco
        if (!$customerId) {
            response(true, ["items" => [], "partner_id" => null, "totals" => null]);
        }

        $stmt = $db->prepare("
            SELECT c.product_id, c.quantity, c.partner_id,
                   p.name, p.price, p.special_price, p.image, p.unit,
                   mp.trade_name as partner_name
            FROM om_market_cart c
            INNER JOIN om_market_products p ON c.product_id = p.product_id
            LEFT JOIN om_market_partners mp ON c.partner_id = mp.partner_id
            WHERE c.customer_id = ?
        ");
        $stmt->execute([$customerId]);
        $items = $stmt->fetchAll();

        $partnerId = null;
        $partnerName = '';
        $cartItems = [];
        $subtotal = 0;

        foreach ($items as $item) {
            $preco = ($item['special_price'] && (float)$item['special_price'] > 0 && (float)$item['special_price'] < (float)$item['price'])
                ? (float)$item['special_price'] : (float)$item['price'];
            $qty = (int)$item['quantity'];
            $subtotal += $preco * $qty;
            $partnerId = (int)$item['partner_id'];
            $partnerName = $item['partner_name'] ?? '';

            $cartItems[] = [
                "id" => (int)$item['product_id'],
                "nome" => $item['name'],
                "preco" => (float)$item['price'],
                "preco_promo" => $item['special_price'] ? (float)$item['special_price'] : null,
                "imagem" => $item['image'],
                "unidade" => $item['unit'] ?? 'un',
                "quantity" => $qty
            ];
        }

        $deliveryFee = $subtotal >= 99 ? 0 : 9.99;
        $serviceFee = 2.49;

        response(true, [
            "items" => $cartItems,
            "partner_id" => $partnerId,
            "partner_name" => $partnerName,
            "totals" => [
                "subtotal" => round($subtotal, 2),
                "delivery_fee" => $deliveryFee,
                "service_fee" => $serviceFee,
                "total" => round($subtotal + $deliveryFee + $serviceFee, 2)
            ]
        ]);
    }

    elseif ($method === 'POST') {
        $input = getInput();
        $items = $input['items'] ?? [];
        $partnerId = (int)($input['partner_id'] ?? 0);

        if (!$customerId) {
            // Sem login, apenas confirmar que recebeu
            response(true, ["synced" => false, "reason" => "not_authenticated"]);
        }

        // Transação para garantir atomicidade do sync
        $db->beginTransaction();
        try {
            // Limpar carrinho atual
            $stmt = $db->prepare("DELETE FROM om_market_cart WHERE customer_id = ?");
            $stmt->execute([$customerId]);

            // Inserir novos items
            if (!empty($items) && $partnerId) {
                $stmtInsert = $db->prepare("
                    INSERT INTO om_market_cart (customer_id, product_id, partner_id, quantity, created_at, updated_at)
                    VALUES (?, ?, ?, ?, NOW(), NOW())
                ");
                foreach ($items as $item) {
                    $productId = (int)($item['id'] ?? 0);
                    $qty = max(1, (int)($item['quantity'] ?? 1));
                    if ($productId) {
                        $stmtInsert->execute([$customerId, $productId, $partnerId, $qty]);
                    }
                }
            }

            $db->commit();
        } catch (Exception $txEx) {
            $db->rollBack();
            throw $txEx;
        }

        response(true, ["synced" => true]);
    }

    else {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[API Cart Sync] Erro: " . $e->getMessage());
    response(false, null, "Erro ao sincronizar carrinho", 500);
}
