<?php
require_once __DIR__ . '/config/database.php';
/**
 * 🔧 CORREÇÃO COMPLETA - Popular tabela e corrigir estrutura
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "<style>
body{font-family:monospace;background:#111;color:#0f0;padding:20px}
h2{color:#0ff;margin-top:25px}
.ok{color:#0f0}.err{color:#f00}.warn{color:#ff0}
pre{background:#000;padding:15px;border-radius:8px}
.btn{padding:20px 40px;background:#0f0;color:#000;font-size:18px;cursor:pointer;border:none;border-radius:10px;margin:10px}
</style>";

echo "<h1>🔧 CORREÇÃO COMPLETA</h1>";

// ═══════════════════════════════════════════════════════════════════════════════
// PROBLEMA IDENTIFICADO:
// - om_market_partner_products tem: price_promo, stock, active
// - API espera: special_price, quantity, status
// - om_market_partners usa partner_id, não id
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>📊 Situação Atual</h2>";
echo "<p>om_market_partner_products: <strong>" . $pdo->query("SELECT COUNT(*) FROM om_market_partner_products")->fetchColumn() . "</strong> registros</p>";
echo "<p>om_market_products: <strong>" . $pdo->query("SELECT COUNT(*) FROM om_market_products WHERE status = '1'")->fetchColumn() . "</strong> ativos</p>";

echo "<h2>🔧 Ações</h2>";
echo "<form method='post'>";
echo "<button type='submit' name='popular_tabela' class='btn'>📦 POPULAR om_market_partner_products (Partner 100)</button><br>";
echo "<button type='submit' name='testar_query' class='btn' style='background:#3498db;color:#fff'>🧪 TESTAR QUERY CORRIGIDA</button>";
echo "</form>";

// ═══════════════════════════════════════════════════════════════════════════════
// POPULAR TABELA
// ═══════════════════════════════════════════════════════════════════════════════

if (isset($_POST['popular_tabela'])) {
    echo "<h2>📦 Populando om_market_partner_products...</h2>";
    
    // Buscar produtos ativos de om_market_products
    $produtos = $pdo->query("
        SELECT id, name, price, special_price, stock, quantity, image 
        FROM om_market_products 
        WHERE status = 1
        LIMIT 300
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Encontrados " . count($produtos) . " produtos ativos</p>";
    
    $inseridos = 0;
    $erros = 0;
    
    foreach ($produtos as $p) {
        try {
            // Usar preço existente ou gerar
            $preco = ($p['price'] > 0) ? $p['price'] : rand(500, 5000) / 100;
            
            // Promoção (20% chance)
            $promo = null;
            if ($p['special_price'] > 0) {
                $promo = $p['special_price'];
            } elseif (rand(1, 5) == 1) {
                $promo = round($preco * 0.85, 2);
            }
            
            // Estoque
            $estoque = ($p['stock'] > 0) ? $p['stock'] : (($p['quantity'] > 0) ? $p['quantity'] : rand(20, 100));
            
            // Inserir com as colunas CERTAS da tabela
            $stmt = $pdo->prepare("
                INSERT INTO om_market_partner_products 
                (partner_id, product_id, price, price_promo, stock, active)
                VALUES (100, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE 
                    price = VALUES(price),
                    price_promo = VALUES(price_promo),
                    stock = VALUES(stock),
                    active = 1
            ");
            $stmt->execute([$p['id'], $preco, $promo, $estoque]);
            $inseridos++;
        } catch (Exception $e) {
            $erros++;
            if ($erros <= 3) {
                echo "<p class='err'>Erro: " . $e->getMessage() . "</p>";
            }
        }
    }
    
    echo "<p class='ok'>✅ Inseridos/Atualizados: <strong>$inseridos</strong> produtos</p>";
    if ($erros > 0) echo "<p class='warn'>⚠️ Erros: $erros</p>";
    
    // Verificar
    $total = $pdo->query("SELECT COUNT(*) FROM om_market_partner_products WHERE partner_id = 100")->fetchColumn();
    echo "<p class='ok'>✅ Total em om_market_partner_products (partner 100): <strong>$total</strong></p>";
}

// ═══════════════════════════════════════════════════════════════════════════════
// TESTAR QUERY CORRIGIDA
// ═══════════════════════════════════════════════════════════════════════════════

if (isset($_POST['testar_query'])) {
    echo "<h2>🧪 Testando Query Corrigida</h2>";
    
    // Query com nomes CORRETOS das colunas
    $sql = "
        SELECT 
            pp.id,
            pp.partner_id,
            mp.id as product_id,
            mp.name,
            mp.brand,
            pp.price,
            pp.price_promo as special_price,
            pp.stock as quantity,
            COALESCE(mp.image, mp.image_url) as image
        FROM om_market_partner_products pp
        INNER JOIN om_market_products mp ON pp.product_id = mp.id
        INNER JOIN om_market_partners pa ON pp.partner_id = pa.partner_id
        WHERE pp.partner_id = 100
            AND pp.active = 1
            AND pp.stock > 0
            AND mp.status = 1
            AND mp.name LIKE '%feij%'
        LIMIT 10
    ";
    
    echo "<p>Query corrigida:</p>";
    echo "<pre>$sql</pre>";
    
    try {
        $produtos = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        echo "<p class='ok'>✅ Sucesso! Encontrados: " . count($produtos) . " produtos</p>";
        
        if ($produtos) {
            echo "<table style='width:100%;border-collapse:collapse'>";
            echo "<tr style='background:#222'><th style='padding:10px;border:1px solid #333'>ID</th><th style='padding:10px;border:1px solid #333'>Nome</th><th style='padding:10px;border:1px solid #333'>Preço</th><th style='padding:10px;border:1px solid #333'>Promo</th></tr>";
            foreach ($produtos as $p) {
                $promo = $p['special_price'] ? "R$ " . number_format($p['special_price'], 2, ',', '.') : '-';
                echo "<tr>";
                echo "<td style='padding:10px;border:1px solid #333'>{$p['id']}</td>";
                echo "<td style='padding:10px;border:1px solid #333'>{$p['name']}</td>";
                echo "<td style='padding:10px;border:1px solid #333'>R$ " . number_format($p['price'], 2, ',', '.') . "</td>";
                echo "<td style='padding:10px;border:1px solid #333'>$promo</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    } catch (Exception $e) {
        echo "<p class='err'>❌ Erro: " . $e->getMessage() . "</p>";
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// VERIFICAR ESTADO ATUAL
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>📋 Estado Atual</h2>";

$count_pp = $pdo->query("SELECT COUNT(*) FROM om_market_partner_products WHERE partner_id = 100 AND active = 1")->fetchColumn();
echo "<p>Produtos ativos em partner 100: <strong class='" . ($count_pp > 0 ? 'ok' : 'err') . "'>$count_pp</strong></p>";

if ($count_pp > 0) {
    // Amostra
    $amostra = $pdo->query("
        SELECT pp.id, mp.name, pp.price, pp.price_promo, pp.stock
        FROM om_market_partner_products pp
        JOIN om_market_products mp ON pp.product_id = mp.id
        WHERE pp.partner_id = 100 AND pp.active = 1
        ORDER BY mp.name
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Amostra:</p><pre>" . print_r($amostra, true) . "</pre>";
}

// ═══════════════════════════════════════════════════════════════════════════════
// PRÓXIMO PASSO: CORRIGIR A API
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>⚠️ PRÓXIMO PASSO</h2>";
echo "<p>A API <code>busca-inteligente.php</code> precisa ser corrigida para usar:</p>";
echo "<ul>";
echo "<li><code>pp.price_promo</code> em vez de <code>pp.special_price</code></li>";
echo "<li><code>pp.stock</code> em vez de <code>pp.quantity</code></li>";
echo "<li><code>pp.active</code> em vez de <code>pp.status</code></li>";
echo "<li><code>pa.partner_id</code> em vez de <code>pa.id</code></li>";
echo "</ul>";

echo "<form method='post'>";
echo "<button type='submit' name='criar_api_corrigida' class='btn' style='background:#e74c3c'>🔧 CRIAR API CORRIGIDA</button>";
echo "</form>";

if (isset($_POST['criar_api_corrigida'])) {
    // Criar versão corrigida da API
    $api_code = '<?php
/**
 * 🔍 ONEMUNDO MERCADO - BUSCA INTELIGENTE (CORRIGIDA)
 */

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");

// Conexão
try {
    $pdo = getPDO();
} catch (PDOException $e) {
    echo json_encode(["error" => "Database error"]);
    exit;
}

// Parâmetros
$query = trim($_GET["q"] ?? "");
$partner_id = intval($_GET["partner_id"] ?? 100);
$limit = min(intval($_GET["limit"] ?? 40), 40);

if (empty($query)) {
    echo json_encode(["error" => "Query required", "products" => []]);
    exit;
}

// Buscar produtos
$terms = preg_split("/\s+/", $query);
$where_parts = [];
$params = [];

foreach ($terms as $i => $term) {
    if (strlen($term) < 2) continue;
    $where_parts[] = "(mp.name LIKE :term{$i} OR mp.brand LIKE :brand{$i})";
    $params[":term{$i}"] = "%{$term}%";
    $params[":brand{$i}"] = "%{$term}%";
}

if (empty($where_parts)) {
    echo json_encode(["error" => "Invalid query", "products" => []]);
    exit;
}

$where_sql = "(" . implode(" OR ", $where_parts) . ")";

// Query CORRIGIDA com nomes certos das colunas
$sql = "
    SELECT 
        pp.id,
        pp.partner_id,
        mp.id as product_id,
        mp.name,
        mp.brand,
        mp.description,
        pp.price,
        pp.price_promo as special_price,
        pp.stock as quantity,
        COALESCE(mp.image, mp.image_url) as image,
        mp.category,
        mp.unit
    FROM om_market_partner_products pp
    INNER JOIN om_market_products mp ON pp.product_id = mp.id
    INNER JOIN om_market_partners pa ON pp.partner_id = pa.partner_id
    WHERE pp.partner_id = :partner_id
        AND pp.active = 1
        AND pp.stock > 0
        AND mp.status = 1
        AND {$where_sql}
    ORDER BY 
        CASE WHEN mp.name LIKE :exact THEN 0 ELSE 1 END,
        pp.price_promo IS NOT NULL DESC,
        mp.name ASC
    LIMIT {$limit}
";

$params[":partner_id"] = $partner_id;
$params[":exact"] = "%{$terms[0]}%";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatar
    foreach ($products as &$p) {
        $p["price"] = floatval($p["price"]);
        $p["special_price"] = $p["special_price"] ? floatval($p["special_price"]) : null;
        
        if ($p["image"]) {
            $p["image_url"] = (preg_match("/^https?:\/\//", $p["image"])) ? $p["image"] : "/image/" . $p["image"];
        } else {
            $p["image_url"] = "/mercado/assets/img/no-image.png";
        }
    }
    
    echo json_encode([
        "query" => $query,
        "partner_id" => $partner_id,
        "products" => $products,
        "alternatives" => [],
        "total_found" => count($products)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(["error" => $e->getMessage(), "products" => []]);
}
';

    file_put_contents('/mnt/user-data/outputs/busca-corrigida.php', $api_code);
    echo "<p class='ok'>✅ API corrigida criada! Faça upload para: <code>/mercado/api/busca-inteligente.php</code></p>";
}

echo "<br><br>";
echo "<a href='/mercado/' style='padding:15px 30px;background:#2ecc71;color:#000;text-decoration:none;border-radius:8px;font-size:16px'>🛒 Testar /mercado/</a>";
?>
