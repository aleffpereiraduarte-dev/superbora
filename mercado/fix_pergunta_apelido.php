<?php
/**
 * 🔧 FIX - Pergunta sobre apelido salvo
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔧 Fix Pergunta Apelido</h1>";
echo "<style>body{font-family:sans-serif;background:#0f172a;color:#e2e8f0;padding:40px;}.btn{background:#10b981;color:white;padding:16px 32px;border:none;border-radius:12px;cursor:pointer;font-size:18px;}.success{color:#10b981;}.error{color:#ef4444;}.card{background:#1e293b;padding:24px;border-radius:12px;margin:20px 0;}</style>";

$onePath = __DIR__ . '/one.php';
$conteudo = file_get_contents($onePath);

// Verificar sintaxe
$check = shell_exec("php -l $onePath 2>&1");
$ok = strpos($check, 'No syntax errors') !== false;

echo "<p>Sintaxe: " . ($ok ? '<span class="success">✅ OK</span>' : '<span class="error">❌ ERRO</span>') . "</p>";

if (!$ok) {
    echo "<p class='error'>Primeiro restaure um backup válido!</p>";
    exit;
}

if (isset($_GET['fix'])) {
    
    // Backup
    copy($onePath, $onePath . '.bkp_pergunta_apelido_' . time());
    
    // Código para responder pergunta sobre apelido
    $codigo = '
            // Pergunta sobre como quer ser chamado
            if (preg_match(\'/(como|qual).*(quero|quer|devo|vou).*(ser chamad|me chamar|me chama)/i\', $msgLowerApelido) ||
                preg_match(\'/(como|qual).*(você|vc|voce).*(me chama|chama eu)/i\', $msgLowerApelido) ||
                preg_match(\'/(qual|como).*(meu apelido|meu nome)/i\', $msgLowerApelido)) {
                
                if ($apelidoSalvo) {
                    $respostasApelido = [
                        "Você pediu pra te chamar de $apelidoSalvo. Quer mudar?",
                        "Tô te chamando de $apelidoSalvo. Tá bom assim?",
                        "Seu apelido aqui é $apelidoSalvo."
                    ];
                } else {
                    $respostasApelido = [
                        "Tô te chamando de $primeiroNome. Quer que eu te chame de outro jeito?",
                        "Por enquanto é $primeiroNome. Me fala se quiser outro nome.",
                        "Uso $primeiroNome. Quer mudar?"
                    ];
                }
                $respApelido = $respostasApelido[array_rand($respostasApelido)];
                $this->salvar(\'one\', $respApelido, [\'fonte\' => \'pergunta_apelido\']);
                return [\'success\' => true, \'response\' => $respApelido, \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
            }
            
';
    
    // Inserir após o sistema de apelido (antes do FIM)
    $marcador = '// ═══ FIM SISTEMA APELIDO ═══';
    
    if (strpos($conteudo, $marcador) !== false) {
        $conteudo = str_replace($marcador, $codigo . $marcador, $conteudo);
        file_put_contents($onePath, $conteudo);
        
        $checkFinal = shell_exec("php -l $onePath 2>&1");
        if (strpos($checkFinal, 'No syntax errors') !== false) {
            echo "<div class='card' style='border:2px solid #10b981;'>";
            echo "<h2 class='success'>✅ Instalado!</h2>";
            echo "<p>Agora a ONE entende:</p>";
            echo "<ul>";
            echo "<li>\"como você me chama?\"</li>";
            echo "<li>\"qual meu apelido?\"</li>";
            echo "<li>\"como quero ser chamado?\"</li>";
            echo "</ul>";
            echo "<p><a href='one.php' style='color:#10b981;font-size:18px;'>💚 Testar ONE</a></p>";
            echo "</div>";
        } else {
            echo "<p class='error'>❌ Erro de sintaxe</p>";
            echo "<pre>$checkFinal</pre>";
        }
    } else {
        echo "<p class='error'>❌ Sistema de apelido não encontrado. Instale primeiro.</p>";
    }
    
} else {
    echo "<div class='card'>";
    echo "<h2>O que vai fazer:</h2>";
    echo "<p>Adiciona entendimento para perguntas como:</p>";
    echo "<ul>";
    echo "<li>\"como você me chama?\" → \"Tô te chamando de Amor\"</li>";
    echo "<li>\"qual meu apelido?\" → \"Seu apelido aqui é Amor\"</li>";
    echo "<li>\"como quero ser chamado?\" → \"Você pediu pra te chamar de Amor\"</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<p><a href='?fix=1' class='btn'>🔧 APLICAR</a></p>";
}
