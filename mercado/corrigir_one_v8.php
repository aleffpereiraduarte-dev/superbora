<?php
/**
 * 🔧 CORREÇÃO RÁPIDA - ONE v8
 * 
 * Corrige:
 * 1. Warning session_start no brain_v6
 * 2. Bug de profissão no memory_v8
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>🔧 Correções ONE v8</h2>";

$basePath = __DIR__;

// ═══════════════════════════════════════════════════════════════════════════
// CORREÇÃO 1: session_start no brain_v6
// ═══════════════════════════════════════════════════════════════════════════

$brainPath = $basePath . '/one_brain_v6.php';

if (file_exists($brainPath)) {
    $content = file_get_contents($brainPath);
    
    // Verifica se já tem a correção
    if (strpos($content, 'session_status()') === false) {
        // Substitui session_start() por versão segura
        $content = str_replace(
            'session_start();',
            'if (session_status() === PHP_SESSION_NONE) { session_start(); }',
            $content
        );
        
        file_put_contents($brainPath, $content);
        echo "<p style='color:#30d158'>✅ brain_v6: session_start corrigido</p>";
    } else {
        echo "<p style='color:#ffd60a'>⚠️ brain_v6: já está corrigido</p>";
    }
} else {
    echo "<p style='color:#ff453a'>❌ brain_v6: arquivo não encontrado</p>";
}

// ═══════════════════════════════════════════════════════════════════════════
// CORREÇÃO 2: Bug de profissão no memory_v8
// ═══════════════════════════════════════════════════════════════════════════

$memoryPath = $basePath . '/one_memory_v8.php';

if (file_exists($memoryPath)) {
    $content = file_get_contents($memoryPath);
    
    // Verifica se tem o bug (regex antiga de profissão)
    if (strpos($content, "/(sou|trabalho como|minha profissão é) (.*?)") !== false) {
        
        // Substitui a regex antiga pela nova corrigida
        $old = <<<'OLD'
        // Profissão
        if (preg_match('/(sou|trabalho como|minha profissão é) (.*?)(\.|$|,)/i', $msg, $matches)) {
            $profissao = trim($matches[2]);
            if (strlen($profissao) > 2 && strlen($profissao) < 50) {
                $memorias[] = [
                    'tipo' => 'fato',
                    'categoria' => 'trabalho',
                    'conteudo' => "Cliente trabalha como $profissao",
                    'importancia' => 5,
                ];
            }
        }
OLD;

        $new = <<<'NEW'
        // Profissão (evita pegar "sou alérgico", "sou vegetariano", etc)
        if (preg_match('/(trabalho como|minha profissão é|trabalho de) (.*?)(\.|$|,)/i', $msg, $matches)) {
            $profissao = trim($matches[2]);
            // Lista de palavras que NÃO são profissões
            $naoProfissao = ['alérgico', 'alergico', 'vegetariano', 'vegano', 'intolerante', 'casado', 'solteiro', 'divorciado'];
            $ehProfissao = true;
            foreach ($naoProfissao as $palavra) {
                if (stripos($profissao, $palavra) !== false) {
                    $ehProfissao = false;
                    break;
                }
            }
            if ($ehProfissao && strlen($profissao) > 2 && strlen($profissao) < 50) {
                $memorias[] = [
                    'tipo' => 'fato',
                    'categoria' => 'trabalho',
                    'conteudo' => "Cliente trabalha como $profissao",
                    'importancia' => 5,
                ];
            }
        }
NEW;

        $content = str_replace($old, $new, $content);
        file_put_contents($memoryPath, $content);
        echo "<p style='color:#30d158'>✅ memory_v8: bug de profissão corrigido</p>";
        
    } else {
        echo "<p style='color:#ffd60a'>⚠️ memory_v8: já está corrigido ou estrutura diferente</p>";
    }
} else {
    echo "<p style='color:#ff453a'>❌ memory_v8: arquivo não encontrado</p>";
}

// ═══════════════════════════════════════════════════════════════════════════
// TESTE RÁPIDO
// ═══════════════════════════════════════════════════════════════════════════

echo "<h3>🧪 Teste Rápido</h3>";

// Carrega memory
if (file_exists($memoryPath)) {
    include_once $memoryPath;
    
    if (class_exists('OneMemoryV8')) {
        $memory = OneMemoryV8::getInstance();
        
        $testes = [
            "Sou alérgico a amendoim",
            "Trabalho como programador",
            "Minha mãe se chama Ana",
        ];
        
        echo "<table style='width:100%;border-collapse:collapse'>";
        echo "<tr style='background:#222'><th style='padding:10px;text-align:left'>Mensagem</th><th style='padding:10px;text-align:left'>Memórias Extraídas</th></tr>";
        
        foreach ($testes as $msg) {
            $mems = $memory->extrairMemoriasDaMensagem($msg, null, 'teste_correcao_' . time());
            $extracted = [];
            foreach ($mems as $m) {
                $extracted[] = "[{$m['categoria']}] {$m['conteudo']}";
            }
            $extractedStr = count($extracted) > 0 ? implode('<br>', $extracted) : '-';
            
            echo "<tr style='border-bottom:1px solid #333'>";
            echo "<td style='padding:10px'>\"$msg\"</td>";
            echo "<td style='padding:10px'>$extractedStr</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
}

echo "<br><p style='color:#888'>✅ Correções aplicadas! Pode deletar este arquivo.</p>";
echo "<p><code>rm " . basename(__FILE__) . "</code></p>";
