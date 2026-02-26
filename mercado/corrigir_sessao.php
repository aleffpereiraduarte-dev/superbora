<?php
require_once __DIR__ . '/config/database.php';
/**
 * 🔧 CORRIGIR SESSÃO DO MERCADO
 */

session_name('OCSESSID');
session_start();

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$msg = '';

// Se receber CEP, fazer o match
if (isset($_POST['cep']) || isset($_GET['cep'])) {
    $cep = preg_replace('/\D/', '', $_POST['cep'] ?? $_GET['cep']);
    
    // Coordenadas fixas para CEPs conhecidos
    $ceps_coords = [
        '35040090' => ['lat' => -18.8574, 'lng' => -41.9439, 'cidade' => 'Governador Valadares'],
        '35010000' => ['lat' => -18.8510, 'lng' => -41.9530, 'cidade' => 'Governador Valadares'],
        '35020000' => ['lat' => -18.8620, 'lng' => -41.9420, 'cidade' => 'Governador Valadares'],
        '30130000' => ['lat' => -19.9200, 'lng' => -43.9400, 'cidade' => 'Belo Horizonte'],
        '01310000' => ['lat' => -23.5505, 'lng' => -46.6333, 'cidade' => 'São Paulo'],
    ];
    
    if (isset($ceps_coords[$cep])) {
        $coords = $ceps_coords[$cep];
    } else {
        // Tentar API ViaCEP + Nominatim
        $via = json_decode(file_get_contents("https://viacep.com.br/ws/$cep/json/"), true);
        if ($via && !isset($via['erro'])) {
            $coords = ['lat' => -18.8574, 'lng' => -41.9439, 'cidade' => $via['localidade'] ?? 'Desconhecida'];
        } else {
            $coords = null;
        }
    }
    
    if ($coords) {
        // Buscar mercado mais próximo
        $mercados = $pdo->query("SELECT partner_id, name, city, latitude, longitude, raio_entrega_km FROM om_market_partners WHERE status = '1' AND latitude IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        
        $mercado_match = null;
        $menor_dist = PHP_FLOAT_MAX;
        
        foreach ($mercados as $m) {
            $dist = sqrt(pow($coords['lat'] - $m['latitude'], 2) + pow($coords['lng'] - $m['longitude'], 2)) * 111;
            $raio = $m['raio_entrega_km'] ?? 10;
            
            if ($dist <= $raio && $dist < $menor_dist) {
                $menor_dist = $dist;
                $mercado_match = $m;
            }
        }
        
        if ($mercado_match) {
            // SALVAR NA SESSÃO
            $_SESSION['market_partner_id'] = $mercado_match['partner_id'];
            $_SESSION['market_partner_name'] = $mercado_match['name'];
            $_SESSION['market_cep'] = $cep;
            $_SESSION['market_cidade'] = $mercado_match['city'];
            $_SESSION['market_lat'] = $coords['lat'];
            $_SESSION['market_lng'] = $coords['lng'];
            $_SESSION['customer_cep'] = $cep;
            
            $msg = "✅ Mercado definido: {$mercado_match['name']} (ID: {$mercado_match['partner_id']})";
        } else {
            $msg = "❌ Nenhum mercado atende este CEP!";
        }
    } else {
        $msg = "❌ CEP não encontrado!";
    }
}

// Forçar mercado específico
if (isset($_POST['forcar_mercado'])) {
    $partner_id = $_POST['partner_id'];
    $stmt = $pdo->prepare("SELECT partner_id, name, city FROM om_market_partners WHERE partner_id = ?");
    $stmt->execute([$partner_id]);
    $mercado = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($mercado) {
        $_SESSION['market_partner_id'] = $mercado['partner_id'];
        $_SESSION['market_partner_name'] = $mercado['name'];
        $_SESSION['market_cidade'] = $mercado['city'];
        $_SESSION['market_cep'] = '35040090';
        $msg = "✅ Mercado forçado: {$mercado['name']}";
    }
}

// Limpar sessão
if (isset($_POST['limpar'])) {
    unset($_SESSION['market_partner_id']);
    unset($_SESSION['market_partner_name']);
    unset($_SESSION['market_cep']);
    unset($_SESSION['market_cidade']);
    $msg = "🗑️ Sessão limpa!";
}

// Buscar mercados
$mercados = $pdo->query("SELECT partner_id, name, city FROM om_market_partners WHERE status = '1' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Buscar produtos do mercado atual
$produtos = [];
if (isset($_SESSION['market_partner_id'])) {
    $stmt = $pdo->prepare("SELECT id, name, category, price, stock FROM om_market_products WHERE partner_id = ? AND status = '1' ORDER BY category, name");
    $stmt->execute([$_SESSION['market_partner_id']]);
    $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔧 Corrigir Sessão Mercado</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:#0a0a15;color:#fff;padding:20px}
        .container{max-width:900px;margin:0 auto}
        h1{color:#00c896;margin-bottom:20px;text-align:center}
        .card{background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:20px;margin-bottom:15px}
        .card h2{color:#00c896;margin-bottom:15px;font-size:16px}
        .msg{padding:12px;border-radius:8px;margin-bottom:15px;background:rgba(0,200,150,0.2);border:1px solid #00c896}
        .session-box{background:#111;padding:15px;border-radius:8px;font-family:monospace;font-size:13px}
        .session-box .ok{color:#00c896}
        .session-box .err{color:#e74c3c}
        input,select{padding:10px;border:1px solid #333;border-radius:6px;background:#1a1a2e;color:#fff;font-size:14px;margin-right:10px}
        .btn{padding:10px 20px;border:none;border-radius:6px;font-weight:bold;cursor:pointer;margin:5px}
        .btn-primary{background:#00c896;color:#000}
        .btn-danger{background:#e74c3c;color:#fff}
        .btn-secondary{background:#555;color:#fff}
        table{width:100%;border-collapse:collapse;margin-top:15px}
        th,td{padding:8px;text-align:left;border-bottom:1px solid rgba(255,255,255,0.1);font-size:13px}
        th{color:#00c896;font-size:11px}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:15px}
    </style>
</head>
<body>
<div class="container">
    <h1>🔧 Corrigir Sessão do Mercado</h1>
    
    <?php if ($msg): ?>
        <div class="msg"><?= $msg ?></div>
    <?php endif; ?>
    
    <!-- SESSÃO ATUAL -->
    <div class="card">
        <h2>📋 Sessão Atual</h2>
        <div class="session-box">
            <p class="<?= isset($_SESSION['market_partner_id']) ? 'ok' : 'err' ?>">
                market_partner_id: <strong><?= $_SESSION['market_partner_id'] ?? 'NÃO DEFINIDO' ?></strong>
            </p>
            <p class="<?= isset($_SESSION['market_partner_name']) ? 'ok' : 'err' ?>">
                market_partner_name: <strong><?= $_SESSION['market_partner_name'] ?? 'NÃO DEFINIDO' ?></strong>
            </p>
            <p class="<?= isset($_SESSION['market_cep']) ? 'ok' : 'err' ?>">
                market_cep: <strong><?= $_SESSION['market_cep'] ?? 'NÃO DEFINIDO' ?></strong>
            </p>
            <p class="<?= isset($_SESSION['market_cidade']) ? 'ok' : 'err' ?>">
                market_cidade: <strong><?= $_SESSION['market_cidade'] ?? 'NÃO DEFINIDO' ?></strong>
            </p>
        </div>
    </div>
    
    <div class="grid">
        <!-- DEFINIR POR CEP -->
        <div class="card">
            <h2>📍 Definir por CEP</h2>
            <form method="post">
                <input type="text" name="cep" placeholder="35040-090" value="35040090" style="width:150px">
                <button type="submit" class="btn btn-primary">🔍 Buscar</button>
            </form>
        </div>
        
        <!-- FORÇAR MERCADO -->
        <div class="card">
            <h2>🏪 Forçar Mercado</h2>
            <form method="post">
                <input type="hidden" name="forcar_mercado" value="1">
                <select name="partner_id">
                    <?php foreach ($mercados as $m): ?>
                        <option value="<?= $m['partner_id'] ?>"><?= $m['name'] ?> (<?= $m['city'] ?>)</option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-secondary">✅ Definir</button>
            </form>
        </div>
    </div>
    
    <!-- LIMPAR -->
    <div class="card">
        <form method="post" style="display:inline">
            <input type="hidden" name="limpar" value="1">
            <button type="submit" class="btn btn-danger">🗑️ Limpar Sessão</button>
        </form>
        <a href="/mercado/" class="btn btn-primary" style="text-decoration:none;display:inline-block">🛒 Ir para /mercado/</a>
    </div>
    
    <!-- PRODUTOS -->
    <?php if (!empty($produtos)): ?>
    <div class="card">
        <h2>📦 Produtos do Mercado (<?= count($produtos) ?>)</h2>
        <table>
            <thead>
                <tr><th>ID</th><th>Nome</th><th>Categoria</th><th>Preço</th><th>Estoque</th></tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($produtos, 0, 15) as $p): ?>
                <tr>
                    <td><?= $p['id'] ?></td>
                    <td><?= $p['name'] ?></td>
                    <td><?= $p['category'] ?></td>
                    <td>R$ <?= number_format($p['price'], 2, ',', '.') ?></td>
                    <td><?= $p['stock'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (count($produtos) > 15): ?>
            <p style="color:#888;margin-top:10px">... e mais <?= count($produtos) - 15 ?> produtos</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
