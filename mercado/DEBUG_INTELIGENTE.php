<?php
require_once __DIR__ . '/config/database.php';
/**
 * 🔍 DEBUG SISTEMA INTELIGENTE
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<pre style='background:#0f172a;color:#fff;padding:20px;font-family:monospace;'>";
echo "🔍 DEBUG SISTEMA INTELIGENTE\n";
echo "=============================\n\n";

// 1. Conexão
echo "1. Testando conexão...\n";
try {
    $pdo = getPDO();
    echo "   ✅ Conexão OK\n\n";
} catch (Exception $e) {
    die("   ❌ ERRO: " . $e->getMessage() . "\n");
}

// 2. Criar colunas necessárias
echo "2. Criando colunas necessárias...\n";

$alteracoes = [
    "ALTER TABLE om_market_shoppers ADD COLUMN current_lat DECIMAL(10,8) DEFAULT NULL",
    "ALTER TABLE om_market_shoppers ADD COLUMN current_lng DECIMAL(11,8) DEFAULT NULL",
    "ALTER TABLE om_market_shoppers ADD COLUMN last_location_at DATETIME DEFAULT NULL",
    "ALTER TABLE om_market_shoppers ADD COLUMN rating DECIMAL(3,2) DEFAULT 5.00",
    "ALTER TABLE om_market_shoppers ADD COLUMN accept_rate DECIMAL(5,2) DEFAULT 100.00",
    "ALTER TABLE om_market_shoppers ADD COLUMN avg_accept_time INT DEFAULT 30",
    "ALTER TABLE om_market_shoppers ADD COLUMN total_offers INT DEFAULT 0",
    "ALTER TABLE om_market_shoppers ADD COLUMN total_accepts INT DEFAULT 0",
    "ALTER TABLE om_market_delivery ADD COLUMN current_lat DECIMAL(10,8) DEFAULT NULL",
    "ALTER TABLE om_market_delivery ADD COLUMN current_lng DECIMAL(11,8) DEFAULT NULL",
    "ALTER TABLE om_market_delivery ADD COLUMN last_location_at DATETIME DEFAULT NULL",
    "ALTER TABLE om_market_delivery ADD COLUMN rating DECIMAL(3,2) DEFAULT 5.00",
    "ALTER TABLE om_market_delivery ADD COLUMN accept_rate DECIMAL(5,2) DEFAULT 100.00",
    "ALTER TABLE om_market_delivery ADD COLUMN avg_accept_time INT DEFAULT 30",
    "ALTER TABLE om_market_delivery ADD COLUMN total_offers INT DEFAULT 0",
    "ALTER TABLE om_market_delivery ADD COLUMN total_accepts INT DEFAULT 0",
    "ALTER TABLE om_market_partners ADD COLUMN lat DECIMAL(10,8) DEFAULT NULL",
    "ALTER TABLE om_market_partners ADD COLUMN lng DECIMAL(11,8) DEFAULT NULL",
    "ALTER TABLE om_market_partners ADD COLUMN delivery_radius INT DEFAULT 15",
];

foreach ($alteracoes as $sql) {
    try {
        $pdo->exec($sql);
        echo "   ✅ OK\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "   ⏭️ Já existe\n";
        } else {
            echo "   ⚠️ " . $e->getMessage() . "\n";
        }
    }
}

// 3. Criar tabela de ofertas
echo "\n3. Criando tabela om_dispatch_offers...\n";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS om_dispatch_offers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        worker_type VARCHAR(20) NOT NULL,
        worker_id INT NOT NULL,
        status VARCHAR(20) DEFAULT 'pending',
        score DECIMAL(5,2) DEFAULT 0,
        distancia_km DECIMAL(5,2) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        responded_at DATETIME DEFAULT NULL,
        response_time_seconds INT DEFAULT NULL
    )");
    echo "   ✅ Tabela OK\n";
} catch (Exception $e) {
    echo "   ❌ " . $e->getMessage() . "\n";
}

// 4. Atualizar parceiro com coordenadas
echo "\n4. Atualizando parceiro com GPS...\n";
try {
    $pdo->exec("UPDATE om_market_partners SET lat = -18.8512, lng = -41.9455, delivery_radius = 15 WHERE partner_id = 1");
    echo "   ✅ Parceiro atualizado\n";
} catch (Exception $e) {
    echo "   ❌ " . $e->getMessage() . "\n";
}

// 5. Criar/atualizar shoppers
echo "\n5. Criando shoppers de teste...\n";
try {
    $pdo->exec("DELETE FROM om_market_shoppers WHERE email LIKE 'shopper%@teste.com'");
    
    $shoppers = [
        ['Shopper Perto', 'shopper1@teste.com', -18.8520, -41.9460, 5.0, 95],
        ['Shopper Médio', 'shopper2@teste.com', -18.8600, -41.9500, 4.8, 88],
        ['Shopper Longe', 'shopper3@teste.com', -18.8800, -41.9700, 4.5, 75],
    ];
    
    foreach ($shoppers as $s) {
        $pdo->exec("INSERT INTO om_market_shoppers (name, email, status, is_online, is_busy, current_lat, current_lng, rating, accept_rate, avg_accept_time)
                    VALUES ('{$s[0]}', '{$s[1]}', 'online', 1, 0, {$s[2]}, {$s[3]}, {$s[4]}, {$s[5]}, 25)");
        echo "   ✅ {$s[0]} criado\n";
    }
} catch (Exception $e) {
    echo "   ❌ " . $e->getMessage() . "\n";
}

// 6. Criar/atualizar deliverys
echo "\n6. Criando deliverys de teste...\n";
try {
    $pdo->exec("DELETE FROM om_market_delivery WHERE email LIKE 'delivery%@teste.com'");
    
    $deliverys = [
        ['Motoboy Veloz', 'delivery1@teste.com', -18.8515, -41.9458, 4.9, 98, 'moto'],
        ['Ciclista Eco', 'delivery2@teste.com', -18.8700, -41.9600, 4.7, 85, 'bike'],
    ];
    
    foreach ($deliverys as $d) {
        $pdo->exec("INSERT INTO om_market_delivery (name, email, phone, status, is_online, current_lat, current_lng, rating, accept_rate, avg_accept_time, vehicle)
                    VALUES ('{$d[0]}', '{$d[1]}', '11999990000', 'online', 1, {$d[2]}, {$d[3]}, {$d[4]}, {$d[5]}, 20, '{$d[6]}')");
        echo "   ✅ {$d[0]} criado\n";
    }
} catch (Exception $e) {
    echo "   ❌ " . $e->getMessage() . "\n";
}

// 7. Verificar shoppers
echo "\n7. Verificando shoppers...\n";
$shoppers = $pdo->query("SELECT shopper_id, name, status, is_online, is_busy, current_lat, current_lng FROM om_market_shoppers WHERE is_online = 1")->fetchAll();
foreach ($shoppers as $s) {
    echo "   - {$s['name']} (ID:{$s['shopper_id']}) GPS: {$s['current_lat']}, {$s['current_lng']}\n";
}

// 8. Verificar deliverys
echo "\n8. Verificando deliverys...\n";
$deliverys = $pdo->query("SELECT delivery_id, name, status, is_online, current_lat, current_lng FROM om_market_delivery WHERE is_online = 1")->fetchAll();
foreach ($deliverys as $d) {
    echo "   - {$d['name']} (ID:{$d['delivery_id']}) GPS: {$d['current_lat']}, {$d['current_lng']}\n";
}

// 9. Testar criação de pedido
echo "\n9. Criando pedido de teste...\n";
try {
    $orderNumber = 'DEBUG' . time();
    $pdo->exec("INSERT INTO om_orders (order_number, customer_id, partner_id, total, status, payment_status, created_at)
                VALUES ('$orderNumber', 2, 1, 100.00, 'pago', 'aprovado', NOW())");
    $orderId = $pdo->lastInsertId();
    echo "   ✅ Pedido #$orderId criado\n";
} catch (Exception $e) {
    echo "   ❌ " . $e->getMessage() . "\n";
    $orderId = 0;
}

// 10. Testar dispatch manual (sem API)
if ($orderId) {
    echo "\n10. Testando dispatch manual...\n";
    
    // Buscar mercado
    $mercado = $pdo->query("SELECT * FROM om_market_partners WHERE partner_id = 1")->fetch();
    $mercadoLat = $mercado['lat'] ?? -18.8512;
    $mercadoLng = $mercado['lng'] ?? -41.9455;
    echo "   Mercado: {$mercado['name']} em $mercadoLat, $mercadoLng\n";
    
    // Buscar shoppers disponíveis
    $shoppers = $pdo->query("SELECT * FROM om_market_shoppers WHERE is_online = 1 AND (is_busy = 0 OR is_busy IS NULL)")->fetchAll();
    echo "   Shoppers disponíveis: " . count($shoppers) . "\n";
    
    // Calcular distâncias
    foreach ($shoppers as $s) {
        $lat1 = $s['current_lat'] ?? -18.85;
        $lng1 = $s['current_lng'] ?? -41.94;
        
        // Haversine
        $R = 6371;
        $dLat = deg2rad($mercadoLat - $lat1);
        $dLon = deg2rad($mercadoLng - $lng1);
        $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($mercadoLat)) * sin($dLon/2) * sin($dLon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        $dist = round($R * $c, 2);
        
        echo "   - {$s['name']}: $dist km do mercado\n";
        
        // Criar oferta
        $pdo->prepare("INSERT INTO om_dispatch_offers (order_id, worker_type, worker_id, score, distancia_km) VALUES (?, 'shopper', ?, ?, ?)")
            ->execute([$orderId, $s['shopper_id'], 100 - ($dist * 5), $dist]);
    }
    
    // Simular aceite do primeiro
    $primeiroShopper = $shoppers[0] ?? null;
    if ($primeiroShopper) {
        echo "\n11. Simulando aceite do shopper...\n";
        
        $pdo->exec("UPDATE om_orders SET status = 'shopper_aceito', shopper_id = {$primeiroShopper['shopper_id']} WHERE order_id = $orderId");
        echo "   ✅ {$primeiroShopper['name']} aceitou o pedido\n";
        
        // Gerar código
        $palavras = ["BANANA", "LARANJA", "MORANGO", "ABACAXI"];
        $codigo = $palavras[array_rand($palavras)] . '-' . rand(100, 999);
        
        $pdo->exec("UPDATE om_orders SET delivery_code = '$codigo', status = 'compra_finalizada' WHERE order_id = $orderId");
        echo "   🔑 Código: $codigo\n";
        
        // Simular delivery
        $primeiroDelivery = $deliverys[0] ?? null;
        if ($primeiroDelivery) {
            $pdo->exec("UPDATE om_orders SET status = 'delivery_aceito', delivery_id = {$primeiroDelivery['delivery_id']} WHERE order_id = $orderId");
            echo "   ✅ {$primeiroDelivery['name']} aceitou a entrega\n";
            
            $pdo->exec("UPDATE om_orders SET status = 'entregue', delivered_at = NOW() WHERE order_id = $orderId");
            echo "   ✅ PEDIDO ENTREGUE!\n";
        }
    }
}

echo "\n=============================\n";
echo "🎉 DEBUG CONCLUÍDO!\n";
echo "=============================\n";
echo "</pre>";

echo "<br><br>";
echo "<a href='TESTE_INTELIGENTE.php' style='padding:12px 24px;background:#10b981;color:#fff;text-decoration:none;border-radius:8px;margin:5px;'>🧠 Tentar Teste Inteligente</a>";
echo "<a href='MEGA_ROBO.php' style='padding:12px 24px;background:#3b82f6;color:#fff;text-decoration:none;border-radius:8px;margin:5px;'>🤖 Mega Robô</a>";
