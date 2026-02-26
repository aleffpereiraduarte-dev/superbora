<?php
require_once __DIR__ . '/config/database.php';
/**
 * 🔧 CORRIGIR CATEGORY_ID E INVESTIGAR CATEGORIAS
 */

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "<style>
body{font-family:monospace;background:#111;color:#0f0;padding:20px}
h2{color:#0ff;margin:25px 0 15px}
pre{background:#000;padding:15px;border-radius:8px}
table{border-collapse:collapse;width:100%}
th,td{border:1px solid #333;padding:8px;text-align:left}
th{background:#222}
.ok{color:#0f0}.err{color:#f00}
.btn{padding:15px 30px;background:#0f0;color:#000;border:none;font-size:16px;cursor:pointer;border-radius:8px;margin:10px 5px}
.btn-blue{background:#3498db;color:#fff}
</style>";

echo "<h1>🔧 Corrigir Categorias</h1>";

// 1. Ver estrutura de om_market_categories
echo "<h2>1. Categorias do Mercado (om_market_categories)</h2>";
$cats = $pdo->query("SELECT * FROM om_market_categories WHERE parent_id = 0 OR parent_id IS NULL ORDER BY name LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
echo "<table><tr><th>ID</th><th>Nome</th><th>Slug</th><th>Status</th></tr>";
foreach ($cats as $c) {
    echo "<tr><td>{$c['category_id']}</td><td>{$c['name']}</td><td>" . ($c['slug'] ?? '-') . "</td><td>{$c['status']}</td></tr>";
}
echo "</table>";

// 2. Buscar categorias que batem com os produtos
echo "<h2>2. Mapear Categorias</h2>";
$categorias_produtos = $pdo->query("SELECT DISTINCT category FROM om_market_products WHERE partner_id = 100")->fetchAll(PDO::FETCH_COLUMN);

echo "<p>Categorias nos produtos do partner 100:</p>";
echo "<ul>";
foreach ($categorias_produtos as $cat) {
    // Tentar encontrar na tabela de categorias
    $stmt = $pdo->prepare("SELECT category_id, name FROM om_market_categories WHERE name LIKE ? OR slug LIKE ? LIMIT 1");
    $stmt->execute(["%$cat%", "%$cat%"]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($match) {
        echo "<li class='ok'>$cat → <strong>{$match['name']}</strong> (ID: {$match['category_id']})</li>";
    } else {
        echo "<li class='err'>$cat → <strong>NÃO ENCONTRADA!</strong></li>";
    }
}
echo "</ul>";

// 3. Criar categorias faltantes e mapear
echo "<h2>3. Corrigir Tudo</h2>";
echo "<form method='post'>";
echo "<button type='submit' name='criar_categorias' class='btn'>📁 Criar Categorias Faltantes</button>";
echo "<button type='submit' name='mapear_ids' class='btn btn-blue'>🔗 Mapear category_id</button>";
echo "</form>";

if (isset($_POST['criar_categorias'])) {
    $categorias_criar = [
        ['name' => 'Laticínios', 'slug' => 'laticinios', 'icon' => '🥛'],
        ['name' => 'Padaria', 'slug' => 'padaria', 'icon' => '🍞'],
        ['name' => 'Bebidas', 'slug' => 'bebidas', 'icon' => '🥤'],
        ['name' => 'Açougue', 'slug' => 'acougue', 'icon' => '🥩'],
        ['name' => 'Carnes', 'slug' => 'carnes', 'icon' => '🥩'],
        ['name' => 'Hortifruti', 'slug' => 'hortifruti', 'icon' => '🥬'],
        ['name' => 'Limpeza', 'slug' => 'limpeza', 'icon' => '🧹'],
        ['name' => 'Mercearia', 'slug' => 'mercearia', 'icon' => '🛒'],
        ['name' => 'Congelados', 'slug' => 'congelados', 'icon' => '🧊'],
        ['name' => 'Higiene', 'slug' => 'higiene', 'icon' => '🧴'],
    ];
    
    $criadas = 0;
    foreach ($categorias_criar as $cat) {
        // Verificar se já existe
        $stmt = $pdo->prepare("SELECT category_id FROM om_market_categories WHERE name = ? OR slug = ?");
        $stmt->execute([$cat['name'], $cat['slug']]);
        if (!$stmt->fetch()) {
            try {
                $stmt = $pdo->prepare("INSERT INTO om_market_categories (name, slug, icon, status, sort_order) VALUES (?, ?, ?, 1, 0)");
                $stmt->execute([$cat['name'], $cat['slug'], $cat['icon']]);
                $criadas++;
                echo "<p class='ok'>✅ Criada: {$cat['name']}</p>";
            } catch (Exception $e) {
                echo "<p class='err'>❌ Erro ao criar {$cat['name']}: {$e->getMessage()}</p>";
            }
        } else {
            echo "<p>⏭️ Já existe: {$cat['name']}</p>";
        }
    }
    echo "<p><strong>Total criadas: $criadas</strong></p>";
}

if (isset($_POST['mapear_ids'])) {
    // Buscar mapeamento
    $map = [];
    $cats = $pdo->query("SELECT category_id, name FROM om_market_categories")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cats as $c) {
        $map[strtolower($c['name'])] = $c['category_id'];
    }
    
    // Atualizar produtos
    $categorias_produtos = $pdo->query("SELECT DISTINCT category FROM om_market_products WHERE partner_id = 100")->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($categorias_produtos as $cat) {
        $cat_lower = strtolower($cat);
        if (isset($map[$cat_lower])) {
            $cat_id = $map[$cat_lower];
            $stmt = $pdo->prepare("UPDATE om_market_products SET category_id = ? WHERE partner_id = 100 AND category = ?");
            $stmt->execute([$cat_id, $cat]);
            echo "<p class='ok'>✅ $cat → category_id = $cat_id</p>";
        } else {
            echo "<p class='err'>❌ $cat não encontrada no mapeamento</p>";
        }
    }
}

// 4. Verificar resultado
echo "<h2>4. Verificar Resultado</h2>";
$result = $pdo->query("SELECT id, name, category, category_id FROM om_market_products WHERE partner_id = 100")->fetchAll(PDO::FETCH_ASSOC);
echo "<table><tr><th>ID</th><th>Nome</th><th>Categoria</th><th>category_id</th></tr>";
foreach ($result as $r) {
    $class = $r['category_id'] ? 'ok' : 'err';
    echo "<tr><td>{$r['id']}</td><td>{$r['name']}</td><td>{$r['category']}</td><td class='$class'>" . ($r['category_id'] ?: 'NULL') . "</td></tr>";
}
echo "</table>";

// 5. Testar endpoint da API
echo "<h2>5. Testar Possíveis Endpoints</h2>";

$endpoints = [
    '/mercado/api/products.php?partner_id=100',
    '/mercado/api/produtos.php?partner_id=100',
    '/index.php?route=api/market/products&partner_id=100',
];

foreach ($endpoints as $ep) {
    echo "<p><a href='$ep' target='_blank' style='color:#0ff'>$ep</a></p>";
}

// 6. Verificar se tem arquivo de API
echo "<h2>6. Link direto para testar</h2>";
echo "<a href='/mercado/' class='btn' style='text-decoration:none;display:inline-block'>🛒 IR PARA /mercado/</a>";

// 7. Sessão
echo "<h2>7. Sessão Atual</h2>";
session_name('OCSESSID');
session_start();
echo "<pre>";
echo "market_partner_id: " . ($_SESSION['market_partner_id'] ?? 'NÃO DEFINIDO') . "\n";
echo "market_cep: " . ($_SESSION['market_cep'] ?? 'NÃO DEFINIDO') . "\n";
echo "</pre>";
?>
