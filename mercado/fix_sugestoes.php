<?php
/**
 * 🔧 FIX - Sugestões Contextuais Inteligentes
 * 
 * Problema: Botões "Primeiro, Segundo, Terceiro" aparecem sem contexto
 * Solução: Sugestões só aparecem quando fazem sentido
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔧 Fix Sugestões</h1>";
echo "<style>body{font-family:sans-serif;background:#0f172a;color:#e2e8f0;padding:40px;}pre{background:#1e293b;padding:16px;border-radius:8px;overflow-x:auto;font-size:11px;max-height:400px;}.btn{background:#10b981;color:white;padding:16px 32px;border:none;border-radius:12px;cursor:pointer;font-size:18px;}.success{color:#10b981;}.error{color:#ef4444;}</style>";

$onePath = __DIR__ . '/one.php';
$conteudo = file_get_contents($onePath);

echo "<h2>Problema:</h2>";
echo "<p>Botões 'Primeiro, Segundo, Terceiro' aparecem sem contexto quando a ONE fala 'opções'.</p>";

echo "<h2>Solução:</h2>";
echo "<p>Sugestões só aparecem quando a ONE realmente oferece opções numeradas (1., 2., 3.)</p>";

if (isset($_GET['fix'])) {
    
    // Backup
    copy($onePath, $onePath . '.bkp_sugestoes_' . time());
    echo "<p class='success'>✅ Backup</p>";
    
    // Nova função de sugestões - mais inteligente
    $funcaoAntiga = "    // Analyze response and show contextual suggestions
    function analyzeAndSuggest(response, userMessage) {
        const lower = response.toLowerCase();
        const userLower = userMessage.toLowerCase();
        let suggestions = [];
        
        // Se perguntou sobre produtos/opções
        if (lower.includes('qual você') || lower.includes('qual quer') || lower.includes('opções')) {
            suggestions = [
                { icon: '1️⃣', label: 'Primeiro', text: '1' },
                { icon: '2️⃣', label: 'Segundo', text: '2' },
                { icon: '3️⃣', label: 'Terceiro', text: '3' }
            ];
        }
        // Se perguntou se quer finalizar
        else if (lower.includes('mando?') || lower.includes('mando entregar') || lower.includes('finalizar')) {
            suggestions = [
                { icon: '✅', label: 'Sim, manda!', text: 'Sim, pode mandar!' },
                { icon: '➕', label: 'Adicionar mais', text: 'Quero adicionar mais coisas' },
                { icon: '🛒', label: 'Ver carrinho', text: 'Ver meu carrinho' }
            ];
        }
        // Se pedido foi confirmado
        else if (lower.includes('pedido confirmado') || lower.includes('preparado')) {
            suggestions = [
                { icon: '🍳', label: 'Ver receitas', text: 'Me sugere uma receita' },
                { icon: '🛒', label: 'Comprar mais', text: 'Quero comprar mais coisas' }
            ];
        }
        // Se falou de receita
        else if (lower.includes('receita') || lower.includes('ingredientes') || userLower.includes('fazer')) {
            suggestions = [
                { icon: '📝', label: 'Ver receita', text: 'Me passa a receita completa' },
                { icon: '🛒', label: 'Comprar ingredientes', text: 'Comprar os ingredientes' }
            ];
        }
        // Carrinho vazio
        else if (lower.includes('carrinho') && lower.includes('vazio')) {
            suggestions = [
                { icon: '🥚', label: 'Ovos', text: 'Preciso de ovos' },
                { icon: '🍚', label: 'Arroz', text: 'Preciso de arroz' },
                { icon: '🥛', label: 'Leite', text: 'Preciso de leite' }
            ];
        }
        
        showSuggestions(suggestions);
    }";
    
    $funcaoNova = "    // Analyze response and show contextual suggestions
    function analyzeAndSuggest(response, userMessage) {
        const lower = response.toLowerCase();
        const userLower = userMessage.toLowerCase();
        let suggestions = [];
        
        // Só mostra opções numéricas se a ONE realmente listou opções (1. 2. 3. ou 1) 2) 3))
        const temOpcoes = response.match(/[1-3][\.\)]\s+\w/) || 
                          response.match(/opção\s*[1-3]/i) ||
                          response.match(/primeira.*segunda.*terceira/i);
        
        if (temOpcoes) {
            // Extrai os nomes das opções se possível
            const opcoes = response.match(/[1-3][\.\)]\s*([^\n\r]+)/g);
            if (opcoes && opcoes.length >= 2) {
                suggestions = opcoes.slice(0, 3).map((opt, i) => {
                    const nome = opt.replace(/^[1-3][\.\)]\s*/, '').substring(0, 25);
                    return { icon: ['1️⃣','2️⃣','3️⃣'][i], label: nome, text: String(i+1) };
                });
            } else {
                suggestions = [
                    { icon: '1️⃣', label: 'Primeira', text: '1' },
                    { icon: '2️⃣', label: 'Segunda', text: '2' },
                    { icon: '3️⃣', label: 'Terceira', text: '3' }
                ];
            }
        }
        // Perguntou se quer finalizar/confirmar
        else if (lower.includes('confirma') || lower.includes('mando?') || lower.includes('fecha o pedido')) {
            suggestions = [
                { icon: '✅', label: 'Confirmar', text: 'Sim, confirma!' },
                { icon: '✏️', label: 'Alterar', text: 'Quero alterar' }
            ];
        }
        // Perguntou sim/não
        else if (lower.includes('quer que eu') || lower.includes('posso ') || lower.includes('você quer')) {
            suggestions = [
                { icon: '👍', label: 'Sim', text: 'Sim' },
                { icon: '👎', label: 'Não', text: 'Não' }
            ];
        }
        // Pedido confirmado
        else if (lower.includes('pedido confirmado') || lower.includes('tá feito')) {
            suggestions = [
                { icon: '🍳', label: 'Receita', text: 'Me sugere uma receita' },
                { icon: '🛒', label: 'Comprar mais', text: 'Quero comprar mais' }
            ];
        }
        // Falou de viagem
        else if (lower.includes('viagem') || lower.includes('passagem') || lower.includes('destino')) {
            // Não mostra sugestões genéricas - deixa o usuário falar
        }
        // NÃO mostra sugestões para conversas normais
        // Só mostra quando realmente faz sentido
        
        showSuggestions(suggestions);
    }";
    
    if (strpos($conteudo, $funcaoAntiga) !== false) {
        $conteudo = str_replace($funcaoAntiga, $funcaoNova, $conteudo);
        echo "<p class='success'>✅ Função atualizada</p>";
    } else {
        // Tentar substituição parcial
        $antigoSimples = "if (lower.includes('qual você') || lower.includes('qual quer') || lower.includes('opções')) {";
        $novoSimples = "// Só mostra opções numéricas se a ONE realmente listou opções
        const temOpcoes = response.match(/[1-3][\\.\\)]\\s+\\w/) || response.match(/opção\\s*[1-3]/i);
        if (temOpcoes) {";
        
        if (strpos($conteudo, $antigoSimples) !== false) {
            $conteudo = str_replace($antigoSimples, $novoSimples, $conteudo);
            echo "<p class='success'>✅ Condição atualizada</p>";
        } else {
            echo "<p class='error'>❌ Padrão não encontrado</p>";
        }
    }
    
    file_put_contents($onePath, $conteudo);
    
    // Verificar sintaxe
    $check = shell_exec("php -l $onePath 2>&1");
    if (strpos($check, 'No syntax errors') !== false) {
        echo "<h2 class='success'>✅ FIX APLICADO!</h2>";
        echo "<p>Agora as sugestões só aparecem quando fazem sentido.</p>";
        echo "<p><a href='one.php' style='color:#10b981;font-size:18px;'>💚 Testar ONE</a></p>";
    } else {
        echo "<p class='error'>❌ Erro de sintaxe</p>";
        echo "<pre>$check</pre>";
    }
    
} else {
    echo "<p style='margin-top:30px;'><a href='?fix=1' class='btn'>🔧 APLICAR FIX</a></p>";
}
