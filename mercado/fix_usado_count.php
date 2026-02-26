<?php
/**
 * 🔧 FIX RÁPIDO - usado_count → uso_count
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/html; charset=utf-8');

echo "<h1>🔧 Fix usado_count → uso_count</h1>";
echo "<style>body{font-family:sans-serif;background:#1e293b;color:#e2e8f0;padding:40px;} .ok{color:#10b981;} .erro{color:#ef4444;} pre{background:#0f172a;padding:15px;border-radius:8px;}</style>";

$onePath = __DIR__ . '/one.php';

if (!file_exists($onePath)) {
    die("<p class='erro'>❌ one.php não encontrado!</p>");
}

$conteudo = file_get_contents($onePath);
$original = $conteudo;

// Conta quantas ocorrências
$qtdAntes = substr_count($conteudo, 'usado_count');
echo "<p>📊 Ocorrências de 'usado_count': <strong>$qtdAntes</strong></p>";

if ($qtdAntes == 0) {
    echo "<p class='ok'>✅ Nenhuma ocorrência encontrada - já está corrigido!</p>";
    exit;
}

if (isset($_POST['aplicar'])) {
    // Backup
    $backup = $onePath . '.backup_usadocount_' . date('Y-m-d_H-i-s');
    file_put_contents($backup, $original);
    echo "<p class='ok'>✅ Backup criado: $backup</p>";
    
    // Substituir
    $conteudo = str_replace('usado_count', 'uso_count', $conteudo);
    
    // Salvar
    file_put_contents($onePath, $conteudo);
    
    $qtdDepois = substr_count($conteudo, 'usado_count');
    
    echo "<div style='background:#064e3b;padding:20px;border-radius:12px;margin:20px 0;'>";
    echo "<h2 class='ok'>🎉 CORRIGIDO!</h2>";
    echo "<p>Substituições: <strong>$qtdAntes</strong> ocorrências</p>";
    echo "<p>Ocorrências restantes: <strong>$qtdDepois</strong></p>";
    echo "</div>";
    
    echo "<p><a href='one.php?action=send&message=oi' style='color:#10b981;font-size:1.2rem;'>🧪 TESTAR AGORA</a></p>";
    
} else {
    echo "<p>Este fix vai substituir todas as ocorrências de <code>usado_count</code> por <code>uso_count</code> no one.php</p>";
    
    echo "<h3>📋 Ocorrências encontradas:</h3>";
    echo "<pre>";
    
    // Mostra contexto de cada ocorrência
    $linhas = explode("\n", $conteudo);
    foreach ($linhas as $num => $linha) {
        if (strpos($linha, 'usado_count') !== false) {
            $numLinha = $num + 1;
            echo "Linha $numLinha: " . htmlspecialchars(trim($linha)) . "\n";
        }
    }
    echo "</pre>";
    
    echo "<form method='post' style='margin-top:20px;'>";
    echo "<button type='submit' name='aplicar' style='background:#10b981;color:white;border:none;padding:15px 30px;border-radius:8px;cursor:pointer;font-size:18px;'>🔧 APLICAR FIX</button>";
    echo "</form>";
}
