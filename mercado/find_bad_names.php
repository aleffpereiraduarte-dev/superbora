<?php
// Encontrar produtos com nomes que podem quebrar o onclick
require_once __DIR__ . '/db.php';

$db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);

$produtos = $db->query("
    SELECT product_id, name 
    FROM oc_product_description 
    WHERE name LIKE '%\"%' 
       OR name LIKE '%\'%'
       OR name LIKE '%<%'
       OR name LIKE '%>%'
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

echo "<h2>Produtos com caracteres problemáticos no nome:</h2>";
echo "<table border='1' cellpadding='10'>";
echo "<tr><th>ID</th><th>Nome</th><th>Problema</th></tr>";

foreach ($produtos as $p) {
    $problemas = [];
    if (strpos($p['name'], '"') !== false) $problemas[] = 'Aspas duplas "';
    if (strpos($p['name'], "'") !== false) $problemas[] = "Aspas simples '";
    if (strpos($p['name'], '<') !== false) $problemas[] = 'Tag <';
    if (strpos($p['name'], '>') !== false) $problemas[] = 'Tag >';
    
    echo "<tr>";
    echo "<td>{$p['product_id']}</td>";
    echo "<td>" . htmlspecialchars($p['name']) . "</td>";
    echo "<td>" . implode(', ', $problemas) . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>Total: " . count($produtos) . " produtos</h3>";
