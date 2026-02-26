<?php
/**
 * 🔬 ANÁLISE PENTE FINO - ONE.PHP
 * 
 * Documento completo com TODOS os problemas identificados
 * e correções necessárias
 * 
 * Gerado em: <?= date('d/m/Y H:i:s') ?>
 */

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Análise Pente Fino - ONE</title>";
echo "<style>
body{font-family:system-ui;background:#0a0a0a;color:#e5e5e5;padding:20px;max-width:1200px;margin:0 auto}
h1{color:#22c55e;text-align:center;font-size:28px}
h2{color:#3b82f6;border-bottom:2px solid #333;padding-bottom:10px;margin-top:40px}
h3{color:#eab308;margin-top:25px}
.card{background:#151515;border-radius:12px;padding:20px;margin:20px 0}
.critico{border-left:4px solid #ef4444;background:#1a0505}
.alto{border-left:4px solid #f97316;background:#1a0f05}
.medio{border-left:4px solid #eab308;background:#1a1505}
.baixo{border-left:4px solid #22c55e;background:#051a05}
.ok{color:#22c55e}.erro{color:#ef4444}.aviso{color:#eab308}
pre{background:#0a0a0a;padding:12px;border-radius:8px;font-size:11px;overflow-x:auto;border:1px solid #333}
code{background:#222;padding:2px 6px;border-radius:4px;font-size:12px}
table{width:100%;border-collapse:collapse;margin:15px 0}
td,th{padding:12px;border:1px solid #333;text-align:left}
th{background:#1a1a1a;color:#22c55e}
.linha{color:#888;font-size:11px}
.badge{display:inline-block;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:bold}
.badge-critico{background:#ef4444;color:#fff}
.badge-alto{background:#f97316;color:#fff}
.badge-medio{background:#eab308;color:#000}
.badge-baixo{background:#22c55e;color:#000}
.fix-code{background:#0f1f0f;border:1px solid #22c55e;border-radius:8px;padding:15px;margin:10px 0}
.problema-code{background:#1f0f0f;border:1px solid #ef4444;border-radius:8px;padding:15px;margin:10px 0}
.btn{background:#22c55e;color:#000;border:none;padding:14px 28px;border-radius:8px;cursor:pointer;font-weight:700;font-size:16px;display:inline-block;margin:8px}
.btn:hover{opacity:0.9}
.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin:20px 0}
.summary-item{background:#151515;border-radius:12px;padding:20px;text-align:center}
.summary-item h3{margin:0;font-size:32px}
.summary-item p{margin:5px 0 0;color:#888}
</style></head><body>";

echo "<h1>🔬 ANÁLISE PENTE FINO - ONE.PHP</h1>";
echo "<p style='text-align:center;color:#888'>Todos os problemas identificados e correções necessárias</p>";

// ═══════════════════════════════════════════════════════════════════════════════
// SUMÁRIO
// ═══════════════════════════════════════════════════════════════════════════════

echo "<div class='summary'>
    <div class='summary-item' style='border:2px solid #ef4444'>
        <h3 class='erro'>3</h3>
        <p>Críticos</p>
    </div>
    <div class='summary-item' style='border:2px solid #f97316'>
        <h3 style='color:#f97316'>4</h3>
        <p>Altos</p>
    </div>
    <div class='summary-item' style='border:2px solid #eab308'>
        <h3 style='color:#eab308'>5</h3>
        <p>Médios</p>
    </div>
    <div class='summary-item' style='border:2px solid #22c55e'>
        <h3 class='ok'>3</h3>
        <p>Baixos</p>
    </div>
</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// PROBLEMAS CRÍTICOS
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>🔴 PROBLEMAS CRÍTICOS</h2>";

// CRÍTICO 1
echo "<div class='card critico'>";
echo "<h3><span class='badge badge-critico'>CRÍTICO</span> #1: Variável \$cliente indefinida</h3>";
echo "<p class='linha'>Linha 8877</p>";
echo "<p>A variável <code>\$cliente</code> é usada mas NUNCA foi definida nesse escopo. Deveria ser <code>\$clienteLogado</code>.</p>";
echo "<div class='problema-code'><b>PROBLEMA:</b><pre>if (\$cliente) {  // ❌ \$cliente não existe!
    \$nomeCompleto = trim(\$cliente['firstname'] . ' ' . \$cliente['lastname']);
    \$resp = oneResposta('nome', ['nome' => \$nomeCompleto]);
}</pre></div>";
echo "<div class='fix-code'><b>CORREÇÃO:</b><pre>if (\$clienteLogado) {  // ✅ \$clienteLogado definido na linha 8701
    \$nomeCompleto = trim(\$clienteLogado['firstname'] . ' ' . \$clienteLogado['lastname']);
    \$resp = oneResposta('nome', ['nome' => \$nomeCompleto]);
}</pre></div>";
echo "<p><b>Impacto:</b> Quando pergunta \"você sabe meu nome?\" ou \"quem sou eu?\", SEMPRE retorna \"Ainda não sei seu nome\" porque \$cliente é null.</p>";
echo "</div>";

// CRÍTICO 2
echo "<div class='card critico'>";
echo "<h3><span class='badge badge-critico'>CRÍTICO</span> #2: Pattern \"quem sou eu\" incompleto</h3>";
echo "<p class='linha'>Linha 8875</p>";
echo "<p>O pattern para reconhecer \"quem sou eu\" está incompleto e não cobre variações comuns.</p>";
echo "<div class='problema-code'><b>PROBLEMA:</b><pre>preg_match('/(quem sou eu|quem eu sou)/i', \$msgLower)
// Não reconhece:
// - \"você sabe quem eu sou?\"
// - \"sabe quem sou?\"
// - \"quem sou eu?\" (com ?) às vezes falha</pre></div>";
echo "<div class='fix-code'><b>CORREÇÃO:</b><pre>// Adicionar mais patterns:
preg_match('/(quem sou eu|quem eu sou)/i', \$msgLower) ||
preg_match('/sabe quem (eu )?sou/i', \$msgLower) ||
preg_match('/conhece.*(eu|mim)/i', \$msgLower)</pre></div>";
echo "</div>";

// CRÍTICO 3
echo "<div class='card critico'>";
echo "<h3><span class='badge badge-critico'>CRÍTICO</span> #3: loadPerfil não busca nome do cadastro</h3>";
echo "<p class='linha'>Linha 5251-5257</p>";
echo "<p>A função <code>loadPerfil()</code> só busca de <code>om_one_cliente_perfil</code> que pode estar vazia. Deveria também buscar de <code>oc_customer</code>.</p>";
echo "<div class='problema-code'><b>PROBLEMA:</b><pre>private function loadPerfil() {
    \$stmt = \$this->pdo->prepare(\"SELECT * FROM om_one_cliente_perfil WHERE customer_id = ?\");
    // Se não existir registro, \$this->perfil fica vazio
    // E getNome() retorna null
}</pre></div>";
echo "<div class='fix-code'><b>CORREÇÃO:</b><pre>private function loadPerfil() {
    // Busca perfil ONE
    \$stmt = \$this->pdo->prepare(\"SELECT * FROM om_one_cliente_perfil WHERE customer_id = ?\");
    \$stmt->execute([\$this->customer_id]);
    \$this->perfil = \$stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    
    // Se não tem nome, busca do cadastro
    if (empty(\$this->perfil['nome'])) {
        \$stmtCliente = \$this->pdo->prepare(\"SELECT firstname FROM oc_customer WHERE customer_id = ?\");
        \$stmtCliente->execute([\$this->customer_id]);
        \$nome = \$stmtCliente->fetchColumn();
        if (\$nome) \$this->perfil['nome'] = \$nome;
    }
}</pre></div>";
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// PROBLEMAS ALTOS
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>🟠 PROBLEMAS ALTOS</h2>";

// ALTO 1
echo "<div class='card alto'>";
echo "<h3><span class='badge badge-alto'>ALTO</span> #4: Código duplicado - Busca de apelido</h3>";
echo "<p class='linha'>Linhas 8607-8620 e 8926-8939</p>";
echo "<p>O código de busca de apelido está DUPLICADO, executando a mesma query duas vezes por requisição.</p>";
echo "<div class='problema-code'><b>PROBLEMA:</b><pre>// Linha 8607 - PRIMEIRA VEZ
\$apelidoSalvo = null;
if (\$this->pdo && \$this->customer_id) {
    \$stmtApelido = \$this->pdo->prepare(\"SELECT valor FROM om_one_memoria_pessoal...\");
    ...
}

// Linha 8926 - SEGUNDA VEZ (mesma coisa!)
\$apelidoSalvo = null;
if (\$this->pdo && \$this->customer_id) {
    \$stmtApelido = \$this->pdo->prepare(\"SELECT valor FROM om_one_memoria_pessoal...\");
    ...
}</pre></div>";
echo "<p><b>Impacto:</b> Performance degradada, query executada desnecessariamente.</p>";
echo "</div>";

// ALTO 2
echo "<div class='card alto'>";
echo "<h3><span class='badge badge-alto'>ALTO</span> #5: Welcome message hardcoded</h3>";
echo "<p class='linha'>Linha 14488</p>";
echo "<p>A mensagem de boas-vindas está hardcoded no HTML e não usa o nome do cliente logado.</p>";
echo "<div class='problema-code'><b>PROBLEMA:</b><pre>&lt;div class=\"bubble\" id=\"welcomeMessage\"&gt;
    Oi! Estou te ouvindo... diga \"Oi One\" quando precisar de mim! 💚
&lt;/div&gt;</pre></div>";
echo "<div class='fix-code'><b>CORREÇÃO:</b><pre>// Usar PHP para personalizar:
&lt;?php
\$welcomeMsg = \"Oi\";
if (!empty(\$_SESSION['customer_name'])) {
    \$welcomeMsg .= \" \" . \$_SESSION['customer_name'];
}
\$welcomeMsg .= \"! Como posso te ajudar hoje? 💚\";
?&gt;
&lt;div class=\"bubble\" id=\"welcomeMessage\"&gt;&lt;?= \$welcomeMsg ?&gt;&lt;/div&gt;</pre></div>";
echo "</div>";

// ALTO 3
echo "<div class='card alto'>";
echo "<h3><span class='badge badge-alto'>ALTO</span> #6: Variável \$cliente usada em múltiplos lugares sem definição prévia</h3>";
echo "<p class='linha'>Linhas 9312, 9352, 9402</p>";
echo "<p>Em vários pontos do código, <code>\$cliente</code> é usado mas só é definido DEPOIS ou dentro de blocos específicos.</p>";
echo "<div class='problema-code'><b>Ocorrências:</b><pre>Linha 8877: if (\$cliente) - ❌ não definido
Linha 9312: \$cliente = \$this->carregarClienteCompleto(); - definido aqui
Linha 9352: \$cliente = \$this->carregarClienteCompleto(); - redefinido
Linha 9402: \$cliente = \$this->carregarClienteCompleto(); - redefinido de novo</pre></div>";
echo "<p><b>Impacto:</b> Inconsistência, carrega cliente múltiplas vezes.</p>";
echo "</div>";

// ALTO 4
echo "<div class='card alto'>";
echo "<h3><span class='badge badge-alto'>ALTO</span> #7: Dependência de arquivos externos não verificada</h3>";
echo "<p class='linha'>Linhas 2, 6, 7</p>";
echo "<p>O arquivo depende de <code>one_personalidade.php</code>, <code>one_pack5_inteligencia.php</code> e <code>one_pack6_upgrade.php</code> mas não verifica se existem.</p>";
echo "<div class='problema-code'><b>PROBLEMA:</b><pre>require_once __DIR__ . '/one_personalidade.php';
require_once __DIR__ . '/one_pack5_inteligencia.php';
require_once __DIR__ . '/one_pack6_upgrade.php';
// Se algum não existir = FATAL ERROR</pre></div>";
echo "<div class='fix-code'><b>CORREÇÃO:</b><pre>// Verificar existência:
\$deps = ['one_personalidade.php', 'one_pack5_inteligencia.php', 'one_pack6_upgrade.php'];
foreach (\$deps as \$dep) {
    if (file_exists(__DIR__ . '/' . \$dep)) {
        require_once __DIR__ . '/' . \$dep;
    }
}</pre></div>";
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// PROBLEMAS MÉDIOS
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>🟡 PROBLEMAS MÉDIOS</h2>";

// MEDIO 1
echo "<div class='card medio'>";
echo "<h3><span class='badge badge-medio'>MÉDIO</span> #8: Múltiplas chamadas a carregarClienteCompleto()</h3>";
echo "<p class='linha'>Várias linhas</p>";
echo "<p>A função <code>carregarClienteCompleto()</code> é chamada várias vezes na mesma requisição, fazendo queries repetidas.</p>";
echo "<pre>Linha 8617: \$clienteBase = \$this->carregarClienteCompleto();
Linha 8701: \$clienteLogado = \$this->carregarClienteCompleto();
Linha 8937: \$clienteInfo = \$this->carregarClienteCompleto();
Linha 9312: \$cliente = \$this->carregarClienteCompleto();
Linha 9352: \$cliente = \$this->carregarClienteCompleto();
Linha 9402: \$cliente = \$this->carregarClienteCompleto();</pre>";
echo "<p><b>Solução:</b> Cachear resultado em propriedade da classe.</p>";
echo "</div>";

// MEDIO 2
echo "<div class='card medio'>";
echo "<h3><span class='badge badge-medio'>MÉDIO</span> #9: Pattern de saudação muito simples</h3>";
echo "<p class='linha'>Linha 8858</p>";
echo "<p>O pattern de saudação é muito restritivo e pode não pegar variações.</p>";
echo "<pre>// Atual:
preg_match('/^(oi|olá|ola|eae|eai|opa|hey|oie?)[\s\!\.\,]*\$/i', \$msgLower)

// Não pega:
// - \"oi tudo bem\" (tem mais texto)
// - \"oiii\" (múltiplos i)
// - \"oie!\" com espaço antes</pre>";
echo "</div>";

// MEDIO 3
echo "<div class='card medio'>";
echo "<h3><span class='badge badge-medio'>MÉDIO</span> #10: Sem tratamento de erro em salvar()</h3>";
echo "<p class='linha'>Linha 5269-5298</p>";
echo "<p>A função <code>salvar()</code> não retorna feedback de sucesso/erro.</p>";
echo "</div>";

// MEDIO 4
echo "<div class='card medio'>";
echo "<h3><span class='badge badge-medio'>MÉDIO</span> #11: Sessão não inicializada explicitamente</h3>";
echo "<p>O código usa <code>\$_SESSION</code> sem verificar se a sessão está iniciada.</p>";
echo "<pre>// Deveria ter no início:
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}</pre>";
echo "</div>";

// MEDIO 5
echo "<div class='card medio'>";
echo "<h3><span class='badge badge-medio'>MÉDIO</span> #12: Mensagem de \"precisa login\" mesmo quando logado</h3>";
echo "<p class='linha'>Linha 8705-8726</p>";
echo "<p>O check de login pode falhar mesmo com usuário logado se <code>customer_id</code> não estiver na sessão.</p>";
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// PROBLEMAS BAIXOS
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>🟢 PROBLEMAS BAIXOS (Melhorias)</h2>";

echo "<div class='card baixo'>";
echo "<h3><span class='badge badge-baixo'>BAIXO</span> #13: Produtos hardcoded</h3>";
echo "<p>Lista de produtos está hardcoded no código (linhas 7200-7400+). Deveria vir do banco.</p>";
echo "</div>";

echo "<div class='card baixo'>";
echo "<h3><span class='badge badge-baixo'>BAIXO</span> #14: Muitos returns no processar()</h3>";
echo "<p>A função <code>processar()</code> tem 50+ pontos de return, dificultando manutenção.</p>";
echo "</div>";

echo "<div class='card baixo'>";
echo "<h3><span class='badge badge-baixo'>BAIXO</span> #15: Código comentado obsoleto</h3>";
echo "<p>Existem blocos de código comentado que deveriam ser removidos.</p>";
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// CORREÇÃO AUTOMÁTICA
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>⚡ APLICAR CORREÇÕES</h2>";

$onePath = __DIR__ . '/one.php';
$canFix = file_exists($onePath);

if (isset($_POST['aplicar_fix'])) {
    
    if (!$canFix) {
        echo "<p class='erro'>❌ Arquivo one.php não encontrado!</p>";
    } else {
        $conteudo = file_get_contents($onePath);
        $backup = $onePath . '.bkp_pentefino_' . date('Ymd_His');
        copy($onePath, $backup);
        
        echo "<div class='card'>";
        echo "<p class='ok'>✅ Backup criado: " . basename($backup) . "</p>";
        
        $fixes = 0;
        
        // FIX 1: $cliente → $clienteLogado na linha 8877
        $antes1 = 'if ($cliente) {
                    $nomeCompleto = trim($cliente[\'firstname\'] . \' \' . $cliente[\'lastname\']);';
        $depois1 = 'if ($clienteLogado) {
                    $nomeCompleto = trim($clienteLogado[\'firstname\'] . \' \' . $clienteLogado[\'lastname\']);';
        
        if (strpos($conteudo, $antes1) !== false) {
            $conteudo = str_replace($antes1, $depois1, $conteudo);
            echo "<p class='ok'>✅ FIX #1: \$cliente → \$clienteLogado</p>";
            $fixes++;
        }
        
        // FIX 2: Adicionar patterns "quem sou eu"
        $antes2 = "preg_match('/(quem sou eu|quem eu sou)/i', \$msgLower)) {";
        $depois2 = "preg_match('/(quem sou eu|quem eu sou)/i', \$msgLower) ||
                preg_match('/sabe quem (eu )?sou/i', \$msgLower) ||
                preg_match('/voce sabe quem/i', \$msgLower) ||
                preg_match('/vc sabe quem/i', \$msgLower)) {";
        
        if (strpos($conteudo, $antes2) !== false) {
            $conteudo = str_replace($antes2, $depois2, $conteudo);
            echo "<p class='ok'>✅ FIX #2: Patterns 'quem sou eu' expandidos</p>";
            $fixes++;
        }
        
        // FIX 3: Melhorar loadPerfil
        $antes3 = 'private function loadPerfil() {
            if (!$this->pdo) return;
            try {
                $stmt = $this->pdo->prepare("SELECT * FROM om_one_cliente_perfil WHERE customer_id = ?");
                $stmt->execute([$this->customer_id]);
                $this->perfil = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Exception $e) {}
        }';
        
        $depois3 = 'private function loadPerfil() {
            if (!$this->pdo) return;
            try {
                // Busca perfil ONE
                $stmt = $this->pdo->prepare("SELECT * FROM om_one_cliente_perfil WHERE customer_id = ?");
                $stmt->execute([$this->customer_id]);
                $this->perfil = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                
                // Se não tem nome no perfil, busca do cadastro OpenCart
                if (empty($this->perfil[\'nome\']) && $this->customer_id) {
                    $stmtCliente = $this->pdo->prepare("SELECT firstname, lastname FROM oc_customer WHERE customer_id = ?");
                    $stmtCliente->execute([$this->customer_id]);
                    $cliente = $stmtCliente->fetch(PDO::FETCH_ASSOC);
                    if ($cliente && !empty($cliente[\'firstname\'])) {
                        $this->perfil[\'nome\'] = $cliente[\'firstname\'];
                        $this->perfil[\'nome_completo\'] = trim($cliente[\'firstname\'] . \' \' . $cliente[\'lastname\']);
                        // Sincroniza com perfil ONE
                        try {
                            $this->pdo->prepare("INSERT INTO om_one_cliente_perfil (customer_id, nome) VALUES (?, ?) ON DUPLICATE KEY UPDATE nome = VALUES(nome)")
                                ->execute([$this->customer_id, $cliente[\'firstname\']]);
                        } catch (Exception $e) {}
                    }
                }
            } catch (Exception $e) {
                error_log(\'ONE loadPerfil error: \' . $e->getMessage());
            }
        }';
        
        if (strpos($conteudo, $antes3) !== false) {
            $conteudo = str_replace($antes3, $depois3, $conteudo);
            echo "<p class='ok'>✅ FIX #3: loadPerfil melhorado</p>";
            $fixes++;
        }
        
        // FIX 4: Remover duplicação de busca de apelido (linha 8926-8939)
        // Apenas a segunda ocorrência deve ser removida
        $duplicado = '// ═══ SISTEMA DE APELIDO ═══
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
            
            // Detectar pedido de apelido';
        
        $simplificado = '// ═══ SISTEMA DE APELIDO ═══
            // Usar variáveis já definidas no início do processar()
            // $apelidoSalvo e $nomeExibir já foram definidos nas linhas 8607-8620
            
            // Detectar pedido de apelido';
        
        // Conta ocorrências
        $count = substr_count($conteudo, '$apelidoSalvo = null;');
        if ($count > 1) {
            // Remove a segunda ocorrência
            $pos = strpos($conteudo, $duplicado);
            if ($pos !== false) {
                $conteudo = substr_replace($conteudo, $simplificado, $pos, strlen($duplicado));
                echo "<p class='ok'>✅ FIX #4: Código duplicado de apelido removido</p>";
                $fixes++;
            }
        }
        
        // Salvar
        file_put_contents($onePath, $conteudo);
        
        // Verificar sintaxe
        $check = shell_exec("php -l $onePath 2>&1");
        $sintaxeOk = strpos($check, 'No syntax errors') !== false;
        
        echo "</div>";
        
        if ($sintaxeOk) {
            echo "<div class='card' style='border:2px solid #22c55e;text-align:center'>";
            echo "<h2 class='ok'>✅ $fixes CORREÇÕES APLICADAS COM SUCESSO!</h2>";
            echo "<p><a href='one.php' class='btn'>💬 Testar ONE</a></p>";
            echo "</div>";
        } else {
            echo "<div class='card' style='border:2px solid #ef4444'>";
            echo "<h2 class='erro'>❌ Erro de Sintaxe!</h2>";
            echo "<pre>$check</pre>";
            echo "<p>Restaurando backup...</p>";
            copy($backup, $onePath);
            echo "<p class='ok'>✅ Backup restaurado</p>";
            echo "</div>";
        }
    }
    
} else {
    
    echo "<div class='card' style='text-align:center'>";
    echo "<p>Este script vai aplicar as correções dos problemas <b>CRÍTICOS</b> identificados:</p>";
    echo "<ul style='text-align:left;max-width:500px;margin:20px auto'>";
    echo "<li>FIX #1: \$cliente → \$clienteLogado</li>";
    echo "<li>FIX #2: Patterns 'quem sou eu' expandidos</li>";
    echo "<li>FIX #3: loadPerfil busca de oc_customer</li>";
    echo "<li>FIX #4: Remover código duplicado de apelido</li>";
    echo "</ul>";
    echo "<form method='post'>";
    echo "<button type='submit' name='aplicar_fix' class='btn' style='font-size:18px;padding:16px 40px'>⚡ APLICAR CORREÇÕES CRÍTICAS</button>";
    echo "</form>";
    echo "</div>";
    
}

// ═══════════════════════════════════════════════════════════════════════════════
// RESUMO FINAL
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>📋 Resumo Final</h2>";

echo "<div class='card'>";
echo "<table>
<tr>
    <th>Tipo</th>
    <th>Qtd</th>
    <th>Status</th>
</tr>
<tr>
    <td><span class='badge badge-critico'>CRÍTICO</span></td>
    <td>3</td>
    <td>Corrigíveis automaticamente</td>
</tr>
<tr>
    <td><span class='badge badge-alto'>ALTO</span></td>
    <td>4</td>
    <td>1 corrigível, 3 requerem refatoração</td>
</tr>
<tr>
    <td><span class='badge badge-medio'>MÉDIO</span></td>
    <td>5</td>
    <td>Melhorias de performance</td>
</tr>
<tr>
    <td><span class='badge badge-baixo'>BAIXO</span></td>
    <td>3</td>
    <td>Melhorias futuras</td>
</tr>
</table>";
echo "</div>";

echo "</body></html>";
