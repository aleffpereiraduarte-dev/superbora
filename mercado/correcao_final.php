<?php
require_once __DIR__ . '/config/database.php';
/**
 * 🔧 CORREÇÃO FINAL - TODAS AS TABELAS E SESSÃO
 */

session_name('OCSESSID');
session_start();

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "<style>
body{font-family:'Segoe UI',sans-serif;background:#0a0a15;color:#fff;padding:20px}
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
.stat-val{font-size:24px;font-weight:bold;color:#3498db}
.stat-lbl{font-size:11px;color:#888}
code{background:#000;padding:3px 8px;border-radius:4px;color:#ff0}
</style>";

echo "<h1>🔧 CORREÇÃO FINAL - Sistema Completo</h1>";

// ═══════════════════════════════════════════════════════════════════════════════
// DIAGNÓSTICO
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>📊 Diagnóstico das Tabelas</h2>";

$tabelas = [
    'om_market_products_base' => 'Usado pelo index.php',
    'om_market_products_price' => 'Usado pelo index.php (JOIN)',
    'om_market_products' => 'Usado pela API de busca',
    'om_market_partner_products' => 'Usado pela API de busca (JOIN)',
    'om_market_partners' => 'Cadastro de mercados',
];

echo "<div class='box'>";
foreach ($tabelas as $tabela => $descricao) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM $tabela")->fetchColumn();
        
        // Verificar partner 100
        $count_p100 = 0;
        if (strpos($tabela, 'partner') !== false || strpos($tabela, 'price') !== false) {
            $count_p100 = $pdo->query("SELECT COUNT(*) FROM $tabela WHERE partner_id = 100")->fetchColumn();
        }
        
        $class = $count > 0 ? 'ok' : 'err';
        $p100_class = $count_p100 > 0 ? 'ok' : 'err';
        
        echo "<div class='stat'><div class='stat-val'>$count</div><div class='stat-lbl'>$tabela</div></div>";
        
        if ($count_p100 !== 0 || strpos($tabela, 'partner') !== false || strpos($tabela, 'price') !== false) {
            echo "<span class='$p100_class'>(P100: $count_p100)</span> ";
        }
    } catch (Exception $e) {
        echo "<div class='stat'><div class='stat-val err'>ERRO</div><div class='stat-lbl'>$tabela</div></div>";
    }
}
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// VERIFICAR SESSÕES
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>🔑 Verificar Sessões</h2>";

echo "<div class='box'>";
echo "<p><strong>PHP \$_SESSION:</strong></p>";
echo "<ul>";
echo "<li>market_partner_id (com 'a'): <code>" . ($_SESSION['market_partner_id'] ?? 'NÃO DEFINIDO') . "</code></li>";
echo "<li>mercado_partner_id (com 'o'): <code>" . ($_SESSION['mercado_partner_id'] ?? 'NÃO DEFINIDO') . "</code> ⚠️ <span class='warn'>API usa esse!</span></li>";
echo "</ul>";

$ocsessid = $_COOKIE['OCSESSID'] ?? '';
if ($ocsessid) {
    $stmt = $pdo->prepare("SELECT data FROM oc_session WHERE session_id = ?");
    $stmt->execute([$ocsessid]);
    $row = $stmt->fetch();
    if ($row) {
        $data = json_decode($row['data'], true);
        echo "<p><strong>oc_session (banco):</strong></p>";
        echo "<ul>";
        echo "<li>market_partner_id: <code>" . ($data['market_partner_id'] ?? 'NÃO DEFINIDO') . "</code></li>";
        echo "<li>mercado_partner_id: <code>" . ($data['mercado_partner_id'] ?? 'NÃO DEFINIDO') . "</code></li>";
        echo "</ul>";
    }
}
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// AÇÕES
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>🔧 Correções</h2>";

echo "<div class='box'>";
echo "<form method='post'>";
echo "<button type='submit' name='corrigir_tudo' class='btn btn-success' style='font-size:20px;padding:20px 40px'>✅ CORRIGIR TUDO DE UMA VEZ</button>";
echo "<br><br>";
echo "<button type='submit' name='popular_partner_products' class='btn btn-primary'>📦 Popular om_market_partner_products</button>";
echo "<button type='submit' name='corrigir_sessao' class='btn btn-warning'>🔑 Corrigir Sessão (ambas variáveis)</button>";
echo "</form>";
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// EXECUTAR CORREÇÕES
// ═══════════════════════════════════════════════════════════════════════════════

if (isset($_POST['corrigir_tudo']) || isset($_POST['popular_partner_products'])) {
    echo "<h2>📦 Populando om_market_partner_products...</h2>";
    echo "<div class='box'>";
    
    // Verificar estrutura da tabela
    try {
        $colunas = $pdo->query("SHOW COLUMNS FROM om_market_partner_products")->fetchAll(PDO::FETCH_COLUMN);
        echo "<p>Colunas: " . implode(', ', $colunas) . "</p>";
    } catch (Exception $e) {
        // Criar tabela se não existir
        $pdo->exec("CREATE TABLE IF NOT EXISTS om_market_partner_products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            partner_id INT NOT NULL,
            product_id INT NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            special_price DECIMAL(10,2) DEFAULT NULL,
            quantity INT DEFAULT 100,
            status TINYINT DEFAULT 1,
            image VARCHAR(500) DEFAULT NULL,
            date_added DATETIME DEFAULT CURRENT_TIMESTAMP,
            date_modified DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_partner_product (partner_id, product_id)
        )");
        echo "<p class='ok'>✅ Tabela criada!</p>";
    }
    
    // Popular com produtos do om_market_products (que a API usa)
    $produtos = $pdo->query("
        SELECT id, name, price, quantity, image 
        FROM om_market_products 
        WHERE status = '1' AND quantity > 0
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $inseridos = 0;
    foreach ($produtos as $p) {
        try {
            $preco = $p['price'] ?: rand(500, 5000) / 100;
            $promo = rand(1, 5) == 1 ? round($preco * 0.85, 2) : null;
            
            $stmt = $pdo->prepare("
                INSERT INTO om_market_partner_products 
                (partner_id, product_id, price, special_price, quantity, status, image)
                VALUES (100, ?, ?, ?, ?, 1, ?)
                ON DUPLICATE KEY UPDATE price = VALUES(price), special_price = VALUES(special_price)
            ");
            $stmt->execute([$p['id'], $preco, $promo, $p['quantity'] ?: 50, $p['image']]);
            $inseridos++;
        } catch (Exception $e) {
            // Ignorar duplicados
        }
    }
    
    echo "<p class='ok'>✅ Inseridos/Atualizados: <strong>$inseridos</strong> produtos na om_market_partner_products</p>";
    echo "</div>";
}

if (isset($_POST['corrigir_tudo']) || isset($_POST['corrigir_sessao'])) {
    echo "<h2>🔑 Corrigindo Sessão...</h2>";
    echo "<div class='box'>";
    
    // Corrigir $_SESSION PHP - AMBAS variáveis
    $_SESSION['market_partner_id'] = 100;
    $_SESSION['mercado_partner_id'] = 100; // API usa essa!
    $_SESSION['market_partner_name'] = 'Mercado Central GV';
    $_SESSION['market_cep'] = '35040090';
    
    echo "<p class='ok'>✅ \$_SESSION['market_partner_id'] = 100</p>";
    echo "<p class='ok'>✅ \$_SESSION['mercado_partner_id'] = 100 (API usa essa!)</p>";
    
    // Corrigir oc_session no banco
    if ($ocsessid) {
        $stmt = $pdo->prepare("SELECT data FROM oc_session WHERE session_id = ?");
        $stmt->execute([$ocsessid]);
        $row = $stmt->fetch();
        
        if ($row) {
            $data = json_decode($row['data'], true) ?: [];
            $data['market_partner_id'] = 100;
            $data['mercado_partner_id'] = 100; // API usa essa!
            $data['market_partner_name'] = 'Mercado Central GV';
            $data['market_cep'] = '35040090';
            
            $stmt = $pdo->prepare("UPDATE oc_session SET data = ? WHERE session_id = ?");
            $stmt->execute([json_encode($data), $ocsessid]);
            
            echo "<p class='ok'>✅ oc_session atualizada com AMBAS variáveis</p>";
        }
    }
    
    echo "</div>";
}

// ═══════════════════════════════════════════════════════════════════════════════
// TESTAR API
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>🧪 Testar API de Busca</h2>";
echo "<div class='box'>";

$test_url = "/mercado/api/busca-inteligente.php?q=feijao&partner_id=100";
echo "<p>URL: <code>$test_url</code></p>";
echo "<p><a href='$test_url' target='_blank' style='color:#3498db'>Abrir API diretamente</a></p>";

// Testar query na tabela certa
$teste = $pdo->query("
    SELECT pp.id, mp.name, pp.price, pp.partner_id
    FROM om_market_partner_products pp
    JOIN om_market_products mp ON pp.product_id = mp.id
    WHERE pp.partner_id = 100 AND pp.status = '1' AND mp.name LIKE '%feij%'
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Teste da query da API (om_market_partner_products):</p>";
if ($teste) {
    echo "<table><tr><th>ID</th><th>Nome</th><th>Preço</th><th>Partner</th></tr>";
    foreach ($teste as $t) {
        echo "<tr><td>{$t['id']}</td><td>{$t['name']}</td><td>R$ " . number_format($t['price'], 2, ',', '.') . "</td><td>{$t['partner_id']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p class='err'>❌ Nenhum produto encontrado! Clique em CORRIGIR TUDO</p>";
}

echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// LINK FINAL
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>🛒 Testar no /mercado/</h2>";
echo "<div class='box'>";
echo "<a href='/mercado/' class='btn btn-success' style='text-decoration:none;display:inline-block;font-size:20px'>🛒 IR PARA /mercado/</a>";
echo "<a href='/mercado/?q=feijao' class='btn btn-primary' style='text-decoration:none;display:inline-block;font-size:16px'>🔍 Buscar Feijão</a>";
echo "</div>";
?>
