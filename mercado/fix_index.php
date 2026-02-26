<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * DIAGNÓSTICO E CORREÇÃO AUTOMÁTICA - OneMundo Mercado
 * ══════════════════════════════════════════════════════════════════════════════
 * 
 * Este script analisa o index.php e corrige problemas automaticamente
 * 
 * USO: Acesse /mercado/fix_index.php no navegador
 */

set_time_limit(300);
error_reporting(E_ALL);
ini_set('display_errors', 1);

$arquivo = __DIR__ . '/index.php';
$backup_dir = __DIR__ . '/backups';

// Criar pasta de backups
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

$acao = $_GET['acao'] ?? 'diagnostico';
$problemas = [];
$correcoes = [];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔧 Fix Index.php - OneMundo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        h1 { font-size: 28px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
        h2 { font-size: 18px; margin: 20px 0 10px; color: #94a3b8; }
        .card { background: #1e293b; border-radius: 12px; padding: 20px; margin-bottom: 16px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .card-title { font-size: 16px; font-weight: 600; }
        .badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-red { background: #ef4444; }
        .badge-yellow { background: #f59e0b; }
        .badge-green { background: #10b981; }
        .badge-blue { background: #3b82f6; }
        .problema { background: #7f1d1d; border-left: 4px solid #ef4444; padding: 12px 16px; margin: 8px 0; border-radius: 0 8px 8px 0; }
        .correcao { background: #14532d; border-left: 4px solid #10b981; padding: 12px 16px; margin: 8px 0; border-radius: 0 8px 8px 0; }
        .info { background: #1e3a5f; border-left: 4px solid #3b82f6; padding: 12px 16px; margin: 8px 0; border-radius: 0 8px 8px 0; }
        code { background: #0f172a; padding: 2px 8px; border-radius: 4px; font-family: 'Fira Code', monospace; font-size: 13px; }
        pre { background: #0f172a; padding: 16px; border-radius: 8px; overflow-x: auto; font-size: 13px; margin: 10px 0; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; border-radius: 8px; font-size: 14px; font-weight: 600; text-decoration: none; cursor: pointer; border: none; transition: all 0.2s; }
        .btn-primary { background: #10b981; color: white; }
        .btn-primary:hover { background: #059669; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; }
        .btn-secondary { background: #475569; color: white; }
        .btn-secondary:hover { background: #334155; }
        .actions { display: flex; gap: 12px; margin-top: 20px; flex-wrap: wrap; }
        .stat { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; background: #334155; border-radius: 8px; margin: 4px; }
        .stat-num { font-size: 20px; font-weight: 700; }
        .progress { height: 8px; background: #334155; border-radius: 4px; overflow: hidden; margin: 10px 0; }
        .progress-bar { height: 100%; background: #10b981; transition: width 0.3s; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #334155; }
        th { background: #0f172a; font-weight: 600; }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        .warning { color: #f59e0b; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔧 Diagnóstico e Correção - index.php</h1>

<?php

// ══════════════════════════════════════════════════════════════════════════════
// VERIFICAR SE ARQUIVO EXISTE
// ══════════════════════════════════════════════════════════════════════════════

if (!file_exists($arquivo)) {
    echo '<div class="card"><div class="problema">❌ Arquivo index.php não encontrado em: ' . $arquivo . '</div></div>';
    echo '</div></body></html>';
    exit;
}

$conteudo = file_get_contents($arquivo);
$tamanho = strlen($conteudo);
$linhas = substr_count($conteudo, "\n") + 1;

// ══════════════════════════════════════════════════════════════════════════════
// ANÁLISE DE PROBLEMAS
// ══════════════════════════════════════════════════════════════════════════════

// 1. Auth-guard duplicado
$auth_guard_count = substr_count($conteudo, "require_once 'auth-guard.php'");
$auth_guard_problema = preg_match_all("/<\?php\s*\n\s*require_once 'auth-guard\.php';\s*(if|else|endif|endforeach|endwhile|foreach|while|for|endfor|switch|case|default|break)/", $conteudo, $matches);

if ($auth_guard_problema > 0) {
    $problemas[] = [
        'tipo' => 'CRÍTICO',
        'titulo' => 'Auth-guard injetado incorretamente',
        'descricao' => "Encontrado $auth_guard_problema ocorrências de require_once 'auth-guard.php' antes de comandos PHP (if, else, foreach, etc)",
        'quantidade' => $auth_guard_problema
    ];
}

// 2. Funções duplicadas
$funcoes_duplicadas = [];
preg_match_all('/function\s+(\w+)\s*\(/', $conteudo, $funcoes);
$contagem_funcoes = array_count_values($funcoes[1]);
foreach ($contagem_funcoes as $func => $count) {
    if ($count > 1) {
        $funcoes_duplicadas[$func] = $count;
    }
}

if (!empty($funcoes_duplicadas)) {
    $problemas[] = [
        'tipo' => 'ERRO',
        'titulo' => 'Funções JavaScript duplicadas',
        'descricao' => 'Funções definidas mais de uma vez: ' . implode(', ', array_keys($funcoes_duplicadas)),
        'detalhes' => $funcoes_duplicadas
    ];
}

// 3. Variáveis CSS duplicadas
$css_vars_duplicadas = [];
preg_match_all('/--([a-zA-Z0-9-]+)\s*:/', $conteudo, $css_vars);
$contagem_css = array_count_values($css_vars[1]);
foreach ($contagem_css as $var => $count) {
    if ($count > 5) { // Mais de 5 é suspeito
        $css_vars_duplicadas[$var] = $count;
    }
}

if (!empty($css_vars_duplicadas)) {
    $problemas[] = [
        'tipo' => 'AVISO',
        'titulo' => 'Variáveis CSS possivelmente duplicadas',
        'descricao' => 'Variáveis definidas muitas vezes',
        'detalhes' => $css_vars_duplicadas
    ];
}

// 4. Scripts duplicados
$scripts_externos = [];
preg_match_all('/<script[^>]+src=["\']([^"\']+)["\']/', $conteudo, $scripts);
$contagem_scripts = array_count_values($scripts[1]);
foreach ($contagem_scripts as $script => $count) {
    if ($count > 1) {
        $scripts_externos[$script] = $count;
    }
}

if (!empty($scripts_externos)) {
    $problemas[] = [
        'tipo' => 'AVISO',
        'titulo' => 'Scripts externos duplicados',
        'descricao' => 'Scripts carregados mais de uma vez',
        'detalhes' => $scripts_externos
    ];
}

// 5. Blocos <style> duplicados
$style_count = substr_count($conteudo, '<style');
if ($style_count > 10) {
    $problemas[] = [
        'tipo' => 'AVISO',
        'titulo' => 'Muitos blocos <style>',
        'descricao' => "Encontrados $style_count blocos <style>. Considere unificar.",
        'quantidade' => $style_count
    ];
}

// 6. Session start duplicado
$session_starts = substr_count($conteudo, 'session_start()');
if ($session_starts > 3) {
    $problemas[] = [
        'tipo' => 'AVISO',
        'titulo' => 'session_start() chamado múltiplas vezes',
        'descricao' => "Encontrados $session_starts chamadas de session_start()",
        'quantidade' => $session_starts
    ];
}

// 7. Verificar addToCart
$addToCart_defs = substr_count($conteudo, 'function addToCart');
$addToCart_calls = substr_count($conteudo, 'addToCart(');

if ($addToCart_defs > 1) {
    $problemas[] = [
        'tipo' => 'ERRO',
        'titulo' => 'addToCart definido múltiplas vezes',
        'descricao' => "A função addToCart está definida $addToCart_defs vezes",
        'quantidade' => $addToCart_defs
    ];
}

// 8. Verificar saveCart
$saveCart_defs = substr_count($conteudo, 'function saveCart');
if ($saveCart_defs > 1) {
    $problemas[] = [
        'tipo' => 'ERRO',
        'titulo' => 'saveCart definido múltiplas vezes',
        'descricao' => "A função saveCart está definida $saveCart_defs vezes",
        'quantidade' => $saveCart_defs
    ];
}

// 9. Verificar originalAddToCart (problema de sobrescrita)
$originalAddToCart = substr_count($conteudo, 'originalAddToCart');
if ($originalAddToCart > 2) {
    $problemas[] = [
        'tipo' => 'ERRO',
        'titulo' => 'addToCart sendo sobrescrito múltiplas vezes',
        'descricao' => "Encontrados $originalAddToCart referências a originalAddToCart - função sendo sobrescrita",
        'quantidade' => $originalAddToCart
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
// EXIBIR DIAGNÓSTICO
// ══════════════════════════════════════════════════════════════════════════════

if ($acao === 'diagnostico') {
    echo '<div class="card">';
    echo '<div class="card-header"><span class="card-title">📊 Informações do Arquivo</span></div>';
    echo '<div class="stat"><span>📄 Tamanho:</span><span class="stat-num">' . number_format($tamanho / 1024, 1) . ' KB</span></div>';
    echo '<div class="stat"><span>📝 Linhas:</span><span class="stat-num">' . number_format($linhas) . '</span></div>';
    echo '<div class="stat"><span>🔧 Auth-guard:</span><span class="stat-num">' . $auth_guard_count . 'x</span></div>';
    echo '<div class="stat"><span>➕ addToCart:</span><span class="stat-num">' . $addToCart_defs . ' def / ' . $addToCart_calls . ' calls</span></div>';
    echo '</div>';

    if (empty($problemas)) {
        echo '<div class="card"><div class="correcao">✅ Nenhum problema encontrado! O arquivo está OK.</div></div>';
    } else {
        echo '<div class="card">';
        echo '<div class="card-header"><span class="card-title">🚨 Problemas Encontrados (' . count($problemas) . ')</span></div>';
        
        foreach ($problemas as $p) {
            $badge_class = $p['tipo'] === 'CRÍTICO' ? 'badge-red' : ($p['tipo'] === 'ERRO' ? 'badge-yellow' : 'badge-blue');
            echo '<div class="problema">';
            echo '<span class="badge ' . $badge_class . '">' . $p['tipo'] . '</span> ';
            echo '<strong>' . $p['titulo'] . '</strong><br>';
            echo '<small>' . $p['descricao'] . '</small>';
            if (isset($p['detalhes'])) {
                echo '<pre>' . print_r($p['detalhes'], true) . '</pre>';
            }
            echo '</div>';
        }
        echo '</div>';

        echo '<div class="actions">';
        echo '<a href="?acao=corrigir" class="btn btn-primary">🔧 Corrigir Automaticamente</a>';
        echo '<a href="?acao=backup" class="btn btn-secondary">💾 Apenas Backup</a>';
        echo '</div>';
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// EXECUTAR CORREÇÕES
// ══════════════════════════════════════════════════════════════════════════════

if ($acao === 'corrigir') {
    // Backup primeiro
    $backup_file = $backup_dir . '/index_' . date('Y-m-d_H-i-s') . '.php';
    copy($arquivo, $backup_file);
    $correcoes[] = "✅ Backup criado: " . basename($backup_file);

    $conteudo_novo = $conteudo;
    $alteracoes = 0;

    // CORREÇÃO 1: Remover auth-guard injetado incorretamente
    $pattern = "/<\?php\s*\nrequire_once 'auth-guard\.php'; /";
    $conteudo_novo = preg_replace($pattern, "<?php ", $conteudo_novo, -1, $count1);
    if ($count1 > 0) {
        $correcoes[] = "✅ Removido $count1 injeções de auth-guard";
        $alteracoes += $count1;
    }

    // CORREÇÃO 2: Remover sobrescritas de addToCart duplicadas
    // Manter apenas a primeira definição de originalAddToCart
    $pattern2 = '/\/\/ Melhorar função addToCart existente\s*\nconst originalAddToCart = window\.addToCart;\s*\nwindow\.addToCart = function\([^)]*\) \{[^}]+\};?/s';
    
    // Contar ocorrências
    preg_match_all($pattern2, $conteudo_novo, $matches_over);
    $overwrite_count = count($matches_over[0]);
    
    if ($overwrite_count > 1) {
        // Remover todas menos a primeira
        $first = true;
        $conteudo_novo = preg_replace_callback($pattern2, function($match) use (&$first) {
            if ($first) {
                $first = false;
                return $match[0]; // Manter a primeira
            }
            return ''; // Remover as demais
        }, $conteudo_novo);
        $correcoes[] = "✅ Removido " . ($overwrite_count - 1) . " sobrescritas duplicadas de addToCart";
        $alteracoes += ($overwrite_count - 1);
    }

    // CORREÇÃO 3: Limpar linhas em branco excessivas (mais de 3 seguidas)
    $conteudo_novo = preg_replace("/\n{4,}/", "\n\n\n", $conteudo_novo, -1, $count3);
    if ($count3 > 0) {
        $correcoes[] = "✅ Limpou $count3 blocos de linhas em branco excessivas";
    }

    // Salvar se houve alterações
    if ($alteracoes > 0) {
        file_put_contents($arquivo, $conteudo_novo);
        
        echo '<div class="card">';
        echo '<div class="card-header"><span class="card-title">✅ Correções Aplicadas</span></div>';
        foreach ($correcoes as $c) {
            echo '<div class="correcao">' . $c . '</div>';
        }
        echo '<div class="info">📊 Total de alterações: ' . $alteracoes . '</div>';
        echo '</div>';

        // Estatísticas após correção
        $novo_tamanho = strlen($conteudo_novo);
        $reducao = $tamanho - $novo_tamanho;
        
        echo '<div class="card">';
        echo '<div class="card-header"><span class="card-title">📊 Resultado</span></div>';
        echo '<table>';
        echo '<tr><th>Métrica</th><th>Antes</th><th>Depois</th><th>Diferença</th></tr>';
        echo '<tr><td>Tamanho</td><td>' . number_format($tamanho/1024, 1) . ' KB</td><td>' . number_format($novo_tamanho/1024, 1) . ' KB</td><td class="success">-' . number_format($reducao/1024, 1) . ' KB</td></tr>';
        echo '<tr><td>Auth-guard</td><td>' . $auth_guard_count . '</td><td>' . substr_count($conteudo_novo, "require_once 'auth-guard.php'") . '</td><td class="success">-' . $count1 . '</td></tr>';
        echo '</table>';
        echo '</div>';
    } else {
        echo '<div class="card"><div class="info">ℹ️ Nenhuma correção necessária.</div></div>';
    }

    echo '<div class="actions">';
    echo '<a href="?acao=diagnostico" class="btn btn-secondary">🔍 Ver Diagnóstico</a>';
    echo '<a href="/mercado/" class="btn btn-primary">🏠 Ir para Mercado</a>';
    echo '</div>';
}

// ══════════════════════════════════════════════════════════════════════════════
// APENAS BACKUP
// ══════════════════════════════════════════════════════════════════════════════

if ($acao === 'backup') {
    $backup_file = $backup_dir . '/index_' . date('Y-m-d_H-i-s') . '.php';
    if (copy($arquivo, $backup_file)) {
        echo '<div class="card"><div class="correcao">✅ Backup criado com sucesso!<br><code>' . $backup_file . '</code></div></div>';
    } else {
        echo '<div class="card"><div class="problema">❌ Erro ao criar backup</div></div>';
    }
    
    echo '<div class="actions">';
    echo '<a href="?acao=diagnostico" class="btn btn-secondary">🔍 Ver Diagnóstico</a>';
    echo '</div>';
}

// ══════════════════════════════════════════════════════════════════════════════
// LISTAR BACKUPS
// ══════════════════════════════════════════════════════════════════════════════

$backups = glob($backup_dir . '/index_*.php');
if (!empty($backups)) {
    rsort($backups);
    echo '<div class="card">';
    echo '<div class="card-header"><span class="card-title">💾 Backups Disponíveis</span></div>';
    echo '<table>';
    echo '<tr><th>Arquivo</th><th>Data</th><th>Tamanho</th></tr>';
    foreach (array_slice($backups, 0, 5) as $b) {
        echo '<tr>';
        echo '<td><code>' . basename($b) . '</code></td>';
        echo '<td>' . date('d/m/Y H:i:s', filemtime($b)) . '</td>';
        echo '<td>' . number_format(filesize($b)/1024, 1) . ' KB</td>';
        echo '</tr>';
    }
    echo '</table>';
    echo '</div>';
}

?>

</div>
</body>
</html>
