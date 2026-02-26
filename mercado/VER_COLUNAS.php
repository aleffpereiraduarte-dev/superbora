<?php
require_once __DIR__ . '/config/database.php';
/**
 * Ver estrutura das tabelas
 */

try {
    $pdo = getPDO();
    
    echo "<h2>Estrutura: om_orders</h2><pre>";
    $cols = $pdo->query("DESCRIBE om_orders")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "{$c['Field']} - {$c['Type']} - {$c['Null']} - {$c['Key']}\n";
    }
    echo "</pre>";
    
    echo "<h2>Estrutura: om_order_items</h2><pre>";
    $cols = $pdo->query("DESCRIBE om_order_items")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "{$c['Field']} - {$c['Type']} - {$c['Null']} - {$c['Key']}\n";
    }
    echo "</pre>";
    
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage();
}
