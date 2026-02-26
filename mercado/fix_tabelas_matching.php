<?php
require_once __DIR__ . '/config/database.php';
/**
 * FIX - Criar tabelas om_shopper_offers e om_delivery_offers
 * (nomes que o cron_matching.php usa)
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>FIX - Tabelas do Matching</h1>";
echo "<pre>";

$conn = getMySQLi();
if ($conn->connect_error) {
    die("Erro: " . $conn->connect_error);
}

echo "✅ Conexao OK\n\n";

// =============================================
// 1. Verificar tabelas existentes
// =============================================
echo "=== VERIFICANDO TABELAS EXISTENTES ===\n";

$tabelas = array(
    'om_shopper_offers',
    'om_market_shopper_offers', 
    'om_delivery_offers',
    'om_market_driver_offers'
);

foreach ($tabelas as $t) {
    $r = $conn->query("SHOW TABLES LIKE '$t'");
    $existe = $r->num_rows > 0 ? 'EXISTE' : 'NAO EXISTE';
    echo "$t: $existe\n";
}

// =============================================
// 2. Criar om_shopper_offers (que o CRON usa)
// =============================================
echo "\n=== CRIANDO om_shopper_offers ===\n";

$sql1 = "CREATE TABLE IF NOT EXISTS om_shopper_offers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    shopper_id INT NOT NULL,
    partner_id INT DEFAULT NULL,
    status ENUM('pending', 'accepted', 'rejected', 'expired', 'cancelled') DEFAULT 'pending',
    wave INT DEFAULT 1,
    offer_amount DECIMAL(10,2) DEFAULT 0.00,
    distance_km DECIMAL(10,2) DEFAULT NULL,
    estimated_time INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    responded_at TIMESTAMP NULL,
    response_time_seconds INT DEFAULT NULL,
    INDEX idx_order (order_id),
    INDEX idx_shopper (shopper_id),
    INDEX idx_status (status),
    INDEX idx_partner (partner_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql1)) {
    echo "✅ Tabela om_shopper_offers criada/verificada!\n";
} else {
    echo "❌ Erro: " . $conn->error . "\n";
}

// Verificar se tem partner_id
$check = $conn->query("SHOW COLUMNS FROM om_shopper_offers LIKE 'partner_id'");
if ($check->num_rows == 0) {
    $conn->query("ALTER TABLE om_shopper_offers ADD COLUMN partner_id INT DEFAULT NULL AFTER shopper_id");
    echo "✅ Coluna partner_id adicionada em om_shopper_offers!\n";
} else {
    echo "⏭️ Coluna partner_id ja existe em om_shopper_offers\n";
}

// =============================================
// 3. Criar om_delivery_offers (que o CRON usa)
// =============================================
echo "\n=== CRIANDO om_delivery_offers ===\n";

$sql2 = "CREATE TABLE IF NOT EXISTS om_delivery_offers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    driver_id INT NOT NULL,
    partner_id INT DEFAULT NULL,
    status ENUM('pending', 'accepted', 'rejected', 'expired', 'cancelled') DEFAULT 'pending',
    wave INT DEFAULT 1,
    offer_amount DECIMAL(10,2) DEFAULT 0.00,
    distance_km DECIMAL(10,2) DEFAULT NULL,
    estimated_time INT DEFAULT NULL,
    pickup_lat DECIMAL(10,8) DEFAULT NULL,
    pickup_lng DECIMAL(11,8) DEFAULT NULL,
    delivery_lat DECIMAL(10,8) DEFAULT NULL,
    delivery_lng DECIMAL(11,8) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    responded_at TIMESTAMP NULL,
    INDEX idx_order (order_id),
    INDEX idx_driver (driver_id),
    INDEX idx_status (status),
    INDEX idx_partner (partner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql2)) {
    echo "✅ Tabela om_delivery_offers criada/verificada!\n";
} else {
    echo "❌ Erro: " . $conn->error . "\n";
}

// =============================================
// 4. Mostrar estrutura final
// =============================================
echo "\n=== ESTRUTURA om_shopper_offers ===\n";
$r = $conn->query("DESCRIBE om_shopper_offers");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        echo "  " . $row['Field'] . " - " . $row['Type'] . "\n";
    }
}

echo "\n=== ESTRUTURA om_delivery_offers ===\n";
$r = $conn->query("DESCRIBE om_delivery_offers");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        echo "  " . $row['Field'] . " - " . $row['Type'] . "\n";
    }
}

// =============================================
// 5. Verificar final
// =============================================
echo "\n=== VERIFICACAO FINAL ===\n";
foreach ($tabelas as $t) {
    $r = $conn->query("SHOW TABLES LIKE '$t'");
    $existe = $r->num_rows > 0 ? '✅ EXISTE' : '❌ NAO EXISTE';
    echo "$t: $existe\n";
}

echo "\n\n✅ FIX CONCLUIDO!\n";
echo "\nAgora o cron_matching.php deve funcionar!\n";

echo "</pre>";

echo "<p><a href='/mercado/cron_matching.php' style='display:inline-block; padding:15px 30px; background:#00d4aa; color:#000; text-decoration:none; border-radius:8px; font-weight:bold; margin:10px;'>Rodar Matching</a>";
echo "<a href='/mercado/diagnostico_crons_pagarme.php' style='display:inline-block; padding:15px 30px; background:#0066cc; color:#fff; text-decoration:none; border-radius:8px; font-weight:bold; margin:10px;'>Ver Diagnostico</a></p>";

$conn->close();
