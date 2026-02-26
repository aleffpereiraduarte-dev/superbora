<?php
/**
 * 🔧 FIX MASTER - ONE Quality Assurance
 * 
 * Corrige todos os problemas encontrados:
 * 1. Contexto preso ("banana") - Limpar estado
 * 2. Sentimentos não reconhecidos
 * 3. Conversa geral não funciona
 * 4. Mercado com falhas
 * 5. Perguntas sobre identidade
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔧 FIX MASTER - ONE</h1>";
echo "<style>
body{font-family:'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;padding:40px;max-width:1000px;margin:0 auto;}
.card{background:#1e293b;border-radius:12px;padding:24px;margin:20px 0;}
.success{color:#10b981;}.error{color:#ef4444;}.warning{color:#f59e0b;}
.btn{background:linear-gradient(135deg,#10b981,#059669);color:white;border:none;padding:16px 32px;border-radius:12px;cursor:pointer;font-size:18px;font-weight:600;}
pre{background:#0f172a;padding:16px;border-radius:8px;font-size:11px;overflow-x:auto;max-height:300px;}
table{width:100%;border-collapse:collapse;}
td,th{padding:10px;border:1px solid #334155;text-align:left;}
th{background:#334155;}
.fix-item{background:#0f172a;padding:12px;margin:8px 0;border-radius:8px;border-left:3px solid #10b981;}
</style>";

$onePath = __DIR__ . '/one.php';

// Verificar sintaxe atual
$check = shell_exec("php -l $onePath 2>&1");
$sintaxeOk = strpos($check, 'No syntax errors') !== false;

echo "<div class='card'>";
echo "<h2>📋 Diagnóstico</h2>";
echo "<p>Sintaxe atual: " . ($sintaxeOk ? '<span class="success">✅ OK</span>' : '<span class="error">❌ ERRO</span>') . "</p>";

if (!$sintaxeOk) {
    echo "<pre>$check</pre>";
    echo "<p class='error'>Corrija o erro de sintaxe antes de aplicar o fix!</p>";
    exit;
}
echo "</div>";

echo "<div class='card'>";
echo "<h2>🔧 Correções a Aplicar</h2>";

$fixes = [
    ['nome' => 'Limpar contexto preso', 'desc' => 'Remove estado de "escolha de banana" antes de processar'],
    ['nome' => 'Respostas para sentimentos', 'desc' => 'Reconhece "tô triste", "tô cansado", "tô ansioso", etc'],
    ['nome' => 'Conversa geral', 'desc' => 'Responde "que horas são?", "me conta piada", "tchau", etc'],
    ['nome' => 'Perguntas de identidade', 'desc' => 'Melhora "você é robô?", "quem te criou?", etc'],
    ['nome' => 'Mercado melhorado', 'desc' => 'Detecta melhor "tem feijão?", "quanto custa?"'],
];

echo "<table><tr><th>#</th><th>Fix</th><th>Descrição</th></tr>";
foreach ($fixes as $i => $fix) {
    echo "<tr><td>" . ($i+1) . "</td><td><strong>{$fix['nome']}</strong></td><td>{$fix['desc']}</td></tr>";
}
echo "</table>";
echo "</div>";

if (isset($_POST['aplicar'])) {
    
    echo "<div class='card'>";
    echo "<h2>⚡ Aplicando Fixes...</h2>";
    
    // Backup
    $backupPath = $onePath . '.bkp_fixmaster_' . date('Ymd_His');
    copy($onePath, $backupPath);
    echo "<p class='success'>✅ Backup: " . basename($backupPath) . "</p>";
    
    $conteudo = file_get_contents($onePath);
    $fixesAplicados = 0;
    
    // ═══════════════════════════════════════════════════════════════════════════
    // FIX 1: LIMPAR CONTEXTO PRESO
    // ═══════════════════════════════════════════════════════════════════════════
    
    $codigoLimparContexto = '
            // ═══ FIX MASTER: LIMPAR CONTEXTO PRESO ═══
            // Se não é resposta numérica ou confirmação, limpa contexto de escolha
            $msgLimpa = trim($msg);
            $ehEscolha = preg_match(\'/^[1-9]$/\', $msgLimpa) || 
                         preg_match(\'/^(sim|não|nao|ok|beleza|pode|quero|esse|este|essa|esta|primeiro|segundo|terceiro)$/i\', $msgLimpa);
            
            if (!$ehEscolha) {
                // Limpa contextos de escolha de produto
                unset($_SESSION[\'one_conversa\'][\'aguardando_escolha\']);
                unset($_SESSION[\'one_conversa\'][\'opcoes_produto\']);
                unset($_SESSION[\'one_conversa\'][\'ultimo_produto_buscado\']);
                unset($_SESSION[\'one_pack4_ultimo_produto\']);
            }
            // ═══ FIM LIMPAR CONTEXTO ═══
            
';
    
    // Inserir no início do processamento
    if (strpos($conteudo, 'FIX MASTER: LIMPAR CONTEXTO') === false) {
        $marcador = '// ═══ BUSCA APELIDO (INÍCIO) ═══';
        if (strpos($conteudo, $marcador) !== false) {
            $conteudo = str_replace($marcador, $codigoLimparContexto . $marcador, $conteudo);
            echo "<p class='success'>✅ Fix 1: Limpar contexto preso</p>";
            $fixesAplicados++;
        } else {
            // Tentar outro marcador
            $marcador2 = 'public function processar($msg)';
            $pos = strpos($conteudo, $marcador2);
            if ($pos !== false) {
                $posChave = strpos($conteudo, '{', $pos);
                $conteudo = substr($conteudo, 0, $posChave + 1) . "\n" . $codigoLimparContexto . substr($conteudo, $posChave + 1);
                echo "<p class='success'>✅ Fix 1: Limpar contexto preso (marcador alt)</p>";
                $fixesAplicados++;
            }
        }
    } else {
        echo "<p class='warning'>⚠️ Fix 1 já aplicado</p>";
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // FIX 2: RESPOSTAS PARA SENTIMENTOS
    // ═══════════════════════════════════════════════════════════════════════════
    
    $codigoSentimentos = '
            // ═══ FIX MASTER: SENTIMENTOS ═══
            $sentimentos = [
                \'triste\' => [
                    "Ai amiga, o que aconteceu? Tô aqui contigo.",
                    "Ei, isso dói... você não merece se sentir assim.",
                    "Dias difíceis passam. Você é mais forte do que pensa."
                ],
                \'cansad\' => [
                    "Descansa um pouco, você merece.",
                    "Cansaço pede uma pausa. Cuida de você.",
                    "Sei como é... o corpo pede descanso às vezes."
                ],
                \'ansios\' => [
                    "Respira fundo. Uma coisa de cada vez, tá?",
                    "Calma, vai dar certo. Você consegue.",
                    "Ansiedade é pesada, mas passa. Força."
                ],
                \'estressad\' => [
                    "Estresse é pesado mesmo. Respira fundo.",
                    "Para um pouco, respira. Isso vai passar.",
                    "Caramba, que barra. Tô aqui contigo."
                ],
                \'sozinho\' => [
                    "Você não tá sozinho. Tô aqui.",
                    "Solidão dói, eu sei. Mas tô aqui contigo.",
                    "Ei, pode contar comigo, viu?"
                ],
                \'feliz\' => [
                    "Que bom! Adoro te ver assim!",
                    "Isso é ótimo! Conta mais!",
                    "AEEEE! Essa energia boa contagia!"
                ],
                \'difícil\' => [
                    "Dias difíceis passam. Você é forte.",
                    "Força. Amanhã pode ser melhor.",
                    "Sei como é... mas você supera isso."
                ],
                \'desabafar\' => [
                    "Pode falar. Tô ouvindo.",
                    "Desabafa aqui, sem julgamento.",
                    "Fala, tô aqui pra ouvir você."
                ],
                \'entediad\' => [
                    "Tédio? Que tal uma receita nova?",
                    "Bora fazer algo? Posso sugerir ideias!",
                    "Entediado? Me conta o que você curte fazer."
                ]
            ];
            
            foreach ($sentimentos as $gatilho => $respostas) {
                if (stripos($msgLowerApelido, $gatilho) !== false) {
                    $resp = $respostas[array_rand($respostas)];
                    $this->salvar(\'one\', $resp, [\'fonte\' => \'sentimento\']);
                    return [\'success\' => true, \'response\' => $resp, \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
                }
            }
            // ═══ FIM SENTIMENTOS ═══
            
';
    
    if (strpos($conteudo, 'FIX MASTER: SENTIMENTOS') === false) {
        // Inserir após o sistema de apelido
        $marcador = '// ═══ FIM SISTEMA APELIDO ═══';
        if (strpos($conteudo, $marcador) !== false) {
            $conteudo = str_replace($marcador, $marcador . "\n" . $codigoSentimentos, $conteudo);
            echo "<p class='success'>✅ Fix 2: Respostas para sentimentos</p>";
            $fixesAplicados++;
        }
    } else {
        echo "<p class='warning'>⚠️ Fix 2 já aplicado</p>";
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // FIX 3: CONVERSA GERAL
    // ═══════════════════════════════════════════════════════════════════════════
    
    $codigoConversaGeral = '
            // ═══ FIX MASTER: CONVERSA GERAL ═══
            $conversaGeral = [
                \'que horas\' => "Agora são " . date("H:i") . "! ⏰",
                \'que dia\' => "Hoje é " . strftime("%A, %d de %B") . "! 📅",
                \'calor\' => ["Tá quente mesmo! Bebe água! 💧", "Calorão né? Hidrata-se!"],
                \'frio\' => ["Tá frio! Se agasalha! 🧥", "Friozinho bom pra um café!"],
                \'piada\' => [
                    "Por que o livro de matemática se suicidou? Porque tinha muitos problemas! 😄",
                    "O que o zero disse pro oito? Bonito cinto! 😂",
                    "Por que a galinha atravessou a rua? Pra provar que não era covarde! 🐔"
                ],
                \'tchau\' => ["Tchau! Volta sempre! 👋", "Até mais! Tô por aqui!", "Falou! Qualquer coisa me chama!"],
                \'até mais\' => ["Até! Conta comigo! 👋", "Até mais! Foi bom falar contigo!"],
                \'fazer\' => ["Quer pedir algo do mercado? Planejar uma viagem? Ou só bater papo?", "Posso te ajudar com compras, viagens, receitas... o que você preferir!"]
            ];
            
            foreach ($conversaGeral as $gatilho => $respostas) {
                if (stripos($msgLowerApelido, $gatilho) !== false) {
                    $resp = is_array($respostas) ? $respostas[array_rand($respostas)] : $respostas;
                    $this->salvar(\'one\', $resp, [\'fonte\' => \'conversa_geral\']);
                    return [\'success\' => true, \'response\' => $resp, \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
                }
            }
            // ═══ FIM CONVERSA GERAL ═══
            
';
    
    if (strpos($conteudo, 'FIX MASTER: CONVERSA GERAL') === false) {
        $marcador = '// ═══ FIM SENTIMENTOS ═══';
        if (strpos($conteudo, $marcador) !== false) {
            $conteudo = str_replace($marcador, $marcador . "\n" . $codigoConversaGeral, $conteudo);
            echo "<p class='success'>✅ Fix 3: Conversa geral</p>";
            $fixesAplicados++;
        }
    } else {
        echo "<p class='warning'>⚠️ Fix 3 já aplicado</p>";
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // FIX 4: PERGUNTAS DE IDENTIDADE
    // ═══════════════════════════════════════════════════════════════════════════
    
    $codigoIdentidade = '
            // ═══ FIX MASTER: IDENTIDADE ═══
            $identidade = [
                \'robô\' => ["Sou uma assistente, mas com muito carinho! 💚", "Sou a ONE, sua parceira digital!"],
                \'robo\' => ["Sou uma assistente, mas com muito carinho! 💚", "Sou a ONE, sua parceira digital!"],
                \'inteligência artificial\' => ["Sou sim! Mas tô aqui pra te ajudar de verdade.", "IA com coração! 💚"],
                \' ia\' => ["Sou a ONE! Uma assistente que tá aqui pra facilitar sua vida."],
                \'criou\' => ["Fui criada pela equipe OneMundo, com muito carinho!", "A galera do OneMundo me fez! 💚"],
                \'qual seu nome\' => ["Me chama de ONE! Prazer! 💚", "Sou a ONE, sua parceira!"],
                \'seu nome\' => ["ONE! Pode me chamar assim. 💚"]
            ];
            
            foreach ($identidade as $gatilho => $respostas) {
                if (stripos($msgLowerApelido, $gatilho) !== false) {
                    $resp = is_array($respostas) ? $respostas[array_rand($respostas)] : $respostas;
                    $this->salvar(\'one\', $resp, [\'fonte\' => \'identidade\']);
                    return [\'success\' => true, \'response\' => $resp, \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
                }
            }
            // ═══ FIM IDENTIDADE ═══
            
';
    
    if (strpos($conteudo, 'FIX MASTER: IDENTIDADE') === false) {
        $marcador = '// ═══ FIM CONVERSA GERAL ═══';
        if (strpos($conteudo, $marcador) !== false) {
            $conteudo = str_replace($marcador, $marcador . "\n" . $codigoIdentidade, $conteudo);
            echo "<p class='success'>✅ Fix 4: Perguntas de identidade</p>";
            $fixesAplicados++;
        }
    } else {
        echo "<p class='warning'>⚠️ Fix 4 já aplicado</p>";
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // FIX 5: MERCADO MELHORADO
    // ═══════════════════════════════════════════════════════════════════════════
    
    $codigoMercado = '
            // ═══ FIX MASTER: MERCADO MELHORADO ═══
            // Detecta perguntas sobre produtos do mercado
            if (preg_match(\'/(tem|têm|tem |quanto custa|qual o preço|preço d[oa])\s*([\wáéíóúãõç\s]+)/i\', $msg, $matchMercado)) {
                $produtoPergunta = trim($matchMercado[2]);
                if (strlen($produtoPergunta) > 2) {
                    $resp = "Vou buscar $produtoPergunta pra você! Me dá um segundo... 🔍";
                    // Redireciona para busca de mercado
                    $_SESSION[\'one_conversa\'][\'buscando_produto\'] = $produtoPergunta;
                    $this->salvar(\'one\', $resp, [\'fonte\' => \'mercado_busca\']);
                    return [\'success\' => true, \'response\' => $resp, \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
                }
            }
            
            // Detecta "preciso fazer compras" ou similar
            if (preg_match(\'/(fazer compras|ir ao mercado|lista de compras|supermercado)/i\', $msg)) {
                $resp = "Bora! Me fala o que você precisa que eu vou anotando! 🛒";
                $this->salvar(\'one\', $resp, [\'fonte\' => \'mercado_inicio\']);
                return [\'success\' => true, \'response\' => $resp, \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
            }
            // ═══ FIM MERCADO MELHORADO ═══
            
';
    
    if (strpos($conteudo, 'FIX MASTER: MERCADO MELHORADO') === false) {
        $marcador = '// ═══ FIM IDENTIDADE ═══';
        if (strpos($conteudo, $marcador) !== false) {
            $conteudo = str_replace($marcador, $marcador . "\n" . $codigoMercado, $conteudo);
            echo "<p class='success'>✅ Fix 5: Mercado melhorado</p>";
            $fixesAplicados++;
        }
    } else {
        echo "<p class='warning'>⚠️ Fix 5 já aplicado</p>";
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // SALVAR E VERIFICAR
    // ═══════════════════════════════════════════════════════════════════════════
    
    file_put_contents($onePath, $conteudo);
    
    $checkFinal = shell_exec("php -l $onePath 2>&1");
    $sintaxeFinal = strpos($checkFinal, 'No syntax errors') !== false;
    
    echo "</div>";
    
    if ($sintaxeFinal) {
        echo "<div class='card' style='border:2px solid #10b981;text-align:center;'>";
        echo "<h2 class='success'>✅ FIX MASTER APLICADO!</h2>";
        echo "<p>$fixesAplicados correções aplicadas com sucesso!</p>";
        echo "<p style='margin-top:20px;'>";
        echo "<a href='one_qa.html' class='btn' style='text-decoration:none;margin:8px;'>🤖 Rodar QA Novamente</a>";
        echo "<a href='one.php' class='btn' style='text-decoration:none;margin:8px;'>💚 Testar ONE</a>";
        echo "</p>";
        echo "</div>";
    } else {
        echo "<div class='card' style='border:2px solid #ef4444;'>";
        echo "<h2 class='error'>❌ Erro de Sintaxe!</h2>";
        echo "<pre>$checkFinal</pre>";
        echo "<p>Restaurando backup...</p>";
        copy($backupPath, $onePath);
        echo "<p class='success'>✅ Backup restaurado</p>";
        echo "</div>";
    }
    
} else {
    
    echo "<div class='card' style='text-align:center;'>";
    echo "<form method='post'>";
    echo "<p style='margin-bottom:20px;color:#64748b;'>Isso vai corrigir os problemas encontrados no QA</p>";
    echo "<button type='submit' name='aplicar' class='btn'>🔧 APLICAR FIX MASTER</button>";
    echo "</form>";
    echo "</div>";
    
}
