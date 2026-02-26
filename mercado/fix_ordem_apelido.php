<?php
/**
 * 🔧 FIX - Ordem do Apelido na Saudação
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔧 Fix Ordem Apelido</h1>";
echo "<style>body{font-family:sans-serif;background:#0f172a;color:#e2e8f0;padding:40px;}.card{background:#1e293b;padding:24px;border-radius:12px;margin:20px 0;}.btn{background:#10b981;color:white;padding:16px 32px;border:none;border-radius:12px;cursor:pointer;font-size:18px;}.success{color:#10b981;}.error{color:#ef4444;}pre{background:#0f172a;padding:12px;border-radius:8px;font-size:11px;overflow-x:auto;max-height:300px;}</style>";

$onePath = __DIR__ . '/one.php';
$conteudo = file_get_contents($onePath);

// Verificar sintaxe
$check = shell_exec("php -l $onePath 2>&1");
$ok = strpos($check, 'No syntax errors') !== false;
echo "<p>Sintaxe: " . ($ok ? '<span class="success">✅ OK</span>' : '<span class="error">❌ ERRO</span>') . "</p>";

if (!$ok) {
    echo "<pre>$check</pre>";
    exit;
}

// Mostrar ordem atual
echo "<div class='card'>";
echo "<h2>📋 Ordem Atual do Código</h2>";

// Encontrar posições
$posApelido = strpos($conteudo, '// ═══ SISTEMA DE APELIDO ═══');
$posPersonalidade = strpos($conteudo, '// ═══ PERSONALIDADE ONE V2 ═══');
$posSaudacao = strpos($conteudo, "preg_match('/^(oi|olá|ola|eae|eai|opa|hey|oie?)");
$posDetector = strpos($conteudo, '$intencaoDetectada = $this->detectarIntencaoUniversal');

echo "<p>1. Sistema Apelido: " . ($posApelido ? "linha ~" . substr_count(substr($conteudo, 0, $posApelido), "\n") : "❌ não encontrado") . "</p>";
echo "<p>2. Personalidade V2: " . ($posPersonalidade ? "linha ~" . substr_count(substr($conteudo, 0, $posPersonalidade), "\n") : "❌ não encontrado") . "</p>";
echo "<p>3. Saudação: " . ($posSaudacao ? "linha ~" . substr_count(substr($conteudo, 0, $posSaudacao), "\n") : "❌ não encontrado") . "</p>";
echo "<p>4. Detector Intenção: " . ($posDetector ? "linha ~" . substr_count(substr($conteudo, 0, $posDetector), "\n") : "❌ não encontrado") . "</p>";

// Verificar se saudação usa $nomeExibir ou $nome
if ($posSaudacao) {
    $trechoSaudacao = substr($conteudo, $posSaudacao, 500);
    $usaNomeExibir = strpos($trechoSaudacao, '$nomeExibir') !== false;
    $usaNome = strpos($trechoSaudacao, 'oneSaudacao($nome)') !== false;
    
    echo "<h3>Saudação usa:</h3>";
    echo "<p>" . ($usaNomeExibir ? '✅ $nomeExibir (correto!)' : '❌ $nomeExibir') . "</p>";
    echo "<p>" . ($usaNome ? '⚠️ $nome (problema! precisa trocar pra $nomeExibir)' : '✅ não usa $nome') . "</p>";
}

echo "</div>";

if (isset($_GET['fix'])) {
    
    // Backup
    copy($onePath, $onePath . '.bkp_ordem_' . time());
    echo "<p class='success'>✅ Backup</p>";
    
    // O problema: a saudação na PERSONALIDADE V2 usa oneSaudacao($nome) 
    // mas $nome vem do carregarClienteCompleto() que pega do cadastro
    // Precisamos fazer ela usar $nomeExibir que considera o apelido
    
    // Mas o $nomeExibir é definido no SISTEMA DE APELIDO que vem DEPOIS
    // Solução: mover a busca do apelido para ANTES da saudação
    
    // 1. Remover a definição de $nomeExibir do sistema de apelido
    // 2. Colocar a busca do apelido logo no início, antes de tudo
    
    $buscaApelido = '
            // ═══ BUSCA APELIDO (INÍCIO) ═══
            $apelidoSalvo = null;
            if ($this->pdo && $this->customer_id) {
                try {
                    $stmtApelido = $this->pdo->prepare("SELECT valor FROM om_one_memoria_pessoal WHERE customer_id = ? AND chave = \'apelido\' LIMIT 1");
                    $stmtApelido->execute([$this->customer_id]);
                    $rowApelido = $stmtApelido->fetch(PDO::FETCH_ASSOC);
                    if ($rowApelido) $apelidoSalvo = $rowApelido[\'valor\'];
                } catch (Exception $e) {}
            }
            $clienteBase = $this->carregarClienteCompleto();
            $primeiroNome = $clienteBase ? trim(explode(\' \', trim($clienteBase[\'firstname\']))[0]) : null;
            $nomeExibir = $apelidoSalvo ?: $primeiroNome;
            // ═══ FIM BUSCA APELIDO ═══
            
';
    
    // Verificar se já tem a busca no início
    if (strpos($conteudo, '// ═══ BUSCA APELIDO (INÍCIO) ═══') !== false) {
        echo "<p>⚠️ Busca de apelido já está no início</p>";
    } else {
        // Encontrar início do método processar ou similar
        // Vamos inserir logo após o início do processamento, antes de qualquer resposta
        
        // Procurar onde começa o processamento de mensagem
        $marcadorInicio = 'public function processar($msg)';
        if (strpos($conteudo, $marcadorInicio) !== false) {
            // Inserir após o { do método
            $pos = strpos($conteudo, $marcadorInicio);
            $posChave = strpos($conteudo, '{', $pos);
            
            if ($posChave !== false) {
                $antes = substr($conteudo, 0, $posChave + 1);
                $depois = substr($conteudo, $posChave + 1);
                $conteudo = $antes . "\n" . $buscaApelido . $depois;
                echo "<p class='success'>✅ Busca de apelido inserida no início</p>";
            }
        } else {
            echo "<p class='error'>❌ Método processar não encontrado</p>";
        }
    }
    
    // Agora trocar oneSaudacao($nome) por oneSaudacao($nomeExibir)
    $conteudo = str_replace('oneSaudacao($nome)', 'oneSaudacao($nomeExibir)', $conteudo);
    echo "<p class='success'>✅ Saudação atualizada para usar $nomeExibir</p>";
    
    // Também trocar onde define $nome para usar $nomeExibir
    // Na personalidade V2, trocar: $nome = $cliente ? trim($cliente['firstname']) : null;
    // Por: usar $nomeExibir que já foi definido
    
    // Remover redefinição de $nome na personalidade
    $conteudo = preg_replace(
        '/\$cliente = \$this->carregarClienteCompleto\(\);\s*\$nome = \$cliente \? trim\(\$cliente\[\'firstname\'\]\) : null;/',
        '// Usando $nomeExibir definido no início',
        $conteudo
    );
    
    file_put_contents($onePath, $conteudo);
    
    $checkFinal = shell_exec("php -l $onePath 2>&1");
    if (strpos($checkFinal, 'No syntax errors') !== false) {
        echo "<div class='card' style='border:2px solid #10b981;'>";
        echo "<h2 class='success'>✅ Fix Aplicado!</h2>";
        echo "<p>Agora a ONE vai usar 'Amor' (ou outro apelido salvo) na saudação.</p>";
        echo "<p><a href='one.php' class='btn'>💚 Testar ONE</a></p>";
        echo "</div>";
    } else {
        echo "<p class='error'>❌ Erro de sintaxe</p>";
        echo "<pre>$checkFinal</pre>";
    }
    
} else {
    echo "<div class='card' style='text-align:center;'>";
    echo "<p><a href='?fix=1' class='btn'>🔧 APLICAR FIX</a></p>";
    echo "</div>";
}
