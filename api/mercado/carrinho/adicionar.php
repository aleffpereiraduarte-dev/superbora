<?php
/**
 * POST /api/mercado/carrinho/adicionar.php
 * Body: { "session_id": "sess_xxx", "partner_id": 1, "product_id": 10, "quantity": 2 }
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once __DIR__ . "/../helpers/cache.php";

try {
    $input = getInput();
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
        // Auth is optional for cart (anonymous sessions allowed)
    }

    $session_id = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($input["session_id"] ?? ''));
    $customer_id = $authCustomerId; // SECURITY: never trust client-supplied customer_id
    $partner_id = (int)($input["partner_id"] ?? 0);
    $product_id = (int)($input["product_id"] ?? 0);
    $rawQuantity = (int)($input["quantity"] ?? 1);
    if ($rawQuantity <= 0) {
        response(false, null, "Quantidade deve ser maior que zero", 400);
    }
    if ($rawQuantity > 99) {
        response(false, null, "Quantidade maxima por item e 99 unidades", 400);
    }
    $quantity = $rawQuantity;
    $notes = mb_substr(trim($input["notes"] ?? ""), 0, 500);

    if (!$partner_id || !$product_id) {
        response(false, null, "partner_id e product_id obrigatórios", 400);
    }

    if (!$session_id && !$customer_id) {
        response(false, null, "session_id ou customer_id obrigatório", 400);
    }

    // SECURITY: Validate session_id format for anonymous carts (must be UUID-like)
    if (!$customer_id && $session_id) {
        if (!preg_match('/^[a-f0-9]{8}-?[a-f0-9]{4}-?[a-f0-9]{4}-?[a-f0-9]{4}-?[a-f0-9]{12}$/i', $session_id)
            && !preg_match('/^sess_[a-zA-Z0-9_-]{20,60}$/', $session_id)) {
            response(false, null, "session_id invalido", 400);
        }
    }

    // Verificar se produto existe E pertence ao parceiro informado.
    // Cache Redis 5min em "product:cart:{id}" — invalidado por partner/product-save.
    // Produtos mudam pouco (admin edita 1-2x por dia no max). Reduz 1 query por
    // adicionar.php em ~95% dos casos (P50 de ~0.4ms DB -> ~0.1ms Redis hit).
    $prodCacheKey = "product:cart:{$product_id}";
    $produto = cacheGet($prodCacheKey);
    if ($produto !== null && $partner_id > 0 && (int)($produto['partner_id'] ?? 0) !== $partner_id) {
        // Cache hit but wrong partner — fall through to DB (rare: cross-partner scan attempt).
        $produto = null;
    }
    if ($produto === null) {
        if ($partner_id > 0) {
            $stmtProd = $db->prepare("SELECT product_id, partner_id, name, price, image, quantity AS stock FROM om_market_products WHERE product_id = ? AND partner_id = ?");
            $stmtProd->execute([$product_id, $partner_id]);
        } else {
            $stmtProd = $db->prepare("SELECT product_id, partner_id, name, price, image, quantity AS stock FROM om_market_products WHERE product_id = ?");
            $stmtProd->execute([$product_id]);
        }
        $produto = $stmtProd->fetch(PDO::FETCH_ASSOC);
        if ($produto) {
            // Cache apenas os campos usados downstream (price, stock). Nao cacheia
            // o objeto inteiro pra evitar blow-up de memoria se alguem adicionar
            // campos pesados no SELECT.
            cacheSet($prodCacheKey, [
                'product_id' => (int)$produto['product_id'],
                'partner_id' => (int)$produto['partner_id'],
                'name' => $produto['name'],
                'price' => $produto['price'],
                'image' => $produto['image'],
                'stock' => $produto['stock'],
            ], 300);
        }
    }
    if (!$produto) response(false, null, "Produto não encontrado", 404);

    // Verificar se a loja está aberta antes de adicionar ao carrinho
    $stmtPartner = $db->prepare("SELECT is_open, pause_until FROM om_market_partners WHERE partner_id = ? AND status::text = '1'");
    $stmtPartner->execute([$partner_id]);
    $partnerData = $stmtPartner->fetch();
    if (!$partnerData) {
        response(false, null, "Estabelecimento não encontrado", 404);
    }
    $lojaFechada = (int)($partnerData['is_open'] ?? 0) !== 1;
    $lojaPausada = !empty($partnerData['pause_until']) && strtotime($partnerData['pause_until']) > time();
    if ($lojaFechada || $lojaPausada) {
        response(false, null, "Esta loja está fechada no momento", 400);
    }

    // Build WHERE clause — authenticated users use customer_id only, anonymous use session_id
    // Prefix with table alias "c" because JOINed tables also have customer_id
    if ($customer_id > 0) {
        $whereClause = "c.customer_id = ?";
        $whereParams = [$customer_id];
    } else {
        $whereClause = "c.session_id = ?";
        $whereParams = [$session_id];
    }

    $setQuantity = !empty($input["set_quantity"]);

    // Use transaction to prevent race condition on check-then-insert
    $db->beginTransaction();
    try {
        // Lock existing row if present (SELECT FOR UPDATE)
        $stmtExiste = $db->prepare("SELECT cart_id, quantity FROM om_market_cart c WHERE {$whereClause} AND product_id = ? FOR UPDATE");
        $stmtExiste->execute([...$whereParams, $product_id]);
        $existe = $stmtExiste->fetch();

        // Validar estoque disponível
        if ($produto['stock'] !== null) {
            $existingQty = $existe ? (int)$existe['quantity'] : 0;
            $requestedTotal = $setQuantity ? $quantity : ($existingQty + $quantity);
            if ($requestedTotal > (int)$produto['stock']) {
                $db->rollBack();
                response(false, null, "Estoque insuficiente", 400);
            }
        }

        if ($existe) {
            $nova_qtd = $setQuantity ? $quantity : ($existe["quantity"] + $quantity);
            if ($nova_qtd > 99) {
                $db->rollBack();
                response(false, null, "Quantidade maxima por item e 99 unidades", 400);
            }
            $stmtUpd = $db->prepare("UPDATE om_market_cart SET quantity = ?, price = ? WHERE cart_id = ?");
            $stmtUpd->execute([$nova_qtd, $produto["price"], $existe["cart_id"]]);
            $msg = "Quantidade atualizada";
        } else {
            // Verificar se carrinho tem produtos de outro parceiro
            $stmtOutro = $db->prepare("SELECT DISTINCT c.partner_id, p.name AS partner_name
                                       FROM om_market_cart c
                                       LEFT JOIN om_market_partners p ON p.partner_id = c.partner_id
                                       WHERE {$whereClause} AND c.partner_id != ?");
            $stmtOutro->execute([...$whereParams, $partner_id]);
            $outras = $stmtOutro->fetchAll();

            if (!empty($outras)) {
                $multistop = (int)($input["multistop_route"] ?? 0);
                $allowMulti = (int)($input["allow_multi_partner"] ?? 0);
                if (!$multistop && !$allowMulti) {
                    $db->rollBack();
                    // Return structured 409 so mobile can show "deseja adicionar mesmo assim?" modal
                    response(false, [
                        'has_other_partners' => true,
                        'other_partners' => array_map(function($o) {
                            return [
                                'partner_id' => (int)$o['partner_id'],
                                'partner_name' => $o['partner_name'],
                            ];
                        }, $outras),
                        'hint' => 'Reenvie com allow_multi_partner=1 para confirmar carrinho com varias lojas',
                    ], "Voce ja tem produtos de outras lojas no carrinho", 409);
                }
                // Multi-stop route OR explicit multi-partner consent: allow
            }

            // Inserir novo item (race condition handled by FOR UPDATE lock above)
            $stmt = $db->prepare("
                INSERT INTO om_market_cart (session_id, customer_id, partner_id, product_id, quantity, price, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$session_id, $customer_id, $partner_id, $product_id, $quantity, $produto["price"], $notes]);
            $msg = "Produto adicionado";
        }

        $db->commit();
    } catch (Exception $txEx) {
        $db->rollBack();
        throw $txEx;
    }

    // Invalidate listar.php cache before returning — next listar rebuilds fresh.
    cacheInvalidateCart($customer_id, $session_id);

    // Retornar carrinho atualizado
    $stmtCart = $db->prepare("SELECT c.cart_id, c.product_id, c.partner_id, c.quantity, c.price, p.name, p.image FROM om_market_cart c INNER JOIN om_market_products p ON c.product_id = p.product_id WHERE {$whereClause}");
    $stmtCart->execute($whereParams);
    $carrinho = $stmtCart->fetchAll();

    $total = array_sum(array_map(fn($i) => $i["price"] * $i["quantity"], $carrinho));

    // Realtime: broadcast cart update so other open sessions refresh without pull-to-refresh.
    // Debounced (300ms window) to collapse rapid +/- taps into a single push.
    if ($customer_id > 0) {
        try {
            require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';
            wsBroadcastDebounced(
                "cart_bc:{$customer_id}",
                "user_{$customer_id}",
                'cart_updated',
                [
                    'count' => count($carrinho),
                    'total' => round($total, 2),
                    'action' => 'add',
                ],
                300
            );
        } catch (Throwable $e) { /* best effort */ }
    }

    response(true, [
        "itens" => count($carrinho),
        "total" => round($total, 2),
        "carrinho" => $carrinho
    ], $msg);

} catch (Exception $e) {
    error_log("[carrinho/adicionar] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
