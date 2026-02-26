<?php
require_once __DIR__ . '/config/database.php';
/**
 * Análise da estrutura de Shoppers/Delivery
 */

try {
    $pdo = getPDO();
    
    echo "<h1>🔍 Análise das Tabelas - Shoppers & Delivery</h1>";
    
    // 1. Estrutura om_market_shoppers
    echo "<h2>📦 Tabela: om_market_shoppers</h2>";
    try {
        $result = $pdo->query("DESCRIBE om_market_shoppers");
        echo "<table border='1' cellpadding='8' style='border-collapse:collapse'>";
        echo "<tr style='background:#333;color:#fff'><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $highlight = '';
            if (in_array($row['Field'], ['type', 'role', 'is_shopper', 'is_delivery', 'latitude', 'longitude', 'lat', 'lng', 'location'])) {
                $highlight = "style='background:#afa'";
            }
            echo "<tr $highlight>";
            echo "<td><strong>{$row['Field']}</strong></td>";
            echo "<td>{$row['Type']}</td>";
            echo "<td>{$row['Null']}</td>";
            echo "<td>{$row['Key']}</td>";
            echo "<td>{$row['Default']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Dados de exemplo
        echo "<h3>👥 Dados atuais (primeiros 5):</h3>";
        $shoppers = $pdo->query("SELECT * FROM om_market_shoppers LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        if ($shoppers) {
            echo "<pre>" . print_r($shoppers, true) . "</pre>";
        } else {
            echo "<p>⚠️ Tabela vazia</p>";
        }
        
    } catch (PDOException $e) {
        echo "<p>❌ Tabela não existe: " . $e->getMessage() . "</p>";
    }
    
    // 2. Verificar se existe tabela de delivery separada
    echo "<h2>🚚 Procurando tabelas de Delivery...</h2>";
    $tables = $pdo->query("SHOW TABLES LIKE '%deliver%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($tables) {
        foreach ($tables as $table) {
            echo "<h3>Tabela: $table</h3>";
            $result = $pdo->query("DESCRIBE $table");
            echo "<table border='1' cellpadding='8' style='border-collapse:collapse'>";
            echo "<tr style='background:#333;color:#fff'><th>Campo</th><th>Tipo</th></tr>";
            while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td></tr>";
            }
            echo "</table>";
        }
    } else {
        echo "<p>ℹ️ Nenhuma tabela separada de delivery encontrada</p>";
    }
    
    // 3. Verificar tabelas relacionadas
    echo "<h2>📋 Outras tabelas relevantes:</h2>";
    $related = $pdo->query("SHOW TABLES LIKE 'om_market%'")->fetchAll(PDO::FETCH_COLUMN);
    echo "<ul>";
    foreach ($related as $table) {
        $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        echo "<li><strong>$table</strong> - $count registros</li>";
    }
    echo "</ul>";
    
    // 4. Verificar campos de localização em partners
    echo "<h2>📍 Localização dos Parceiros (Mercados):</h2>";
    try {
        $result = $pdo->query("DESCRIBE om_market_partners");
        $location_fields = [];
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            if (preg_match('/(lat|lng|longitude|latitude|location|address|cep|postcode)/i', $row['Field'])) {
                $location_fields[] = $row['Field'] . " ({$row['Type']})";
            }
        }
        if ($location_fields) {
            echo "<p>✅ Campos de localização encontrados: " . implode(", ", $location_fields) . "</p>";
            
            // Mostrar dados
            $partners = $pdo->query("SELECT partner_id, name, latitude, longitude, address, cep FROM om_market_partners LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
            echo "<pre>" . print_r($partners, true) . "</pre>";
        } else {
            echo "<p>⚠️ Nenhum campo de localização encontrado</p>";
        }
    } catch (PDOException $e) {
        echo "<p>❌ Erro: " . $e->getMessage() . "</p>";
    }
    
    // 5. Resumo e recomendações
    echo "<h2>💡 Análise e Recomendações:</h2>";
    echo "<div style='background:#f5f5f5;padding:20px;border-radius:10px'>";
    
    // Verificar se tem campo de tipo/role
    $has_role = false;
    $has_location = false;
    
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM om_market_shoppers")->fetchAll(PDO::FETCH_COLUMN);
        $has_role = in_array('role', $cols) || in_array('type', $cols) || in_array('is_delivery', $cols);
        $has_location = in_array('latitude', $cols) || in_array('lat', $cols);
    } catch (Exception $e) {}
    
    if (!$has_role) {
        echo "<p>⚠️ <strong>Falta campo de tipo!</strong> Precisamos adicionar:</p>";
        echo "<code>ALTER TABLE om_market_shoppers ADD COLUMN role ENUM('shopper', 'delivery', 'both') DEFAULT 'both';</code>";
    }
    
    if (!$has_location) {
        echo "<p>⚠️ <strong>Falta localização!</strong> Precisamos adicionar:</p>";
        echo "<code>ALTER TABLE om_market_shoppers ADD COLUMN latitude DECIMAL(10,8) NULL;</code><br>";
        echo "<code>ALTER TABLE om_market_shoppers ADD COLUMN longitude DECIMAL(11,8) NULL;</code>";
    }
    
    echo "</div>";
    
} catch (PDOException $e) {
    echo "<h2>❌ Erro de conexão:</h2>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
