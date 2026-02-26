<?php
require_once __DIR__ . '/config/database.php';
/**
 * 🛒 POPULAR PRODUTOS NOS MERCADOS
 * Upload em: /mercado/POPULAR_PRODUTOS.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🛒 Popular Produtos nos Mercados</h1><pre>";

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "✅ Conectado\n\n";

// Catálogo de produtos
$catalogo = [
    'Laticínios' => [
        ['Leite Integral 1L', 5.99],
        ['Leite Desnatado 1L', 6.29],
        ['Queijo Mussarela 200g', 12.90],
        ['Queijo Prato 200g', 14.50],
        ['Iogurte Natural 170g', 3.99],
        ['Iogurte Grego 100g', 4.50],
        ['Manteiga 200g', 9.90],
        ['Requeijão 200g', 7.80],
        ['Creme de Leite 200g', 3.50],
        ['Leite Condensado 395g', 6.90],
    ],
    'Padaria' => [
        ['Pão Francês (10 unidades)', 5.90],
        ['Pão de Forma 500g', 7.50],
        ['Pão Integral 400g', 8.90],
        ['Bolo de Chocolate 500g', 18.90],
        ['Croissant (4 unidades)', 12.00],
        ['Sonho (4 unidades)', 10.00],
    ],
    'Carnes' => [
        ['Frango Inteiro Congelado 1kg', 12.90],
        ['Peito de Frango 1kg', 19.90],
        ['Carne Moída 500g', 22.90],
        ['Picanha 1kg', 79.90],
        ['Alcatra 1kg', 49.90],
        ['Costela Bovina 1kg', 34.90],
        ['Linguiça Toscana 500g', 15.90],
        ['Bacon 200g', 14.90],
    ],
    'Bebidas' => [
        ['Coca-Cola 2L', 10.90],
        ['Coca-Cola Zero 2L', 10.90],
        ['Guaraná Antarctica 2L', 8.90],
        ['Água Mineral 1.5L', 2.99],
        ['Água com Gás 500ml', 3.50],
        ['Suco de Laranja 1L', 8.90],
        ['Suco de Uva 1L', 9.90],
        ['Cerveja Skol 350ml (12un)', 29.90],
        ['Cerveja Heineken 350ml (6un)', 32.90],
    ],
    'Hortifruti' => [
        ['Banana Prata 1kg', 6.90],
        ['Maçã Fuji 1kg', 12.90],
        ['Laranja Pera 1kg', 5.90],
        ['Tomate 1kg', 8.90],
        ['Batata 1kg', 6.50],
        ['Cebola 1kg', 5.90],
        ['Alface Crespa', 3.50],
        ['Cenoura 1kg', 7.90],
        ['Limão 1kg', 4.90],
        ['Abacate 1kg', 9.90],
    ],
    'Mercearia' => [
        ['Arroz Branco 5kg', 24.90],
        ['Feijão Carioca 1kg', 8.90],
        ['Feijão Preto 1kg', 7.90],
        ['Açúcar 1kg', 4.90],
        ['Sal 1kg', 2.50],
        ['Óleo de Soja 900ml', 7.90],
        ['Azeite Extra Virgem 500ml', 29.90],
        ['Café 500g', 18.90],
        ['Macarrão Espaguete 500g', 4.50],
        ['Molho de Tomate 340g', 3.90],
        ['Farinha de Trigo 1kg', 5.90],
        ['Achocolatado 400g', 8.90],
    ],
    'Limpeza' => [
        ['Detergente 500ml', 2.50],
        ['Sabão em Pó 1kg', 12.90],
        ['Amaciante 2L', 14.90],
        ['Água Sanitária 2L', 6.90],
        ['Desinfetante 2L', 8.90],
        ['Esponja (3 unidades)', 4.50],
        ['Papel Higiênico (12 rolos)', 19.90],
        ['Papel Toalha (2 rolos)', 7.90],
    ],
    'Higiene' => [
        ['Sabonete (3 unidades)', 6.90],
        ['Shampoo 350ml', 14.90],
        ['Condicionador 350ml', 14.90],
        ['Creme Dental 90g', 4.90],
        ['Desodorante 150ml', 12.90],
        ['Escova de Dentes', 8.90],
    ],
];

// Buscar mercados
$mercados = $pdo->query("SELECT partner_id, name FROM om_market_partners WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);

if (empty($mercados)) {
    echo "❌ Nenhum mercado encontrado! Criando mercados...\n\n";
    
    $mercadosNomes = [
        ['Super Economia Centro', 'Belo Horizonte', 'MG'],
        ['Mercado Bom Preço', 'São Paulo', 'SP'],
        ['Hipermercado Central', 'Rio de Janeiro', 'RJ'],
        ['Market Express', 'Brasília', 'DF'],
        ['Supermercado Família', 'Salvador', 'BA'],
    ];
    
    $stmt = $pdo->prepare("INSERT INTO om_market_partners (name, city, state, status) VALUES (?, ?, ?, 'active')");
    foreach ($mercadosNomes as $m) {
        $stmt->execute($m);
        echo "   ✅ Mercado criado: {$m[0]}\n";
    }
    
    $mercados = $pdo->query("SELECT partner_id, name FROM om_market_partners WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
    echo "\n";
}

echo "🏪 Mercados encontrados: " . count($mercados) . "\n\n";

// Limpar produtos antigos (opcional - comentar se quiser manter)
// $pdo->exec("DELETE FROM om_market_products");
// echo "🧹 Produtos antigos removidos\n\n";

// Verificar estrutura da tabela
echo "📋 Verificando estrutura de om_market_products...\n";
$colunas = $pdo->query("SHOW COLUMNS FROM om_market_products")->fetchAll(PDO::FETCH_COLUMN);
echo "   Colunas: " . implode(', ', $colunas) . "\n\n";

// Preparar INSERT
$stmt = $pdo->prepare("INSERT INTO om_market_products (partner_id, name, category, price, stock, status) VALUES (?, ?, ?, ?, ?, 'active')");

$totalProdutos = 0;

foreach ($mercados as $mercado) {
    echo "🏪 {$mercado['name']}:\n";
    $produtosMercado = 0;
    
    foreach ($catalogo as $categoria => $produtos) {
        // Pegar produtos aleatórios de cada categoria (50-80%)
        $qtdProdutos = rand(ceil(count($produtos) * 0.5), count($produtos));
        $produtosAleatorios = array_rand($produtos, $qtdProdutos);
        if (!is_array($produtosAleatorios)) $produtosAleatorios = [$produtosAleatorios];
        
        foreach ($produtosAleatorios as $idx) {
            $p = $produtos[$idx];
            // Variar preço em ±10%
            $preco = round($p[1] * (1 + (rand(-10, 10) / 100)), 2);
            $estoque = rand(20, 200);
            
            try {
                $stmt->execute([
                    $mercado['partner_id'],
                    $p[0],
                    $categoria,
                    $preco,
                    $estoque
                ]);
                $produtosMercado++;
                $totalProdutos++;
            } catch (Exception $e) {
                // Produto duplicado, ignora
            }
        }
    }
    
    echo "   ✅ $produtosMercado produtos adicionados\n";
}

// Atualizar product_id = id onde necessário
try {
    $pdo->exec("UPDATE om_market_products SET product_id = id WHERE product_id IS NULL OR product_id = 0");
    echo "\n✅ product_id sincronizado\n";
} catch (Exception $e) {
    // Coluna pode não existir, ignora
}

// Resultado final
echo "\n════════════════════════════════════════\n";
echo "🎉 PRODUTOS POPULADOS!\n\n";

$stats = $pdo->query("SELECT 
    COUNT(*) as total,
    COUNT(DISTINCT partner_id) as mercados,
    COUNT(DISTINCT category) as categorias
FROM om_market_products WHERE status = 'active'")->fetch(PDO::FETCH_ASSOC);

echo "📊 RESULTADO:\n";
echo "   🛒 Total de produtos: {$stats['total']}\n";
echo "   🏪 Mercados com produtos: {$stats['mercados']}\n";
echo "   📁 Categorias: {$stats['categorias']}\n";

// Mostrar por categoria
echo "\n📁 Por categoria:\n";
$porCategoria = $pdo->query("SELECT category, COUNT(*) as c FROM om_market_products WHERE status = 'active' GROUP BY category ORDER BY c DESC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($porCategoria as $cat) {
    echo "   {$cat['category']}: {$cat['c']} produtos\n";
}

echo "</pre>";

echo "<p style='margin-top:20px;'>";
echo "<a href='ROBO_SIMULADOR.php' style='display:inline-block;padding:14px 28px;background:#10b981;color:white;text-decoration:none;border-radius:10px;margin:5px;'>🤖 Rodar Simulação</a>";
echo "<a href='ROBO_CLAUDE.php' style='display:inline-block;padding:14px 28px;background:#6366f1;color:white;text-decoration:none;border-radius:10px;margin:5px;'>📊 Dashboard</a>";
echo "</p>";
?>
