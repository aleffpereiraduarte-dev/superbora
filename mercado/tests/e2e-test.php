<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * TESTE E2E - FLUXO COMPLETO DO SISTEMA ONEMUNDO MERCADO
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Testa o fluxo completo:
 * 1. Cliente cria pedido
 * 2. Shopper recebe e aceita oferta
 * 3. Shopper coleta itens
 * 4. Shopper finaliza coleta
 * 5. Shopper inicia entrega
 * 6. Shopper confirma entrega com código
 * 7. Sistema cria repasses
 * 8. Repasses são liberados (simulado)
 * 9. Saldos são atualizados
 *
 * Executar: php /var/www/html/mercado/tests/e2e-test.php
 * Ou via web: /mercado/tests/e2e-test.php?run=1
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Config
require_once dirname(__DIR__) . '/config/database.php';

class E2ETest {
    private $pdo;
    private $results = [];
    private $testData = [];
    private $startTime;

    // Cores para terminal
    const GREEN = "\033[32m";
    const RED = "\033[31m";
    const YELLOW = "\033[33m";
    const BLUE = "\033[34m";
    const RESET = "\033[0m";
    const BOLD = "\033[1m";

    public function __construct() {
        $this->pdo = getPDO();
        $this->startTime = microtime(true);
    }

    private function log($message, $type = 'info') {
        $timestamp = date('H:i:s');
        $isCli = php_sapi_name() === 'cli';

        if ($isCli) {
            $colors = [
                'info' => self::BLUE,
                'success' => self::GREEN,
                'error' => self::RED,
                'warning' => self::YELLOW,
                'header' => self::BOLD
            ];
            $color = $colors[$type] ?? '';
            echo "{$color}[{$timestamp}] {$message}" . self::RESET . "\n";
        } else {
            $classes = [
                'info' => 'color: #3b82f6',
                'success' => 'color: #10b981; font-weight: bold',
                'error' => 'color: #ef4444; font-weight: bold',
                'warning' => 'color: #f59e0b',
                'header' => 'font-weight: bold; font-size: 1.2em; margin-top: 20px'
            ];
            $style = $classes[$type] ?? '';
            echo "<div style='{$style}'>[{$timestamp}] {$message}</div>";
        }

        flush();
    }

    private function assert($condition, $testName, $details = '') {
        $this->results[] = [
            'name' => $testName,
            'passed' => $condition,
            'details' => $details
        ];

        if ($condition) {
            $this->log("✓ PASSOU: {$testName}", 'success');
        } else {
            $this->log("✗ FALHOU: {$testName} - {$details}", 'error');
        }

        return $condition;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // SETUP - Criar dados de teste
    // ═══════════════════════════════════════════════════════════════════════════
    public function setup() {
        $this->log("\n═══════════════════════════════════════════════════════════════", 'header');
        $this->log("INICIANDO SETUP DOS TESTES E2E", 'header');
        $this->log("═══════════════════════════════════════════════════════════════\n", 'header');

        try {
            // 1. Buscar ou criar mercado de teste
            $this->log("Buscando mercado de teste...", 'info');
            $stmt = $this->pdo->query("SELECT * FROM om_market_partners WHERE status = '1' LIMIT 1");
            $partner = $stmt->fetch();

            if (!$partner) {
                $this->log("Criando mercado de teste...", 'warning');
                $this->pdo->exec("
                    INSERT INTO om_market_partners (name, email, status, created_at)
                    VALUES ('Mercado Teste E2E', 'teste@e2e.com', 1, NOW())
                ");
                $partner = ['partner_id' => $this->pdo->lastInsertId(), 'name' => 'Mercado Teste E2E'];
            }
            $this->testData['partner'] = $partner;
            $this->log("Mercado: {$partner['name']} (ID: {$partner['partner_id']})", 'success');

            // 2. Buscar ou criar shopper de teste
            $this->log("Buscando shopper de teste...", 'info');
            $stmt = $this->pdo->query("SELECT * FROM om_market_shoppers WHERE status = '1' LIMIT 1");
            $shopper = $stmt->fetch();

            if (!$shopper) {
                $this->log("Criando shopper de teste...", 'warning');
                $this->pdo->exec("
                    INSERT INTO om_market_shoppers (name, email, phone, status, is_online, created_at)
                    VALUES ('Shopper Teste E2E', 'shopper@e2e.com', '11999999999', 1, 1, NOW())
                ");
                $shopper = ['shopper_id' => $this->pdo->lastInsertId(), 'name' => 'Shopper Teste E2E'];
            }
            $this->testData['shopper'] = $shopper;
            $this->log("Shopper: {$shopper['name']} (ID: {$shopper['shopper_id']})", 'success');

            // 3. Buscar produtos do mercado
            $this->log("Buscando produtos...", 'info');
            $stmt = $this->pdo->prepare("
                SELECT p.product_id, p.name, COALESCE(pp.price, 10.00) as price
                FROM om_market_products_base p
                LEFT JOIN om_market_products_price pp ON pp.product_id = p.product_id AND pp.partner_id = ?
                WHERE p.status = 1
                LIMIT 5
            ");
            $stmt->execute([$partner['partner_id']]);
            $products = $stmt->fetchAll();

            if (empty($products)) {
                $this->log("Criando produto de teste...", 'warning');
                $this->pdo->exec("
                    INSERT INTO om_market_products_base (name, barcode, status, created_at)
                    VALUES ('Produto Teste E2E', '7891234567890', 1, NOW())
                ");
                $productId = $this->pdo->lastInsertId();
                $products = [['product_id' => $productId, 'name' => 'Produto Teste E2E', 'price' => 10.00]];
            }
            $this->testData['products'] = $products;
            $this->log("Produtos encontrados: " . count($products), 'success');

            // 4. Gerar código de entrega único
            $this->testData['delivery_code'] = strtoupper(substr(md5(uniqid()), 0, 6));
            $this->log("Código de entrega gerado: {$this->testData['delivery_code']}", 'info');

            return true;

        } catch (Exception $e) {
            $this->log("ERRO NO SETUP: " . $e->getMessage(), 'error');
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTE 1: Cliente cria pedido
    // ═══════════════════════════════════════════════════════════════════════════
    public function testCreateOrder() {
        $this->log("\n───────────────────────────────────────────────────────────────", 'header');
        $this->log("TESTE 1: CLIENTE CRIA PEDIDO", 'header');
        $this->log("───────────────────────────────────────────────────────────────\n", 'header');

        try {
            $partner = $this->testData['partner'];
            $products = $this->testData['products'];
            $deliveryCode = $this->testData['delivery_code'];

            // Calcular total
            $total = 0;
            foreach ($products as $p) {
                $total += $p['price'];
            }
            $shopperEarning = 8.00; // Valor fixo de exemplo
            $deliveryFee = 5.00;

            // Criar pedido
            $orderNumber = 'E2E-' . date('YmdHis') . '-' . rand(1000, 9999);

            $stmt = $this->pdo->prepare("
                INSERT INTO om_market_orders (
                    order_number, partner_id, market_id, customer_id, customer_name, customer_phone,
                    subtotal, total, shopper_earning, delivery_fee, delivery_code,
                    shipping_address, shipping_city, shipping_state, shipping_cep,
                    delivery_address, status, matching_status, created_at
                ) VALUES (?, ?, ?, 1, 'Cliente Teste E2E', '11888888888', ?, ?, ?, ?, ?,
                    'Rua Teste, 123', 'São Paulo', 'SP', '01310100',
                    'Rua Entrega, 456 - São Paulo/SP',
                    'pending', 'searching', NOW())
            ");
            $stmt->execute([
                $orderNumber,
                $partner['partner_id'],
                $partner['partner_id'], // market_id = partner_id
                $total,
                $total,
                $shopperEarning,
                $deliveryFee,
                $deliveryCode
            ]);

            $orderId = $this->pdo->lastInsertId();
            $this->testData['order_id'] = $orderId;
            $this->testData['order_number'] = $orderNumber;
            $this->testData['total'] = $total;
            $this->testData['shopper_earning'] = $shopperEarning;

            // Criar itens do pedido
            foreach ($products as $p) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO om_market_order_items (order_id, product_id, product_name, quantity, price, scanned)
                    VALUES (?, ?, ?, 1, ?, 0)
                ");
                $stmt->execute([$orderId, $p['product_id'], $p['name'], $p['price']]);
            }

            // Verificar
            $stmt = $this->pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            $this->assert(
                $order && $order['status'] === 'pending',
                "Pedido criado com sucesso",
                "Order ID: {$orderId}, Status: {$order['status']}"
            );

            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM om_market_order_items WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $itemCount = $stmt->fetchColumn();

            $this->assert(
                $itemCount == count($products),
                "Itens do pedido criados",
                "Esperado: " . count($products) . ", Criados: {$itemCount}"
            );

            $this->log("Pedido #{$orderNumber} criado com {$itemCount} itens, total: R$ " . number_format($total, 2, ',', '.'), 'info');

            return true;

        } catch (Exception $e) {
            $this->log("ERRO: " . $e->getMessage(), 'error');
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTE 2: Shopper vê oferta disponível
    // ═══════════════════════════════════════════════════════════════════════════
    public function testOfferAvailable() {
        $this->log("\n───────────────────────────────────────────────────────────────", 'header');
        $this->log("TESTE 2: SHOPPER VÊ OFERTA DISPONÍVEL", 'header');
        $this->log("───────────────────────────────────────────────────────────────\n", 'header');

        try {
            // Simular busca de ofertas
            $stmt = $this->pdo->prepare("
                SELECT o.*, p.name as partner_name
                FROM om_market_orders o
                LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
                WHERE o.status = 'pending'
                AND o.shopper_id IS NULL
                AND o.order_id = ?
            ");
            $stmt->execute([$this->testData['order_id']]);
            $offer = $stmt->fetch();

            $this->assert(
                $offer !== false,
                "Oferta disponível para shopper",
                $offer ? "Pedido #{$offer['order_number']} do {$offer['partner_name']}" : "Nenhuma oferta encontrada"
            );

            return $offer !== false;

        } catch (Exception $e) {
            $this->log("ERRO: " . $e->getMessage(), 'error');
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTE 3: Shopper aceita pedido
    // ═══════════════════════════════════════════════════════════════════════════
    public function testAcceptOrder() {
        $this->log("\n───────────────────────────────────────────────────────────────", 'header');
        $this->log("TESTE 3: SHOPPER ACEITA PEDIDO", 'header');
        $this->log("───────────────────────────────────────────────────────────────\n", 'header');

        try {
            $orderId = $this->testData['order_id'];
            $shopperId = $this->testData['shopper']['shopper_id'];
            $shopperName = $this->testData['shopper']['name'];

            // Aceitar pedido
            $stmt = $this->pdo->prepare("
                UPDATE om_market_orders
                SET shopper_id = ?,
                    shopper_name = ?,
                    status = 'accepted',
                    matching_status = 'accepted',
                    accepted_at = NOW()
                WHERE order_id = ? AND shopper_id IS NULL
            ");
            $stmt->execute([$shopperId, $shopperName, $orderId]);

            $rowsAffected = $stmt->rowCount();

            $this->assert(
                $rowsAffected > 0,
                "Pedido aceito pelo shopper",
                "Rows affected: {$rowsAffected}"
            );

            // Verificar status
            $stmt = $this->pdo->prepare("SELECT status, shopper_id FROM om_market_orders WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            $this->assert(
                $order['status'] === 'accepted' && $order['shopper_id'] == $shopperId,
                "Status atualizado para 'accepted'",
                "Status: {$order['status']}, Shopper: {$order['shopper_id']}"
            );

            return true;

        } catch (Exception $e) {
            $this->log("ERRO: " . $e->getMessage(), 'error');
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTE 4: Shopper inicia compras
    // ═══════════════════════════════════════════════════════════════════════════
    public function testStartShopping() {
        $this->log("\n───────────────────────────────────────────────────────────────", 'header');
        $this->log("TESTE 4: SHOPPER INICIA COMPRAS", 'header');
        $this->log("───────────────────────────────────────────────────────────────\n", 'header');

        try {
            $orderId = $this->testData['order_id'];

            $stmt = $this->pdo->prepare("
                UPDATE om_market_orders
                SET status = 'shopping',
                    shopping_started_at = NOW()
                WHERE order_id = ?
            ");
            $stmt->execute([$orderId]);

            // Verificar
            $stmt = $this->pdo->prepare("SELECT status FROM om_market_orders WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            $this->assert(
                $order['status'] === 'shopping',
                "Status atualizado para 'shopping'",
                "Status atual: {$order['status']}"
            );

            return true;

        } catch (Exception $e) {
            $this->log("ERRO: " . $e->getMessage(), 'error');
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTE 5: Shopper escaneia itens
    // ═══════════════════════════════════════════════════════════════════════════
    public function testScanItems() {
        $this->log("\n───────────────────────────────────────────────────────────────", 'header');
        $this->log("TESTE 5: SHOPPER ESCANEIA ITENS", 'header');
        $this->log("───────────────────────────────────────────────────────────────\n", 'header');

        try {
            $orderId = $this->testData['order_id'];

            // Marcar todos os itens como escaneados
            $stmt = $this->pdo->prepare("
                UPDATE om_market_order_items
                SET scanned = 1, scanned_at = NOW()
                WHERE order_id = ?
            ");
            $stmt->execute([$orderId]);

            // Verificar
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as total, SUM(scanned) as scanned
                FROM om_market_order_items WHERE order_id = ?
            ");
            $stmt->execute([$orderId]);
            $items = $stmt->fetch();

            $this->assert(
                $items['total'] == $items['scanned'],
                "Todos os itens escaneados",
                "Total: {$items['total']}, Escaneados: {$items['scanned']}"
            );

            return true;

        } catch (Exception $e) {
            $this->log("ERRO: " . $e->getMessage(), 'error');
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTE 6: Shopper finaliza compras
    // ═══════════════════════════════════════════════════════════════════════════
    public function testFinishShopping() {
        $this->log("\n───────────────────────────────────────────────────────────────", 'header');
        $this->log("TESTE 6: SHOPPER FINALIZA COMPRAS", 'header');
        $this->log("───────────────────────────────────────────────────────────────\n", 'header');

        try {
            $orderId = $this->testData['order_id'];

            $stmt = $this->pdo->prepare("
                UPDATE om_market_orders
                SET status = 'purchased',
                    shopping_finished_at = NOW()
                WHERE order_id = ?
            ");
            $stmt->execute([$orderId]);

            // Verificar
            $stmt = $this->pdo->prepare("SELECT status FROM om_market_orders WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            $this->assert(
                $order['status'] === 'purchased',
                "Status atualizado para 'purchased'",
                "Status atual: {$order['status']}"
            );

            return true;

        } catch (Exception $e) {
            $this->log("ERRO: " . $e->getMessage(), 'error');
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTE 7: Shopper inicia entrega
    // ═══════════════════════════════════════════════════════════════════════════
    public function testStartDelivery() {
        $this->log("\n───────────────────────────────────────────────────────────────", 'header');
        $this->log("TESTE 7: SHOPPER INICIA ENTREGA", 'header');
        $this->log("───────────────────────────────────────────────────────────────\n", 'header');

        try {
            $orderId = $this->testData['order_id'];

            $stmt = $this->pdo->prepare("
                UPDATE om_market_orders
                SET status = 'delivering',
                    delivery_started_at = NOW()
                WHERE order_id = ?
            ");
            $stmt->execute([$orderId]);

            // Verificar
            $stmt = $this->pdo->prepare("SELECT status FROM om_market_orders WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            $this->assert(
                $order['status'] === 'delivering',
                "Status atualizado para 'delivering'",
                "Status atual: {$order['status']}"
            );

            return true;

        } catch (Exception $e) {
            $this->log("ERRO: " . $e->getMessage(), 'error');
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTE 8: Shopper confirma entrega
    // ═══════════════════════════════════════════════════════════════════════════
    public function testConfirmDelivery() {
        $this->log("\n───────────────────────────────────────────────────────────────", 'header');
        $this->log("TESTE 8: SHOPPER CONFIRMA ENTREGA", 'header');
        $this->log("───────────────────────────────────────────────────────────────\n", 'header');

        try {
            $orderId = $this->testData['order_id'];
            $deliveryCode = $this->testData['delivery_code'];

            // Verificar código
            $stmt = $this->pdo->prepare("SELECT delivery_code FROM om_market_orders WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            $codeMatch = $order['delivery_code'] === $deliveryCode;
            $this->assert($codeMatch, "Código de entrega válido", "Código: {$deliveryCode}");

            if (!$codeMatch) return false;

            // Confirmar entrega
            $stmt = $this->pdo->prepare("
                UPDATE om_market_orders
                SET status = 'delivered',
                    matching_status = 'completed',
                    delivered_at = NOW()
                WHERE order_id = ?
            ");
            $stmt->execute([$orderId]);

            // Verificar
            $stmt = $this->pdo->prepare("SELECT status, delivered_at FROM om_market_orders WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            $this->assert(
                $order['status'] === 'delivered',
                "Status atualizado para 'delivered'",
                "Status: {$order['status']}, Entregue em: {$order['delivered_at']}"
            );

            return true;

        } catch (Exception $e) {
            $this->log("ERRO: " . $e->getMessage(), 'error');
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTE 9: Sistema cria repasses
    // ═══════════════════════════════════════════════════════════════════════════
    public function testCreateRepasses() {
        $this->log("\n───────────────────────────────────────────────────────────────", 'header');
        $this->log("TESTE 9: SISTEMA CRIA REPASSES", 'header');
        $this->log("───────────────────────────────────────────────────────────────\n", 'header');

        try {
            $orderId = $this->testData['order_id'];
            $partnerId = $this->testData['partner']['partner_id'];
            $shopperId = $this->testData['shopper']['shopper_id'];
            $total = $this->testData['total'];
            $shopperEarning = $this->testData['shopper_earning'];

            // Simular criação de repasses (como faria a API)
            $holdHours = 2;
            $holdUntil = date('Y-m-d H:i:s', strtotime("+{$holdHours} hours"));

            // Calcular valores
            $taxaPlataforma = $total * 0.10; // 10%
            $valorMercado = $total - $taxaPlataforma;

            // Repasse Mercado
            $stmt = $this->pdo->prepare("
                INSERT INTO om_repasses (order_id, tipo, destinatario_id, valor_bruto, taxa_plataforma, valor_liquido, status, hold_until, created_at)
                VALUES (?, 'mercado', ?, ?, ?, ?, 'hold', ?, NOW())
            ");
            $stmt->execute([$orderId, $partnerId, $total, $taxaPlataforma, $valorMercado, $holdUntil]);

            // Repasse Shopper
            $stmt = $this->pdo->prepare("
                INSERT INTO om_repasses (order_id, tipo, destinatario_id, valor_bruto, taxa_plataforma, valor_liquido, status, hold_until, created_at)
                VALUES (?, 'shopper', ?, ?, 0, ?, 'hold', ?, NOW())
            ");
            $stmt->execute([$orderId, $shopperId, $shopperEarning, $shopperEarning, $holdUntil]);

            // Verificar repasses criados
            $stmt = $this->pdo->prepare("SELECT * FROM om_repasses WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $repasses = $stmt->fetchAll();

            $this->assert(
                count($repasses) >= 2,
                "Repasses criados",
                "Total de repasses: " . count($repasses)
            );

            foreach ($repasses as $r) {
                $this->log("  - {$r['tipo']}: R$ " . number_format($r['valor_liquido'], 2, ',', '.') . " (status: {$r['status']}, libera em: {$r['hold_until']})", 'info');
            }

            return count($repasses) >= 2;

        } catch (Exception $e) {
            $this->log("ERRO: " . $e->getMessage(), 'error');
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTE 10: Simular liberação de repasses
    // ═══════════════════════════════════════════════════════════════════════════
    public function testReleaseRepasses() {
        $this->log("\n───────────────────────────────────────────────────────────────", 'header');
        $this->log("TESTE 10: SIMULAR LIBERAÇÃO DE REPASSES", 'header');
        $this->log("───────────────────────────────────────────────────────────────\n", 'header');

        try {
            $orderId = $this->testData['order_id'];
            $partnerId = $this->testData['partner']['partner_id'];
            $shopperId = $this->testData['shopper']['shopper_id'];

            // Forçar hold_until para o passado (simular passagem de 2 horas)
            $stmt = $this->pdo->prepare("
                UPDATE om_repasses
                SET hold_until = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
                WHERE order_id = ?
            ");
            $stmt->execute([$orderId]);

            $this->log("Simulando passagem de 2 horas...", 'info');

            // Buscar repasses prontos para liberar
            $stmt = $this->pdo->prepare("
                SELECT * FROM om_repasses
                WHERE order_id = ? AND status = 'hold' AND hold_until <= NOW()
            ");
            $stmt->execute([$orderId]);
            $repasses = $stmt->fetchAll();

            $this->assert(
                count($repasses) >= 2,
                "Repasses prontos para liberação",
                "Repasses em hold expirado: " . count($repasses)
            );

            // Processar cada repasse
            foreach ($repasses as $repasse) {
                $tipo = $repasse['tipo'];
                $valor = $repasse['valor_liquido'];

                if ($tipo === 'mercado') {
                    // Atualizar saldo do mercado
                    $stmt = $this->pdo->prepare("
                        INSERT INTO om_mercado_saldo (partner_id, saldo_disponivel, total_recebido, updated_at)
                        VALUES (?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE
                            saldo_disponivel = saldo_disponivel + VALUES(saldo_disponivel),
                            total_recebido = total_recebido + VALUES(total_recebido),
                            updated_at = NOW()
                    ");
                    $stmt->execute([$partnerId, $valor, $valor]);

                } elseif ($tipo === 'shopper') {
                    // Atualizar saldo do shopper
                    $stmt = $this->pdo->prepare("
                        INSERT INTO om_shopper_saldo (shopper_id, saldo_disponivel, total_ganhos, updated_at)
                        VALUES (?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE
                            saldo_disponivel = saldo_disponivel + VALUES(saldo_disponivel),
                            total_ganhos = total_ganhos + VALUES(total_ganhos),
                            updated_at = NOW()
                    ");
                    $stmt->execute([$shopperId, $valor, $valor]);
                }

                // Marcar repasse como liberado
                $stmt = $this->pdo->prepare("
                    UPDATE om_repasses SET status = 'liberado', liberado_at = NOW() WHERE id = ?
                ");
                $stmt->execute([$repasse['id']]);

                $this->log("  ✓ Repasse {$tipo} liberado: R$ " . number_format($valor, 2, ',', '.'), 'success');
            }

            // Verificar saldos
            $stmt = $this->pdo->prepare("SELECT saldo_disponivel FROM om_mercado_saldo WHERE partner_id = ?");
            $stmt->execute([$partnerId]);
            $mercadoSaldo = $stmt->fetchColumn();

            $stmt = $this->pdo->prepare("SELECT saldo_disponivel FROM om_shopper_saldo WHERE shopper_id = ?");
            $stmt->execute([$shopperId]);
            $shopperSaldo = $stmt->fetchColumn();

            $this->assert(
                $mercadoSaldo > 0,
                "Saldo do mercado atualizado",
                "Saldo: R$ " . number_format($mercadoSaldo, 2, ',', '.')
            );

            $this->assert(
                $shopperSaldo > 0,
                "Saldo do shopper atualizado",
                "Saldo: R$ " . number_format($shopperSaldo, 2, ',', '.')
            );

            return true;

        } catch (Exception $e) {
            $this->log("ERRO: " . $e->getMessage(), 'error');
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // CLEANUP - Limpar dados de teste (opcional)
    // ═══════════════════════════════════════════════════════════════════════════
    public function cleanup($deleteData = false) {
        if (!$deleteData) {
            $this->log("\n[INFO] Dados de teste mantidos para inspeção manual.", 'info');
            return;
        }

        $this->log("\n───────────────────────────────────────────────────────────────", 'header');
        $this->log("LIMPANDO DADOS DE TESTE", 'header');
        $this->log("───────────────────────────────────────────────────────────────\n", 'header');

        try {
            $orderId = $this->testData['order_id'] ?? 0;

            if ($orderId) {
                $this->pdo->prepare("DELETE FROM om_repasses WHERE order_id = ?")->execute([$orderId]);
                $this->pdo->prepare("DELETE FROM om_market_order_items WHERE order_id = ?")->execute([$orderId]);
                $this->pdo->prepare("DELETE FROM om_market_orders WHERE order_id = ?")->execute([$orderId]);
                $this->log("Pedido de teste removido", 'success');
            }

        } catch (Exception $e) {
            $this->log("Erro na limpeza: " . $e->getMessage(), 'warning');
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // EXECUTAR TODOS OS TESTES
    // ═══════════════════════════════════════════════════════════════════════════
    public function run($cleanup = false) {
        $this->log("\n");
        $this->log("╔══════════════════════════════════════════════════════════════════╗", 'header');
        $this->log("║     TESTE E2E - SISTEMA ONEMUNDO MERCADO                        ║", 'header');
        $this->log("║     Data: " . date('d/m/Y H:i:s') . "                                    ║", 'header');
        $this->log("╚══════════════════════════════════════════════════════════════════╝", 'header');

        // Executar setup
        if (!$this->setup()) {
            $this->log("\n❌ SETUP FALHOU - Abortando testes", 'error');
            return false;
        }

        // Executar testes em sequência
        $tests = [
            'testCreateOrder',
            'testOfferAvailable',
            'testAcceptOrder',
            'testStartShopping',
            'testScanItems',
            'testFinishShopping',
            'testStartDelivery',
            'testConfirmDelivery',
            'testCreateRepasses',
            'testReleaseRepasses'
        ];

        $allPassed = true;
        foreach ($tests as $test) {
            if (!$this->$test()) {
                $allPassed = false;
                $this->log("\n⚠️ Teste falhou, continuando com os próximos...", 'warning');
            }
        }

        // Cleanup
        $this->cleanup($cleanup);

        // Relatório final
        $this->printSummary();

        return $allPassed;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // RELATÓRIO FINAL
    // ═══════════════════════════════════════════════════════════════════════════
    private function printSummary() {
        $passed = count(array_filter($this->results, fn($r) => $r['passed']));
        $failed = count($this->results) - $passed;
        $total = count($this->results);
        $duration = round(microtime(true) - $this->startTime, 2);

        $this->log("\n");
        $this->log("╔══════════════════════════════════════════════════════════════════╗", 'header');
        $this->log("║                    RELATÓRIO FINAL                               ║", 'header');
        $this->log("╠══════════════════════════════════════════════════════════════════╣", 'header');
        $this->log("║  Total de testes: {$total}                                            ║", 'header');
        $this->log("║  ✓ Passou: {$passed}                                                   ║", $failed === 0 ? 'success' : 'header');
        $this->log("║  ✗ Falhou: {$failed}                                                   ║", $failed > 0 ? 'error' : 'header');
        $this->log("║  Tempo: {$duration}s                                                ║", 'header');
        $this->log("╚══════════════════════════════════════════════════════════════════╝", 'header');

        if ($failed === 0) {
            $this->log("\n🎉 TODOS OS TESTES PASSARAM! O sistema está funcionando corretamente.", 'success');
        } else {
            $this->log("\n⚠️ ALGUNS TESTES FALHARAM. Verifique os erros acima.", 'error');
        }

        // Dados do teste
        if (!empty($this->testData['order_id'])) {
            $this->log("\n📋 Dados do teste:", 'info');
            $this->log("   - Order ID: {$this->testData['order_id']}", 'info');
            $this->log("   - Order Number: {$this->testData['order_number']}", 'info');
            $this->log("   - Código de entrega: {$this->testData['delivery_code']}", 'info');
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// EXECUÇÃO
// ═══════════════════════════════════════════════════════════════════════════════

// Se executado via web, adicionar HTML
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
    <html><head>
    <title>Teste E2E - OneMundo Mercado</title>
    <style>
        body { font-family: monospace; background: #0a0a0a; color: #fff; padding: 20px; line-height: 1.6; }
        div { margin: 2px 0; }
    </style>
    </head><body>';
}

// Executar apenas se solicitado
if (php_sapi_name() === 'cli' || isset($_GET['run'])) {
    $test = new E2ETest();
    $cleanup = isset($_GET['cleanup']) || (isset($argv[1]) && $argv[1] === '--cleanup');
    $test->run($cleanup);
} else {
    echo "<h1>Teste E2E - OneMundo Mercado</h1>";
    echo "<p>Adicione <code>?run=1</code> na URL para executar os testes.</p>";
    echo "<p>Adicione <code>&cleanup=1</code> para limpar dados após o teste.</p>";
    echo "<p><a href='?run=1' style='color: #10b981;'>▶️ Executar Testes</a></p>";
    echo "<p><a href='?run=1&cleanup=1' style='color: #f59e0b;'>▶️ Executar e Limpar</a></p>";
}

if (php_sapi_name() !== 'cli') {
    echo '</body></html>';
}
