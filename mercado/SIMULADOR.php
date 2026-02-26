<?php
require_once __DIR__ . '/config/database.php';
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * 🎮 SIMULADOR ONEMUNDO MERCADO - PAINEL PRINCIPAL
 * ══════════════════════════════════════════════════════════════════════════════
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');

$pdo = getPDO();

// ══════════════════════════════════════════════════════════════════════════════
// PROCESSAR AÇÕES AJAX
// ══════════════════════════════════════════════════════════════════════════════

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax'];
    
    // Toggle shopper online/offline
    if ($action === 'toggle_shopper') {
        $id = intval($_GET['id']);
        $pdo->exec("UPDATE om_market_shoppers SET is_online = NOT is_online, status = IF(is_online, 'offline', 'online') WHERE shopper_id = $id");
        $status = $pdo->query("SELECT is_online FROM om_market_shoppers WHERE shopper_id = $id")->fetchColumn();
        echo json_encode(['success' => true, 'is_online' => $status]);
        exit;
    }
    
    // Toggle delivery online/offline
    if ($action === 'toggle_delivery') {
        $id = intval($_GET['id']);
        $pdo->exec("UPDATE om_market_delivery SET is_online = NOT is_online, status = IF(is_online, 'offline', 'online') WHERE delivery_id = $id");
        $status = $pdo->query("SELECT is_online FROM om_market_delivery WHERE delivery_id = $id")->fetchColumn();
        echo json_encode(['success' => true, 'is_online' => $status]);
        exit;
    }
    
    // Toggle mercado aberto/fechado
    if ($action === 'toggle_mercado') {
        $id = intval($_GET['id']);
        $pdo->exec("UPDATE om_market_partners SET is_open = NOT is_open WHERE partner_id = $id");
        $status = $pdo->query("SELECT is_open FROM om_market_partners WHERE partner_id = $id")->fetchColumn();
        echo json_encode(['success' => true, 'is_open' => $status]);
        exit;
    }
    
    // Buscar mercados para cliente
    if ($action === 'buscar_mercados') {
        $clienteId = intval($_GET['cliente_id']);
        $cliente = $pdo->query("SELECT * FROM om_sim_customers WHERE id = $clienteId")->fetch();
        
        if (!$cliente) {
            echo json_encode(['success' => false, 'error' => 'Cliente não encontrado']);
            exit;
        }
        
        // Buscar mercados abertos
        $mercados = $pdo->query("SELECT * FROM om_market_partners WHERE status = '1' AND is_open = 1")->fetchAll();
        
        $mercadosProximos = [];
        foreach ($mercados as $m) {
            // Calcular distância
            $dist = calcDistancia($cliente['lat'], $cliente['lng'], $m['lat'], $m['lng']);
            $tempo = ceil(($dist / 20) * 60); // 20km/h
            
            $raio = $m['delivery_radius'] ?? 15;
            
            if ($dist <= $raio && $tempo <= 45) {
                // Contar shoppers online na região
                $shoppersOnline = $pdo->query("
                    SELECT COUNT(*) FROM om_market_shoppers 
                    WHERE is_online = 1 AND is_busy = 0 AND city = '{$m['city']}'
                ")->fetchColumn();
                
                // Contar deliverys online
                $deliverysOnline = $pdo->query("
                    SELECT COUNT(*) FROM om_market_delivery 
                    WHERE is_online = 1 AND city = '{$m['city']}'
                ")->fetchColumn();
                
                $mercadosProximos[] = [
                    'id' => $m['partner_id'],
                    'name' => $m['name'],
                    'city' => $m['city'],
                    'distancia' => round($dist, 2),
                    'tempo' => $tempo,
                    'taxa' => $m['delivery_fee'],
                    'minimo' => $m['min_order'],
                    'shoppers' => $shoppersOnline,
                    'deliverys' => $deliverysOnline,
                    'disponivel' => $shoppersOnline > 0 && $deliverysOnline > 0
                ];
            }
        }
        
        usort($mercadosProximos, fn($a, $b) => $a['distancia'] <=> $b['distancia']);
        
        echo json_encode([
            'success' => true,
            'cliente' => $cliente,
            'mercados' => $mercadosProximos,
            'total' => count($mercadosProximos)
        ]);
        exit;
    }
    
    // Atualizar GPS (simular movimento)
    if ($action === 'simular_movimento') {
        // Mover shoppers online aleatoriamente (até 100m)
        $pdo->exec("UPDATE om_market_shoppers SET 
                    current_lat = current_lat + (RANDOM() - 0.5) * 0.001,
                    current_lng = current_lng + (RANDOM() - 0.5) * 0.001,
                    last_location_at = NOW()
                    WHERE is_online = 1");
        
        // Mover deliverys
        $pdo->exec("UPDATE om_market_delivery SET 
                    current_lat = current_lat + (RANDOM() - 0.5) * 0.002,
                    current_lng = current_lng + (RANDOM() - 0.5) * 0.002,
                    last_location_at = NOW()
                    WHERE is_online = 1");
        
        echo json_encode(['success' => true, 'message' => 'GPS atualizado']);
        exit;
    }
    
    exit;
}

function calcDistancia($lat1, $lng1, $lat2, $lng2) {
    if (!$lat1 || !$lng1 || !$lat2 || !$lng2) return 999;
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}

// ══════════════════════════════════════════════════════════════════════════════
// BUSCAR DADOS
// ══════════════════════════════════════════════════════════════════════════════

$mercados = $pdo->query("SELECT * FROM om_market_partners WHERE status = '1' ORDER BY city, name")->fetchAll();
$shoppers = $pdo->query("SELECT * FROM om_market_shoppers ORDER BY city, is_online DESC, name")->fetchAll();
$deliverys = $pdo->query("SELECT * FROM om_market_delivery ORDER BY city, is_online DESC, name")->fetchAll();
$clientes = $pdo->query("SELECT * FROM om_sim_customers ORDER BY city, name")->fetchAll();

// Agrupar por cidade
$shoppersPorCidade = [];
$deliverysPorCidade = [];
foreach ($shoppers as $s) {
    $shoppersPorCidade[$s['city']][] = $s;
}
foreach ($deliverys as $d) {
    $deliverysPorCidade[$d['city']][] = $d;
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>🎮 Simulador OneMundo Mercado</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', sans-serif; background: #0f172a; color: #fff; min-height: 100vh; }

.header { background: linear-gradient(135deg, #1e293b, #334155); padding: 20px; text-align: center; border-bottom: 1px solid #334155; }
.header h1 { font-size: 24px; margin-bottom: 8px; }
.header p { color: #64748b; }

.container { max-width: 1400px; margin: 0 auto; padding: 20px; }

.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; }

.card { background: #1e293b; border-radius: 16px; padding: 20px; }
.card h3 { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #334155; }
.card h3 .count { margin-left: auto; background: #334155; padding: 4px 12px; border-radius: 20px; font-size: 14px; }

.item { display: flex; align-items: center; gap: 12px; padding: 10px; border-radius: 8px; margin-bottom: 8px; background: #0f172a; }
.item:hover { background: #1a2744; }
.item .status { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.item .status.online { background: #10b981; box-shadow: 0 0 8px #10b981; }
.item .status.offline { background: #64748b; }
.item .status.busy { background: #f59e0b; box-shadow: 0 0 8px #f59e0b; }
.item .status.closed { background: #ef4444; }
.item .info { flex: 1; }
.item .name { font-weight: 600; font-size: 14px; }
.item .details { color: #64748b; font-size: 12px; }
.item .toggle { background: none; border: none; cursor: pointer; padding: 4px 8px; border-radius: 4px; font-size: 18px; }
.item .toggle:hover { background: #334155; }

.city-group { margin-bottom: 20px; }
.city-group h4 { color: #94a3b8; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; padding-left: 8px; }

.cliente-item { cursor: pointer; transition: all 0.2s; }
.cliente-item:hover { transform: translateX(4px); }
.cliente-item.selected { background: #1e40af; border: 1px solid #3b82f6; }

.resultado { margin-top: 20px; padding: 16px; background: #0f172a; border-radius: 12px; display: none; }
.resultado.show { display: block; }
.resultado h4 { margin-bottom: 12px; }

.mercado-result { padding: 12px; background: #1e293b; border-radius: 8px; margin-bottom: 8px; }
.mercado-result.indisponivel { opacity: 0.5; }
.mercado-result .header-result { display: flex; justify-content: space-between; align-items: center; }
.mercado-result .stats { display: flex; gap: 16px; margin-top: 8px; font-size: 12px; color: #64748b; }

.no-mercado { padding: 20px; text-align: center; background: linear-gradient(135deg, #7f1d1d, #991b1b); border-radius: 12px; }
.no-mercado h4 { margin-bottom: 8px; }

.btn { padding: 12px 24px; border-radius: 8px; font-weight: 600; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
.btn-green { background: #10b981; color: #fff; }
.btn-blue { background: #3b82f6; color: #fff; }
.btn-purple { background: #8b5cf6; color: #fff; }

.actions { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }

.stats-bar { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
.stat-item { background: #1e293b; padding: 16px; border-radius: 12px; text-align: center; }
.stat-item .value { font-size: 28px; font-weight: 800; color: #10b981; }
.stat-item .label { color: #64748b; font-size: 12px; }
</style>
</head>
<body>

<div class="header">
    <h1>🎮 Simulador OneMundo Mercado</h1>
    <p>Controle shoppers, deliverys e teste o sistema de distribuição</p>
</div>

<div class="container">
    
    <div class="stats-bar">
        <div class="stat-item">
            <div class="value"><?= count($mercados) ?></div>
            <div class="label">🏪 Mercados</div>
        </div>
        <div class="stat-item">
            <div class="value"><?= count(array_filter($shoppers, fn($s) => $s['is_online'])) ?>/<?= count($shoppers) ?></div>
            <div class="label">👷 Shoppers Online</div>
        </div>
        <div class="stat-item">
            <div class="value"><?= count(array_filter($deliverys, fn($d) => $d['is_online'])) ?>/<?= count($deliverys) ?></div>
            <div class="label">🚴 Deliverys Online</div>
        </div>
        <div class="stat-item">
            <div class="value"><?= count($clientes) ?></div>
            <div class="label">👤 Clientes</div>
        </div>
    </div>
    
    <div class="actions">
        <button class="btn btn-green" onclick="simularMovimento()">📍 Simular Movimento GPS</button>
        <button class="btn btn-blue" onclick="location.reload()">🔄 Atualizar</button>
        <button class="btn btn-purple" onclick="location.href='SIMULADOR_SETUP.php'">⚙️ Recriar Dados</button>
    </div>
    
    <div class="grid">
        
        <!-- MERCADOS -->
        <div class="card">
            <h3>🏪 Mercados <span class="count"><?= count($mercados) ?></span></h3>
            <?php foreach ($mercados as $m): ?>
            <div class="item">
                <div class="status <?= $m['is_open'] ? 'online' : 'closed' ?>"></div>
                <div class="info">
                    <div class="name"><?= $m['name'] ?></div>
                    <div class="details"><?= $m['city'] ?> | Raio: <?= $m['delivery_radius'] ?>km | Taxa: R$ <?= number_format($m['delivery_fee'], 2, ',', '.') ?></div>
                </div>
                <button class="toggle" onclick="toggleMercado(<?= $m['partner_id'] ?>)" title="Abrir/Fechar">
                    <?= $m['is_open'] ? '🟢' : '🔴' ?>
                </button>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- SHOPPERS -->
        <div class="card">
            <h3>👷 Shoppers <span class="count"><?= count($shoppers) ?></span></h3>
            <?php foreach ($shoppersPorCidade as $cidade => $lista): ?>
            <div class="city-group">
                <h4><?= $cidade ?> (<?= count($lista) ?>)</h4>
                <?php foreach ($lista as $s): ?>
                <div class="item">
                    <div class="status <?= $s['is_online'] ? ($s['is_busy'] ? 'busy' : 'online') : 'offline' ?>"></div>
                    <div class="info">
                        <div class="name"><?= $s['name'] ?></div>
                        <div class="details">⭐<?= number_format($s['rating'], 1) ?> | <?= $s['accept_rate'] ?>% aceite</div>
                    </div>
                    <button class="toggle" onclick="toggleShopper(<?= $s['shopper_id'] ?>)" title="Online/Offline">
                        <?= $s['is_online'] ? '🟢' : '⚫' ?>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- DELIVERYS -->
        <div class="card">
            <h3>🚴 Deliverys <span class="count"><?= count($deliverys) ?></span></h3>
            <?php foreach ($deliverysPorCidade as $cidade => $lista): ?>
            <div class="city-group">
                <h4><?= $cidade ?> (<?= count($lista) ?>)</h4>
                <?php foreach ($lista as $d): ?>
                <div class="item">
                    <div class="status <?= $d['is_online'] ? 'online' : 'offline' ?>"></div>
                    <div class="info">
                        <div class="name"><?= $d['name'] ?> <?= $d['vehicle'] == 'moto' ? '🏍️' : ($d['vehicle'] == 'bike' ? '🚴' : '🚗') ?></div>
                        <div class="details">⭐<?= number_format($d['rating'], 1) ?> | <?= $d['accept_rate'] ?>% aceite</div>
                    </div>
                    <button class="toggle" onclick="toggleDelivery(<?= $d['delivery_id'] ?>)" title="Online/Offline">
                        <?= $d['is_online'] ? '🟢' : '⚫' ?>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- CLIENTES / TESTE -->
        <div class="card">
            <h3>👤 Testar Cliente <span class="count"><?= count($clientes) ?></span></h3>
            <p style="color:#64748b;font-size:13px;margin-bottom:12px;">Clique em um cliente para ver os mercados disponíveis:</p>
            
            <?php foreach ($clientes as $c): ?>
            <div class="item cliente-item" onclick="buscarMercados(<?= $c['id'] ?>, this)">
                <div class="status online"></div>
                <div class="info">
                    <div class="name"><?= $c['name'] ?></div>
                    <div class="details"><?= $c['city'] ?> | CEP: <?= $c['cep'] ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <div class="resultado" id="resultado"></div>
        </div>
        
    </div>
    
</div>

<script>
async function toggleShopper(id) {
    const res = await fetch(`?ajax=toggle_shopper&id=${id}`);
    const data = await res.json();
    if (data.success) location.reload();
}

async function toggleDelivery(id) {
    const res = await fetch(`?ajax=toggle_delivery&id=${id}`);
    const data = await res.json();
    if (data.success) location.reload();
}

async function toggleMercado(id) {
    const res = await fetch(`?ajax=toggle_mercado&id=${id}`);
    const data = await res.json();
    if (data.success) location.reload();
}

async function simularMovimento() {
    const res = await fetch(`?ajax=simular_movimento`);
    const data = await res.json();
    alert('📍 GPS de todos os workers atualizado!');
}

async function buscarMercados(clienteId, el) {
    // Highlight selecionado
    document.querySelectorAll('.cliente-item').forEach(e => e.classList.remove('selected'));
    el.classList.add('selected');
    
    const res = await fetch(`?ajax=buscar_mercados&cliente_id=${clienteId}`);
    const data = await res.json();
    
    const resultado = document.getElementById('resultado');
    resultado.classList.add('show');
    
    if (!data.success) {
        resultado.innerHTML = `<div class="no-mercado"><h4>❌ Erro</h4><p>${data.error}</p></div>`;
        return;
    }
    
    const cliente = data.cliente;
    
    if (data.total === 0) {
        resultado.innerHTML = `
            <div class="no-mercado">
                <h4>😔 Ainda não estamos na sua região</h4>
                <p>Infelizmente não há mercados disponíveis para <strong>${cliente.city}</strong> no momento.</p>
                <p style="margin-top:8px;font-size:13px;opacity:0.8;">Cadastre-se para ser avisado quando chegarmos aí!</p>
            </div>
        `;
        return;
    }
    
    let html = `<h4>🏪 ${data.total} mercado(s) disponível(is) para ${cliente.name}</h4>`;
    
    data.mercados.forEach(m => {
        const disponivel = m.disponivel;
        html += `
            <div class="mercado-result ${disponivel ? '' : 'indisponivel'}">
                <div class="header-result">
                    <strong>${m.name}</strong>
                    <span>${disponivel ? '✅ Disponível' : '⚠️ Sem entregadores'}</span>
                </div>
                <div class="stats">
                    <span>📍 ${m.distancia}km</span>
                    <span>⏱️ ~${m.tempo}min</span>
                    <span>🛵 R$ ${parseFloat(m.taxa).toFixed(2).replace('.', ',')}</span>
                    <span>👷 ${m.shoppers} shoppers</span>
                    <span>🚴 ${m.deliverys} deliverys</span>
                </div>
            </div>
        `;
    });
    
    resultado.innerHTML = html;
}
</script>

</body>
</html>
