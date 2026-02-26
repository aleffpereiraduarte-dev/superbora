<?php
/**
 * FIX - MOVER INCLUDE DO MODAL
 * O problema: Modal é incluído ANTES do auth-guard.php
 * Solução: Mover para DEPOIS
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');

echo "<html><head><meta charset='UTF-8'><title>Fix Include Modal</title>";
echo "<style>
body { font-family: Arial, sans-serif; background: #1a1a2e; color: #fff; padding: 20px; max-width: 900px; margin: 0 auto; }
h1, h2 { color: #00d4aa; }
.box { background: #16213e; padding: 20px; border-radius: 10px; margin: 15px 0; }
.ok { color: #00d4aa; }
.erro { color: #ff6b6b; }
pre { background: #0a0a15; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 12px; }
.btn { display: inline-block; padding: 15px 30px; background: #00d4aa; color: #000; text-decoration: none; border-radius: 8px; font-weight: bold; margin: 10px 5px; }
</style></head><body>";

echo "<h1>🔧 Fix - Posição do Include do Modal</h1>";

$baseDir = __DIR__;
$indexPath = $baseDir . '/index.php';

$acao = $_GET['acao'] ?? 'analisar';

if ($acao == 'analisar') {
    
    echo "<div class='box'>";
    echo "<h2>🔍 Problema Identificado</h2>";
    echo "<p>O modal está sendo incluído <strong>ANTES</strong> do código que configura a sessão:</p>";
    echo "<pre>";
    echo htmlspecialchars('<body>
<?php include ... modal_verificar_cep.php; ?>  ← MODAL AQUI (sessão vazia!)

<?php
require_once \'auth-guard.php\';  ← SESSÃO CONFIGURADA AQUI (depois!)');
    echo "</pre>";
    echo "<p class='erro'>Quando o modal roda, a sessão ainda não tem market_partner_id!</p>";
    echo "</div>";
    
    echo "<div class='box'>";
    echo "<h2>💡 Solução</h2>";
    echo "<p>Mover o include do modal para <strong>DEPOIS</strong> do auth-guard.php</p>";
    echo "<pre>";
    echo htmlspecialchars('<body>
<?php
require_once \'auth-guard.php\';  ← PRIMEIRO: configura sessão
include __DIR__ . \'/components/modal_verificar_cep.php\';  ← DEPOIS: modal com sessão OK
?>');
    echo "</pre>";
    echo "</div>";
    
    echo "<div class='box' style='text-align:center'>";
    echo "<p><a href='?acao=corrigir' class='btn'>🔧 APLICAR FIX</a></p>";
    echo "</div>";
    
} elseif ($acao == 'corrigir') {
    
    echo "<div class='box'>";
    echo "<h2>🔧 Aplicando correção...</h2>";
    
    // Backup
    $backupPath = $indexPath . '.backup_modal_pos_' . date('YmdHis');
    copy($indexPath, $backupPath);
    echo "<p class='ok'>✅ Backup: " . basename($backupPath) . "</p>";
    
    $content = file_get_contents($indexPath);
    $alterou = false;
    
    // PASSO 1: Remover o include antigo (logo após <body>)
    $patterns = [
        '/(<body[^>]*>)\s*<\?php\s+include\s+__DIR__\s*\.\s*[\'"]\/components\/modal_verificar_cep\.php[\'"]\s*;\s*\?>/is',
        '/(<body[^>]*>)\s*\n\s*<\?php\s+include.*modal_verificar_cep.*\?>/is'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, '$1', $content);
            echo "<p class='ok'>✅ Removido include antigo (após body)</p>";
            $alterou = true;
            break;
        }
    }
    
    // PASSO 2: Adicionar include DEPOIS do auth-guard.php
    // Procurar: require_once 'auth-guard.php'; ou require 'auth-guard.php';
    if (preg_match('/(require(?:_once)?\s*[\(]?\s*[\'"]auth-guard\.php[\'"]\s*[\)]?\s*;)/', $content, $matches, PREG_OFFSET_CAPTURE)) {
        
        $pos = $matches[0][1] + strlen($matches[0][0]);
        
        // Verificar se já tem o include do modal logo depois
        $proximos100chars = substr($content, $pos, 100);
        if (strpos($proximos100chars, 'modal_verificar_cep') === false) {
            // Adicionar
            $includeModal = "\ninclude __DIR__ . '/components/modal_verificar_cep.php';\n";
            $content = substr($content, 0, $pos) . $includeModal . substr($content, $pos);
            echo "<p class='ok'>✅ Adicionado include após auth-guard.php</p>";
            $alterou = true;
        } else {
            echo "<p class='ok'>✅ Include já está após auth-guard.php</p>";
        }
    } else {
        echo "<p class='erro'>❌ auth-guard.php não encontrado</p>";
        
        // Alternativa: procurar onde $partner_id ou market_partner_id é definido
        echo "<p>Tentando abordagem alternativa...</p>";
    }
    
    if ($alterou) {
        file_put_contents($indexPath, $content);
        echo "<p class='ok'><strong>✅ Arquivo atualizado!</strong></p>";
    } else {
        echo "<p class='aviso'>⚠️ Nenhuma alteração necessária</p>";
    }
    
    echo "</div>";
    
    // Mostrar resultado
    echo "<div class='box'>";
    echo "<h2>📋 Verificando resultado</h2>";
    
    $newContent = file_get_contents($indexPath);
    
    // Procurar auth-guard e mostrar contexto
    if (preg_match('/auth-guard/', $newContent, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1];
        $inicio = max(0, $pos - 50);
        $fim = min(strlen($newContent), $pos + 250);
        $contexto = substr($newContent, $inicio, $fim - $inicio);
        echo "<pre>" . htmlspecialchars($contexto) . "</pre>";
    }
    
    echo "</div>";
    
    echo "<div class='box' style='text-align:center'>";
    echo "<p><a href='/mercado/' target='_blank' class='btn'>🛒 Testar Mercado</a></p>";
    echo "<p><a href='/mercado/diagnostico_modal.php' class='btn'>🔍 Diagnóstico</a></p>";
    echo "</div>";
}

echo "</body></html>";
