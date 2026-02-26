<?php
require_once __DIR__ . '/config/database.php';
/**
 * 🔧 CORRIGIR PRODUTOS - Featured e Categorias
 */

session_name('OCSESSID');
session_start();

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$msg = '';

// CORREÇÃO 1: Marcar produtos como featured
if (isset($_POST['marcar_featured'])) {
    $partner_id = $_POST['partner_id'];
    
    // Marcar TODOS os produtos ativos como featured
    $stmt = $pdo->prepare("UPDATE om_market_products SET is_featured = 1 WHERE partner_id = ? AND status = '1'");
    $stmt->execute([$partner_id]);
    $affected = $stmt->rowCount();
    
    $msg = "✅ $affected produtos marcados como FEATURED!";
}

// CORREÇÃO 2: Ajustar categorias para bater com o menu
if (isset($_POST['ajustar_categorias'])) {
    $partner_id = $_POST['partner_id'];
    
    // Carnes -> Açougue
    $pdo->prepare("UPDATE om_market_products SET category = 'Açougue' WHERE partner_id = ? AND category = 'Carnes'")->execute([$partner_id]);
    
    // Padaria -> Padaria (já está ok)
    // Bebidas -> Bebidas (já está ok)
    // Hortifruti -> Hortifruti (já está ok)
    // Laticínios -> Laticínios (criar no menu)
    // Limpeza -> Limpeza (criar no menu)
    
    $msg = "✅ Categorias ajustadas! Carnes → Açougue";
}

// CORREÇÃO 3: Aplicar TUDO de uma vez
if (isset($_POST['corrigir_tudo'])) {
    $partner_id = $_POST['partner_id'] ?? 100;
    
    // 1. Marcar todos como featured
    $pdo->prepare("UPDATE om_market_products SET is_featured = 1 WHERE partner_id = ? AND status = '1'")->execute([$partner_id]);
    
    // 2. Ajustar categoria Carnes -> Açougue
    $pdo->prepare("UPDATE om_market_products SET category = 'Açougue' WHERE partner_id = ? AND category = 'Carnes'")->execute([$partner_id]);
    
    // 3. Garantir que in_stock = 1
    $pdo->prepare("UPDATE om_market_products SET in_stock = 1 WHERE partner_id = ? AND status = '1' AND stock > 0")->execute([$partner_id]);
    
    // 4. Garantir quantity
    $pdo->prepare("UPDATE om_market_products SET quantity = stock WHERE partner_id = ? AND (quantity IS NULL OR quantity = 0)")->execute([$partner_id]);
    
    $msg = "✅ TUDO CORRIGIDO! Agora vai funcionar!";
}

// Buscar mercados
$mercados = $pdo->query("SELECT partner_id, name, city FROM om_market_partners WHERE status = '1'")->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$partner_id = $_GET['mercado'] ?? $_POST['partner_id'] ?? 100;

$stats = [
    'total' => $pdo->query("SELECT COUNT(*) FROM om_market_products WHERE partner_id = $partner_id")->fetchColumn(),
    'ativos' => $pdo->query("SELECT COUNT(*) FROM om_market_products WHERE partner_id = $partner_id AND status = '1'")->fetchColumn(),
    'featured' => $pdo->query("SELECT COUNT(*) FROM om_market_products WHERE partner_id = $partner_id AND is_featured = 1")->fetchColumn(),
    'in_stock' => $pdo->query("SELECT COUNT(*) FROM om_market_products WHERE partner_id = $partner_id AND in_stock = 1")->fetchColumn(),
];

$categorias = $pdo->query("SELECT category, COUNT(*) as total, SUM(is_featured) as featured FROM om_market_products WHERE partner_id = $partner_id AND status = '1' GROUP BY category")->fetchAll(PDO::FETCH_ASSOC);

$produtos = $pdo->query("SELECT id, name, category, price, stock, is_featured, in_stock, status FROM om_market_products WHERE partner_id = $partner_id ORDER BY category, name LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔧 Corrigir Produtos</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:#0a0a15;color:#fff;padding:20px}
        .container{max-width:1000px;margin:0 auto}
        h1{color:#f39c12;margin-bottom:20px;text-align:center}
        .msg{padding:15px;border-radius:8px;margin-bottom:20px;background:rgba(46,204,113,0.2);border:1px solid #2ecc71;font-size:16px}
        .card{background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:20px;margin-bottom:15px}
        .card h2{color:#f39c12;margin-bottom:15px}
        .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin-bottom:20px}
        .stat{background:rgba(243,156,18,0.1);border:1px solid rgba(243,156,18,0.3);border-radius:10px;padding:15px;text-align:center}
        .stat-val{font-size:28px;font-weight:bold;color:#f39c12}
        .stat-lbl{font-size:11px;color:#888}
        .btn{padding:12px 24px;border:none;border-radius:8px;font-size:14px;font-weight:bold;cursor:pointer;margin:5px}
        .btn-warning{background:#f39c12;color:#000}
        .btn-success{background:#2ecc71;color:#000}
        .btn-danger{background:#e74c3c;color:#fff}
        .btn-primary{background:#3498db;color:#fff}
        .btn:hover{opacity:0.9;transform:translateY(-2px)}
        select{padding:10px;border:1px solid #333;border-radius:6px;background:#1a1a2e;color:#fff;margin-right:10px}
        table{width:100%;border-collapse:collapse;margin-top:15px}
        th,td{padding:10px;text-align:left;border-bottom:1px solid rgba(255,255,255,0.1);font-size:13px}
        th{color:#f39c12;font-size:11px}
        .badge{display:inline-block;padding:3px 8px;border-radius:10px;font-size:11px}
        .badge-ok{background:#2ecc71;color:#000}
        .badge-err{background:#e74c3c;color:#fff}
        .warning-box{background:rgba(231,76,60,0.2);border:2px solid #e74c3c;border-radius:12px;padding:20px;margin-bottom:20px}
        .warning-box h3{color:#e74c3c;margin-bottom:10px}
    </style>
</head>
<body>
<div class="container">
    <h1>🔧 Corrigir Produtos do Mercado</h1>
    
    <?php if ($msg): ?>
        <div class="msg"><?= $msg ?></div>
    <?php endif; ?>
    
    <!-- PROBLEMA IDENTIFICADO -->
    <div class="warning-box">
        <h3>⚠️ PROBLEMAS IDENTIFICADOS:</h3>
        <p>1. <strong>is_featured = 0</strong> → O menu "Produtos em destaque" não mostra nada!</p>
        <p>2. <strong>Categoria "Carnes"</strong> → O menu espera "Açougue"</p>
        <p>3. <strong>in_stock pode estar 0</strong> → Produtos não aparecem como disponíveis</p>
    </div>
    
    <!-- SELECIONAR MERCADO -->
    <div class="card">
        <h2>🏪 Selecionar Mercado</h2>
        <form method="get">
            <select name="mercado" onchange="this.form.submit()">
                <?php foreach ($mercados as $m): ?>
                    <option value="<?= $m['partner_id'] ?>" <?= $partner_id == $m['partner_id'] ? 'selected' : '' ?>>
                        <?= $m['name'] ?> (<?= $m['city'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    
    <!-- STATS -->
    <div class="stats">
        <div class="stat">
            <div class="stat-val"><?= $stats['total'] ?></div>
            <div class="stat-lbl">Total</div>
        </div>
        <div class="stat">
            <div class="stat-val"><?= $stats['ativos'] ?></div>
            <div class="stat-lbl">Ativos</div>
        </div>
        <div class="stat">
            <div class="stat-val" style="color:<?= $stats['featured'] > 0 ? '#2ecc71' : '#e74c3c' ?>"><?= $stats['featured'] ?></div>
            <div class="stat-lbl">Featured ⚠️</div>
        </div>
        <div class="stat">
            <div class="stat-val"><?= $stats['in_stock'] ?></div>
            <div class="stat-lbl">Em Estoque</div>
        </div>
    </div>
    
    <!-- AÇÕES -->
    <div class="card">
        <h2>🚀 CORRIGIR AGORA</h2>
        <form method="post">
            <input type="hidden" name="partner_id" value="<?= $partner_id ?>">
            
            <button type="submit" name="corrigir_tudo" class="btn btn-success" style="font-size:18px;padding:20px 40px">
                ✅ CORRIGIR TUDO DE UMA VEZ
            </button>
            
            <p style="color:#888;margin-top:15px;font-size:12px">
                Isso vai: Marcar como featured, Ajustar categorias, Garantir estoque
            </p>
        </form>
        
        <hr style="margin:20px 0;border-color:#333">
        
        <form method="post" style="display:inline">
            <input type="hidden" name="partner_id" value="<?= $partner_id ?>">
            <button type="submit" name="marcar_featured" class="btn btn-warning">⭐ Marcar Featured</button>
        </form>
        
        <form method="post" style="display:inline">
            <input type="hidden" name="partner_id" value="<?= $partner_id ?>">
            <button type="submit" name="ajustar_categorias" class="btn btn-primary">📁 Ajustar Categorias</button>
        </form>
        
        <a href="/mercado/" class="btn btn-success">🛒 Testar /mercado/</a>
    </div>
    
    <!-- CATEGORIAS -->
    <div class="card">
        <h2>📁 Categorias no Mercado</h2>
        <table>
            <thead>
                <tr><th>Categoria</th><th>Qtd</th><th>Featured</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($categorias as $c): ?>
                <tr>
                    <td><strong><?= $c['category'] ?></strong></td>
                    <td><?= $c['total'] ?></td>
                    <td><?= $c['featured'] ?>/<?= $c['total'] ?></td>
                    <td>
                        <?php if ($c['featured'] == $c['total']): ?>
                            <span class="badge badge-ok">OK</span>
                        <?php else: ?>
                            <span class="badge badge-err">CORRIGIR</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- PRODUTOS -->
    <div class="card">
        <h2>📦 Produtos (primeiros 30)</h2>
        <table>
            <thead>
                <tr><th>ID</th><th>Nome</th><th>Categoria</th><th>Preço</th><th>Estoque</th><th>Featured</th><th>In Stock</th></tr>
            </thead>
            <tbody>
                <?php foreach ($produtos as $p): ?>
                <tr>
                    <td><?= $p['id'] ?></td>
                    <td><?= $p['name'] ?></td>
                    <td><?= $p['category'] ?></td>
                    <td>R$ <?= number_format($p['price'], 2, ',', '.') ?></td>
                    <td><?= $p['stock'] ?></td>
                    <td><span class="badge <?= $p['is_featured'] ? 'badge-ok' : 'badge-err' ?>"><?= $p['is_featured'] ? 'SIM' : 'NÃO' ?></span></td>
                    <td><span class="badge <?= $p['in_stock'] ? 'badge-ok' : 'badge-err' ?>"><?= $p['in_stock'] ? 'SIM' : 'NÃO' ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
