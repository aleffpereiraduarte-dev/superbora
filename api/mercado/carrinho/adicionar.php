<?php
/**
 * POST /api/mercado/carrinho/adicionar.php
 * Body: { "session_id": "sess_xxx", "partner_id": 1, "product_id": 10, "quantity": 2 }
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

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
    $quantity = max(1, (int)($input["quantity"] ?? 1));
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

    // Verificar se produto existe E pertence ao parceiro informado
    if ($partner_id > 0) {
        $stmtProd = $db->prepare("SELECT product_id, name, price, image, quantity AS stock FROM om_market_products WHERE product_id = ? AND partner_id = ?");
        $stmtProd->execute([$product_id, $partner_id]);
    } else {
        $stmtProd = $db->prepare("SELECT product_id, name, price, image, quantity AS stock FROM om_market_products WHERE product_id = ?");
        $stmtProd->execute([$product_id]);
    }
    $produto = $stmtProd->fetch();
    if (!$produto) response(false, null, "Produto não encontrado", 404);

    // Build WHERE clause — authenticated users use customer_id only, anonymous use session_id
    if ($customer_id > 0) {
        $whereClause = "customer_id = ?";
        $whereParams = [$customer_id];
    } else {
        $whereClause = "session_id = ?";
        $whereParams = [$session_id];
    }

    $setQuantity = !empty($input["set_quantity"]);

    // Use transaction to prevent race condition on check-then-insert
    $db->beginTransaction();
    try {
        // Lock existing row if present (SELECT FOR UPDATE)
        $stmtExiste = $db->prepare("SELECT cart_id, quantity FROM om_market_cart WHERE {$whereClause} AND product_id = ? FOR UPDATE");
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
            $stmtUpd = $db->prepare("UPDATE om_market_cart SET quantity = ?, price = ? WHERE cart_id = ?");
            $stmtUpd->execute([$nova_qtd, $produto["price"], $existe["cart_id"]]);
            $msg = "Quantidade atualizada";
        } else {
            // Verificar se carrinho tem produtos de outro parceiro
            $stmtOutro = $db->prepare("SELECT partner_id FROM om_market_cart WHERE {$whereClause} AND partner_id != ? LIMIT 1");
            $stmtOutro->execute([...$whereParams, $partner_id]);
            $outro = $stmtOutro->fetch();

            if ($outro) {
                $multistop = (int)($input["multistop_route"] ?? 0);
                if (!$multistop) {
                    $db->rollBack();
                    response(false, null, "Você já tem produtos de outro mercado no carrinho. Finalize ou limpe o carrinho primeiro.", 400);
                }
                // Multi-stop route: permite adicionar de loja diferente
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

    // Retornar carrinho atualizado
    $stmtCart = $db->prepare("SELECT c.cart_id, c.product_id, c.partner_id, c.quantity, c.price, p.name, p.image FROM om_market_cart c INNER JOIN om_market_products p ON c.product_id = p.product_id WHERE {$whereClause}");
    $stmtCart->execute($whereParams);
    $carrinho = $stmtCart->fetchAll();

    $total = array_sum(array_map(fn($i) => $i["price"] * $i["quantity"], $carrinho));

    response(true, [
        "itens" => count($carrinho),
        "total" => round($total, 2),
        "carrinho" => $carrinho
    ], $msg);

} catch (Exception $e) {
    error_log("[carrinho/adicionar] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
