<?php
require_once __DIR__ . '/config/database.php';
/**
 * 🔍 INVESTIGAR COMO /mercado/ BUSCA PRODUTOS
 */

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "<style>
body{font-family:monospace;background:#111;color:#0f0;padding:20px;font-size:13px}
h2{color:#0ff;margin:25px 0 15px;border-bottom:1px solid #333;padding-bottom:10px}
.ok{color:#0f0}.err{color:#f00}.warn{color:#ff0}
pre{background:#000;padding:15px;border-radius:8px;overflow-x:auto;margin:10px 0}
table{border-collapse:collapse;width:100%;margin:10px 0}
th,td{border:1px solid #333;padding:8px;text-align:left}
th{background:#222}
.box{background:#1a1a1a;border:1px solid #333;padding:15px;margin:15px 0;border-radius:8px}
</style>";

echo "<h1>🔍 Investigação Profunda - /mercado/</h1>";

// 1. Verificar TODAS as possíveis tabelas de produtos que o mercado pode usar
echo "<h2>1. Tabelas que podem ter produtos do mercado</h2>";

$tabelas_produtos = [
    'om_market_products' => 'partner_id',
    'om_market_products_price' => 'partner_id', 
    'om_market_products_base' => null,
    'om_market_partner_products' => 'partner_id',
    'om_market_essential_products' => 'partner_id',
];

foreach ($tabelas_produtos as $tabela => $campo_partner) {
    echo "<div class='box'>";
    echo "<h3>$tabela</h3>";
    
    try {
        $total = $pdo->query("SELECT COUNT(*) FROM $tabela")->fetchColumn();
        echo "<p>Total: <strong>$total</strong></p>";
        
        if ($campo_partner && $total > 0) {
            // Ver por partner
            $por_partner = $pdo->query("SELECT $campo_partner, COUNT(*) as qtd FROM $tabela GROUP BY $campo_partner ORDER BY qtd DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
            echo "<p>Por partner_id:</p><pre>" . print_r($por_partner, true) . "</pre>";
            
            // Ver amostra do partner 100
            $amostra = $pdo->query("SELECT * FROM $tabela WHERE $campo_partner = 100 LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
            if ($amostra) {
                echo "<p>Amostra partner_id=100:</p><pre>" . print_r($amostra, true) . "</pre>";
            }
        }
    } catch (Exception $e) {
        echo "<p class='err'>Erro: {$e->getMessage()}</p>";
    }
    echo "</div>";
}

// 2. Verificar se existe API de produtos
echo "<h2>2. Verificar APIs/Endpoints de produtos</h2>";
echo "<p>Possíveis endpoints que o frontend pode chamar:</p>";
echo "<ul>";
echo "<li>/mercado/api/products.php</li>";
echo "<li>/mercado/index.php?route=api/products</li>";
echo "<li>/index.php?route=extension/module/market/products</li>";
echo "<li>/index.php?route=api/market/products</li>";
echo "</ul>";

// 3. Verificar estrutura da tabela om_market_products detalhada
echo "<h2>3. Campos importantes em om_market_products</h2>";
$campos = $pdo->query("SELECT 
    SUM(CASE WHEN is_featured = 1 THEN 1 ELSE 0 END) as featured,
    SUM(CASE WHEN in_stock = 1 THEN 1 ELSE 0 END) as in_stock,
    SUM(CASE WHEN status = '1' THEN 1 ELSE 0 END) as ativos,
    SUM(CASE WHEN stock > 0 THEN 1 ELSE 0 END) as com_estoque,
    SUM(CASE WHEN quantity > 0 THEN 1 ELSE 0 END) as com_quantity,
    SUM(CASE WHEN image IS NOT NULL AND image != '' THEN 1 ELSE 0 END) as com_imagem,
    COUNT(*) as total
FROM om_market_products WHERE partner_id = 100")->fetch(PDO::FETCH_ASSOC);

echo "<table>";
echo "<tr><th>Campo</th><th>Qtd OK</th><th>Total</th><th>%</th></tr>";
foreach ($campos as $campo => $valor) {
    if ($campo != 'total') {
        $pct = $campos['total'] > 0 ? round(($valor / $campos['total']) * 100) : 0;
        $class = $pct >= 80 ? 'ok' : ($pct >= 50 ? 'warn' : 'err');
        echo "<tr><td>$campo</td><td class='$class'>$valor</td><td>{$campos['total']}</td><td>$pct%</td></tr>";
    }
}
echo "</table>";

// 4. Verificar se existe relação com om_market_products_base
echo "<h2>4. Relação om_market_products_base</h2>";
$base_count = $pdo->query("SELECT COUNT(*) FROM om_market_products_base")->fetchColumn();
echo "<p>om_market_products_base tem <strong>$base_count</strong> produtos</p>";

// Verificar se om_market_products tem product_id que referencia base
$com_product_id = $pdo->query("SELECT COUNT(*) FROM om_market_products WHERE product_id IS NOT NULL AND product_id > 0")->fetchColumn();
echo "<p>om_market_products com product_id: <strong>$com_product_id</strong></p>";

// 5. Verificar om_market_essential_products
echo "<h2>5. om_market_essential_products (produtos essenciais)</h2>";
$essenciais = $pdo->query("SELECT * FROM om_market_essential_products LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>" . print_r($essenciais, true) . "</pre>";

// 6. Query que DEVE funcionar
echo "<h2>6. Testar Query Direta</h2>";

$queries = [
    "Produtos ativos do partner 100" => "SELECT id, name, category, price FROM om_market_products WHERE partner_id = 100 AND status = '1' LIMIT 5",
    "Produtos featured do partner 100" => "SELECT id, name, category, price FROM om_market_products WHERE partner_id = 100 AND is_featured = 1 LIMIT 5",
    "Produtos in_stock do partner 100" => "SELECT id, name, category, price FROM om_market_products WHERE partner_id = 100 AND in_stock = 1 LIMIT 5",
    "Produtos com TUDO do partner 100" => "SELECT id, name, category, price FROM om_market_products WHERE partner_id = 100 AND status = '1' AND is_featured = 1 AND in_stock = 1 LIMIT 5",
];

foreach ($queries as $desc => $sql) {
    echo "<div class='box'>";
    echo "<h4>$desc</h4>";
    echo "<code>$sql</code>";
    $result = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $count = count($result);
    echo "<p class='" . ($count > 0 ? 'ok' : 'err') . "'>Resultado: <strong>$count produtos</strong></p>";
    if ($count > 0) {
        echo "<pre>" . print_r($result, true) . "</pre>";
    }
    echo "</div>";
}

// 7. Verificar se o sistema usa category_id em vez de category
echo "<h2>7. Verificar category_id vs category</h2>";
$cats = $pdo->query("SELECT DISTINCT category, category_id FROM om_market_products WHERE partner_id = 100")->fetchAll(PDO::FETCH_ASSOC);
echo "<table><tr><th>category</th><th>category_id</th></tr>";
foreach ($cats as $c) {
    echo "<tr><td>{$c['category']}</td><td>" . ($c['category_id'] ?: 'NULL') . "</td></tr>";
}
echo "</table>";

// 8. Verificar se existe tabela de categorias do mercado
echo "<h2>8. Tabelas de categorias do mercado</h2>";
$cat_tables = $pdo->query("SHOW TABLES LIKE '%market%categ%'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($cat_tables as $t) {
    $count = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    echo "<p>$t: <strong>$count</strong></p>";
    if ($count > 0 && $count < 50) {
        $sample = $pdo->query("SELECT * FROM $t LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        echo "<pre>" . print_r($sample, true) . "</pre>";
    }
}

// 9. SOLUÇÃO FINAL - Atualizar TUDO
echo "<h2>9. 🔧 SOLUÇÃO - Atualizar TODOS os campos necessários</h2>";
echo "<form method='post'>";
echo "<button type='submit' name='fix_all' style='padding:20px 40px;background:#0f0;color:#000;font-size:18px;cursor:pointer;border:none;border-radius:10px'>🔧 CORRIGIR TODOS OS CAMPOS AGORA</button>";
echo "</form>";

if (isset($_POST['fix_all'])) {
    echo "<div class='box'>";
    
    // 1. Marcar todos como featured
    $pdo->exec("UPDATE om_market_products SET is_featured = 1 WHERE partner_id = 100 AND status = '1'");
    echo "<p class='ok'>✅ is_featured = 1</p>";
    
    // 2. Marcar todos como in_stock
    $pdo->exec("UPDATE om_market_products SET in_stock = 1 WHERE partner_id = 100 AND status = '1'");
    echo "<p class='ok'>✅ in_stock = 1</p>";
    
    // 3. Garantir quantity
    $pdo->exec("UPDATE om_market_products SET quantity = 999 WHERE partner_id = 100 AND (quantity IS NULL OR quantity = 0)");
    echo "<p class='ok'>✅ quantity = 999</p>";
    
    // 4. Garantir stock
    $pdo->exec("UPDATE om_market_products SET stock = 100 WHERE partner_id = 100 AND (stock IS NULL OR stock = 0)");
    echo "<p class='ok'>✅ stock = 100</p>";
    
    // 5. Categorias - adicionar category_id se existir tabela
    try {
        $cat_map = [
            'Laticínios' => 1,
            'Padaria' => 2,
            'Bebidas' => 3,
            'Carnes' => 4,
            'Açougue' => 4,
            'Hortifruti' => 5,
            'Limpeza' => 6,
        ];
        foreach ($cat_map as $cat => $id) {
            $pdo->exec("UPDATE om_market_products SET category_id = $id WHERE partner_id = 100 AND category = '$cat'");
        }
        echo "<p class='ok'>✅ category_id atualizado</p>";
    } catch (Exception $e) {}
    
    echo "<p style='color:#0ff;font-size:16px;margin-top:15px'>PRONTO! Agora teste o /mercado/</p>";
    echo "<a href='/mercado/' style='display:inline-block;padding:15px 30px;background:#0ff;color:#000;text-decoration:none;border-radius:8px;margin-top:10px'>🛒 IR PARA /mercado/</a>";
    echo "</div>";
}

// 10. Debug da sessão atual
echo "<h2>10. Sessão Atual (para debug)</h2>";
session_name('OCSESSID');
session_start();
echo "<pre>" . print_r($_SESSION, true) . "</pre>";
?>
