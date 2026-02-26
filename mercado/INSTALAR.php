<?php
require_once __DIR__ . '/config/database.php';
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * 🚀 MEGA INSTALADOR - ONEMUNDO MERCADO COMPLETO
 * ══════════════════════════════════════════════════════════════════════════════
 * 
 * Este instalador configura TODO o sistema:
 * ✅ Estrutura de tabelas (pedidos, produtos, ofertas, chat, rotas)
 * ✅ Pagar.me integração (PIX com pré-autorização)
 * ✅ Sistema de dispatch inteligente (GPS, rating, velocidade)
 * ✅ Chat em tempo real
 * ✅ Multi-entrega (double/triple)
 * ✅ Código de entrega (palavra passe)
 * ✅ Comissões (shopper, delivery, plataforma)
 * ✅ Dados de teste (mercados, shoppers, deliverys, clientes, produtos)
 * 
 * @author OneMundo Team
 * @version 2.0
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(300);

$startTime = microtime(true);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>🚀 MEGA INSTALADOR</title>";
echo "<style>
body { font-family: 'Segoe UI', sans-serif; background: linear-gradient(135deg, #0f172a, #1e293b); color: #fff; padding: 20px; min-height: 100vh; }
.container { max-width: 900px; margin: 0 auto; }
h1 { text-align: center; font-size: 28px; margin-bottom: 8px; }
.subtitle { text-align: center; color: #64748b; margin-bottom: 30px; }
.section { background: #1e293b; border-radius: 16px; padding: 20px; margin-bottom: 20px; border-left: 4px solid #3b82f6; }
.section h2 { font-size: 18px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
.log { font-family: monospace; font-size: 13px; line-height: 1.8; }
.ok { color: #10b981; }
.warn { color: #f59e0b; }
.err { color: #ef4444; }
.skip { color: #64748b; }
.summary { background: linear-gradient(135deg, #10b981, #059669); border-radius: 16px; padding: 24px; text-align: center; margin-top: 30px; }
.summary h2 { margin-bottom: 16px; }
.stats { display: flex; justify-content: center; gap: 30px; flex-wrap: wrap; margin: 20px 0; }
.stat { text-align: center; }
.stat .value { font-size: 32px; font-weight: 800; }
.stat .label { font-size: 13px; opacity: 0.9; }
.links { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; margin-top: 20px; }
.links a { display: inline-block; padding: 12px 24px; background: rgba(255,255,255,0.2); color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600; }
.links a:hover { background: rgba(255,255,255,0.3); }
</style></head><body><div class='container'>";

echo "<h1>🚀 MEGA INSTALADOR</h1>";
echo "<p class='subtitle'>OneMundo Mercado - Sistema Completo</p>";

// ══════════════════════════════════════════════════════════════════════════════
// CONEXÃO
// ══════════════════════════════════════════════════════════════════════════════

try {
    $pdo = getPDO();
} catch (Exception $e) {
    die("<div class='section'><p class='err'>❌ Erro de conexão: " . $e->getMessage() . "</p></div>");
}

$logs = [];
$stats = [
    'tabelas' => 0,
    'colunas' => 0,
    'mercados' => 0,
    'produtos' => 0,
    'shoppers' => 0,
    'deliverys' => 0,
    'clientes' => 0
];

function logOk($msg) { global $logs; $logs[] = "<span class='ok'>✅</span> $msg"; }
function logWarn($msg) { global $logs; $logs[] = "<span class='warn'>⚠️</span> $msg"; }
function logErr($msg) { global $logs; $logs[] = "<span class='err'>❌</span> $msg"; }
function logSkip($msg) { global $logs; $logs[] = "<span class='skip'>⏭️</span> $msg"; }

function execSQL($pdo, $sql, $desc = '') {
    global $stats;
    try {
        $pdo->exec($sql);
        if (stripos($sql, 'CREATE TABLE') !== false) {
            $stats['tabelas']++;
            logOk("Tabela criada: $desc");
        } elseif (stripos($sql, 'ALTER TABLE') !== false && stripos($sql, 'ADD COLUMN') !== false) {
            $stats['colunas']++;
        }
        return true;
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            logSkip("Já existe: $desc");
            return true;
        }
        logErr("Erro em $desc: " . $e->getMessage());
        return false;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// PARTE 1: ESTRUTURA DE TABELAS
// ══════════════════════════════════════════════════════════════════════════════

echo "<div class='section'><h2>📦 PARTE 1: Estrutura de Tabelas</h2><div class='log'>";

// Tabela de pedidos principal
execSQL($pdo, "CREATE TABLE IF NOT EXISTS om_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) NOT NULL UNIQUE,
    customer_id INT NOT NULL,
    partner_id INT NOT NULL,
    
    -- Status
    status ENUM('aguardando_pagamento','pago','aguardando_shopper','shopper_aceito','em_compra','compra_finalizada','aguardando_delivery','delivery_aceito','em_entrega','entregue','cancelado') DEFAULT 'aguardando_pagamento',
    
    -- Valores
    subtotal DECIMAL(10,2) DEFAULT 0,
    delivery_fee DECIMAL(10,2) DEFAULT 0,
    discount DECIMAL(10,2) DEFAULT 0,
    total DECIMAL(10,2) DEFAULT 0,
    platform_fee DECIMAL(10,2) DEFAULT 0,
    
    -- Pagamento Pagar.me
    payment_method VARCHAR(20) DEFAULT 'pix',
    payment_status VARCHAR(20) DEFAULT 'pendente',
    pagarme_order_id VARCHAR(50),
    pagarme_charge_id VARCHAR(50),
    pagarme_transaction_id VARCHAR(50),
    pix_qr_code TEXT,
    pix_qr_code_url VARCHAR(500),
    pix_expires_at DATETIME,
    paid_at DATETIME,
    
    -- Endereço entrega
    delivery_address TEXT,
    delivery_lat DECIMAL(10,8),
    delivery_lng DECIMAL(11,8),
    delivery_complement VARCHAR(255),
    delivery_reference VARCHAR(255),
    
    -- Shopper
    shopper_id INT DEFAULT NULL,
    shopper_earning DECIMAL(10,2) DEFAULT 0,
    shopper_accepted_at DATETIME,
    shopping_started_at DATETIME,
    shopping_finished_at DATETIME,
    
    -- Delivery
    delivery_id INT DEFAULT NULL,
    delivery_earning DECIMAL(10,2) DEFAULT 0,
    delivery_accepted_at DATETIME,
    delivery_started_at DATETIME,
    
    -- Códigos
    delivery_code VARCHAR(20),
    box_qr_code VARCHAR(50),
    delivery_code_confirmed TINYINT(1) DEFAULT 0,
    handoff_at DATETIME,
    
    -- Multi-entrega
    route_id INT DEFAULT NULL,
    route_position INT DEFAULT NULL,
    
    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    delivered_at DATETIME,
    cancelled_at DATETIME,
    cancel_reason TEXT,
    
    INDEX idx_customer (customer_id),
    INDEX idx_partner (partner_id),
    INDEX idx_status (status),
    INDEX idx_shopper (shopper_id),
    INDEX idx_delivery (delivery_id),
    INDEX idx_pagarme (pagarme_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "om_orders");

// Tabela de itens do pedido
execSQL($pdo, "CREATE TABLE IF NOT EXISTS om_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(255),
    product_image VARCHAR(500),
    quantity INT DEFAULT 1,
    unit_price DECIMAL(10,2),
    total_price DECIMAL(10,2),
    unit VARCHAR(20) DEFAULT 'un',
    notes TEXT,
    
    -- Status de coleta
    status ENUM('pendente','coletado','substituido','indisponivel') DEFAULT 'pendente',
    substitution_product_id INT DEFAULT NULL,
    substitution_approved TINYINT(1) DEFAULT NULL,
    picked_at DATETIME,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "om_order_items");

// Tabela de produtos por mercado
execSQL($pdo, "CREATE TABLE IF NOT EXISTS om_market_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    category VARCHAR(100),
    subcategory VARCHAR(100),
    brand VARCHAR(100),
    sku VARCHAR(50),
    barcode VARCHAR(50),
    price DECIMAL(10,2) NOT NULL,
    special_price DECIMAL(10,2) DEFAULT NULL,
    cost_price DECIMAL(10,2) DEFAULT NULL,
    unit VARCHAR(20) DEFAULT 'un',
    weight DECIMAL(8,3) DEFAULT NULL,
    stock INT DEFAULT 100,
    min_stock INT DEFAULT 10,
    image VARCHAR(500),
    images TEXT,
    is_available TINYINT(1) DEFAULT 1,
    is_featured TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_partner (partner_id),
    INDEX idx_category (category),
    INDEX idx_available (is_available)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "om_market_products");

// Tabela de ofertas (dispatch)
execSQL($pdo, "CREATE TABLE IF NOT EXISTS om_dispatch_offers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    worker_type ENUM('shopper','delivery') NOT NULL,
    worker_id INT NOT NULL,
    status ENUM('pending','accepted','rejected','expired') DEFAULT 'pending',
    
    -- Métricas de scoring
    score DECIMAL(5,2) DEFAULT 0,
    score_distancia DECIMAL(5,2) DEFAULT 0,
    score_velocidade DECIMAL(5,2) DEFAULT 0,
    score_rating DECIMAL(5,2) DEFAULT 0,
    score_taxa_aceite DECIMAL(5,2) DEFAULT 0,
    distancia_km DECIMAL(5,2) DEFAULT 0,
    tempo_estimado INT DEFAULT 0,
    
    -- Resposta
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME,
    responded_at DATETIME,
    response_time_seconds INT DEFAULT NULL,
    
    INDEX idx_order (order_id),
    INDEX idx_worker (worker_type, worker_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "om_dispatch_offers");

// Tabela de chat
execSQL($pdo, "CREATE TABLE IF NOT EXISTS om_order_chat (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    sender_type ENUM('customer','shopper','delivery','system','support') NOT NULL,
    sender_id INT DEFAULT 0,
    sender_name VARCHAR(100),
    message TEXT,
    message_type ENUM('text','image','audio','location','product','action') DEFAULT 'text',
    attachment_url VARCHAR(500),
    is_read TINYINT(1) DEFAULT 0,
    read_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order (order_id),
    INDEX idx_sender (sender_type, sender_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "om_order_chat");

// Tabela de rotas (multi-entrega)
execSQL($pdo, "CREATE TABLE IF NOT EXISTS om_delivery_routes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worker_type ENUM('shopper','delivery') NOT NULL,
    worker_id INT NOT NULL,
    status ENUM('active','completed','cancelled') DEFAULT 'active',
    total_orders INT DEFAULT 0,
    completed_orders INT DEFAULT 0,
    total_distance_km DECIMAL(6,2) DEFAULT 0,
    total_earnings DECIMAL(10,2) DEFAULT 0,
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME,
    INDEX idx_worker (worker_type, worker_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "om_delivery_routes");

// Tabela de clientes Pagar.me
execSQL($pdo, "CREATE TABLE IF NOT EXISTS om_customer_pagarme (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    pagarme_customer_id VARCHAR(50),
    name VARCHAR(200),
    email VARCHAR(200),
    phone VARCHAR(20),
    document VARCHAR(20),
    document_type VARCHAR(10) DEFAULT 'cpf',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_customer (customer_id),
    INDEX idx_pagarme (pagarme_customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "om_customer_pagarme");

// Tabela de clientes simulados
execSQL($pdo, "CREATE TABLE IF NOT EXISTS om_sim_customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    document VARCHAR(20),
    cep VARCHAR(10),
    address VARCHAR(255),
    number VARCHAR(20),
    complement VARCHAR(100),
    neighborhood VARCHAR(100),
    city VARCHAR(100),
    state VARCHAR(2),
    lat DECIMAL(10,8),
    lng DECIMAL(11,8),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "om_sim_customers");

// Tabela de webhooks Pagar.me
execSQL($pdo, "CREATE TABLE IF NOT EXISTS om_pagarme_webhooks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(100),
    event_id VARCHAR(100),
    order_id INT,
    pagarme_order_id VARCHAR(50),
    payload LONGTEXT,
    processed TINYINT(1) DEFAULT 0,
    processed_at DATETIME,
    error_message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event (event_type),
    INDEX idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "om_pagarme_webhooks");

echo "</div></div>";

// ══════════════════════════════════════════════════════════════════════════════
// PARTE 2: COLUNAS ADICIONAIS NAS TABELAS EXISTENTES
// ══════════════════════════════════════════════════════════════════════════════

echo "<div class='section'><h2>🔧 PARTE 2: Colunas Adicionais</h2><div class='log'>";

// Colunas para om_market_partners (mercados)
$colunasPartners = [
    "lat DECIMAL(10,8) DEFAULT NULL",
    "lng DECIMAL(11,8) DEFAULT NULL",
    "delivery_radius INT DEFAULT 15",
    "delivery_time_min INT DEFAULT 30",
    "delivery_time_max INT DEFAULT 60",
    "min_order DECIMAL(10,2) DEFAULT 20.00",
    "delivery_fee DECIMAL(10,2) DEFAULT 9.90",
    "is_open TINYINT(1) DEFAULT 1",
    "open_time TIME DEFAULT '07:00:00'",
    "close_time TIME DEFAULT '22:00:00'",
    "accepts_pix TINYINT(1) DEFAULT 1",
    "accepts_card TINYINT(1) DEFAULT 1",
    "pagarme_recipient_id VARCHAR(50)",
];

foreach ($colunasPartners as $col) {
    $colName = explode(' ', $col)[0];
    execSQL($pdo, "ALTER TABLE om_market_partners ADD COLUMN $col", "partners.$colName");
}

// Colunas para om_market_shoppers
$colunasShoppers = [
    "current_lat DECIMAL(10,8) DEFAULT NULL",
    "current_lng DECIMAL(11,8) DEFAULT NULL",
    "last_location_at DATETIME DEFAULT NULL",
    "rating DECIMAL(3,2) DEFAULT 5.00",
    "total_ratings INT DEFAULT 0",
    "accept_rate DECIMAL(5,2) DEFAULT 100.00",
    "avg_accept_time INT DEFAULT 30",
    "total_offers INT DEFAULT 0",
    "total_accepts INT DEFAULT 0",
    "total_orders INT DEFAULT 0",
    "total_earnings DECIMAL(10,2) DEFAULT 0",
    "current_order_id INT DEFAULT NULL",
    "pix_key VARCHAR(100)",
    "bank_account TEXT",
];

foreach ($colunasShoppers as $col) {
    $colName = explode(' ', $col)[0];
    execSQL($pdo, "ALTER TABLE om_market_shoppers ADD COLUMN $col", "shoppers.$colName");
}

// Colunas para om_market_delivery
$colunasDelivery = [
    "current_lat DECIMAL(10,8) DEFAULT NULL",
    "current_lng DECIMAL(11,8) DEFAULT NULL",
    "last_location_at DATETIME DEFAULT NULL",
    "rating DECIMAL(3,2) DEFAULT 5.00",
    "total_ratings INT DEFAULT 0",
    "accept_rate DECIMAL(5,2) DEFAULT 100.00",
    "avg_accept_time INT DEFAULT 30",
    "total_offers INT DEFAULT 0",
    "total_accepts INT DEFAULT 0",
    "total_deliveries INT DEFAULT 0",
    "total_earnings DECIMAL(10,2) DEFAULT 0",
    "active_order_id INT DEFAULT NULL",
    "vehicle VARCHAR(20) DEFAULT 'moto'",
    "vehicle_plate VARCHAR(20)",
    "pix_key VARCHAR(100)",
    "bank_account TEXT",
];

foreach ($colunasDelivery as $col) {
    $colName = explode(' ', $col)[0];
    execSQL($pdo, "ALTER TABLE om_market_delivery ADD COLUMN $col", "delivery.$colName");
}

logOk("Total de colunas verificadas: " . $stats['colunas']);

echo "</div></div>";

// ══════════════════════════════════════════════════════════════════════════════
// PARTE 3: DADOS DE TESTE - MERCADOS
// ══════════════════════════════════════════════════════════════════════════════

echo "<div class='section'><h2>🏪 PARTE 3: Mercados de Teste</h2><div class='log'>";

$mercados = [
    ['name' => 'Mercado Central GV', 'city' => 'Governador Valadares', 'state' => 'MG', 'cep' => '35010000', 'address' => 'Av. Brasil, 1000 - Centro', 'lat' => -18.8512, 'lng' => -41.9455, 'radius' => 15, 'fee' => 9.90, 'min' => 30.00],
    ['name' => 'Supermercado Economia GV', 'city' => 'Governador Valadares', 'state' => 'MG', 'cep' => '35020000', 'address' => 'Rua Israel Pinheiro, 500', 'lat' => -18.8620, 'lng' => -41.9380, 'radius' => 12, 'fee' => 7.90, 'min' => 25.00],
    ['name' => 'Mercado Express Paulista', 'city' => 'São Paulo', 'state' => 'SP', 'cep' => '01310100', 'address' => 'Av. Paulista, 1500', 'lat' => -23.5629, 'lng' => -46.6544, 'radius' => 8, 'fee' => 14.90, 'min' => 50.00],
    ['name' => 'Super Moema', 'city' => 'São Paulo', 'state' => 'SP', 'cep' => '04077000', 'address' => 'Av. Ibirapuera, 2000', 'lat' => -23.6010, 'lng' => -46.6650, 'radius' => 6, 'fee' => 12.90, 'min' => 40.00],
    ['name' => 'Hiper BH Savassi', 'city' => 'Belo Horizonte', 'state' => 'MG', 'cep' => '30130000', 'address' => 'Av. Afonso Pena, 1000', 'lat' => -19.9245, 'lng' => -43.9352, 'radius' => 10, 'fee' => 10.90, 'min' => 35.00],
];

// Verificar se já existem
$existentes = $pdo->query("SELECT COUNT(*) FROM om_market_partners WHERE lat IS NOT NULL")->fetchColumn();

if ($existentes < 3) {
    foreach ($mercados as $m) {
        try {
            $stmt = $pdo->prepare("INSERT INTO om_market_partners (name, city, state, cep, address, lat, lng, delivery_radius, delivery_fee, min_order, status, is_open) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)");
            $stmt->execute([$m['name'], $m['city'], $m['state'], $m['cep'], $m['address'], $m['lat'], $m['lng'], $m['radius'], $m['fee'], $m['min']]);
            $stats['mercados']++;
            logOk("Mercado: {$m['name']}");
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate') === false) {
                logWarn("Mercado {$m['name']}: " . $e->getMessage());
            }
        }
    }
} else {
    // Atualizar coordenadas dos existentes
    foreach ($mercados as $m) {
        $pdo->prepare("UPDATE om_market_partners SET lat = ?, lng = ?, delivery_radius = ?, delivery_fee = ?, min_order = ? WHERE name LIKE ? OR city = ?")->execute([$m['lat'], $m['lng'], $m['radius'], $m['fee'], $m['min'], '%' . substr($m['name'], 0, 15) . '%', $m['city']]);
    }
    logSkip("Mercados já existem - coordenadas atualizadas");
    $stats['mercados'] = $existentes;
}

echo "</div></div>";

// ══════════════════════════════════════════════════════════════════════════════
// PARTE 4: DADOS DE TESTE - PRODUTOS
// ══════════════════════════════════════════════════════════════════════════════

echo "<div class='section'><h2>🛒 PARTE 4: Produtos</h2><div class='log'>";

$produtosBase = [
    ['cat' => 'Frutas', 'name' => 'Banana Prata', 'unit' => 'kg', 'base' => 5.99],
    ['cat' => 'Frutas', 'name' => 'Maçã Fuji', 'unit' => 'kg', 'base' => 12.90],
    ['cat' => 'Frutas', 'name' => 'Laranja Pera', 'unit' => 'kg', 'base' => 4.99],
    ['cat' => 'Frutas', 'name' => 'Mamão Formosa', 'unit' => 'kg', 'base' => 7.50],
    ['cat' => 'Frutas', 'name' => 'Uva Itália', 'unit' => 'kg', 'base' => 15.90],
    ['cat' => 'Carnes', 'name' => 'Picanha Bovina', 'unit' => 'kg', 'base' => 79.90],
    ['cat' => 'Carnes', 'name' => 'Frango Inteiro', 'unit' => 'kg', 'base' => 14.90],
    ['cat' => 'Carnes', 'name' => 'Carne Moída', 'unit' => 'kg', 'base' => 32.90],
    ['cat' => 'Carnes', 'name' => 'Linguiça Toscana', 'unit' => 'kg', 'base' => 24.90],
    ['cat' => 'Laticínios', 'name' => 'Leite Integral 1L', 'unit' => 'un', 'base' => 5.49],
    ['cat' => 'Laticínios', 'name' => 'Queijo Mussarela', 'unit' => 'kg', 'base' => 44.90],
    ['cat' => 'Laticínios', 'name' => 'Iogurte Natural', 'unit' => 'un', 'base' => 6.90],
    ['cat' => 'Laticínios', 'name' => 'Manteiga 200g', 'unit' => 'un', 'base' => 12.90],
    ['cat' => 'Bebidas', 'name' => 'Coca-Cola 2L', 'unit' => 'un', 'base' => 10.90],
    ['cat' => 'Bebidas', 'name' => 'Água Mineral 1.5L', 'unit' => 'un', 'base' => 3.50],
    ['cat' => 'Bebidas', 'name' => 'Suco Natural 1L', 'unit' => 'un', 'base' => 9.90],
    ['cat' => 'Bebidas', 'name' => 'Cerveja Heineken', 'unit' => 'un', 'base' => 6.90],
    ['cat' => 'Padaria', 'name' => 'Pão Francês', 'unit' => 'kg', 'base' => 14.90],
    ['cat' => 'Padaria', 'name' => 'Pão de Forma', 'unit' => 'un', 'base' => 8.90],
    ['cat' => 'Padaria', 'name' => 'Bolo de Chocolate', 'unit' => 'un', 'base' => 25.90],
    ['cat' => 'Mercearia', 'name' => 'Arroz 5kg', 'unit' => 'un', 'base' => 28.90],
    ['cat' => 'Mercearia', 'name' => 'Feijão Carioca 1kg', 'unit' => 'un', 'base' => 8.90],
    ['cat' => 'Mercearia', 'name' => 'Macarrão 500g', 'unit' => 'un', 'base' => 5.50],
    ['cat' => 'Mercearia', 'name' => 'Óleo de Soja 900ml', 'unit' => 'un', 'base' => 7.90],
    ['cat' => 'Mercearia', 'name' => 'Açúcar 1kg', 'unit' => 'un', 'base' => 5.90],
    ['cat' => 'Mercearia', 'name' => 'Café 500g', 'unit' => 'un', 'base' => 18.90],
    ['cat' => 'Limpeza', 'name' => 'Detergente 500ml', 'unit' => 'un', 'base' => 2.99],
    ['cat' => 'Limpeza', 'name' => 'Sabão em Pó 1kg', 'unit' => 'un', 'base' => 15.90],
    ['cat' => 'Higiene', 'name' => 'Papel Higiênico 12un', 'unit' => 'un', 'base' => 22.90],
    ['cat' => 'Higiene', 'name' => 'Shampoo 400ml', 'unit' => 'un', 'base' => 18.90],
];

// Buscar mercados
$mercadosDB = $pdo->query("SELECT partner_id, name, city FROM om_market_partners WHERE status = '1' ORDER BY partner_id")->fetchAll();

// Variação de preço por cidade
$variacaoPreco = [
    'Governador Valadares' => 1.00,
    'São Paulo' => 1.20,
    'Belo Horizonte' => 1.08,
];

$produtosExistentes = $pdo->query("SELECT COUNT(*) FROM om_market_products")->fetchColumn();

if ($produtosExistentes < 50) {
    foreach ($mercadosDB as $mercado) {
        $multiplicador = $variacaoPreco[$mercado['city']] ?? 1.00;
        
        foreach ($produtosBase as $p) {
            if (rand(0, 100) < 8) continue; // 8% chance de não ter
            
            $preco = round($p['base'] * $multiplicador, 2);
            $temPromo = rand(0, 100) < 15;
            $promoPreco = $temPromo ? round($preco * 0.85, 2) : null;
            
            try {
                $stmt = $pdo->prepare("INSERT INTO om_market_products (partner_id, name, category, price, special_price, unit, stock) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$mercado['partner_id'], $p['name'], $p['cat'], $preco, $promoPreco, $p['unit'], rand(50, 200)]);
                $stats['produtos']++;
            } catch (Exception $e) {}
        }
    }
    logOk("Produtos criados: {$stats['produtos']}");
} else {
    logSkip("Produtos já existem: $produtosExistentes");
    $stats['produtos'] = $produtosExistentes;
}

echo "</div></div>";

// ══════════════════════════════════════════════════════════════════════════════
// PARTE 5: SHOPPERS E DELIVERYS
// ══════════════════════════════════════════════════════════════════════════════

echo "<div class='section'><h2>👷 PARTE 5: Shoppers & Deliverys</h2><div class='log'>";

$nomes = ['João', 'Maria', 'Pedro', 'Ana', 'Carlos', 'Julia', 'Lucas', 'Fernanda', 'Bruno', 'Camila', 'Rafael', 'Beatriz'];
$sobrenomes = ['Silva', 'Santos', 'Oliveira', 'Souza', 'Lima', 'Costa', 'Rodrigues', 'Almeida', 'Pereira', 'Nascimento'];

$cidadesWorkers = [
    ['city' => 'Governador Valadares', 'lat' => -18.8512, 'lng' => -41.9455, 'shoppers' => 6, 'deliverys' => 4],
    ['city' => 'São Paulo', 'lat' => -23.5629, 'lng' => -46.6544, 'shoppers' => 8, 'deliverys' => 6],
    ['city' => 'Belo Horizonte', 'lat' => -19.9245, 'lng' => -43.9352, 'shoppers' => 4, 'deliverys' => 3],
];

$shoppersExistentes = $pdo->query("SELECT COUNT(*) FROM om_market_shoppers WHERE current_lat IS NOT NULL")->fetchColumn();
$deliverysExistentes = $pdo->query("SELECT COUNT(*) FROM om_market_delivery WHERE current_lat IS NOT NULL")->fetchColumn();

if ($shoppersExistentes < 10) {
    foreach ($cidadesWorkers as $c) {
        for ($i = 0; $i < $c['shoppers']; $i++) {
            $nome = $nomes[array_rand($nomes)] . ' ' . $sobrenomes[array_rand($sobrenomes)];
            $email = 'shopper' . ($stats['shoppers'] + 1) . '@onemundo.com';
            $lat = $c['lat'] + (rand(-40, 40) / 1000);
            $lng = $c['lng'] + (rand(-40, 40) / 1000);
            $online = rand(0, 100) < 70;
            
            try {
                $stmt = $pdo->prepare("INSERT INTO om_market_shoppers (name, email, phone, city, status, is_online, is_busy, current_lat, current_lng, rating, accept_rate, avg_accept_time) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)");
                $stmt->execute([$nome, $email, '119' . rand(10000000, 99999999), $c['city'], $online ? 'online' : 'offline', $online ? 1 : 0, $lat, $lng, rand(40, 50) / 10, rand(70, 100), rand(15, 45)]);
                $stats['shoppers']++;
            } catch (Exception $e) {}
        }
    }
    logOk("Shoppers criados: {$stats['shoppers']}");
} else {
    logSkip("Shoppers já existem: $shoppersExistentes");
    $stats['shoppers'] = $shoppersExistentes;
}

if ($deliverysExistentes < 8) {
    $veiculos = ['moto', 'moto', 'moto', 'bike', 'carro'];
    foreach ($cidadesWorkers as $c) {
        for ($i = 0; $i < $c['deliverys']; $i++) {
            $nome = $nomes[array_rand($nomes)] . ' ' . $sobrenomes[array_rand($sobrenomes)];
            $lat = $c['lat'] + (rand(-30, 30) / 1000);
            $lng = $c['lng'] + (rand(-30, 30) / 1000);
            $online = rand(0, 100) < 60;
            $veiculo = $veiculos[array_rand($veiculos)];
            
            try {
                $stmt = $pdo->prepare("INSERT INTO om_market_delivery (name, phone, city, vehicle, status, is_online, current_lat, current_lng, rating, accept_rate, avg_accept_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$nome, '119' . rand(10000000, 99999999), $c['city'], $veiculo, $online ? 'online' : 'offline', $online ? 1 : 0, $lat, $lng, rand(40, 50) / 10, rand(75, 100), rand(10, 30)]);
                $stats['deliverys']++;
            } catch (Exception $e) {}
        }
    }
    logOk("Deliverys criados: {$stats['deliverys']}");
} else {
    logSkip("Deliverys já existem: $deliverysExistentes");
    $stats['deliverys'] = $deliverysExistentes;
}

echo "</div></div>";

// ══════════════════════════════════════════════════════════════════════════════
// PARTE 6: CLIENTES DE TESTE
// ══════════════════════════════════════════════════════════════════════════════

echo "<div class='section'><h2>👤 PARTE 6: Clientes de Teste</h2><div class='log'>";

$clientes = [
    ['name' => 'Carlos Centro', 'city' => 'Governador Valadares', 'cep' => '35010010', 'lat' => -18.8530, 'lng' => -41.9470, 'address' => 'Rua Sete de Setembro, 100'],
    ['name' => 'Maria Vila Bretas', 'city' => 'Governador Valadares', 'cep' => '35020100', 'lat' => -18.8600, 'lng' => -41.9400, 'address' => 'Av. JK, 500'],
    ['name' => 'João São Paulo', 'city' => 'Governador Valadares', 'cep' => '35030050', 'lat' => -18.8450, 'lng' => -41.9500, 'address' => 'Rua Barão do Rio Branco, 200'],
    ['name' => 'Pedro Longe', 'city' => 'Governador Valadares', 'cep' => '35100000', 'lat' => -19.0000, 'lng' => -42.1000, 'address' => 'Rua Distante, 999'],
    ['name' => 'Ana Paulista', 'city' => 'São Paulo', 'cep' => '01310100', 'lat' => -23.5650, 'lng' => -46.6550, 'address' => 'Av. Paulista, 1000'],
    ['name' => 'Bruno Moema', 'city' => 'São Paulo', 'cep' => '04077100', 'lat' => -23.6000, 'lng' => -46.6600, 'address' => 'Rua dos Bandeirantes, 300'],
    ['name' => 'Julia Pinheiros', 'city' => 'São Paulo', 'cep' => '05422000', 'lat' => -23.5670, 'lng' => -46.6900, 'address' => 'Rua dos Pinheiros, 800'],
    ['name' => 'Lucas Guarulhos', 'city' => 'Guarulhos', 'cep' => '07000000', 'lat' => -23.4500, 'lng' => -46.5300, 'address' => 'Av. Guarulhos, 100'],
    ['name' => 'Fernanda Savassi', 'city' => 'Belo Horizonte', 'cep' => '30130000', 'lat' => -19.9350, 'lng' => -43.9300, 'address' => 'Praça da Savassi, 50'],
    ['name' => 'Camila Funcionários', 'city' => 'Belo Horizonte', 'cep' => '30140000', 'lat' => -19.9400, 'lng' => -43.9250, 'address' => 'Rua Cláudio Manoel, 400'],
    ['name' => 'Rafael Contagem', 'city' => 'Contagem', 'cep' => '32000000', 'lat' => -19.9300, 'lng' => -44.0500, 'address' => 'Av. João César, 200'],
    ['name' => 'Gabriel Ipatinga', 'city' => 'Ipatinga', 'cep' => '35160000', 'lat' => -19.4700, 'lng' => -42.5400, 'address' => 'Rua Sem Mercado, 1'],
];

$clientesExistentes = $pdo->query("SELECT COUNT(*) FROM om_sim_customers")->fetchColumn();

if ($clientesExistentes < 5) {
    $pdo->exec("DELETE FROM om_sim_customers");
    foreach ($clientes as $c) {
        $email = strtolower(str_replace(' ', '.', $c['name'])) . '@cliente.com';
        $doc = rand(10000000000, 99999999999);
        try {
            $stmt = $pdo->prepare("INSERT INTO om_sim_customers (name, email, phone, document, city, cep, lat, lng, address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$c['name'], $email, '119' . rand(10000000, 99999999), $doc, $c['city'], $c['cep'], $c['lat'], $c['lng'], $c['address']]);
            $stats['clientes']++;
        } catch (Exception $e) {}
    }
    logOk("Clientes criados: {$stats['clientes']}");
} else {
    logSkip("Clientes já existem: $clientesExistentes");
    $stats['clientes'] = $clientesExistentes;
}

echo "</div></div>";

// ══════════════════════════════════════════════════════════════════════════════
// RESUMO FINAL
// ══════════════════════════════════════════════════════════════════════════════

$tempoTotal = round(microtime(true) - $startTime, 2);

// Contar totais
$totais = [
    'mercados' => $pdo->query("SELECT COUNT(*) FROM om_market_partners WHERE status = '1'")->fetchColumn(),
    'produtos' => $pdo->query("SELECT COUNT(*) FROM om_market_products")->fetchColumn(),
    'shoppers' => $pdo->query("SELECT COUNT(*) FROM om_market_shoppers")->fetchColumn(),
    'deliverys' => $pdo->query("SELECT COUNT(*) FROM om_market_delivery")->fetchColumn(),
    'clientes' => $pdo->query("SELECT COUNT(*) FROM om_sim_customers")->fetchColumn(),
];

echo "<div class='summary'>";
echo "<h2>🎉 INSTALAÇÃO COMPLETA!</h2>";
echo "<p>Sistema OneMundo Mercado configurado com sucesso em {$tempoTotal}s</p>";

echo "<div class='stats'>";
echo "<div class='stat'><div class='value'>{$totais['mercados']}</div><div class='label'>🏪 Mercados</div></div>";
echo "<div class='stat'><div class='value'>{$totais['produtos']}</div><div class='label'>🛒 Produtos</div></div>";
echo "<div class='stat'><div class='value'>{$totais['shoppers']}</div><div class='label'>👷 Shoppers</div></div>";
echo "<div class='stat'><div class='value'>{$totais['deliverys']}</div><div class='label'>🚴 Deliverys</div></div>";
echo "<div class='stat'><div class='value'>{$totais['clientes']}</div><div class='label'>👤 Clientes</div></div>";
echo "</div>";

echo "<div class='links'>";
echo "<a href='SIM_MERCADO.php'>🛒 Testar como Cliente</a>";
echo "<a href='SIM_SHOPPER.php'>👷 Testar como Shopper</a>";
echo "<a href='SIM_DELIVERY.php'>🚴 Testar como Delivery</a>";
echo "<a href='SIMULADOR.php'>🎮 Painel de Controle</a>";
echo "</div>";

echo "</div>";

echo "</div></body></html>";
