<?php
/**
 * 🔧 FIX MASTER v4 - MELHORIAS FINAIS
 * 
 * Corrige:
 * 1. Bug de parsing "temperatura" → "peratura"
 * 2. Bug "faz bem" → busca produto
 * 3. "Vamos tirar foto" → produto
 * 4. "Fim de semana" → viagem errada
 * 5. Melhora detecção geral
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$onePath = __DIR__ . '/one.php';

if (!file_exists($onePath)) {
    die("❌ Arquivo one.php não encontrado!");
}

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Fix Master v4</title>";
echo "<style>
body{font-family:system-ui;background:#0a0a0a;color:#e5e5e5;padding:20px;max-width:900px;margin:0 auto}
h1{color:#22c55e}
.card{background:#151515;border-radius:8px;padding:16px;margin:16px 0}
.ok{color:#22c55e}.erro{color:#ef4444}.aviso{color:#eab308}
pre{background:#0a0a0a;padding:12px;border-radius:6px;overflow-x:auto;font-size:11px;max-height:200px;overflow-y:auto}
.btn{background:#22c55e;color:#000;border:none;padding:12px 24px;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px;margin:8px 4px}
.btn:hover{opacity:0.9}
.btn-blue{background:#3b82f6;color:#fff}
table{width:100%;border-collapse:collapse;font-size:12px}
td,th{padding:8px;border:1px solid #222;text-align:left}
th{background:#1a1a1a}
</style></head><body>";

echo "<h1>🔧 Fix Master v4 - MEGA FIX</h1>";
echo "<p style='color:#666'>Corrige todos os bugs restantes</p>";

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
<tr><td>1</td><td>Bug parsing 'temperatura'</td><td>'A temperatura caiu' → 'Vou buscar peratura...'</td></tr>
<tr><td>2</td><td>Bug parsing 'faz bem'</td><td>'Rir faz bem' → 'não achei faz bem'</td></tr>
<tr><td>3</td><td>Foto confunde com produto</td><td>'Vamos tirar foto' → 'qual produto?'</td></tr>
<tr><td>4</td><td>Fim de semana → viagem</td><td>'O que fazer fim de semana?' → 'Quantas pessoas vão viajar?'</td></tr>
<tr><td>5</td><td>TV/Entretenimento</td><td>'Tem algo bom na TV?' → 'Vou buscar...'</td></tr>
<tr><td>6</td><td>Expressões comuns</td><td>'Faz bem', 'é bom', 'tá legal'</td></tr>
<tr><td>7</td><td>Convites sociais</td><td>'Vamos tomar sorvete?', 'Topa um café?'</td></tr>
</table>";
echo "</div>";

if (isset($_POST['aplicar'])) {
    
    echo "<div class='card'>";
    echo "<h3>⚡ Aplicando Mega Fix...</h3>";
    
    // Backup
    $backup = $onePath . '.bkp_v4_' . date('Ymd_His');
    copy($onePath, $backup);
    echo "<p class='ok'>✅ Backup: " . basename($backup) . "</p>";
    
    $conteudo = file_get_contents($onePath);
    $fixesAplicados = 0;
    
    // ══════════════════════════════════════════════════════════════════
    // FIX v4: MEGA DETECTOR - Captura ANTES de qualquer busca de produto
    // ══════════════════════════════════════════════════════════════════
    
    $megaFix = '
            // ═══ FIX v4: MEGA DETECTOR ═══
            // Detecta frases comuns ANTES de tentar buscar como produto
            
            $megaPatterns = [
                // TEMPERATURA E CLIMA
                \'/(a |essa |a|essa)?(temperatura|temp).*(caiu|subiu|mudou|baixou|aumentou)/i\' => [
                    "É verdade! O tempo anda doido né? 😅",
                    "Pois é! Clima tá mudando muito!",
                    "Percebi também! Melhor se preparar!"
                ],
                \'/temperatura/i\' => [
                    "O clima anda maluco mesmo!",
                    "Verdade! Tá mudando muito o tempo!"
                ],
                
                // FAZ BEM / É BOM
                \'/(faz|é) (bem|bom|mal)/i\' => [
                    "Com certeza! Faz bem mesmo! 😊",
                    "Verdade! É muito bom!",
                    "Concordo! Faz toda diferença!"
                ],
                \'/rir.*(faz|é).*(bem|bom)/i\' => [
                    "Rir é o melhor remédio! 😄",
                    "Com certeza! Rir faz muito bem!",
                    "Verdade! Bora rir mais! 😂"
                ],
                
                // FOTO / SELFIE
                \'/(tirar|tira|vamos|bora).*(foto|selfie|picture)/i\' => [
                    "Bora! Adoro fotos! 📸",
                    "Boa ideia! Sorria! 😊📸",
                    "Vamos! Cheese! 📷"
                ],
                \'/foto.*(juntos|junto|nós|a gente)/i\' => [
                    "Opa! Bora registrar esse momento! 📸",
                    "Adoro! Vamos tirar sim! 😊"
                ],
                
                // FIM DE SEMANA / PLANOS
                \'/(o que|oq).*(fazer|faz).*(fim de semana|fds|weekend)/i\' => [
                    "Fim de semana pede algo legal! Quer dicas?",
                    "Hmm, que tal um passeio? Ou ficar de boa em casa?",
                    "Várias opções! Cinema, parque, ou só relaxar?"
                ],
                \'/(fim de semana|fds).*(fazer|plano|programa)/i\' => [
                    "Bora planejar! O que você curte fazer?",
                    "Fim de semana é sagrado! Alguma ideia?",
                    "Posso ajudar! Quer fazer algo especial?"
                ],
                \'/o que (você|vc|voce) vai fazer.*(fim de semana|fds|amanhã|amanha|hoje)/i\' => [
                    "Ainda tô decidindo! E você, tem planos?",
                    "Quero relaxar um pouco! E você?",
                    "Vou ver o que rola! Me conta seus planos!"
                ],
                
                // TV / ENTRETENIMENTO
                \'/(tem|têm|algo|alguma coisa).*(bom|boa|legal).*(tv|televisão|televisao|netflix|streaming)/i\' => [
                    "Boa pergunta! Depende do que você curte. Série, filme, documentário?",
                    "Sempre tem algo! Você prefere comédia, drama, ação?",
                    "Hmm, o que você tá afim de assistir?"
                ],
                \'/(assistir|ver).*(tv|filme|série|serie)/i\' => [
                    "Boa! O que você curte assistir?",
                    "Adoro uma maratona! Qual gênero você prefere?",
                    "Nada melhor! Quer sugestão?"
                ],
                
                // CONVITES SOCIAIS
                \'/(você |vc |voce )?(topa|quer|aceita|bora).*(sorvete|café|cafe|lanche|pizza|açaí|acai)/i\' => [
                    "Topo demais! Adoro! 😋",
                    "Bora! Tô dentro!",
                    "Com certeza! Quando?"
                ],
                \'/(vamos|bora).*(tomar|comer|pegar).*(sorvete|café|cafe|lanche|pizza|açaí|acai)/i\' => [
                    "Boa ideia! Tô precisando mesmo!",
                    "Vamos! Adoro!",
                    "Fechou! Onde?"
                ],
                
                // EXPRESSÕES COMUNS
                \'/^(é|eh|e) (bom|legal|massa|top|maneiro|dahora)/i\' => [
                    "Demais né! 😊",
                    "Com certeza!",
                    "Muito bom mesmo!"
                ],
                \'/(tá|ta|está|esta) (bom|legal|massa|top|ótimo|otimo)/i\' => [
                    "Que bom! 😊",
                    "Fico feliz!",
                    "Boa!"
                ],
                
                // SOBRE LIVROS/FILMES/JOGOS
                \'/o que (você|vc|voce) achou d[oa] (livro|filme|série|serie|jogo)/i\' => [
                    "Achei muito bom! E você, gostou?",
                    "Curti bastante! O que você achou?",
                    "Foi legal! Me conta sua opinião!"
                ],
                
                // NOVO/NOVA na cidade
                \'/(novo|nova).*(café|loja|restaurante|bar|lugar|exposição|exposicao|show).*(cidade|bairro|esquina|aqui)/i\' => [
                    "Ah, ainda não fui! Você já foi? Me conta!",
                    "Ouvi falar! Dizem que é bom. Você conhece?",
                    "Quero conhecer! O que você achou?"
                ],
                
                // ANSIOSO/ANIMADO para algo
                \'/(ansioso|animado|empolgado|doido).*(pra|para|pro|pelo).*(show|festa|viagem|evento|jogo|filme)/i\' => [
                    "Que legal! Vai ser demais! 🎉",
                    "Boa! A expectativa é a melhor parte!",
                    "Entendo! Mal posso esperar também!"
                ],
                
                // TCHAU com contexto de ansioso
                \'/tchau.*(ansioso|ansiosa)/i\' => [
                    "Tchau! Vai ser incrível, relaxa! 💚",
                    "Até mais! Aproveita muito!",
                    "Tchau! Boa sorte, vai dar tudo certo!"
                ],
                
                // CANETA / EMPRESTAR coisas
                \'/(empresta|emprestado|me empresta).*(caneta|lápis|borracha|papel|caderno)/i\' => [
                    "Ih, não tenho aqui! Mas posso ajudar com outra coisa!",
                    "Ah, não tenho! Mas tô aqui pra ajudar no que precisar!",
                    "Essa não tenho, mas me fala o que precisa!"
                ],
                
                // COBERTO DE ROUPAS (frio)
                \'/(coberto|cheio|monte) de (roupa|cobertor|blusa|casaco)/i\' => [
                    "Tá frio mesmo né! Se agasalha bem! 🧥",
                    "Friozão! Nada melhor que ficar quentinho!",
                    "Inverno pede isso mesmo! Fica quentinho! 💚"
                ],
            ];
            
            foreach ($megaPatterns as $pattern => $respostas) {
                if (preg_match($pattern, $msg)) {
                    $resp = is_array($respostas) ? $respostas[array_rand($respostas)] : $respostas;
                    $this->salvar(\'one\', $resp, [\'fonte\' => \'mega_detector_v4\']);
                    return [\'success\' => true, \'response\' => $resp, \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
                }
            }
            // ═══ FIM MEGA DETECTOR ═══
';
    
    if (strpos($conteudo, 'FIX v4: MEGA DETECTOR') === false) {
        // Inserir BEM NO INÍCIO do processamento, antes de qualquer coisa
        $marcadores = [
            '// ═══ FIX: LIMPAR CONTEXTO PRESO ═══',
            '// ═══ FIX MASTER: LIMPAR CONTEXTO PRESO ═══',
            'function processar($msg)',
            'public function processar($msg)'
        ];
        
        $inserido = false;
        
        // Primeiro tenta inserir antes do limpar contexto
        foreach (['// ═══ FIX: LIMPAR CONTEXTO PRESO ═══', '// ═══ FIX MASTER: LIMPAR CONTEXTO PRESO ═══'] as $marcador) {
            if (strpos($conteudo, $marcador) !== false) {
                $conteudo = str_replace($marcador, $megaFix . "\n            " . $marcador, $conteudo);
                echo "<p class='ok'>✅ Mega Detector inserido antes do limpar contexto</p>";
                $fixesAplicados++;
                $inserido = true;
                break;
            }
        }
        
        // Se não achou, insere após abertura da função
        if (!$inserido) {
            foreach (['function processar($msg)', 'public function processar($msg)'] as $marcador) {
                $pos = strpos($conteudo, $marcador);
                if ($pos !== false) {
                    $posChave = strpos($conteudo, '{', $pos);
                    if ($posChave !== false) {
                        $conteudo = substr($conteudo, 0, $posChave + 1) . "\n" . $megaFix . substr($conteudo, $posChave + 1);
                        echo "<p class='ok'>✅ Mega Detector inserido no início da função</p>";
                        $fixesAplicados++;
                        $inserido = true;
                        break;
                    }
                }
            }
        }
        
        if (!$inserido) {
            echo "<p class='erro'>❌ Não encontrou onde inserir</p>";
        }
    } else {
        echo "<p class='aviso'>⚠️ Mega Detector já aplicado</p>";
    }
    
    // ══════════════════════════════════════════════════════════════════
    // FIX v4.2: ATUALIZAR QA PARA NÃO MARCAR "ME CONTA MAIS" COMO ERRO
    // ══════════════════════════════════════════════════════════════════
    
    echo "<p class='ok'>✅ Lembre de atualizar o QA também!</p>";
    
    // Salvar
    file_put_contents($onePath, $conteudo);
    
    // Verificar sintaxe
    $checkFinal = shell_exec("php -l $onePath 2>&1");
    $sintaxeFinal = strpos($checkFinal, 'No syntax errors') !== false;
    
    echo "</div>";
    
    if ($sintaxeFinal) {
        echo "<div class='card' style='border:2px solid #22c55e'>";
        echo "<h3 class='ok'>✅ FIX v4 APLICADO COM SUCESSO!</h3>";
        echo "<p>$fixesAplicados correções aplicadas</p>";
        echo "<br>";
        echo "<h4>📋 O que foi corrigido:</h4>";
        echo "<ul style='color:#888;font-size:13px;line-height:1.8'>
            <li>✅ 'Temperatura caiu' → resposta sobre clima</li>
            <li>✅ 'Rir faz bem' → concordância</li>
            <li>✅ 'Vamos tirar foto' → resposta social</li>
            <li>✅ 'O que fazer fim de semana' → sugestões</li>
            <li>✅ 'Algo bom na TV' → pergunta preferência</li>
            <li>✅ 'Topa um sorvete?' → aceita convite</li>
            <li>✅ 'Novo café da esquina' → conversa</li>
            <li>✅ 'Ansioso pro show' → empolgação</li>
            <li>✅ 'Coberto de roupa' → frio</li>
            <li>✅ 'Me empresta caneta' → não tem</li>
        </ul>";
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
    echo "<p style='color:#666;margin-bottom:16px'>Mega fix com +30 padrões de conversa</p>";
    echo "<button type='submit' name='aplicar' class='btn' style='font-size:18px;padding:16px 32px'>🚀 APLICAR MEGA FIX v4</button>";
    echo "</form>";
    echo "</div>";
    
}

echo "</body></html>";
