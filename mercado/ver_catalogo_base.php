<?php
require_once __DIR__ . '/config/database.php';
header('Content-Type: text/html; charset=utf-8');

$conn = getMySQLi();
if ($conn->connect_error) die("Erro: " . $conn->connect_error);
$conn->set_charset('utf8mb4');

echo "<html><head><meta charset='UTF-8'><title>Catálogo Base do Mercado</title>";
echo "<style>
body { font-family: Arial, sans-serif; background: #1a1a2e; color: #fff; padding: 20px; }
h1, h2, h3 { color: #00d4aa; }
table { width: 100%; border-collapse: collapse; margin: 15px 0; background: #16213e; }
th, td { padding: 10px; text-align: left; border-bottom: 1px solid #333; font-size: 12px; }
th { background: #0f3460; color: #00d4aa; position: sticky; top: 0; }
tr:hover { background: #1e3a5f; }
.box { background: #16213e; padding: 15px; border-radius: 10px; margin: 15px 0; }
img { max-width: 60px; max-height: 60px; border-radius: 5px; background: #fff; }
.ok { color: #00d4aa; }
.erro { color: #ff6b6b; }
.aviso { color: #ffc107; }
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
.stat { background: #0f3460; padding: 20px; border-radius: 10px; text-align: center; }
.stat-num { font-size: 32px; color: #00d4aa; font-weight: bold; }
.stat-label { font-size: 12px; color: #888; margin-top: 5px; }
.filter { margin: 15px 0; }
.filter input, .filter select { padding: 10px; border-radius: 5px; border: 1px solid #333; background: #0f3460; color: #fff; margin-right: 10px; }
.filter button { padding: 10px 20px; background: #00d4aa; color: #000; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; }
</style></head><body>";

echo "<h1>📦 Catálogo Base do Mercado (om_market_products_base)</h1>";

// ==========================================
// ESTATÍSTICAS GERAIS
// ==========================================
echo "<div class='stats'>";

// Total
$r = $conn->query("SELECT COUNT(*) as t FROM om_market_products_base");
$total = $r->fetch_assoc()['t'];
echo "<div class='stat'><div class='stat-num'>$total</div><div class='stat-label'>Total Produtos</div></div>";

// Com imagem
$r = $conn->query("SELECT COUNT(*) as t FROM om_market_products_base WHERE image IS NOT NULL AND image != ''");
$comImg = $r->fetch_assoc()['t'];
echo "<div class='stat'><div class='stat-num'>$comImg</div><div class='stat-label'>Com Imagem</div></div>";

// Com código de barras
$r = $conn->query("SELECT COUNT(*) as t FROM om_market_products_base WHERE barcode IS NOT NULL AND barcode != ''");
$comBarcode = $r->fetch_assoc()['t'];
echo "<div class='stat'><div class='stat-num'>$comBarcode</div><div class='stat-label'>Com Código de Barras</div></div>";

// Com marca
$r = $conn->query("SELECT COUNT(*) as t FROM om_market_products_base WHERE brand IS NOT NULL AND brand != ''");
$comMarca = $r->fetch_assoc()['t'];
echo "<div class='stat'><div class='stat-num'>$comMarca</div><div class='stat-label'>Com Marca</div></div>";

// Ativos
$r = $conn->query("SELECT COUNT(*) as t FROM om_market_products_base WHERE status = '1'");
$ativos = $r->fetch_assoc()['t'];
echo "<div class='stat'><div class='stat-num'>$ativos</div><div class='stat-label'>Ativos</div></div>";

echo "</div>";

// ==========================================
// POR CATEGORIA
// ==========================================
echo "<div class='box'>";
echo "<h2>📂 Por Categoria</h2>";

$r = $conn->query("
    SELECT c.category_id, c.name as categoria, COUNT(p.product_id) as total
    FROM om_market_categories c
    LEFT JOIN om_market_products_base p ON p.category_id = c.category_id
    GROUP BY c.category_id
    ORDER BY total DESC
    LIMIT 20
");

echo "<table>";
echo "<tr><th>ID</th><th>Categoria</th><th>Produtos</th></tr>";
while ($row = $r->fetch_assoc()) {
    echo "<tr>";
    echo "<td>{$row['category_id']}</td>";
    echo "<td>" . htmlspecialchars($row['categoria'] ?? 'Sem nome') . "</td>";
    echo "<td>{$row['total']}</td>";
    echo "</tr>";
}
echo "</table>";
echo "</div>";

// ==========================================
// POR MARCA (TOP 30)
// ==========================================
echo "<div class='box'>";
echo "<h2>🏷️ Top 30 Marcas</h2>";

$r = $conn->query("
    SELECT brand, COUNT(*) as total
    FROM om_market_products_base
    WHERE brand IS NOT NULL AND brand != ''
    GROUP BY brand
    ORDER BY total DESC
    LIMIT 30
");

echo "<table>";
echo "<tr><th>Marca</th><th>Produtos</th></tr>";
while ($row = $r->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['brand']) . "</td>";
    echo "<td>{$row['total']}</td>";
    echo "</tr>";
}
echo "</table>";
echo "</div>";

// ==========================================
// QUALIDADE DOS DADOS
// ==========================================
echo "<div class='box'>";
echo "<h2>📊 Qualidade dos Dados</h2>";

echo "<table>";
echo "<tr><th>Campo</th><th>Preenchido</th><th>Vazio</th><th>%</th></tr>";

$campos = [
    'name' => 'Nome',
    'brand' => 'Marca',
    'barcode' => 'Código de Barras',
    'image' => 'Imagem',
    'description' => 'Descrição',
    'ingredients' => 'Ingredientes',
    'calories' => 'Calorias',
    'unit' => 'Unidade'
];

foreach ($campos as $campo => $label) {
    $r = $conn->query("SELECT COUNT(*) as t FROM om_market_products_base WHERE $campo IS NOT NULL AND $campo != ''");
    $preenchido = $r->fetch_assoc()['t'];
    $vazio = $total - $preenchido;
    $pct = round(($preenchido / $total) * 100, 1);
    
    $cor = $pct > 80 ? 'ok' : ($pct > 50 ? 'aviso' : 'erro');
    
    echo "<tr>";
    echo "<td>$label</td>";
    echo "<td class='ok'>$preenchido</td>";
    echo "<td class='erro'>$vazio</td>";
    echo "<td class='$cor'>$pct%</td>";
    echo "</tr>";
}
echo "</table>";
echo "</div>";

// ==========================================
// AMOSTRA DE PRODUTOS
// ==========================================
echo "<div class='box'>";
echo "<h2>🛒 Amostra de Produtos (últimos 100)</h2>";

$r = $conn->query("
    SELECT p.*, c.name as categoria
    FROM om_market_products_base p
    LEFT JOIN om_market_categories c ON p.category_id = c.category_id
    ORDER BY p.product_id DESC
    LIMIT 100
");

echo "<table>";
echo "<tr><th>ID</th><th>Foto</th><th>Nome</th><th>Marca</th><th>Código</th><th>Categoria</th><th>Unidade</th><th>Status</th></tr>";
while ($row = $r->fetch_assoc()) {
    $img = $row['image'] ? "<img src='{$row['image']}' onerror=\"this.src='https://via.placeholder.com/60?text=Sem'\">" : '<span class="erro">-</span>';
    $status = $row['status'] == 1 ? '<span class="ok">✅</span>' : '<span class="erro">❌</span>';
    $barcode = $row['barcode'] ?: '<span class="erro">-</span>';
    $marca = $row['brand'] ?: '<span class="aviso">-</span>';
    
    echo "<tr>";
    echo "<td>{$row['product_id']}</td>";
    echo "<td>$img</td>";
    echo "<td>" . htmlspecialchars(substr($row['name'] ?? '', 0, 50)) . "</td>";
    echo "<td>$marca</td>";
    echo "<td>$barcode</td>";
    echo "<td>" . htmlspecialchars($row['categoria'] ?? '-') . "</td>";
    echo "<td>" . ($row['unit'] ?? '-') . "</td>";
    echo "<td>$status</td>";
    echo "</tr>";
}
echo "</table>";
echo "</div>";

// ==========================================
// PRODUTOS COM DADOS COMPLETOS
// ==========================================
echo "<div class='box'>";
echo "<h2>⭐ Produtos com Dados Completos (nome + marca + código + imagem)</h2>";

$r = $conn->query("
    SELECT p.*, c.name as categoria
    FROM om_market_products_base p
    LEFT JOIN om_market_categories c ON p.category_id = c.category_id
    WHERE p.name IS NOT NULL AND p.name != ''
    AND p.brand IS NOT NULL AND p.brand != ''
    AND p.barcode IS NOT NULL AND p.barcode != ''
    AND p.image IS NOT NULL AND p.image != ''
    ORDER BY p.product_id DESC
    LIMIT 50
");

$completos = $r->num_rows;
echo "<p>Mostrando 50 de aproximadamente <strong>muitos produtos completos</strong>:</p>";

echo "<table>";
echo "<tr><th>ID</th><th>Foto</th><th>Nome</th><th>Marca</th><th>Código</th><th>Categoria</th></tr>";
while ($row = $r->fetch_assoc()) {
    $img = "<img src='{$row['image']}' onerror=\"this.src='https://via.placeholder.com/60?text=Erro'\">";
    
    echo "<tr>";
    echo "<td>{$row['product_id']}</td>";
    echo "<td>$img</td>";
    echo "<td>" . htmlspecialchars(substr($row['name'], 0, 50)) . "</td>";
    echo "<td>" . htmlspecialchars($row['brand']) . "</td>";
    echo "<td>{$row['barcode']}</td>";
    echo "<td>" . htmlspecialchars($row['categoria'] ?? '-') . "</td>";
    echo "</tr>";
}
echo "</table>";
echo "</div>";

$conn->close();
echo "</body></html>";
