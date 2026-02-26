<?php
/**
 * 🔧 FIX AGRESSIVO - ONE Memory v8
 * Reescreve completamente a seção de profissão
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Fix Agressivo</title>";
echo "<style>body{font-family:sans-serif;background:#111;color:#eee;padding:40px}pre{background:#222;padding:15px;border-radius:8px;overflow-x:auto;font-size:12px}.ok{color:#30d158}.err{color:#ff453a}code{background:#333;padding:2px 6px;border-radius:4px}</style></head><body>";

$file = __DIR__ . '/one_memory_v8.php';

if (!file_exists($file)) {
    die("<p class='err'>❌ Arquivo não encontrado!</p>");
}

echo "<h2>🔧 Fix Agressivo - Profissão</h2>";

$content = file_get_contents($file);

// Mostra o que tem atualmente
echo "<h4>📄 Conteúdo atual (seção profissão):</h4>";
if (preg_match('/\/\/ Profissão.*?\/\/ Pets/s', $content, $matches)) {
    echo "<pre>" . htmlspecialchars(substr($matches[0], 0, 1500)) . "...</pre>";
}

// ═══════════════════════════════════════════════════════════════════════════
// REMOVE TODA A SEÇÃO DE PROFISSÃO E SUBSTITUI
// ═══════════════════════════════════════════════════════════════════════════

// Padrão para encontrar toda a seção de profissão (do comentário até antes de Pets)
$pattern = '/\/\/ Profissão[^\n]*\n.*?(?=\/\/ Pets)/s';

$replacement = '// Profissão - CORRIGIDO: só aceita "trabalho como" ou profissões específicas
        // NÃO pega mais "sou alérgico", "sou vegetariano", etc.
        if (preg_match(\'/(trabalho como|minha profissão é|trabalho de) ([a-zA-Zà-úÀ-Ú ]+)/i\', $msg, $matches)) {
            $profissao = trim($matches[2]);
            // Ignora se contém palavras que não são profissões
            $ignorar = [\'alérgico\', \'alergico\', \'vegetariano\', \'vegano\', \'intolerante\', \'casado\', \'solteiro\'];
            $valido = true;
            foreach ($ignorar as $palavra) {
                if (stripos($profissao, $palavra) !== false) {
                    $valido = false;
                    break;
                }
            }
            if ($valido && strlen($profissao) > 2 && strlen($profissao) < 50) {
                $memorias[] = [
                    \'tipo\' => \'fato\',
                    \'categoria\' => \'trabalho\',
                    \'conteudo\' => "Cliente trabalha como $profissao",
                    \'importancia\' => 5,
                ];
            }
        }
        
        // "Sou [profissão]" - APENAS profissões da lista
        $profissoes_validas = \'médico|medico|professor|programador|desenvolvedor|engenheiro|advogado|dentista|enfermeiro|contador|arquiteto|designer|vendedor|gerente|analista|técnico|motorista|cozinheiro|chef|farmacêutico|nutricionista|padeiro|açougueiro|eletricista|pedreiro|pintor|mecânico|garçom|atendente|recepcionista|secretária|auxiliar|assistente|consultor|psicólogo|fisioterapeuta|veterinário|jornalista|escritor|músico|artista|ator|fotógrafo|cabeleireiro|barbeiro|manicure|esteticista|personal|instrutor|educador\';
        if (preg_match(\'/^sou (\'.$profissoes_validas.\')$/i\', trim($msg), $matches)) {
            $memorias[] = [
                \'tipo\' => \'fato\',
                \'categoria\' => \'trabalho\',
                \'conteudo\' => "Cliente trabalha como " . trim($matches[1]),
                \'importancia\' => 5,
            ];
        }
        
        ';

$newContent = preg_replace($pattern, $replacement, $content);

if ($newContent === $content) {
    echo "<p class='err'>⚠️ Padrão não encontrado. Tentando método alternativo...</p>";
    
    // Método alternativo: busca linha por linha
    $lines = explode("\n", $content);
    $newLines = [];
    $skipUntilPets = false;
    $addedNew = false;
    
    foreach ($lines as $line) {
        // Começa a pular quando encontra "// Profissão"
        if (strpos($line, '// Profissão') !== false && !$addedNew) {
            $skipUntilPets = true;
            // Adiciona a nova versão
            $newLines[] = '        // Profissão - CORRIGIDO v2';
            $newLines[] = '        if (preg_match(\'/(trabalho como|minha profissão é|trabalho de) ([a-zA-Zà-úÀ-Ú ]+)/i\', $msg, $matches)) {';
            $newLines[] = '            $profissao = trim($matches[2]);';
            $newLines[] = '            $ignorar = [\'alérgico\', \'alergico\', \'vegetariano\', \'vegano\', \'intolerante\', \'casado\', \'solteiro\'];';
            $newLines[] = '            $valido = true;';
            $newLines[] = '            foreach ($ignorar as $palavra) {';
            $newLines[] = '                if (stripos($profissao, $palavra) !== false) { $valido = false; break; }';
            $newLines[] = '            }';
            $newLines[] = '            if ($valido && strlen($profissao) > 2 && strlen($profissao) < 50) {';
            $newLines[] = '                $memorias[] = [\'tipo\' => \'fato\', \'categoria\' => \'trabalho\', \'conteudo\' => "Cliente trabalha como $profissao", \'importancia\' => 5];';
            $newLines[] = '            }';
            $newLines[] = '        }';
            $newLines[] = '';
            $addedNew = true;
            continue;
        }
        
        // Para de pular quando encontra "// Pets"
        if (strpos($line, '// Pets') !== false) {
            $skipUntilPets = false;
        }
        
        // Adiciona a linha se não estiver pulando
        if (!$skipUntilPets) {
            $newLines[] = $line;
        }
    }
    
    $newContent = implode("\n", $newLines);
}

// Backup
$backup = $file . '.bak2_' . date('His');
copy($file, $backup);
echo "<p class='ok'>✅ Backup: " . basename($backup) . "</p>";

// Salva
if (file_put_contents($file, $newContent)) {
    echo "<p class='ok'>✅ Arquivo salvo!</p>";
} else {
    chmod($file, 0666);
    file_put_contents($file, $newContent);
    echo "<p class='ok'>✅ Arquivo salvo (após chmod)</p>";
}

// ═══════════════════════════════════════════════════════════════════════════
// TESTE
// ═══════════════════════════════════════════════════════════════════════════

echo "<h3>🧪 Teste Final</h3>";

// Limpa cache do PHP
if (function_exists('opcache_reset')) {
    opcache_reset();
}

// Força recarregar
$testCode = file_get_contents($file);
$testFile = __DIR__ . '/test_memory_temp_' . time() . '.php';
file_put_contents($testFile, $testCode);

// Renomeia a classe para evitar conflito
$testCode = str_replace('class OneMemoryV8', 'class OneMemoryV8Test', $testCode);
$testCode = str_replace('OneMemoryV8::', 'OneMemoryV8Test::', $testCode);
file_put_contents($testFile, $testCode);

include_once $testFile;

if (class_exists('OneMemoryV8Test')) {
    $memory = OneMemoryV8Test::getInstance();
    
    $testes = [
        "Sou alérgico a amendoim",
        "Trabalho como programador", 
        "Sou programador",
        "Sou vegetariano",
        "Minha profissão é médico",
    ];
    
    echo "<table style='width:100%;border-collapse:collapse'>";
    echo "<tr style='background:#333'><th style='padding:10px'>Mensagem</th><th>Categorias</th><th>Status</th></tr>";
    
    foreach ($testes as $msg) {
        $mems = $memory->extrairMemoriasDaMensagem($msg, null, 'test_' . rand(1000,9999));
        
        $categorias = array_column($mems, 'categoria');
        $temTrabalho = in_array('trabalho', $categorias);
        
        // Verifica se está correto
        $deveSerTrabalho = (stripos($msg, 'trabalho') !== false || stripos($msg, 'profissão') !== false || $msg === 'Sou programador');
        $naoDeveSerTrabalho = (stripos($msg, 'alérgico') !== false || stripos($msg, 'vegetariano') !== false);
        
        if ($naoDeveSerTrabalho) {
            $correto = !$temTrabalho;
        } else {
            $correto = $deveSerTrabalho ? $temTrabalho : true;
        }
        
        $status = $correto ? "<span class='ok'>✅</span>" : "<span class='err'>❌</span>";
        
        echo "<tr style='border-bottom:1px solid #333'>";
        echo "<td style='padding:10px'>\"$msg\"</td>";
        echo "<td>[" . implode(', ', $categorias) . "]</td>";
        echo "<td>$status</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Remove arquivo temporário
    unlink($testFile);
} else {
    echo "<p class='err'>Erro ao carregar classe de teste</p>";
}

echo "<h4>📄 Nova seção de profissão:</h4>";
$newFileContent = file_get_contents($file);
if (preg_match('/\/\/ Profissão.*?(?=\/\/ Pets)/s', $newFileContent, $matches)) {
    echo "<pre>" . htmlspecialchars($matches[0]) . "</pre>";
}

echo "<br><p><a href='instalar_integrado_v8.php?acao=teste' style='color:#007aff'>→ Testar sistema completo</a></p>";
echo "<p style='color:#888'>Delete: <code>rm " . basename(__FILE__) . "</code></p>";
echo "</body></html>";
