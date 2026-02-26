<?php
require_once __DIR__ . '/config/database.php';
/**
 * 🔧 FIX v5 DIRETO - Só atualiza o banco
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔧 Fix v5 - Dados do Cliente</h1>";
echo "<style>body{font-family:system-ui;background:#0a0a0a;color:#e5e5e5;padding:20px;max-width:800px;margin:0 auto}.ok{color:#22c55e}.erro{color:#ef4444}pre{background:#151515;padding:12px;border-radius:6px;}</style>";

try {
    $pdo = getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p class='ok'>✅ Banco conectado</p>";
} catch (Exception $e) {
    die("<p class='erro'>❌ Erro: {$e->getMessage()}</p>");
}

// 1. Pegar dados do cliente
$stmt = $pdo->query("SELECT customer_id, firstname, lastname, email FROM oc_customer WHERE customer_id = 1000006");
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

if ($cliente) {
    echo "<p class='ok'>✅ Cliente: {$cliente['firstname']} {$cliente['lastname']}</p>";
} else {
    die("<p class='erro'>❌ Cliente 1000006 não encontrado</p>");
}

// 2. Verificar/criar tabela de perfil
$pdo->exec("
    CREATE TABLE IF NOT EXISTS om_one_cliente_perfil (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL UNIQUE,
        nome VARCHAR(100),
        apelido VARCHAR(100),
        primeira_conversa DATETIME,
        ultima_conversa DATETIME
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "<p class='ok'>✅ Tabela om_one_cliente_perfil OK</p>";

// 3. Inserir/atualizar perfil
$stmt = $pdo->prepare("SELECT id FROM om_one_cliente_perfil WHERE customer_id = ?");
$stmt->execute([1000006]);

if ($stmt->fetch()) {
    $pdo->prepare("UPDATE om_one_cliente_perfil SET nome = ?, ultima_conversa = NOW() WHERE customer_id = ?")
        ->execute([$cliente['firstname'], 1000006]);
    echo "<p class='ok'>✅ Perfil atualizado: nome = {$cliente['firstname']}</p>";
} else {
    $pdo->prepare("INSERT INTO om_one_cliente_perfil (customer_id, nome, primeira_conversa, ultima_conversa) VALUES (?, ?, NOW(), NOW())")
        ->execute([1000006, $cliente['firstname']]);
    echo "<p class='ok'>✅ Perfil criado: nome = {$cliente['firstname']}</p>";
}

// 4. Verificar perfil
$stmt = $pdo->query("SELECT * FROM om_one_cliente_perfil WHERE customer_id = 1000006");
$perfil = $stmt->fetch(PDO::FETCH_ASSOC);
echo "<pre>" . print_r($perfil, true) . "</pre>";

// 5. Testar se a ONE consegue pegar o nome
echo "<h2>🧪 Teste da ONE</h2>";

session_start();
$_SESSION['customer_id'] = 1000006;

// Simular chamada
$url = "https://onemundo.com.br/mercado/one.php?action=send&message=" . urlencode("você sabe meu nome?");
echo "<p>URL de teste: <a href='$url' target='_blank'>$url</a></p>";

echo "<h2>📋 O que fazer agora</h2>";
echo "<ol>
<li>Abre o chat da ONE</li>
<li>Pergunta: <b>\"você sabe meu nome?\"</b></li>
<li>Se não souber, me manda o código atual do one.php que eu verifico</li>
</ol>";

echo "<h2>🔍 Debug - Verificar one.php</h2>";
$onePath = __DIR__ . '/one.php';
if (file_exists($onePath)) {
    $conteudo = file_get_contents($onePath);
    
    // Verificar se tem loadPerfil
    if (strpos($conteudo, 'loadPerfil') !== false) {
        echo "<p class='ok'>✅ Tem função loadPerfil()</p>";
    } else {
        echo "<p class='erro'>❌ Não tem loadPerfil()</p>";
    }
    
    // Verificar se tem getNome
    if (strpos($conteudo, 'getNome') !== false) {
        echo "<p class='ok'>✅ Tem função getNome()</p>";
    } else {
        echo "<p class='erro'>❌ Não tem getNome()</p>";
    }
    
    // Verificar se carrega perfil
    if (strpos($conteudo, 'om_one_cliente_perfil') !== false) {
        echo "<p class='ok'>✅ Referencia tabela om_one_cliente_perfil</p>";
    } else {
        echo "<p class='erro'>❌ Não referencia tabela om_one_cliente_perfil</p>";
    }
    
    // Verificar customer_id
    if (strpos($conteudo, 'customer_id') !== false) {
        echo "<p class='ok'>✅ Usa customer_id</p>";
    }
    
    // Procurar onde pega o nome
    if (preg_match('/function\s+getNome[^}]+}/s', $conteudo, $m)) {
        echo "<h3>Função getNome() atual:</h3>";
        echo "<pre>" . htmlspecialchars(substr($m[0], 0, 500)) . "</pre>";
    }
    
} else {
    echo "<p class='erro'>❌ one.php não encontrado</p>";
}
