<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ╔══════════════════════════════════════════════════════════════════════════════════════╗
 * ║  📊 ONEMUNDO MERCADO - PAINEL DE CONTROLE DA PRECIFICAÇÃO                            ║
 * ║  Visualize, ajuste e monitore a IA de precificação                                   ║
 * ╚══════════════════════════════════════════════════════════════════════════════════════╝
 */

require_once __DIR__ . '/PrecificacaoInteligente.php';

// Conectar
$conn = getMySQLi();
$conn->set_charset('utf8mb4');

$ia = new PrecificacaoInteligente($conn);

// Ações
$mensagem = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['processar_mercado'])) {
        $partner_id = (int)$_POST['partner_id'];
        $resultado = $ia->processarMercado($partner_id);
        $mensagem = "✅ Mercado #{$partner_id} processado! {$resultado['atualizados']} produtos atualizados.";
    }
}

// Buscar estatísticas
$stats = [
    'total_mercados' => 0,
    'total_produtos_calculados' => 0,
    'margem_media' => 0,
    'lucro_estimado' => 0
];

$res = $conn->query("SELECT COUNT(*) as total FROM om_market_partners WHERE status = '1'");
if ($row = $res->fetch_assoc()) {
    $stats['total_mercados'] = $row['total'];
}

$res = $conn->query("SELECT COUNT(*) as total, AVG(margin_percent) as margem, SUM(profit_estimated) as lucro FROM om_market_products_sale WHERE status = '1'");
if ($row = $res->fetch_assoc()) {
    $stats['total_produtos_calculados'] = $row['total'] ?? 0;
    $stats['margem_media'] = round($row['margem'] ?? 0, 2);
    $stats['lucro_estimado'] = $row['lucro'] ?? 0;
}

// Buscar mercados
$mercados = [];
$res = $conn->query("
    SELECT p.partner_id, p.name, p.city, p.state,
           COUNT(s.id) as produtos_calculados,
           AVG(s.margin_percent) as margem_media,
           SUM(s.profit_estimated) as lucro_estimado
    FROM om_market_partners p
    LEFT JOIN om_market_products_sale s ON p.partner_id = s.partner_id AND s.status = 1
    WHERE p.status = 1
    GROUP BY p.partner_id
    ORDER BY p.name
");
while ($row = $res->fetch_assoc()) {
    $mercados[] = $row;
}

// Buscar últimas execuções do CRON
$cronLogs = [];
$res = $conn->query("SELECT * FROM om_market_pricing_log ORDER BY executed_at DESC LIMIT 10");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $cronLogs[] = $row;
    }
}

// Buscar distribuição por categoria
$categorias = [];
$res = $conn->query("
    SELECT category_key, COUNT(*) as total, AVG(margin_percent) as margem, AVG(profit_estimated) as lucro
    FROM om_market_products_sale
    WHERE status = 1
    GROUP BY category_key
    ORDER BY total DESC
    LIMIT 20
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $categorias[] = $row;
    }
}

$config = $ia->getConfiguracoes();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OneMundo - Painel de Precificação</title>
    <style>
        :root {
            --primary: #3498db;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --dark: #2c3e50;
            --light: #ecf0f1;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            color: var(--dark);
            font-size: 1.8rem;
        }
        
        .header h1 span {
            color: var(--primary);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card .icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        
        .stat-card .value {
            font-size: 2rem;
            font-weight: bold;
            color: var(--dark);
        }
        
        .stat-card .label {
            color: #666;
            font-size: 0.9rem;
            margin-top: 5px;
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .card-header {
            background: var(--primary);
            color: white;
            padding: 15px 20px;
            font-weight: bold;
        }
        
        .card-body {
            padding: 20px;
            max-height: 400px;
            overflow-y: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        th {
            background: var(--light);
            font-weight: 600;
            color: var(--dark);
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: #2980b9;
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-sm {
            padding: 5px 12px;
            font-size: 0.85rem;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .progress-bar {
            height: 8px;
            background: var(--light);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-bar .fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.5s;
        }
        
        .config-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .config-item {
            background: var(--light);
            padding: 15px;
            border-radius: 10px;
        }
        
        .config-item .label {
            font-size: 0.8rem;
            color: #666;
            margin-bottom: 5px;
        }
        
        .config-item .value {
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--dark);
        }
        
        @media (max-width: 900px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>🧠 <span>OneMundo</span> - Painel de Precificação IA</h1>
            <div>
                <span style="color:#666;">Última atualização: <?= date('d/m/Y H:i') ?></span>
            </div>
        </div>
        
        <?php if ($mensagem): ?>
        <div class="alert alert-success"><?= $mensagem ?></div>
        <?php endif; ?>
        
        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon">🏪</div>
                <div class="value"><?= $stats['total_mercados'] ?></div>
                <div class="label">Mercados Ativos</div>
            </div>
            <div class="stat-card">
                <div class="icon">📦</div>
                <div class="value"><?= number_format($stats['total_produtos_calculados']) ?></div>
                <div class="label">Produtos Precificados</div>
            </div>
            <div class="stat-card">
                <div class="icon">📊</div>
                <div class="value"><?= $stats['margem_media'] ?>%</div>
                <div class="label">Margem Média</div>
            </div>
            <div class="stat-card">
                <div class="icon">💰</div>
                <div class="value">R$ <?= number_format($stats['lucro_estimado'], 0, ',', '.') ?></div>
                <div class="label">Lucro Estimado (mensal)</div>
            </div>
        </div>
        
        <!-- Main Grid -->
        <div class="grid-2">
            <!-- Mercados -->
            <div class="card">
                <div class="card-header">🏪 Mercados Parceiros</div>
                <div class="card-body">
                    <table>
                        <thead>
                            <tr>
                                <th>Mercado</th>
                                <th>Cidade</th>
                                <th>Produtos</th>
                                <th>Margem</th>
                                <th>Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mercados as $m): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($m['name']) ?></strong></td>
                                <td><?= htmlspecialchars($m['city'] . '/' . $m['state']) ?></td>
                                <td><?= number_format($m['produtos_calculados'] ?? 0) ?></td>
                                <td>
                                    <?php 
                                    $margem = round($m['margem_media'] ?? 0, 1);
                                    $badge = $margem >= 25 ? 'success' : ($margem >= 20 ? 'warning' : 'danger');
                                    ?>
                                    <span class="badge badge-<?= $badge ?>"><?= $margem ?>%</span>
                                </td>
                                <td>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="partner_id" value="<?= $m['partner_id'] ?>">
                                        <button type="submit" name="processar_mercado" class="btn btn-primary btn-sm">
                                            ⚡ Atualizar
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Configurações -->
            <div class="card">
                <div class="card-header">⚙️ Configurações Atuais</div>
                <div class="card-body">
                    <div class="config-grid">
                        <div class="config-item">
                            <div class="label">Shopper (fixo)</div>
                            <div class="value">R$ <?= $config['custos_shopper']['fixo_por_pedido'] ?></div>
                        </div>
                        <div class="config-item">
                            <div class="label">Shopper (%)</div>
                            <div class="value"><?= $config['custos_shopper']['percentual_valor'] * 100 ?>%</div>
                        </div>
                        <div class="config-item">
                            <div class="label">Delivery (fixo)</div>
                            <div class="value">R$ <?= $config['custos_delivery']['fixo_por_entrega'] ?></div>
                        </div>
                        <div class="config-item">
                            <div class="label">Delivery/km</div>
                            <div class="value">R$ <?= $config['custos_delivery']['custo_por_km'] ?></div>
                        </div>
                        <div class="config-item">
                            <div class="label">Gateway</div>
                            <div class="value"><?= $config['custos_gateway']['media_ponderada'] * 100 ?>%</div>
                        </div>
                        <div class="config-item">
                            <div class="label">Reserva Erros</div>
                            <div class="value"><?= $config['reserva_problemas']['total'] * 100 ?>%</div>
                        </div>
                        <div class="config-item">
                            <div class="label">Margem Mínima</div>
                            <div class="value"><?= $config['limites_seguranca']['margem_minima_percent'] * 100 ?>%</div>
                        </div>
                        <div class="config-item">
                            <div class="label">Lucro Mínimo</div>
                            <div class="value">R$ <?= $config['limites_seguranca']['lucro_minimo_reais'] ?></div>
                        </div>
                    </div>
                    
                    <hr style="margin: 20px 0; border-color: #eee;">
                    
                    <p style="color: #666; font-size: 0.9rem;">
                        <strong>Custos Fixos/mês:</strong> R$ <?= number_format($config['custos_fixos_mensais']['total'], 2, ',', '.') ?><br>
                        <strong>Meta pedidos/mês:</strong> <?= number_format($config['meta_pedidos_mensal']) ?><br>
                        <strong>Categorias configuradas:</strong> <?= $config['total_categorias'] ?>
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Segunda linha -->
        <div class="grid-2">
            <!-- Categorias -->
            <div class="card">
                <div class="card-header">📂 Distribuição por Categoria</div>
                <div class="card-body">
                    <table>
                        <thead>
                            <tr>
                                <th>Categoria</th>
                                <th>Produtos</th>
                                <th>Margem Média</th>
                                <th>Lucro Médio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categorias as $c): ?>
                            <tr>
                                <td><strong><?= ucfirst($c['category_key']) ?></strong></td>
                                <td><?= number_format($c['total']) ?></td>
                                <td><?= round($c['margem'], 1) ?>%</td>
                                <td>R$ <?= number_format($c['lucro'], 2, ',', '.') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Histórico CRON -->
            <div class="card">
                <div class="card-header">🕐 Histórico de Execuções</div>
                <div class="card-body">
                    <?php if (empty($cronLogs)): ?>
                    <p style="color: #666; text-align: center; padding: 20px;">
                        Nenhuma execução registrada ainda.<br>
                        Configure o CRON para rodar automaticamente.
                    </p>
                    <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Data/Hora</th>
                                <th>Produtos</th>
                                <th>Margem</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cronLogs as $log): ?>
                            <tr>
                                <td><?= date('d/m H:i', strtotime($log['executed_at'])) ?></td>
                                <td><?= number_format($log['products_updated']) ?></td>
                                <td><?= $log['avg_margin'] ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div style="text-align: center; color: white; margin-top: 20px; opacity: 0.8;">
            <p>OneMundo Mercado - IA de Precificação Inteligente v2.0</p>
            <p style="font-size: 0.8rem;">Sistema que NUNCA dá prejuízo • Baseado em pesquisa de mercado 2024/2025</p>
        </div>
    </div>
</body>
</html>
