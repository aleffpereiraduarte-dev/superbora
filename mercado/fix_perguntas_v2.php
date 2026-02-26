<?php
/**
 * 🔧 FIX - Perguntas Pessoais (regex melhorado)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔧 Fix Perguntas Pessoais</h1>";
echo "<style>body{font-family:sans-serif;background:#0f172a;color:#e2e8f0;padding:40px;}pre{background:#1e293b;padding:16px;border-radius:8px;}.btn{background:#10b981;color:white;padding:16px 32px;border:none;border-radius:12px;cursor:pointer;font-size:18px;}.success{color:#10b981;}.error{color:#ef4444;}</style>";

$onePath = __DIR__ . '/one.php';
$conteudo = file_get_contents($onePath);

$jaTem = strpos($conteudo, '// ═══ PERGUNTAS PESSOAIS ═══') !== false;
echo "<p>Perguntas pessoais: " . ($jaTem ? '<span class="success">✅ Instalado</span>' : '⏳ Não instalado') . "</p>";

if (isset($_GET['fix'])) {
    
    // Backup
    copy($onePath, $onePath . '.bkp_perguntas_' . time());
    echo "<p class='success'>✅ Backup</p>";
    
    // Remover versão antiga se existir
    if ($jaTem) {
        $conteudo = preg_replace('/\/\/ ═══ PERGUNTAS PESSOAIS ═══.*?\/\/ ═══ FIM PERGUNTAS PESSOAIS ═══\s*/s', '', $conteudo);
        echo "<p>✅ Versão antiga removida</p>";
    }
    
    // Código melhorado
    $codigo = '
            // ═══ PERGUNTAS PESSOAIS ═══
            // Detecta variações: "qual meu nome", "voce sabe meu nome", "sabe quem eu sou", etc
            $msgLower = mb_strtolower($msg, \'UTF-8\');
            if (preg_match(\'/(qual|como).*(meu nome|me chamo)/i\', $msgLower) ||
                preg_match(\'/(sabe|conhece|lembra).*(meu nome|quem (eu )?sou|de mim)/i\', $msgLower) ||
                preg_match(\'/meu nome/i\', $msgLower) ||
                preg_match(\'/(quem sou eu|quem eu sou)/i\', $msgLower)) {
                
                $cliente = $this->carregarClienteCompleto();
                if ($cliente) {
                    $nome = trim($cliente[\'firstname\'] . \' \' . $cliente[\'lastname\']);
                    $respostas = [
                        "Claro que sei! Você é $nome! 😊",
                        "Sei sim! Você é o $nome! 💚",
                        "Como não vou saber? Você é $nome! 😄",
                        "Óbvio que lembro! $nome, né? 💚"
                    ];
                    $resp = $respostas[array_rand($respostas)];
                    $this->salvar(\'one\', $resp, [\'fonte\' => \'pergunta_pessoal\']);
                    return [\'success\' => true, \'response\' => $resp, \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
                }
            }
            
            // Perguntas sobre email/telefone
            if (preg_match(\'/(qual|sabe).*(meu email|meu telefone|meu celular|meu endereco|meu endereço)/i\', $msgLower)) {
                $cliente = $this->carregarClienteCompleto();
                if ($cliente) {
                    if (preg_match(\'/email/i\', $msgLower)) {
                        $resp = "Seu email é {$cliente[\'email\']}! 📧";
                    } elseif (preg_match(\'/(telefone|celular)/i\', $msgLower)) {
                        $resp = "Seu telefone é {$cliente[\'telephone\']}! 📱";
                    } elseif (preg_match(\'/(endereco|endereço)/i\', $msgLower) && !empty($cliente[\'enderecos\'])) {
                        $end = $cliente[\'enderecos\'][0];
                        $resp = "Seu endereço é {$end[\'address_1\']}, {$end[\'city\']}! 🏠";
                    } else {
                        $resp = "Me fala o que você quer saber - email, telefone ou endereço? 😊";
                    }
                    $this->salvar(\'one\', $resp, [\'fonte\' => \'pergunta_pessoal\']);
                    return [\'success\' => true, \'response\' => $resp, \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
                }
            }
            // ═══ FIM PERGUNTAS PESSOAIS ═══
            
';
    
    // Inserir antes do detector de intenção
    $marcador = '$intencaoDetectada = $this->detectarIntencaoUniversal($msg);';
    
    if (strpos($conteudo, $marcador) !== false) {
        $conteudo = str_replace($marcador, $codigo . $marcador, $conteudo);
        file_put_contents($onePath, $conteudo);
        
        $check = shell_exec("php -l $onePath 2>&1");
        if (strpos($check, 'No syntax errors') !== false) {
            echo "<h2 class='success'>✅ INSTALADO!</h2>";
            echo "<p><a href='teste_api_one.php?msg=voce+sabe+meu+nome' style='color:#10b981;'>🧪 Testar</a></p>";
            echo "<p><a href='one.php' style='color:#10b981;font-size:18px;'>💚 Ir para ONE</a></p>";
        } else {
            echo "<p class='error'>❌ Erro sintaxe</p>";
            echo "<pre>$check</pre>";
        }
    } else {
        echo "<p class='error'>❌ Marcador não encontrado</p>";
    }
    
} else {
    echo "<h2>Variações que vai reconhecer:</h2>";
    echo "<ul>";
    echo "<li>qual meu nome?</li>";
    echo "<li>voce sabe meu nome?</li>";
    echo "<li>sabe quem eu sou?</li>";
    echo "<li>quem sou eu?</li>";
    echo "<li>você me conhece?</li>";
    echo "<li>lembra de mim?</li>";
    echo "</ul>";
    echo "<p style='margin-top:30px;'><a href='?fix=1' class='btn'>🔧 INSTALAR</a></p>";
}
