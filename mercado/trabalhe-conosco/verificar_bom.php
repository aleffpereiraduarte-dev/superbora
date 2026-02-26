<?php
$path = __DIR__ . '/includes/theme.php';
$content = file_get_contents($path);

echo "<pre style='background:#000;color:#0f0;padding:20px;'>";
echo "🔍 VERIFICANDO INÍCIO DO ARQUIVO\n\n";

// Primeiros 20 bytes em HEX
echo "Primeiros 20 bytes em HEX:\n";
for ($i = 0; $i < 20; $i++) {
    echo sprintf("%02X ", ord($content[$i]));
}
echo "\n\n";

// Verificar BOM
$bom_utf8 = "\xEF\xBB\xBF";
$bom_utf16_le = "\xFF\xFE";
$bom_utf16_be = "\xFE\xFF";

if (substr($content, 0, 3) === $bom_utf8) {
    echo "❌ ENCONTRADO: BOM UTF-8 (EF BB BF) no início!\n";
    echo "ISSO CAUSA ERRO 500!\n\n";
    
    // Remover BOM
    $content_clean = substr($content, 3);
    file_put_contents($path, $content_clean);
    echo "✅ BOM REMOVIDO! Arquivo corrigido.\n";
    
} elseif (substr($content, 0, 2) === $bom_utf16_le) {
    echo "❌ ENCONTRADO: BOM UTF-16 LE!\n";
} elseif (substr($content, 0, 2) === $bom_utf16_be) {
    echo "❌ ENCONTRADO: BOM UTF-16 BE!\n";
} else {
    echo "Primeiros caracteres: ";
    for ($i = 0; $i < 10; $i++) {
        $c = $content[$i];
        if (ord($c) < 32 || ord($c) > 126) {
            echo "[0x" . sprintf("%02X", ord($c)) . "]";
        } else {
            echo $c;
        }
    }
    echo "\n\n";
    
    // Verificar se começa com <?php
    if (substr($content, 0, 5) === '<?php') {
        echo "✅ Arquivo começa com <?php corretamente\n";
    } else {
        echo "❌ Arquivo NÃO começa com <?php!\n";
        echo "Começa com: [" . substr($content, 0, 10) . "]\n";
        
        // Procurar <?php
        $pos = strpos($content, '<?php');
        if ($pos !== false) {
            echo "<?php encontrado na posição $pos\n";
            echo "Há $pos caracteres antes do <?php\n\n";
            
            // Mostrar o que tem antes
            echo "Caracteres antes do <?php:\n";
            for ($i = 0; $i < $pos; $i++) {
                echo sprintf("[%d]=0x%02X ", $i, ord($content[$i]));
            }
            echo "\n\n";
            
            // Remover caracteres antes
            $content_clean = substr($content, $pos);
            file_put_contents($path, $content_clean);
            echo "✅ Caracteres removidos! Arquivo corrigido.\n";
        }
    }
}

// Testar arquivo após correção
echo "\n\nTestando sintaxe após correção...\n";
exec("php -l " . escapeshellarg($path) . " 2>&1", $output, $return);
if ($return === 0) {
    echo "✅ SINTAXE OK! Arquivo corrigido com sucesso!\n";
} else {
    echo "❌ Ainda tem erro: " . implode("\n", $output) . "\n";
}

echo "</pre>";
