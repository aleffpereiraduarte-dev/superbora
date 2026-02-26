<?php
/**
 * POST /api/mercado/pedido/criar.php
 * Cria pedido do mercado
 *
 * CORRIGIDO:
 * - SQL Injection fixed com prepared statements
 * - Validação de entrada
 * - Rate limiting
 * - Verificação de estoque
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";
require_once dirname(__DIR__, 3) . "/includes/classes/PusherService.php";
require_once __DIR__ . "/../helpers/notify.php";
setCorsHeaders();

// Rate limiting: 10 pedidos por minuto
if (!RateLimiter::check(10, 60)) {
    exit;
}

try {
    $input = getInput();
    $db = getDB();

    // Authenticate: customer_id MUST come from JWT, never from request body
    $customer_id = requireCustomerAuth();
    $session_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $input["session_id"] ?? session_id());
    $enderecoRaw = $input["endereco"] ?? "";
    if (is_array($enderecoRaw)) {
        $enderecoRaw = implode(", ", array_filter(array_map('strval', array_values($enderecoRaw))));
    }
    $endereco = trim(substr((string)$enderecoRaw, 0, 500));
    $latitude = isset($input["latitude"]) ? floatval($input["latitude"]) : null;
    $longitude = isset($input["longitude"]) ? floatval($input["longitude"]) : null;
    $forma_pagamento = preg_replace('/[^a-z_]/', '', $input["forma_pagamento"] ?? "pix");
    $observacoes = trim(substr($input["observacoes"] ?? "", 0, 1000));

    // Validar forma de pagamento
    $formasPermitidas = ['pix', 'credito', 'debito', 'dinheiro'];
    if (!in_array($forma_pagamento, $formasPermitidas)) {
        response(false, null, "Forma de pagamento inválida", 400);
    }

    // Validar endereço
    if (empty($endereco)) {
        response(false, null, "Endereço de entrega é obrigatório", 400);
    }

    // Buscar carrinho do cliente autenticado (SECURITY: use only customer_id, not session_id OR)
    $stmt = $db->prepare("SELECT c.*, p.name, p.quantity as estoque FROM om_market_cart c
                          INNER JOIN om_market_products p ON c.product_id = p.product_id
                          WHERE c.customer_id = ?");
    $stmt->execute([$customer_id]);
    $itens = $stmt->fetchAll();

    if (empty($itens)) {
        response(false, null, "Carrinho vazio", 400);
    }

    // Verificar estoque de todos os itens
    $errosEstoque = [];
    foreach ($itens as $item) {
        if ($item["quantity"] > $item["estoque"]) {
            $errosEstoque[] = "'{$item['name']}' - estoque insuficiente (disponível: {$item['estoque']})";
        }
    }

    if (!empty($errosEstoque)) {
        response(false, [
            "erros_estoque" => $errosEstoque
        ], "Alguns itens estão sem estoque: " . $errosEstoque[0], 400);
    }

    $partner_id = (int)$itens[0]["partner_id"];

    // Dados do cliente
    $customer_name = trim(substr($input["nome"] ?? $input["customer_name"] ?? "Cliente", 0, 200));
    $customer_phone = preg_replace('/[^0-9+]/', '', $input["telefone"] ?? $input["customer_phone"] ?? "");
    $customer_email = filter_var($input["email"] ?? $input["customer_email"] ?? "", FILTER_SANITIZE_EMAIL);
    $shipping_city = trim(substr($input["cidade"] ?? $input["shipping_city"] ?? "", 0, 100));
    $shipping_state = trim(substr($input["estado"] ?? $input["shipping_state"] ?? "", 0, 2));
    $shipping_cep = preg_replace('/[^0-9]/', '', $input["cep"] ?? $input["shipping_cep"] ?? "");

    // Buscar parceiro (prepared statement)
    $stmt = $db->prepare("SELECT * FROM om_market_partners WHERE partner_id = ? AND status = '1'");
    $stmt->execute([$partner_id]);
    $parceiro = $stmt->fetch();

    if (!$parceiro) {
        response(false, null, "Mercado não disponível", 400);
    }

    // Verify store is open
    if (!$parceiro['is_open']) {
        // Check if pause expired
        if ($parceiro['pause_until'] && strtotime($parceiro['pause_until']) < time()) {
            $db->prepare("UPDATE om_market_partners SET is_open = 1, pause_until = NULL WHERE partner_id = ?")->execute([$partner_id]);
        } else {
            response(false, null, "Esta loja esta temporariamente fechada. Tente novamente mais tarde.", 400);
        }
    }

    // Gerar order_number temporario (sera atualizado apos INSERT com order_id)
    $order_number_temp = 'SB-' . strtoupper(bin2hex(random_bytes(4)));
    $market_id = (int)($parceiro["market_id"] ?? $parceiro["partner_id"] ?? 0);

    // Calcular valores
    $subtotal = array_sum(array_map(fn($i) => $i["price"] * $i["quantity"], $itens));
    $taxa_entrega = floatval($parceiro["delivery_fee"] ?? 0);
    $total = $subtotal + $taxa_entrega;

    // Validar pedido mínimo
    $pedidoMinimo = floatval($parceiro["min_order_value"] ?? 0);
    if ($subtotal < $pedidoMinimo) {
        response(false, null, "Pedido mínimo: R$ " . number_format($pedidoMinimo, 2, ',', '.'), 400);
    }

    // Gerar código de entrega seguro (6 caracteres alfanuméricos)
    $codigo_entrega = strtoupper(bin2hex(random_bytes(3)));

    // Iniciar transação
    $db->beginTransaction();

    try {
        // Timer: 5 minutos para parceiro aceitar
        $timer_started = date('Y-m-d H:i:s');
        $timer_expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        // Categoria do parceiro
        $partner_categoria = $parceiro['categoria'] ?? 'mercado';

        // Itens com opcoes adicionais - recalcular total
        $itens_input = $input['itens'] ?? [];
        $opcoes_extra_total = 0;

        // Pre-calcular extras das opcoes
        if (!empty($itens_input)) {
            foreach ($itens_input as $item_input) {
                $opcoes = $item_input['opcoes'] ?? [];
                foreach ($opcoes as $opcao) {
                    $opcao_id = (int)($opcao['option_id'] ?? 0);
                    if ($opcao_id) {
                        $stmtOpt = $db->prepare("SELECT price_extra FROM om_product_options WHERE id = ? AND available::text = '1'");
                        $stmtOpt->execute([$opcao_id]);
                        $opt = $stmtOpt->fetch();
                        if ($opt) {
                            $qty = (int)($item_input['quantity'] ?? 1);
                            $opcoes_extra_total += floatval($opt['price_extra']) * $qty;
                        }
                    }
                }
            }
        }

        $total = $subtotal + $opcoes_extra_total + $taxa_entrega;

        // Criar pedido (prepared statement)
        $stmt = $db->prepare("INSERT INTO om_market_orders (
                    order_number, partner_id, market_id, customer_id,
                    customer_name, customer_phone, customer_email,
                    status, subtotal, delivery_fee, total,
                    delivery_address, shipping_address, shipping_city, shipping_state, shipping_cep,
                    shipping_lat, shipping_lng,
                    notes, codigo_entrega, forma_pagamento,
                    timer_started, timer_expires, partner_categoria, date_added
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pendente', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                RETURNING order_id");

        $stmt->execute([
            $order_number_temp, $partner_id, $market_id, $customer_id,
            $customer_name, $customer_phone, $customer_email,
            $subtotal + $opcoes_extra_total, $taxa_entrega, $total,
            $endereco, $endereco, $shipping_city, $shipping_state, $shipping_cep,
            $latitude, $longitude, $observacoes, $codigo_entrega, $forma_pagamento,
            $timer_started, $timer_expires, $partner_categoria
        ]);

        $order_id = (int)$stmt->fetchColumn();

        // Gerar order_number bonito com o order_id: SB00025
        $order_number = 'SB' . str_pad($order_id, 5, '0', STR_PAD_LEFT);
        $db->prepare("UPDATE om_market_orders SET order_number = ? WHERE order_id = ?")->execute([$order_number, $order_id]);

        // Criar itens do pedido (prepared statement) com observacao
        $stmtItem = $db->prepare("INSERT INTO om_market_order_items (order_id, product_id, name, quantity, price, total, observacao)
                                   VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING item_id");

        // Mapear opcoes dos itens pelo product_id
        $itensOpcoesMap = [];
        foreach ($itens_input as $ii) {
            $pid = (int)($ii['product_id'] ?? 0);
            if ($pid) $itensOpcoesMap[$pid] = $ii;
        }

        $stmtInsertOpcao = $db->prepare("INSERT INTO om_order_item_options (order_item_id, option_id, option_group_name, option_name, price_extra) VALUES (?, ?, ?, ?, ?)");

        foreach ($itens as $item) {
            $itemTotal = $item["price"] * $item["quantity"];

            // Buscar observacao e opcoes deste item
            $itemInput = $itensOpcoesMap[$item["product_id"]] ?? [];
            $observacao_item = trim(substr($itemInput['observacao'] ?? '', 0, 1000)) ?: null;

            $stmtItem->execute([
                $order_id,
                $item["product_id"],
                $item["name"],
                $item["quantity"],
                $item["price"],
                $itemTotal,
                $observacao_item
            ]);

            $item_id = (int)$stmtItem->fetchColumn();

            // Inserir opcoes selecionadas para este item
            $opcoes = $itemInput['opcoes'] ?? [];
            foreach ($opcoes as $opcao) {
                $opcao_id = (int)($opcao['option_id'] ?? 0);
                if (!$opcao_id) continue;

                // Buscar dados da opcao e grupo
                $stmtOpt = $db->prepare("
                    SELECT o.id, o.name as option_name, o.price_extra,
                           g.name as group_name
                    FROM om_product_options o
                    INNER JOIN om_product_option_groups g ON o.group_id = g.id
                    WHERE o.id = ? AND o.available::text = '1'
                ");
                $stmtOpt->execute([$opcao_id]);
                $opt = $stmtOpt->fetch();

                if ($opt) {
                    $stmtInsertOpcao->execute([
                        $item_id,
                        $opt['id'],
                        $opt['group_name'],
                        $opt['option_name'],
                        $opt['price_extra']
                    ]);
                }
            }

            // Decrementar estoque com guarded decrement (race-safe)
            $stmtEstoque = $db->prepare("UPDATE om_market_products SET quantity = quantity - ? WHERE product_id = ? AND quantity >= ?");
            $stmtEstoque->execute([$item["quantity"], $item["product_id"], $item["quantity"]]);
            if ($stmtEstoque->rowCount() === 0) {
                throw new Exception("Estoque insuficiente para '{$item['name']}' durante finalização do pedido");
            }
        }

        // Limpar carrinho (SECURITY: only by customer_id)
        $stmt = $db->prepare("DELETE FROM om_market_cart WHERE customer_id = ?");
        $stmt->execute([$customer_id]);

        // Commit transação
        $db->commit();

        // Notificar parceiro sobre novo pedido
        notifyPartner($db, $partner_id,
            'Novo pedido!',
            "Pedido #$order_number - R$ " . number_format($total, 2, ',', '.') . " - $customer_name",
            '/painel/mercado/pedidos.php'
        );

        // Pusher: notificar parceiro em tempo real sobre novo pedido
        try {
            PusherService::newOrder($partner_id, [
                'id' => $order_id,
                'order_number' => $order_number,
                'status' => 'pendente',
                'customer_name' => $customer_name,
                'total' => $total,
                'items_count' => count($itens)
            ]);
        } catch (Exception $pusherErr) {
            error_log("[pedido/criar] Pusher erro: " . $pusherErr->getMessage());
        }

        // Log do pedido
        error_log("Pedido criado: #$order_id | Cliente: $customer_id | Total: R$ " . number_format($total, 2));

        response(true, [
            "order_id" => $order_id,
            "codigo_entrega" => $codigo_entrega,
            "status" => "pendente",
            "subtotal" => round($subtotal, 2),
            "taxa_entrega" => round($taxa_entrega, 2),
            "total" => round($total, 2),
            "tempo_estimado" => (int)($parceiro["delivery_time_min"] ?? 60),
            "parceiro" => [
                "id" => $partner_id,
                "nome" => $parceiro["trade_name"] ?? $parceiro["name"]
            ],
            "mensagem" => "Pedido criado! Aguardando shopper aceitar."
        ], "Pedido criado com sucesso!");

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Pedido criar error: " . $e->getMessage());
    response(false, null, "Erro ao criar pedido. Tente novamente.", 500);
}
