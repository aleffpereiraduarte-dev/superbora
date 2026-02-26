<?php
$arquivo = __DIR__ . '/index.php';
$conteudo = file_get_contents($arquivo);
$linhas = file($arquivo);

echo "<h1>Procurando código quebrado</h1>";
echo "<pre style='background:#1e1e1e;color:#fff;padding:20px;font-size:12px;overflow:auto;'>";

// Procurar padrões problemáticos
$padroes = [
    '/\}\s*\}\s*\}\s*\}/' => 'Muitos } seguidos',
    '/\}\s*function\s/' => '} seguido de function (sem fechar script)',
    '/\}\s*\n\s*\n\s*function\s/' => '} e function separados',
    '/\}\s*"\]\`\)\;/' => 'Código lixo }"]`);',
    '/\}\s+else\s+\{[^}]+\}\s*\n\s*updateCartUI/' => 'Código órfão do carrinho',
    '/showToast\([^)]+\);\s*\n\s*\}\s*\n\s*\n\s*function/' => 'Função mal fechada',
];

foreach ($padroes as $padrao => $desc) {
    if (preg_match_all($padrao, $conteudo, $matches, PREG_OFFSET_CAPTURE)) {
        echo "\n<b style='color:#ff6b6b'>═══ $desc ═══</b>\n";
        foreach ($matches[0] as $m) {
            $pos = $m[1];
            // Encontrar linha
            $linha = substr_count(substr($conteudo, 0, $pos), "\n") + 1;
            echo "Linha ~$linha: <span style='background:#333;padding:2px 8px;'>" . htmlspecialchars(substr($m[0], 0, 50)) . "</span>\n";
        }
    }
}

// Procurar linhas com apenas } ou };
echo "\n<b style='color:#ffa500'>═══ Linhas suspeitas (só } ou }) ═══</b>\n";
$count = 0;
foreach ($linhas as $i => $linha) {
    $trimmed = trim($linha);
    if ($trimmed === '}' || $trimmed === '};' || $trimmed === '}});') {
        // Verificar contexto
        $prev = isset($linhas[$i-1]) ? trim($linhas[$i-1]) : '';
        $next = isset($linhas[$i+1]) ? trim($linhas[$i+1]) : '';
        
        // Se depois de } vier algo estranho
        if (preg_match('/^(function|const|let|var|if|else|for|while)/', $next)) {
            $num = $i + 1;
            echo "Linha $num: } seguido de '$next'\n";
            $count++;
            if ($count > 10) { echo "...(mais)...\n"; break; }
        }
    }
}

// Contar chaves { e }
$abre = substr_count($conteudo, '{');
$fecha = substr_count($conteudo, '}');
echo "\n<b style='color:#4ecdc4'>═══ Balanço de chaves ═══</b>\n";
echo "{ = $abre\n";
echo "} = $fecha\n";
echo "Diferença: " . ($abre - $fecha) . "\n";

echo "</pre>";
