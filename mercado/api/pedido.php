<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ONEMUNDO MERCADO - API DE PEDIDOS
 * ══════════════════════════════════════════════════════════════════════════════
 * Ações: create, status, add_item, driver_location
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit('{}');
}

session_name('OCSESSID');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Conexão usando env_loader
require_once dirname(__DIR__) . '/includes/env_loader.php';

try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'Erro de conexão']));
}

$customer_id = $_SESSION['customer_id'] ?? 0;
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? $_GET['action'] ?? '';

// Função de resposta
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Gerar número do pedido único
function generateOrderNumber($pdo) {
    $prefix = 'OM';
    $date = date('ymd');
    
    // Buscar último número do dia
    $stmt = $pdo->prepare("SELECT order_number FROM om_market_orders WHERE order_number LIKE ? ORDER BY order_id DESC LIMIT 1");
    $stmt->execute([$prefix . $date . '%']);
    $last = $stmt->fetchColumn();
    
    if ($last) {
        $seq = (int)substr($last, -4) + 1;
    } else {
        $seq = 1;
    }
    
    return $prefix . $date . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

switch ($action) {

// ══════════════════════════════════════════════════════════════════════════════
// CRIAR PEDIDO
// ══════════════════════════════════════════════════════════════════════════════
case 'create':
    if (!$customer_id) {
        jsonResponse(['success' => false, 'error' => 'Faça login para continuar'], 401);
    }
    
    // Dados do pedido
    $address_id = (int)($input['address_id'] ?? 0);
    $items = $input['items'] ?? [];
    $subtotal = (float)($input['subtotal'] ?? 0);
    $delivery_fee = (float)($input['delivery_fee'] ?? 0);
    $service_fee = (float)($input['service_fee'] ?? 0);
    $total = (float)($input['total'] ?? 0);
    $payment_method = $input['payment_method'] ?? 'pix';
    $payment_id = $input['payment_id'] ?? '';
    $notes = trim($input['notes'] ?? '');
    $delivery_type = $input['delivery_type'] ?? 'express';
    $delivery_date = $input['delivery_date'] ?? date('Y-m-d');
    $delivery_time_start = $input['delivery_time_start'] ?? null;
    $delivery_time_end = $input['delivery_time_end'] ?? null;
    $discount = (float)($input['discount'] ?? 0);

    // Dados de tipo de entrega
    $delivery_type_code = $input['delivery_type'] ?? 'standard';
    $is_pickup = (int)($input['is_pickup'] ?? 0);
    $tipo_entrega = ($input['tipo_entrega'] ?? null) === 'retirada' || $is_pickup ? 'retirada' : 'entrega';
    if ($tipo_entrega === 'retirada') $is_pickup = 1;
    $ponto_apoio_id = !empty($input['ponto_apoio_id']) ? (int)$input['ponto_apoio_id'] : null;
    
    // Validações
    if (empty($items)) {
        jsonResponse(['success' => false, 'error' => 'Carrinho vazio']);
    }
    
    if (!$address_id) {
        jsonResponse(['success' => false, 'error' => 'Selecione um endereço']);
    }
    
    if ($total < 1) {
        jsonResponse(['success' => false, 'error' => 'Valor inválido']);
    }
    
    // Buscar dados do cliente
    $stmt = $pdo->prepare("SELECT * FROM oc_customer WHERE customer_id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();
    
    if (!$customer) {
        jsonResponse(['success' => false, 'error' => 'Cliente não encontrado']);
    }
    
    // Buscar endereço
    $stmt = $pdo->prepare("
        SELECT a.*, z.code as zone_code, z.name as zone_name 
        FROM oc_address a 
        LEFT JOIN oc_zone z ON a.zone_id = z.zone_id 
        WHERE a.address_id = ? AND a.customer_id = ?
    ");
    $stmt->execute([$address_id, $customer_id]);
    $address = $stmt->fetch();
    
    if (!$address) {
        jsonResponse(['success' => false, 'error' => 'Endereço não encontrado']);
    }
    
    // Partner e Market ID
    $partner_id = (int)($items[0]['partner_id'] ?? 100);
    $market_id = $partner_id;
    
    // Gerar número do pedido
    $order_number = generateOrderNumber($pdo);
    
    // Código de verificação
    $verification_code = strtoupper(substr(md5(uniqid() . time()), 0, 6));
    
    // Nome completo do cliente
    $customer_name = trim(($customer['firstname'] ?? '') . ' ' . ($customer['lastname'] ?? ''));
    if (empty($customer_name)) $customer_name = 'Cliente';
    
    // Mapear método de pagamento
    $payment_map = [
        'card' => 'credit_card',
        'credit_card' => 'credit_card',
        'debit_card' => 'debit_card',
        'pix' => 'pix',
        'cash' => 'cash'
    ];
    $db_payment_method = $payment_map[$payment_method] ?? 'pix';
    
    // Extrair número do endereço
    $shipping_address = $address['address_1'] ?? 'Endereço';
    $shipping_number = '';
    if (preg_match('/[,\s]+(\d+)\s*$/', $shipping_address, $m)) {
        $shipping_number = $m[1];
        $shipping_address = trim(preg_replace('/[,\s]+\d+\s*$/', '', $shipping_address));
    }
    
    // É agendado?
    $is_scheduled = ($delivery_type === 'scheduled') ? 1 : 0;
    
    try {
        $pdo->beginTransaction();
        
        // Buscar nome do ponto de apoio se houver
        $ponto_apoio_nome = null;
        if ($ponto_apoio_id) {
            $stmtPonto = $pdo->prepare("SELECT store_name FROM oc_purpletree_vendor_stores WHERE seller_id = ?");
            $stmtPonto->execute([$ponto_apoio_id]);
            $ponto_apoio_nome = $stmtPonto->fetchColumn();
        }

        // Inserir pedido
        $stmt = $pdo->prepare("
            INSERT INTO om_market_orders (
                order_number, customer_id, partner_id, market_id,
                customer_name, customer_email, customer_phone,
                shipping_address, shipping_number, shipping_complement,
                shipping_neighborhood, shipping_city, shipping_state, shipping_cep,
                subtotal, delivery_fee, delivery_type_code, is_pickup, tipo_entrega, ponto_apoio_id, ponto_apoio_nome,
                service_fee, discount, total,
                payment_method, payment_status, payment_id,
                status, notes, verification_code,
                scheduled_date, scheduled_time_start, scheduled_time_end, is_scheduled,
                items_count, date_added, created_at
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, 'paid', ?,
                'confirmed', ?, ?,
                ?, ?, ?, ?,
                ?, NOW(), NOW()
            )
        ");

        $stmt->execute([
            $order_number, $customer_id, $partner_id, $market_id,
            $customer_name, $customer['email'] ?? '', $customer['telephone'] ?? '',
            $shipping_address, $shipping_number, $address['address_2'] ?? '',
            '', $address['city'] ?? '', $address['zone_code'] ?? 'MG', preg_replace('/\D/', '', $address['postcode'] ?? ''),
            $subtotal, $delivery_fee, $delivery_type_code, $is_pickup, $tipo_entrega, $ponto_apoio_id, $ponto_apoio_nome,
            $service_fee, $discount, $total,
            $db_payment_method, $payment_id,
            $notes, $verification_code,
            $delivery_date, $delivery_time_start, $delivery_time_end, $is_scheduled,
            count($items)
        ]);
        
        $order_id = $pdo->lastInsertId();
        
        // Inserir itens
        $stmt = $pdo->prepare("
            INSERT INTO om_market_order_items (
                order_id, product_id, product_name, product_image, unit, price, quantity, total_price
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($items as $item) {
            $item_qty = (int)($item['qty'] ?? $item['quantity'] ?? 1);
            $item_price = (float)($item['price'] ?? 0);
            $item_total = $item_price * $item_qty;
            
            $stmt->execute([
                $order_id,
                (int)($item['id'] ?? $item['product_id'] ?? 0),
                $item['name'] ?? 'Produto',
                $item['image'] ?? '',
                $item['unit'] ?? 'un',
                $item_price,
                $item_qty,
                $item_total
            ]);
        }
        
        $pdo->commit();
        
        // ══════════════════════════════════════════════════════════════════════════
        // DISPARAR MATCHING ENGINE - Criar ofertas para shoppers
        // ══════════════════════════════════════════════════════════════════════════
        try {
            // Atualizar status do pedido para iniciar matching
            $pdo->prepare("UPDATE om_market_orders SET matching_status = 'searching', matching_started_at = NOW() WHERE order_id = ?")->execute([$order_id]);
            
            // Buscar shoppers online do parceiro
            $stmt = $pdo->prepare("
                SELECT shopper_id, name, push_token, device_token
                FROM om_market_shoppers 
                WHERE (
                    is_online = 1 
                    OR online = 1 
                    OR is_available = 1 
                    OR disponivel = 1
                    OR status = '1'
                )
                AND (is_busy = 0 OR is_busy IS NULL)
                AND (partner_id = ? OR partner_id IS NULL OR partner_id = 0)
                ORDER BY rating DESC, total_orders DESC
                LIMIT 10
            ");
            $stmt->execute([$partner_id]);
            $shoppers = $stmt->fetchAll();
            
            // Se não encontrou nenhum online, pega qualquer ativo
            if (empty($shoppers)) {
                $stmt = $pdo->prepare("
                    SELECT shopper_id, name, push_token, device_token
                    FROM om_market_shoppers 
                    WHERE status = '1' OR is_available = 1
                    ORDER BY rating DESC
                    LIMIT 10
                ");
                $stmt->execute();
                $shoppers = $stmt->fetchAll();
            }
            
            if (!empty($shoppers)) {
                // Criar ofertas para cada shopper (wave 1)
                $stmtOffer = $pdo->prepare("
                    INSERT INTO om_shopper_offers 
                    (order_id, worker_id, partner_id, order_total, status, current_wave, expires_at, created_at)
                    VALUES (?, ?, ?, ?, 'pending', 1, DATE_ADD(NOW(), INTERVAL 60 SECOND), NOW())
                ");
                
                foreach ($shoppers as $shopper) {
                    $shopper_id = $shopper['shopper_id'] ?? $shopper['worker_id'] ?? $shopper['id'];
                    $stmtOffer->execute([$order_id, $shopper_id, $partner_id, $total]);
                }
                
                // Atualizar wave do pedido
                $pdo->prepare("UPDATE om_market_orders SET matching_wave = 1 WHERE order_id = ?")->execute([$order_id]);
                
                error_log("MATCHING: Criadas " . count($shoppers) . " ofertas para pedido #$order_id");
            } else {
                error_log("MATCHING: Nenhum shopper online para pedido #$order_id");
            }
            
            // Registrar na timeline
            $pdo->prepare("
                INSERT INTO om_order_timeline (order_id, status, description, created_at)
                VALUES (?, 'confirmed', 'Pedido confirmado e enviado para shoppers', NOW())
            ")->execute([$order_id]);
            
        } catch (Exception $matchError) {
            error_log("Erro no matching: " . $matchError->getMessage());
            // Não falha o pedido se o matching der erro
        }
        
        // Limpar carrinho
        unset($_SESSION['market_cart']);
        unset($_SESSION['market_coupon']);
        $_SESSION['last_order_id'] = $order_id;
        
        jsonResponse([
            'success' => true,
            'order_id' => $order_id,
            'order_number' => $order_number,
            'verification_code' => $verification_code,
            'message' => 'Pedido criado com sucesso!'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Erro ao criar pedido: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Erro ao criar pedido: ' . $e->getMessage()], 500);
    }
    break;

// ══════════════════════════════════════════════════════════════════════════════
// VERIFICAR STATUS DO PEDIDO
// ══════════════════════════════════════════════════════════════════════════════
case 'status':
    $order_id = (int)($_GET['order_id'] ?? $input['order_id'] ?? 0);
    
    if (!$order_id) {
        jsonResponse(['success' => false, 'error' => 'ID do pedido não informado']);
    }
    
    $stmt = $pdo->prepare("SELECT status, shopper_id, driver_id FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
    
    if (!$order) {
        jsonResponse(['success' => false, 'error' => 'Pedido não encontrado']);
    }
    
    jsonResponse([
        'success' => true,
        'status' => $order['status'],
        'shopper_id' => $order['shopper_id'],
        'driver_id' => $order['driver_id']
    ]);
    break;

// ══════════════════════════════════════════════════════════════════════════════
// LOCALIZAÇÃO DO DRIVER
// ══════════════════════════════════════════════════════════════════════════════
case 'driver_location':
    $order_id = (int)($_GET['order_id'] ?? $input['order_id'] ?? 0);
    
    if (!$order_id) {
        jsonResponse(['success' => false, 'error' => 'ID do pedido não informado']);
    }
    
    $stmt = $pdo->prepare("SELECT delivery_lat, delivery_lng FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
    
    if (!$order) {
        jsonResponse(['success' => false, 'error' => 'Pedido não encontrado']);
    }
    
    jsonResponse([
        'success' => true,
        'lat' => $order['delivery_lat'],
        'lng' => $order['delivery_lng']
    ]);
    break;

// ══════════════════════════════════════════════════════════════════════════════
// ADICIONAR ITEM AO PEDIDO
// ══════════════════════════════════════════════════════════════════════════════
case 'add_item':
    if (!$customer_id) {
        jsonResponse(['success' => false, 'error' => 'Faça login'], 401);
    }
    
    $order_id = (int)($input['order_id'] ?? 0);
    $product_id = (int)($input['product_id'] ?? 0);
    $quantity = max(1, (int)($input['quantity'] ?? 1));
    
    if (!$order_id || !$product_id) {
        jsonResponse(['success' => false, 'error' => 'Dados inválidos']);
    }
    
    // Buscar pedido
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
    $stmt->execute([$order_id, $customer_id]);
    $order = $stmt->fetch();
    
    if (!$order) {
        jsonResponse(['success' => false, 'error' => 'Pedido não encontrado']);
    }
    
    // Verificar se pode adicionar (desabilita quando shopper já está escaneando)
    $scan_progress = (float)($order['scan_progress'] ?? $order['progress_pct'] ?? 0);
    
    if (!in_array($order['status'], ['confirmed', 'shopping']) || $scan_progress >= 30) {
        jsonResponse(['success' => false, 'error' => 'Não é possível adicionar itens - shopper já está escaneando os produtos']);
    }
    
    // Buscar produto
    $stmt = $pdo->prepare("
        SELECT pb.*, COALESCE(ps.sale_price, pp.price) as price
        FROM om_market_products_base pb
        JOIN om_market_products_price pp ON pb.product_id = pp.product_id
        LEFT JOIN om_market_products_sale ps ON pb.product_id = ps.product_id AND pp.partner_id = ps.partner_id
        WHERE pb.product_id = ? AND pp.partner_id = ?
    ");
    $stmt->execute([$product_id, $order['partner_id']]);
    $product = $stmt->fetch();
    
    if (!$product) {
        jsonResponse(['success' => false, 'error' => 'Produto não encontrado']);
    }
    
    $item_total = $product['price'] * $quantity;
    
    try {
        $pdo->beginTransaction();
        
        // Inserir item
        $stmt = $pdo->prepare("
            INSERT INTO om_market_order_items (order_id, product_id, product_name, product_image, unit, price, quantity, total_price)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $order_id,
            $product_id,
            $product['name'],
            $product['image'],
            $product['unit'],
            $product['price'],
            $quantity,
            $item_total
        ]);
        
        // Atualizar totais do pedido
        $new_total = $order['total'] + $item_total;
        $new_subtotal = $order['subtotal'] + $item_total;
        
        $stmt = $pdo->prepare("UPDATE om_market_orders SET subtotal = ?, total = ? WHERE order_id = ?");
        $stmt->execute([$new_subtotal, $new_total, $order_id]);
        
        $pdo->commit();
        
        jsonResponse([
            'success' => true,
            'message' => 'Item adicionado!',
            'new_total' => $new_total
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => 'Erro ao adicionar item'], 500);
    }
    break;

// ══════════════════════════════════════════════════════════════════════════════
// VERIFICAR SE PODE ADICIONAR ITENS
// ══════════════════════════════════════════════════════════════════════════════
case 'can_add_items':
    $order_id = (int)($_GET['order_id'] ?? $input['order_id'] ?? 0);
    
    if (!$order_id) {
        jsonResponse(['success' => false, 'error' => 'ID do pedido não informado']);
    }
    
    $stmt = $pdo->prepare("SELECT status, scan_progress, progress_pct FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
    
    if (!$order) {
        jsonResponse(['success' => false, 'error' => 'Pedido não encontrado']);
    }
    
    $scan_progress = (float)($order['scan_progress'] ?? $order['progress_pct'] ?? 0);
    $can_add = in_array($order['status'], ['confirmed', 'shopping']) && $scan_progress < 30;
    
    jsonResponse([
        'success' => true,
        'can_add' => $can_add,
        'scan_progress' => $scan_progress,
        'status' => $order['status'],
        'reason' => !$can_add ? 'Shopper já está escaneando os produtos (>30%)' : null
    ]);
    break;

// ══════════════════════════════════════════════════════════════════════════════
// TESTE
// ══════════════════════════════════════════════════════════════════════════════
case 'test':
    jsonResponse([
        'success' => true,
        'message' => 'API de Pedidos funcionando',
        'customer_id' => $customer_id,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    break;

// ══════════════════════════════════════════════════════════════════════════════
// AÇÃO DESCONHECIDA
// ══════════════════════════════════════════════════════════════════════════════
default:
    jsonResponse(['success' => false, 'error' => "Ação desconhecida: $action"], 400);
}
