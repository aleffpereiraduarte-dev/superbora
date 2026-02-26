<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * POST /api/mercado/shopper/aceitar-pedido.php
 * Shopper aceita um pedido para fazer compras e/ou entrega
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REQUER: Autenticação de Shopper APROVADO pelo RH
 * Header: Authorization: Bearer <token>
 *
 * Body: { "order_id": 10 }
 *
 * SEGURANÇA:
 * - ✅ Autenticação obrigatória
 * - ✅ Verificação de aprovação RH
 * - ✅ Transação ACID com locks (evita race condition)
 * - ✅ Validação de transição de estado
 * - ✅ Prepared statements (sem SQL injection)
 */

require_once __DIR__ . "/../config/auth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/PushNotification.php";

try {
    $input = getInput();
    $db = getDB();

    // ═══════════════════════════════════════════════════════════════════
    // AUTENTICAÇÃO - Shopper precisa estar aprovado pelo RH
    // ═══════════════════════════════════════════════════════════════════
    $auth = requireShopperAuth();
    $shopper_id = $auth['uid'];

    // Verificar se shopper está aprovado
    if (!om_auth()->isShopperApproved($shopper_id)) {
        response(false, null, "Seu cadastro ainda não foi aprovado pelo RH. Aguarde a análise.", 403);
    }

    $order_id = (int)($input["order_id"] ?? 0);

    if (!$order_id) {
        response(false, null, "order_id é obrigatório", 400);
    }

    // ═══════════════════════════════════════════════════════════════════
    // TRANSAÇÃO COM LOCKS
    // ═══════════════════════════════════════════════════════════════════
    $db->beginTransaction();

    try {
        // Verificar pedido (com lock para evitar race condition)
        $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ? FOR UPDATE");
        $stmt->execute([$order_id]);
        $pedido = $stmt->fetch();

        if (!$pedido) {
            $db->rollBack();
            response(false, null, "Pedido não encontrado", 404);
        }

        // Validar transição de estado
        try {
            om_status()->validateOrderTransition($pedido["status"], 'aceito');
        } catch (InvalidArgumentException $e) {
            $db->rollBack();
            error_log("[aceitar-pedido] Transicao invalida: " . $e->getMessage());
            response(false, null, 'Operacao nao permitida para o status atual do pedido', 409);
        }

        if ($pedido["shopper_id"]) {
            $db->rollBack();
            response(false, null, "Pedido já foi aceito por outro shopper", 409);
        }

        // Verificar shopper (com lock)
        $stmt = $db->prepare("SELECT * FROM om_market_shoppers WHERE shopper_id = ? FOR UPDATE");
        $stmt->execute([$shopper_id]);
        $shopper = $stmt->fetch();

        if (!$shopper) {
            $db->rollBack();
            response(false, null, "Shopper não encontrado", 404);
        }

        if (!$shopper["disponivel"]) {
            $db->rollBack();
            response(false, null, "Você já está em outro pedido. Finalize-o primeiro.", 400);
        }

        // Aceitar pedido (usando prepared statement)
        $stmt = $db->prepare("
            UPDATE om_market_orders SET
                shopper_id = ?,
                status = 'aceito',
                accepted_at = NOW()
            WHERE order_id = ? AND shopper_id IS NULL AND status = 'pendente'
        ");
        $stmt->execute([$shopper_id, $order_id]);

        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            response(false, null, "Pedido não disponível (já foi aceito por outro shopper)", 409);
        }

        // Marcar shopper como ocupado
        $stmt = $db->prepare("
            UPDATE om_market_shoppers SET
                disponivel = 0,
                pedido_atual_id = ?
            WHERE shopper_id = ?
        ");
        $stmt->execute([$order_id, $shopper_id]);

        // Commit da transação
        $db->commit();

        // Log de auditoria
        logAudit('update', 'order', $order_id,
            ['status' => 'pendente', 'shopper_id' => null],
            ['status' => 'aceito', 'shopper_id' => $shopper_id],
            "Pedido aceito por shopper #$shopper_id"
        );

        // ═══════════════════════════════════════════════════════════════════
        // NOTIFICACOES EM TEMPO REAL (SSE)
        // ═══════════════════════════════════════════════════════════════════
        if (function_exists('om_realtime')) {
            om_realtime()->pedidoAceito(
                $order_id,
                (int)$pedido['partner_id'],
                (int)$pedido['customer_id'],
                $shopper_id,
                $shopper['name'] ?? "Shopper #$shopper_id"
            );
        }

        // ═══════════════════════════════════════════════════════════════════
        // PUSH NOTIFICATIONS (FCM)
        // ═══════════════════════════════════════════════════════════════════
        try {
            PushNotification::getInstance()->setDb($db);
            om_push()->notifyOrderAccepted(
                $order_id,
                (int)$pedido['customer_id'],
                (int)$pedido['partner_id'],
                $shopper_id
            );
        } catch (Exception $pushError) {
            // Log but don't fail the request
            error_log("[aceitar-pedido] Push notification error: " . $pushError->getMessage());
        }

        // Buscar itens do pedido (query de leitura, fora da transação)
        $stmt = $db->prepare("
            SELECT i.*, p.name, p.image, p.barcode
            FROM om_market_order_items i
            INNER JOIN om_market_products p ON i.product_id = p.product_id
            WHERE i.order_id = ?
        ");
        $stmt->execute([$order_id]);
        $itens = $stmt->fetchAll();

        // Buscar parceiro
        $stmt = $db->prepare("SELECT * FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$pedido["partner_id"]]);
        $parceiro = $stmt->fetch();

        response(true, [
            "order_id" => $order_id,
            "status" => "aceito",
            "parceiro" => [
                "id" => $parceiro["partner_id"],
                "nome" => $parceiro["name"],
                "endereco" => $parceiro["address"],
                "telefone" => $parceiro["phone"] ?? null,
                "logo" => $parceiro["logo"]
            ],
            "endereco_entrega" => $pedido["delivery_address"],
            "codigo_entrega" => $pedido["codigo_entrega"],
            "itens" => array_map(function($item) {
                return [
                    "id" => $item["id"],
                    "product_id" => $item["product_id"],
                    "nome" => $item["name"],
                    "quantidade" => $item["quantity"],
                    "preco" => floatval($item["price"]),
                    "imagem" => $item["image"],
                    "barcode" => $item["barcode"]
                ];
            }, $itens)
        ], "Pedido aceito! Vá até o mercado para iniciar a coleta.");

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("[aceitar-pedido] Erro: " . $e->getMessage());
    response(false, null, "Erro ao aceitar pedido. Tente novamente.", 500);
}
