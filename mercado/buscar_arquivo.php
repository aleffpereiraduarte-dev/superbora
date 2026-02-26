<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>BUSCANDO ARQUIVO PERDIDO (733KB)</h1>";

$dir = __DIR__;

// Busca TODOS os arquivos PHP na pasta
echo "<h3>Todos os arquivos PHP ordenados por tamanho:</h3>";
$files = glob($dir . '/*.php');
$fileData = [];

foreach ($files as $f) {
    $fileData[] = [
        'name' => basename($f),
        'size' => filesize($f),
        'time' => filemtime($f)
    ];
}

// Ordena por tamanho (maior primeiro)
usort($fileData, function($a, $b) {
    return $b['size'] - $a['size'];
});

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Arquivo</th><th>Tamanho</th><th>Data</th><th>Ação</th></tr>";
foreach ($fileData as $f) {
    $sizeKB = round($f['size'] / 1024, 1);
    $date = date('d/m/Y H:i:s', $f['time']);
    $color = $f['size'] > 720000 ? 'background:yellow;font-weight:bold;' : '';
    echo "<tr style='$color'>";
    echo "<td>{$f['name']}</td>";
    echo "<td>{$sizeKB} KB ({$f['size']} bytes)</td>";
    echo "<td>$date</td>";
    echo "<td><a href='?ver={$f['name']}'>VER</a></td>";
    echo "</tr>";
}
echo "</table>";

// Busca arquivos temporarios ou de backup em outras pastas
echo "<h3>Buscando em outras pastas...</h3>";

$otherPaths = [
    '/tmp',
    $dir . '/../',
    $dir . '/backup',
    $dir . '/backups',
    $dir . '/cache',
];

foreach ($otherPaths as $path) {
    if (is_dir($path)) {
        $found = glob($path . '/one*.php');
        if ($found) {
            echo "<p><b>$path:</b></p><ul>";
            foreach ($found as $f) {
                $size = filesize($f);
                echo "<li>" . basename($f) . " - $size bytes</li>";
            }
            echo "</ul>";
        }
    }
}

// Verifica se tem arquivo com o patch quebrado
echo "<h3>Arquivos que contem 'PATCH-V20':</h3>";
foreach (glob($dir . '/*.php') as $f) {
    $content = file_get_contents($f);
    if (strpos($content, 'PATCH-V20') !== false) {
        $size = filesize($f);
        echo "<p style='color:red;'><b>" . basename($f) . "</b> - $size bytes - CONTEM O PATCH!</p>";
    }
}

// Mostra conteudo de arquivo se solicitado
if (isset($_GET['ver'])) {
    $file = basename($_GET['ver']);
    $path = $dir . '/' . $file;
    if (file_exists($path)) {
        echo "<hr><h3>Conteudo de $file (primeiras 200 linhas):</h3>";
        $lines = file($path);
        echo "<pre style='background:#f5f5f5;padding:10px;max-height:500px;overflow:auto;font-size:11px;'>";
        for ($i = 0; $i < min(200, count($lines)); $i++) {
            echo ($i+1) . ": " . htmlspecialchars($lines[$i]);
        }
        echo "</pre>";
    }
}

// Verifica lixeira do sistema (se acessivel)
echo "<h3>Informacoes do sistema:</h3>";
echo "<p>Pasta atual: $dir</p>";
echo "<p>Usuario PHP: " . get_current_user() . "</p>";

// Comando pra rodar no SSH
echo "<h3>Rode esse comando no SSH pra buscar:</h3>";
echo "<pre style='background:#333;color:#0f0;padding:10px;'>";
echo "find /var/www -name 'one*.php' -size +700k -ls 2>/dev/null\n";
echo "# ou\n";
echo "ls -la /var/www/html/mercado/one*.php | sort -k5 -n";
echo "</pre>";

?>
