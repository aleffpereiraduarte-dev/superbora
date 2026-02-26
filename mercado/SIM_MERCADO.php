<?php
require_once __DIR__ . '/config/database.php';
ini_set('display_errors', 1); error_reporting(E_ALL); session_start();
try { $pdo = getPDO(); } catch (Exception $e) { die("Erro: " . $e->getMessage()); }

function calcDist($lat1, $lng1, $lat2, $lng2) { if (!$lat1 || !$lng1 || !$lat2 || !$lng2) return 999; $R = 6371; $dLat = deg2rad($lat2 - $lat1); $dLon = deg2rad($lng2 - $lng1); $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2); return $R * 2 * atan2(sqrt($a), sqrt(1-$a)); }

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $a = $_GET['ajax'];
    
    if ($a === 'get_mercados') {
        $cid = intval($_GET['cliente_id'] ?? 0);
        $cli = $pdo->query("SELECT * FROM om_sim_customers WHERE id = $cid")->fetch();
        if (!$cli) { echo json_encode(['success' => false, 'error' => 'Cliente não encontrado']); exit; }
        $mercs = $pdo->query("SELECT * FROM om_market_partners WHERE status = '1'")->fetchAll();
        $prox = [];
        foreach ($mercs as $m) {
            $dist = calcDist($cli['lat'], $cli['lng'], $m['lat'], $m['lng']);
            if ($dist <= ($m['delivery_radius'] ?? 15) && ceil(($dist / 20) * 60) <= 45) {
                $shop = $pdo->query("SELECT COUNT(*) FROM om_market_shoppers WHERE is_online = 1 AND city = '" . addslashes($m['city']) . "'")->fetchColumn();
                $del = $pdo->query("SELECT COUNT(*) FROM om_market_delivery WHERE is_online = 1 AND city = '" . addslashes($m['city']) . "'")->fetchColumn();
                $prox[] = ['id' => $m['partner_id'], 'name' => $m['name'], 'city' => $m['city'], 'distancia' => round($dist, 2), 'tempo_min' => $m['delivery_time_min'] ?? 30, 'tempo_max' => $m['delivery_time_max'] ?? 60, 'taxa' => $m['delivery_fee'] ?? 9.90, 'minimo' => $m['min_order'] ?? 20, 'shoppers' => $shop, 'deliverys' => $del, 'disponivel' => $shop > 0 && $del > 0];
            }
        }
        usort($prox, fn($a, $b) => $a['distancia'] <=> $b['distancia']);
        echo json_encode(['success' => true, 'cliente' => $cli, 'mercados' => $prox]); exit;
    }
    
    if ($a === 'get_produtos') {
        $pid = intval($_GET['partner_id'] ?? 0);
        $prods = $pdo->query("SELECT * FROM om_market_products WHERE partner_id = $pid AND is_available = 1 ORDER BY category, name")->fetchAll();
        $cats = []; foreach ($prods as $p) { $cats[$p['category']][] = $p; }
        $merc = $pdo->query("SELECT * FROM om_market_partners WHERE partner_id = $pid")->fetch();
        echo json_encode(['success' => true, 'mercado' => $merc, 'categorias' => $cats, 'total' => count($prods)]); exit;
    }
    
    if ($a === 'checkout') {
        $in = json_decode(file_get_contents('php://input'), true);
        $cid = intval($in['cliente_id'] ?? 0); $pid = intval($in['partner_id'] ?? 0); $itens = $in['itens'] ?? [];
        if (!$cid || !$pid || empty($itens)) { echo json_encode(['success' => false, 'error' => 'Dados incompletos']); exit; }
        $cli = $pdo->query("SELECT * FROM om_sim_customers WHERE id = $cid")->fetch();
        $merc = $pdo->query("SELECT * FROM om_market_partners WHERE partner_id = $pid")->fetch();
        $sub = 0; foreach ($itens as $i) { $sub += $i['price'] * $i['qty']; }
        $taxa = $merc['delivery_fee'] ?? 9.90; $tot = $sub + $taxa;
        if ($sub < ($merc['min_order'] ?? 20)) { echo json_encode(['success' => false, 'error' => 'Pedido mínimo não atingido']); exit; }
        $num = 'OM' . date('ymdHis') . rand(10, 99);
        $palavras = ["BANANA", "LARANJA", "MORANGO", "ABACAXI", "MELANCIA"];
        $cod = $palavras[array_rand($palavras)] . '-' . rand(100, 999);
        $stmt = $pdo->prepare("INSERT INTO om_orders (order_number, customer_id, partner_id, status, subtotal, delivery_fee, total, payment_method, payment_status, delivery_address, delivery_lat, delivery_lng, delivery_code) VALUES (?, ?, ?, 'pago', ?, ?, ?, 'pix', 'aprovado', ?, ?, ?, ?)");
        $stmt->execute([$num, $cid, $pid, $sub, $taxa, $tot, $cli['address'] . ', ' . $cli['city'], $cli['lat'], $cli['lng'], $cod]);
        $oid = $pdo->lastInsertId();
        foreach ($itens as $i) { $pdo->prepare("INSERT INTO om_order_items (order_id, product_id, product_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)")->execute([$oid, $i['id'], $i['name'], $i['qty'], $i['price'], $i['price'] * $i['qty']]); }
        $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_name, message) VALUES (?, 'system', 'Sistema', ?)")->execute([$oid, "🛒 Pedido #$num criado!"]);
        $shops = $pdo->query("SELECT * FROM om_market_shoppers WHERE is_online = 1 AND (is_busy = 0 OR is_busy IS NULL) AND city = '" . addslashes($merc['city']) . "' LIMIT 5")->fetchAll();
        foreach ($shops as $s) { $d = calcDist($s['current_lat'], $s['current_lng'], $merc['lat'], $merc['lng']); $pdo->prepare("INSERT INTO om_dispatch_offers (order_id, worker_type, worker_id, score, distancia_km) VALUES (?, 'shopper', ?, ?, ?)")->execute([$oid, $s['shopper_id'], 100 - ($d * 5) + ($s['rating'] * 10), round($d, 2)]); }
        $pdo->exec("UPDATE om_orders SET status = 'aguardando_shopper' WHERE id = $oid");
        $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_name, message) VALUES (?, 'system', 'Sistema', ?)")->execute([$oid, "🔍 Buscando shopper..."]);
        echo json_encode(['success' => true, 'order_id' => $oid, 'order_number' => $num, 'total' => $tot]); exit;
    }
    
    if ($a === 'order_status') {
        $oid = intval($_GET['order_id'] ?? 0);
        $ped = $pdo->query("SELECT o.*, c.name as cliente_nome, p.name as mercado_nome, s.name as shopper_nome, d.name as delivery_nome, d.vehicle as delivery_veiculo FROM om_orders o LEFT JOIN om_sim_customers c ON o.customer_id = c.id LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id LEFT JOIN om_market_shoppers s ON o.shopper_id = s.shopper_id LEFT JOIN om_market_delivery d ON o.delivery_id = d.delivery_id WHERE o.id = $oid")->fetch();
        if (!$ped) { echo json_encode(['success' => false]); exit; }
        $itens = $pdo->query("SELECT * FROM om_order_items WHERE order_id = $oid")->fetchAll();
        $chat = $pdo->query("SELECT * FROM om_order_chat WHERE order_id = $oid ORDER BY created_at")->fetchAll();
        echo json_encode(['success' => true, 'pedido' => $ped, 'itens' => $itens, 'chat' => $chat]); exit;
    }
    
    if ($a === 'send_chat') {
        $in = json_decode(file_get_contents('php://input'), true);
        if ($in['order_id'] && $in['message']) { $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_name, message) VALUES (?, 'customer', ?, ?)")->execute([$in['order_id'], $in['sender_name'] ?? 'Cliente', $in['message']]); }
        echo json_encode(['success' => true]); exit;
    }
    
    if ($a === 'confirm_delivery') {
        $in = json_decode(file_get_contents('php://input'), true);
        $oid = intval($in['order_id'] ?? 0); $cod = strtoupper(trim($in['codigo'] ?? ''));
        $ped = $pdo->query("SELECT * FROM om_orders WHERE id = $oid")->fetch();
        if ($ped && strtoupper($ped['delivery_code']) === $cod) {
            $pdo->exec("UPDATE om_orders SET status = 'entregue', delivery_code_confirmed = 1, delivered_at = NOW() WHERE id = $oid");
            if ($ped['delivery_id']) { $pdo->exec("UPDATE om_market_delivery SET active_order_id = NULL WHERE delivery_id = {$ped['delivery_id']}"); }
            $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_name, message) VALUES (?, 'system', 'Sistema', ?)")->execute([$oid, "✅ Entrega confirmada! 🎉"]);
            echo json_encode(['success' => true, 'message' => 'Entrega confirmada!']);
        } else { echo json_encode(['success' => false, 'error' => 'Código incorreto']); }
        exit;
    }
    exit;
}

$clientes = [];
try { $clientes = $pdo->query("SELECT * FROM om_sim_customers ORDER BY city, name")->fetchAll(); } catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>🛒 OneMundo Mercado</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', sans-serif; background: #f8fafc; min-height: 100vh; }
.header { background: linear-gradient(135deg, #10b981, #059669); color: #fff; padding: 16px 20px; position: sticky; top: 0; z-index: 100; }
.header h1 { font-size: 20px; }
.container { max-width: 600px; margin: 0 auto; padding: 16px; }
.card { background: #fff; border-radius: 16px; padding: 20px; margin-bottom: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.card h3 { margin-bottom: 16px; color: #1e293b; }
.select-box { width: 100%; padding: 14px; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 16px; margin-bottom: 12px; }
.btn { width: 100%; padding: 14px; border-radius: 12px; font-weight: 700; font-size: 16px; border: none; cursor: pointer; margin-bottom: 8px; }
.btn-green { background: #10b981; color: #fff; }
.btn-gray { background: #64748b; color: #fff; }
.mercado-card { border: 2px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 12px; cursor: pointer; }
.mercado-card:hover { border-color: #10b981; background: #f0fdf4; }
.mercado-card.indisponivel { opacity: 0.5; cursor: not-allowed; }
.mercado-card h4 { font-size: 16px; color: #1e293b; margin-bottom: 8px; }
.mercado-card .info { display: flex; gap: 12px; flex-wrap: wrap; font-size: 13px; color: #64748b; }
.produto-item { display: flex; align-items: center; gap: 12px; padding: 12px; border-bottom: 1px solid #f1f5f9; }
.produto-info { flex: 1; }
.produto-info .name { font-weight: 600; color: #1e293b; }
.produto-info .price { color: #10b981; font-weight: 700; }
.qty-control { display: flex; align-items: center; gap: 8px; }
.qty-control button { width: 32px; height: 32px; border-radius: 8px; border: none; background: #e2e8f0; font-size: 18px; cursor: pointer; }
.qty-control button:hover { background: #10b981; color: #fff; }
.categoria-title { font-size: 14px; color: #64748b; padding: 12px 0 8px; border-bottom: 2px solid #10b981; margin-bottom: 8px; text-transform: uppercase; }
.carrinho-float { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); background: #10b981; color: #fff; padding: 14px 24px; border-radius: 30px; font-weight: 700; box-shadow: 0 4px 20px rgba(16,185,129,0.4); display: none; cursor: pointer; z-index: 100; }
.carrinho-float.show { display: flex; align-items: center; gap: 12px; }
.no-mercado { text-align: center; padding: 40px 20px; }
.no-mercado h3 { color: #ef4444; margin-bottom: 12px; }
.status-badge { display: inline-block; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
.status-badge.verde { background: #dcfce7; color: #16a34a; }
.status-badge.amarelo { background: #fef3c7; color: #d97706; }
.status-badge.azul { background: #dbeafe; color: #2563eb; }
.chat-box { max-height: 300px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px; margin-bottom: 12px; background: #f8fafc; }
.chat-msg { margin-bottom: 8px; padding: 8px 12px; border-radius: 12px; max-width: 85%; }
.chat-msg.system { background: #e2e8f0; color: #64748b; font-size: 13px; text-align: center; max-width: 100%; }
.chat-msg.customer { background: #10b981; color: #fff; margin-left: auto; }
.chat-msg.shopper { background: #f59e0b; color: #fff; }
.chat-msg.delivery { background: #3b82f6; color: #fff; }
.chat-msg .sender { font-size: 11px; opacity: 0.8; }
.chat-input { display: flex; gap: 8px; }
.chat-input input { flex: 1; padding: 12px; border: 2px solid #e2e8f0; border-radius: 12px; }
.chat-input button { padding: 12px 20px; background: #10b981; color: #fff; border: none; border-radius: 12px; cursor: pointer; }
.codigo-input { display: flex; gap: 8px; margin-top: 12px; }
.codigo-input input { flex: 1; padding: 14px; border: 2px solid #10b981; border-radius: 12px; font-size: 18px; text-align: center; text-transform: uppercase; }
.step { display: none; }
.step.active { display: block; }
.hidden { display: none !important; }
</style>
</head>
<body>
<div class="header"><h1>🛒 OneMundo Mercado</h1></div>
<div class="container">
    <div class="step active" id="step-cliente">
        <div class="card">
            <h3>👤 Entrar como Cliente</h3>
            <?php if (empty($clientes)): ?>
            <p style="color:#ef4444;">⚠️ Rode o <a href="INSTALAR.php">instalador</a> primeiro</p>
            <?php else: ?>
            <select class="select-box" id="selectCliente">
                <option value="">Selecione...</option>
                <?php foreach ($clientes as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> - <?= $c['city'] ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-green" onclick="buscarMercados()">🔍 Buscar Mercados</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="step" id="step-mercados"><div class="card"><h3>🏪 Mercados</h3><div id="listaMercados"></div></div><button class="btn btn-gray" onclick="showStep('cliente')">← Voltar</button></div>
    <div class="step" id="step-produtos"><div class="card"><h3 id="nomeMercado">🛒 Produtos</h3><div id="listaProdutos"></div></div><button class="btn btn-gray" onclick="showStep('mercados')">← Voltar</button></div>
    <div class="step" id="step-carrinho"><div class="card"><h3>🛒 Carrinho</h3><div id="listaCarrinho"></div><hr style="margin:16px 0;"><div style="display:flex;justify-content:space-between;font-size:20px;font-weight:700;color:#10b981;"><span>Total:</span><span id="totalCarrinho">R$ 0,00</span></div></div><button class="btn btn-green" onclick="finalizarPedido()">💳 Finalizar</button><button class="btn btn-gray" onclick="showStep('produtos')">← Continuar</button></div>
    <div class="step" id="step-pedido"><div class="card"><h3>📦 Pedido <span id="numeroPedido"></span></h3><div id="statusPedido"></div><div id="infoPedido"></div></div><div class="card"><h3>💬 Chat</h3><div class="chat-box" id="chatBox"></div><div class="chat-input"><input type="text" id="chatMessage" placeholder="Mensagem..."><button onclick="enviarMensagem()">Enviar</button></div></div><div class="card hidden" id="cardCodigo"><h3>🔑 Confirmar Entrega</h3><div class="codigo-input"><input type="text" id="inputCodigo" placeholder="BANANA-123"><button class="btn btn-green" style="width:auto;" onclick="confirmarEntrega()">✓</button></div></div><button class="btn btn-green" onclick="novaCompra()">🛒 Nova Compra</button></div>
</div>
<div class="carrinho-float" id="carrinhoFloat" onclick="abrirCarrinho()">🛒 <span id="carrinhoCount">0</span> | <span id="carrinhoTotal">R$ 0</span></div>
<script>
let cli=null, merc=null, carr=[], ped=null, taxa=0, poll=null;
function showStep(s){document.querySelectorAll('.step').forEach(e=>e.classList.remove('active'));document.getElementById('step-'+s).classList.add('active');}
async function buscarMercados(){const cid=document.getElementById('selectCliente').value;if(!cid)return alert('Selecione');const r=await fetch(`?ajax=get_mercados&cliente_id=${cid}`);const d=await r.json();if(!d.success)return alert(d.error);cli=d.cliente;const l=document.getElementById('listaMercados');if(d.mercados.length===0){l.innerHTML='<div class="no-mercado"><h3>😔 Sem mercados</h3><p>Não há mercados na sua região</p></div>';}else{l.innerHTML=d.mercados.map(m=>`<div class="mercado-card ${m.disponivel?'':'indisponivel'}" onclick="${m.disponivel?`selMerc(${m.id})`:''}"><h4>${m.name}</h4><div class="info"><span>📍${m.distancia}km</span><span>⏱️${m.tempo_min}-${m.tempo_max}min</span><span>🛵R$${parseFloat(m.taxa).toFixed(2)}</span></div>${!m.disponivel?'<p style="color:#ef4444;font-size:12px;">⚠️ Sem entregadores</p>':''}</div>`).join('');}showStep('mercados');}
async function selMerc(pid){const r=await fetch(`?ajax=get_produtos&partner_id=${pid}`);const d=await r.json();if(!d.success)return;merc=d.mercado;taxa=parseFloat(merc.delivery_fee)||9.9;document.getElementById('nomeMercado').textContent='🛒 '+merc.name;let h='';for(const[c,ps]of Object.entries(d.categorias)){h+=`<div class="categoria-title">${c}</div>`;ps.forEach(p=>{const pr=p.special_price||p.price;h+=`<div class="produto-item"><div class="produto-info"><div class="name">${p.name}</div><div class="price">R$ ${parseFloat(pr).toFixed(2)}</div></div><div class="qty-control"><button onclick="altQtd(${p.id},'${p.name.replace(/'/g,"\\'")}',${pr},-1)">−</button><span id="qty-${p.id}">0</span><button onclick="altQtd(${p.id},'${p.name.replace(/'/g,"\\'")}',${pr},1)">+</button></div></div>`;});}document.getElementById('listaProdutos').innerHTML=h;carr=[];updCarr();showStep('produtos');}
function altQtd(id,name,price,d){let i=carr.find(x=>x.id===id);if(!i){if(d>0){i={id,name,price,qty:0};carr.push(i);}else return;}i.qty+=d;if(i.qty<=0){carr=carr.filter(x=>x.id!==id);i=null;}document.getElementById('qty-'+id).textContent=i?i.qty:0;updCarr();}
function updCarr(){const t=carr.reduce((s,i)=>s+i.price*i.qty,0);const c=carr.reduce((s,i)=>s+i.qty,0);document.getElementById('carrinhoCount').textContent=c;document.getElementById('carrinhoTotal').textContent='R$ '+t.toFixed(2);document.getElementById('carrinhoFloat').classList.toggle('show',c>0);}
function abrirCarrinho(){if(!carr.length)return alert('Vazio!');const sub=carr.reduce((s,i)=>s+i.price*i.qty,0);document.getElementById('listaCarrinho').innerHTML=carr.map(i=>`<div class="produto-item"><div class="produto-info"><div class="name">${i.name}</div><div class="price">R$ ${(i.price*i.qty).toFixed(2)}</div></div><span>${i.qty}x</span></div>`).join('');document.getElementById('totalCarrinho').textContent='R$ '+(sub+taxa).toFixed(2);showStep('carrinho');}
async function finalizarPedido(){if(!carr.length)return alert('Vazio!');const r=await fetch('?ajax=checkout',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({cliente_id:cli.id,partner_id:merc.partner_id,itens:carr})});const d=await r.json();if(!d.success)return alert(d.error);ped=d.order_id;document.getElementById('numeroPedido').textContent='#'+d.order_number;carr=[];updCarr();showStep('pedido');updStatus();poll=setInterval(updStatus,3000);}
async function updStatus(){if(!ped)return;const r=await fetch(`?ajax=order_status&order_id=${ped}`);const d=await r.json();if(!d.success)return;const p=d.pedido;const sm={'aguardando_shopper':{t:'🔍 Buscando Shopper...',c:'amarelo'},'shopper_aceito':{t:'👷 Shopper a caminho!',c:'azul'},'em_compra':{t:'🛒 Fazendo compras...',c:'azul'},'compra_finalizada':{t:'✅ Compras prontas!',c:'verde'},'aguardando_delivery':{t:'🔍 Buscando Delivery...',c:'amarelo'},'delivery_aceito':{t:'🚴 Delivery a caminho!',c:'azul'},'em_entrega':{t:'🛵 Em entrega!',c:'azul'},'entregue':{t:'✅ Entregue!',c:'verde'}};const st=sm[p.status]||{t:p.status,c:'amarelo'};document.getElementById('statusPedido').innerHTML=`<div style="text-align:center;margin:16px 0;"><span class="status-badge ${st.c}">${st.t}</span></div>`;let inf=`<p><b>Mercado:</b> ${p.mercado_nome||'N/A'}</p>`;if(p.shopper_nome)inf+=`<p><b>Shopper:</b> ${p.shopper_nome}</p>`;if(p.delivery_nome)inf+=`<p><b>Delivery:</b> ${p.delivery_nome}</p>`;if(p.delivery_code&&['em_entrega','entregue'].includes(p.status))inf+=`<p style="font-size:24px;font-weight:800;color:#10b981;text-align:center;">🔑 ${p.delivery_code}</p>`;document.getElementById('infoPedido').innerHTML=inf;document.getElementById('cardCodigo').classList.toggle('hidden',p.status!=='em_entrega');const cb=document.getElementById('chatBox');cb.innerHTML=d.chat.map(m=>`<div class="chat-msg ${m.sender_type}"><div class="sender">${m.sender_name}</div>${m.message}</div>`).join('');cb.scrollTop=cb.scrollHeight;if(p.status==='entregue'&&poll)clearInterval(poll);}
async function enviarMensagem(){const inp=document.getElementById('chatMessage');if(!inp.value.trim())return;await fetch('?ajax=send_chat',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({order_id:ped,message:inp.value,sender_name:cli.name})});inp.value='';updStatus();}
async function confirmarEntrega(){const cod=document.getElementById('inputCodigo').value;if(!cod)return alert('Digite');const r=await fetch('?ajax=confirm_delivery',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({order_id:ped,codigo:cod})});const d=await r.json();if(d.success){alert('🎉 '+d.message);updStatus();}else alert('❌ '+d.error);}
function novaCompra(){if(poll)clearInterval(poll);ped=null;carr=[];updCarr();showStep('cliente');}
</script>
</body>
</html>
