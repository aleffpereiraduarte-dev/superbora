<?php
/**
 * 🔧 FIX v5 FINAL - Força ONE a saber o nome do cliente
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Fix v5 Final</title>";
echo "<style>body{font-family:system-ui;background:#0a0a0a;color:#e5e5e5;padding:20px;max-width:900px;margin:0 auto}h1{color:#22c55e}.card{background:#151515;border-radius:8px;padding:16px;margin:16px 0}.ok{color:#22c55e}.erro{color:#ef4444}pre{background:#0a0a0a;padding:10px;border-radius:6px;font-size:11px;overflow-x:auto}.btn{background:#22c55e;color:#000;border:none;padding:12px 24px;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px}</style></head><body>";

echo "<h1>🔧 Fix v5 Final - Nome do Cliente</h1>";

$onePath = __DIR__ . '/one.php';

if (!file_exists($onePath)) {
    die("<p class='erro'>❌ one.php não encontrado</p>");
}

$conteudo = file_get_contents($onePath);
echo "<p class='ok'>✅ one.php carregado (" . strlen($conteudo) . " bytes)</p>";

// Diagnóstico
echo "<div class='card'><h3>📋 Diagnóstico</h3>";

$temLoadPerfil = strpos($conteudo, 'loadPerfil') !== false;
$temGetNome = strpos($conteudo, 'getNome') !== false;
$temPerfilTable = strpos($conteudo, 'om_one_cliente_perfil') !== false;
$temCustomerId = strpos($conteudo, 'customer_id') !== false;

echo "<p>" . ($temLoadPerfil ? '✅' : '❌') . " loadPerfil()</p>";
echo "<p>" . ($temGetNome ? '✅' : '❌') . " getNome()</p>";
echo "<p>" . ($temPerfilTable ? '✅' : '❌') . " Referencia om_one_cliente_perfil</p>";
echo "<p>" . ($temCustomerId ? '✅' : '❌') . " Usa customer_id</p>";

// Mostrar função getNome atual
if (preg_match('/private\s+function\s+getNome\s*\([^)]*\)\s*\{[^}]+\}/s', $conteudo, $m)) {
    echo "<h4>Função getNome() atual:</h4>";
    echo "<pre>" . htmlspecialchars($m[0]) . "</pre>";
}

echo "</div>";

if (isset($_POST['aplicar'])) {
    
    echo "<div class='card'><h3>⚡ Aplicando Fix...</h3>";
    
    // Backup
    $backup = $onePath . '.bkp_v5final_' . date('His');
    copy($onePath, $backup);
    echo "<p class='ok'>✅ Backup: " . basename($backup) . "</p>";
    
    $alteracoes = 0;
    
    // 1. PROCURAR e SUBSTITUIR a pergunta sobre nome
    // Procurar padrões existentes de "sabe meu nome" ou "qual meu nome"
    
    $patternNome = '/\/\/.*?(sabe|qual).*?nome.*?\n.*?if.*?getNome.*?\{[^}]+\}[^}]*\}/s';
    
    // Código novo para responder sobre nome
    $codigoNome = '
            // ═══ RESPOSTA SOBRE NOME DO CLIENTE ═══
            if (preg_match(\'/(sabe|conhece|lembra).*(meu nome|como me chamo)/i\', $msg) || 
                preg_match(\'/(qual|como).*(meu nome|eu me chamo)/i\', $msg) ||
                preg_match(\'/voce sabe meu nome/i\', $msg) ||
                preg_match(\'/vc sabe meu nome/i\', $msg)) {
                
                $nomeCliente = null;
                
                // Tenta pegar do perfil
                if (!empty($this->perfil[\'nome\'])) {
                    $nomeCliente = $this->perfil[\'nome\'];
                }
                // Tenta pegar da sessão
                elseif (!empty($_SESSION[\'one_conversa\'][\'nome\'])) {
                    $nomeCliente = $_SESSION[\'one_conversa\'][\'nome\'];
                }
                // Tenta pegar do banco direto
                elseif ($this->customer_id && $this->pdo) {
                    try {
                        $stmtN = $this->pdo->prepare("SELECT firstname FROM oc_customer WHERE customer_id = ?");
                        $stmtN->execute([$this->customer_id]);
                        $nomeCliente = $stmtN->fetchColumn();
                    } catch (Exception $e) {}
                }
                
                if ($nomeCliente) {
                    $respostas = [
                        "Claro que sei! Você é $nomeCliente! 💚",
                        "Sei sim! $nomeCliente, né? 😊",
                        "Lógico! Você é o $nomeCliente!",
                        "Com certeza! $nomeCliente! 💚"
                    ];
                    $resp = $respostas[array_rand($respostas)];
                } else {
                    $resp = "Ainda não sei seu nome! Como posso te chamar?";
                }
                
                $this->salvar(\'one\', $resp, [\'fonte\' => \'nome_cliente\']);
                return [\'success\' => true, \'response\' => $resp, \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
            }
            // ═══ FIM RESPOSTA SOBRE NOME ═══
';
    
    // Verificar se já existe
    if (strpos($conteudo, '═══ RESPOSTA SOBRE NOME DO CLIENTE ═══') !== false) {
        echo "<p class='ok'>⚠️ Fix de nome já existe, substituindo...</p>";
        // Remove o antigo
        $conteudo = preg_replace('/\s*\/\/ ═══ RESPOSTA SOBRE NOME DO CLIENTE ═══.*?\/\/ ═══ FIM RESPOSTA SOBRE NOME ═══/s', '', $conteudo);
    }
    
    // Encontrar onde inserir - logo após o início da função processar
    $marcadores = [
        '// ═══ FIX v4: MEGA DETECTOR ═══',
        '// ═══ FIX v3: CONVERSA CASUAL EXPANDIDA ═══',
        '// ═══ FIX: CLIMA E TEMPO ═══',
        '// ═══ FIX: SAUDAÇÕES EXPANDIDAS ═══',
        '// ═══ FIX: AGRADECIMENTOS ═══',
        '// ═══ FIX: LIMPAR CONTEXTO PRESO ═══',
        'function processar($msg)',
        'public function processar($msg)'
    ];
    
    $inserido = false;
    foreach ($marcadores as $marcador) {
        if (strpos($conteudo, $marcador) !== false) {
            $conteudo = str_replace($marcador, $codigoNome . "\n            " . $marcador, $conteudo);
            echo "<p class='ok'>✅ Código inserido antes de: $marcador</p>";
            $alteracoes++;
            $inserido = true;
            break;
        }
    }
    
    // Se não encontrou marcador, insere após abertura do processar
    if (!$inserido) {
        $pos = strpos($conteudo, 'function processar');
        if ($pos !== false) {
            $posChave = strpos($conteudo, '{', $pos);
            if ($posChave !== false) {
                $conteudo = substr($conteudo, 0, $posChave + 1) . "\n" . $codigoNome . substr($conteudo, $posChave + 1);
                echo "<p class='ok'>✅ Código inserido no início do processar()</p>";
                $alteracoes++;
                $inserido = true;
            }
        }
    }
    
    if (!$inserido) {
        echo "<p class='erro'>❌ Não conseguiu inserir o código</p>";
    }
    
    // Salvar
    file_put_contents($onePath, $conteudo);
    
    // Verificar sintaxe
    $check = shell_exec("php -l $onePath 2>&1");
    $sintaxeOk = strpos($check, 'No syntax errors') !== false;
    
    echo "</div>";
    
    if ($sintaxeOk) {
        echo "<div class='card' style='border:2px solid #22c55e;text-align:center'>";
        echo "<h3 class='ok'>✅ FIX APLICADO!</h3>";
        echo "<p>$alteracoes alterações feitas</p>";
        echo "<br><p><b>Agora testa:</b></p>";
        echo "<p style='font-size:18px'>\"você sabe meu nome?\"</p>";
        echo "<br><p><a href='one.php' class='btn'>💬 Testar ONE</a></p>";
        echo "</div>";
    } else {
        echo "<div class='card' style='border:2px solid #ef4444'>";
        echo "<h3 class='erro'>❌ Erro de Sintaxe</h3>";
        echo "<pre>$check</pre>";
        echo "<p>Restaurando backup...</p>";
        copy($backup, $onePath);
        echo "<p class='ok'>✅ Restaurado</p>";
        echo "</div>";
    }
    
} else {
    echo "<div class='card' style='text-align:center'>";
    echo "<form method='post'>";
    echo "<p style='color:#888;margin-bottom:16px'>Vai fazer a ONE responder corretamente quando você perguntar o nome</p>";
    echo "<button type='submit' name='aplicar' class='btn'>🔧 APLICAR FIX</button>";
    echo "</form>";
    echo "</div>";
}

echo "</body></html>";
