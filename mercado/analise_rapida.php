<?php
require_once __DIR__ . '/config/database.php';
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(30);

$pdo = getPDO();

echo "=== ANÁLISE RÁPIDA ===\n\n";

// Pegar 1 produto recente pelo MAX ID
echo "1. PRODUTO MAIS RECENTE:\n";
$maxId = $pdo->query("SELECT MAX(id) FROM om_market_products_base")->fetchColumn();
echo "   Max ID: $maxId\n";

$p = $pdo->query("SELECT * FROM om_market_products_base WHERE id = $maxId")->fetch(PDO::FETCH_ASSOC);

if ($p) {
    foreach ($p as $k => $v) {
        $val = $v ?? 'NULL';
        if (strlen($val) > 80) $val = substr($val, 0, 80) . '...';
        echo "   $k: $val\n";
    }
}

echo "\n2. COLUNAS:\n";
$cols = $pdo->query("SHOW COLUMNS FROM om_market_products_base")->fetchAll(PDO::FETCH_COLUMN);
echo "   " . implode(", ", $cols) . "\n";

echo "\n3. CONTAGENS:\n";
echo "   Total: " . $pdo->query("SELECT COUNT(*) FROM om_market_products_base")->fetchColumn() . "\n";

echo "\n=== FIM ===\n";
?>
