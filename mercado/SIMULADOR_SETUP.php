<?php
require_once __DIR__ . '/config/database.php';
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * 🎮 SIMULADOR ONEMUNDO MERCADO - SETUP
 * ══════════════════════════════════════════════════════════════════════════════
 * 
 * Cria ambiente completo de simulação:
 * - 5 Mercados em cidades diferentes
 * - 20 Shoppers distribuídos
 * - 10 Deliverys distribuídos
 * - 15 Clientes com CEPs diferentes
 * - Tabelas de controle
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<pre style='background:#0f172a;color:#fff;padding:20px;font-family:monospace;min-height:100vh;'>";
echo "🎮 SIMULADOR ONEMUNDO MERCADO - SETUP\n";
echo "═══════════════════════════════════════════════════════\n\n";

$pdo = getPDO();

// ══════════════════════════════════════════════════════════════════════════════
// PARTE 1: GARANTIR ESTRUTURA DAS TABELAS
// ══════════════════════════════════════════════════════════════════════════════

echo "📦 PARTE 1: Preparando estrutura...\n\n";

$alteracoes = [
    // Parceiros/Mercados
    "ALTER TABLE om_market_partners ADD COLUMN lat DECIMAL(10,8) DEFAULT NULL",
    "ALTER TABLE om_market_partners ADD COLUMN lng DECIMAL(11,8) DEFAULT NULL",
    "ALTER TABLE om_market_partners ADD COLUMN delivery_radius INT DEFAULT 15",
    "ALTER TABLE om_market_partners ADD COLUMN delivery_time_min INT DEFAULT 30",
    "ALTER TABLE om_market_partners ADD COLUMN delivery_time_max INT DEFAULT 60",
    "ALTER TABLE om_market_partners ADD COLUMN min_order DECIMAL(10,2) DEFAULT 20.00",
    "ALTER TABLE om_market_partners ADD COLUMN delivery_fee DECIMAL(10,2) DEFAULT 9.90",
    "ALTER TABLE om_market_partners ADD COLUMN is_open TINYINT(1) DEFAULT 1",
    "ALTER TABLE om_market_partners ADD COLUMN open_time TIME DEFAULT '08:00:00'",
    "ALTER TABLE om_market_partners ADD COLUMN close_time TIME DEFAULT '22:00:00'",
    
    // Shoppers
    "ALTER TABLE om_market_shoppers ADD COLUMN current_lat DECIMAL(10,8) DEFAULT NULL",
    "ALTER TABLE om_market_shoppers ADD COLUMN current_lng DECIMAL(11,8) DEFAULT NULL",
    "ALTER TABLE om_market_shoppers ADD COLUMN last_location_at DATETIME DEFAULT NULL",
    "ALTER TABLE om_market_shoppers ADD COLUMN rating DECIMAL(3,2) DEFAULT 5.00",
    "ALTER TABLE om_market_shoppers ADD COLUMN total_ratings INT DEFAULT 0",
    "ALTER TABLE om_market_shoppers ADD COLUMN accept_rate DECIMAL(5,2) DEFAULT 100.00",
    "ALTER TABLE om_market_shoppers ADD COLUMN avg_accept_time INT DEFAULT 30",
    "ALTER TABLE om_market_shoppers ADD COLUMN total_offers INT DEFAULT 0",
    "ALTER TABLE om_market_shoppers ADD COLUMN total_accepts INT DEFAULT 0",
    "ALTER TABLE om_market_shoppers ADD COLUMN total_orders INT DEFAULT 0",
    "ALTER TABLE om_market_shoppers ADD COLUMN total_earnings DECIMAL(10,2) DEFAULT 0",
    "ALTER TABLE om_market_shoppers ADD COLUMN city VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE om_market_shoppers ADD COLUMN partner_id INT DEFAULT NULL",
    
    // Deliverys
    "ALTER TABLE om_market_delivery ADD COLUMN current_lat DECIMAL(10,8) DEFAULT NULL",
    "ALTER TABLE om_market_delivery ADD COLUMN current_lng DECIMAL(11,8) DEFAULT NULL",
    "ALTER TABLE om_market_delivery ADD COLUMN last_location_at DATETIME DEFAULT NULL",
    "ALTER TABLE om_market_delivery ADD COLUMN rating DECIMAL(3,2) DEFAULT 5.00",
    "ALTER TABLE om_market_delivery ADD COLUMN total_ratings INT DEFAULT 0",
    "ALTER TABLE om_market_delivery ADD COLUMN accept_rate DECIMAL(5,2) DEFAULT 100.00",
    "ALTER TABLE om_market_delivery ADD COLUMN avg_accept_time INT DEFAULT 30",
    "ALTER TABLE om_market_delivery ADD COLUMN total_offers INT DEFAULT 0",
    "ALTER TABLE om_market_delivery ADD COLUMN total_accepts INT DEFAULT 0",
    "ALTER TABLE om_market_delivery ADD COLUMN total_deliveries INT DEFAULT 0",
    "ALTER TABLE om_market_delivery ADD COLUMN total_earnings DECIMAL(10,2) DEFAULT 0",
    "ALTER TABLE om_market_delivery ADD COLUMN city VARCHAR(100) DEFAULT NULL",
];

foreach ($alteracoes as $sql) {
    try { 
        $pdo->exec($sql); 
        echo "✅ OK\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "⏭️ Já existe\n";
        }
    }
}

// Tabela de clientes simulados
$pdo->exec("CREATE TABLE IF NOT EXISTS om_sim_customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    cep VARCHAR(10),
    address VARCHAR(255),
    city VARCHAR(100),
    state VARCHAR(2),
    lat DECIMAL(10,8),
    lng DECIMAL(11,8),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

echo "\n✅ Estrutura preparada!\n\n";

// ══════════════════════════════════════════════════════════════════════════════
// PARTE 2: CRIAR MERCADOS
// ══════════════════════════════════════════════════════════════════════════════

echo "🏪 PARTE 2: Criando mercados...\n\n";

$mercados = [
    // Governador Valadares - MG
    [
        'name' => 'Supermercado Central GV',
        'city' => 'Governador Valadares',
        'state' => 'MG',
        'cep' => '35010000',
        'address' => 'Av. Brasil, 1000 - Centro',
        'lat' => -18.8512,
        'lng' => -41.9455,
        'radius' => 15,
        'fee' => 9.90,
        'min' => 30.00
    ],
    [
        'name' => 'Mercado Bom Preço GV',
        'city' => 'Governador Valadares',
        'state' => 'MG',
        'cep' => '35020000',
        'address' => 'Rua Israel Pinheiro, 500 - Vila Bretas',
        'lat' => -18.8620,
        'lng' => -41.9380,
        'radius' => 12,
        'fee' => 7.90,
        'min' => 25.00
    ],
    // São Paulo - SP
    [
        'name' => 'Mercado Express SP',
        'city' => 'São Paulo',
        'state' => 'SP',
        'cep' => '01310100',
        'address' => 'Av. Paulista, 1500',
        'lat' => -23.5629,
        'lng' => -46.6544,
        'radius' => 10,
        'fee' => 12.90,
        'min' => 40.00
    ],
    [
        'name' => 'Super Economia Moema',
        'city' => 'São Paulo',
        'state' => 'SP',
        'cep' => '04077000',
        'address' => 'Av. Ibirapuera, 2000 - Moema',
        'lat' => -23.6010,
        'lng' => -46.6650,
        'radius' => 8,
        'fee' => 10.90,
        'min' => 35.00
    ],
    // Belo Horizonte - MG
    [
        'name' => 'Hiper BH Centro',
        'city' => 'Belo Horizonte',
        'state' => 'MG',
        'cep' => '30130000',
        'address' => 'Av. Afonso Pena, 1000 - Centro',
        'lat' => -19.9245,
        'lng' => -43.9352,
        'radius' => 12,
        'fee' => 8.90,
        'min' => 30.00
    ],
];

// Limpar mercados de teste antigos
$pdo->exec("DELETE FROM om_market_partners WHERE name LIKE '%GV%' OR name LIKE '%SP%' OR name LIKE '%BH%' OR name LIKE '%Economia%' OR name LIKE '%Express%' OR name LIKE '%Hiper%'");

foreach ($mercados as $m) {
    $stmt = $pdo->prepare("INSERT INTO om_market_partners 
        (name, city, state, cep, address, lat, lng, delivery_radius, delivery_fee, min_order, status, is_open, open_time, close_time) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, '07:00:00', '23:00:00')");
    $stmt->execute([$m['name'], $m['city'], $m['state'], $m['cep'], $m['address'], $m['lat'], $m['lng'], $m['radius'], $m['fee'], $m['min']]);
    echo "✅ {$m['name']} - {$m['city']}/{$m['state']}\n";
}

echo "\n";

// ══════════════════════════════════════════════════════════════════════════════
// PARTE 3: CRIAR SHOPPERS
// ══════════════════════════════════════════════════════════════════════════════

echo "👷 PARTE 3: Criando shoppers...\n\n";

$nomesMasc = ['João', 'Pedro', 'Carlos', 'Lucas', 'Rafael', 'Bruno', 'Felipe', 'André', 'Marcos', 'Gustavo'];
$nomesFem = ['Maria', 'Ana', 'Julia', 'Fernanda', 'Camila', 'Beatriz', 'Larissa', 'Patrícia', 'Carla', 'Amanda'];
$sobrenomes = ['Silva', 'Santos', 'Oliveira', 'Souza', 'Lima', 'Pereira', 'Costa', 'Rodrigues', 'Almeida', 'Nascimento'];

// Shoppers por cidade (com variação de localização)
$shoppersConfig = [
    // Gov. Valadares - 8 shoppers
    ['city' => 'Governador Valadares', 'base_lat' => -18.8512, 'base_lng' => -41.9455, 'count' => 8],
    // São Paulo - 8 shoppers
    ['city' => 'São Paulo', 'base_lat' => -23.5629, 'base_lng' => -46.6544, 'count' => 8],
    // BH - 4 shoppers
    ['city' => 'Belo Horizonte', 'base_lat' => -19.9245, 'base_lng' => -43.9352, 'count' => 4],
];

// Limpar shoppers de simulação
$pdo->exec("DELETE FROM om_market_shoppers WHERE email LIKE '%@sim.onemundo.com'");

$shopperId = 1;
foreach ($shoppersConfig as $config) {
    for ($i = 0; $i < $config['count']; $i++) {
        $isFem = rand(0, 1);
        $nome = $isFem ? $nomesFem[array_rand($nomesFem)] : $nomesMasc[array_rand($nomesMasc)];
        $sobrenome = $sobrenomes[array_rand($sobrenomes)];
        $fullName = "$nome $sobrenome";
        
        // Variação de localização (até 5km do centro)
        $lat = $config['base_lat'] + (rand(-50, 50) / 1000);
        $lng = $config['base_lng'] + (rand(-50, 50) / 1000);
        
        // Status variado
        $isOnline = rand(0, 100) < 70; // 70% online
        $isBusy = $isOnline && rand(0, 100) < 20; // 20% ocupados
        $rating = rand(40, 50) / 10; // 4.0 a 5.0
        $acceptRate = rand(60, 100);
        $avgTime = rand(10, 60);
        
        $email = strtolower(str_replace(' ', '.', $fullName)) . $shopperId . '@sim.onemundo.com';
        $phone = '119' . rand(10000000, 99999999);
        
        $stmt = $pdo->prepare("INSERT INTO om_market_shoppers 
            (name, email, phone, city, status, is_online, is_busy, current_lat, current_lng, 
             rating, accept_rate, avg_accept_time, last_location_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $fullName, $email, $phone, $config['city'],
            $isOnline ? 'online' : 'offline',
            $isOnline ? 1 : 0,
            $isBusy ? 1 : 0,
            $lat, $lng,
            $rating, $acceptRate, $avgTime
        ]);
        
        $status = $isOnline ? ($isBusy ? '🟡 Ocupado' : '🟢 Online') : '⚫ Offline';
        echo "   $status $fullName - {$config['city']} ({$rating}⭐)\n";
        $shopperId++;
    }
}

echo "\n";

// ══════════════════════════════════════════════════════════════════════════════
// PARTE 4: CRIAR DELIVERYS
// ══════════════════════════════════════════════════════════════════════════════

echo "🚴 PARTE 4: Criando deliverys...\n\n";

$veiculos = ['moto', 'bike', 'carro', 'moto', 'moto']; // Mais motos

$deliverysConfig = [
    ['city' => 'Governador Valadares', 'base_lat' => -18.8512, 'base_lng' => -41.9455, 'count' => 4],
    ['city' => 'São Paulo', 'base_lat' => -23.5629, 'base_lng' => -46.6544, 'count' => 4],
    ['city' => 'Belo Horizonte', 'base_lat' => -19.9245, 'base_lng' => -43.9352, 'count' => 2],
];

// Limpar deliverys de simulação
$pdo->exec("DELETE FROM om_market_delivery WHERE name LIKE '%Motoboy%' OR name LIKE '%Ciclista%' OR name LIKE '%Motorista%'");

foreach ($deliverysConfig as $config) {
    for ($i = 0; $i < $config['count']; $i++) {
        $veiculo = $veiculos[array_rand($veiculos)];
        $nome = $nomesMasc[array_rand($nomesMasc)];
        $sobrenome = $sobrenomes[array_rand($sobrenomes)];
        
        $titulo = $veiculo == 'moto' ? 'Motoboy' : ($veiculo == 'bike' ? 'Ciclista' : 'Motorista');
        $fullName = "$titulo $nome";
        
        $lat = $config['base_lat'] + (rand(-40, 40) / 1000);
        $lng = $config['base_lng'] + (rand(-40, 40) / 1000);
        
        $isOnline = rand(0, 100) < 60;
        $rating = rand(40, 50) / 10;
        $acceptRate = rand(70, 100);
        
        $phone = '119' . rand(10000000, 99999999);
        
        $stmt = $pdo->prepare("INSERT INTO om_market_delivery 
            (name, phone, city, vehicle, status, is_online, current_lat, current_lng, 
             rating, accept_rate, avg_accept_time, last_location_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $fullName, $phone, $config['city'], $veiculo,
            $isOnline ? 'online' : 'offline',
            $isOnline ? 1 : 0,
            $lat, $lng,
            $rating, $acceptRate, rand(10, 40)
        ]);
        
        $veiculoIcon = $veiculo == 'moto' ? '🏍️' : ($veiculo == 'bike' ? '🚴' : '🚗');
        $status = $isOnline ? '🟢' : '⚫';
        echo "   $status $veiculoIcon $fullName - {$config['city']}\n";
    }
}

echo "\n";

// ══════════════════════════════════════════════════════════════════════════════
// PARTE 5: CRIAR CLIENTES
// ══════════════════════════════════════════════════════════════════════════════

echo "👤 PARTE 5: Criando clientes simulados...\n\n";

$clientes = [
    // Gov. Valadares - dentro do raio
    ['name' => 'Cliente Centro GV', 'city' => 'Governador Valadares', 'cep' => '35010010', 'lat' => -18.8530, 'lng' => -41.9470],
    ['name' => 'Cliente Vila Bretas', 'city' => 'Governador Valadares', 'cep' => '35020100', 'lat' => -18.8600, 'lng' => -41.9400],
    ['name' => 'Cliente São Paulo GV', 'city' => 'Governador Valadares', 'cep' => '35030050', 'lat' => -18.8450, 'lng' => -41.9500],
    // Gov. Valadares - FORA do raio (longe)
    ['name' => 'Cliente Longe GV', 'city' => 'Governador Valadares', 'cep' => '35100000', 'lat' => -19.0000, 'lng' => -42.1000],
    
    // São Paulo - dentro do raio
    ['name' => 'Cliente Paulista', 'city' => 'São Paulo', 'cep' => '01310100', 'lat' => -23.5650, 'lng' => -46.6550],
    ['name' => 'Cliente Moema', 'city' => 'São Paulo', 'cep' => '04077100', 'lat' => -23.6000, 'lng' => -46.6600],
    ['name' => 'Cliente Pinheiros', 'city' => 'São Paulo', 'cep' => '05422000', 'lat' => -23.5670, 'lng' => -46.6900],
    // São Paulo - FORA do raio
    ['name' => 'Cliente Guarulhos', 'city' => 'Guarulhos', 'cep' => '07000000', 'lat' => -23.4500, 'lng' => -46.5300],
    
    // BH - dentro do raio
    ['name' => 'Cliente Savassi', 'city' => 'Belo Horizonte', 'cep' => '30130000', 'lat' => -19.9350, 'lng' => -43.9300],
    ['name' => 'Cliente Funcionários', 'city' => 'Belo Horizonte', 'cep' => '30140000', 'lat' => -19.9400, 'lng' => -43.9250],
    // BH - FORA do raio
    ['name' => 'Cliente Contagem', 'city' => 'Contagem', 'cep' => '32000000', 'lat' => -19.9300, 'lng' => -44.0500],
    
    // Cidade SEM mercado
    ['name' => 'Cliente Sem Mercado', 'city' => 'Ipatinga', 'cep' => '35160000', 'lat' => -19.4700, 'lng' => -42.5400],
    ['name' => 'Cliente Interior', 'city' => 'Caratinga', 'cep' => '35300000', 'lat' => -19.7900, 'lng' => -42.1400],
];

$pdo->exec("DELETE FROM om_sim_customers");

foreach ($clientes as $c) {
    $email = strtolower(str_replace(' ', '.', $c['name'])) . '@cliente.com';
    $phone = '119' . rand(10000000, 99999999);
    
    $stmt = $pdo->prepare("INSERT INTO om_sim_customers (name, email, phone, city, cep, lat, lng) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$c['name'], $email, $phone, $c['city'], $c['cep'], $c['lat'], $c['lng']]);
    
    echo "   👤 {$c['name']} - {$c['city']} (CEP: {$c['cep']})\n";
}

echo "\n";

// ══════════════════════════════════════════════════════════════════════════════
// RESUMO
// ══════════════════════════════════════════════════════════════════════════════

$totalMercados = $pdo->query("SELECT COUNT(*) FROM om_market_partners WHERE status = '1'")->fetchColumn();
$totalShoppersOnline = $pdo->query("SELECT COUNT(*) FROM om_market_shoppers WHERE is_online = 1")->fetchColumn();
$totalShoppersOffline = $pdo->query("SELECT COUNT(*) FROM om_market_shoppers WHERE is_online = 0")->fetchColumn();
$totalDeliverysOnline = $pdo->query("SELECT COUNT(*) FROM om_market_delivery WHERE is_online = 1")->fetchColumn();
$totalDeliverysOffline = $pdo->query("SELECT COUNT(*) FROM om_market_delivery WHERE is_online = 0")->fetchColumn();
$totalClientes = $pdo->query("SELECT COUNT(*) FROM om_sim_customers")->fetchColumn();

echo "═══════════════════════════════════════════════════════\n";
echo "🎉 SIMULADOR CONFIGURADO!\n";
echo "═══════════════════════════════════════════════════════\n\n";

echo "📊 RESUMO:\n";
echo "   🏪 Mercados: $totalMercados\n";
echo "   👷 Shoppers: 🟢 $totalShoppersOnline online | ⚫ $totalShoppersOffline offline\n";
echo "   🚴 Deliverys: 🟢 $totalDeliverysOnline online | ⚫ $totalDeliverysOffline offline\n";
echo "   👤 Clientes: $totalClientes\n";

echo "\n";
echo "</pre>";

echo "<div style='text-align:center;padding:20px;'>";
echo "<a href='SIMULADOR.php' style='display:inline-block;padding:16px 32px;background:#10b981;color:#fff;text-decoration:none;border-radius:12px;font-weight:bold;font-size:18px;'>🎮 Abrir Simulador</a>";
echo "</div>";
