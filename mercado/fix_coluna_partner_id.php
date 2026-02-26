<?php
require_once __DIR__ . '/config/database.php';
/**
 * FIX - Adicionar coluna partner_id na tabela de ofertas
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>FIX - Coluna partner_id</h1>";

$conn = getMySQLi();
if ($conn->connect_error) {
    die("Erro: " . $conn->connect_error);
}

echo "<pre>";

// Verificar estrutura atual
echo "=== ESTRUTURA ATUAL om_market_shopper_offers ===\n";
$r = $conn->query("DESCRIBE om_market_shopper_offers");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        echo $row['Field'] . " - " . $row['Type'] . "\n";
    }
}

echo "\n=== ADICIONANDO COLUNAS FALTANTES ===\n";

// Lista de colunas para adicionar
$colunas = array(
    'partner_id' => "ALTER TABLE om_market_shopper_offers ADD COLUMN partner_id INT DEFAULT NULL AFTER shopper_id",
    'wave' => "ALTER TABLE om_market_shopper_offers ADD COLUMN wave INT DEFAULT 1",
    'offer_amount' => "ALTER TABLE om_market_shopper_offers ADD COLUMN offer_amount DECIMAL(10,2) DEFAULT 0.00",
    'distance_km' => "ALTER TABLE om_market_shopper_offers ADD COLUMN distance_km DECIMAL(10,2) DEFAULT NULL",
    'estimated_time' => "ALTER TABLE om_market_shopper_offers ADD COLUMN estimated_time INT DEFAULT NULL",
    'expires_at' => "ALTER TABLE om_market_shopper_offers ADD COLUMN expires_at TIMESTAMP NULL",
    'responded_at' => "ALTER TABLE om_market_shopper_offers ADD COLUMN responded_at TIMESTAMP NULL",
    'response_time_seconds' => "ALTER TABLE om_market_shopper_offers ADD COLUMN response_time_seconds INT DEFAULT NULL"
);

foreach ($colunas as $col => $sql) {
    $check = $conn->query("SHOW COLUMNS FROM om_market_shopper_offers LIKE '$col'");
    if ($check->num_rows == 0) {
        if ($conn->query($sql)) {
            echo "✅ Coluna '$col' adicionada!\n";
        } else {
            echo "❌ Erro ao adicionar '$col': " . $conn->error . "\n";
        }
    } else {
        echo "⏭️ Coluna '$col' ja existe\n";
    }
}

// Adicionar indices
echo "\n=== ADICIONANDO INDICES ===\n";

$indices = array(
    'idx_order' => "CREATE INDEX idx_order ON om_market_shopper_offers(order_id)",
    'idx_shopper' => "CREATE INDEX idx_shopper ON om_market_shopper_offers(shopper_id)",
    'idx_status' => "CREATE INDEX idx_status ON om_market_shopper_offers(status)",
    'idx_partner' => "CREATE INDEX idx_partner ON om_market_shopper_offers(partner_id)"
);

foreach ($indices as $nome => $sql) {
    $check = $conn->query("SHOW INDEX FROM om_market_shopper_offers WHERE Key_name = '$nome'");
    if ($check->num_rows == 0) {
        if ($conn->query($sql)) {
            echo "✅ Indice '$nome' criado!\n";
        } else {
            echo "⏭️ Indice '$nome': " . $conn->error . "\n";
        }
    } else {
        echo "⏭️ Indice '$nome' ja existe\n";
    }
}

// Verificar estrutura final
echo "\n=== ESTRUTURA FINAL ===\n";
$r = $conn->query("DESCRIBE om_market_shopper_offers");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        echo $row['Field'] . " - " . $row['Type'] . "\n";
    }
}

echo "\n\n✅ FIX CONCLUIDO!\n";
echo "\nAgora rode o cron_matching.php novamente.\n";

echo "</pre>";

echo "<p><a href='/mercado/cron_matching.php' style='display:inline-block; padding:15px 30px; background:#00d4aa; color:#000; text-decoration:none; border-radius:8px; font-weight:bold;'>Rodar Matching Novamente</a></p>";

$conn->close();
