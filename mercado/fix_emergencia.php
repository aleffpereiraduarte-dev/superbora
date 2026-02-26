<?php
/**
 * 🚨 FIX EMERGÊNCIA - Restaurar + Apelido
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🚨 Fix Emergência</h1>";
echo "<style>body{font-family:sans-serif;background:#0f172a;color:#e2e8f0;padding:40px;}.btn{background:#10b981;color:white;padding:16px 32px;border:none;border-radius:12px;cursor:pointer;font-size:18px;margin:8px;}.btn-red{background:#ef4444;}.success{color:#10b981;}.error{color:#ef4444;}.card{background:#1e293b;padding:24px;border-radius:12px;margin:20px 0;}pre{background:#0f172a;padding:12px;border-radius:8px;font-size:11px;overflow-x:auto;}</style>";

$onePath = __DIR__ . '/one.php';

// Listar backups válidos
echo "<div class='card'>";
echo "<h2>📦 Backups Disponíveis</h2>";

$backups = glob($onePath . '.*');
rsort($backups);

$backupsValidos = [];
foreach ($backups as $b) {
    if (strpos($b, '.bkp') !== false || strpos($b, '.backup') !== false) {
        $check = shell_exec("php -l " . escapeshellarg($b) . " 2>&1");
        $ok = strpos($check, 'No syntax errors') !== false;
        if ($ok) {
            $backupsValidos[] = $b;
            $nome = basename($b);
            $tamanho = round(filesize($b) / 1024) . 'KB';
            echo "<p>✅ $nome ($tamanho) <a href='?restaurar=" . urlencode($b) . "' style='color:#10b981;'>[RESTAURAR]</a></p>";
        }
    }
}

if (empty($backupsValidos)) {
    echo "<p class='error'>❌ Nenhum backup válido encontrado!</p>";
}
echo "</div>";

// Restaurar
if (isset($_GET['restaurar'])) {
    $bkp = $_GET['restaurar'];
    if (file_exists($bkp)) {
        // Backup do atual (quebrado)
        copy($onePath, $onePath . '.quebrado_' . time());
        
        // Restaurar
        copy($bkp, $onePath);
        
        // Verificar
        $check = shell_exec("php -l $onePath 2>&1");
        $ok = strpos($check, 'No syntax errors') !== false;
        
        echo "<div class='card' style='border:2px solid " . ($ok ? '#10b981' : '#ef4444') . ";'>";
        if ($ok) {
            echo "<h2 class='success'>✅ Restaurado com Sucesso!</h2>";
            echo "<p><a href='one.php' class='btn'>💚 Testar ONE</a></p>";
            echo "<p><a href='?aplicar_apelido=1' class='btn'>👤 Aplicar Sistema de Apelido</a></p>";
        } else {
            echo "<h2 class='error'>❌ Ainda com erro</h2>";
            echo "<pre>$check</pre>";
        }
        echo "</div>";
    }
}

// Aplicar sistema de apelido (de forma segura)
if (isset($_GET['aplicar_apelido'])) {
    
    // Verificar sintaxe primeiro
    $check = shell_exec("php -l $onePath 2>&1");
    if (strpos($check, 'No syntax errors') === false) {
        echo "<p class='error'>❌ one.php tem erro! Restaure um backup primeiro.</p>";
    } else {
        
        $conteudo = file_get_contents($onePath);
        
        // Backup antes de modificar
        copy($onePath, $onePath . '.bkp_apelido_' . time());
        
        // Verificar se já tem sistema de apelido
        if (strpos($conteudo, '// ═══ SISTEMA DE APELIDO ═══') !== false) {
            echo "<p class='success'>✅ Sistema de apelido já instalado!</p>";
        } else {
            
            // Código do sistema de apelido
            $codigoApelido = '
            // ═══ SISTEMA DE APELIDO ═══
            // Buscar apelido salvo
            $apelidoSalvo = null;
            if ($this->pdo && $this->customer_id) {
                try {
                    $stmtApelido = $this->pdo->prepare("SELECT valor FROM om_one_memoria_pessoal WHERE customer_id = ? AND chave = \'apelido\' LIMIT 1");
                    $stmtApelido->execute([$this->customer_id]);
                    $rowApelido = $stmtApelido->fetch(PDO::FETCH_ASSOC);
                    if ($rowApelido) $apelidoSalvo = $rowApelido[\'valor\'];
                } catch (Exception $e) {}
            }
            
            // Nome a exibir: apelido ou primeiro nome
            $clienteInfo = $this->carregarClienteCompleto();
            $primeiroNome = $clienteInfo ? trim(explode(\' \', trim($clienteInfo[\'firstname\']))[0]) : null;
            $nomeExibir = $apelidoSalvo ?: $primeiroNome;
            
            // Detectar pedido de apelido
            $msgLowerApelido = mb_strtolower($msg, \'UTF-8\');
            if (preg_match(\'/(pode me chamar de|me chama de|meu apelido é|prefiro ser chamad[oa] de|quero ser chamad[oa] de)\s+([a-záéíóúâêôãõç]+)/iu\', $msg, $matchApelido)) {
                $novoApelido = ucfirst(mb_strtolower(trim($matchApelido[2]), \'UTF-8\'));
                
                // Salvar na memória
                if ($this->pdo && $this->customer_id) {
                    try {
                        $stmtSalvar = $this->pdo->prepare("INSERT INTO om_one_memoria_pessoal (customer_id, categoria, chave, valor) VALUES (?, \'pessoal\', \'apelido\', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
                        $stmtSalvar->execute([$this->customer_id, $novoApelido]);
                    } catch (Exception $e) {}
                }
                
                $respostasApelido = [
                    "Combinado, $novoApelido. Vou te chamar assim.",
                    "Beleza, $novoApelido. Anotado.",
                    "Certo, $novoApelido. Guardei."
                ];
                $respApelido = $respostasApelido[array_rand($respostasApelido)];
                $this->salvar(\'one\', $respApelido, [\'fonte\' => \'apelido\']);
                return [\'success\' => true, \'response\' => $respApelido, \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
            }
            // ═══ FIM SISTEMA APELIDO ═══
            
';
            
            // Inserir no início do processamento
            $marcador = '$intencaoDetectada = $this->detectarIntencaoUniversal($msg);';
            
            if (strpos($conteudo, $marcador) !== false) {
                $conteudo = str_replace($marcador, $codigoApelido . $marcador, $conteudo);
                file_put_contents($onePath, $conteudo);
                
                // Verificar
                $checkFinal = shell_exec("php -l $onePath 2>&1");
                if (strpos($checkFinal, 'No syntax errors') !== false) {
                    echo "<div class='card' style='border:2px solid #10b981;'>";
                    echo "<h2 class='success'>✅ Sistema de Apelido Instalado!</h2>";
                    echo "<p>• Chama pelo primeiro nome por padrão</p>";
                    echo "<p>• 'me chama de Amor' → salva e usa 'Amor'</p>";
                    echo "<p><a href='one.php' class='btn'>💚 Testar ONE</a></p>";
                    echo "</div>";
                } else {
                    echo "<p class='error'>❌ Erro após aplicar</p>";
                    echo "<pre>$checkFinal</pre>";
                }
            } else {
                echo "<p class='error'>❌ Marcador não encontrado</p>";
            }
        }
    }
}

// Status atual
echo "<div class='card'>";
echo "<h2>📋 Status Atual</h2>";
$checkAtual = shell_exec("php -l $onePath 2>&1");
$okAtual = strpos($checkAtual, 'No syntax errors') !== false;
echo "<p>Sintaxe: " . ($okAtual ? '<span class="success">✅ OK</span>' : '<span class="error">❌ ERRO</span>') . "</p>";
if (!$okAtual) {
    echo "<pre>$checkAtual</pre>";
}
echo "</div>";
