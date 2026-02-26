<?php
/**
 * 🔧 FIX COMPLETO - Corrigir detector e adicionar funções
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');

echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>🔧 Fix Completo</title>
    <style>
        body { font-family: "Segoe UI", sans-serif; background: #0f172a; color: #e2e8f0; padding: 40px; }
        .container { max-width: 800px; margin: 0 auto; }
        h1 { color: #10b981; }
        .card { background: #1e293b; border-radius: 12px; padding: 24px; margin: 20px 0; }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        pre { background: #0f172a; padding: 16px; border-radius: 8px; overflow-x: auto; font-size: 12px; }
        .btn { background: #10b981; color: white; border: none; padding: 14px 28px; border-radius: 8px; cursor: pointer; font-size: 16px; }
    </style>
</head>
<body>
<div class="container">';

echo '<h1>🔧 Fix Completo - Fase 1</h1>';

$onePath = __DIR__ . '/one.php';
$conteudo = file_get_contents($onePath);

// Verificar problemas
echo '<div class="card">';
echo '<h2>🔍 Problemas Encontrados</h2>';
echo '<p class="error">1. detectarIntencao() existente retorna STRING, não ARRAY</p>';
echo '<p class="error">2. carregarClienteCompleto() não existe</p>';
echo '<p class="error">3. salvarContexto() não existe</p>';
echo '</div>';

if (isset($_POST['aplicar'])) {
    
    // Backup
    $backup = $onePath . '.backup_fix_' . date('Y-m-d_H-i-s');
    file_put_contents($backup, $conteudo);
    
    echo '<div class="card">';
    echo '<h2>⚙️ Aplicando Fix...</h2>';
    echo '<pre>';
    echo "✅ Backup: $backup\n\n";
    
    // ═══════════════════════════════════════════════════════════════════════════
    // FIX 1: Renomear chamada do detector para usar nova versão
    // ═══════════════════════════════════════════════════════════════════════════
    
    echo "1️⃣ Corrigindo chamada do detector...\n";
    
    // Trocar detectarIntencao por detectarIntencaoUniversal no código novo
    $conteudo = str_replace(
        '$intencaoDetectada = $this->detectarIntencao($msg);',
        '$intencaoDetectada = $this->detectarIntencaoUniversal($msg);',
        $conteudo
    );
    echo "✅ Chamada corrigida para detectarIntencaoUniversal()\n\n";
    
    // ═══════════════════════════════════════════════════════════════════════════
    // FIX 2: Adicionar funções faltantes
    // ═══════════════════════════════════════════════════════════════════════════
    
    echo "2️⃣ Adicionando funções faltantes...\n";
    
    $funcoesFaltantes = '
    // ═══════════════════════════════════════════════════════════════════════════
    // 🚀 ONE UNIVERSAL - FUNÇÕES FASE 1
    // ═══════════════════════════════════════════════════════════════════════════
    
    private function detectarIntencaoUniversal($mensagem) {
        $msg = mb_strtolower(trim($mensagem), \'UTF-8\');
        $r = [\'intencao\' => \'conversa\', \'confianca\' => 0.5, \'entidades\' => []];
        
        // VIAGEM
        if (preg_match(\'/(quero|vou|preciso|bora).*(viajar|ir pra|ir para|passagem|voo|hotel)/i\', $msg) ||
            preg_match(\'/(miami|orlando|paris|cancun|nova york|europa|lisboa|madrid|roma|dubai|passagem|voo|hotel|viagem|aeroporto)/i\', $msg)) {
            $r[\'intencao\'] = \'viagem\'; 
            $r[\'confianca\'] = 0.9;
            if (preg_match(\'/(miami|orlando|nova york|paris|cancun|lisboa|madrid|roma|dubai|rio|salvador|fortaleza|recife)/i\', $msg, $m))
                $r[\'entidades\'][\'destino\'] = ucwords($m[0]);
            return $r;
        }
        
        // CORRIDA
        if (preg_match(\'/(corrida|motorista|me busca|buscar|transporte|levar pro aeroporto|uber|99)/i\', $msg)) {
            $r[\'intencao\'] = \'corrida\'; 
            $r[\'confianca\'] = 0.85;
            if (preg_match(\'/(aeroporto|rodoviária|shopping|hospital|trabalho)/i\', $msg, $m))
                $r[\'entidades\'][\'destino\'] = $m[0];
            return $r;
        }
        
        // ECOMMERCE
        if (preg_match(\'/(notebook|celular|iphone|samsung|tv|televisão|geladeira|fone|playstation|xbox|tablet|ipad)/i\', $msg)) {
            $r[\'intencao\'] = \'ecommerce\'; 
            $r[\'confianca\'] = 0.85;
            if (preg_match(\'/(notebook|celular|iphone|samsung|tv|geladeira|fone|playstation|xbox|tablet|ipad)/i\', $msg, $m))
                $r[\'entidades\'][\'produto\'] = $m[0];
            return $r;
        }
        
        return $r;
    }
    
    private function carregarClienteCompleto() {
        if (!$this->pdo || !$this->customer_id) return null;
        try {
            $stmt = $this->pdo->prepare("SELECT *, CONCAT(firstname, \' \', lastname) as nome_completo FROM oc_customer WHERE customer_id = ?");
            $stmt->execute([$this->customer_id]);
            $c = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$c) return null;
            
            // Endereços
            $stmt = $this->pdo->prepare("SELECT * FROM oc_address WHERE customer_id = ?");
            $stmt->execute([$this->customer_id]);
            $c[\'enderecos\'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Cartões
            try {
                $stmt = $this->pdo->prepare("SELECT * FROM oc_om_customer_cards WHERE customer_id = ? AND status = '1'");
                $stmt->execute([$this->customer_id]);
                $c[\'cartoes\'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { 
                $c[\'cartoes\'] = []; 
            }
            
            return $c;
        } catch (Exception $e) { 
            return null; 
        }
    }
    
    private function salvarContexto($intencao, $etapa = null, $dados = []) {
        if (!$this->pdo || !$this->customer_id) return false;
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM om_one_contexto WHERE customer_id = ? AND status = \'ativo\'");
            $stmt->execute([$this->customer_id]);
            $ex = $stmt->fetch();
            if ($ex) {
                $stmt = $this->pdo->prepare("UPDATE om_one_contexto SET intencao_atual=?, etapa_atual=?, dados_contexto=? WHERE id=?");
                $stmt->execute([$intencao, $etapa, json_encode($dados), $ex[\'id\']]);
            } else {
                $stmt = $this->pdo->prepare("INSERT INTO om_one_contexto (customer_id, session_id, intencao_atual, etapa_atual, dados_contexto) VALUES (?,?,?,?,?)");
                $stmt->execute([$this->customer_id, session_id(), $intencao, $etapa, json_encode($dados)]);
            }
            return true;
        } catch (Exception $e) { 
            return false; 
        }
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // FIM FUNÇÕES FASE 1
    // ═══════════════════════════════════════════════════════════════════════════

';

    // Verificar se já existem
    if (strpos($conteudo, 'function detectarIntencaoUniversal') === false) {
        // Inserir antes de salvarNoBrainUniversal ou no final da classe
        $marcador = 'private function salvarNoBrainUniversal';
        
        if (strpos($conteudo, $marcador) !== false) {
            $conteudo = str_replace($marcador, $funcoesFaltantes . "\n    " . $marcador, $conteudo);
            echo "✅ Funções inseridas antes de salvarNoBrainUniversal()\n\n";
        } else {
            echo "❌ Não encontrou local para inserir funções\n\n";
        }
    } else {
        echo "⚠️ Funções já existem\n\n";
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // SALVAR
    // ═══════════════════════════════════════════════════════════════════════════
    
    file_put_contents($onePath, $conteudo);
    echo "✅ one.php atualizado!\n";
    
    echo '</pre></div>';
    
    echo '<div class="card" style="border-color:#10b981;">';
    echo '<h2 class="success">✅ Fix Aplicado!</h2>';
    echo '<p>Agora teste:</p>';
    echo '<p style="margin-top:16px;">';
    echo '<a href="one.php?action=send&message=quero%20ir%20pra%20miami" style="color:#10b981;display:block;margin:8px 0;" target="_blank">🧪 Testar: "quero ir pra miami"</a>';
    echo '<a href="one.php?action=send&message=oi" style="color:#3b82f6;display:block;margin:8px 0;" target="_blank">🧪 Testar: "oi" (fluxo normal)</a>';
    echo '</p>';
    echo '</div>';
    
} else {
    
    echo '<div class="card">';
    echo '<h2>🔧 O que este fix faz:</h2>';
    echo '<ol style="margin:16px 0;padding-left:24px;line-height:2;">';
    echo '<li>Cria <code>detectarIntencaoUniversal()</code> - retorna ARRAY com intenção e entidades</li>';
    echo '<li>Cria <code>carregarClienteCompleto()</code> - pega dados do cliente OpenCart</li>';
    echo '<li>Cria <code>salvarContexto()</code> - salva contexto da conversa</li>';
    echo '<li>Corrige a chamada no código do detector</li>';
    echo '</ol>';
    echo '<form method="post" style="margin-top:20px;"><button type="submit" name="aplicar" class="btn">🔧 APLICAR FIX</button></form>';
    echo '</div>';
}

echo '</div></body></html>';
