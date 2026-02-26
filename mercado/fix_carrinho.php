<?php
/**
 * 🔧 FIX - Carrinho Aninhado
 * 
 * Problema: data.carrinho retorna {success:true, carrinho:[]} em vez de []
 * Isso causa erro no updateCartUI
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔧 Fix Carrinho Aninhado</h1>";
echo "<style>body{font-family:sans-serif;background:#0f172a;color:#e2e8f0;padding:40px;}pre{background:#1e293b;padding:16px;border-radius:8px;}.btn{background:#10b981;color:white;padding:16px 32px;border:none;border-radius:12px;cursor:pointer;font-size:18px;}.success{color:#10b981;}.error{color:#ef4444;}</style>";

$onePath = __DIR__ . '/one.php';
$conteudo = file_get_contents($onePath);

echo "<h2>Problema:</h2>";
echo "<p>O carrinho está retornando aninhado:</p>";
echo "<pre>\"carrinho\": {\"success\":true, \"carrinho\":[], ...}</pre>";
echo "<p>Deveria ser:</p>";
echo "<pre>\"carrinho\": []</pre>";

// Encontrar onde está o problema
echo "<h2>Buscando problema...</h2>";

// O problema está no getCarrinho() que retorna um array com success
if (preg_match('/function getCarrinho\(\).*?return.*?;/s', $conteudo, $match)) {
    echo "<p>Função getCarrinho() encontrada</p>";
}

if (isset($_GET['fix'])) {
    
    // Backup
    copy($onePath, $onePath . '.bkp_carrinho_' . time());
    echo "<p class='success'>✅ Backup criado</p>";
    
    // Fix 1: Corrigir o JavaScript para lidar com carrinho aninhado
    $jsAntigo = "updateCartUI(data.carrinho, data.total);";
    $jsNovo = "updateCartUI(data.carrinho?.carrinho || data.carrinho || [], data.carrinho?.total || data.total || 0);";
    
    $conteudo = str_replace($jsAntigo, $jsNovo, $conteudo);
    echo "<p class='success'>✅ JavaScript corrigido para lidar com carrinho aninhado</p>";
    
    // Fix 2: Corrigir a função getCarrinho para retornar array simples
    // Procurar a função getCarrinho
    $antigoGetCarrinho = "private function getCarrinho() {
        return [
            'success' => true,
            'carrinho' => \$_SESSION['one_conversa']['carrinho'] ?? [],
            'total' => \$this->getTotal(),
            'itens' => count(\$_SESSION['one_conversa']['carrinho'] ?? [])
        ];
    }";
    
    $novoGetCarrinho = "private function getCarrinho() {
        return \$_SESSION['one_conversa']['carrinho'] ?? [];
    }";
    
    if (strpos($conteudo, $antigoGetCarrinho) !== false) {
        $conteudo = str_replace($antigoGetCarrinho, $novoGetCarrinho, $conteudo);
        echo "<p class='success'>✅ Função getCarrinho() corrigida</p>";
    } else {
        echo "<p class='warning'>⚠️ Função getCarrinho() não encontrada no formato esperado - fix JS aplicado</p>";
    }
    
    // Salvar
    file_put_contents($onePath, $conteudo);
    
    // Verificar sintaxe
    $check = shell_exec("php -l $onePath 2>&1");
    $ok = strpos($check, 'No syntax errors') !== false;
    
    if ($ok) {
        echo "<h2 class='success'>✅ FIX APLICADO!</h2>";
        echo "<p><a href='one.php' style='color:#10b981;font-size:20px;'>💚 Testar ONE</a></p>";
    } else {
        echo "<h2 class='error'>❌ Erro de sintaxe!</h2>";
        echo "<pre>$check</pre>";
    }
    
} else {
    echo "<p style='margin-top:30px;'><a href='?fix=1' class='btn'>🔧 APLICAR FIX</a></p>";
}
