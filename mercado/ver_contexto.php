<?php
$arquivo = __DIR__ . '/index.php';
$linhas = file($arquivo);

echo "<h1>Contexto linhas 16260-16290</h1>";
echo "<pre style='background:#1e1e1e;color:#fff;padding:20px;font-size:11px;'>";

for ($i = 16255; $i < min(count($linhas), 16295); $i++) {
    $num = $i + 1;
    $linha = rtrim($linhas[$i]);
    
    // Destacar linhas suspeitas
    $cor = "";
    if (trim($linha) === '}' || trim($linha) === '};') {
        $cor = "color:#ff6b6b";
    } elseif (strpos($linha, 'function ') !== false) {
        $cor = "color:#4ecdc4";
    } elseif (strpos($linha, '</script>') !== false || strpos($linha, '<script') !== false) {
        $cor = "color:#ffd93d";
    }
    
    echo "<span style='color:#666'>" . str_pad($num, 5) . "</span> <span style='$cor'>" . htmlspecialchars($linha) . "</span>\n";
}

echo "</pre>";

echo "<h2>Contexto linhas 16140-16175</h2>";
echo "<pre style='background:#1e1e1e;color:#fff;padding:20px;font-size:11px;'>";

for ($i = 16135; $i < min(count($linhas), 16180); $i++) {
    $num = $i + 1;
    $linha = rtrim($linhas[$i]);
    
    $cor = "";
    if (trim($linha) === '}' || trim($linha) === '};') {
        $cor = "color:#ff6b6b";
    } elseif (strpos($linha, 'function ') !== false) {
        $cor = "color:#4ecdc4";
    } elseif (strpos($linha, '</script>') !== false || strpos($linha, '<script') !== false) {
        $cor = "color:#ffd93d";
    }
    
    echo "<span style='color:#666'>" . str_pad($num, 5) . "</span> <span style='$cor'>" . htmlspecialchars($linha) . "</span>\n";
}

echo "</pre>";
