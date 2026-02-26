<?php
/**
 * FIX - ERRO SQL PARTNER_ID NULL
 * Corrige a query que quebra quando não tem mercado selecionado
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');

echo "<html><head><meta charset='UTF-8'><title>Fix SQL Partner</title>";
echo "<style>
body { font-family: Arial, sans-serif; background: #1a1a2e; color: #fff; padding: 20px; max-width: 900px; margin: 0 auto; }
h1, h2 { color: #00d4aa; }
.box { background: #16213e; padding: 20px; border-radius: 10px; margin: 15px 0; }
.ok { color: #00d4aa; }
.erro { color: #ff6b6b; }
pre { background: #0f0f23; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 12px; }
.btn { display: inline-block; padding: 15px 30px; background: #00d4aa; color: #000; text-decoration: none; border-radius: 8px; font-weight: bold; margin: 10px 5px; }
</style></head><body>";

echo "<h1>🔧 Fix SQL Partner ID</h1>";

$baseDir = __DIR__;
$indexPath = $baseDir . '/index.php';

$acao = $_GET['acao'] ?? 'analisar';

if ($acao == 'analisar') {
    echo "<div class='box'>";
    echo "<h2>🔍 Analisando o problema...</h2>";
    
    if (!file_exists($indexPath)) {
        echo "<p class='erro'>❌ index.php não encontrado</p>";
        exit;
    }
    
    $content = file_get_contents($indexPath);
    
    // Encontrar a linha 552 e contexto
    $lines = explode("\n", $content);
    
    echo "<p>Contexto ao redor da linha 552:</p>";
    echo "<pre>";
    for ($i = 545; $i <= 560 && $i < count($lines); $i++) {
        $linha = $i + 1;
        $destaque = ($linha == 552) ? ' style="background:#ff6b6b;color:#000"' : '';
        echo "<span$destaque>$linha: " . htmlspecialchars($lines[$i]) . "</span>\n";
    }
    echo "</pre>";
    
    // Procurar queries problemáticas
    echo "<h3>🔍 Queries com partner_id:</h3>";
    
    preg_match_all('/partner_id\s*=\s*[\$\?]/', $content, $matches, PREG_OFFSET_CAPTURE);
    echo "<p>Encontradas " . count($matches[0]) . " ocorrências de 'partner_id = $' ou 'partner_id = ?'</p>";
    
    echo "</div>";
    
    echo "<div class='box'>";
    echo "<h2>💡 Solução</h2>";
    echo "<p>Quando não tem mercado selecionado, o sistema deve:</p>";
    echo "<ol>";
    echo "<li>Mostrar o modal de CEP</li>";
    echo "<li>NÃO executar queries de produtos</li>";
    echo "<li>Mostrar mensagem 'Selecione sua localização'</li>";
    echo "</ol>";
    echo "<p><a href='?acao=corrigir' class='btn'>🔧 APLICAR FIX</a></p>";
    echo "</div>";
    
} elseif ($acao == 'corrigir') {
    
    echo "<div class='box'>";
    echo "<h2>🔧 Aplicando correção...</h2>";
    
    // Backup
    $backupPath = $indexPath . '.backup_fix_' . date('YmdHis');
    copy($indexPath, $backupPath);
    echo "<p class='ok'>✅ Backup: " . basename($backupPath) . "</p>";
    
    $content = file_get_contents($indexPath);
    $original = $content;
    
    // Estratégia: Adicionar verificação antes das queries de produtos
    // Procurar onde as queries são feitas e adicionar proteção
    
    // 1. Encontrar onde $partner_id é usado nas queries e adicionar proteção
    
    // Procurar padrões como "WHERE ... partner_id = $partner_id" ou similar
    // E substituir por versão segura
    
    // Fix 1: Adicionar verificação no início da seção de produtos
    $fixCode = '
// ═══ FIX: VERIFICAR SE TEM MERCADO SELECIONADO ═══
$tem_mercado_selecionado = isset($partner_id) && $partner_id > 0;
if (!$tem_mercado_selecionado) {
    $partner_id = 0; // Valor seguro para queries
}
';
    
    // Procurar um bom lugar para inserir (após a definição de $partner_id)
    if (preg_match('/\$partner_id\s*=\s*\$_SESSION\[.market_partner_id.\]/', $content, $m, PREG_OFFSET_CAPTURE)) {
        // Encontrar o fim da linha
        $pos = $m[0][1];
        $fimLinha = strpos($content, "\n", $pos);
        
        // Verificar se já tem o fix
        if (strpos($content, 'tem_mercado_selecionado') === false) {
            $content = substr($content, 0, $fimLinha + 1) . $fixCode . substr($content, $fimLinha + 1);
            echo "<p class='ok'>✅ Adicionada verificação de mercado</p>";
        } else {
            echo "<p class='aviso'>⚠️ Verificação já existe</p>";
        }
    }
    
    // Fix 2: Proteger queries - substituir partner_id = $partner_id por versão segura
    // Onde $partner_id pode ser 0/null, a query deve retornar vazio
    
    // Procurar e corrigir a query problemática na linha ~552
    // O padrão é: pp.partner_id = $partner_id ou pp.partner_id = {$partner_id}
    
    // Abordagem: mudar a lógica para que partner_id = 0 retorne nada
    // Isso já acontece naturalmente se $partner_id = 0 porque nenhum produto tem partner_id = 0
    
    // Salvar
    if ($content !== $original) {
        file_put_contents($indexPath, $content);
        echo "<p class='ok'>✅ Arquivo atualizado</p>";
    } else {
        echo "<p class='aviso'>⚠️ Nenhuma alteração necessária no conteúdo</p>";
    }
    
    echo "</div>";
    
    // Verificação adicional
    echo "<div class='box'>";
    echo "<h2>📋 Verificação da Query linha 552</h2>";
    
    $content = file_get_contents($indexPath);
    $lines = explode("\n", $content);
    
    // Mostrar linhas 548-555
    echo "<pre>";
    for ($i = 547; $i <= 555 && $i < count($lines); $i++) {
        echo ($i+1) . ": " . htmlspecialchars($lines[$i]) . "\n";
    }
    echo "</pre>";
    
    echo "<p>Se ainda tiver problema, pode ser que a variável \$partner_id não esteja sendo setada corretamente.</p>";
    echo "</div>";
    
    // Mostrar código para fix manual
    echo "<div class='box'>";
    echo "<h2>🛠️ Fix Manual (se necessário)</h2>";
    echo "<p>Na linha onde define \$partner_id, adicione:</p>";
    echo "<pre>";
    echo htmlspecialchars('$partner_id = $_SESSION[\'market_partner_id\'] ?? 0;
if (!$partner_id || $partner_id <= 0) {
    $partner_id = 0; // Seguro - retornará 0 produtos
    $mostrar_modal_cep = true;
}');
    echo "</pre>";
    echo "</div>";
    
    echo "<div class='box' style='text-align:center'>";
    echo "<p><a href='/mercado/' target='_blank' class='btn'>🛒 Testar Mercado</a></p>";
    echo "<p><a href='/mercado/diagnostico_erro500.php' class='btn'>🔍 Diagnóstico</a></p>";
    echo "</div>";
}

echo "</body></html>";
