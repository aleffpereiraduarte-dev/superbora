<?php
/**
 * 🔧 FASE 1.1 - INTEGRAR DETECTOR (CORRIGIDO)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');

echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>🔧 Integrar Detector</title>
    <style>
        body { font-family: "Segoe UI", sans-serif; background: #0f172a; color: #e2e8f0; padding: 40px; }
        .container { max-width: 800px; margin: 0 auto; }
        h1 { color: #10b981; }
        .card { background: #1e293b; border-radius: 12px; padding: 24px; margin: 20px 0; }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        pre { background: #0f172a; padding: 16px; border-radius: 8px; overflow-x: auto; font-size: 13px; }
        .btn { background: #10b981; color: white; border: none; padding: 14px 28px; border-radius: 8px; cursor: pointer; font-size: 16px; }
    </style>
</head>
<body>
<div class="container">';

echo '<h1>🔧 Integrar Detector de Intenções</h1>';

$onePath = __DIR__ . '/one.php';

if (!file_exists($onePath)) {
    die('<p class="error">❌ one.php não encontrado!</p>');
}

$conteudo = file_get_contents($onePath);

// Verificar se já está integrado
$jaIntegrado = strpos($conteudo, '// 🎯 ONE UNIVERSAL - DETECTOR DE INTENÇÃO') !== false;

echo '<div class="card">';
echo '<h2>📋 Status</h2>';
echo '<p>detectarIntencao(): ' . (strpos($conteudo, 'function detectarIntencao') !== false ? '<span class="success">✅ Existe</span>' : '<span class="error">❌ Não existe</span>') . '</p>';
echo '<p>Integrado no fluxo: ' . ($jaIntegrado ? '<span class="success">✅ Sim</span>' : '<span class="error">❌ Não</span>') . '</p>';
echo '</div>';

if (isset($_POST['aplicar'])) {
    
    if ($jaIntegrado) {
        echo '<div class="card"><p class="success">✅ Já está integrado!</p></div>';
        echo '</div></body></html>';
        exit;
    }
    
    // Backup
    $backup = $onePath . '.backup_detector_' . date('Y-m-d_H-i-s');
    file_put_contents($backup, $conteudo);
    
    echo '<div class="card">';
    echo '<h2>⚙️ Aplicando...</h2>';
    echo '<pre>';
    echo "✅ Backup: $backup\n\n";
    
    // Código a inserir - logo após correção de palavras
    $codigoIntencao = '
            // ═══════════════════════════════════════════════════════════════════════════
            // 🎯 ONE UNIVERSAL - DETECTOR DE INTENÇÃO
            // ═══════════════════════════════════════════════════════════════════════════
            
            $intencaoDetectada = $this->detectarIntencao($msg);
            $intencao = $intencaoDetectada[\'intencao\'];
            $entidades = $intencaoDetectada[\'entidades\'] ?? [];
            
            // VIAGEM - Se detectou destino
            if ($intencao === \'viagem\') {
                $destino = $entidades[\'destino\'] ?? null;
                $cliente = $this->carregarClienteCompleto();
                $nome = $cliente ? \' \' . $cliente[\'firstname\'] : \'\';
                
                $this->salvarContexto(\'viagem\', $destino ? \'destino_informado\' : \'aguardando_destino\', [\'destino\' => $destino]);
                
                if ($destino) {
                    $respostas = [
                        "Opa$nome! $destino é demais! 🌴 Quando você quer ir? E vai ser só você ou tem mais gente?",
                        "Que legal$nome, $destino! ✈️ Me conta: quando você quer viajar? E quantas pessoas vão?",
                        "$destino! Ótima escolha! 🎉 Pra eu achar as melhores opções: qual a data e quantos viajantes?"
                    ];
                } else {
                    $respostas = [
                        "Viajar é tudo de bom! ✈️ Pra onde você quer ir?",
                        "Oba, viagem! 🌴 Me conta o destino que eu busco as melhores opções!",
                        "Adoro ajudar com viagens! ✈️ Qual o destino dos sonhos?"
                    ];
                }
                
                $resp = $respostas[array_rand($respostas)];
                $this->salvar(\'one\', $resp, [\'fonte\' => \'intencao_viagem\']);
                return [\'success\' => true, \'response\' => $resp, \'intencao\' => \'viagem\', \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
            }
            
            // CORRIDA
            if ($intencao === \'corrida\') {
                $destino = $entidades[\'destino\'] ?? null;
                $this->salvarContexto(\'corrida\', \'iniciando\', [\'destino\' => $destino]);
                
                $resp = $destino 
                    ? "Beleza! Vou agendar uma corrida pro $destino! 🚗 Pra quando você precisa?"
                    : "Opa! Vou te ajudar com a corrida! 🚗 Pra onde você quer ir e quando?";
                
                $this->salvar(\'one\', $resp, [\'fonte\' => \'intencao_corrida\']);
                return [\'success\' => true, \'response\' => $resp, \'intencao\' => \'corrida\', \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
            }
            
            // ECOMMERCE
            if ($intencao === \'ecommerce\' && !empty($entidades[\'produto\'])) {
                $produto = $entidades[\'produto\'];
                $this->salvarContexto(\'ecommerce\', \'buscando\', [\'produto\' => $produto]);
                
                $resp = "Boa! Deixa eu procurar $produto pra você! 🛍️ Tem preferência de marca ou faixa de preço?";
                $this->salvar(\'one\', $resp, [\'fonte\' => \'intencao_ecommerce\']);
                return [\'success\' => true, \'response\' => $resp, \'intencao\' => \'ecommerce\', \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
            }
            
            // ═══════════════════════════════════════════════════════════════════════════
            // FIM DETECTOR - Continua fluxo normal
            // ═══════════════════════════════════════════════════════════════════════════

';

    // Procurar o marcador certo - após a correção de palavras
    // Padrão: "// ═══ PACK 6.7 - ADICIONAR PRODUTO QUANDO MENCIONA ═══"
    $marcador = '// ═══ PACK 6.7 - ADICIONAR PRODUTO QUANDO MENCIONA ═══';
    
    $inserido = false;
    
    if (strpos($conteudo, $marcador) !== false) {
        $conteudo = str_replace($marcador, $codigoIntencao . "\n            " . $marcador, $conteudo);
        echo "✅ Código inserido antes do PACK 6.7\n";
        $inserido = true;
    } else {
        echo "⚠️ Marcador PACK 6.7 não encontrado, tentando alternativa...\n";
        
        // Alternativa: inserir após o bloco de correção de palavras
        // Procura por: $msg = $msgCorrigida; seguido de $msgLower
        $padrao = '/(\$msg = \$msgCorrigida;\s*\$msgLower = mb_strtolower\(\$msg.*?\);\s*\})/s';
        
        if (preg_match($padrao, $conteudo, $match)) {
            $original = $match[0];
            $conteudo = str_replace($original, $original . "\n" . $codigoIntencao, $conteudo);
            echo "✅ Código inserido após bloco de correção de palavras\n";
            $inserido = true;
        } else {
            // Última tentativa: procurar linha específica
            $linhaEspecifica = '$msgTrim = trim($msgLower);';
            if (strpos($conteudo, $linhaEspecifica) !== false) {
                $conteudo = str_replace($linhaEspecifica, $codigoIntencao . "\n            " . $linhaEspecifica, $conteudo);
                echo "✅ Código inserido antes de \$msgTrim\n";
                $inserido = true;
            }
        }
    }
    
    if ($inserido) {
        file_put_contents($onePath, $conteudo);
        echo "\n✅ one.php atualizado!\n";
    } else {
        echo "\n❌ Não conseguiu inserir automaticamente\n";
        echo "Você pode inserir manualmente após a linha 8300 do processar()\n";
    }
    
    echo '</pre></div>';
    
    if ($inserido) {
        echo '<div class="card" style="border-color:#10b981;">';
        echo '<h2 class="success">✅ Integração Concluída!</h2>';
        echo '<p>Agora a ONE detecta intenções de viagem, corrida e ecommerce!</p>';
        echo '<p style="margin-top:16px;">';
        echo '<a href="one.php?action=send&message=quero%20ir%20pra%20miami" style="color:#10b981;margin-right:20px;" target="_blank">🧪 Testar Viagem</a>';
        echo '<a href="one.php?action=send&message=preciso%20de%20uma%20corrida" style="color:#3b82f6;margin-right:20px;" target="_blank">🧪 Testar Corrida</a>';
        echo '<a href="one.php?action=send&message=quero%20comprar%20um%20iphone" style="color:#a855f7;" target="_blank">🧪 Testar Ecommerce</a>';
        echo '</p>';
        echo '</div>';
    }
    
} else {
    
    echo '<div class="card">';
    echo '<h2>🔧 O que este patch faz:</h2>';
    echo '<p>Insere o detector de intenções no início do <code>processar()</code></p>';
    echo '<ul style="margin:16px 0;padding-left:24px;line-height:2;">';
    echo '<li><strong>Viagem</strong>: "quero ir pra miami" → Pergunta data e pessoas</li>';
    echo '<li><strong>Corrida</strong>: "preciso de corrida" → Pergunta destino e horário</li>';
    echo '<li><strong>Ecommerce</strong>: "quero um iphone" → Pergunta preferências</li>';
    echo '<li><strong>Outros</strong>: Continua fluxo normal</li>';
    echo '</ul>';
    echo '<form method="post" style="margin-top:20px;"><button type="submit" name="aplicar" class="btn">🔧 APLICAR</button></form>';
    echo '</div>';
}

echo '</div></body></html>';
