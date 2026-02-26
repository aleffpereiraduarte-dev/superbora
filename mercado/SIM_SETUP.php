<?php
require_once __DIR__ . '/config/database.php';
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * 🎮 SIMULADOR V2 - SETUP COMPLETO COM PRODUTOS POR MERCADO
 * ══════════════════════════════════════════════════════════════════════════════
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<pre style='background:#0f172a;color:#fff;padding:20px;font-family:monospace;min-height:100vh;'>";
echo "🎮 SIMULADOR V2 - SETUP COMPLETO\n";
echo "═══════════════════════════════════════════════════════\n\n";

$pdo = getPDO();

// ══════════════════════════════════════════════════════════════════════════════
// PARTE 1: ESTRUTURA DAS TABELAS
// ══════════════════════════════════════════════════════════════════════════════

echo "📦 PARTE 1: Criando estrutura...\n\n";

// Tabela de produtos por mercado (preços diferentes por mercado)
$pdo->exec("CREATE TABLE IF NOT EXISTS om_sim_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    category VARCHAR(100),
    price DECIMAL(10,2) NOT NULL,
    special_price DECIMAL(10,2) DEFAULT NULL,
    unit VARCHAR(20) DEFAULT 'un',
    stock INT DEFAULT 100,
    image VARCHAR(255),
    is_available TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_partner (partner_id),
    INDEX idx_category (category)
)");

// Tabela de pedidos simulados
$pdo->exec("CREATE TABLE IF NOT EXISTS om_sim_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) NOT NULL,
    customer_id INT NOT NULL,
    partner_id INT NOT NULL,
    
    -- Status do pedido
    status ENUM('aguardando_pagamento','pago','aguardando_shopper','shopper_aceito','em_compra','compra_finalizada','aguardando_delivery','delivery_aceito','em_entrega','entregue','cancelado') DEFAULT 'aguardando_pagamento',
    
    -- Valores
    subtotal DECIMAL(10,2) DEFAULT 0,
    delivery_fee DECIMAL(10,2) DEFAULT 0,
    discount DECIMAL(10,2) DEFAULT 0,
    total DECIMAL(10,2) DEFAULT 0,
    
    -- Pagamento
    payment_method VARCHAR(20) DEFAULT 'pix',
    payment_status VARCHAR(20) DEFAULT 'pendente',
    
    -- Endereço
    delivery_address TEXT,
    delivery_lat DECIMAL(10,8),
    delivery_lng DECIMAL(11,8),
    
    -- Workers
    shopper_id INT DEFAULT NULL,
    shopper_earning DECIMAL(10,2) DEFAULT 0,
    shopper_accepted_at DATETIME DEFAULT NULL,
    shopping_started_at DATETIME DEFAULT NULL,
    shopping_finished_at DATETIME DEFAULT NULL,
    
    delivery_id INT DEFAULT NULL,
    delivery_earning DECIMAL(10,2) DEFAULT 0,
    delivery_accepted_at DATETIME DEFAULT NULL,
    delivery_started_at DATETIME DEFAULT NULL,
    
    -- Códigos
    delivery_code VARCHAR(20),
    box_qr_code VARCHAR(50),
    delivery_code_confirmed TINYINT(1) DEFAULT 0,
    
    -- Multi-entrega
    route_id INT DEFAULT NULL,
    route_position INT DEFAULT NULL,
    
    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    delivered_at DATETIME DEFAULT NULL,
    
    INDEX idx_customer (customer_id),
    INDEX idx_partner (partner_id),
    INDEX idx_status (status),
    INDEX idx_shopper (shopper_id),
    INDEX idx_delivery (delivery_id)
)");

// Tabela de itens do pedido
$pdo->exec("CREATE TABLE IF NOT EXISTS om_sim_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(200),
    quantity INT DEFAULT 1,
    unit_price DECIMAL(10,2),
    total_price DECIMAL(10,2),
    status ENUM('pendente','coletado','substituido','indisponivel') DEFAULT 'pendente',
    substitution_id INT DEFAULT NULL,
    substitution_approved TINYINT(1) DEFAULT NULL,
    picked_at DATETIME DEFAULT NULL,
    INDEX idx_order (order_id)
)");

// Tabela de chat
$pdo->exec("CREATE TABLE IF NOT EXISTS om_sim_chat (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    sender_type ENUM('customer','shopper','delivery','system') NOT NULL,
    sender_id INT DEFAULT 0,
    sender_name VARCHAR(100),
    message TEXT,
    message_type ENUM('text','image','audio','location','product') DEFAULT 'text',
    is_read TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order (order_id)
)");

// Tabela de ofertas (dispatch)
$pdo->exec("CREATE TABLE IF NOT EXISTS om_sim_offers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    worker_type ENUM('shopper','delivery') NOT NULL,
    worker_id INT NOT NULL,
    status ENUM('pending','accepted','rejected','expired') DEFAULT 'pending',
    score DECIMAL(5,2) DEFAULT 0,
    distancia_km DECIMAL(5,2) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME DEFAULT NULL,
    response_time_seconds INT DEFAULT NULL,
    INDEX idx_order (order_id),
    INDEX idx_worker (worker_type, worker_id)
)");

// Tabela de rotas (multi-entrega)
$pdo->exec("CREATE TABLE IF NOT EXISTS om_sim_routes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worker_type ENUM('shopper','delivery') NOT NULL,
    worker_id INT NOT NULL,
    status ENUM('active','completed','cancelled') DEFAULT 'active',
    total_orders INT DEFAULT 0,
    completed_orders INT DEFAULT 0,
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME DEFAULT NULL,
    INDEX idx_worker (worker_type, worker_id)
)");

echo "✅ Tabelas criadas!\n\n";

// ══════════════════════════════════════════════════════════════════════════════
// PARTE 2: CRIAR MERCADOS COM COORDENADAS
// ══════════════════════════════════════════════════════════════════════════════

echo "🏪 PARTE 2: Criando mercados...\n\n";

// Limpar dados antigos
$pdo->exec("DELETE FROM om_market_partners WHERE name LIKE 'Mercado%' OR name LIKE 'Super%' OR name LIKE 'Hiper%'");

$mercados = [
    [
        'id' => 100,
        'name' => 'Mercado Central GV',
        'city' => 'Governador Valadares',
        'state' => 'MG',
        'cep' => '35010000',
        'address' => 'Av. Brasil, 1000 - Centro',
        'lat' => -18.8512,
        'lng' => -41.9455,
        'radius' => 15,
        'fee' => 9.90,
        'min' => 30.00,
        'tempo_min' => 30,
        'tempo_max' => 50
    ],
    [
        'id' => 101,
        'name' => 'Supermercado Economia GV',
        'city' => 'Governador Valadares',
        'state' => 'MG',
        'cep' => '35020000',
        'address' => 'Rua Israel Pinheiro, 500',
        'lat' => -18.8620,
        'lng' => -41.9380,
        'radius' => 12,
        'fee' => 7.90,
        'min' => 25.00,
        'tempo_min' => 25,
        'tempo_max' => 45
    ],
    [
        'id' => 102,
        'name' => 'Mercado Express Paulista',
        'city' => 'São Paulo',
        'state' => 'SP',
        'cep' => '01310100',
        'address' => 'Av. Paulista, 1500',
        'lat' => -23.5629,
        'lng' => -46.6544,
        'radius' => 8,
        'fee' => 14.90,
        'min' => 50.00,
        'tempo_min' => 20,
        'tempo_max' => 40
    ],
    [
        'id' => 103,
        'name' => 'Super Moema',
        'city' => 'São Paulo',
        'state' => 'SP',
        'cep' => '04077000',
        'address' => 'Av. Ibirapuera, 2000',
        'lat' => -23.6010,
        'lng' => -46.6650,
        'radius' => 6,
        'fee' => 12.90,
        'min' => 40.00,
        'tempo_min' => 25,
        'tempo_max' => 45
    ],
    [
        'id' => 104,
        'name' => 'Hiper BH Savassi',
        'city' => 'Belo Horizonte',
        'state' => 'MG',
        'cep' => '30130000',
        'address' => 'Av. Afonso Pena, 1000',
        'lat' => -19.9245,
        'lng' => -43.9352,
        'radius' => 10,
        'fee' => 10.90,
        'min' => 35.00,
        'tempo_min' => 30,
        'tempo_max' => 55
    ],
];

foreach ($mercados as $m) {
    $pdo->exec("INSERT INTO om_market_partners 
        (partner_id, name, city, state, cep, address, lat, lng, delivery_radius, delivery_fee, min_order, delivery_time_min, delivery_time_max, status, is_open) 
        VALUES ({$m['id']}, '{$m['name']}', '{$m['city']}', '{$m['state']}', '{$m['cep']}', '{$m['address']}', {$m['lat']}, {$m['lng']}, {$m['radius']}, {$m['fee']}, {$m['min']}, {$m['tempo_min']}, {$m['tempo_max']}, 1, 1)");
    echo "✅ {$m['name']} ({$m['city']})\n";
}

echo "\n";

// ══════════════════════════════════════════════════════════════════════════════
// PARTE 3: CRIAR PRODUTOS POR MERCADO (PREÇOS DIFERENTES)
// ══════════════════════════════════════════════════════════════════════════════

echo "🛒 PARTE 3: Criando produtos por mercado...\n\n";

$pdo->exec("DELETE FROM om_sim_products");

// Produtos base com variação de preço por mercado
$produtosBase = [
    // Frutas
    ['cat' => 'Frutas', 'name' => 'Banana Prata', 'unit' => 'kg', 'base' => 5.99],
    ['cat' => 'Frutas', 'name' => 'Maçã Fuji', 'unit' => 'kg', 'base' => 12.90],
    ['cat' => 'Frutas', 'name' => 'Laranja Pera', 'unit' => 'kg', 'base' => 4.99],
    ['cat' => 'Frutas', 'name' => 'Mamão Formosa', 'unit' => 'kg', 'base' => 7.50],
    ['cat' => 'Frutas', 'name' => 'Uva Itália', 'unit' => 'kg', 'base' => 15.90],
    
    // Carnes
    ['cat' => 'Carnes', 'name' => 'Picanha Bovina', 'unit' => 'kg', 'base' => 79.90],
    ['cat' => 'Carnes', 'name' => 'Frango Inteiro', 'unit' => 'kg', 'base' => 14.90],
    ['cat' => 'Carnes', 'name' => 'Carne Moída', 'unit' => 'kg', 'base' => 32.90],
    ['cat' => 'Carnes', 'name' => 'Linguiça Toscana', 'unit' => 'kg', 'base' => 24.90],
    ['cat' => 'Carnes', 'name' => 'Costela Suína', 'unit' => 'kg', 'base' => 29.90],
    
    // Laticínios
    ['cat' => 'Laticínios', 'name' => 'Leite Integral 1L', 'unit' => 'un', 'base' => 5.49],
    ['cat' => 'Laticínios', 'name' => 'Queijo Mussarela', 'unit' => 'kg', 'base' => 44.90],
    ['cat' => 'Laticínios', 'name' => 'Iogurte Natural', 'unit' => 'un', 'base' => 6.90],
    ['cat' => 'Laticínios', 'name' => 'Manteiga 200g', 'unit' => 'un', 'base' => 12.90],
    ['cat' => 'Laticínios', 'name' => 'Requeijão 200g', 'unit' => 'un', 'base' => 8.50],
    
    // Bebidas
    ['cat' => 'Bebidas', 'name' => 'Coca-Cola 2L', 'unit' => 'un', 'base' => 10.90],
    ['cat' => 'Bebidas', 'name' => 'Água Mineral 1.5L', 'unit' => 'un', 'base' => 3.50],
    ['cat' => 'Bebidas', 'name' => 'Suco Natural 1L', 'unit' => 'un', 'base' => 9.90],
    ['cat' => 'Bebidas', 'name' => 'Cerveja Heineken', 'unit' => 'un', 'base' => 6.90],
    ['cat' => 'Bebidas', 'name' => 'Vinho Tinto', 'unit' => 'un', 'base' => 35.90],
    
    // Padaria
    ['cat' => 'Padaria', 'name' => 'Pão Francês', 'unit' => 'kg', 'base' => 14.90],
    ['cat' => 'Padaria', 'name' => 'Pão de Forma', 'unit' => 'un', 'base' => 8.90],
    ['cat' => 'Padaria', 'name' => 'Bolo de Chocolate', 'unit' => 'un', 'base' => 25.90],
    ['cat' => 'Padaria', 'name' => 'Croissant', 'unit' => 'un', 'base' => 5.50],
    
    // Mercearia
    ['cat' => 'Mercearia', 'name' => 'Arroz 5kg', 'unit' => 'un', 'base' => 28.90],
    ['cat' => 'Mercearia', 'name' => 'Feijão Carioca 1kg', 'unit' => 'un', 'base' => 8.90],
    ['cat' => 'Mercearia', 'name' => 'Macarrão 500g', 'unit' => 'un', 'base' => 5.50],
    ['cat' => 'Mercearia', 'name' => 'Óleo de Soja 900ml', 'unit' => 'un', 'base' => 7.90],
    ['cat' => 'Mercearia', 'name' => 'Açúcar 1kg', 'unit' => 'un', 'base' => 5.90],
    ['cat' => 'Mercearia', 'name' => 'Café 500g', 'unit' => 'un', 'base' => 18.90],
    
    // Limpeza
    ['cat' => 'Limpeza', 'name' => 'Detergente 500ml', 'unit' => 'un', 'base' => 2.99],
    ['cat' => 'Limpeza', 'name' => 'Sabão em Pó 1kg', 'unit' => 'un', 'base' => 15.90],
    ['cat' => 'Limpeza', 'name' => 'Água Sanitária 2L', 'unit' => 'un', 'base' => 6.90],
    ['cat' => 'Limpeza', 'name' => 'Desinfetante 2L', 'unit' => 'un', 'base' => 9.90],
    
    // Higiene
    ['cat' => 'Higiene', 'name' => 'Papel Higiênico 12un', 'unit' => 'un', 'base' => 22.90],
    ['cat' => 'Higiene', 'name' => 'Shampoo 400ml', 'unit' => 'un', 'base' => 18.90],
    ['cat' => 'Higiene', 'name' => 'Sabonete 90g', 'unit' => 'un', 'base' => 3.50],
    ['cat' => 'Higiene', 'name' => 'Creme Dental', 'unit' => 'un', 'base' => 6.90],
];

// Variação de preço por mercado (multiplicador)
$variacaoPreco = [
    100 => 1.00,   // GV Central - preço base
    101 => 0.92,   // GV Economia - 8% mais barato
    102 => 1.25,   // SP Paulista - 25% mais caro
    103 => 1.15,   // SP Moema - 15% mais caro
    104 => 1.08,   // BH - 8% mais caro
];

$totalProdutos = 0;
foreach ($mercados as $m) {
    $multiplicador = $variacaoPreco[$m['id']];
    $produtosInseridos = 0;
    
    foreach ($produtosBase as $p) {
        // Alguns produtos podem não estar disponíveis em alguns mercados
        if (rand(0, 100) < 10) continue; // 10% chance de não ter
        
        $preco = round($p['base'] * $multiplicador, 2);
        
        // Alguns com promoção
        $temPromo = rand(0, 100) < 20;
        $promoPreco = $temPromo ? round($preco * 0.85, 2) : null;
        
        $promoSql = $promoPreco ? $promoPreco : 'NULL';
        
        $pdo->exec("INSERT INTO om_sim_products (partner_id, name, category, price, special_price, unit, stock)
                    VALUES ({$m['id']}, '{$p['name']}', '{$p['cat']}', $preco, $promoSql, '{$p['unit']}', " . rand(50, 200) . ")");
        $produtosInseridos++;
    }
    
    echo "   🏪 {$m['name']}: $produtosInseridos produtos\n";
    $totalProdutos += $produtosInseridos;
}

echo "\n   Total: $totalProdutos produtos criados\n\n";

// ══════════════════════════════════════════════════════════════════════════════
// PARTE 4: CRIAR SHOPPERS E DELIVERYS
// ══════════════════════════════════════════════════════════════════════════════

echo "👷 PARTE 4: Criando workers...\n\n";

$nomes = ['João', 'Maria', 'Pedro', 'Ana', 'Carlos', 'Julia', 'Lucas', 'Fernanda', 'Bruno', 'Camila'];
$sobrenomes = ['Silva', 'Santos', 'Oliveira', 'Souza', 'Lima', 'Costa', 'Rodrigues', 'Almeida'];

// Limpar workers antigos de simulação
$pdo->exec("DELETE FROM om_market_shoppers WHERE email LIKE '%@sim.%'");
$pdo->exec("DELETE FROM om_market_delivery WHERE phone LIKE '11999%'");

// Shoppers por cidade
$cidadesWorkers = [
    ['city' => 'Governador Valadares', 'lat' => -18.8512, 'lng' => -41.9455, 'shoppers' => 6, 'deliverys' => 4],
    ['city' => 'São Paulo', 'lat' => -23.5629, 'lng' => -46.6544, 'shoppers' => 8, 'deliverys' => 6],
    ['city' => 'Belo Horizonte', 'lat' => -19.9245, 'lng' => -43.9352, 'shoppers' => 4, 'deliverys' => 3],
];

$totalShoppers = 0;
$totalDeliverys = 0;

foreach ($cidadesWorkers as $c) {
    // Shoppers
    for ($i = 0; $i < $c['shoppers']; $i++) {
        $nome = $nomes[array_rand($nomes)] . ' ' . $sobrenomes[array_rand($sobrenomes)];
        $email = 'shopper' . ($totalShoppers + 1) . '@sim.onemundo.com';
        $lat = $c['lat'] + (rand(-40, 40) / 1000);
        $lng = $c['lng'] + (rand(-40, 40) / 1000);
        $online = rand(0, 100) < 70;
        $rating = rand(40, 50) / 10;
        
        $pdo->exec("INSERT INTO om_market_shoppers 
            (name, email, phone, city, status, is_online, is_busy, current_lat, current_lng, rating, accept_rate, avg_accept_time)
            VALUES ('$nome', '$email', '119" . rand(10000000, 99999999) . "', '{$c['city']}', 
                    '" . ($online ? 'online' : 'offline') . "', " . ($online ? 1 : 0) . ", 0, $lat, $lng, $rating, " . rand(70, 100) . ", " . rand(15, 45) . ")");
        $totalShoppers++;
    }
    
    // Deliverys
    $veiculos = ['moto', 'moto', 'bike', 'carro'];
    for ($i = 0; $i < $c['deliverys']; $i++) {
        $nome = $nomes[array_rand($nomes)];
        $veiculo = $veiculos[array_rand($veiculos)];
        $lat = $c['lat'] + (rand(-30, 30) / 1000);
        $lng = $c['lng'] + (rand(-30, 30) / 1000);
        $online = rand(0, 100) < 60;
        
        $pdo->exec("INSERT INTO om_market_delivery 
            (name, phone, city, vehicle, status, is_online, current_lat, current_lng, rating, accept_rate, avg_accept_time)
            VALUES ('$nome', '11999" . rand(100000, 999999) . "', '{$c['city']}', '$veiculo',
                    '" . ($online ? 'online' : 'offline') . "', " . ($online ? 1 : 0) . ", $lat, $lng, " . (rand(40, 50) / 10) . ", " . rand(75, 100) . ", " . rand(10, 30) . ")");
        $totalDeliverys++;
    }
    
    echo "   📍 {$c['city']}: {$c['shoppers']} shoppers, {$c['deliverys']} deliverys\n";
}

echo "\n   Total: $totalShoppers shoppers, $totalDeliverys deliverys\n\n";

// ══════════════════════════════════════════════════════════════════════════════
// PARTE 5: CRIAR CLIENTES
// ══════════════════════════════════════════════════════════════════════════════

echo "👤 PARTE 5: Criando clientes...\n\n";

$pdo->exec("DELETE FROM om_sim_customers");

$clientes = [
    // GV - dentro do raio
    ['name' => 'Carlos Centro', 'city' => 'Governador Valadares', 'cep' => '35010010', 'lat' => -18.8530, 'lng' => -41.9470, 'address' => 'Rua Sete de Setembro, 100'],
    ['name' => 'Maria Vila Bretas', 'city' => 'Governador Valadares', 'cep' => '35020100', 'lat' => -18.8600, 'lng' => -41.9400, 'address' => 'Av. JK, 500'],
    ['name' => 'João São Paulo', 'city' => 'Governador Valadares', 'cep' => '35030050', 'lat' => -18.8450, 'lng' => -41.9500, 'address' => 'Rua Barão do Rio Branco, 200'],
    // GV - fora do raio
    ['name' => 'Pedro Longe', 'city' => 'Governador Valadares', 'cep' => '35100000', 'lat' => -19.0000, 'lng' => -42.1000, 'address' => 'Rua Distante, 999'],
    
    // SP - dentro do raio
    ['name' => 'Ana Paulista', 'city' => 'São Paulo', 'cep' => '01310100', 'lat' => -23.5650, 'lng' => -46.6550, 'address' => 'Av. Paulista, 1000'],
    ['name' => 'Bruno Moema', 'city' => 'São Paulo', 'cep' => '04077100', 'lat' => -23.6000, 'lng' => -46.6600, 'address' => 'Rua dos Bandeirantes, 300'],
    ['name' => 'Julia Pinheiros', 'city' => 'São Paulo', 'cep' => '05422000', 'lat' => -23.5670, 'lng' => -46.6900, 'address' => 'Rua dos Pinheiros, 800'],
    // SP - fora do raio
    ['name' => 'Lucas Guarulhos', 'city' => 'Guarulhos', 'cep' => '07000000', 'lat' => -23.4500, 'lng' => -46.5300, 'address' => 'Av. Guarulhos, 100'],
    
    // BH
    ['name' => 'Fernanda Savassi', 'city' => 'Belo Horizonte', 'cep' => '30130000', 'lat' => -19.9350, 'lng' => -43.9300, 'address' => 'Praça da Savassi, 50'],
    ['name' => 'Camila Funcionários', 'city' => 'Belo Horizonte', 'cep' => '30140000', 'lat' => -19.9400, 'lng' => -43.9250, 'address' => 'Rua Cláudio Manoel, 400'],
    // BH - fora
    ['name' => 'Rafael Contagem', 'city' => 'Contagem', 'cep' => '32000000', 'lat' => -19.9300, 'lng' => -44.0500, 'address' => 'Av. João César, 200'],
    
    // Sem mercado
    ['name' => 'Gabriel Ipatinga', 'city' => 'Ipatinga', 'cep' => '35160000', 'lat' => -19.4700, 'lng' => -42.5400, 'address' => 'Rua Sem Mercado, 1'],
];

foreach ($clientes as $c) {
    $email = strtolower(str_replace(' ', '.', $c['name'])) . '@cliente.com';
    $pdo->exec("INSERT INTO om_sim_customers (name, email, phone, city, cep, lat, lng, address) 
                VALUES ('{$c['name']}', '$email', '119" . rand(10000000, 99999999) . "', '{$c['city']}', '{$c['cep']}', {$c['lat']}, {$c['lng']}, '{$c['address']}')");
    echo "   👤 {$c['name']} - {$c['city']}\n";
}

echo "\n";

// ══════════════════════════════════════════════════════════════════════════════
// RESUMO
// ══════════════════════════════════════════════════════════════════════════════

$stats = [
    'mercados' => $pdo->query("SELECT COUNT(*) FROM om_market_partners WHERE status = '1'")->fetchColumn(),
    'produtos' => $pdo->query("SELECT COUNT(*) FROM om_sim_products")->fetchColumn(),
    'shoppers_online' => $pdo->query("SELECT COUNT(*) FROM om_market_shoppers WHERE is_online = 1")->fetchColumn(),
    'shoppers_total' => $pdo->query("SELECT COUNT(*) FROM om_market_shoppers")->fetchColumn(),
    'deliverys_online' => $pdo->query("SELECT COUNT(*) FROM om_market_delivery WHERE is_online = 1")->fetchColumn(),
    'deliverys_total' => $pdo->query("SELECT COUNT(*) FROM om_market_delivery")->fetchColumn(),
    'clientes' => $pdo->query("SELECT COUNT(*) FROM om_sim_customers")->fetchColumn(),
];

echo "═══════════════════════════════════════════════════════\n";
echo "🎉 SETUP COMPLETO!\n";
echo "═══════════════════════════════════════════════════════\n\n";

echo "📊 RESUMO:\n";
echo "   🏪 Mercados: {$stats['mercados']}\n";
echo "   🛒 Produtos: {$stats['produtos']} (preços variam por mercado)\n";
echo "   👷 Shoppers: 🟢 {$stats['shoppers_online']} online | Total: {$stats['shoppers_total']}\n";
echo "   🚴 Deliverys: 🟢 {$stats['deliverys_online']} online | Total: {$stats['deliverys_total']}\n";
echo "   👤 Clientes: {$stats['clientes']}\n";

echo "\n</pre>";

echo "<div style='text-align:center;padding:20px;'>";
echo "<a href='SIM_MERCADO.php' style='display:inline-block;padding:16px 32px;background:#10b981;color:#fff;text-decoration:none;border-radius:12px;font-weight:bold;font-size:18px;margin:8px;'>🛒 Entrar como Cliente</a>";
echo "<a href='SIM_SHOPPER.php' style='display:inline-block;padding:16px 32px;background:#f59e0b;color:#fff;text-decoration:none;border-radius:12px;font-weight:bold;font-size:18px;margin:8px;'>👷 Entrar como Shopper</a>";
echo "<a href='SIM_DELIVERY.php' style='display:inline-block;padding:16px 32px;background:#3b82f6;color:#fff;text-decoration:none;border-radius:12px;font-weight:bold;font-size:18px;margin:8px;'>🚴 Entrar como Delivery</a>";
echo "<a href='SIM_PAINEL.php' style='display:inline-block;padding:16px 32px;background:#8b5cf6;color:#fff;text-decoration:none;border-radius:12px;font-weight:bold;font-size:18px;margin:8px;'>🎮 Painel de Controle</a>";
echo "</div>";
