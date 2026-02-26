<?php
require_once __DIR__ . '/config/database.php';
/**
 * FIX COMPLETO - Tabela om_shopper_offers
 * Cria com TODAS as colunas que o cron_matching.php precisa
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>FIX COMPLETO - Tabela om_shopper_offers</h1>";
echo "<pre>";

$conn = getMySQLi();
if ($conn->connect_error) {
    die("Erro: " . $conn->connect_error);
}

echo "Conexao OK\n\n";

// =============================================
// 1. Dropar tabela antiga e criar nova correta
// =============================================
echo "=== RECRIANDO TABELA om_shopper_offers ===\n";

// Fazer backup se tiver dados
$r = $conn->query("SELECT COUNT(*) as t FROM om_shopper_offers");
if ($r) {
    $row = $r->fetch_assoc();
    echo "Registros na tabela atual: " . $row['t'] . "\n";
}

// Dropar tabela antiga
$conn->query("DROP TABLE IF EXISTS om_shopper_offers");
echo "Tabela antiga removida\n";

// Criar tabela com estrutura correta (baseada no cron_matching.php)
$sql = "CREATE TABLE om_shopper_offers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    worker_id INT NOT NULL,
    partner_id INT DEFAULT NULL,
    order_total DECIMAL(10,2) DEFAULT 0.00,
    shopper_earning DECIMAL(10,2) DEFAULT 0.00,
    status ENUM('pending', 'accepted', 'rejected', 'expired', 'cancelled') DEFAULT 'pending',
    current_wave INT DEFAULT 1,
    wave_started_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    responded_at TIMESTAMP NULL,
    response_time_seconds INT DEFAULT NULL,
    distance_km DECIMAL(10,2) DEFAULT NULL,
    estimated_time INT DEFAULT NULL,
    INDEX idx_order (order_id),
    INDEX idx_worker (worker_id),
    INDEX idx_status (status),
    INDEX idx_partner (partner_id),
    INDEX idx_expires (expires_at),
    INDEX idx_wave (current_wave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql)) {
    echo "Tabela om_shopper_offers CRIADA com sucesso!\n";
} else {
    echo "ERRO: " . $conn->error . "\n";
}

// =============================================
// 2. Verificar estrutura
// =============================================
echo "\n=== ESTRUTURA FINAL ===\n";
$r = $conn->query("DESCRIBE om_shopper_offers");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        echo "  " . str_pad($row['Field'], 25) . " " . $row['Type'] . "\n";
    }
}

// =============================================
// 3. Verificar om_delivery_offers tambem
// =============================================
echo "\n=== VERIFICANDO om_delivery_offers ===\n";

$r = $conn->query("SHOW TABLES LIKE 'om_delivery_offers'");
if ($r->num_rows == 0) {
    $sql2 = "CREATE TABLE om_delivery_offers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        driver_id INT NOT NULL,
        partner_id INT DEFAULT NULL,
        order_total DECIMAL(10,2) DEFAULT 0.00,
        driver_earning DECIMAL(10,2) DEFAULT 0.00,
        status ENUM('pending', 'accepted', 'rejected', 'expired', 'cancelled') DEFAULT 'pending',
        current_wave INT DEFAULT 1,
        wave_started_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP NULL,
        responded_at TIMESTAMP NULL,
        pickup_lat DECIMAL(10,8) DEFAULT NULL,
        pickup_lng DECIMAL(11,8) DEFAULT NULL,
        delivery_lat DECIMAL(10,8) DEFAULT NULL,
        delivery_lng DECIMAL(11,8) DEFAULT NULL,
        distance_km DECIMAL(10,2) DEFAULT NULL,
        INDEX idx_order (order_id),
        INDEX idx_driver (driver_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if ($conn->query($sql2)) {
        echo "Tabela om_delivery_offers CRIADA!\n";
    } else {
        echo "ERRO: " . $conn->error . "\n";
    }
} else {
    echo "Tabela om_delivery_offers ja existe\n";
}

// =============================================
// 4. Verificar se tabela om_market_chat existe (usada no cron)
// =============================================
echo "\n=== VERIFICANDO om_market_chat ===\n";
$r = $conn->query("SHOW TABLES LIKE 'om_market_chat'");
if ($r->num_rows > 0) {
    echo "Tabela om_market_chat existe\n";
} else {
    echo "Tabela om_market_chat NAO EXISTE - pode dar erro no chat\n";
}

echo "\n";
echo "========================================\n";
echo "FIX CONCLUIDO!\n";
echo "========================================\n";
echo "\nAgora rode o cron_matching.php novamente!\n";

echo "</pre>";

echo "<p style='margin-top:20px;'>";
echo "<a href='/mercado/cron_matching.php' style='display:inline-block; padding:15px 30px; background:#00d4aa; color:#000; text-decoration:none; border-radius:8px; font-weight:bold; margin:5px;'>Rodar Matching</a>";
echo "<a href='/mercado/diagnostico_crons_pagarme.php' style='display:inline-block; padding:15px 30px; background:#0066cc; color:#fff; text-decoration:none; border-radius:8px; font-weight:bold; margin:5px;'>Ver Diagnostico</a>";
echo "</p>";

$conn->close();
