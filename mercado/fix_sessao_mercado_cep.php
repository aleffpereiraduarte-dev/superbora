<?php
/**
 * FIX - Sincronizar Sessões de Mercado/Parceiro
 * OneMundo Mercado
 * 
 * Problema: A API de localização salva em $_SESSION['mercado_proximo']
 *           mas o index.php usa $_SESSION['market_partner_id']
 * 
 * Este fix sincroniza as duas variáveis
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<html><head><meta charset='UTF-8'><title>Fix Sessão Mercado</title>";
echo "<style>
body { font-family: Arial, sans-serif; background: #1a1a2e; color: #fff; padding: 20px; max-width: 900px; margin: 0 auto; }
.ok { color: #00d4aa; }
.erro { color: #ff6b6b; }
.box { background: #16213e; padding: 20px; border-radius: 10px; margin: 20px 0; }
h1, h2 { color: #00d4aa; }
pre { background: #0f0f23; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 12px; white-space: pre-wrap; }
.btn { display: inline-block; padding: 15px 30px; background: #00d4aa; color: #000; text-decoration: none; border-radius: 8px; font-weight: bold; margin: 10px 5px; border: none; cursor: pointer; }
</style></head><body>";

echo "<h1>🔧 Fix - Sessão de Mercado por CEP</h1>";

$acao = $_GET['acao'] ?? '';

// =============================================
// 1. PATCH NA API DE LOCALIZAÇÃO
// =============================================
if ($acao == 'patch_api') {
    $arquivo = __DIR__ . '/api/localizacao.php';
    
    if (!file_exists($arquivo)) {
        echo "<p class='erro'>Arquivo api/localizacao.php não encontrado!</p>";
        echo "<a href='?' class='btn'>Voltar</a>";
        exit;
    }
    
    $conteudo = file_get_contents($arquivo);
    
    // Verificar se já tem o patch
    if (strpos($conteudo, "market_partner_id") !== false) {
        echo "<p class='ok'>✅ API já tem o patch aplicado!</p>";
        echo "<a href='?' class='btn'>Voltar</a>";
        exit;
    }
    
    // Aplicar patch: adicionar sincronização após salvar mercado_proximo
    $buscar = "\$_SESSION['mercado_proximo'] = [";
    $substituir = "\$_SESSION['market_partner_id'] = \$mercado['partner_id'];
            \$_SESSION['market_partner_name'] = \$mercado['nome'];
            \$_SESSION['mercado_proximo'] = [";
    
    $conteudo_novo = str_replace($buscar, $substituir, $conteudo);
    
    if ($conteudo === $conteudo_novo) {
        echo "<p class='erro'>Não foi possível aplicar o patch. Estrutura do arquivo diferente.</p>";
        echo "<a href='?' class='btn'>Voltar</a>";
        exit;
    }
    
    // Backup
    copy($arquivo, $arquivo . '.backup_' . date('YmdHis'));
    
    // Salvar
    file_put_contents($arquivo, $conteudo_novo);
    
    echo "<p class='ok'>✅ Patch aplicado com sucesso!</p>";
    echo "<p>Agora quando o CEP for verificado, vai setar:</p>";
    echo "<pre>\$_SESSION['market_partner_id']\n\$_SESSION['market_partner_name']\n\$_SESSION['mercado_proximo']</pre>";
    
    echo "<a href='?' class='btn'>Voltar</a>";
}

// =============================================
// 2. INSTRUÇÕES DE INCLUSÃO DO COMPONENTE
// =============================================
elseif ($acao == 'instrucoes') {
    echo "<h2>📖 Como Ativar o Seletor de CEP</h2>";
    
    echo "<div class='box'>";
    echo "<h3>Passo 1: No index.php</h3>";
    echo "<p>Encontre o header (por volta da linha onde tem o logo) e adicione:</p>";
    echo "<pre>" . htmlspecialchars("<?php include 'components/endereco-entrega.php'; ?>") . "</pre>";
    echo "</div>";
    
    echo "<div class='box'>";
    echo "<h3>Passo 2: No header, ao lado do logo</h3>";
    echo "<p>O componente já renderiza um botão 'Entregar em'. Você só precisa incluir ele no local certo do HTML.</p>";
    echo "<p>Procure por <code>&lt;header</code> ou <code>class=\"header\"</code> e coloque o include depois do logo.</p>";
    echo "</div>";
    
    echo "<div class='box'>";
    echo "<h3>Passo 3: Testar</h3>";
    echo "<ol>";
    echo "<li>Abra o site em janela anônima (sem login)</li>";
    echo "<li>Clique em 'Entregar em' / 'Informe seu CEP'</li>";
    echo "<li>Digite um CEP e clique Verificar</li>";
    echo "<li>Se aparecer o mercado, a página deve recarregar com os produtos filtrados</li>";
    echo "</ol>";
    echo "</div>";
    
    echo "<a href='?' class='btn'>Voltar</a>";
}

// =============================================
// 3. CÓDIGO PARA INCLUIR NO INDEX.PHP
// =============================================
elseif ($acao == 'gerar_codigo') {
    echo "<h2>📝 Código para Incluir no index.php</h2>";
    
    echo "<div class='box'>";
    echo "<h3>1. No início do arquivo (após session_start)</h3>";
    echo "<pre>";
    echo htmlspecialchars('<?php
// Sincronizar sessões de mercado (se veio da API de localização)
if (isset($_SESSION["mercado_proximo"]["partner_id"]) && !isset($_SESSION["market_partner_id"])) {
    $_SESSION["market_partner_id"] = $_SESSION["mercado_proximo"]["partner_id"];
    $_SESSION["market_partner_name"] = $_SESSION["mercado_proximo"]["nome"] ?? "Mercado";
}
?>');
    echo "</pre>";
    echo "</div>";
    
    echo "<div class='box'>";
    echo "<h3>2. No header (onde quer que apareça o seletor)</h3>";
    echo "<pre>" . htmlspecialchars('<?php include __DIR__ . "/components/endereco-entrega.php"; ?>') . "</pre>";
    echo "</div>";
    
    echo "<div class='box'>";
    echo "<h3>3. Opcional: Forçar modal de CEP se não tiver mercado</h3>";
    echo "<pre>";
    echo htmlspecialchars('<?php if (!isset($_SESSION["market_partner_id"])): ?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    if (typeof abrirModalEndereco === "function") {
        setTimeout(abrirModalEndereco, 1000);
    }
});
</script>
<?php endif; ?>');
    echo "</pre>";
    echo "</div>";
    
    echo "<a href='?' class='btn'>Voltar</a>";
}

// =============================================
// MENU PRINCIPAL
// =============================================
else {
    echo "<div class='box'>";
    echo "<h2>📋 Diagnóstico</h2>";
    
    // Verificar arquivos
    $apiExiste = file_exists(__DIR__ . '/api/localizacao.php');
    $compExiste = file_exists(__DIR__ . '/components/endereco-entrega.php');
    
    echo "<p>API localizacao.php: <span class='" . ($apiExiste ? 'ok' : 'erro') . "'>" . ($apiExiste ? '✅ Existe' : '❌ Não existe') . "</span></p>";
    echo "<p>Componente endereco-entrega.php: <span class='" . ($compExiste ? 'ok' : 'erro') . "'>" . ($compExiste ? '✅ Existe' : '❌ Não existe') . "</span></p>";
    
    // Verificar se API tem o patch
    if ($apiExiste) {
        $conteudo = file_get_contents(__DIR__ . '/api/localizacao.php');
        $temPatch = strpos($conteudo, "market_partner_id") !== false;
        echo "<p>API sincroniza sessões: <span class='" . ($temPatch ? 'ok' : 'erro') . "'>" . ($temPatch ? '✅ Sim' : '❌ Não (precisa do patch)') . "</span></p>";
    }
    
    echo "</div>";
    
    echo "<div class='box'>";
    echo "<h2>🚀 Ações</h2>";
    echo "<p><a href='?acao=patch_api' class='btn'>1. Aplicar Patch na API</a></p>";
    echo "<p>Faz a API setar <code>\$_SESSION['market_partner_id']</code> quando verificar CEP</p>";
    echo "<br>";
    echo "<p><a href='?acao=gerar_codigo' class='btn'>2. Ver Código para index.php</a></p>";
    echo "<p>Mostra o código que você precisa adicionar no index.php</p>";
    echo "<br>";
    echo "<p><a href='?acao=instrucoes' class='btn'>3. Instruções Completas</a></p>";
    echo "</div>";
    
    echo "<div class='box'>";
    echo "<h2>🔗 Testar</h2>";
    echo "<p><a href='/mercado/api/localizacao.php?action=verificar_cep&cep=01310100&debug=1' target='_blank' class='btn'>Testar API com CEP 01310-100</a></p>";
    echo "</div>";
}

echo "</body></html>";
