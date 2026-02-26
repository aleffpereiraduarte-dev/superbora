<?php
require_once __DIR__ . '/config/database.php';
/**
 * 🔧 CORREÇÃO DEFINITIVA - Popular om_market_products_price
 * 
 * O sistema /mercado/ usa:
 * - om_market_products_base (catálogo geral)
 * - om_market_products_price (preços por parceiro)
 * 
 * MAS a tabela om_market_products_price só tem partner_id 1 e 2!
 * Precisamos adicionar produtos para partner_id 100 (Valadares)
 */

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "<style>
body{font-family:'Segoe UI',sans-serif;background:#0a0a15;color:#fff;padding:30px}
h1{color:#f39c12;text-align:center}
h2{color:#3498db;margin:30px 0 15px;border-bottom:1px solid #333;padding-bottom:10px}
.box{background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:20px;margin:15px 0}
.ok{color:#2ecc71}.err{color:#e74c3c}.warn{color:#f39c12}
pre{background:#000;padding:15px;border-radius:8px;overflow-x:auto;font-size:12px}
table{width:100%;border-collapse:collapse}
th,td{padding:10px;text-align:left;border-bottom:1px solid rgba(255,255,255,0.1)}
th{color:#3498db;background:rgba(52,152,219,0.1)}
.btn{padding:15px 30px;border:none;border-radius:10px;font-size:16px;font-weight:bold;cursor:pointer;margin:10px 5px}
.btn-success{background:#2ecc71;color:#000}
.btn-primary{background:#3498db;color:#fff}
.btn-warning{background:#f39c12;color:#000}
.stat{display:inline-block;background:rgba(52,152,219,0.1);border:1px solid rgba(52,152,219,0.3);padding:15px 25px;border-radius:10px;margin:5px;text-align:center}
.stat-val{font-size:28px;font-weight:bold;color:#3498db}
.stat-lbl{font-size:11px;color:#888}
</style>";

echo "<h1>🔧 CORREÇÃO DEFINITIVA - Sistema de Produtos</h1>";

// ═══════════════════════════════════════════════════════════════════════════════
// DIAGNÓSTICO
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>📊 Diagnóstico Atual</h2>";

$stats = [
    'base' => $pdo->query("SELECT COUNT(*) FROM om_market_products_base")->fetchColumn(),
    'price_total' => $pdo->query("SELECT COUNT(*) FROM om_market_products_price")->fetchColumn(),
    'price_p1' => $pdo->query("SELECT COUNT(*) FROM om_market_products_price WHERE partner_id = 1")->fetchColumn(),
    'price_p2' => $pdo->query("SELECT COUNT(*) FROM om_market_products_price WHERE partner_id = 2")->fetchColumn(),
    'price_p100' => $pdo->query("SELECT COUNT(*) FROM om_market_products_price WHERE partner_id = 100")->fetchColumn(),
    'products_p100' => $pdo->query("SELECT COUNT(*) FROM om_market_products WHERE partner_id = 100")->fetchColumn(),
];

echo "<div class='box'>";
echo "<div class='stat'><div class='stat-val'>{$stats['base']}</div><div class='stat-lbl'>Produtos Base</div></div>";
echo "<div class='stat'><div class='stat-val'>{$stats['price_total']}</div><div class='stat-lbl'>Preços Total</div></div>";
echo "<div class='stat'><div class='stat-val'>{$stats['price_p1']}</div><div class='stat-lbl'>Partner 1</div></div>";
echo "<div class='stat'><div class='stat-val'>{$stats['price_p2']}</div><div class='stat-lbl'>Partner 2</div></div>";
echo "<div class='stat'><div class='stat-val " . ($stats['price_p100'] > 0 ? 'ok' : 'err') . "'>{$stats['price_p100']}</div><div class='stat-lbl'>Partner 100 ⚠️</div></div>";
echo "</div>";

if ($stats['price_p100'] == 0) {
    echo "<div class='box' style='border-color:#e74c3c'>";
    echo "<h3 class='err'>❌ PROBLEMA IDENTIFICADO!</h3>";
    echo "<p>A tabela <code>om_market_products_price</code> não tem produtos para partner_id = 100 (Valadares)</p>";
    echo "<p>O sistema /mercado/ faz JOIN entre <code>om_market_products_base</code> e <code>om_market_products_price</code></p>";
    echo "<p>Por isso não aparecem produtos!</p>";
    echo "</div>";
}

// ═══════════════════════════════════════════════════════════════════════════════
// AÇÕES
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>🔧 Ações de Correção</h2>";

echo "<div class='box'>";
echo "<form method='post'>";
echo "<button type='submit' name='popular_base' class='btn btn-success'>✅ POPULAR 100 PRODUTOS DO CATÁLOGO BASE</button>";
echo "<button type='submit' name='copiar_p1' class='btn btn-primary'>📋 COPIAR PREÇOS DO PARTNER 1</button>";
echo "<button type='submit' name='limpar_p100' class='btn btn-warning'>🗑️ LIMPAR PARTNER 100</button>";
echo "</form>";
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// POPULAR DO CATÁLOGO BASE
// ═══════════════════════════════════════════════════════════════════════════════

if (isset($_POST['popular_base'])) {
    echo "<h2>📥 Populando do Catálogo Base...</h2>";
    
    // Buscar produtos do catálogo base com categorias populares
    $produtos_base = $pdo->query("
        SELECT pb.product_id, pb.name, pb.brand, pb.image, c.name as category
        FROM om_market_products_base pb
        LEFT JOIN om_market_categories c ON pb.category_id = c.category_id
        WHERE pb.name IS NOT NULL AND pb.name != ''
        ORDER BY RANDOM()
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $inseridos = 0;
    $erros = 0;
    
    foreach ($produtos_base as $p) {
        // Verificar se já existe
        $stmt = $pdo->prepare("SELECT id FROM om_market_products_price WHERE product_id = ? AND partner_id = 100");
        $stmt->execute([$p['product_id']]);
        if ($stmt->fetch()) continue;
        
        // Gerar preço aleatório baseado na categoria
        $preco_base = rand(300, 5000) / 100; // R$ 3,00 a R$ 50,00
        $cat = strtolower($p['category'] ?? '');
        
        if (strpos($cat, 'carne') !== false || strpos($cat, 'açougue') !== false) {
            $preco_base = rand(1500, 8000) / 100;
        } elseif (strpos($cat, 'bebida') !== false) {
            $preco_base = rand(300, 2000) / 100;
        } elseif (strpos($cat, 'limpeza') !== false) {
            $preco_base = rand(500, 3000) / 100;
        }
        
        // Variação de preço (±10%)
        $variacao = rand(-10, 10) / 100;
        $preco = round($preco_base * (1 + $variacao), 2);
        
        // Promoção aleatória (20% de chance)
        $promo = 0;
        if (rand(1, 5) == 1) {
            $promo = round($preco * (1 - rand(10, 30) / 100), 2);
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO om_market_products_price 
                (product_id, partner_id, price, price_promo, stock, status, in_stock, is_available, date_added, date_modified)
                VALUES (?, 100, ?, ?, ?, 1, 1, 1, NOW(), NOW())
            ");
            $stmt->execute([$p['product_id'], $preco, $promo, rand(10, 200)]);
            $inseridos++;
        } catch (Exception $e) {
            $erros++;
        }
    }
    
    echo "<div class='box'>";
    echo "<p class='ok'>✅ Inseridos: <strong>$inseridos</strong> produtos</p>";
    if ($erros > 0) echo "<p class='err'>❌ Erros: $erros</p>";
    echo "</div>";
}

// ═══════════════════════════════════════════════════════════════════════════════
// COPIAR DO PARTNER 1
// ═══════════════════════════════════════════════════════════════════════════════

if (isset($_POST['copiar_p1'])) {
    echo "<h2>📋 Copiando preços do Partner 1...</h2>";
    
    // Copiar todos os preços do partner 1 para partner 100 com variação
    $copiados = $pdo->exec("
        INSERT INTO om_market_products_price 
        (product_id, partner_id, price, price_promo, stock, status, in_stock, is_available, date_added, date_modified)
        SELECT 
            product_id, 
            100, 
            ROUND(price * (1 + (RANDOM() * 0.2 - 0.1)), 2),
            CASE WHEN price_promo > 0 THEN ROUND(price_promo * (1 + (RANDOM() * 0.2 - 0.1)), 2) ELSE 0 END,
            FLOOR(RANDOM() * 200) + 10,
            1, 1, 1, NOW(), NOW()
        FROM om_market_products_price 
        WHERE partner_id = 1
        AND product_id NOT IN (SELECT product_id FROM om_market_products_price WHERE partner_id = 100)
    ");
    
    echo "<div class='box'>";
    echo "<p class='ok'>✅ Copiados: <strong>$copiados</strong> produtos com variação de preço (±10%)</p>";
    echo "</div>";
}

// ═══════════════════════════════════════════════════════════════════════════════
// LIMPAR
// ═══════════════════════════════════════════════════════════════════════════════

if (isset($_POST['limpar_p100'])) {
    $deletados = $pdo->exec("DELETE FROM om_market_products_price WHERE partner_id = 100");
    echo "<div class='box'><p class='warn'>🗑️ Removidos: $deletados registros do partner 100</p></div>";
}

// ═══════════════════════════════════════════════════════════════════════════════
// VERIFICAR RESULTADO
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>📋 Produtos do Partner 100 (após correção)</h2>";

$produtos_p100 = $pdo->query("
    SELECT pb.product_id, pb.name, pb.brand, pp.price, pp.price_promo, pp.stock
    FROM om_market_products_base pb
    JOIN om_market_products_price pp ON pb.product_id = pp.product_id
    WHERE pp.partner_id = 100 AND pp.status = 1
    ORDER BY pb.name
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

if (count($produtos_p100) > 0) {
    echo "<div class='box'>";
    echo "<p class='ok'>✅ <strong>" . count($produtos_p100) . "</strong> produtos encontrados!</p>";
    echo "<table>";
    echo "<tr><th>ID</th><th>Nome</th><th>Marca</th><th>Preço</th><th>Promo</th><th>Estoque</th></tr>";
    foreach ($produtos_p100 as $p) {
        $promo = $p['price_promo'] > 0 ? 'R$ ' . number_format($p['price_promo'], 2, ',', '.') : '-';
        echo "<tr>";
        echo "<td>{$p['product_id']}</td>";
        echo "<td>{$p['name']}</td>";
        echo "<td>{$p['brand']}</td>";
        echo "<td>R$ " . number_format($p['price'], 2, ',', '.') . "</td>";
        echo "<td>$promo</td>";
        echo "<td>{$p['stock']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";
} else {
    echo "<div class='box'><p class='err'>❌ Nenhum produto para partner 100. Clique nos botões acima para popular!</p></div>";
}

// ═══════════════════════════════════════════════════════════════════════════════
// SESSÃO
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>🔑 Verificar Sessão</h2>";
session_name('OCSESSID');
session_start();

echo "<div class='box'>";
echo "<p>market_partner_id: <strong class='" . (($_SESSION['market_partner_id'] ?? 0) == 100 ? 'ok' : 'err') . "'>" . ($_SESSION['market_partner_id'] ?? 'NÃO DEFINIDO') . "</strong></p>";
echo "<p>market_cep: <strong>" . ($_SESSION['market_cep'] ?? 'NÃO DEFINIDO') . "</strong></p>";

if (($_SESSION['market_partner_id'] ?? 0) != 100) {
    echo "<form method='post'>";
    echo "<button type='submit' name='forcar_sessao' class='btn btn-primary'>🔧 FORÇAR SESSÃO PARA PARTNER 100</button>";
    echo "</form>";
}
echo "</div>";

if (isset($_POST['forcar_sessao'])) {
    $_SESSION['market_partner_id'] = 100;
    $_SESSION['market_partner_name'] = 'Mercado Central GV';
    $_SESSION['market_cep'] = '35040090';
    echo "<script>location.reload();</script>";
}

// LINK FINAL
echo "<h2>🛒 Testar</h2>";
echo "<div class='box'>";
echo "<a href='/mercado/' class='btn btn-success' style='text-decoration:none;display:inline-block;font-size:20px'>🛒 IR PARA /mercado/ AGORA!</a>";
echo "</div>";
?>
