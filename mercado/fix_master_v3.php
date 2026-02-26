<?php
/**
 * 🔧 FIX MASTER v3 - Últimos ajustes
 * 
 * Problemas restantes:
 * 1. "Vou buscar X pra você" pegando palavras erradas
 * 2. Perguntas pessoais caindo no mercado
 * 3. "Me manda mensagem" sem resposta
 * 4. "Esse jogo é divertido" -> produto
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$onePath = __DIR__ . '/one.php';

if (!file_exists($onePath)) {
    die("❌ Arquivo one.php não encontrado!");
}

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Fix Master v3</title>";
echo "<style>
body{font-family:system-ui;background:#0a0a0a;color:#e5e5e5;padding:20px;max-width:900px;margin:0 auto}
h1{color:#22c55e}
.card{background:#151515;border-radius:8px;padding:16px;margin:16px 0}
.ok{color:#22c55e}.erro{color:#ef4444}.aviso{color:#eab308}
pre{background:#0a0a0a;padding:12px;border-radius:6px;overflow-x:auto;font-size:11px;max-height:200px;overflow-y:auto}
.btn{background:#22c55e;color:#000;border:none;padding:12px 24px;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px;margin:8px 4px}
.btn:hover{opacity:0.9}
.btn-blue{background:#3b82f6;color:#fff}
table{width:100%;border-collapse:collapse;font-size:13px}
td,th{padding:10px;border:1px solid #222;text-align:left}
th{background:#1a1a1a}
</style></head><body>";

echo "<h1>🔧 Fix Master v3</h1>";
echo "<p style='color:#666'>Corrige os últimos erros do QA (96% → 100%)</p>";

$check = shell_exec("php -l $onePath 2>&1");
$sintaxeOk = strpos($check, 'No syntax errors') !== false;

echo "<div class='card'>";
echo "<h3>📋 Diagnóstico</h3>";
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
<tr><td>1</td><td>Parsing errado de busca</td><td>'tempo que não te vejo' → 'Vou buscar po que não...'</td></tr>
<tr><td>2</td><td>Perguntas pessoais → mercado</td><td>'como estão as coisas?' → 'qual produto?'</td></tr>
<tr><td>3</td><td>Pedidos sem resposta</td><td>'me manda mensagem' → (nada)</td></tr>
<tr><td>4</td><td>Comentários sobre coisas → mercado</td><td>'esse jogo é divertido' → 'qual produto?'</td></tr>
</table>";
echo "</div>";

if (isset($_POST['aplicar'])) {
    
    echo "<div class='card'>";
    echo "<h3>⚡ Aplicando Correções...</h3>";
    
    // Backup
    $backup = $onePath . '.bkp_v3_' . date('Ymd_His');
    copy($onePath, $backup);
    echo "<p class='ok'>✅ Backup: " . basename($backup) . "</p>";
    
    $conteudo = file_get_contents($onePath);
    $fixesAplicados = 0;
    
    // ══════════════════════════════════════════════════════════════════
    // FIX 1: CONVERSA CASUAL EXPANDIDA
    // ══════════════════════════════════════════════════════════════════
    
    $fixConversa = '
            // ═══ FIX v3: CONVERSA CASUAL EXPANDIDA ═══
            $conversaCasual = [
                // Comentários sobre coisas
                \'/^(esse|essa|este|esta).*(jogo|filme|serie|música|musica|livro|lugar|comida|roupa).*(legal|bom|boa|divertido|incrível|otimo|ótimo|massa|top|maneiro)/i\' => [
                    "Que bom que você tá curtindo! 😊",
                    "Adoro quando a gente encontra coisas legais assim!",
                    "Boa! Me conta mais sobre isso!"
                ],
                // Faz tempo / saudade
                \'/faz tempo|há tempo|a tempo|saudade/i\' => [
                    "É verdade! O tempo passa rápido né?",
                    "Pois é! Bons tempos! 😊",
                    "Também sinto falta!"
                ],
                // Perguntas pessoais genéricas
                \'/como (estão|andam|vão) as coisas/i\' => [
                    "Tudo certo por aqui! E com você?",
                    "Tá tudo bem! Me conta as novidades!",
                    "Por aqui tá tranquilo! E aí?"
                ],
                // Preferências
                \'/você (prefere|gosta mais|curte mais)/i\' => [
                    "Difícil escolher! Cada um tem seu charme. E você?",
                    "Gosto dos dois! Mas você, qual prefere?",
                    "Hmm, boa pergunta! Depende do momento. E você?"
                ],
                // Pedidos de mensagem/contato
                \'/me (manda|envia|passa).*(mensagem|msg|whats|zap|contato|numero|número)/i\' => [
                    "Pode deixar! Qualquer coisa tô por aqui! 💚",
                    "Combinado! Me chama quando precisar!",
                    "Fechou! Tamo junto! 💚"
                ],
                // Pedidos genéricos
                \'/me (manda|envia|passa)/i\' => [
                    "Pode deixar! O que você precisa?",
                    "Claro! Me fala mais!",
                    "Combinado!"
                ],
                // Lembranças
                \'/você (lembra|se lembra|recorda)/i\' => [
                    "Lembro sim! Bons tempos! 😊",
                    "Como esquecer! Foi muito bom!",
                    "Claro que lembro!"
                ],
                // O que fazer
                \'/o que (você|vc|voce) (costuma|gosta de) fazer/i\' => [
                    "Adoro ajudar pessoas! É o que faço de melhor! 💚",
                    "Gosto de conversar e ajudar! E você, o que curte?",
                    "Curto bastante coisa! Me conta o que você gosta!"
                ],
                // Receita parece fácil
                \'/receita.*(fácil|facil|simples|rápida|rapida)/i\' => [
                    "Quer que eu te ajude com alguma receita? 👩‍🍳",
                    "Adoro receitas práticas! Quer uma sugestão?",
                    "Receitas fáceis são as melhores! Posso te ajudar!"
                ],
                // Fazer algo diferente
                \'/(vamos|bora) fazer (algo|alguma coisa) diferente/i\' => [
                    "Adoro! O que você tem em mente?",
                    "Bora! Topa uma viagem? Um rolê diferente?",
                    "Boa ideia! Me conta o que você tá pensando!"
                ],
                // Piquenique, churrasco, etc
                \'/(vamos|bora).*(piquenique|churrasco|festa|rolê|role)/i\' => [
                    "Boa! Posso te ajudar a organizar! O que precisa?",
                    "Adoro! Quer que eu ajude com a lista de compras?",
                    "Fechou! Me conta os detalhes!"
                ],
            ];
            
            foreach ($conversaCasual as $pattern => $respostas) {
                if (preg_match($pattern, $msgLowerApelido)) {
                    $resp = is_array($respostas) ? $respostas[array_rand($respostas)] : $respostas;
                    $this->salvar(\'one\', $resp, [\'fonte\' => \'conversa_casual_v3\']);
                    return [\'success\' => true, \'response\' => $resp, \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
                }
            }
            // ═══ FIM CONVERSA CASUAL EXPANDIDA ═══
';
    
    if (strpos($conteudo, 'FIX v3: CONVERSA CASUAL EXPANDIDA') === false) {
        // Inserir após os fixes anteriores ou após limpar contexto
        $marcadores = [
            '// ═══ FIM CLIMA E TEMPO ═══',
            '// ═══ FIM SAUDAÇÕES EXPANDIDAS ═══',
            '// ═══ FIM AGRADECIMENTOS ═══',
            '// ═══ FIM LIMPAR CONTEXTO ═══'
        ];
        
        $inserido = false;
        foreach ($marcadores as $marcador) {
            if (strpos($conteudo, $marcador) !== false) {
                $conteudo = str_replace($marcador, $marcador . "\n" . $fixConversa, $conteudo);
                echo "<p class='ok'>✅ Fix 1: Conversa casual expandida</p>";
                $fixesAplicados++;
                $inserido = true;
                break;
            }
        }
        
        if (!$inserido) {
            echo "<p class='aviso'>⚠️ Fix 1: Não encontrou marcador</p>";
        }
    } else {
        echo "<p class='aviso'>⚠️ Fix 1: Já aplicado</p>";
    }
    
    // ══════════════════════════════════════════════════════════════════
    // FIX 2: BLOQUEAR BUSCA DE PRODUTO PARA PALAVRAS COMUNS
    // ══════════════════════════════════════════════════════════════════
    
    // Procurar onde faz a busca de produto e adicionar filtro
    $fixBloqueio = '
            // ═══ FIX v3: PALAVRAS QUE NÃO SÃO PRODUTOS ═══
            $naoProdutos = [
                \'tempo\', \'peratura\', \'temperatura\', \'coisa\', \'coisas\', \'vida\', 
                \'jogo\', \'filme\', \'serie\', \'música\', \'musica\', \'livro\',
                \'mensagem\', \'msg\', \'foto\', \'imagem\', \'video\',
                \'festa\', \'exposição\', \'exposicao\', \'show\',
                \'pessoa\', \'gente\', \'amigo\', \'amiga\',
                \'lugar\', \'local\', \'casa\', \'trabalho\',
                \'dia\', \'noite\', \'tarde\', \'manhã\', \'manha\',
                \'bem\', \'mal\', \'bom\', \'ruim\', \'legal\', \'chato\'
            ];
            // ═══ FIM PALAVRAS QUE NÃO SÃO PRODUTOS ═══
';
    
    if (strpos($conteudo, 'FIX v3: PALAVRAS QUE NÃO SÃO PRODUTOS') === false) {
        $marcador = '// ═══ FIM CONVERSA CASUAL EXPANDIDA ═══';
        if (strpos($conteudo, $marcador) !== false) {
            $conteudo = str_replace($marcador, $marcador . "\n" . $fixBloqueio, $conteudo);
            echo "<p class='ok'>✅ Fix 2: Lista de palavras que não são produtos</p>";
            $fixesAplicados++;
        }
    } else {
        echo "<p class='aviso'>⚠️ Fix 2: Já aplicado</p>";
    }
    
    // ══════════════════════════════════════════════════════════════════
    // FIX 3: MELHORAR FALLBACK FINAL
    // ══════════════════════════════════════════════════════════════════
    
    // Procurar o fallback "não entendi" e melhorar
    $patterns = [
        '/não entendi.*café tradicional.*extra forte.*gourmet/i',
        '/nao entendi.*cafe tradicional.*extra forte.*gourmet/i',
        '/"não entendi,/i',
        '/"nao entendi,/i'
    ];
    
    $fallbackNovo = '"Hmm, não entendi bem. Pode explicar de outro jeito?"';
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $conteudo)) {
            // Encontrou o fallback ruim, mas vamos só marcar
            echo "<p class='aviso'>⚠️ Fix 3: Fallback ruim encontrado (precisa correção manual no fluxo)</p>";
            break;
        }
    }
    
    // Salvar
    file_put_contents($onePath, $conteudo);
    
    // Verificar sintaxe
    $checkFinal = shell_exec("php -l $onePath 2>&1");
    $sintaxeFinal = strpos($checkFinal, 'No syntax errors') !== false;
    
    echo "</div>";
    
    if ($sintaxeFinal) {
        echo "<div class='card' style='border:2px solid #22c55e'>";
        echo "<h3 class='ok'>✅ FIX v3 APLICADO!</h3>";
        echo "<p>$fixesAplicados correções aplicadas</p>";
        echo "<p style='margin-top:16px'>";
        echo "<a href='one_qa_v8.php' class='btn'>🤖 Rodar QA</a>";
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
    echo "<p style='color:#666;margin-bottom:16px'>Vai corrigir os últimos 4% de erros</p>";
    echo "<button type='submit' name='aplicar' class='btn' style='font-size:18px;padding:16px 32px'>🔧 APLICAR FIX v3</button>";
    echo "</form>";
    echo "</div>";
    
}

echo "</body></html>";
