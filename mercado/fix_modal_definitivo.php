<?php
/**
 * FIX DEFINITIVO - MODAL NA POSIÇÃO CORRETA
 * 
 * Problema: Modal na linha 3 (antes da sessão ser configurada)
 * Solução: Remover da linha 3, deixar apenas após <body> (linha 14230+)
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');

echo "<html><head><meta charset='UTF-8'><title>Fix Definitivo Modal</title>";
echo "<style>
body { font-family: Arial, sans-serif; background: #1a1a2e; color: #fff; padding: 20px; max-width: 900px; margin: 0 auto; }
h1, h2 { color: #00d4aa; }
.box { background: #16213e; padding: 20px; border-radius: 10px; margin: 15px 0; }
.ok { color: #00d4aa; }
.erro { color: #ff6b6b; }
.aviso { color: #ffc107; }
pre { background: #0a0a15; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 12px; }
.btn { display: inline-block; padding: 15px 30px; background: #00d4aa; color: #000; text-decoration: none; border-radius: 8px; font-weight: bold; margin: 10px 5px; }
</style></head><body>";

echo "<h1>🔧 Fix Definitivo - Modal</h1>";

$baseDir = __DIR__;
$indexPath = $baseDir . '/index.php';

$acao = $_GET['acao'] ?? 'analisar';

if ($acao == 'analisar') {
    
    $content = file_get_contents($indexPath);
    
    // Contar quantos includes do modal existem
    preg_match_all('/include.*modal_verificar_cep/i', $content, $matches, PREG_OFFSET_CAPTURE);
    
    echo "<div class='box'>";
    echo "<h2>🔍 Análise Atual</h2>";
    echo "<p>Includes do modal encontrados: <strong>" . count($matches[0]) . "</strong></p>";
    
    if (count($matches[0]) > 0) {
        echo "<ul>";
        foreach ($matches[0] as $m) {
            $pos = $m[1];
            $linha = substr_count(substr($content, 0, $pos), "\n") + 1;
            echo "<li>Linha $linha: <code>" . htmlspecialchars(substr($m[0], 0, 60)) . "</code></li>";
        }
        echo "</ul>";
    }
    echo "</div>";
    
    echo "<div class='box'>";
    echo "<h2>💡 O que será feito</h2>";
    echo "<ol>";
    echo "<li><strong>Remover</strong> include da linha 3 (antes da sessão)</li>";
    echo "<li><strong>Garantir</strong> que existe include após &lt;body&gt; (após sessão configurada)</li>";
    echo "</ol>";
    
    echo "<p>Fluxo correto:</p>";
    echo "<pre>";
    echo "Linha 2:     auth-guard.php\n";
    echo "Linha 3:     [REMOVER include aqui]\n";
    echo "...\n";
    echo "Linha 266:   \$_SESSION['market_partner_id'] = ...;\n";
    echo "...\n";
    echo "Linha 14230: &lt;body&gt;\n";
    echo "Linha 14231: include modal_verificar_cep.php;  ← CORRETO!\n";
    echo "</pre>";
    echo "</div>";
    
    echo "<div class='box' style='text-align:center'>";
    echo "<p><a href='?acao=corrigir' class='btn'>🔧 APLICAR FIX</a></p>";
    echo "</div>";
    
} elseif ($acao == 'corrigir') {
    
    echo "<div class='box'>";
    echo "<h2>🔧 Aplicando correção...</h2>";
    
    // Backup
    $backupPath = $indexPath . '.backup_definitivo_' . date('YmdHis');
    copy($indexPath, $backupPath);
    echo "<p class='ok'>✅ Backup: " . basename($backupPath) . "</p>";
    
    $content = file_get_contents($indexPath);
    $alterou = false;
    
    // PASSO 1: Remover include do modal das primeiras 50 linhas
    $lines = explode("\n", $content);
    
    for ($i = 0; $i < min(50, count($lines)); $i++) {
        if (strpos($lines[$i], 'modal_verificar_cep') !== false && strpos($lines[$i], 'include') !== false) {
            echo "<p class='ok'>✅ Removendo include da linha " . ($i + 1) . ": <code>" . htmlspecialchars(trim($lines[$i])) . "</code></p>";
            // Comentar a linha em vez de deletar
            $lines[$i] = '// [REMOVIDO - modal movido para após body] ' . $lines[$i];
            $alterou = true;
        }
    }
    
    $content = implode("\n", $lines);
    
    // PASSO 2: Verificar se já tem include após <body>
    // Procurar padrão: <body> seguido de include modal
    $temIncludeAposBody = preg_match('/<body[^>]*>\s*(<\?php\s+)?include.*modal_verificar_cep/is', $content);
    
    if (!$temIncludeAposBody) {
        echo "<p class='aviso'>⚠️ Include após &lt;body&gt; não encontrado. Adicionando...</p>";
        
        // Adicionar após <body>
        $content = preg_replace(
            '/(<body[^>]*>)/i',
            "$1\n<?php include __DIR__ . '/components/modal_verificar_cep.php'; ?>",
            $content,
            1
        );
        echo "<p class='ok'>✅ Adicionado include após &lt;body&gt;</p>";
        $alterou = true;
    } else {
        echo "<p class='ok'>✅ Include após &lt;body&gt; já existe</p>";
    }
    
    // Salvar
    if ($alterou) {
        file_put_contents($indexPath, $content);
        echo "<p><strong>✅ Arquivo atualizado!</strong></p>";
    } else {
        echo "<p class='aviso'>⚠️ Nenhuma alteração necessária</p>";
    }
    
    echo "</div>";
    
    // Mostrar resultado - primeiras 10 linhas
    echo "<div class='box'>";
    echo "<h2>📋 Primeiras 10 linhas (após fix)</h2>";
    
    $newContent = file_get_contents($indexPath);
    $newLines = explode("\n", $newContent);
    
    echo "<pre>";
    for ($i = 0; $i < min(10, count($newLines)); $i++) {
        $num = $i + 1;
        $linha = htmlspecialchars($newLines[$i]);
        if (strpos($newLines[$i], 'REMOVIDO') !== false) {
            echo "<span style='color:#ffc107'>$num: $linha</span>\n";
        } else {
            echo "$num: $linha\n";
        }
    }
    echo "</pre>";
    echo "</div>";
    
    // Mostrar região do <body>
    echo "<div class='box'>";
    echo "<h2>📋 Região do &lt;body&gt; (após fix)</h2>";
    
    // Encontrar <body>
    foreach ($newLines as $i => $line) {
        if (preg_match('/<body/i', $line)) {
            echo "<pre>";
            for ($j = max(0, $i - 2); $j < min(count($newLines), $i + 5); $j++) {
                $num = $j + 1;
                $l = htmlspecialchars($newLines[$j]);
                if (strpos($newLines[$j], 'modal_verificar_cep') !== false) {
                    echo "<span style='color:#00d4aa'>$num: $l</span>\n";
                } elseif (strpos($newLines[$j], '<body') !== false) {
                    echo "<span style='color:#ffc107'>$num: $l</span>\n";
                } else {
                    echo "$num: $l\n";
                }
            }
            echo "</pre>";
            break;
        }
    }
    echo "</div>";
    
    // Verificação final
    echo "<div class='box'>";
    echo "<h2>✅ Verificação Final</h2>";
    
    $finalContent = file_get_contents($indexPath);
    
    // Contar includes
    preg_match_all('/include.*modal_verificar_cep/i', $finalContent, $finalMatches);
    $total = count($finalMatches[0]);
    
    // Verificar se tem nas primeiras 50 linhas (não comentado)
    $finalLines = explode("\n", $finalContent);
    $temNoTopo = false;
    for ($i = 0; $i < min(50, count($finalLines)); $i++) {
        if (strpos($finalLines[$i], 'modal_verificar_cep') !== false 
            && strpos($finalLines[$i], 'include') !== false
            && strpos($finalLines[$i], '//') !== 0
            && strpos($finalLines[$i], 'REMOVIDO') === false) {
            $temNoTopo = true;
            break;
        }
    }
    
    echo "<ul>";
    echo "<li>Total de includes do modal: $total</li>";
    echo "<li>Include no topo (antes sessão): " . ($temNoTopo ? "<span class='erro'>SIM ❌</span>" : "<span class='ok'>NÃO ✅</span>") . "</li>";
    echo "</ul>";
    
    if (!$temNoTopo && $total > 0) {
        echo "<p class='ok'><strong>✅ FIX APLICADO COM SUCESSO!</strong></p>";
        echo "<p>Agora o modal só será carregado após a sessão estar configurada.</p>";
    }
    echo "</div>";
    
    echo "<div class='box' style='text-align:center'>";
    echo "<p><a href='/mercado/' target='_blank' class='btn'>🛒 Testar Mercado</a></p>";
    echo "<p><a href='/mercado/diagnostico_sessao.php' class='btn'>🔍 Diagnóstico Sessão</a></p>";
    echo "</div>";
}

echo "</body></html>";
