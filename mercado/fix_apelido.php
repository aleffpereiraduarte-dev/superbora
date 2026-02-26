<?php
/**
 * 🔧 DIAGNÓSTICO + FIX + SISTEMA DE APELIDO
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔧 Diagnóstico ONE</h1>";
echo "<style>body{font-family:sans-serif;background:#0f172a;color:#e2e8f0;padding:40px;}pre{background:#1e293b;padding:16px;border-radius:8px;overflow-x:auto;font-size:11px;}.btn{background:#10b981;color:white;padding:16px 32px;border:none;border-radius:12px;cursor:pointer;font-size:18px;margin:8px;}.success{color:#10b981;}.error{color:#ef4444;}.card{background:#1e293b;padding:24px;border-radius:12px;margin:20px 0;}</style>";

$onePath = __DIR__ . '/one.php';

// 1. Verificar sintaxe
echo "<div class='card'>";
echo "<h2>1️⃣ Sintaxe do one.php</h2>";
$check = shell_exec("php -l $onePath 2>&1");
$ok = strpos($check, 'No syntax errors') !== false;
echo "<p>" . ($ok ? '<span class="success">✅ OK</span>' : '<span class="error">❌ ERRO</span>') . "</p>";
if (!$ok) {
    echo "<pre>$check</pre>";
    
    // Mostrar linha do erro
    if (preg_match('/line (\d+)/', $check, $m)) {
        $linha = (int)$m[1];
        $conteudo = file_get_contents($onePath);
        $linhas = explode("\n", $conteudo);
        echo "<h3>Contexto do erro (linha $linha):</h3>";
        echo "<pre>";
        for ($i = max(0, $linha - 5); $i < min(count($linhas), $linha + 5); $i++) {
            $destaque = ($i + 1 == $linha) ? ' style="background:#ef4444;color:white;"' : '';
            echo "<span$destaque>" . ($i+1) . ": " . htmlspecialchars($linhas[$i]) . "</span>\n";
        }
        echo "</pre>";
    }
}
echo "</div>";

// 2. Listar backups
echo "<div class='card'>";
echo "<h2>2️⃣ Backups Disponíveis</h2>";
$backups = glob($onePath . '.bkp_*');
rsort($backups);
foreach (array_slice($backups, 0, 5) as $b) {
    $checkB = shell_exec("php -l $b 2>&1");
    $okB = strpos($checkB, 'No syntax errors') !== false;
    $nome = basename($b);
    echo "<p>" . ($okB ? "✅" : "❌") . " $nome ";
    if ($okB) {
        echo "<a href='?restaurar=" . urlencode($b) . "' style='color:#10b981;'>[Restaurar]</a>";
    }
    echo "</p>";
}
echo "</div>";

// 3. Restaurar backup
if (isset($_GET['restaurar'])) {
    $bkp = $_GET['restaurar'];
    if (file_exists($bkp)) {
        copy($bkp, $onePath);
        echo "<div class='card' style='border:2px solid #10b981;'>";
        echo "<h2 class='success'>✅ Backup Restaurado!</h2>";
        echo "<p><a href='one.php' style='color:#10b981;'>Testar ONE</a></p>";
        echo "</div>";
    }
}

// 4. Aplicar fix do apelido
if (isset($_POST['aplicar_apelido'])) {
    
    $conteudo = file_get_contents($onePath);
    
    // Verificar se sintaxe tá ok primeiro
    $check = shell_exec("php -l $onePath 2>&1");
    if (strpos($check, 'No syntax errors') === false) {
        echo "<p class='error'>❌ Primeiro restaure um backup válido!</p>";
    } else {
        
        // Backup
        copy($onePath, $onePath . '.bkp_apelido_' . time());
        
        // Novo código para perguntar apelido e salvar na memória
        $codigoApelido = '
            // ═══ SISTEMA DE APELIDO ═══
            $msgLower = mb_strtolower($msg, \'UTF-8\');
            
            // Buscar apelido salvo na memória
            $apelidoSalvo = null;
            if ($this->pdo && $this->customer_id) {
                try {
                    $stmt = $this->pdo->prepare("SELECT valor FROM om_one_memoria_pessoal WHERE customer_id = ? AND chave = \'apelido\' LIMIT 1");
                    $stmt->execute([$this->customer_id]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row) $apelidoSalvo = $row[\'valor\'];
                } catch (Exception $e) {}
            }
            
            // Se não tem apelido salvo, usar primeiro nome
            $cliente = $this->carregarClienteCompleto();
            $nomeExibir = $apelidoSalvo ?: ($cliente ? trim(explode(\' \', $cliente[\'firstname\'])[0]) : null);
            
            // Detectar se está informando apelido
            if (preg_match(\'/(pode me chamar de|me chama de|meu nome é|meu apelido é|prefiro ser chamad[oa] de)\s+([a-záéíóúâêôãõç]+)/i\', $msg, $m)) {
                $novoApelido = ucfirst(strtolower(trim($m[2])));
                
                // Salvar na memória
                if ($this->pdo && $this->customer_id) {
                    try {
                        $stmt = $this->pdo->prepare("INSERT INTO om_one_memoria_pessoal (customer_id, categoria, chave, valor) VALUES (?, \'pessoal\', \'apelido\', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor), vezes_mencionado = vezes_mencionado + 1");
                        $stmt->execute([$this->customer_id, $novoApelido]);
                    } catch (Exception $e) {}
                }
                
                $respostas = [
                    "Combinado, $novoApelido. Vou te chamar assim.",
                    "Beleza, $novoApelido. Anotado.",
                    "Pode deixar, $novoApelido. Tô ligada.",
                    "Certo, $novoApelido. Guardei aqui."
                ];
                $resp = $respostas[array_rand($respostas)];
                $this->salvar(\'one\', $resp, [\'fonte\' => \'apelido\']);
                return [\'success\' => true, \'response\' => $resp, \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
            }
            // ═══ FIM SISTEMA APELIDO ═══
            
';
        
        // Atualizar função de saudação para usar apelido
        // Procurar onde tem oneSaudacao e adaptar
        
        // Inserir antes do PERSONALIDADE ONE V2 ou antes do detector
        if (strpos($conteudo, '// ═══ PERSONALIDADE ONE V2 ═══') !== false) {
            $conteudo = str_replace('// ═══ PERSONALIDADE ONE V2 ═══', $codigoApelido . '// ═══ PERSONALIDADE ONE V2 ═══', $conteudo);
        } elseif (strpos($conteudo, '$intencaoDetectada = $this->detectarIntencaoUniversal($msg);') !== false) {
            $conteudo = str_replace('$intencaoDetectada = $this->detectarIntencaoUniversal($msg);', $codigoApelido . '$intencaoDetectada = $this->detectarIntencaoUniversal($msg);', $conteudo);
        }
        
        // Atualizar saudações para usar $nomeExibir em vez de $nome
        $conteudo = str_replace(
            '$resp = oneSaudacao($nome);',
            '$resp = oneSaudacao($nomeExibir);',
            $conteudo
        );
        
        file_put_contents($onePath, $conteudo);
        
        $checkFinal = shell_exec("php -l $onePath 2>&1");
        if (strpos($checkFinal, 'No syntax errors') !== false) {
            echo "<div class='card' style='border:2px solid #10b981;'>";
            echo "<h2 class='success'>✅ Sistema de Apelido Instalado!</h2>";
            echo "<p>Agora a ONE:</p>";
            echo "<ul>";
            echo "<li>Chama pelo primeiro nome por padrão</li>";
            echo "<li>Se o cliente disser 'me chama de Amor' → salva e usa 'Amor'</li>";
            echo "<li>Lembra do apelido nas próximas conversas</li>";
            echo "</ul>";
            echo "<p><a href='one.php' style='color:#10b981;font-size:18px;'>💚 Testar ONE</a></p>";
            echo "</div>";
        } else {
            echo "<p class='error'>❌ Erro de sintaxe após aplicar</p>";
            echo "<pre>$checkFinal</pre>";
        }
    }
}

// Botões de ação
echo "<div class='card' style='text-align:center;'>";
echo "<form method='post' style='display:inline;'>";
echo "<button type='submit' name='aplicar_apelido' class='btn'>💚 Aplicar Sistema de Apelido</button>";
echo "</form>";
echo "</div>";

echo "<div class='card'>";
echo "<h2>💡 Como vai funcionar:</h2>";
echo "<p><strong>Padrão:</strong> ONE chama pelo primeiro nome (Aleff)</p>";
echo "<p><strong>Cliente diz:</strong> \"me chama de Amor\" → ONE salva e passa a chamar de \"Amor\"</p>";
echo "<p><strong>Cliente diz:</strong> \"pode me chamar de Leff\" → ONE salva e passa a chamar de \"Leff\"</p>";
echo "<p><strong>Memória:</strong> Apelido fica salvo para próximas conversas</p>";
echo "</div>";
