<?php
/**
 * 🔧 FIX - Adicionar função salvarNoBrainUniversal
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/html; charset=utf-8');

echo "<h1>🔧 Fix salvarNoBrainUniversal</h1>";
echo "<style>body{font-family:sans-serif;background:#1e293b;color:#e2e8f0;padding:40px;} .ok{color:#10b981;} .erro{color:#ef4444;} pre{background:#0f172a;padding:15px;border-radius:8px;overflow-x:auto;} button{background:#10b981;color:white;padding:15px 30px;border:none;border-radius:8px;cursor:pointer;font-size:18px;margin:10px 0;}</style>";

$onePath = __DIR__ . '/one.php';

if (!file_exists($onePath)) {
    die("<p class='erro'>❌ one.php não encontrado!</p>");
}

$conteudo = file_get_contents($onePath);

// Verificar se função já existe
if (strpos($conteudo, 'function salvarNoBrainUniversal') !== false) {
    echo "<p class='ok'>✅ Função salvarNoBrainUniversal já existe!</p>";
    
    // Mas vamos verificar se está dentro da classe correta
    echo "<p>Verificando se está no lugar certo...</p>";
    
} else {
    echo "<p class='erro'>❌ Função salvarNoBrainUniversal NÃO existe!</p>";
}

// Verificar se tem a chamada
$temChamada = strpos($conteudo, '$this->salvarNoBrainUniversal') !== false;
echo "<p>Chamada \$this->salvarNoBrainUniversal: " . ($temChamada ? "<span class='ok'>SIM</span>" : "<span class='erro'>NÃO</span>") . "</p>";

if (isset($_POST['aplicar'])) {
    
    // Backup
    $backup = $onePath . '.backup_func_' . date('Y-m-d_H-i-s');
    file_put_contents($backup, $conteudo);
    echo "<p class='ok'>✅ Backup criado: $backup</p>";
    
    // A função que precisa adicionar
    $funcao = '
    // ═══════════════════════════════════════════════════════════════════════════
    // BRAIN UNIVERSAL - SALVAR NOVAS RESPOSTAS
    // ═══════════════════════════════════════════════════════════════════════════
    private function salvarNoBrainUniversal($pergunta, $resposta) {
        if (!$this->pdo) return;
        
        try {
            $perguntaNorm = mb_strtolower(trim($pergunta), \'UTF-8\');
            $perguntaNorm = preg_replace(\'/[?!.,]+$/\', \'\', $perguntaNorm);
            
            // Detecta categoria
            $categoria = \'geral\';
            $modulo = \'geral\';
            
            if (preg_match(\'/(receita|fazer|cozinhar)/i\', $pergunta)) {
                $categoria = \'receitas\'; $modulo = \'mercado\';
            } elseif (preg_match(\'/(preço|quanto|custa)/i\', $pergunta)) {
                $categoria = \'precos\'; $modulo = \'ecommerce\';
            } elseif (preg_match(\'/(entrega|frete)/i\', $pergunta)) {
                $categoria = \'frete\'; $modulo = \'ecommerce\';
            } elseif (preg_match(\'/(viagem|passagem|voo)/i\', $pergunta)) {
                $categoria = \'viagens\'; $modulo = \'travel\';
            } elseif (preg_match(\'/(oi|olá|bom dia|boa tarde)/i\', $pergunta)) {
                $categoria = \'saudacao\'; $modulo = \'conversa\';
            }
            
            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO om_one_brain_universal 
                (pergunta, resposta, categoria, modulo, origem, ativo, qualidade)
                VALUES (?, ?, ?, ?, \'chat-aprendido\', 1, 4)
            ");
            $stmt->execute([$perguntaNorm, $resposta, $categoria, $modulo]);
            
        } catch (Exception $e) {
            error_log("Brain Universal: " . $e->getMessage());
        }
    }
    // ═══════════════════════════════════════════════════════════════════════════

';

    // Encontrar lugar para inserir - antes de "public function status()"
    $marcador = 'public function status()';
    
    if (strpos($conteudo, $marcador) !== false) {
        // Verificar se função já existe
        if (strpos($conteudo, 'function salvarNoBrainUniversal') === false) {
            $conteudo = str_replace($marcador, $funcao . "\n        " . $marcador, $conteudo);
            echo "<p class='ok'>✅ Função salvarNoBrainUniversal ADICIONADA!</p>";
        } else {
            echo "<p class='ok'>✅ Função já existia</p>";
        }
    } else {
        echo "<p class='erro'>❌ Não encontrou marcador 'public function status()'</p>";
    }
    
    // TAMBÉM: Remover a chamada se a resposta veio do BRAIN (não precisa salvar de volta)
    // O problema é que está chamando salvarNoBrainUniversal mesmo quando veio do brain
    
    // Procurar e remover/comentar a chamada quando resposta vem do brain
    $chamadaProblema = '$this->salvarNoBrainUniversal($msg, $resp);';
    
    // Verificar contexto da chamada
    if (preg_match('/if\s*\(\$resp\)\s*\{[^}]*salvarNoBrainUniversal/s', $conteudo)) {
        echo "<p class='ok'>✅ Chamada está dentro do bloco GPT (correto)</p>";
    }
    
    // Salvar
    file_put_contents($onePath, $conteudo);
    
    echo "<div style='background:#064e3b;padding:20px;border-radius:12px;margin:20px 0;'>";
    echo "<h2 class='ok'>🎉 FIX APLICADO!</h2>";
    echo "</div>";
    
    echo "<p><a href='one.php?action=send&message=voce%20ta%20bem' style='color:#10b981;font-size:1.2rem;'>🧪 TESTAR AGORA</a></p>";
    
} else {
    
    echo "<h2>📋 O que o fix vai fazer:</h2>";
    echo "<ol>";
    echo "<li>Criar backup do one.php</li>";
    echo "<li>Adicionar a função <code>salvarNoBrainUniversal()</code> que está faltando</li>";
    echo "</ol>";
    
    echo "<form method='post'>";
    echo "<button type='submit' name='aplicar'>🔧 APLICAR FIX</button>";
    echo "</form>";
}
