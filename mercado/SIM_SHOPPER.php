<?php
require_once __DIR__ . '/config/database.php';
/**
 * 👷 SIMULADOR - SHOPPER
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

$pdo = getPDO();

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax'];
    
    if ($action === 'get_ofertas') {
        $shopperId = intval($_GET['shopper_id'] ?? 0);
        $ofertas = $pdo->query("
            SELECT o.*, ord.order_number, ord.total, p.name as mercado_nome, c.name as cliente_nome
            FROM om_sim_offers o
            JOIN om_sim_orders ord ON o.order_id = ord.id
            LEFT JOIN om_market_partners p ON ord.partner_id = p.partner_id
            LEFT JOIN om_sim_customers c ON ord.customer_id = c.id
            WHERE o.worker_type = 'shopper' AND o.worker_id = $shopperId AND o.status = 'pending'
            AND ord.status = 'aguardando_shopper'
        ")->fetchAll();
        echo json_encode(['success' => true, 'ofertas' => $ofertas]);
        exit;
    }
    
    if ($action === 'aceitar_oferta') {
        $input = json_decode(file_get_contents('php://input'), true);
        $offerId = intval($input['offer_id'] ?? 0);
        $shopperId = intval($input['shopper_id'] ?? 0);
        
        $oferta = $pdo->query("SELECT * FROM om_sim_offers WHERE id = $offerId")->fetch();
        $pedido = $pdo->query("SELECT * FROM om_sim_orders WHERE id = {$oferta['order_id']} AND status = 'aguardando_shopper'")->fetch();
        
        if (!$pedido) {
            echo json_encode(['success' => false, 'error' => 'Pedido já aceito']);
            exit;
        }
        
        $ganho = max(5, $pedido['total'] * 0.05);
        $pdo->exec("UPDATE om_sim_offers SET status = 'accepted' WHERE id = $offerId");
        $pdo->exec("UPDATE om_sim_orders SET status = 'shopper_aceito', shopper_id = $shopperId, shopper_earning = $ganho WHERE id = {$oferta['order_id']}");
        $pdo->exec("UPDATE om_market_shoppers SET is_busy = 1 WHERE shopper_id = $shopperId");
        
        $nome = $pdo->query("SELECT name FROM om_market_shoppers WHERE shopper_id = $shopperId")->fetchColumn();
        $pdo->prepare("INSERT INTO om_sim_chat (order_id, sender_type, sender_name, message) VALUES (?, 'system', 'Sistema', ?)")
            ->execute([$oferta['order_id'], "👷 $nome aceitou seu pedido!"]);
        
        echo json_encode(['success' => true, 'order_id' => $oferta['order_id'], 'ganho' => $ganho]);
        exit;
    }
    
    if ($action === 'get_pedido') {
        $shopperId = intval($_GET['shopper_id'] ?? 0);
        $pedido = $pdo->query("
            SELECT o.*, p.name as mercado_nome, c.name as cliente_nome, c.address as cliente_endereco
            FROM om_sim_orders o
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            LEFT JOIN om_sim_customers c ON o.customer_id = c.id
            WHERE o.shopper_id = $shopperId AND o.status IN ('shopper_aceito', 'em_compra', 'compra_finalizada')
            LIMIT 1
        ")->fetch();
        
        if (!$pedido) { echo json_encode(['success' => false]); exit; }
        
        $itens = $pdo->query("SELECT * FROM om_sim_order_items WHERE order_id = {$pedido['id']}")->fetchAll();
        $chat = $pdo->query("SELECT * FROM om_sim_chat WHERE order_id = {$pedido['id']} ORDER BY created_at")->fetchAll();
        
        echo json_encode(['success' => true, 'pedido' => $pedido, 'itens' => $itens, 'chat' => $chat]);
        exit;
    }
    
    if ($action === 'iniciar_compra') {
        $input = json_decode(file_get_contents('php://input'), true);
        $orderId = intval($input['order_id'] ?? 0);
        $pdo->exec("UPDATE om_sim_orders SET status = 'em_compra' WHERE id = $orderId");
        $pdo->prepare("INSERT INTO om_sim_chat (order_id, sender_type, sender_name, message) VALUES (?, 'system', 'Sistema', ?)")
            ->execute([$orderId, "🛒 Shopper começou suas compras!"]);
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action === 'toggle_item') {
        $input = json_decode(file_get_contents('php://input'), true);
        $itemId = intval($input['item_id'] ?? 0);
        $status = $input['coletado'] ? 'coletado' : 'pendente';
        $pdo->exec("UPDATE om_sim_order_items SET status = '$status' WHERE id = $itemId");
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action === 'finalizar_compra') {
        $input = json_decode(file_get_contents('php://input'), true);
        $orderId = intval($input['order_id'] ?? 0);
        $shopperId = intval($input['shopper_id'] ?? 0);
        
        $palavras = ["BANANA", "LARANJA", "MORANGO", "ABACAXI", "MELANCIA", "MANGA", "UVA"];
        $codigo = $palavras[array_rand($palavras)] . '-' . rand(100, 999);
        
        $pdo->exec("UPDATE om_sim_orders SET status = 'compra_finalizada', delivery_code = '$codigo' WHERE id = $orderId");
        $pdo->exec("UPDATE om_market_shoppers SET is_busy = 0 WHERE shopper_id = $shopperId");
        
        $pdo->prepare("INSERT INTO om_sim_chat (order_id, sender_type, sender_name, message) VALUES (?, 'system', 'Sistema', ?)")
            ->execute([$orderId, "✅ Compras finalizadas! Código: $codigo"]);
        
        // Disparar delivery
        $pedido = $pdo->query("SELECT o.*, p.city FROM om_sim_orders o JOIN om_market_partners p ON o.partner_id = p.partner_id WHERE o.id = $orderId")->fetch();
        $deliverys = $pdo->query("SELECT * FROM om_market_delivery WHERE is_online = 1 AND city = '{$pedido['city']}' LIMIT 5")->fetchAll();
        foreach ($deliverys as $d) {
            $pdo->prepare("INSERT INTO om_sim_offers (order_id, worker_type, worker_id, score) VALUES (?, 'delivery', ?, ?)")
                ->execute([$orderId, $d['delivery_id'], rand(70, 100)]);
        }
        $pdo->exec("UPDATE om_sim_orders SET status = 'aguardando_delivery' WHERE id = $orderId");
        $pdo->prepare("INSERT INTO om_sim_chat (order_id, sender_type, sender_name, message) VALUES (?, 'system', 'Sistema', ?)")
            ->execute([$orderId, "🔍 Buscando entregador..."]);
        
        echo json_encode(['success' => true, 'codigo' => $codigo]);
        exit;
    }
    
    if ($action === 'send_chat') {
        $input = json_decode(file_get_contents('php://input'), true);
        $orderId = intval($input['order_id'] ?? 0);
        $shopperId = intval($input['shopper_id'] ?? 0);
        $message = $input['message'] ?? '';
        $nome = $pdo->query("SELECT name FROM om_market_shoppers WHERE shopper_id = $shopperId")->fetchColumn();
        $pdo->prepare("INSERT INTO om_sim_chat (order_id, sender_type, sender_name, message) VALUES (?, 'shopper', ?, ?)")
            ->execute([$orderId, $nome, $message]);
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action === 'toggle_online') {
        $shopperId = intval($_GET['shopper_id'] ?? 0);
        $pdo->exec("UPDATE om_market_shoppers SET is_online = NOT is_online WHERE shopper_id = $shopperId");
        $status = $pdo->query("SELECT is_online FROM om_market_shoppers WHERE shopper_id = $shopperId")->fetchColumn();
        echo json_encode(['success' => true, 'is_online' => $status]);
        exit;
    }
    exit;
}

$shoppers = $pdo->query("SELECT * FROM om_market_shoppers ORDER BY city, name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>👷 Shopper</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', sans-serif; background: #0f172a; color: #fff; min-height: 100vh; }
.header { background: linear-gradient(135deg, #f59e0b, #d97706); padding: 16px; }
.header h1 { font-size: 20px; }
.container { max-width: 500px; margin: 0 auto; padding: 16px; }
.card { background: #1e293b; border-radius: 16px; padding: 20px; margin-bottom: 16px; }
.card h3 { margin-bottom: 16px; }
.select-box { width: 100%; padding: 14px; border: 2px solid #334155; border-radius: 12px; background: #0f172a; color: #fff; margin-bottom: 12px; }
.btn { width: 100%; padding: 14px; border-radius: 12px; font-weight: 700; border: none; cursor: pointer; margin-bottom: 8px; }
.btn-orange { background: #f59e0b; color: #fff; }
.btn-green { background: #10b981; color: #fff; }
.btn-gray { background: #475569; color: #fff; }
.oferta-card { background: #0f172a; border: 2px solid #334155; border-radius: 12px; padding: 16px; margin-bottom: 12px; }
.oferta-card h4 { color: #f59e0b; margin-bottom: 8px; }
.oferta-card .valor { font-size: 24px; font-weight: 800; color: #10b981; }
.item-lista { display: flex; align-items: center; gap: 12px; padding: 12px; background: #0f172a; border-radius: 8px; margin-bottom: 8px; cursor: pointer; }
.item-lista.coletado { background: #064e3b; }
.item-lista .check { width: 24px; height: 24px; border: 2px solid #475569; border-radius: 6px; display: flex; align-items: center; justify-content: center; }
.item-lista.coletado .check { background: #10b981; border-color: #10b981; }
.progress-bar { height: 8px; background: #334155; border-radius: 4px; margin: 12px 0; }
.progress-bar .fill { height: 100%; background: #10b981; }
.codigo-box { background: linear-gradient(135deg, #10b981, #059669); border-radius: 16px; padding: 24px; text-align: center; margin: 16px 0; }
.codigo-box .code { font-size: 32px; font-weight: 800; letter-spacing: 3px; }
.chat-box { max-height: 200px; overflow-y: auto; background: #0f172a; border-radius: 12px; padding: 12px; margin-bottom: 12px; }
.chat-msg { margin-bottom: 8px; padding: 8px 12px; border-radius: 12px; max-width: 85%; font-size: 14px; }
.chat-msg.system { background: #334155; color: #94a3b8; text-align: center; max-width: 100%; }
.chat-msg.customer { background: #10b981; margin-left: auto; }
.chat-msg.shopper { background: #f59e0b; }
.chat-msg .sender { font-size: 11px; opacity: 0.8; }
.chat-input { display: flex; gap: 8px; }
.chat-input input { flex: 1; padding: 12px; border: 2px solid #334155; border-radius: 12px; background: #0f172a; color: #fff; }
.chat-input button { padding: 12px 16px; background: #f59e0b; border: none; border-radius: 12px; color: #fff; cursor: pointer; }
.empty-state { text-align: center; padding: 40px; color: #64748b; }
.step { display: none; }
.step.active { display: block; }
.hidden { display: none; }
</style>
</head>
<body>
<div class="header"><h1>👷 Shopper OneMundo</h1></div>
<div class="container">
    <div class="step active" id="step-login">
        <div class="card">
            <h3>Entrar como Shopper</h3>
            <select class="select-box" id="selectShopper">
                <option value="">Selecione...</option>
                <?php foreach ($shoppers as $s): ?>
                <option value="<?= $s['shopper_id'] ?>"><?= $s['is_online'] ? '🟢' : '⚫' ?> <?= $s['name'] ?> - <?= $s['city'] ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-orange" onclick="entrar()">Entrar</button>
        </div>
    </div>
    <div class="step" id="step-dashboard">
        <div class="card">
            <h3>📋 Ofertas</h3>
            <div id="listaOfertas"><div class="empty-state">📭 Nenhuma oferta</div></div>
        </div>
        <button class="btn btn-gray" onclick="sair()">← Sair</button>
    </div>
    <div class="step" id="step-pedido">
        <div class="card">
            <h3>🛒 Pedido <span id="pedidoNum"></span></h3>
            <p id="pedidoInfo" style="color:#94a3b8;"></p>
            <div class="progress-bar"><div class="fill" id="progressBar" style="width:0%"></div></div>
            <p id="progressText" style="text-align:center;color:#94a3b8;font-size:13px;">0/0 itens</p>
        </div>
        <div class="card"><h3>📝 Lista</h3><div id="listaItens"></div></div>
        <button class="btn btn-orange" id="btnIniciar" onclick="iniciarCompra()">🛒 Iniciar Compras</button>
        <button class="btn btn-green hidden" id="btnFinalizar" onclick="finalizarCompra()">✅ Finalizar</button>
        <div class="card hidden" id="cardCodigo">
            <div class="codigo-box"><div class="code" id="codigoGerado">---</div></div>
        </div>
        <div class="card">
            <h3>💬 Chat</h3>
            <div class="chat-box" id="chatBox"></div>
            <div class="chat-input">
                <input type="text" id="chatMsg" placeholder="Mensagem...">
                <button onclick="enviarChat()">Enviar</button>
            </div>
        </div>
    </div>
</div>
<script>
let shopper = null, pedido = null, polling = null;
function showStep(s) { document.querySelectorAll('.step').forEach(e => e.classList.remove('active')); document.getElementById('step-'+s).classList.add('active'); }

async function entrar() {
    const id = document.getElementById('selectShopper').value;
    if (!id) return alert('Selecione');
    shopper = { id: parseInt(id) };
    showStep('dashboard');
    await verificarPedido();
    polling = setInterval(atualizar, 3000);
}

async function verificarPedido() {
    const res = await fetch(`?ajax=get_pedido&shopper_id=${shopper.id}`);
    const data = await res.json();
    if (data.success && data.pedido) { pedido = data.pedido; mostrarPedido(data); }
}

async function atualizar() {
    await verificarPedido();
    if (pedido) return;
    const res = await fetch(`?ajax=get_ofertas&shopper_id=${shopper.id}`);
    const data = await res.json();
    if (data.ofertas?.length > 0) {
        document.getElementById('listaOfertas').innerHTML = data.ofertas.map(o => `
            <div class="oferta-card">
                <h4>🏪 ${o.mercado_nome}</h4>
                <p style="color:#94a3b8;">👤 ${o.cliente_nome} | #${o.order_number}</p>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;">
                    <div class="valor">R$ ${(o.total * 0.05).toFixed(2).replace('.', ',')}</div>
                    <button class="btn btn-green" style="width:auto;padding:10px 20px;" onclick="aceitar(${o.id})">Aceitar</button>
                </div>
            </div>
        `).join('');
    } else {
        document.getElementById('listaOfertas').innerHTML = '<div class="empty-state">📭 Nenhuma oferta</div>';
    }
}

async function aceitar(id) {
    const res = await fetch('?ajax=aceitar_oferta', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({offer_id: id, shopper_id: shopper.id}) });
    const data = await res.json();
    if (!data.success) return alert(data.error);
    alert('✅ Aceito! Ganho: R$ ' + data.ganho.toFixed(2));
    verificarPedido();
}

function mostrarPedido(data) {
    const p = data.pedido, itens = data.itens;
    document.getElementById('pedidoNum').textContent = '#' + p.order_number;
    document.getElementById('pedidoInfo').textContent = '🏪 ' + p.mercado_nome + ' | 👤 ' + p.cliente_nome;
    
    const coletados = itens.filter(i => i.status === 'coletado').length;
    document.getElementById('progressBar').style.width = (coletados/itens.length*100) + '%';
    document.getElementById('progressText').textContent = `${coletados}/${itens.length} itens`;
    
    document.getElementById('listaItens').innerHTML = itens.map(i => `
        <div class="item-lista ${i.status === 'coletado' ? 'coletado' : ''}" onclick="toggleItem(${i.id}, ${i.status !== 'coletado'})">
            <div class="check">${i.status === 'coletado' ? '✓' : ''}</div>
            <div>${i.product_name} (${i.quantity}x)</div>
        </div>
    `).join('');
    
    document.getElementById('btnIniciar').classList.toggle('hidden', p.status !== 'shopper_aceito');
    document.getElementById('btnFinalizar').classList.toggle('hidden', p.status !== 'em_compra');
    document.getElementById('cardCodigo').classList.toggle('hidden', p.status !== 'compra_finalizada' && p.status !== 'aguardando_delivery');
    if (p.delivery_code) document.getElementById('codigoGerado').textContent = p.delivery_code;
    
    const chatBox = document.getElementById('chatBox');
    chatBox.innerHTML = data.chat.map(m => `<div class="chat-msg ${m.sender_type}"><div class="sender">${m.sender_name}</div>${m.message}</div>`).join('');
    chatBox.scrollTop = chatBox.scrollHeight;
    
    showStep('pedido');
}

async function iniciarCompra() {
    await fetch('?ajax=iniciar_compra', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({order_id: pedido.id}) });
    verificarPedido();
}

async function toggleItem(id, marcar) {
    await fetch('?ajax=toggle_item', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({item_id: id, coletado: marcar}) });
    verificarPedido();
}

async function finalizarCompra() {
    if (!confirm('Finalizar compras?')) return;
    const res = await fetch('?ajax=finalizar_compra', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({order_id: pedido.id, shopper_id: shopper.id}) });
    const data = await res.json();
    alert('✅ Código: ' + data.codigo);
    verificarPedido();
}

async function enviarChat() {
    const input = document.getElementById('chatMsg');
    if (!input.value.trim()) return;
    await fetch('?ajax=send_chat', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({order_id: pedido.id, shopper_id: shopper.id, message: input.value}) });
    input.value = '';
    verificarPedido();
}

function sair() { clearInterval(polling); shopper = pedido = null; showStep('login'); }
</script>
</body>
</html>
