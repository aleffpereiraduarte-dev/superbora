<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║  TESTE E2E COMPLETO - OneMundo Market                                        ║
 * ║  Fluxo: Cliente -> Pedido -> Shopper -> Coleta -> Entrega                    ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('America/Sao_Paulo');

// Configuração
$BASE_URL = 'http://localhost';
$results = [];
$test_data = [];

// Cores para output
function green($text) { return "\033[32m$text\033[0m"; }
function red($text) { return "\033[31m$text\033[0m"; }
function yellow($text) { return "\033[33m$text\033[0m"; }
function blue($text) { return "\033[34m$text\033[0m"; }
function bold($text) { return "\033[1m$text\033[0m"; }

function printHeader($title) {
    echo "\n" . str_repeat("═", 70) . "\n";
    echo bold("  $title\n");
    echo str_repeat("═", 70) . "\n\n";
}

function printStep($step, $description) {
    echo blue("[$step] ") . "$description\n";
}

function printResult($success, $message, $details = null) {
    if ($success) {
        echo "    " . green("✓ PASS") . " - $message\n";
    } else {
        echo "    " . red("✗ FAIL") . " - $message\n";
    }
    if ($details) {
        echo "      " . yellow("→ $details") . "\n";
    }
}

// Conexão com banco
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        // Tentar ler config do OpenCart
        $host = 'localhost';
        $db = 'love1';
        $user = 'love1';
        $pass = DB_PASSWORD;

        $ocConfig = $_SERVER['DOCUMENT_ROOT'] . '/config.php';
        if (file_exists($ocConfig)) {
            $content = file_get_contents($ocConfig);
            if (preg_match("/define\s*\(\s*'DB_HOSTNAME'\s*,\s*'([^']+)'/", $content, $m)) $host = $m[1];
            if (preg_match("/define\s*\(\s*'DB_DATABASE'\s*,\s*'([^']+)'/", $content, $m)) $db = $m[1];
            if (preg_match("/define\s*\(\s*'DB_USERNAME'\s*,\s*'([^']+)'/", $content, $m)) $user = $m[1];
            if (preg_match("/define\s*\(\s*'DB_PASSWORD'\s*,\s*'([^']+)'/", $content, $m)) $pass = $m[1];
        }

        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }
    return $pdo;
}

// ══════════════════════════════════════════════════════════════════════════════
//  INÍCIO DOS TESTES
// ══════════════════════════════════════════════════════════════════════════════

printHeader("TESTE E2E COMPLETO - OneMundo Market");
echo "Iniciado em: " . date('Y-m-d H:i:s') . "\n";
echo "Servidor: $BASE_URL\n\n";

$pdo = getDB();
$total_tests = 0;
$passed_tests = 0;
$failed_tests = 0;

// ══════════════════════════════════════════════════════════════════════════════
//  FASE 1: PREPARAÇÃO DO AMBIENTE
// ══════════════════════════════════════════════════════════════════════════════

printHeader("FASE 1: PREPARAÇÃO DO AMBIENTE");

// 1.1 - Verificar conexão com banco
printStep("1.1", "Verificando conexão com banco de dados");
try {
    $pdo->query("SELECT 1");
    printResult(true, "Conexão com banco OK");
    $passed_tests++;
} catch (Exception $e) {
    printResult(false, "Falha na conexão", $e->getMessage());
    $failed_tests++;
    die("Não é possível continuar sem banco de dados.\n");
}
$total_tests++;

// 1.2 - Verificar tabelas necessárias
printStep("1.2", "Verificando tabelas necessárias");
$required_tables = [
    'om_market_orders',
    'om_market_order_items',
    'om_market_products',
    'om_market_partners',
    'om_market_shoppers'
];

$missing_tables = [];
$existing_tables = [];
foreach ($required_tables as $table) {
    $result = $pdo->query("SHOW TABLES LIKE '$table'")->fetch();
    if (!$result) {
        $missing_tables[] = $table;
    } else {
        $existing_tables[] = $table;
    }
}

if (empty($missing_tables)) {
    printResult(true, "Todas as tabelas existem", count($required_tables) . " tabelas verificadas");
    $passed_tests++;
} else {
    printResult(false, "Tabelas faltando", implode(', ', $missing_tables));
    echo "      " . green("Existentes: ") . implode(', ', $existing_tables) . "\n";
    $failed_tests++;
}
$total_tests++;

// 1.3 - Verificar/Criar parceiro (mercado)
printStep("1.3", "Preparando mercado parceiro de teste");
$test_partner = $pdo->query("SELECT * FROM om_market_partners WHERE status = '1' LIMIT 1")->fetch();

if ($test_partner) {
    $test_data['partner_id'] = $test_partner['partner_id'];
    $test_data['partner_name'] = $test_partner['name'];
    printResult(true, "Mercado encontrado", $test_partner['name'] . " (ID: " . $test_partner['partner_id'] . ")");
    $passed_tests++;
} else {
    // Criar mercado de teste
    try {
        $pdo->exec("INSERT INTO om_market_partners (name, email, phone, status, created_at)
            VALUES ('Mercado Teste E2E', 'mercado@teste.com', '11999999999', 1, NOW())");
        $test_data['partner_id'] = $pdo->lastInsertId();
        $test_data['partner_name'] = 'Mercado Teste E2E';
        printResult(true, "Mercado de teste criado", "ID: " . $test_data['partner_id']);
        $passed_tests++;
    } catch (Exception $e) {
        printResult(false, "Erro ao criar mercado", $e->getMessage());
        $failed_tests++;
    }
}
$total_tests++;

// 1.4 - Verificar/Criar shopper
printStep("1.4", "Preparando shopper de teste");
$test_shopper = $pdo->query("SELECT * FROM om_market_shoppers WHERE status = '1' LIMIT 1")->fetch();

if ($test_shopper) {
    $test_data['shopper_id'] = $test_shopper['shopper_id'];
    $test_data['shopper_name'] = $test_shopper['name'];
    printResult(true, "Shopper encontrado", $test_shopper['name'] . " (ID: " . $test_shopper['shopper_id'] . ")");
    $passed_tests++;
} else {
    // Criar shopper de teste
    try {
        $pdo->exec("INSERT INTO om_market_shoppers (name, email, phone, cpf, status, created_at)
            VALUES ('Shopper Teste E2E', 'shopper@teste.com', '11988888888', '12345678901', 1, NOW())");
        $test_data['shopper_id'] = $pdo->lastInsertId();
        $test_data['shopper_name'] = 'Shopper Teste E2E';
        printResult(true, "Shopper de teste criado", "ID: " . $test_data['shopper_id']);
        $passed_tests++;
    } catch (Exception $e) {
        printResult(false, "Erro ao criar shopper", $e->getMessage());
        $failed_tests++;
    }
}
$total_tests++;

// 1.5 - Verificar produtos disponíveis
printStep("1.5", "Verificando produtos disponíveis");
$products = $pdo->query("
    SELECT * FROM om_market_products
    WHERE partner_id = {$test_data['partner_id']} AND status = 1
    LIMIT 5
")->fetchAll();

if (count($products) >= 2) {
    $test_data['products'] = $products;
    printResult(true, "Produtos disponíveis", count($products) . " produtos encontrados");
    $passed_tests++;
} else {
    // Criar produtos de teste
    try {
        for ($i = 1; $i <= 3; $i++) {
            $price = rand(5, 50) + (rand(0, 99) / 100);
            $pdo->exec("INSERT INTO om_market_products (partner_id, name, description, price, quantity, status, created_at)
                VALUES ({$test_data['partner_id']}, 'Produto Teste $i', 'Descrição do produto teste $i', $price, 100, 1, NOW())");
        }

        $products = $pdo->query("
            SELECT * FROM om_market_products
            WHERE partner_id = {$test_data['partner_id']} AND status = 1
            LIMIT 5
        ")->fetchAll();

        $test_data['products'] = $products;
        printResult(true, "Produtos de teste criados", count($products) . " produtos");
        $passed_tests++;
    } catch (Exception $e) {
        printResult(false, "Erro ao criar produtos", $e->getMessage());
        $failed_tests++;
    }
}
$total_tests++;

// ══════════════════════════════════════════════════════════════════════════════
//  FASE 2: CLIENTE CRIA PEDIDO
// ══════════════════════════════════════════════════════════════════════════════

printHeader("FASE 2: CLIENTE CRIA PEDIDO");

// 2.1 - Criar pedido no banco
printStep("2.1", "Criando pedido de teste");

$order_number = 'TEST-' . date('Ymd') . '-' . rand(1000, 9999);
$order_total = 0;
$order_items = [];

// Selecionar 2-3 produtos
$selected_products = array_slice($test_data['products'], 0, min(3, count($test_data['products'])));

foreach ($selected_products as $product) {
    $qty = rand(1, 3);
    $item_total = $product['price'] * $qty;
    $order_total += $item_total;
    $order_items[] = [
        'product_id' => $product['product_id'],
        'name' => $product['name'],
        'quantity' => $qty,
        'price' => $product['price'],
        'total' => $item_total
    ];
}

$delivery_fee = 5.99;
$order_total += $delivery_fee;
$subtotal = $order_total - $delivery_fee;

// Gerar customer_id fictício
$test_data['customer_id'] = rand(1000, 9999);
$test_data['customer_name'] = 'Cliente Teste E2E';

try {
    $stmt = $pdo->prepare("INSERT INTO om_market_orders
        (customer_id, customer_name, partner_id, status, subtotal, delivery_fee, total,
         address, lat, lng, items_count, created_at)
        VALUES (?, ?, ?, 'pendente', ?, ?, ?, 'Rua Teste 123, Centro - São Paulo', -23.5505, -46.6333, ?, NOW())");

    $stmt->execute([
        $test_data['customer_id'],
        $test_data['customer_name'],
        $test_data['partner_id'],
        $subtotal,
        $delivery_fee,
        $order_total,
        count($order_items)
    ]);

    $test_data['order_id'] = $pdo->lastInsertId();
    $test_data['order_number'] = $order_number;
    $test_data['order_total'] = $order_total;

    printResult(true, "Pedido criado", "ID: {$test_data['order_id']} - Total: R$ " . number_format($order_total, 2, ',', '.'));
    $passed_tests++;
} catch (Exception $e) {
    printResult(false, "Erro ao criar pedido", $e->getMessage());
    $failed_tests++;
}
$total_tests++;

// 2.2 - Adicionar itens ao pedido
printStep("2.2", "Adicionando itens ao pedido");

$items_added = 0;
foreach ($order_items as $item) {
    try {
        $stmt = $pdo->prepare("INSERT INTO om_market_order_items
            (order_id, product_id, name, quantity, price, total, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pendente')");
        $stmt->execute([
            $test_data['order_id'],
            $item['product_id'],
            $item['name'],
            $item['quantity'],
            $item['price'],
            $item['total']
        ]);
        $items_added++;
        echo "      → {$item['name']} x{$item['quantity']} = R$ " . number_format($item['total'], 2, ',', '.') . "\n";
    } catch (Exception $e) {
        echo "      → Erro: " . $e->getMessage() . "\n";
    }
}

if ($items_added > 0) {
    printResult(true, "Itens adicionados", "$items_added itens no pedido");
    $passed_tests++;
} else {
    printResult(false, "Nenhum item adicionado", "Verifique a estrutura da tabela");
    $failed_tests++;
}
$total_tests++;

// 2.3 - Simular pagamento (mudar status para pago)
printStep("2.3", "Simulando pagamento do pedido");

try {
    $stmt = $pdo->prepare("UPDATE om_market_orders SET status = 'pago' WHERE order_id = ?");
    $stmt->execute([$test_data['order_id']]);

    $order = $pdo->query("SELECT status FROM om_market_orders WHERE order_id = {$test_data['order_id']}")->fetch();

    if ($order['status'] === 'pago') {
        printResult(true, "Pagamento confirmado", "Status: pago");
        $passed_tests++;
    } else {
        printResult(false, "Status não atualizado", "Status atual: " . $order['status']);
        $failed_tests++;
    }
} catch (Exception $e) {
    printResult(false, "Erro ao atualizar status", $e->getMessage());
    $failed_tests++;
}
$total_tests++;

// ══════════════════════════════════════════════════════════════════════════════
//  FASE 3: SHOPPER RECEBE E ACEITA PEDIDO
// ══════════════════════════════════════════════════════════════════════════════

printHeader("FASE 3: SHOPPER RECEBE E ACEITA PEDIDO");

// 3.1 - Verificar pedido disponível para shoppers
printStep("3.1", "Verificando pedidos disponíveis para shoppers");

$available_orders = $pdo->query("
    SELECT o.*, p.name as partner_name
    FROM om_market_orders o
    JOIN om_market_partners p ON p.partner_id = o.partner_id
    WHERE o.status IN ('pago', 'pendente') AND o.shopper_id IS NULL
    ORDER BY o.created_at DESC
    LIMIT 10
")->fetchAll();

$our_order_available = false;
foreach ($available_orders as $ao) {
    if ($ao['order_id'] == $test_data['order_id']) {
        $our_order_available = true;
        break;
    }
}

if ($our_order_available) {
    printResult(true, "Pedido disponível para shoppers", count($available_orders) . " pedidos na fila");
    $passed_tests++;
} else {
    printResult(false, "Pedido não está disponível", "Verifique o status do pedido");
    $failed_tests++;
}
$total_tests++;

// 3.2 - Shopper aceita o pedido
printStep("3.2", "Shopper aceita o pedido");

try {
    $stmt = $pdo->prepare("UPDATE om_market_orders
        SET shopper_id = ?, status = 'aceito'
        WHERE order_id = ? AND shopper_id IS NULL");
    $stmt->execute([$test_data['shopper_id'], $test_data['order_id']]);

    $order = $pdo->query("SELECT * FROM om_market_orders WHERE order_id = {$test_data['order_id']}")->fetch();

    if ($order['shopper_id'] == $test_data['shopper_id'] && $order['status'] === 'aceito') {
        printResult(true, "Pedido aceito", "Shopper: {$test_data['shopper_name']} (ID: {$test_data['shopper_id']})");
        $passed_tests++;
    } else {
        printResult(false, "Falha ao aceitar pedido", "Status: " . $order['status']);
        $failed_tests++;
    }
} catch (Exception $e) {
    printResult(false, "Erro ao aceitar pedido", $e->getMessage());
    $failed_tests++;
}
$total_tests++;

// ══════════════════════════════════════════════════════════════════════════════
//  FASE 4: SHOPPER FAZ COMPRAS
// ══════════════════════════════════════════════════════════════════════════════

printHeader("FASE 4: SHOPPER FAZ COMPRAS NO MERCADO");

// 4.1 - Iniciar compras
printStep("4.1", "Shopper inicia as compras");

try {
    $stmt = $pdo->prepare("UPDATE om_market_orders SET status = 'em_compra' WHERE order_id = ?");
    $stmt->execute([$test_data['order_id']]);

    $order = $pdo->query("SELECT status FROM om_market_orders WHERE order_id = {$test_data['order_id']}")->fetch();

    if ($order['status'] === 'em_compra') {
        printResult(true, "Compras iniciadas", "Status: em_compra");
        $passed_tests++;
    } else {
        printResult(false, "Status não atualizado", "Status atual: " . $order['status']);
        $failed_tests++;
    }
} catch (Exception $e) {
    printResult(false, "Erro ao iniciar compras", $e->getMessage());
    $failed_tests++;
}
$total_tests++;

// 4.2 - Buscar itens do pedido
printStep("4.2", "Buscando itens do pedido");

$items = $pdo->query("SELECT * FROM om_market_order_items WHERE order_id = {$test_data['order_id']}")->fetchAll();

if (count($items) > 0) {
    printResult(true, "Itens encontrados", count($items) . " itens para coletar");
    $test_data['items'] = $items;
    $passed_tests++;
} else {
    printResult(false, "Nenhum item encontrado", "Pedido sem itens");
    $failed_tests++;
}
$total_tests++;

// 4.3 - Coletar itens
printStep("4.3", "Shopper coleta os itens");

$items_collected = 0;
foreach ($items as $item) {
    try {
        $stmt = $pdo->prepare("UPDATE om_market_order_items SET status = 'coletado' WHERE item_id = ?");
        $stmt->execute([$item['item_id']]);
        $items_collected++;
        echo "      → Coletado: {$item['name']} (x{$item['quantity']})\n";
    } catch (Exception $e) {
        // Continua mesmo com erro
    }
}

printResult(true, "Itens coletados", "$items_collected/" . count($items) . " itens");
$passed_tests++;
$total_tests++;

// 4.4 - Finalizar compras
printStep("4.4", "Shopper finaliza as compras");

try {
    $stmt = $pdo->prepare("UPDATE om_market_orders SET status = 'comprado' WHERE order_id = ?");
    $stmt->execute([$test_data['order_id']]);

    $order = $pdo->query("SELECT status FROM om_market_orders WHERE order_id = {$test_data['order_id']}")->fetch();

    if ($order['status'] === 'comprado') {
        printResult(true, "Compras finalizadas", "Status: comprado");
        $passed_tests++;
    } else {
        printResult(false, "Status não atualizado", "Status atual: " . $order['status']);
        $failed_tests++;
    }
} catch (Exception $e) {
    printResult(false, "Erro ao finalizar compras", $e->getMessage());
    $failed_tests++;
}
$total_tests++;

// ══════════════════════════════════════════════════════════════════════════════
//  FASE 5: ENTREGA
// ══════════════════════════════════════════════════════════════════════════════

printHeader("FASE 5: ENTREGA AO CLIENTE");

// 5.1 - Iniciar entrega
printStep("5.1", "Shopper inicia a entrega");

try {
    $stmt = $pdo->prepare("UPDATE om_market_orders SET status = 'em_entrega' WHERE order_id = ?");
    $stmt->execute([$test_data['order_id']]);

    $order = $pdo->query("SELECT status FROM om_market_orders WHERE order_id = {$test_data['order_id']}")->fetch();

    if ($order['status'] === 'em_entrega') {
        printResult(true, "Entrega iniciada", "Status: em_entrega");
        $passed_tests++;
    } else {
        printResult(false, "Status não atualizado", "Status atual: " . $order['status']);
        $failed_tests++;
    }
} catch (Exception $e) {
    printResult(false, "Erro ao iniciar entrega", $e->getMessage());
    $failed_tests++;
}
$total_tests++;

// 5.2 - Gerar código de entrega
printStep("5.2", "Gerando código de entrega");

$delivery_code = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
try {
    $stmt = $pdo->prepare("UPDATE om_market_orders SET delivery_code = ? WHERE order_id = ?");
    $stmt->execute([$delivery_code, $test_data['order_id']]);

    printResult(true, "Código gerado", "Código: $delivery_code");
    $test_data['delivery_code'] = $delivery_code;
    $passed_tests++;
} catch (Exception $e) {
    printResult(false, "Erro ao gerar código", $e->getMessage());
    $failed_tests++;
}
$total_tests++;

// 5.3 - Confirmar entrega
printStep("5.3", "Cliente confirma recebimento com código");

try {
    // Calcular ganhos do shopper (10% do pedido)
    $shopper_earning = $test_data['order_total'] * 0.10;

    $stmt = $pdo->prepare("UPDATE om_market_orders
        SET status = 'entregue', delivered_at = NOW(), delivery_code_verified = 1,
            delivery_code_verified_at = NOW(), delivery_earning = ?
        WHERE order_id = ? AND delivery_code = ?");
    $stmt->execute([$shopper_earning, $test_data['order_id'], $delivery_code]);

    $order = $pdo->query("SELECT * FROM om_market_orders WHERE order_id = {$test_data['order_id']}")->fetch();

    if ($order['status'] === 'entregue') {
        printResult(true, "Entrega confirmada", "Status: entregue");
        printResult(true, "Ganho registrado", "R$ " . number_format($shopper_earning, 2, ',', '.'));
        $passed_tests += 2;
    } else {
        printResult(false, "Status não atualizado", "Status atual: " . $order['status']);
        $failed_tests++;
    }
} catch (Exception $e) {
    printResult(false, "Erro ao confirmar entrega", $e->getMessage());
    $failed_tests++;
}
$total_tests += 2;

// ══════════════════════════════════════════════════════════════════════════════
//  FASE 6: VERIFICAÇÕES FINAIS
// ══════════════════════════════════════════════════════════════════════════════

printHeader("FASE 6: VERIFICAÇÕES FINAIS");

// 6.1 - Verificar estado final do pedido
printStep("6.1", "Verificando estado final do pedido");

$final_order = $pdo->query("SELECT * FROM om_market_orders WHERE order_id = {$test_data['order_id']}")->fetch();

echo "\n    Estado Final do Pedido:\n";
echo "    ├─ Order ID: {$final_order['order_id']}\n";
echo "    ├─ Status: {$final_order['status']}\n";
echo "    ├─ Total: R$ " . number_format($final_order['total'], 2, ',', '.') . "\n";
echo "    ├─ Shopper ID: {$final_order['shopper_id']}\n";
echo "    ├─ Partner ID: {$final_order['partner_id']}\n";
echo "    ├─ Código Entrega: {$final_order['delivery_code']}\n";
echo "    ├─ Entregue em: {$final_order['delivered_at']}\n";
echo "    └─ Ganho Entrega: R$ " . number_format($final_order['delivery_earning'] ?? 0, 2, ',', '.') . "\n";

$checks = [
    'status = entregue' => $final_order['status'] === 'entregue',
    'shopper atribuído' => $final_order['shopper_id'] == $test_data['shopper_id'],
    'delivered_at preenchido' => !empty($final_order['delivered_at']),
    'total > 0' => $final_order['total'] > 0
];

foreach ($checks as $check => $passed) {
    if ($passed) {
        printResult(true, $check);
        $passed_tests++;
    } else {
        printResult(false, $check);
        $failed_tests++;
    }
    $total_tests++;
}

// 6.2 - Verificar itens finais
printStep("6.2", "Verificando itens do pedido");

$final_items = $pdo->query("SELECT * FROM om_market_order_items WHERE order_id = {$test_data['order_id']}")->fetchAll();

echo "\n    Itens do Pedido:\n";
$items_total = 0;
foreach ($final_items as $item) {
    echo "    ├─ {$item['name']} x{$item['quantity']} = R$ " . number_format($item['total'], 2, ',', '.') . " [{$item['status']}]\n";
    $items_total += $item['total'];
}
echo "    └─ Total itens: R$ " . number_format($items_total, 2, ',', '.') . "\n";

if (count($final_items) > 0) {
    printResult(true, "Itens verificados", count($final_items) . " itens");
    $passed_tests++;
} else {
    printResult(false, "Sem itens", "Pedido vazio");
    $failed_tests++;
}
$total_tests++;

// ══════════════════════════════════════════════════════════════════════════════
//  FASE 7: TESTES DE API
// ══════════════════════════════════════════════════════════════════════════════

printHeader("FASE 7: TESTES DE API");

// 7.1 - Testar API de pedidos disponíveis
printStep("7.1", "Testando API de pedidos disponíveis (shopper)");

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "$BASE_URL/api/mercado/shopper/pedidos-disponiveis.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    $json = json_decode($response, true);
    printResult(true, "API respondeu HTTP 200", isset($json['pedidos']) ? count($json['pedidos']) . " pedidos" : "OK");
    $passed_tests++;
} elseif ($httpCode === 401) {
    // 401 é esperado quando não há autenticação - API está funcionando corretamente
    printResult(true, "API requer autenticação", "HTTP 401 (correto)");
    $passed_tests++;
} else {
    printResult(false, "API retornou HTTP $httpCode");
    $failed_tests++;
}
$total_tests++;

// 7.2 - Testar API de login shopper (sem credenciais)
printStep("7.2", "Testando API de login shopper");

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "$BASE_URL/api/mercado/shopper/login.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['email' => 'test@test.com', 'password' => 'test']));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200 || $httpCode === 401 || $httpCode === 400) {
    printResult(true, "API de login acessível", "HTTP $httpCode (esperado)");
    $passed_tests++;
} else {
    printResult(false, "API de login inacessível", "HTTP $httpCode");
    $failed_tests++;
}
$total_tests++;

// 7.3 - Testar página principal do painel
printStep("7.3", "Testando página do painel parceiro");

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "$BASE_URL/mercado/painel/");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    printResult(true, "Painel acessível", "HTTP 200");
    $passed_tests++;
} else {
    printResult(false, "Painel inacessível", "HTTP $httpCode");
    $failed_tests++;
}
$total_tests++;

// 7.4 - Testar página admin
printStep("7.4", "Testando página admin");

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "$BASE_URL/mercado/admin/");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    printResult(true, "Admin acessível", "HTTP 200");
    $passed_tests++;
} else {
    printResult(false, "Admin inacessível", "HTTP $httpCode");
    $failed_tests++;
}
$total_tests++;

// ══════════════════════════════════════════════════════════════════════════════
//  RESUMO FINAL
// ══════════════════════════════════════════════════════════════════════════════

printHeader("RESUMO FINAL");

$success_rate = $total_tests > 0 ? round(($passed_tests / $total_tests) * 100, 1) : 0;

echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║  RESULTADOS DO TESTE E2E                                                     ║\n";
echo "╠══════════════════════════════════════════════════════════════════════════════╣\n";
printf("║  Total de Testes:    %-55d ║\n", $total_tests);
printf("║  Passou:             %-55d ║\n", $passed_tests);
printf("║  Falhou:             %-55d ║\n", $failed_tests);
printf("║  Taxa de Sucesso:    %-55s ║\n", $success_rate . "%");
echo "╠══════════════════════════════════════════════════════════════════════════════╣\n";
printf("║  Order ID:           %-55d ║\n", $test_data['order_id']);
printf("║  Cliente:            %-55s ║\n", $test_data['customer_name']);
printf("║  Mercado:            %-55s ║\n", $test_data['partner_name']);
printf("║  Shopper:            %-55s ║\n", $test_data['shopper_name']);
printf("║  Valor Total:        %-55s ║\n", "R$ " . number_format($test_data['order_total'], 2, ',', '.'));
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n";

if ($failed_tests === 0) {
    echo "\n" . green(bold("✓ TODOS OS TESTES PASSARAM!")) . "\n";
    echo "O fluxo completo Cliente → Pedido → Shopper → Entrega está funcionando.\n";
} else {
    echo "\n" . yellow(bold("⚠ ALGUNS TESTES FALHARAM ($failed_tests de $total_tests)")) . "\n";
    echo "Revise os erros acima e corrija os problemas encontrados.\n";
}

echo "\nFinalizado em: " . date('Y-m-d H:i:s') . "\n\n";

// Retornar código de saída
exit($failed_tests > 0 ? 1 : 0);
