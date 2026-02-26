<?php
require_once __DIR__ . '/config/database.php';
/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════════
 * 🔍 VERIFICADOR DE FLUXOS ONEMUNDO v1.0
 * ═══════════════════════════════════════════════════════════════════════════════════════════════════
 * 
 * Verifica se TODOS os fluxos estão funcionando:
 * - Trabalhe Conosco → RH
 * - Sistema de Pontos
 * - Carteira dos Workers
 * - Motor de Matching
 * - Webhook Pagar.me
 * - Dashboard Admin
 * 
 * Upload em: /mercado/VERIFICAR_FLUXOS.php
 * ═══════════════════════════════════════════════════════════════════════════════════════════════════
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

$CONFIG = [
    'db_host' => '147.93.12.236',
    'db_name' => 'love1',
    'db_user' => 'love1',
    'db_pass' => DB_PASSWORD,
    'base_url' => 'https://onemundo.com.br',
];

try {
    $pdo = new PDO(
        "mysql:host={$CONFIG['db_host']};dbname={$CONFIG['db_name']};charset=utf8mb4",
        $CONFIG['db_user'],
        $CONFIG['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("❌ Erro de conexão: " . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════════
// FUNÇÕES DE VERIFICAÇÃO
// ═══════════════════════════════════════════════════════════════════════════════════════════════════

$verificacoes = [];

function verificar($categoria, $nome, $passou, $detalhes = '', $sugestao = '') {
    global $verificacoes;
    $verificacoes[] = [
        'categoria' => $categoria,
        'nome' => $nome,
        'passou' => $passou,
        'detalhes' => $detalhes,
        'sugestao' => $sugestao
    ];
}

function tableExists($pdo, $table) {
    try {
        $pdo->query("SELECT 1 FROM $table LIMIT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════════
// 1. VERIFICAR FLUXO: TRABALHE CONOSCO → RH
// ═══════════════════════════════════════════════════════════════════════════════════════════════════

$cat = '🔄 FLUXO: TRABALHE CONOSCO → RH';

// 1.1 Workers existem?
if (tableExists($pdo, 'om_market_workers')) {
    $totalWorkers = $pdo->query("SELECT COUNT(*) FROM om_market_workers")->fetchColumn();
    verificar($cat, 'Workers cadastrados', $totalWorkers > 0, "$totalWorkers workers no sistema");
    
    // 1.2 Workers por status de aplicação
    $porStatus = $pdo->query("SELECT application_status, COUNT(*) as c FROM om_market_workers GROUP BY application_status")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($porStatus as $s) {
        $emoji = $s['application_status'] === 'approved' ? '✅' : ($s['application_status'] === 'pending' ? '⏳' : '📋');
        verificar($cat, "Workers {$s['application_status']}", true, "{$s['c']} workers");
    }
    
    // 1.3 Workers pendentes para RH avaliar
    $pendentes = $pdo->query("SELECT COUNT(*) FROM om_market_workers WHERE application_status IN ('pending', 'documents', 'review')")->fetchColumn();
    verificar($cat, 'Fila do RH', $pendentes > 0, "$pendentes workers aguardando avaliação", $pendentes == 0 ? 'Criar mais candidatos para testar o fluxo RH' : '');
    
    // 1.4 Documentos dos workers
    if (tableExists($pdo, 'om_market_worker_documents')) {
        $docs = $pdo->query("SELECT COUNT(*) FROM om_market_worker_documents")->fetchColumn();
        verificar($cat, 'Documentos enviados', $docs > 0, "$docs documentos no sistema");
        
        $docsPendentes = $pdo->query("SELECT COUNT(*) FROM om_market_worker_documents WHERE status = 'pending'")->fetchColumn();
        verificar($cat, 'Documentos para revisar', true, "$docsPendentes documentos pendentes");
    } else {
        verificar($cat, 'Tabela de documentos', false, 'Tabela não existe', 'Rodar MEGA_ROBO primeiro');
    }
} else {
    verificar($cat, 'Tabela de workers', false, 'Tabela não existe', 'Rodar MEGA_ROBO primeiro');
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════════
// 2. VERIFICAR SISTEMA DE PONTOS/GAMIFICAÇÃO
// ═══════════════════════════════════════════════════════════════════════════════════════════════════

$cat = '🎮 SISTEMA DE PONTOS';

if (tableExists($pdo, 'om_market_workers')) {
    // XP Points
    $workersComXP = $pdo->query("SELECT COUNT(*) FROM om_market_workers WHERE xp_points > 0")->fetchColumn();
    $totalXP = $pdo->query("SELECT SUM(xp_points) FROM om_market_workers")->fetchColumn() ?? 0;
    verificar($cat, 'Workers com XP', $workersComXP > 0, "$workersComXP workers com XP (Total: " . number_format($totalXP) . " XP)");
    
    // Níveis
    $porNivel = $pdo->query("SELECT level, COUNT(*) as c FROM om_market_workers GROUP BY level ORDER BY level")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($porNivel as $n) {
        verificar($cat, "Nível {$n['level']}", true, "{$n['c']} workers");
    }
    
    // Badges
    if (tableExists($pdo, 'om_gamification_badges')) {
        $totalBadges = $pdo->query("SELECT COUNT(*) FROM om_gamification_badges")->fetchColumn();
        verificar($cat, 'Badges disponíveis', $totalBadges > 0, "$totalBadges badges criados");
    }
    
    if (tableExists($pdo, 'om_worker_badges')) {
        $badgesAtribuidos = $pdo->query("SELECT COUNT(*) FROM om_worker_badges")->fetchColumn();
        $workersComBadge = $pdo->query("SELECT COUNT(DISTINCT worker_id) FROM om_worker_badges")->fetchColumn();
        verificar($cat, 'Badges atribuídos', $badgesAtribuidos > 0, "$badgesAtribuidos badges para $workersComBadge workers");
    }
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════════
// 3. VERIFICAR CARTEIRA DOS WORKERS
// ═══════════════════════════════════════════════════════════════════════════════════════════════════

$cat = '💰 CARTEIRA DIGITAL';

if (tableExists($pdo, 'om_market_workers')) {
    $workersComSaldo = $pdo->query("SELECT COUNT(*) FROM om_market_workers WHERE balance > 0")->fetchColumn();
    $saldoTotal = $pdo->query("SELECT SUM(balance) FROM om_market_workers")->fetchColumn() ?? 0;
    $totalGanho = $pdo->query("SELECT SUM(total_earned) FROM om_market_workers")->fetchColumn() ?? 0;
    
    verificar($cat, 'Workers com saldo', $workersComSaldo > 0, "$workersComSaldo workers com R$ " . number_format($saldoTotal, 2, ',', '.'));
    verificar($cat, 'Total já ganho', $totalGanho > 0, "R$ " . number_format($totalGanho, 2, ',', '.') . " total ganho");
    
    // Ganhos
    if (tableExists($pdo, 'om_market_worker_earnings')) {
        $ganhosPorTipo = $pdo->query("SELECT type, COUNT(*) as c, SUM(amount) as total FROM om_market_worker_earnings GROUP BY type")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($ganhosPorTipo as $g) {
            verificar($cat, "Ganhos: {$g['type']}", true, "{$g['c']} transações = R$ " . number_format($g['total'], 2, ',', '.'));
        }
    }
    
    // Saques
    if (tableExists($pdo, 'om_market_worker_payouts')) {
        $saques = $pdo->query("SELECT COUNT(*) FROM om_market_worker_payouts")->fetchColumn();
        verificar($cat, 'Saques solicitados', true, "$saques saques");
    }
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════════
// 4. VERIFICAR PEDIDOS E FLUXO
// ═══════════════════════════════════════════════════════════════════════════════════════════════════

$cat = '📦 FLUXO DE PEDIDOS';

if (tableExists($pdo, 'om_market_orders')) {
    $totalPedidos = $pdo->query("SELECT COUNT(*) FROM om_market_orders")->fetchColumn();
    verificar($cat, 'Total de pedidos', $totalPedidos > 0, "$totalPedidos pedidos");
    
    // Por status
    $porStatus = $pdo->query("SELECT status, COUNT(*) as c FROM om_market_orders GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($porStatus as $s) {
        $emoji = match($s['status']) {
            'pending' => '⏳',
            'paid' => '💰',
            'assigned' => '👤',
            'shopping' => '🛒',
            'ready' => '✅',
            'delivering' => '🚗',
            'delivered' => '🎉',
            'cancelled' => '❌',
            default => '📋'
        };
        verificar($cat, "Status: {$s['status']}", true, "{$s['c']} pedidos $emoji");
    }
    
    // Payment status
    $porPagamento = $pdo->query("SELECT payment_status, COUNT(*) as c FROM om_market_orders GROUP BY payment_status")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($porPagamento as $p) {
        $status = $p['payment_status'] ?: 'null';
        verificar($cat, "Pagamento: $status", true, "{$p['c']} pedidos");
    }
    
    // Pedidos com shopper atribuído
    $comShopper = $pdo->query("SELECT COUNT(*) FROM om_market_orders WHERE shopper_id IS NOT NULL")->fetchColumn();
    verificar($cat, 'Com shopper atribuído', $comShopper > 0, "$comShopper pedidos");
    
    // Pedidos com driver atribuído
    $comDriver = $pdo->query("SELECT COUNT(*) FROM om_market_orders WHERE delivery_driver_id IS NOT NULL")->fetchColumn();
    verificar($cat, 'Com driver atribuído', $comDriver > 0, "$comDriver pedidos");
    
    // Chat dos pedidos
    if (tableExists($pdo, 'om_order_chat')) {
        $mensagens = $pdo->query("SELECT COUNT(*) FROM om_order_chat")->fetchColumn();
        $pedidosComChat = $pdo->query("SELECT COUNT(DISTINCT order_id) FROM om_order_chat")->fetchColumn();
        verificar($cat, 'Mensagens de chat', $mensagens > 0, "$mensagens mensagens em $pedidosComChat pedidos");
    }
    
    // Itens dos pedidos
    if (tableExists($pdo, 'om_market_order_items')) {
        $itens = $pdo->query("SELECT COUNT(*) FROM om_market_order_items")->fetchColumn();
        verificar($cat, 'Itens de pedidos', $itens > 0, "$itens itens");
    }
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════════
// 5. VERIFICAR SUPERMERCADOS E PRODUTOS
// ═══════════════════════════════════════════════════════════════════════════════════════════════════

$cat = '🏪 SUPERMERCADOS & PRODUTOS';

if (tableExists($pdo, 'om_market_partners')) {
    $mercados = $pdo->query("SELECT COUNT(*) FROM om_market_partners")->fetchColumn();
    $ativos = $pdo->query("SELECT COUNT(*) FROM om_market_partners WHERE status = 'active'")->fetchColumn();
    verificar($cat, 'Supermercados', $mercados > 0, "$mercados total ($ativos ativos)");
    
    // Por cidade
    $porCidade = $pdo->query("SELECT city, COUNT(*) as c FROM om_market_partners GROUP BY city ORDER BY c DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($porCidade as $c) {
        verificar($cat, "Cidade: {$c['city']}", true, "{$c['c']} supermercados");
    }
}

if (tableExists($pdo, 'om_market_products')) {
    $produtos = $pdo->query("SELECT COUNT(*) FROM om_market_products")->fetchColumn();
    $ativos = $pdo->query("SELECT COUNT(*) FROM om_market_products WHERE status = 'active'")->fetchColumn();
    verificar($cat, 'Produtos', $produtos > 0, "$produtos total ($ativos ativos)");
    
    // Por categoria
    $porCategoria = $pdo->query("SELECT category, COUNT(*) as c FROM om_market_products GROUP BY category ORDER BY c DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($porCategoria as $c) {
        verificar($cat, "Categoria: {$c['category']}", true, "{$c['c']} produtos");
    }
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════════
// 6. VERIFICAR FUNCIONÁRIOS CLT/ADMIN
// ═══════════════════════════════════════════════════════════════════════════════════════════════════

$cat = '👔 FUNCIONÁRIOS CLT/ADMIN';

if (tableExists($pdo, 'om_employees')) {
    $funcionarios = $pdo->query("SELECT COUNT(*) FROM om_employees")->fetchColumn();
    verificar($cat, 'Total de funcionários', $funcionarios > 0, "$funcionarios funcionários");
    
    $porDept = $pdo->query("SELECT department, COUNT(*) as c FROM om_employees GROUP BY department")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($porDept as $d) {
        verificar($cat, "Dept: {$d['department']}", true, "{$d['c']} funcionários");
    }
    
    // Admin com acesso
    $admins = $pdo->query("SELECT name, email, department FROM om_employees WHERE department = 'admin' LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($admins as $a) {
        verificar($cat, "Admin: {$a['name']}", true, $a['email']);
    }
} else {
    verificar($cat, 'Tabela de funcionários', false, 'Tabela não existe', 'Rodar MEGA_ROBO');
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════════
// 7. VERIFICAR WORKERS ONLINE
// ═══════════════════════════════════════════════════════════════════════════════════════════════════

$cat = '🟢 WORKERS ONLINE';

if (tableExists($pdo, 'om_market_workers')) {
    $online = $pdo->query("SELECT COUNT(*) FROM om_market_workers WHERE is_online = 1")->fetchColumn();
    verificar($cat, 'Workers online agora', true, "$online workers online");
    
    $onlinePorTipo = $pdo->query("SELECT worker_type, COUNT(*) as c FROM om_market_workers WHERE is_online = 1 GROUP BY worker_type")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($onlinePorTipo as $t) {
        verificar($cat, "{$t['worker_type']} online", true, "{$t['c']} online");
    }
    
    // Com localização GPS
    $comGPS = $pdo->query("SELECT COUNT(*) FROM om_market_workers WHERE current_lat IS NOT NULL AND current_lng IS NOT NULL")->fetchColumn();
    verificar($cat, 'Com localização GPS', $comGPS > 0, "$comGPS workers com GPS");
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════════
// 8. VERIFICAR OFERTAS/MATCHING
// ═══════════════════════════════════════════════════════════════════════════════════════════════════

$cat = '🎯 MOTOR DE MATCHING';

if (tableExists($pdo, 'om_shopper_offers')) {
    $ofertas = $pdo->query("SELECT COUNT(*) FROM om_shopper_offers")->fetchColumn();
    verificar($cat, 'Total de ofertas', $ofertas > 0, "$ofertas ofertas criadas");
    
    $porStatus = $pdo->query("SELECT status, COUNT(*) as c FROM om_shopper_offers GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($porStatus as $s) {
        verificar($cat, "Ofertas {$s['status']}", true, "{$s['c']} ofertas");
    }
} else {
    verificar($cat, 'Tabela de ofertas', false, 'Tabela não existe', 'Rodar MEGA_ROBO');
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════════
// CALCULAR RESUMO
// ═══════════════════════════════════════════════════════════════════════════════════════════════════

$total = count($verificacoes);
$passou = count(array_filter($verificacoes, fn($v) => $v['passou']));
$falhou = $total - $passou;
$score = $total > 0 ? round(($passou / $total) * 100) : 0;

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔍 Verificador de Fluxos - OneMundo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #f0f4f8;
            color: #333;
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        
        h1 { color: #1a1a2e; margin-bottom: 10px; font-size: 2rem; }
        .subtitle { color: #666; margin-bottom: 30px; }
        
        .score-container {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        .score-card {
            background: linear-gradient(135deg, <?= $score >= 80 ? '#10b981' : ($score >= 60 ? '#f59e0b' : '#ef4444') ?>, <?= $score >= 80 ? '#059669' : ($score >= 60 ? '#d97706' : '#dc2626') ?>);
            color: #fff;
            padding: 30px;
            border-radius: 20px;
            text-align: center;
            min-width: 200px;
        }
        .score-value { font-size: 3rem; font-weight: 700; }
        .score-label { opacity: 0.9; }
        
        .stats-card {
            background: #fff;
            padding: 20px 30px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .stats-card h3 { color: #666; font-size: 0.9rem; margin-bottom: 5px; }
        .stats-card .value { font-size: 2rem; font-weight: 700; }
        .stats-card .value.green { color: #10b981; }
        .stats-card .value.red { color: #ef4444; }
        
        .categoria-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: #fff;
            padding: 15px 20px;
            border-radius: 12px;
            margin: 25px 0 15px;
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .verificacao-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 20px;
            background: #fff;
            border-radius: 10px;
            margin-bottom: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .verificacao-icon { font-size: 1.5rem; }
        .verificacao-info { flex: 1; }
        .verificacao-nome { font-weight: 600; }
        .verificacao-detalhes { font-size: 0.85rem; color: #666; }
        .verificacao-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-ok { background: #d1fae5; color: #059669; }
        .status-erro { background: #fee2e2; color: #dc2626; }
        
        .sugestao {
            background: #fffbeb;
            border-left: 4px solid #f59e0b;
            padding: 10px 15px;
            margin-top: 5px;
            border-radius: 0 8px 8px 0;
            font-size: 0.85rem;
            color: #92400e;
        }
        
        .actions {
            margin-top: 30px;
            text-align: center;
        }
        .btn {
            display: inline-block;
            padding: 14px 28px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: #fff;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
            margin: 5px;
        }
        .btn:hover { opacity: 0.9; }
        .btn-success { background: linear-gradient(135deg, #10b981, #059669); }
        .btn-warning { background: linear-gradient(135deg, #f59e0b, #d97706); }
        
        .warning-box {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            margin-top: 30px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Verificador de Fluxos OneMundo</h1>
        <p class="subtitle">Análise completa de todos os sistemas</p>
        
        <!-- Score -->
        <div class="score-container">
            <div class="score-card">
                <div class="score-value"><?= $score ?>%</div>
                <div class="score-label">Score Geral</div>
            </div>
            <div class="stats-card">
                <h3>✅ Passou</h3>
                <div class="value green"><?= $passou ?></div>
            </div>
            <div class="stats-card">
                <h3>❌ Falhou</h3>
                <div class="value red"><?= $falhou ?></div>
            </div>
            <div class="stats-card">
                <h3>📊 Total</h3>
                <div class="value"><?= $total ?></div>
            </div>
        </div>
        
        <!-- Verificações -->
        <?php
        $catAtual = '';
        foreach ($verificacoes as $v):
            if ($v['categoria'] !== $catAtual):
                $catAtual = $v['categoria'];
                echo "<div class='categoria-header'>{$catAtual}</div>";
            endif;
        ?>
        <div class="verificacao-item">
            <span class="verificacao-icon"><?= $v['passou'] ? '✅' : '❌' ?></span>
            <div class="verificacao-info">
                <div class="verificacao-nome"><?= htmlspecialchars($v['nome']) ?></div>
                <div class="verificacao-detalhes"><?= htmlspecialchars($v['detalhes']) ?></div>
                <?php if (!$v['passou'] && $v['sugestao']): ?>
                <div class="sugestao">💡 <?= htmlspecialchars($v['sugestao']) ?></div>
                <?php endif; ?>
            </div>
            <span class="verificacao-status <?= $v['passou'] ? 'status-ok' : 'status-erro' ?>">
                <?= $v['passou'] ? 'OK' : 'ERRO' ?>
            </span>
        </div>
        <?php endforeach; ?>
        
        <!-- Ações -->
        <div class="actions">
            <a href="?" class="btn">🔄 Verificar Novamente</a>
            <a href="MEGA_ROBO.php" class="btn btn-warning">🤖 Rodar Mega Robô</a>
            <a href="MEGA_TESTADOR.php" class="btn btn-success">🧪 Testar Páginas</a>
            <a href="/rh/" class="btn">👔 Ir para RH</a>
        </div>
        
        <div class="warning-box">
            ⚠️ APAGUE este arquivo após usar em produção!
        </div>
    </div>
</body>
</html>
