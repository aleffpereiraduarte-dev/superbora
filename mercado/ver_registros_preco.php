<?php
require_once __DIR__ . '/config/database.php';
header('Content-Type: text/html; charset=utf-8');

$conn = getMySQLi();
if ($conn->connect_error) die("Erro: " . $conn->connect_error);
$conn->set_charset('utf8mb4');

echo "<html><head><meta charset='UTF-8'><title>Registros de Preço</title>";
echo "<style>
body { font-family: Arial, sans-serif; background: #1a1a2e; color: #fff; padding: 20px; }
h1, h2 { color: #00d4aa; }
table { width: 100%; border-collapse: collapse; margin: 15px 0; background: #16213e; }
th, td { padding: 10px; text-align: left; border-bottom: 1px solid #333; font-size: 13px; }
th { background: #0f3460; color: #00d4aa; }
tr:hover { background: #1e3a5f; }
.partner { background: #0f3460; padding: 15px; border-radius: 10px; margin: 20px 0; }
img { max-width: 50px; max-height: 50px; border-radius: 5px; }
.resumo { background: #16213e; padding: 15px; border-radius: 10px; margin: 10px 0; }
</style></head><body>";

echo "<h1>📋 Registros de Preço - Partners 4, 1, 2</h1>";

// Resumo
echo "<div class='resumo'>";
echo "<h2>📊 Resumo</h2>";
$r = $conn->query("
    SELECT pp.partner_id, COUNT(*) as total,
           MIN(pp.price) as menor_preco,
           MAX(pp.price) as maior_preco,
           AVG(pp.price) as media_preco
    FROM om_market_products_price pp
    WHERE pp.partner_id IN (1, 2, 4)
    GROUP BY pp.partner_id
    ORDER BY total DESC
");
echo "<table>";
echo "<tr><th>Partner ID</th><th>Total Produtos</th><th>Menor Preço</th><th>Maior Preço</th><th>Média</th></tr>";
while ($row = $r->fetch_assoc()) {
    echo "<tr>";
    echo "<td>{$row['partner_id']}</td>";
    echo "<td>{$row['total']}</td>";
    echo "<td>R$ " . number_format($row['menor_preco'], 2, ',', '.') . "</td>";
    echo "<td>R$ " . number_format($row['maior_preco'], 2, ',', '.') . "</td>";
    echo "<td>R$ " . number_format($row['media_preco'], 2, ',', '.') . "</td>";
    echo "</tr>";
}
echo "</table>";
echo "</div>";

// Partner 4
echo "<div class='partner'>";
echo "<h2>🏪 Partner ID = 4 (22.610 produtos)</h2>";
echo "<p>Amostra dos primeiros 50 produtos:</p>";

$r = $conn->query("
    SELECT pp.*, pb.name, pb.brand, pb.barcode, pb.image
    FROM om_market_products_price pp
    LEFT JOIN om_market_products_base pb ON pp.product_id = pb.product_id
    WHERE pp.partner_id = 4
    ORDER BY pp.id DESC
    LIMIT 50
");

echo "<table>";
echo "<tr><th>ID</th><th>Foto</th><th>Produto</th><th>Marca</th><th>Código</th><th>Preço</th><th>Promo</th><th>Estoque</th><th>Status</th></tr>";
while ($row = $r->fetch_assoc()) {
    $img = $row['image'] ? "<img src='{$row['image']}' onerror=\"this.style.display='none'\">" : '-';
    $promo = $row['price_promo'] > 0 ? "R$ " . number_format($row['price_promo'], 2, ',', '.') : '-';
    $status = $row['status'] == 1 ? '✅' : '❌';
    echo "<tr>";
    echo "<td>{$row['product_id']}</td>";
    echo "<td>$img</td>";
    echo "<td>" . htmlspecialchars(substr($row['name'] ?? '', 0, 40)) . "</td>";
    echo "<td>" . htmlspecialchars($row['brand'] ?? '') . "</td>";
    echo "<td>{$row['barcode']}</td>";
    echo "<td>R$ " . number_format($row['price'], 2, ',', '.') . "</td>";
    echo "<td>$promo</td>";
    echo "<td>{$row['stock']}</td>";
    echo "<td>$status</td>";
    echo "</tr>";
}
echo "</table>";
echo "</div>";

// Partner 1
echo "<div class='partner'>";
echo "<h2>🏪 Partner ID = 1 (84 produtos)</h2>";

$r = $conn->query("
    SELECT pp.*, pb.name, pb.brand, pb.barcode
    FROM om_market_products_price pp
    LEFT JOIN om_market_products_base pb ON pp.product_id = pb.product_id
    WHERE pp.partner_id = 1
    ORDER BY pp.id DESC
    LIMIT 30
");

echo "<table>";
echo "<tr><th>ID</th><th>Produto</th><th>Marca</th><th>Código</th><th>Preço</th><th>Promo</th><th>Estoque</th></tr>";
while ($row = $r->fetch_assoc()) {
    $promo = $row['price_promo'] > 0 ? "R$ " . number_format($row['price_promo'], 2, ',', '.') : '-';
    echo "<tr>";
    echo "<td>{$row['product_id']}</td>";
    echo "<td>" . htmlspecialchars(substr($row['name'] ?? '', 0, 40)) . "</td>";
    echo "<td>" . htmlspecialchars($row['brand'] ?? '') . "</td>";
    echo "<td>{$row['barcode']}</td>";
    echo "<td>R$ " . number_format($row['price'], 2, ',', '.') . "</td>";
    echo "<td>$promo</td>";
    echo "<td>{$row['stock']}</td>";
    echo "</tr>";
}
echo "</table>";
echo "</div>";

// Partner 2
echo "<div class='partner'>";
echo "<h2>🏪 Partner ID = 2 (1 produto)</h2>";

$r = $conn->query("
    SELECT pp.*, pb.name, pb.brand, pb.barcode
    FROM om_market_products_price pp
    LEFT JOIN om_market_products_base pb ON pp.product_id = pb.product_id
    WHERE pp.partner_id = 2
");

echo "<table>";
echo "<tr><th>ID</th><th>Produto</th><th>Marca</th><th>Código</th><th>Preço</th><th>Promo</th><th>Estoque</th></tr>";
while ($row = $r->fetch_assoc()) {
    $promo = $row['price_promo'] > 0 ? "R$ " . number_format($row['price_promo'], 2, ',', '.') : '-';
    echo "<tr>";
    echo "<td>{$row['product_id']}</td>";
    echo "<td>" . htmlspecialchars($row['name'] ?? '') . "</td>";
    echo "<td>" . htmlspecialchars($row['brand'] ?? '') . "</td>";
    echo "<td>{$row['barcode']}</td>";
    echo "<td>R$ " . number_format($row['price'], 2, ',', '.') . "</td>";
    echo "<td>$promo</td>";
    echo "<td>{$row['stock']}</td>";
    echo "</tr>";
}
echo "</table>";
echo "</div>";

// Data de criação
echo "<div class='partner'>";
echo "<h2>📅 Quando foram criados?</h2>";

$r = $conn->query("
    SELECT partner_id, 
           MIN(created_at) as primeiro,
           MAX(created_at) as ultimo
    FROM om_market_products_price
    WHERE partner_id IN (1, 2, 4)
    GROUP BY partner_id
");

echo "<table>";
echo "<tr><th>Partner</th><th>Primeiro Registro</th><th>Último Registro</th></tr>";
while ($row = $r->fetch_assoc()) {
    echo "<tr>";
    echo "<td>{$row['partner_id']}</td>";
    echo "<td>{$row['primeiro']}</td>";
    echo "<td>{$row['ultimo']}</td>";
    echo "</tr>";
}
echo "</table>";
echo "</div>";

$conn->close();
echo "</body></html>";
