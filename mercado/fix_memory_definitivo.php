<?php
/**
 * 🔧 FIX DEFINITIVO - ONE Memory v8
 * Este script substitui a função bugada diretamente no arquivo
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Fix Memory v8</title>";
echo "<style>body{font-family:sans-serif;background:#111;color:#eee;padding:40px}pre{background:#222;padding:15px;border-radius:8px;overflow-x:auto}.ok{color:#30d158}.err{color:#ff453a}</style></head><body>";

$file = __DIR__ . '/one_memory_v8.php';

if (!file_exists($file)) {
    die("<p class='err'>❌ Arquivo one_memory_v8.php não encontrado!</p>");
}

echo "<h2>🔧 Fix Definitivo - ONE Memory v8</h2>";

// Lê o conteúdo atual
$content = file_get_contents($file);

// ═══════════════════════════════════════════════════════════════════════════
// PADRÃO ANTIGO (BUGADO) - várias possibilidades
// ═══════════════════════════════════════════════════════════════════════════

$patterns = [
    // Padrão 1: versão original com "sou|"
    "/(sou|trabalho como|minha profissão é)/",
    // Padrão 2: pode ter espaços diferentes
    "/\(sou\|trabalho como/",
];

$found = false;
foreach ($patterns as $p) {
    if (preg_match($p, $content)) {
        $found = true;
        break;
    }
}

if ($found) {
    echo "<p class='err'>🐛 Bug encontrado! Corrigindo...</p>";
    
    // Remove toda a seção de profissão antiga e substitui pela nova
    $oldPattern = '/\/\/ Profissão.*?if \(preg_match\(.*?sou\|trabalho como.*?\}\s*\}/s';
    
    $newCode = '// Profissão - APENAS quando usa "trabalho como" ou "minha profissão é"
        if (preg_match(\'/(trabalho como|minha profissão é|trabalho de) ([^.,!?]+)/i\', $msg, $matches)) {
            $profissao = trim($matches[2]);
            if (strlen($profissao) > 2 && strlen($profissao) < 50) {
                $memorias[] = [
                    \'tipo\' => \'fato\',
                    \'categoria\' => \'trabalho\',
                    \'conteudo\' => "Cliente trabalha como $profissao",
                    \'importancia\' => 5,
                ];
            }
        }
        
        // "Sou [profissão]" - só aceita profissões conhecidas
        if (preg_match(\'/\\bsou (médico|medico|professor|programador|desenvolvedor|engenheiro|advogado|dentista|enfermeiro|contador|arquiteto|designer|vendedor|gerente|analista|técnico|motorista|cozinheiro|chef|farmacêutico|nutricionista|padeiro|açougueiro|eletricista|pedreiro|pintor|mecânico|garçom|atendente|recepcionista|secretária|auxiliar|assistente|consultor|psicólogo|fisioterapeuta|veterinário|jornalista|escritor|músico|artista|ator|fotógrafo|cabeleireiro|barbeiro|manicure|esteticista|personal|instrutor|educador)\\b/i\', $msg, $matches)) {
            $profissao = trim($matches[1]);
            $memorias[] = [
                \'tipo\' => \'fato\',
                \'categoria\' => \'trabalho\',
                \'conteudo\' => "Cliente trabalha como $profissao",
                \'importancia\' => 5,
            ];
        }';
    
    $content = preg_replace($oldPattern, $newCode, $content);
    
} else {
    echo "<p>Verificando se já está correto...</p>";
}

// ═══════════════════════════════════════════════════════════════════════════
// CORREÇÃO MAIS SIMPLES E DIRETA
// ═══════════════════════════════════════════════════════════════════════════

// Se ainda tem o padrão bugado, faz substituição simples
if (strpos($content, '(sou|trabalho como|minha profissão é)') !== false) {
    $content = str_replace(
        '(sou|trabalho como|minha profissão é)',
        '(trabalho como|minha profissão é|trabalho de)',
        $content
    );
    echo "<p class='ok'>✅ Regex de profissão corrigida (método 1)</p>";
}

// Remove qualquer regex que capture "sou" seguido de qualquer coisa como profissão
if (preg_match('/preg_match.*?sou\|trabalho/', $content)) {
    $content = preg_replace(
        '/preg_match\s*\(\s*[\'"]\/\(sou\|/',
        'preg_match(\'/(', 
        $content
    );
    echo "<p class='ok'>✅ Regex corrigida (método 2)</p>";
}

// ═══════════════════════════════════════════════════════════════════════════
// SALVAR
// ═══════════════════════════════════════════════════════════════════════════

// Backup
$backup = $file . '.bak_' . date('His');
copy($file, $backup);
echo "<p class='ok'>✅ Backup criado: " . basename($backup) . "</p>";

// Salva
if (file_put_contents($file, $content)) {
    echo "<p class='ok'>✅ Arquivo salvo com sucesso!</p>";
} else {
    echo "<p class='err'>❌ Erro ao salvar! Tentando com permissões...</p>";
    
    // Tenta chmod
    chmod($file, 0666);
    if (file_put_contents($file, $content)) {
        echo "<p class='ok'>✅ Arquivo salvo após chmod!</p>";
    } else {
        echo "<p class='err'>❌ Não foi possível salvar. Execute:</p>";
        echo "<pre>sudo chmod 666 $file</pre>";
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// TESTE
// ═══════════════════════════════════════════════════════════════════════════

echo "<h3>🧪 Teste</h3>";

// Recarrega o arquivo
include_once $file;

if (class_exists('OneMemoryV8')) {
    // Cria nova instância para testar
    $reflection = new ReflectionClass('OneMemoryV8');
    $instance = $reflection->newInstanceWithoutConstructor();
    
    // Usa reflection para chamar método privado
    $method = $reflection->getMethod('extrairMemoriasDaMensagem');
    
    // Testa
    $memory = OneMemoryV8::getInstance();
    
    $testes = [
        "Sou alérgico a amendoim" => "NÃO deve ter profissão",
        "Trabalho como programador" => "DEVE ter profissão",
        "Sou programador" => "DEVE ter profissão (lista conhecida)",
        "Sou vegetariano" => "NÃO deve ter profissão",
    ];
    
    echo "<table style='width:100%;border-collapse:collapse'>";
    echo "<tr style='background:#333'><th style='padding:10px;text-align:left'>Teste</th><th>Esperado</th><th>Resultado</th><th>Status</th></tr>";
    
    foreach ($testes as $msg => $esperado) {
        $mems = $memory->extrairMemoriasDaMensagem($msg, null, 'fix_test_' . time() . rand(1000,9999));
        
        $temProfissao = false;
        $categorias = [];
        foreach ($mems as $m) {
            $categorias[] = $m['categoria'];
            if ($m['categoria'] === 'trabalho') {
                $temProfissao = true;
            }
        }
        
        $deveTerProfissao = strpos($esperado, 'DEVE ter') !== false && strpos($esperado, 'NÃO') === false;
        $passou = ($deveTerProfissao === $temProfissao);
        
        $status = $passou ? "<span class='ok'>✅ OK</span>" : "<span class='err'>❌ FALHOU</span>";
        $resultado = $temProfissao ? "Tem profissão" : "Sem profissão";
        $categorias_str = implode(', ', $categorias);
        
        echo "<tr style='border-bottom:1px solid #333'>";
        echo "<td style='padding:10px'>\"$msg\"</td>";
        echo "<td>$esperado</td>";
        echo "<td>$resultado<br><small>[$categorias_str]</small></td>";
        echo "<td>$status</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<br><p>✅ Correção finalizada!</p>";
echo "<p><a href='instalar_integrado_v8.php?acao=teste' style='color:#007aff'>→ Testar sistema completo</a></p>";
echo "<p style='color:#888'>Delete este arquivo: <code>rm " . basename(__FILE__) . "</code></p>";

echo "</body></html>";
