<?php
// Capturar o HTML gerado e procurar onclicks problemáticos
ob_start();
include __DIR__ . '/index.php';
$html = ob_get_clean();

// Salvar o HTML para análise
file_put_contents(__DIR__ . '/debug_html.txt', $html);

// Encontrar todas as linhas com onclick
$lines = explode("\n", $html);
$problemas = [];

foreach ($lines as $num => $line) {
    $lineNum = $num + 1;
    
    // Procurar onclick que não fecha corretamente
    if (preg_match('/onclick="[^"]*$/', $line)) {
        $problemas[] = [
            'linha' => $lineNum,
            'tipo' => 'onclick não fecha na mesma linha',
            'preview' => substr($line, 0, 200)
        ];
    }
    
    // Procurar addToCart com parâmetros estranhos
    if (preg_match('/addToCart\([^)]*$/', $line)) {
        $problemas[] = [
            'linha' => $lineNum,
            'tipo' => 'addToCart não fecha na mesma linha',
            'preview' => substr($line, 0, 200)
        ];
    }
    
    // Procurar aspas desbalanceadas em onclick
    if (strpos($line, 'onclick=') !== false) {
        preg_match('/onclick="([^"]*)"/', $line, $match);
        if (isset($match[1])) {
            $content = $match[1];
            $singleQuotes = substr_count($content, "'") - substr_count($content, "\\'");
            if ($singleQuotes % 2 != 0) {
                $problemas[] = [
                    'linha' => $lineNum,
                    'tipo' => 'Aspas simples desbalanceadas no onclick',
                    'preview' => substr($line, 0, 200)
                ];
            }
        }
    }
}

echo "<h2>Análise do HTML Gerado</h2>";
echo "<p>Total de linhas: " . count($lines) . "</p>";
echo "<p>HTML salvo em: debug_html.txt</p>";

if (empty($problemas)) {
    echo "<p style='color:green'>✅ Nenhum problema óbvio encontrado nos onclicks</p>";
} else {
    echo "<h3 style='color:red'>❌ Problemas encontrados:</h3>";
    foreach ($problemas as $p) {
        echo "<div style='background:#1e1e1e;color:#fff;padding:15px;margin:10px 0;border-radius:8px;'>";
        echo "<p><b>Linha {$p['linha']}</b>: {$p['tipo']}</p>";
        echo "<pre style='font-size:11px;overflow-x:auto'>" . htmlspecialchars($p['preview']) . "</pre>";
        echo "</div>";
    }
}

// Mostrar linhas próximas a 14643
echo "<h3>Contexto da linha 14643:</h3>";
echo "<pre style='background:#1e1e1e;color:#fff;padding:15px;overflow-x:auto'>";
for ($i = 14638; $i <= 14648; $i++) {
    if (isset($lines[$i-1])) {
        echo "<span style='color:#888'>$i:</span> " . htmlspecialchars(substr($lines[$i-1], 0, 150)) . "\n";
    }
}
echo "</pre>";
