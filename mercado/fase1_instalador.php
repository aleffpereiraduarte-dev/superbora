<?php
require_once __DIR__ . '/config/database.php';
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * 🚀 ONE UNIVERSAL - FASE 1 - INSTALADOR + PATCH
 * ═══════════════════════════════════════════════════════════════════════════════
 * 
 * Este instalador:
 * 1. Cria as tabelas necessárias
 * 2. Adiciona funções no one.php existente (não cria arquivo novo)
 * 
 * ═══════════════════════════════════════════════════════════════════════════════
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);

header('Content-Type: text/html; charset=utf-8');

echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>🚀 ONE Universal - Fase 1</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Segoe UI", sans-serif; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #e2e8f0; min-height: 100vh; padding: 40px; }
        .container { max-width: 900px; margin: 0 auto; }
        h1 { text-align: center; font-size: 2.5rem; margin-bottom: 10px; background: linear-gradient(135deg, #10b981, #3b82f6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .subtitle { text-align: center; color: #64748b; margin-bottom: 40px; }
        .card { background: rgba(30, 41, 59, 0.8); border: 1px solid rgba(71, 85, 105, 0.5); border-radius: 16px; padding: 24px; margin: 20px 0; }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        .warning { color: #f59e0b; }
        pre { background: #0f172a; padding: 16px; border-radius: 8px; overflow-x: auto; font-size: 13px; margin: 10px 0; white-space: pre-wrap; }
        .btn { background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; padding: 16px 32px; border-radius: 12px; cursor: pointer; font-size: 18px; font-weight: 600; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 40px rgba(16, 185, 129, 0.3); }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; }
        .stat { background: rgba(59, 130, 246, 0.1); border-radius: 12px; padding: 16px; text-align: center; }
        .stat-value { font-size: 1.8rem; font-weight: bold; color: #3b82f6; }
        .stat-label { color: #94a3b8; font-size: 0.85rem; }
    </style>
</head>
<body>
<div class="container">';

echo '<h1>🚀 ONE Universal - Fase 1</h1>';
echo '<p class="subtitle">Instalador + Patch para one.php</p>';

// Conexão
try {
    $pdo = getPDO();
} catch (Exception $e) {
    die('<div class="card"><p class="error">❌ Erro de conexão: ' . $e->getMessage() . '</p></div>');
}

$onePath = __DIR__ . '/one.php';

// ═══════════════════════════════════════════════════════════════════════════════
// ANÁLISE
// ═══════════════════════════════════════════════════════════════════════════════

echo '<div class="card">';
echo '<h2 style="color:#10b981;margin-bottom:16px;">📊 Análise do Sistema</h2>';
echo '<div class="grid">';

$totalClientes = $pdo->query("SELECT COUNT(*) FROM oc_customer")->fetchColumn();
echo "<div class='stat'><div class='stat-value'>" . number_format($totalClientes, 0, ',', '.') . "</div><div class='stat-label'>👤 Clientes</div></div>";

$totalBrain = $pdo->query("SELECT COUNT(*) FROM om_one_brain_universal")->fetchColumn();
echo "<div class='stat'><div class='stat-value'>" . number_format($totalBrain, 0, ',', '.') . "</div><div class='stat-label'>🧠 Brain</div></div>";

if (file_exists($onePath)) {
    $oneSize = round(filesize($onePath) / 1024);
    echo "<div class='stat'><div class='stat-value'>{$oneSize}KB</div><div class='stat-label'>📄 one.php</div></div>";
}

$tabelaContexto = $pdo->query("SHOW TABLES LIKE 'om_one_contexto'")->fetch();
$statusTabelas = $tabelaContexto ? '✅' : '⏳';
echo "<div class='stat'><div class='stat-value'>$statusTabelas</div><div class='stat-label'>📦 Tabelas</div></div>";

echo '</div></div>';

// ═══════════════════════════════════════════════════════════════════════════════
// VERIFICAR one.php
// ═══════════════════════════════════════════════════════════════════════════════

echo '<div class="card">';
echo '<h2 style="color:#10b981;margin-bottom:16px;">🔍 Verificar one.php</h2>';

if (!file_exists($onePath)) {
    die('<p class="error">❌ one.php não encontrado!</p></div>');
}

$conteudo = file_get_contents($onePath);

$checks = [
    'detectarIntencao' => strpos($conteudo, 'function detectarIntencao') !== false,
    'carregarClienteCompleto' => strpos($conteudo, 'function carregarClienteCompleto') !== false,
    'salvarContexto' => strpos($conteudo, 'function salvarContexto') !== false,
    'criarEvento' => strpos($conteudo, 'function criarEvento') !== false,
];

foreach ($checks as $funcao => $existe) {
    $status = $existe ? "<span class='success'>✅ Já existe</span>" : "<span class='warning'>⏳ Será adicionada</span>";
    echo "<p>$funcao(): $status</p>";
}

$precisaPatch = !$checks['detectarIntencao'];

echo '</div>';

// ═══════════════════════════════════════════════════════════════════════════════
// INSTALAR
// ═══════════════════════════════════════════════════════════════════════════════

if (isset($_POST['instalar'])) {
    
    echo '<div class="card">';
    echo '<h2 style="color:#10b981;">⚙️ Instalando Fase 1...</h2>';
    echo '<pre>';
    
    $erros = 0;
    
    // PARTE 1: TABELAS
    echo "═══════════════════════════════════════════════════════════\n";
    echo "📦 CRIANDO TABELAS\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS om_one_contexto (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_id INT NOT NULL,
                session_id VARCHAR(100),
                intencao_atual VARCHAR(50),
                etapa_atual VARCHAR(100),
                dados_contexto JSON,
                status ENUM('ativo','finalizado') DEFAULT 'ativo',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_customer (customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        echo "✅ om_one_contexto\n";
    } catch (Exception $e) { echo "❌ " . $e->getMessage() . "\n"; $erros++; }
    
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS om_one_eventos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_id INT NOT NULL,
                tipo VARCHAR(50) NOT NULL,
                titulo VARCHAR(255) NOT NULL,
                mensagem TEXT,
                dados JSON,
                agendar_para DATETIME NOT NULL,
                status ENUM('pendente','enviado','cancelado') DEFAULT 'pendente',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_customer (customer_id),
                INDEX idx_agendar (agendar_para)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        echo "✅ om_one_eventos\n";
    } catch (Exception $e) { echo "❌ " . $e->getMessage() . "\n"; $erros++; }
    
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS om_one_cliente_preferencias (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_id INT NOT NULL UNIQUE,
                viagem_prioridade ENUM('preco','tempo','conforto') DEFAULT 'preco',
                viagem_assento ENUM('janela','corredor','tanto_faz') DEFAULT 'tanto_faz',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_customer (customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        echo "✅ om_one_cliente_preferencias\n";
    } catch (Exception $e) { echo "❌ " . $e->getMessage() . "\n"; $erros++; }
    
    // PARTE 2: PATCH
    echo "\n═══════════════════════════════════════════════════════════\n";
    echo "🔧 PATCH NO one.php\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    
    if (!$precisaPatch) {
        echo "✅ Funções já existem no one.php!\n";
    } else {
        $backup = $onePath . '.backup_fase1_' . date('Y-m-d_H-i-s');
        file_put_contents($backup, $conteudo);
        echo "✅ Backup: $backup\n\n";
        
        $novasFuncoes = '
    // ═══════════════════════════════════════════════════════════════════════════
    // 🚀 ONE UNIVERSAL - FASE 1
    // ═══════════════════════════════════════════════════════════════════════════
    
    private function detectarIntencao($mensagem) {
        $msg = mb_strtolower(trim($mensagem), \'UTF-8\');
        $r = [\'intencao\' => \'conversa\', \'confianca\' => 0.5, \'entidades\' => []];
        
        // VIAGEM
        if (preg_match(\'/(quero|vou|preciso|bora).*(viajar|ir pra|passagem|voo|hotel)/i\', $msg) ||
            preg_match(\'/(miami|orlando|paris|cancun|nova york|europa|passagem|voo|hotel|viagem)/i\', $msg)) {
            $r[\'intencao\'] = \'viagem\'; $r[\'confianca\'] = 0.9;
            if (preg_match(\'/(miami|orlando|nova york|paris|cancun|lisboa|madrid|roma|dubai|rio|salvador)/i\', $msg, $m))
                $r[\'entidades\'][\'destino\'] = ucwords($m[0]);
            return $r;
        }
        
        // MERCADO
        if (preg_match(\'/(preciso|quero|falta|acabou).*(arroz|feijão|carne|leite|pão|café)/i\', $msg) ||
            preg_match(\'/(mercado|supermercado|feira)/i\', $msg)) {
            $r[\'intencao\'] = \'mercado\'; $r[\'confianca\'] = 0.9;
            preg_match_all(\'/(arroz|feijão|carne|leite|pão|café|ovo|banana|tomate|cebola)/i\', $msg, $m);
            if (!empty($m[0])) $r[\'entidades\'][\'produtos\'] = array_unique($m[0]);
            return $r;
        }
        
        // ECOMMERCE
        if (preg_match(\'/(notebook|celular|iphone|samsung|tv|geladeira|fone|playstation|xbox)/i\', $msg)) {
            $r[\'intencao\'] = \'ecommerce\'; $r[\'confianca\'] = 0.85;
            if (preg_match(\'/(notebook|celular|iphone|samsung|tv|geladeira|fone|playstation)/i\', $msg, $m))
                $r[\'entidades\'][\'produto\'] = $m[0];
            return $r;
        }
        
        // CORRIDA
        if (preg_match(\'/(corrida|motorista|me busca|buscar|transporte|levar pro aeroporto)/i\', $msg)) {
            $r[\'intencao\'] = \'corrida\'; $r[\'confianca\'] = 0.85;
            if (preg_match(\'/(aeroporto|rodoviária|shopping|hospital)/i\', $msg, $m))
                $r[\'entidades\'][\'destino\'] = $m[0];
            return $r;
        }
        
        // RECEITA
        if (preg_match(\'/(receita|como fazer|como faz|ensina|cozinhar)/i\', $msg)) {
            $r[\'intencao\'] = \'receita\'; $r[\'confianca\'] = 0.9;
            if (preg_match(\'/(bolo|lasanha|strogonoff|macarrão|pizza|salada|sopa|frango|carne)/i\', $msg, $m))
                $r[\'entidades\'][\'prato\'] = $m[0];
            return $r;
        }
        
        // SAUDAÇÃO
        if (preg_match(\'/^(oi|olá|eae|bom dia|boa tarde|boa noite|oie|opa)\s*[\!\.\?]*$/i\', $msg)) {
            $r[\'intencao\'] = \'saudacao\'; $r[\'confianca\'] = 0.95; return $r;
        }
        
        // COMO VAI
        if (preg_match(\'/(tudo bem|como vai|como você está|beleza|suave)/i\', $msg)) {
            $r[\'intencao\'] = \'como_vai\'; $r[\'confianca\'] = 0.9; return $r;
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
            
            $stmt = $this->pdo->prepare("SELECT * FROM oc_address WHERE customer_id = ?");
            $stmt->execute([$this->customer_id]);
            $c[\'enderecos\'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            try {
                $stmt = $this->pdo->prepare("SELECT * FROM oc_om_customer_cards WHERE customer_id = ? AND status = '1'");
                $stmt->execute([$this->customer_id]);
                $c[\'cartoes\'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $c[\'cartoes\'] = []; }
            
            return $c;
        } catch (Exception $e) { return null; }
    }
    
    private function getEnderecoPrincipal() {
        $c = $this->carregarClienteCompleto();
        if (!$c || empty($c[\'enderecos\'])) return null;
        foreach ($c[\'enderecos\'] as $e) if ($e[\'address_id\'] == $c[\'address_id\']) return $e;
        return $c[\'enderecos\'][0];
    }
    
    private function getCartaoPrincipal() {
        $c = $this->carregarClienteCompleto();
        if (!$c || empty($c[\'cartoes\'])) return null;
        foreach ($c[\'cartoes\'] as $cart) if (!empty($cart[\'is_default\'])) return $cart;
        return $c[\'cartoes\'][0];
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
        } catch (Exception $e) { return false; }
    }
    
    private function carregarContexto() {
        if (!$this->pdo || !$this->customer_id) return null;
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM om_one_contexto WHERE customer_id = ? AND status = \'ativo\' LIMIT 1");
            $stmt->execute([$this->customer_id]);
            $ctx = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($ctx && $ctx[\'dados_contexto\']) $ctx[\'dados_contexto\'] = json_decode($ctx[\'dados_contexto\'], true);
            return $ctx;
        } catch (Exception $e) { return null; }
    }
    
    private function criarEvento($tipo, $titulo, $msg, $quando, $dados = []) {
        if (!$this->pdo || !$this->customer_id) return false;
        try {
            $stmt = $this->pdo->prepare("INSERT INTO om_one_eventos (customer_id, tipo, titulo, mensagem, agendar_para, dados) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$this->customer_id, $tipo, $titulo, $msg, $quando, json_encode($dados)]);
            return $this->pdo->lastInsertId();
        } catch (Exception $e) { return false; }
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // FIM FASE 1
    // ═══════════════════════════════════════════════════════════════════════════

';
        
        // Encontrar onde inserir
        $marcadores = ['private function salvarNoBrainUniversal', 'public function status()'];
        $inserido = false;
        
        foreach ($marcadores as $marcador) {
            if (strpos($conteudo, $marcador) !== false) {
                $conteudo = str_replace($marcador, $novasFuncoes . "\n    " . $marcador, $conteudo);
                echo "✅ Funções inseridas antes de '$marcador'\n";
                $inserido = true;
                break;
            }
        }
        
        if ($inserido) {
            file_put_contents($onePath, $conteudo);
            echo "✅ one.php atualizado!\n";
        } else {
            echo "❌ Não encontrou local para inserir\n";
            $erros++;
        }
    }
    
    echo "\n═══════════════════════════════════════════════════════════\n";
    echo $erros == 0 ? "🎉 FASE 1 INSTALADA COM SUCESSO!\n" : "⚠️ Concluído com $erros erro(s)\n";
    echo '</pre></div>';
    
    echo '<div class="card" style="border-color:#10b981;">';
    echo '<h2 style="color:#10b981;">✅ Fase 1 Concluída!</h2>';
    echo '<p>Funções adicionadas no one.php:</p>';
    echo '<ul style="margin:16px 0;padding-left:24px;">';
    echo '<li><code>detectarIntencao()</code> - viagem/mercado/ecommerce/corrida/receita</li>';
    echo '<li><code>carregarClienteCompleto()</code> - dados do OpenCart</li>';
    echo '<li><code>salvarContexto()</code> - lembra o que tá fazendo</li>';
    echo '<li><code>criarEvento()</code> - cria lembretes</li>';
    echo '</ul>';
    echo '<p><a href="one.php?action=send&message=quero%20ir%20pra%20miami" style="color:#10b981;" target="_blank">🧪 Testar</a></p>';
    echo '</div>';
    
} else {
    echo '<div class="card" style="text-align:center;">';
    echo '<h2 style="color:#10b981;">🚀 Instalar Fase 1?</h2>';
    echo '<p style="color:#94a3b8;margin:16px 0;">Cria tabelas + adiciona funções no one.php</p>';
    echo '<form method="post"><button type="submit" name="instalar" class="btn">🚀 INSTALAR</button></form>';
    echo '</div>';
}

echo '</div></body></html>';
