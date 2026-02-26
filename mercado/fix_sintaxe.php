<?php
/**
 * 🔧 FIX - Corrigir erro de sintaxe + função faltante
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');

echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>🔧 Fix Erro Sintaxe</title>
    <style>
        body { font-family: "Segoe UI", sans-serif; background: #0f172a; color: #e2e8f0; padding: 40px; }
        .container { max-width: 900px; margin: 0 auto; }
        h1 { color: #ef4444; }
        .card { background: #1e293b; border-radius: 12px; padding: 24px; margin: 20px 0; }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        pre { background: #0f172a; padding: 16px; border-radius: 8px; overflow-x: auto; font-size: 12px; }
        .btn { background: #ef4444; color: white; border: none; padding: 14px 28px; border-radius: 8px; cursor: pointer; font-size: 16px; }
    </style>
</head>
<body>
<div class="container">';

echo '<h1>🔧 Fix Erro de Sintaxe</h1>';

$onePath = __DIR__ . '/one.php';

// Listar backups disponíveis
echo '<div class="card">';
echo '<h2>📋 Backups Disponíveis</h2>';

$backups = glob($onePath . '.backup_*');
rsort($backups); // Mais recente primeiro

if (empty($backups)) {
    echo '<p class="error">❌ Nenhum backup encontrado!</p>';
} else {
    echo '<table style="width:100%;border-collapse:collapse;">';
    echo '<tr><th style="text-align:left;padding:8px;border-bottom:1px solid #334155;">Arquivo</th><th style="text-align:left;padding:8px;border-bottom:1px solid #334155;">Data</th><th style="padding:8px;border-bottom:1px solid #334155;">Ação</th></tr>';
    
    foreach (array_slice($backups, 0, 10) as $bkp) {
        $nome = basename($bkp);
        $data = date('d/m/Y H:i:s', filemtime($bkp));
        $size = round(filesize($bkp) / 1024) . 'KB';
        
        // Verificar sintaxe do backup
        $check = shell_exec("php -l $bkp 2>&1");
        $ok = strpos($check, 'No syntax errors') !== false;
        $status = $ok ? '✅' : '❌';
        
        echo "<tr>";
        echo "<td style='padding:8px;border-bottom:1px solid #334155;'>$status $nome ($size)</td>";
        echo "<td style='padding:8px;border-bottom:1px solid #334155;'>$data</td>";
        echo "<td style='padding:8px;border-bottom:1px solid #334155;'>";
        if ($ok) {
            echo "<form method='post' style='display:inline;'><input type='hidden' name='restaurar' value='$bkp'><button type='submit' style='background:#10b981;color:white;border:none;padding:6px 12px;border-radius:4px;cursor:pointer;'>Restaurar</button></form>";
        }
        echo "</td>";
        echo "</tr>";
    }
    echo '</table>';
}
echo '</div>';

// Restaurar backup
if (isset($_POST['restaurar'])) {
    $bkpFile = $_POST['restaurar'];
    
    if (file_exists($bkpFile)) {
        // Verificar sintaxe antes
        $check = shell_exec("php -l $bkpFile 2>&1");
        
        if (strpos($check, 'No syntax errors') !== false) {
            // Fazer backup do atual quebrado
            copy($onePath, $onePath . '.quebrado_' . date('Y-m-d_H-i-s'));
            
            // Restaurar
            copy($bkpFile, $onePath);
            
            echo '<div class="card" style="border-color:#10b981;">';
            echo '<h2 class="success">✅ Backup Restaurado!</h2>';
            echo '<p>Arquivo restaurado: ' . basename($bkpFile) . '</p>';
            
            // Verificar sintaxe do restaurado
            $checkNovo = shell_exec("php -l $onePath 2>&1");
            echo '<pre>' . $checkNovo . '</pre>';
            
            echo '<p><a href="one.php?action=send&message=oi" target="_blank" style="color:#10b981;">🧪 Testar ONE</a></p>';
            echo '</div>';
        } else {
            echo '<div class="card"><p class="error">❌ Backup também tem erro de sintaxe!</p></div>';
        }
    }
}

// Mostrar linha do erro
echo '<div class="card">';
echo '<h2>🔍 Linha do Erro (8594)</h2>';

$conteudo = file_get_contents($onePath);
$linhas = explode("\n", $conteudo);

echo '<pre>';
for ($i = 8588; $i < 8600 && $i < count($linhas); $i++) {
    $num = $i + 1;
    $linha = htmlspecialchars($linhas[$i]);
    $destaque = ($num == 8594) ? ' style="background:#ef4444;color:white;"' : '';
    echo "<span$destaque>$num: $linha</span>\n";
}
echo '</pre>';
echo '</div>';

// Adicionar função carregarContexto se não existir
echo '<div class="card">';
echo '<h2>🔧 Função carregarContexto()</h2>';

if (strpos($conteudo, 'function carregarContexto') === false) {
    echo '<p class="error">❌ Função carregarContexto() NÃO EXISTE</p>';
    echo '<p>Após restaurar o backup, rode o instalador E novamente.</p>';
} else {
    echo '<p class="success">✅ Função existe</p>';
}
echo '</div>';

echo '<div class="card">';
echo '<h2>💡 Recomendação</h2>';
echo '<ol style="line-height:2;">';
echo '<li>Clique em <strong>Restaurar</strong> no backup mais recente que está ✅</li>';
echo '<li>Depois rode os instaladores novamente, <strong>um de cada vez</strong></li>';
echo '<li>Teste após cada instalador antes de rodar o próximo</li>';
echo '</ol>';
echo '</div>';

echo '</div></body></html>';
