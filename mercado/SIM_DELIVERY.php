<?php
require_once __DIR__ . '/config/database.php';
/**
 * 🚴 SIMULADOR - DELIVERY
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

$pdo = getPDO();

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax'];
    
    if ($action === 'get_ofertas') {
        $deliveryId = intval($_GET['delivery_id'] ?? 0);
        $ofertas = $pdo->query("
            SELECT o.*, ord.order_number, ord.total, ord.delivery_code, ord.delivery_address,
                   p.name as mercado_nome, p.address as mercado_endereco,
                   c.name as cliente_nome
            FROM om_sim_offers o
            JOIN om_sim_orders ord ON o.order_id = ord.id
            LEFT JOIN om_market_partners p ON ord.partner_id = p.partner_id
            LEFT JOIN om_sim_customers c ON ord.customer_id = c.id
            WHERE o.worker_type = 'delivery' AND o.worker_id = $deliveryId AND o.status = 'pending'
            AND ord.status = 'aguardando_delivery'
        ")->fetchAll();
        echo json_encode(['success' => true, 'ofertas' => $ofertas]);
        exit;
    }
    
    if ($action === 'aceitar_oferta') {
        $input = json_decode(file_get_contents('php://input'), true);
        $offerId = intval($input['offer_id'] ?? 0);
        $deliveryId = intval($input['delivery_id'] ?? 0);
        
        $oferta = $pdo->query("SELECT * FROM om_sim_offers WHERE id = $offerId")->fetch();
        $pedido = $pdo->query("SELECT * FROM om_sim_orders WHERE id = {$oferta['order_id']} AND status = 'aguardando_delivery'")->fetch();
        
        if (!$pedido) {
            echo json_encode(['success' => false, 'error' => 'Pedido já aceito']);
            exit;
        }
        
        $ganho = 5.00;
        $pdo->exec("UPDATE om_sim_offers SET status = 'accepted' WHERE id = $offerId");
        $pdo->exec("UPDATE om_sim_orders SET status = 'delivery_aceito', delivery_id = $deliveryId, delivery_earning = $ganho WHERE id = {$oferta['order_id']}");
        $pdo->exec("UPDATE om_market_delivery SET active_order_id = {$oferta['order_id']} WHERE delivery_id = $deliveryId");
        
        $nome = $pdo->query("SELECT name FROM om_market_delivery WHERE delivery_id = $deliveryId")->fetchColumn();
        $pdo->prepare("INSERT INTO om_sim_chat (order_id, sender_type, sender_name, message) VALUES (?, 'system', 'Sistema', ?)")
            ->execute([$oferta['order_id'], "🚴 $nome aceitou a entrega!"]);
        
        echo json_encode(['success' => true, 'order_id' => $oferta['order_id'], 'ganho' => $ganho]);
        exit;
    }
    
    if ($action === 'get_pedido') {
        $deliveryId = intval($_GET['delivery_id'] ?? 0);
        $pedido = $pdo->query("
            SELECT o.*, p.name as mercado_nome, p.address as mercado_endereco,
                   c.name as cliente_nome, c.address as cliente_endereco, c.phone as cliente_phone
            FROM om_sim_orders o
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            LEFT JOIN om_sim_customers c ON o.customer_id = c.id
            WHERE o.delivery_id = $deliveryId AND o.status IN ('delivery_aceito', 'em_entrega')
            LIMIT 1
        ")->fetch();
        
        if (!$pedido) { echo json_encode(['success' => false]); exit; }
        
        $chat = $pdo->query("SELECT * FROM om_sim_chat WHERE order_id = {$pedido['id']} ORDER BY created_at")->fetchAll();
        echo json_encode(['success' => true, 'pedido' => $pedido, 'chat' => $chat]);
        exit;
    }
    
    if ($action === 'coletar') {
        $input = json_decode(file_get_contents('php://input'), true);
        $orderId = intval($input['order_id'] ?? 0);
        
        $pdo->exec("UPDATE om_sim_orders SET status = 'em_entrega' WHERE id = $orderId");
        $pdo->prepare("INSERT INTO om_sim_chat (order_id, sender_type, sender_name, message) VALUES (?, 'system', 'Sistema', ?)")
            ->execute([$orderId, "📦 Pedido coletado! Entregador a caminho!"]);
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action === 'confirmar_entrega') {
        $input = json_decode(file_get_contents('php://input'), true);
        $orderId = intval($input['order_id'] ?? 0);
        $codigo = strtoupper(trim($input['codigo'] ?? ''));
        $deliveryId = intval($input['delivery_id'] ?? 0);
        
        $pedido = $pdo->query("SELECT * FROM om_sim_orders WHERE id = $orderId")->fetch();
        
        if (strtoupper($pedido['delivery_code']) !== $codigo) {
            echo json_encode(['success' => false, 'error' => 'Código incorreto']);
            exit;
        }
        
        $pdo->exec("UPDATE om_sim_orders SET status = 'entregue', delivery_code_confirmed = 1, delivered_at = NOW() WHERE id = $orderId");
        $pdo->exec("UPDATE om_market_delivery SET active_order_id = NULL WHERE delivery_id = $deliveryId");
        
        $pdo->prepare("INSERT INTO om_sim_chat (order_id, sender_type, sender_name, message) VALUES (?, 'system', 'Sistema', ?)")
            ->execute([$orderId, "✅ Pedido entregue com sucesso! 🎉"]);
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action === 'send_chat') {
        $input = json_decode(file_get_contents('php://input'), true);
        $orderId = intval($input['order_id'] ?? 0);
        $deliveryId = intval($input['delivery_id'] ?? 0);
        $message = $input['message'] ?? '';
        $nome = $pdo->query("SELECT name FROM om_market_delivery WHERE delivery_id = $deliveryId")->fetchColumn();
        $pdo->prepare("INSERT INTO om_sim_chat (order_id, sender_type, sender_name, message) VALUES (?, 'delivery', ?, ?)")
            ->execute([$orderId, $nome, $message]);
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action === 'toggle_online') {
        $deliveryId = intval($_GET['delivery_id'] ?? 0);
        $pdo->exec("UPDATE om_market_delivery SET is_online = NOT is_online WHERE delivery_id = $deliveryId");
        $status = $pdo->query("SELECT is_online FROM om_market_delivery WHERE delivery_id = $deliveryId")->fetchColumn();
        echo json_encode(['success' => true, 'is_online' => $status]);
        exit;
    }
    exit;
}

$deliverys = $pdo->query("SELECT * FROM om_market_delivery ORDER BY city, name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>🚴 Delivery</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', sans-serif; background: #0f172a; color: #fff; min-height: 100vh; }
.header { background: linear-gradient(135deg, #3b82f6, #1d4ed8); padding: 16px; }
.header h1 { font-size: 20px; }
.container { max-width: 500px; margin: 0 auto; padding: 16px; }
.card { background: #1e293b; border-radius: 16px; padding: 20px; margin-bottom: 16px; }
.card h3 { margin-bottom: 16px; }
.select-box { width: 100%; padding: 14px; border: 2px solid #334155; border-radius: 12px; background: #0f172a; color: #fff; margin-bottom: 12px; }
.btn { width: 100%; padding: 14px; border-radius: 12px; font-weight: 700; border: none; cursor: pointer; margin-bottom: 8px; }
.btn-blue { background: #3b82f6; color: #fff; }
.btn-green { background: #10b981; color: #fff; }
.btn-gray { background: #475569; color: #fff; }
.oferta-card { background: #0f172a; border: 2px solid #334155; border-radius: 12px; padding: 16px; margin-bottom: 12px; }
.oferta-card h4 { color: #3b82f6; margin-bottom: 8px; }
.oferta-card .valor { font-size: 24px; font-weight: 800; color: #10b981; }
.info-box { background: #0f172a; border-radius: 12px; padding: 16px; margin-bottom: 12px; }
.info-box h4 { color: #3b82f6; margin-bottom: 8px; }
.info-box p { color: #94a3b8; font-size: 14px; line-height: 1.6; }
.codigo-box { background: linear-gradient(135deg, #10b981, #059669); border-radius: 16px; padding: 24px; text-align: center; margin: 16px 0; }
.codigo-box .label { font-size: 14px; opacity: 0.9; margin-bottom: 8px; }
.codigo-box .code { font-size: 32px; font-weight: 800; letter-spacing: 3px; }
.input-codigo { display: flex; gap: 8px; margin-top: 16px; }
.input-codigo input { flex: 1; padding: 14px; border: 2px solid #334155; border-radius: 12px; background: #0f172a; color: #fff; font-size: 18px; text-align: center; text-transform: uppercase; letter-spacing: 2px; }
.chat-box { max-height: 200px; overflow-y: auto; background: #0f172a; border-radius: 12px; padding: 12px; margin-bottom: 12px; }
.chat-msg { margin-bottom: 8px; padding: 8px 12px; border-radius: 12px; max-width: 85%; font-size: 14px; }
.chat-msg.system { background: #334155; color: #94a3b8; text-align: center; max-width: 100%; }
.chat-msg.customer { background: #10b981; margin-left: auto; }
.chat-msg.delivery { background: #3b82f6; }
.chat-msg .sender { font-size: 11px; opacity: 0.8; }
.chat-input { display: flex; gap: 8px; }
.chat-input input { flex: 1; padding: 12px; border: 2px solid #334155; border-radius: 12px; background: #0f172a; color: #fff; }
.chat-input button { padding: 12px 16px; background: #3b82f6; border: none; border-radius: 12px; color: #fff; cursor: pointer; }
.empty-state { text-align: center; padding: 40px; color: #64748b; }
.step { display: none; }
.step.active { display: block; }
.hidden { display: none; }
</style>
</head>
<body>
<div class="header"><h1>🚴 Delivery OneMundo</h1></div>
<div class="container">
    <div class="step active" id="step-login">
        <div class="card">
            <h3>Entrar como Delivery</h3>
            <select class="select-box" id="selectDelivery">
                <option value="">Selecione...</option>
                <?php foreach ($deliverys as $d): ?>
                <option value="<?= $d['delivery_id'] ?>"><?= $d['is_online'] ? '🟢' : '⚫' ?> <?= $d['name'] ?> (<?= $d['vehicle'] ?>) - <?= $d['city'] ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-blue" onclick="entrar()">Entrar</button>
        </div>
    </div>
    
    <div class="step" id="step-dashboard">
        <div class="card">
            <h3>📋 Entregas Disponíveis</h3>
            <div id="listaOfertas"><div class="empty-state">📭 Nenhuma entrega</div></div>
        </div>
        <button class="btn btn-gray" onclick="sair()">← Sair</button>
    </div>
    
    <div class="step" id="step-pedido">
        <div class="card">
            <h3>📦 Entrega <span id="pedidoNum"></span></h3>
            <div class="info-box">
                <h4>🏪 Coleta</h4>
                <p id="infoColeta"></p>
            </div>
            <div class="info-box">
                <h4>🏠 Entrega</h4>
                <p id="infoEntrega"></p>
            </div>
        </div>
        
        <button class="btn btn-blue" id="btnColetar" onclick="coletar()">📦 Coletei o Pedido</button>
        
        <div class="card hidden" id="cardConfirmar">
            <div class="codigo-box">
                <div class="label">Peça o código ao cliente:</div>
                <div class="code" id="codigoEsperado">???</div>
            </div>
            <div class="input-codigo">
                <input type="text" id="inputCodigo" placeholder="BANANA-123" maxlength="15">
                <button class="btn btn-green" style="width:auto;padding:14px 24px;" onclick="confirmarEntrega()">✓</button>
            </div>
        </div>
        
        <div class="card">
            <h3>💬 Chat com Cliente</h3>
            <div class="chat-box" id="chatBox"></div>
            <div class="chat-input">
                <input type="text" id="chatMsg" placeholder="Mensagem...">
                <button onclick="enviarChat()">Enviar</button>
            </div>
        </div>
    </div>
</div>
<script>
let delivery = null, pedido = null, polling = null;
function showStep(s) { document.querySelectorAll('.step').forEach(e => e.classList.remove('active')); document.getElementById('step-'+s).classList.add('active'); }

async function entrar() {
    const id = document.getElementById('selectDelivery').value;
    if (!id) return alert('Selecione');
    delivery = { id: parseInt(id) };
    showStep('dashboard');
    await verificarPedido();
    polling = setInterval(atualizar, 3000);
}

async function verificarPedido() {
    const res = await fetch(`?ajax=get_pedido&delivery_id=${delivery.id}`);
    const data = await res.json();
    if (data.success && data.pedido) { pedido = data.pedido; mostrarPedido(data); }
}

async function atualizar() {
    await verificarPedido();
    if (pedido) return;
    const res = await fetch(`?ajax=get_ofertas&delivery_id=${delivery.id}`);
    const data = await res.json();
    if (data.ofertas?.length > 0) {
        document.getElementById('listaOfertas').innerHTML = data.ofertas.map(o => `
            <div class="oferta-card">
                <h4>🏪 ${o.mercado_nome}</h4>
                <p style="color:#94a3b8;">📍 ${o.mercado_endereco}</p>
                <p style="color:#94a3b8;">🏠 ${o.delivery_address}</p>
                <p style="color:#94a3b8;">👤 ${o.cliente_nome}</p>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;">
                    <div class="valor">R$ 5,00</div>
                    <button class="btn btn-green" style="width:auto;padding:10px 20px;" onclick="aceitar(${o.id})">Aceitar</button>
                </div>
            </div>
        `).join('');
    } else {
        document.getElementById('listaOfertas').innerHTML = '<div class="empty-state">📭 Aguardando entregas...</div>';
    }
}

async function aceitar(id) {
    const res = await fetch('?ajax=aceitar_oferta', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({offer_id: id, delivery_id: delivery.id}) });
    const data = await res.json();
    if (!data.success) return alert(data.error);
    alert('✅ Aceito! Ganho: R$ ' + data.ganho.toFixed(2));
    verificarPedido();
}

function mostrarPedido(data) {
    const p = data.pedido;
    document.getElementById('pedidoNum').textContent = '#' + p.order_number;
    document.getElementById('infoColeta').innerHTML = `<strong>${p.mercado_nome}</strong><br>${p.mercado_endereco}`;
    document.getElementById('infoEntrega').innerHTML = `<strong>${p.cliente_nome}</strong><br>${p.cliente_endereco}<br>📞 ${p.cliente_phone || 'N/A'}`;
    
    document.getElementById('btnColetar').classList.toggle('hidden', p.status !== 'delivery_aceito');
    document.getElementById('cardConfirmar').classList.toggle('hidden', p.status !== 'em_entrega');
    
    if (p.delivery_code) {
        // Mostrar código mascarado para o delivery ver a dica
        document.getElementById('codigoEsperado').textContent = p.delivery_code.split('-')[0] + '-???';
    }
    
    const chatBox = document.getElementById('chatBox');
    chatBox.innerHTML = data.chat.map(m => `<div class="chat-msg ${m.sender_type}"><div class="sender">${m.sender_name}</div>${m.message}</div>`).join('');
    chatBox.scrollTop = chatBox.scrollHeight;
    
    showStep('pedido');
}

async function coletar() {
    await fetch('?ajax=coletar', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({order_id: pedido.id}) });
    alert('📦 Pedido coletado! Vá até o cliente.');
    verificarPedido();
}

async function confirmarEntrega() {
    const codigo = document.getElementById('inputCodigo').value;
    if (!codigo) return alert('Digite o código');
    
    const res = await fetch('?ajax=confirmar_entrega', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({order_id: pedido.id, codigo, delivery_id: delivery.id}) });
    const data = await res.json();
    
    if (!data.success) return alert('❌ ' + data.error);
    
    alert('🎉 Entrega confirmada!');
    pedido = null;
    showStep('dashboard');
    atualizar();
}

async function enviarChat() {
    const input = document.getElementById('chatMsg');
    if (!input.value.trim()) return;
    await fetch('?ajax=send_chat', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({order_id: pedido.id, delivery_id: delivery.id, message: input.value}) });
    input.value = '';
    verificarPedido();
}

function sair() { clearInterval(polling); delivery = pedido = null; showStep('login'); }
</script>
</body>
</html>
