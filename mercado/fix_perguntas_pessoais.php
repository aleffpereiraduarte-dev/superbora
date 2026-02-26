<?php
/**
 * 🔧 FIX - Perguntas Pessoais
 * Adiciona detecção de perguntas sobre o próprio usuário
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔧 Fix Perguntas Pessoais</h1>";
echo "<style>body{font-family:sans-serif;background:#0f172a;color:#e2e8f0;padding:40px;}pre{background:#1e293b;padding:16px;border-radius:8px;}.btn{background:#10b981;color:white;padding:16px 32px;border:none;border-radius:12px;cursor:pointer;font-size:18px;}.success{color:#10b981;}.error{color:#ef4444;}</style>";

$onePath = __DIR__ . '/one.php';
$conteudo = file_get_contents($onePath);

// Verificar se já tem
$jaTem = strpos($conteudo, '// ═══ PERGUNTAS PESSOAIS ═══') !== false;

echo "<p>Fix já instalado: " . ($jaTem ? '<span class="success">✅ SIM</span>' : '❌ NÃO') . "</p>";

if (isset($_GET['fix'])) {
    
    // Backup
    copy($onePath, $onePath . '.bkp_pessoal_' . time());
    echo "<p class='success'>✅ Backup criado</p>";
    
    // Código para adicionar - detecta perguntas pessoais e responde com dados do cliente
    $codigoPessoal = '
            // ═══ PERGUNTAS PESSOAIS ═══
            // Detecta perguntas sobre o próprio usuário (nome, email, etc)
            if (preg_match(\'/(qual|como).*(meu nome|me chamo|meu email|meu telefone|meu endereço|meu endereco|voce sabe meu|você sabe meu|sabe quem eu sou|quem sou eu|me conhece)/i\', $msg)) {
                $cliente = $this->carregarClienteCompleto();
                if ($cliente) {
                    $nome = trim($cliente[\'firstname\'] . \' \' . $cliente[\'lastname\']);
                    $resp = "Claro que sei! Você é $nome! 😊";
                    
                    if (preg_match(\'/(email|e-mail)/i\', $msg)) {
                        $resp = "Seu email é {$cliente[\'email\']}! 📧";
                    } elseif (preg_match(\'/(telefone|celular|whatsapp)/i\', $msg)) {
                        $resp = "Seu telefone é {$cliente[\'telephone\']}! 📱";
                    } elseif (preg_match(\'/(endereço|endereco|onde moro)/i\', $msg) && !empty($cliente[\'enderecos\'])) {
                        $end = $cliente[\'enderecos\'][0];
                        $resp = "Seu endereço principal é {$end[\'address_1\']}, {$end[\'city\']}! 🏠";
                    }
                    
                    $this->salvar(\'one\', $resp, [\'fonte\' => \'pergunta_pessoal\']);
                    return [\'success\' => true, \'response\' => $resp, \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
                }
            }
            
';
    
    // Encontrar onde inserir - logo após o início do processar(), antes do detector de intenção
    // Vamos inserir após "// FIM MÓDULO LOGIN" ou antes do detector universal
    
    $marcador = '// 🎯 ONE UNIVERSAL - DETECTOR DE INTENÇÃO';
    
    if (strpos($conteudo, $marcador) !== false && !$jaTem) {
        $conteudo = str_replace($marcador, $codigoPessoal . "\n            " . $marcador, $conteudo);
        echo "<p class='success'>✅ Código de perguntas pessoais inserido!</p>";
    } elseif ($jaTem) {
        echo "<p>⚠️ Já está instalado</p>";
    } else {
        // Tentar outro marcador
        $marcador2 = '$intencaoDetectada = $this->detectarIntencaoUniversal($msg);';
        if (strpos($conteudo, $marcador2) !== false) {
            $conteudo = str_replace($marcador2, $codigoPessoal . "\n            " . $marcador2, $conteudo);
            echo "<p class='success'>✅ Código inserido (marcador alternativo)!</p>";
        } else {
            echo "<p class='error'>❌ Marcador não encontrado</p>";
        }
    }
    
    // Salvar
    file_put_contents($onePath, $conteudo);
    
    // Verificar sintaxe
    $check = shell_exec("php -l $onePath 2>&1");
    $ok = strpos($check, 'No syntax errors') !== false;
    
    if ($ok) {
        echo "<h2 class='success'>✅ FIX APLICADO!</h2>";
        echo "<p><a href='teste_api_one.php' style='color:#10b981;font-size:18px;'>🧪 Testar API</a></p>";
    } else {
        echo "<h2 class='error'>❌ Erro de sintaxe!</h2>";
        echo "<pre>$check</pre>";
    }
    
} else {
    echo "<h2>O que faz:</h2>";
    echo "<ul>";
    echo "<li>Detecta perguntas como 'qual meu nome?', 'você sabe quem eu sou?'</li>";
    echo "<li>Responde com os dados do cliente logado</li>";
    echo "<li>Funciona para nome, email, telefone, endereço</li>";
    echo "</ul>";
    
    echo "<p style='margin-top:30px;'><a href='?fix=1' class='btn'>🔧 APLICAR FIX</a></p>";
}
