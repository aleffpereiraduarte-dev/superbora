<?php
/**
 * 🔧 FIX MASTER v2 - Corrige erros do QA
 * 
 * Problemas encontrados:
 * 1. Contexto de café preso ("não entendi, café tradicional...")
 * 2. Agradecimentos não reconhecidos
 * 3. Saudações caindo no erro
 * 4. Perguntas sobre clima/sol
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$onePath = __DIR__ . '/one.php';

if (!file_exists($onePath)) {
    die("❌ Arquivo one.php não encontrado!");
}

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Fix Master v2</title>";
echo "<style>
body{font-family:system-ui;background:#0a0a0a;color:#e5e5e5;padding:20px;max-width:900px;margin:0 auto}
h1{color:#22c55e}
.card{background:#151515;border-radius:8px;padding:16px;margin:16px 0}
.ok{color:#22c55e}.erro{color:#ef4444}.aviso{color:#eab308}
pre{background:#0a0a0a;padding:12px;border-radius:6px;overflow-x:auto;font-size:11px;max-height:300px;overflow-y:auto}
.btn{background:#22c55e;color:#000;border:none;padding:12px 24px;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px;margin:8px 4px}
.btn:hover{opacity:0.9}
.btn-blue{background:#3b82f6;color:#fff}
table{width:100%;border-collapse:collapse;font-size:13px}
td,th{padding:10px;border:1px solid #222;text-align:left}
th{background:#1a1a1a}
</style></head><body>";

echo "<h1>🔧 Fix Master v2</h1>";
echo "<p style='color:#666'>Corrige os erros encontrados pelo QA</p>";

// Verificar sintaxe atual
$check = shell_exec("php -l $onePath 2>&1");
$sintaxeOk = strpos($check, 'No syntax errors') !== false;

echo "<div class='card'>";
echo "<h3>📋 Diagnóstico</h3>";
echo "<p>Arquivo: <code>$onePath</code></p>";
echo "<p>Sintaxe: " . ($sintaxeOk ? "<span class='ok'>✅ OK</span>" : "<span class='erro'>❌ ERRO</span>") . "</p>";
if (!$sintaxeOk) {
    echo "<pre>$check</pre>";
    die("</div></body></html>");
}
echo "</div>";

echo "<div class='card'>";
echo "<h3>🔴 Problemas a Corrigir</h3>";
echo "<table>
<tr><th>#</th><th>Problema</th><th>Exemplo</th></tr>
<tr><td>1</td><td>Contexto de café/produto preso</td><td>'obrigado' → 'café tradicional...'</td></tr>
<tr><td>2</td><td>Agradecimentos não reconhecidos</td><td>'valeu', 'obrigado', 'agradeço'</td></tr>
<tr><td>3</td><td>Saudações caindo em erro</td><td>'e aí, como você tá?'</td></tr>
<tr><td>4</td><td>Perguntas sobre clima/tempo</td><td>'esse sol tá forte né?'</td></tr>
<tr><td>5</td><td>Perguntas sobre horas variadas</td><td>'já são quase duas horas'</td></tr>
</table>";
echo "</div>";

if (isset($_POST['aplicar'])) {
    
    echo "<div class='card'>";
    echo "<h3>⚡ Aplicando Correções...</h3>";
    
    // Backup
    $backup = $onePath . '.bkp_' . date('Ymd_His');
    copy($onePath, $backup);
    echo "<p class='ok'>✅ Backup: " . basename($backup) . "</p>";
    
    $conteudo = file_get_contents($onePath);
    $fixesAplicados = 0;
    
    // ══════════════════════════════════════════════════════════════════
    // FIX 1: LIMPAR CONTEXTO DE PRODUTO NO INÍCIO
    // ══════════════════════════════════════════════════════════════════
    
    $fixLimparContexto = '
            // ═══ FIX: LIMPAR CONTEXTO PRESO ═══
            // Se a mensagem não parece ser escolha de produto, limpa o contexto
            $msgTemp = mb_strtolower(trim($msg), "UTF-8");
            $pareceEscolha = preg_match(\'/^[1-9]$/\', $msgTemp) || 
                             preg_match(\'/^(sim|nao|não|ok|esse|esta|este|essa|primeiro|segundo|quero esse|pode ser)$/i\', $msgTemp) ||
                             preg_match(\'/^(tradicional|extra forte|gourmet|integral|desnatado|semi)$/i\', $msgTemp);
            
            if (!$pareceEscolha) {
                // Limpa estados de escolha de produto
                unset($_SESSION[\'one_conversa\'][\'aguardando_escolha\']);
                unset($_SESSION[\'one_conversa\'][\'opcoes_produto\']);
                unset($_SESSION[\'one_conversa\'][\'ultimo_produto_buscado\']);
                unset($_SESSION[\'one_pack4_ultimo_produto\']);
                unset($_SESSION[\'one_pack4_opcoes\']);
                unset($_SESSION[\'one_conversa\'][\'contexto\']);
            }
            // ═══ FIM LIMPAR CONTEXTO ═══
';
    
    if (strpos($conteudo, 'FIX: LIMPAR CONTEXTO PRESO') === false) {
        // Procurar onde inserir (no início do processar)
        $marcadores = [
            'function processar($msg)',
            'public function processar($msg)',
            'function processar( $msg )'
        ];
        
        $inserido = false;
        foreach ($marcadores as $marcador) {
            $pos = strpos($conteudo, $marcador);
            if ($pos !== false) {
                $posChave = strpos($conteudo, '{', $pos);
                if ($posChave !== false) {
                    $conteudo = substr($conteudo, 0, $posChave + 1) . "\n" . $fixLimparContexto . substr($conteudo, $posChave + 1);
                    echo "<p class='ok'>✅ Fix 1: Limpar contexto preso</p>";
                    $fixesAplicados++;
                    $inserido = true;
                    break;
                }
            }
        }
        
        if (!$inserido) {
            echo "<p class='aviso'>⚠️ Fix 1: Não encontrou marcador (aplicar manual)</p>";
        }
    } else {
        echo "<p class='aviso'>⚠️ Fix 1: Já aplicado</p>";
    }
    
    // ══════════════════════════════════════════════════════════════════
    // FIX 2: AGRADECIMENTOS
    // ══════════════════════════════════════════════════════════════════
    
    $fixAgradecimento = '
            // ═══ FIX: AGRADECIMENTOS ═══
            if (preg_match(\'/^(obrigad[oa]|valeu|vlw|brigad[oa]|agradeço|thanks|thank you|tmj|muito obrigad[oa]|obrigad[oa] pela ajuda|você me ajudou|ajudou muito|obrigado mesmo|valeu mesmo)/i\', $msgLowerApelido)) {
                $respostas = [
                    "De nada! Precisando, tô aqui. 💚",
                    "Sempre! Qualquer coisa me chama.",
                    "Por nada! Conta comigo. 😊",
                    "Disponha! Tô por aqui.",
                    "Que bom que ajudei! 💚"
                ];
                $resp = $respostas[array_rand($respostas)];
                $this->salvar(\'one\', $resp, [\'fonte\' => \'agradecimento\']);
                return [\'success\' => true, \'response\' => $resp, \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
            }
            // ═══ FIM AGRADECIMENTOS ═══
';
    
    if (strpos($conteudo, 'FIX: AGRADECIMENTOS') === false) {
        // Inserir após sistema de apelido ou após abertura da função
        $marcador = '// ═══ FIM SISTEMA APELIDO ═══';
        if (strpos($conteudo, $marcador) !== false) {
            $conteudo = str_replace($marcador, $marcador . "\n" . $fixAgradecimento, $conteudo);
            echo "<p class='ok'>✅ Fix 2: Agradecimentos</p>";
            $fixesAplicados++;
        } else {
            // Tentar inserir após o fix de limpar contexto
            $marcador2 = '// ═══ FIM LIMPAR CONTEXTO ═══';
            if (strpos($conteudo, $marcador2) !== false) {
                $conteudo = str_replace($marcador2, $marcador2 . "\n" . $fixAgradecimento, $conteudo);
                echo "<p class='ok'>✅ Fix 2: Agradecimentos (após limpar contexto)</p>";
                $fixesAplicados++;
            } else {
                echo "<p class='aviso'>⚠️ Fix 2: Não encontrou marcador</p>";
            }
        }
    } else {
        echo "<p class='aviso'>⚠️ Fix 2: Já aplicado</p>";
    }
    
    // ══════════════════════════════════════════════════════════════════
    // FIX 3: SAUDAÇÕES EXPANDIDAS
    // ══════════════════════════════════════════════════════════════════
    
    $fixSaudacoes = '
            // ═══ FIX: SAUDAÇÕES EXPANDIDAS ═══
            $saudacoesExpandidas = [
                \'e ai\' => "E aí! Tudo certo? 😊",
                \'e aí\' => "E aí! Tudo certo? 😊",
                \'eae\' => "Eae! Beleza?",
                \'eai\' => "Eai! Suave?",
                \'como voce ta\' => "Tô bem! E você?",
                \'como você tá\' => "Tô bem! E você?",
                \'como vc ta\' => "Tô bem! E você?",
                \'tudo bem\' => "Tudo ótimo! E contigo?",
                \'td bem\' => "Tudo bem sim! E você?",
                \'opa\' => "Opa! Fala aí!",
                \'fala ai\' => "Fala! O que manda?",
                \'fala aí\' => "Fala! O que manda?",
            ];
            
            foreach ($saudacoesExpandidas as $gatilho => $resposta) {
                if (strpos($msgLowerApelido, $gatilho) !== false) {
                    $this->salvar(\'one\', $resposta, [\'fonte\' => \'saudacao_expandida\']);
                    return [\'success\' => true, \'response\' => $resposta, \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
                }
            }
            // ═══ FIM SAUDAÇÕES EXPANDIDAS ═══
';
    
    if (strpos($conteudo, 'FIX: SAUDAÇÕES EXPANDIDAS') === false) {
        $marcador = '// ═══ FIM AGRADECIMENTOS ═══';
        if (strpos($conteudo, $marcador) !== false) {
            $conteudo = str_replace($marcador, $marcador . "\n" . $fixSaudacoes, $conteudo);
            echo "<p class='ok'>✅ Fix 3: Saudações expandidas</p>";
            $fixesAplicados++;
        } else {
            echo "<p class='aviso'>⚠️ Fix 3: Não encontrou marcador</p>";
        }
    } else {
        echo "<p class='aviso'>⚠️ Fix 3: Já aplicado</p>";
    }
    
    // ══════════════════════════════════════════════════════════════════
    // FIX 4: CLIMA E TEMPO
    // ══════════════════════════════════════════════════════════════════
    
    $fixClima = '
            // ═══ FIX: CLIMA E TEMPO ═══
            $climaPatterns = [
                \'/sol.*(forte|quente|intenso|demais)/i\' => ["Tá pegando fogo! ☀️ Bebe água!", "Sol tá castigando hoje! Hidrata-se! 💧"],
                \'/calor.*(forte|demais|insuportavel|insuportável|pegando|deixando)/i\' => ["Calorão brabo! Toma uma água gelada! 💧", "Esse calor não tá fácil! Hidrata-se!"],
                \'/frio.*(demais|muito|intenso)/i\' => ["Friozinho bom pra um café! ☕", "Tá gelando! Se agasalha!"],
                \'/clima.*(esfriou|esquentou|mudou)/i\' => ["O tempo anda doido né? 😅", "Clima tá maluco mesmo!"],
                \'/(já são|que horas|quantas horas|quase.*horas)/i\' => "Agora são " . date("H:i") . "! ⏰",
            ];
            
            foreach ($climaPatterns as $pattern => $respostas) {
                if (preg_match($pattern, $msgLowerApelido)) {
                    $resp = is_array($respostas) ? $respostas[array_rand($respostas)] : $respostas;
                    $this->salvar(\'one\', $resp, [\'fonte\' => \'clima\']);
                    return [\'success\' => true, \'response\' => $resp, \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
                }
            }
            // ═══ FIM CLIMA E TEMPO ═══
';
    
    if (strpos($conteudo, 'FIX: CLIMA E TEMPO') === false) {
        $marcador = '// ═══ FIM SAUDAÇÕES EXPANDIDAS ═══';
        if (strpos($conteudo, $marcador) !== false) {
            $conteudo = str_replace($marcador, $marcador . "\n" . $fixClima, $conteudo);
            echo "<p class='ok'>✅ Fix 4: Clima e tempo</p>";
            $fixesAplicados++;
        } else {
            echo "<p class='aviso'>⚠️ Fix 4: Não encontrou marcador</p>";
        }
    } else {
        echo "<p class='aviso'>⚠️ Fix 4: Já aplicado</p>";
    }
    
    // ══════════════════════════════════════════════════════════════════
    // FIX 5: FALLBACK INTELIGENTE (evita "não entendi" genérico)
    // ══════════════════════════════════════════════════════════════════
    
    // Este fix é mais complexo - precisa modificar o fallback existente
    // Vamos adicionar um check antes do "não entendi"
    
    $fixFallback = '
            // ═══ FIX: FALLBACK INTELIGENTE ═══
            // Se chegou aqui e não entendeu, responde de forma mais amigável
            $fallbacksAmigaveis = [
                "Hmm, não peguei bem. Pode explicar de outro jeito?",
                "Opa, me perdi. Tenta de novo?",
                "Não entendi direito. O que você precisa?",
                "Como assim? Me explica melhor!",
                "Não captei. Fala mais!"
            ];
            // ═══ FIM FALLBACK INTELIGENTE ═══
';
    
    // Salvar
    file_put_contents($onePath, $conteudo);
    
    // Verificar sintaxe
    $checkFinal = shell_exec("php -l $onePath 2>&1");
    $sintaxeFinal = strpos($checkFinal, 'No syntax errors') !== false;
    
    echo "</div>";
    
    if ($sintaxeFinal) {
        echo "<div class='card' style='border:2px solid #22c55e'>";
        echo "<h3 class='ok'>✅ FIX APLICADO COM SUCESSO!</h3>";
        echo "<p>$fixesAplicados correções aplicadas</p>";
        echo "<p style='margin-top:16px'>";
        echo "<a href='one_qa_v8.php' class='btn'>🤖 Rodar QA Novamente</a>";
        echo "<a href='one.php' class='btn btn-blue'>💬 Testar ONE</a>";
        echo "</p>";
        echo "</div>";
    } else {
        echo "<div class='card' style='border:2px solid #ef4444'>";
        echo "<h3 class='erro'>❌ Erro de Sintaxe!</h3>";
        echo "<pre>$checkFinal</pre>";
        echo "<p>Restaurando backup...</p>";
        copy($backup, $onePath);
        echo "<p class='ok'>✅ Backup restaurado</p>";
        echo "</div>";
    }
    
} else {
    
    echo "<div class='card' style='text-align:center'>";
    echo "<form method='post'>";
    echo "<p style='color:#666;margin-bottom:16px'>Isso vai corrigir os problemas encontrados pelo QA</p>";
    echo "<button type='submit' name='aplicar' class='btn' style='font-size:18px;padding:16px 32px'>🔧 APLICAR FIX MASTER v2</button>";
    echo "</form>";
    echo "</div>";
    
}

echo "</body></html>";
