<?php
require_once __DIR__ . '/config/database.php';
/**
 * DIAGNÓSTICO E FIX - TABELAS STRESS TEST
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');

try {
    $pdo = getPDO();
} catch (Exception $e) {
    die("Erro DB: " . $e->getMessage());
}

echo "<html><head><meta charset='UTF-8'><title>Fix Tabelas</title>";
echo "<style>
body { font-family: monospace; background: #1a1a2e; color: #0f0; padding: 20px; }
h1, h2 { color: #00d4aa; }
.box { background: #0a0a15; padding: 15px; border-radius: 8px; margin: 15px 0; }
.ok { color: #00d4aa; }
.erro { color: #ff6b6b; }
.aviso { color: #ffc107; }
.btn { display: inline-block; padding: 15px 30px; background: #00d4aa; color: #000; text-decoration: none; border-radius: 8px; font-weight: bold; margin: 10px 5px; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 10px; text-align: left; border-bottom: 1px solid #333; }
th { color: #00d4aa; }
</style></head><body>";

echo "<h1>🔧 Diagnóstico e Fix - Tabelas Stress Test</h1>";

// ═══════════════════════════════════════════════════════════════════════════
// 1. VERIFICAR TABELAS EXISTENTES
// ═══════════════════════════════════════════════════════════════════════════
echo "<div class='box'>";
echo "<h2>1️⃣ Tabelas Necessárias</h2>";

$tabelas = [
    'om_market_partners' => 'Mercados parceiros',
    'om_market_products_base' => 'Produtos base',
    'om_market_products_price' => 'Preços por mercado',
    'om_market_shoppers' => 'Shoppers',
    'om_market_drivers' => 'Drivers',
    'om_stress_test_customers' => 'Clientes de teste',
    'om_stress_test_orders' => 'Pedidos de teste',
    'om_stress_test_order_items' => 'Itens dos pedidos',
];

echo "<table>";
echo "<tr><th>Tabela</th><th>Descrição</th><th>Status</th><th>Registros</th></tr>";

foreach ($tabelas as $tabela => $desc) {
    $existe = false;
    $registros = 0;
    
    try {
        $result = $pdo->query("SELECT COUNT(*) FROM $tabela");
        $registros = $result->fetchColumn();
        $existe = true;
    } catch (Exception $e) {
        $existe = false;
    }
    
    $status = $existe ? "<span class='ok'>✅ Existe</span>" : "<span class='erro'>❌ Não existe</span>";
    $regs = $existe ? $registros : '-';
    
    echo "<tr><td>$tabela</td><td>$desc</td><td>$status</td><td>$regs</td></tr>";
}

echo "</table>";
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════
// 2. CRIAR/CORRIGIR TABELAS
// ═══════════════════════════════════════════════════════════════════════════
if (isset($_GET['criar'])) {
    echo "<div class='box'>";
    echo "<h2>🔧 Criando Tabelas...</h2>";
    
    $sqls = [
        'om_market_products_base' => "
            CREATE TABLE IF NOT EXISTS om_market_products_base (
                product_id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                category VARCHAR(100),
                barcode VARCHAR(50),
                image VARCHAR(500),
                status TINYINT DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_name (name),
                INDEX idx_category (category)
            )
        ",
        'om_market_products_price' => "
            CREATE TABLE IF NOT EXISTS om_market_products_price (
                price_id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                partner_id INT NOT NULL,
                price DECIMAL(10,2) NOT NULL,
                price_promo DECIMAL(10,2) DEFAULT 0,
                stock INT DEFAULT 0,
                status TINYINT DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_product_partner (product_id, partner_id),
                INDEX idx_partner (partner_id)
            )
        ",
        'om_market_shoppers' => "
            CREATE TABLE IF NOT EXISTS om_market_shoppers (
                shopper_id INT AUTO_INCREMENT PRIMARY KEY,
                nome VARCHAR(100),
                email VARCHAR(100),
                telefone VARCHAR(20),
                cidade VARCHAR(100),
                latitude DECIMAL(10,7),
                longitude DECIMAL(10,7),
                is_online TINYINT DEFAULT 0,
                rating DECIMAL(3,2) DEFAULT 5.00,
                total_orders INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cidade (cidade),
                INDEX idx_online (is_online)
            )
        ",
        'om_market_drivers' => "
            CREATE TABLE IF NOT EXISTS om_market_drivers (
                driver_id INT AUTO_INCREMENT PRIMARY KEY,
                nome VARCHAR(100),
                email VARCHAR(100),
                telefone VARCHAR(20),
                cidade VARCHAR(100),
                latitude DECIMAL(10,7),
                longitude DECIMAL(10,7),
                is_online TINYINT DEFAULT 0,
                rating DECIMAL(3,2) DEFAULT 5.00,
                total_deliveries INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cidade (cidade),
                INDEX idx_online (is_online)
            )
        ",
        'om_stress_test_customers' => "
            CREATE TABLE IF NOT EXISTS om_stress_test_customers (
                customer_id INT AUTO_INCREMENT PRIMARY KEY,
                nome VARCHAR(100),
                email VARCHAR(100),
                telefone VARCHAR(20),
                cep VARCHAR(10),
                cidade VARCHAR(100),
                latitude DECIMAL(10,7),
                longitude DECIMAL(10,7),
                partner_id INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cep (cep),
                INDEX idx_partner (partner_id)
            )
        ",
        'om_stress_test_orders' => "
            CREATE TABLE IF NOT EXISTS om_stress_test_orders (
                order_id INT AUTO_INCREMENT PRIMARY KEY,
                customer_id INT,
                partner_id INT,
                total DECIMAL(10,2),
                status VARCHAR(50) DEFAULT 'pending',
                shopper_id INT,
                driver_id INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_customer (customer_id),
                INDEX idx_partner (partner_id),
                INDEX idx_status (status)
            )
        ",
        'om_stress_test_order_items' => "
            CREATE TABLE IF NOT EXISTS om_stress_test_order_items (
                item_id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT,
                product_id INT,
                product_name VARCHAR(200),
                quantity INT,
                price DECIMAL(10,2),
                INDEX idx_order (order_id)
            )
        ",
    ];
    
    foreach ($sqls as $tabela => $sql) {
        try {
            $pdo->exec($sql);
            echo "<p class='ok'>✅ $tabela criada/verificada</p>";
        } catch (Exception $e) {
            echo "<p class='erro'>❌ $tabela: " . $e->getMessage() . "</p>";
        }
    }
    
    echo "</div>";
}

// ═══════════════════════════════════════════════════════════════════════════
// 3. VERIFICAR MERCADOS
// ═══════════════════════════════════════════════════════════════════════════
echo "<div class='box'>";
echo "<h2>2️⃣ Mercados Cadastrados</h2>";

try {
    $mercados = $pdo->query("SELECT partner_id, name, city, latitude, longitude, raio_entrega_km, status FROM om_market_partners")->fetchAll();
    
    if (count($mercados) > 0) {
        echo "<table>";
        echo "<tr><th>ID</th><th>Nome</th><th>Cidade</th><th>Lat/Lng</th><th>Raio</th><th>Status</th></tr>";
        
        foreach ($mercados as $m) {
            $status = $m['status'] == 1 ? "<span class='ok'>Ativo</span>" : "<span class='erro'>Inativo</span>";
            $coords = $m['latitude'] && $m['longitude'] ? round($m['latitude'], 4) . ', ' . round($m['longitude'], 4) : "<span class='aviso'>Não tem</span>";
            echo "<tr>";
            echo "<td>{$m['partner_id']}</td>";
            echo "<td>{$m['name']}</td>";
            echo "<td>{$m['city']}</td>";
            echo "<td>$coords</td>";
            echo "<td>" . ($m['raio_entrega_km'] ?? 10) . " km</td>";
            echo "<td>$status</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='aviso'>⚠️ Nenhum mercado cadastrado!</p>";
    }
} catch (Exception $e) {
    echo "<p class='erro'>Erro: " . $e->getMessage() . "</p>";
}

echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════
// 4. VERIFICAR PRODUTOS
// ═══════════════════════════════════════════════════════════════════════════
echo "<div class='box'>";
echo "<h2>3️⃣ Produtos por Mercado</h2>";

try {
    // Verificar se tabela existe
    $temTabela = false;
    try {
        $pdo->query("SELECT 1 FROM om_market_products_price LIMIT 1");
        $temTabela = true;
    } catch (Exception $e) {}
    
    if ($temTabela) {
        $stmt = $pdo->query("
            SELECT mp.partner_id, mp.name, 
                   COUNT(pp.price_id) as total_produtos,
                   MIN(pp.price) as preco_min,
                   MAX(pp.price) as preco_max
            FROM om_market_partners mp
            LEFT JOIN om_market_products_price pp ON mp.partner_id = pp.partner_id
            WHERE mp.status = 1
            GROUP BY mp.partner_id
        ");
        
        echo "<table>";
        echo "<tr><th>Mercado</th><th>Produtos</th><th>Preço Min</th><th>Preço Max</th></tr>";
        
        while ($row = $stmt->fetch()) {
            echo "<tr>";
            echo "<td>{$row['name']}</td>";
            echo "<td>" . ($row['total_produtos'] ?: "<span class='aviso'>0</span>") . "</td>";
            echo "<td>" . ($row['preco_min'] ? 'R$ ' . number_format($row['preco_min'], 2) : '-') . "</td>";
            echo "<td>" . ($row['preco_max'] ? 'R$ ' . number_format($row['preco_max'], 2) : '-') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='aviso'>⚠️ Tabela de preços não existe. Clique em 'Criar Tabelas'</p>";
    }
} catch (Exception $e) {
    echo "<p class='erro'>Erro: " . $e->getMessage() . "</p>";
}

echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════
// AÇÕES
// ═══════════════════════════════════════════════════════════════════════════
echo "<div class='box' style='text-align:center'>";
echo "<a href='?criar=1' class='btn'>🔧 Criar/Corrigir Tabelas</a>";
echo "<a href='stress_test_mercado.php' class='btn'>🚀 Ir para Stress Test</a>";
echo "<a href='diagnostico_vinculacao.php' class='btn'>🎯 Diagnóstico Vinculação</a>";
echo "</div>";

echo "</body></html>";
