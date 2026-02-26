<?php
require_once __DIR__ . '/config/database.php';
/**
 * 🔧 FIX v5 - PUXA DADOS DO CLIENTE LOGADO
 * 
 * A ONE deve saber o nome do cliente quando ele está logado!
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$onePath = __DIR__ . '/one.php';

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Fix v5 - Dados Cliente</title>";
echo "<style>
body{font-family:system-ui;background:#0a0a0a;color:#e5e5e5;padding:20px;max-width:900px;margin:0 auto}
h1{color:#22c55e}
.card{background:#151515;border-radius:8px;padding:16px;margin:16px 0}
.ok{color:#22c55e}.erro{color:#ef4444}.aviso{color:#eab308}
pre{background:#0a0a0a;padding:12px;border-radius:6px;overflow-x:auto;font-size:11px}
.btn{background:#22c55e;color:#000;border:none;padding:12px 24px;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px}
</style></head><body>";

echo "<h1>🔧 Fix v5 - Dados do Cliente</h1>";
echo "<p style='color:#666'>Faz a ONE puxar o nome do cliente logado automaticamente</p>";

// Verificar banco
try {
    $pdo = getPDO();
    echo "<div class='card'><p class='ok'>✅ Banco conectado</p></div>";
} catch (Exception $e) {
    die("<div class='card'><p class='erro'>❌ Erro banco: {$e->getMessage()}</p></div>");
}

// Ver se customer_id 1000006 existe
$stmt = $pdo->prepare("SELECT customer_id, firstname, lastname, email FROM oc_customer WHERE customer_id = ?");
$stmt->execute([1000006]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

echo "<div class='card'>";
echo "<h3>👤 Dados do Cliente 1000006</h3>";
if ($cliente) {
    echo "<p class='ok'>✅ Cliente encontrado!</p>";
    echo "<ul>";
    echo "<li><b>ID:</b> {$cliente['customer_id']}</li>";
    echo "<li><b>Nome:</b> {$cliente['firstname']} {$cliente['lastname']}</li>";
    echo "<li><b>Email:</b> {$cliente['email']}</li>";
    echo "</ul>";
} else {
    echo "<p class='erro'>❌ Cliente não encontrado</p>";
}
echo "</div>";

// Ver perfil ONE
$stmt = $pdo->prepare("SELECT * FROM om_one_cliente_perfil WHERE customer_id = ?");
$stmt->execute([1000006]);
$perfil = $stmt->fetch(PDO::FETCH_ASSOC);

echo "<div class='card'>";
echo "<h3>💚 Perfil ONE</h3>";
if ($perfil) {
    echo "<p class='ok'>✅ Perfil encontrado!</p>";
    echo "<ul>";
    echo "<li><b>Nome:</b> " . ($perfil['nome'] ?? 'não definido') . "</li>";
    echo "<li><b>Apelido:</b> " . ($perfil['apelido'] ?? 'não definido') . "</li>";
    echo "</ul>";
} else {
    echo "<p class='aviso'>⚠️ Perfil ONE não existe ainda</p>";
}
echo "</div>";

if (isset($_POST['aplicar'])) {
    
    echo "<div class='card'>";
    echo "<h3>⚡ Aplicando Fix...</h3>";
    
    // 1. Criar/atualizar perfil ONE com dados do cliente
    if ($cliente) {
        $nome = $cliente['firstname'];
        
        if ($perfil) {
            // Atualiza
            $pdo->prepare("UPDATE om_one_cliente_perfil SET nome = ? WHERE customer_id = ?")
                ->execute([$nome, 1000006]);
            echo "<p class='ok'>✅ Perfil atualizado com nome: $nome</p>";
        } else {
            // Cria
            $pdo->prepare("INSERT INTO om_one_cliente_perfil (customer_id, nome, primeira_conversa, ultima_conversa) VALUES (?, ?, NOW(), NOW())")
                ->execute([1000006, $nome]);
            echo "<p class='ok'>✅ Perfil criado com nome: $nome</p>";
        }
    }
    
    // 2. Adicionar código no one.php para carregar nome automaticamente
    $conteudo = file_get_contents($onePath);
    
    $fixCarregarNome = '
            // ═══ FIX v5: CARREGAR NOME DO CLIENTE LOGADO ═══
            if ($this->customer_id && empty($_SESSION[\'one_conversa\'][\'nome_cliente\'])) {
                // Primeiro tenta pegar do perfil ONE
                if (!empty($this->perfil[\'nome\'])) {
                    $_SESSION[\'one_conversa\'][\'nome_cliente\'] = $this->perfil[\'nome\'];
                } 
                // Se não tem, pega do cadastro
                elseif ($this->pdo) {
                    try {
                        $stmtNome = $this->pdo->prepare("SELECT firstname FROM oc_customer WHERE customer_id = ?");
                        $stmtNome->execute([$this->customer_id]);
                        $nomeCliente = $stmtNome->fetchColumn();
                        if ($nomeCliente) {
                            $_SESSION[\'one_conversa\'][\'nome_cliente\'] = $nomeCliente;
                        }
                    } catch (Exception $e) {}
                }
            }
            // ═══ FIM CARREGAR NOME ═══
';
    
    if (strpos($conteudo, 'FIX v5: CARREGAR NOME DO CLIENTE') === false) {
        // Inserir no início do processar, após limpar contexto
        $marcadores = [
            '// ═══ FIX v4: MEGA DETECTOR ═══',
            '// ═══ FIX: LIMPAR CONTEXTO PRESO ═══',
            '// ═══ FIX MASTER: LIMPAR CONTEXTO PRESO ═══'
        ];
        
        $inserido = false;
        foreach ($marcadores as $marcador) {
            if (strpos($conteudo, $marcador) !== false) {
                $conteudo = str_replace($marcador, $fixCarregarNome . "\n            " . $marcador, $conteudo);
                echo "<p class='ok'>✅ Código de carregar nome inserido</p>";
                $inserido = true;
                break;
            }
        }
        
        if (!$inserido) {
            echo "<p class='aviso'>⚠️ Não encontrou marcador para inserir código</p>";
        }
    } else {
        echo "<p class='aviso'>⚠️ Fix já aplicado no código</p>";
    }
    
    // 3. Modificar getNome() para usar o nome carregado
    $fixGetNome = '
        // ═══ FIX v5: GET NOME MELHORADO ═══
        private function getNomeCliente() {
            // 1. Apelido tem prioridade
            if (!empty($_SESSION[\'one_conversa\'][\'apelido\'])) {
                return $_SESSION[\'one_conversa\'][\'apelido\'];
            }
            // 2. Nome da sessão
            if (!empty($_SESSION[\'one_conversa\'][\'nome_cliente\'])) {
                return $_SESSION[\'one_conversa\'][\'nome_cliente\'];
            }
            // 3. Nome do perfil
            if (!empty($this->perfil[\'nome\'])) {
                return $this->perfil[\'nome\'];
            }
            // 4. Apelido do perfil
            if (!empty($this->perfil[\'apelido\'])) {
                return $this->perfil[\'apelido\'];
            }
            return null;
        }
        // ═══ FIM GET NOME MELHORADO ═══
';
    
    if (strpos($conteudo, 'FIX v5: GET NOME MELHORADO') === false) {
        // Procurar onde inserir (antes de getCarrinho ou no final da classe)
        if (strpos($conteudo, 'private function getCarrinho') !== false) {
            $conteudo = str_replace('private function getCarrinho', $fixGetNome . "\n        private function getCarrinho", $conteudo);
            echo "<p class='ok'>✅ Função getNomeCliente() adicionada</p>";
        } else {
            echo "<p class='aviso'>⚠️ Não encontrou onde inserir getNomeCliente()</p>";
        }
    }
    
    // 4. Atualizar perguntas sobre nome para usar o nome carregado
    $fixPerguntaNome = '
            // ═══ FIX v5: RESPONDER SOBRE NOME ═══
            if (preg_match(\'/(sabe|conhece|lembra).*(meu nome|como me chamo|quem eu sou)/i\', $msg) || 
                preg_match(\'/(qual|como).*(é|e).*(meu nome)/i\', $msg)) {
                $nomeCliente = $this->getNomeCliente();
                if ($nomeCliente) {
                    $resp = "Claro que sei! Você é $nomeCliente! 💚";
                } else {
                    $resp = "Ainda não sei seu nome! Como posso te chamar?";
                }
                $this->salvar(\'one\', $resp, [\'fonte\' => \'nome_cliente\']);
                return [\'success\' => true, \'response\' => $resp, \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
            }
            // ═══ FIM RESPONDER SOBRE NOME ═══
';
    
    if (strpos($conteudo, 'FIX v5: RESPONDER SOBRE NOME') === false) {
        $marcador = '// ═══ FIX v5: CARREGAR NOME DO CLIENTE';
        if (strpos($conteudo, $marcador) !== false) {
            // Encontra o fim desse bloco
            $marcadorFim = '// ═══ FIM CARREGAR NOME ═══';
            if (strpos($conteudo, $marcadorFim) !== false) {
                $conteudo = str_replace($marcadorFim, $marcadorFim . "\n" . $fixPerguntaNome, $conteudo);
                echo "<p class='ok'>✅ Resposta sobre nome adicionada</p>";
            }
        }
    }
    
    // Salvar
    file_put_contents($onePath, $conteudo);
    
    // Verificar sintaxe
    $check = shell_exec("php -l $onePath 2>&1");
    $ok = strpos($check, 'No syntax errors') !== false;
    
    echo "</div>";
    
    if ($ok) {
        echo "<div class='card' style='border:2px solid #22c55e;text-align:center'>";
        echo "<h3 class='ok'>✅ FIX v5 APLICADO!</h3>";
        echo "<p>Agora a ONE sabe seu nome quando você está logado!</p>";
        echo "<p style='margin-top:16px'>";
        echo "<a href='one.php' class='btn'>💬 Testar ONE</a>";
        echo "</p>";
        echo "</div>";
    } else {
        echo "<div class='card' style='border:2px solid #ef4444'>";
        echo "<h3 class='erro'>❌ Erro de Sintaxe</h3>";
        echo "<pre>$check</pre>";
        echo "</div>";
    }
    
} else {
    
    echo "<div class='card' style='text-align:center'>";
    echo "<form method='post'>";
    echo "<p style='color:#888;margin-bottom:16px'>Vai fazer a ONE reconhecer seu nome automaticamente</p>";
    echo "<button type='submit' name='aplicar' class='btn'>🔧 APLICAR FIX v5</button>";
    echo "</form>";
    echo "</div>";
}

echo "</body></html>";
