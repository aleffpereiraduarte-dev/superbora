<?php
require_once __DIR__ . '/config/database.php';
/**
 * 🔧 Adicionar campos faltantes + Index.php Atualizado
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔧 Atualização do Catálogo</h1>";
echo "<style>body{font-family:system-ui;padding:20px;background:#1a1a2e;color:#e0e0e0}
.box{background:#16213e;border-radius:12px;padding:20px;margin:16px 0;max-width:800px}
.ok{color:#00ff88}.err{color:#ff4757}
.btn{display:inline-block;padding:12px 24px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;text-decoration:none;border-radius:8px;border:none;cursor:pointer;font-size:14px;margin:8px 4px}
pre{background:#0f0f1a;padding:12px;border-radius:8px;overflow-x:auto;font-size:12px}</style>";

try {
    $pdo = getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p class='ok'>✅ Conectado</p>";
} catch (Exception $e) {
    die("<p class='err'>❌ " . $e->getMessage() . "</p>");
}

// Verificar e adicionar campos faltantes
echo "<div class='box'><h2>1️⃣ Adicionando Campos Faltantes</h2>";

$camposParaAdicionar = [
    'sugar' => "ADD COLUMN sugar DECIMAL(10,2) DEFAULT NULL AFTER sodium",
    'ingredientes' => "ADD COLUMN ingredientes TEXT DEFAULT NULL AFTER nutrition_json",
    'descricao' => "ADD COLUMN descricao TEXT DEFAULT NULL AFTER ingredientes"
];

foreach ($camposParaAdicionar as $campo => $sql) {
    try {
        $pdo->query("SELECT $campo FROM om_market_products_base LIMIT 1");
        echo "<p>✅ Campo <strong>$campo</strong> já existe</p>";
    } catch (Exception $e) {
        try {
            $pdo->exec("ALTER TABLE om_market_products_base $sql");
            echo "<p class='ok'>✅ Campo <strong>$campo</strong> adicionado!</p>";
        } catch (Exception $e2) {
            echo "<p class='err'>❌ Erro ao adicionar $campo: " . $e2->getMessage() . "</p>";
        }
    }
}
echo "</div>";

// Verificar estrutura final
echo "<div class='box'><h2>2️⃣ Campos Nutricionais Disponíveis</h2>";
$nutriCols = ['calories', 'proteins', 'carbs', 'fats', 'fiber', 'sodium', 'sugar', 'nutri_score', 'nutri_score_letter', 'acucares', 'gorduras_saturadas', 'alergenos', 'ingredientes', 'descricao', 'nutrition_json'];

echo "<table style='width:100%;border-collapse:collapse'><tr><th style='border:1px solid #444;padding:8px'>Campo</th><th style='border:1px solid #444;padding:8px'>Status</th><th style='border:1px solid #444;padding:8px'>Produtos Preenchidos</th></tr>";

foreach ($nutriCols as $col) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM om_market_products_base WHERE $col IS NOT NULL AND $col != '' AND $col != 0")->fetchColumn();
        $status = $count > 0 ? "✅" : "⚠️";
        echo "<tr><td style='border:1px solid #444;padding:8px'>$col</td><td style='border:1px solid #444;padding:8px'>Existe</td><td style='border:1px solid #444;padding:8px'>$status $count</td></tr>";
    } catch (Exception $e) {
        echo "<tr><td style='border:1px solid #444;padding:8px'>$col</td><td style='border:1px solid #444;padding:8px;color:#ff4757'>Não existe</td><td style='border:1px solid #444;padding:8px'>-</td></tr>";
    }
}
echo "</table></div>";

// Amostra de produto com dados
echo "<div class='box'><h2>3️⃣ Amostra de Produto</h2>";
$sample = $pdo->query("SELECT product_id, name, brand, barcode, calories, proteins, carbs, fats, fiber, sodium, sugar, nutri_score, nutri_score_letter, ingredients, ingredientes, descricao, description, nutrition_json 
    FROM om_market_products_base 
    WHERE calories > 0 OR ingredients IS NOT NULL OR nutrition_json IS NOT NULL
    LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if ($sample) {
    echo "<pre>" . print_r($sample, true) . "</pre>";
} else {
    echo "<p class='err'>⚠️ Nenhum produto com dados nutricionais encontrado</p>";
    
    // Buscar qualquer produto
    $any = $pdo->query("SELECT product_id, name, brand FROM om_market_products_base LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    echo "<p>Produto exemplo: " . print_r($any, true) . "</p>";
}
echo "</div>";

echo "<div class='box'><h2>4️⃣ Próximos Passos</h2>";
echo "<ol>";
echo "<li>✅ Campos nutricionais criados</li>";
echo "<li>📥 <a href='robot_expansao_catalogo.php' style='color:#667eea'>Executar Robot de Expansão</a> para buscar produtos com nutrição</li>";
echo "<li>📄 <a href='produto/' style='color:#667eea'>Testar página de produto</a></li>";
echo "</ol></div>";
?>
