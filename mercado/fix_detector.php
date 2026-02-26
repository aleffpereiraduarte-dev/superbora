<?php
/**
 * 🔧 FIX - Detector de Intenção (regex vazio)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔧 Fix Detector de Intenção</h1>";
echo "<style>body{font-family:sans-serif;background:#0f172a;color:#e2e8f0;padding:40px;}pre{background:#1e293b;padding:16px;border-radius:8px;overflow-x:auto;}.btn{background:#10b981;color:white;padding:16px 32px;border:none;border-radius:12px;cursor:pointer;font-size:18px;}</style>";

$onePath = __DIR__ . '/one.php';
$conteudo = file_get_contents($onePath);

// Verificar problema
echo "<h2>1️⃣ Problema encontrado</h2>";

$count1 = substr_count($conteudo, "preg_match('//i',");
$count2 = substr_count($conteudo, 'preg_match("//i",');

echo "<p>Ocorrências de regex vazio <code>preg_match('//i', ...)</code>: <strong style='color:#ef4444;'>$count1</strong></p>";

if ($count1 > 0 || $count2 > 0) {
    echo "<p style='color:#ef4444;'>⚠️ Regex vazio sempre retorna TRUE - por isso tudo vira 'viagem'!</p>";
}

if (isset($_GET['fix'])) {
    // Backup
    copy($onePath, $onePath . '.bkp_fix_' . time());
    echo "<p>✅ Backup criado</p>";
    
    // O problema está nas linhas onde deveria ter $destinosTuristicos mas está vazio
    // Linha 10713: preg_match('//i', $msg)) {
    // Deveria ser: preg_match('/' . $destinosTuristicos . '/i', $msg)) {
    
    // Fix 1: Corrigir o regex vazio da viagem (linha ~10713)
    $antigo1 = "preg_match('/(quero|vou|preciso|bora|planejando).*(viajar|ir pra|ir para|conhecer|visitar)/i', \$msg) ||
            preg_match('/(passagem|passagens|voo|voos|voar|hotel|hospedagem|viagem|férias|ferias)/i', \$msg) ||
            preg_match('//i', \$msg))";
    
    $novo1 = "preg_match('/(quero|vou|preciso|bora|planejando).*(viajar|ir pra|ir para|conhecer|visitar)/i', \$msg) ||
            preg_match('/(passagem|passagens|voo|voos|voar|hotel|hospedagem|viagem|férias|ferias)/i', \$msg) ||
            preg_match('/' . \$destinosTuristicos . '/i', \$msg))";
    
    if (strpos($conteudo, $antigo1) !== false) {
        $conteudo = str_replace($antigo1, $novo1, $conteudo);
        echo "<p>✅ Fix 1 aplicado (condição viagem)</p>";
    }
    
    // Fix 2: Corrigir o regex vazio do destino (linha ~10719)
    $antigo2 = "if (preg_match('//i', \$msg, \$m))
                    \$r['entidades']['destino'] = ucwords(\$m[0]);";
    
    $novo2 = "if (preg_match('/' . \$destinosTuristicos . '/i', \$msg, \$m))
                    \$r['entidades']['destino'] = ucwords(\$m[1]);";
    
    if (strpos($conteudo, $antigo2) !== false) {
        $conteudo = str_replace($antigo2, $novo2, $conteudo);
        echo "<p>✅ Fix 2 aplicado (captura destino)</p>";
    }
    
    // Fix 3: Ecommerce
    $antigo3 = "if (preg_match('//i', \$msg)) {";
    $novo3 = "if (preg_match('/' . \$produtosEletronicos . '/i', \$msg)) {";
    
    if (strpos($conteudo, $antigo3) !== false) {
        $conteudo = str_replace($antigo3, $novo3, $conteudo);
        echo "<p>✅ Fix 3 aplicado (ecommerce)</p>";
    }
    
    // Fix 4: Ecommerce captura
    $antigo4 = "if (preg_match('//i', \$msg, \$m))
                \$r['entidades']['produto'] = \$m[0];";
    
    $novo4 = "if (preg_match('/' . \$produtosEletronicos . '/i', \$msg, \$m))
                \$r['entidades']['produto'] = \$m[1];";
    
    if (strpos($conteudo, $antigo4) !== false) {
        $conteudo = str_replace($antigo4, $novo4, $conteudo);
        echo "<p>✅ Fix 4 aplicado (ecommerce captura)</p>";
    }
    
    // Fix 5: Mercado
    $antigo5 = "if (preg_match('/(preciso|quero|falta|acabou|comprar).*(de\s+)?()/i', \$msg) ||";
    $novo5 = "if (preg_match('/(preciso|quero|falta|acabou|comprar).*(de\s+)?(' . \$produtosMercado . ')/i', \$msg) ||";
    
    if (strpos($conteudo, $antigo5) !== false) {
        $conteudo = str_replace($antigo5, $novo5, $conteudo);
        echo "<p>✅ Fix 5 aplicado (mercado)</p>";
    }
    
    // Fix 6: Mercado captura
    $antigo6 = "preg_match_all('//i', \$msg, \$matches);";
    $novo6 = "preg_match_all('/' . \$produtosMercado . '/i', \$msg, \$matches);";
    
    if (strpos($conteudo, $antigo6) !== false) {
        $conteudo = str_replace($antigo6, $novo6, $conteudo);
        echo "<p>✅ Fix 6 aplicado (mercado captura)</p>";
    }
    
    // Salvar
    file_put_contents($onePath, $conteudo);
    
    // Verificar sintaxe
    $check = shell_exec("php -l $onePath 2>&1");
    $ok = strpos($check, 'No syntax errors') !== false;
    
    if ($ok) {
        echo "<h2 style='color:#10b981;'>✅ FIX APLICADO COM SUCESSO!</h2>";
        echo "<p><a href='teste_api_one.php' style='color:#10b981;font-size:20px;'>🧪 Testar API</a></p>";
        echo "<p><a href='one.php' style='color:#3b82f6;font-size:20px;'>💚 Ir para ONE</a></p>";
    } else {
        echo "<h2 style='color:#ef4444;'>❌ Erro de sintaxe!</h2>";
        echo "<pre>$check</pre>";
    }
    
} else {
    echo "<h2>2️⃣ Solução</h2>";
    echo "<p>Os regex vazios <code>//</code> precisam ser substituídos pelas variáveis corretas:</p>";
    echo "<ul>";
    echo "<li><code>\$destinosTuristicos</code> para viagem</li>";
    echo "<li><code>\$produtosEletronicos</code> para ecommerce</li>";
    echo "<li><code>\$produtosMercado</code> para mercado</li>";
    echo "</ul>";
    
    echo "<p style='margin-top:30px;'><a href='?fix=1' class='btn'>🔧 APLICAR FIX</a></p>";
}
