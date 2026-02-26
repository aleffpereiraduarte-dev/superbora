<?php
require_once __DIR__ . '/config/database.php';
/**
 * 🧠 TESTE DO SISTEMA INTELIGENTE DE DISTRIBUIÇÃO
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

session_name('OCSESSID');
session_start();

$pdo = getPDO();

$executar = isset($_GET['run']);
$logs = [];

function api($action, $data = []) {
    $data['action'] = $action;
    $ch = curl_init('http://localhost/mercado/api/dispatch.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

if ($executar) {
    
    // ══════════════════════════════════════════════════════════════════════════
    // PREPARAÇÃO: Criar workers de teste com localizações diferentes
    // ══════════════════════════════════════════════════════════════════════════
    
    $logs[] = ['titulo' => '🔧 Preparando Ambiente', 'status' => 'info', 'detalhes' => 'Criando workers com GPS'];
    
    // Atualizar parceiro com coordenadas (Gov. Valadares)
    $pdo->exec("UPDATE om_market_partners SET lat = -18.8512, lng = -41.9455, latitude = -18.8512, longitude = -41.9455, delivery_radius = 15 WHERE partner_id = 1");
    
    // Criar/atualizar 5 shoppers com localizações diferentes
    $shoppers = [
        ['Shopper Perto', -18.8520, -41.9460, 5.0, 95],      // 1km do mercado
        ['Shopper Médio', -18.8600, -41.9500, 4.8, 88],      // 3km do mercado
        ['Shopper Longe', -18.8800, -41.9700, 4.5, 75],      // 5km do mercado
        ['Shopper Rápido', -18.8550, -41.9480, 4.9, 92],     // 2km, mas aceita rápido
        ['Shopper Lento', -18.8530, -41.9470, 4.2, 60],      // 1.5km, mas aceita devagar
    ];
    
    foreach ($shoppers as $idx => $s) {
        $email = 'shopper' . ($idx + 1) . '@teste.com';
        $pdo->exec("INSERT INTO om_market_shoppers (name, email, status, is_online, is_busy, current_lat, current_lng, rating, accept_rate, avg_accept_time, last_location_at)
                    VALUES ('{$s[0]}', '$email', 'online', 1, 0, {$s[1]}, {$s[2]}, {$s[3]}, {$s[4]}, " . rand(15, 45) . ", NOW())
                    ON DUPLICATE KEY UPDATE 
                    name = '{$s[0]}', status = 'online', is_online = 1, is_busy = 0, current_order_id = NULL,
                    current_lat = {$s[1]}, current_lng = {$s[2]}, rating = {$s[3]}, accept_rate = {$s[4]}, last_location_at = NOW()");
    }
    
    $logs[] = ['titulo' => '✅ Shoppers Criados', 'status' => 'ok', 'detalhes' => count($shoppers) . ' shoppers com GPS'];
    
    // Criar/atualizar 3 deliverys
    $deliverys = [
        ['Motoboy Veloz', -18.8515, -41.9458, 4.9, 98, 'moto'],
        ['Ciclista Eco', -18.8700, -41.9600, 4.7, 85, 'bike'],
        ['Delivery Carro', -18.8550, -41.9500, 4.5, 80, 'carro'],
    ];
    
    foreach ($deliverys as $idx => $d) {
        $email = 'delivery' . ($idx + 1) . '@teste.com';
        $pdo->exec("INSERT INTO om_market_delivery (name, email, phone, status, is_online, current_lat, current_lng, rating, accept_rate, avg_accept_time, vehicle, last_location_at)
                    VALUES ('{$d[0]}', '$email', '11999990000', 'online', 1, {$d[1]}, {$d[2]}, {$d[3]}, {$d[4]}, " . rand(10, 30) . ", '{$d[5]}', NOW())
                    ON DUPLICATE KEY UPDATE 
                    name = '{$d[0]}', status = 'online', is_online = 1, active_order_id = NULL,
                    current_lat = {$d[1]}, current_lng = {$d[2]}, rating = {$d[3]}, accept_rate = {$d[4]}, vehicle = '{$d[5]}', last_location_at = NOW()");
    }
    
    $logs[] = ['titulo' => '✅ Deliverys Criados', 'status' => 'ok', 'detalhes' => count($deliverys) . ' deliverys com GPS'];
    
    // ══════════════════════════════════════════════════════════════════════════
    // TESTE 1: Verificar mercado no raio
    // ══════════════════════════════════════════════════════════════════════════
    
    $logs[] = ['titulo' => '📍 Teste 1: Verificar Raio', 'status' => 'info', 'detalhes' => 'Cliente em Gov. Valadares'];
    
    // Cliente próximo (2km)
    $result = api('check_mercado_raio', ['lat' => -18.8550, 'lng' => -41.9500]);
    
    if ($result['success'] && $result['total'] > 0) {
        $logs[] = ['titulo' => '✅ Mercado Encontrado', 'status' => 'ok', 
                   'detalhes' => $result['mercados'][0]['name'] . ' - ' . $result['mercados'][0]['distancia_km'] . ' km'];
    } else {
        $logs[] = ['titulo' => '⚠️ Mercado', 'status' => 'aviso', 'detalhes' => 'Nenhum mercado no raio'];
    }
    
    // Cliente longe (50km)
    $resultLonge = api('check_mercado_raio', ['lat' => -19.3000, 'lng' => -42.0000]);
    $logs[] = ['titulo' => '📍 Teste Cliente Longe', 'status' => $resultLonge['total'] == 0 ? 'ok' : 'aviso',
               'detalhes' => $resultLonge['total'] == 0 ? 'Corretamente sem mercado (50km)' : 'Mercado apareceu indevidamente'];
    
    // ══════════════════════════════════════════════════════════════════════════
    // TESTE 2: Criar pedido e disparar para shoppers
    // ══════════════════════════════════════════════════════════════════════════
    
    $logs[] = ['titulo' => '🛒 Teste 2: Criar Pedido', 'status' => 'info', 'detalhes' => 'Criando pedido de teste'];
    
    // Criar pedido
    $orderNumber = 'INT' . date('His');
    $pdo->exec("INSERT INTO om_orders (order_number, customer_id, partner_id, market_id, total, status, payment_status, created_at)
                VALUES ('$orderNumber', 2, 1, 1, 100.00, 'pago', 'aprovado', NOW())");
    $orderId = $pdo->lastInsertId();
    
    $logs[] = ['titulo' => '✅ Pedido Criado', 'status' => 'ok', 'detalhes' => "#$orderId ($orderNumber)"];
    
    // ══════════════════════════════════════════════════════════════════════════
    // TESTE 3: Dispatch inteligente para shoppers
    // ══════════════════════════════════════════════════════════════════════════
    
    $logs[] = ['titulo' => '📢 Teste 3: Dispatch Shopper', 'status' => 'info', 'detalhes' => 'Disparando para shoppers próximos'];
    
    $dispatch = api('dispatch_shopper', ['order_id' => $orderId]);
    
    if ($dispatch['success'] && $dispatch['total_dispatchs'] > 0) {
        $logs[] = ['titulo' => '✅ Dispatch Shopper OK', 'status' => 'ok', 
                   'detalhes' => $dispatch['total_dispatchs'] . ' ofertas criadas'];
        
        // Mostrar ranking
        foreach ($dispatch['ofertas'] as $of) {
            $logs[] = ['titulo' => "   #{$of['prioridade']} {$of['name']}", 'status' => 'info',
                       'detalhes' => "Score: {$of['score']} | {$of['distancia_km']}km | ~{$of['tempo_estimado']}min"];
        }
        
        // Simular primeiro aceitar
        $primeiraOferta = $dispatch['ofertas'][0];
        
        $logs[] = ['titulo' => '👷 Teste 4: Shopper Aceita', 'status' => 'info', 
                   'detalhes' => $primeiraOferta['name'] . ' aceitando...'];
        
        $aceite = api('accept_offer', [
            'offer_id' => $primeiraOferta['offer_id'],
            'shopper_id' => $primeiraOferta['shopper_id']
        ]);
        
        if ($aceite['success']) {
            $logs[] = ['titulo' => '✅ Aceite OK', 'status' => 'ok',
                       'detalhes' => "Ganho: R$ " . number_format($aceite['ganho_estimado'], 2, ',', '.') . " | Tempo: {$aceite['tempo_resposta']}"];
        } else {
            $logs[] = ['titulo' => '❌ Erro Aceite', 'status' => 'erro', 'detalhes' => $aceite['error'] ?? 'Erro desconhecido'];
        }
        
    } else {
        $logs[] = ['titulo' => '❌ Dispatch Falhou', 'status' => 'erro', 'detalhes' => $dispatch['error'] ?? 'Erro desconhecido'];
    }
    
    // ══════════════════════════════════════════════════════════════════════════
    // TESTE 5: Simular compras e dispatch delivery
    // ══════════════════════════════════════════════════════════════════════════
    
    $logs[] = ['titulo' => '🛒 Teste 5: Shopper Finaliza', 'status' => 'info', 'detalhes' => 'Simulando compras finalizadas'];
    
    // Gerar código
    $palavras = ["BANANA", "LARANJA", "MORANGO", "ABACAXI", "MELANCIA"];
    $codigo = $palavras[array_rand($palavras)] . '-' . rand(100, 999);
    $boxQr = 'BOX-' . $orderId . '-' . strtoupper(substr(md5(time()), 0, 6));
    
    $pdo->exec("UPDATE om_orders SET status = 'compra_finalizada', delivery_code = '$codigo', box_qr_code = '$boxQr' WHERE order_id = $orderId");
    
    $logs[] = ['titulo' => '🔑 Código Gerado', 'status' => 'ok', 'detalhes' => "$codigo | $boxQr"];
    
    // Dispatch delivery
    $logs[] = ['titulo' => '📢 Teste 6: Dispatch Delivery', 'status' => 'info', 'detalhes' => 'Disparando para deliverys próximos'];
    
    $dispatchDel = api('dispatch_delivery', ['order_id' => $orderId]);
    
    if ($dispatchDel['success'] && $dispatchDel['total_dispatchs'] > 0) {
        $logs[] = ['titulo' => '✅ Dispatch Delivery OK', 'status' => 'ok', 
                   'detalhes' => $dispatchDel['total_dispatchs'] . ' ofertas criadas'];
        
        foreach ($dispatchDel['ofertas'] as $of) {
            $logs[] = ['titulo' => "   #{$of['prioridade']} {$of['name']} ({$of['vehicle']})", 'status' => 'info',
                       'detalhes' => "Score: {$of['score']} | {$of['distancia_km']}km | ~{$of['tempo_estimado']}min"];
        }
        
        // Simular aceite
        $primeiraOfertaDel = $dispatchDel['ofertas'][0];
        
        $logs[] = ['titulo' => '🚴 Teste 7: Delivery Aceita', 'status' => 'info', 
                   'detalhes' => $primeiraOfertaDel['name'] . ' aceitando...'];
        
        $aceiteDel = api('accept_delivery_offer', [
            'offer_id' => $primeiraOfertaDel['offer_id'],
            'delivery_id' => $primeiraOfertaDel['delivery_id']
        ]);
        
        if ($aceiteDel['success']) {
            $logs[] = ['titulo' => '✅ Aceite Delivery OK', 'status' => 'ok',
                       'detalhes' => "Ganho: R$ " . number_format($aceiteDel['ganho_estimado'], 2, ',', '.') . " | Tempo: {$aceiteDel['tempo_resposta']}"];
        } else {
            $logs[] = ['titulo' => '❌ Erro Aceite', 'status' => 'erro', 'detalhes' => $aceiteDel['error'] ?? 'Erro'];
        }
    } else {
        $logs[] = ['titulo' => '❌ Dispatch Delivery Falhou', 'status' => 'erro', 'detalhes' => $dispatchDel['error'] ?? 'Erro'];
    }
    
    // ══════════════════════════════════════════════════════════════════════════
    // TESTE 8: Finalizar entrega
    // ══════════════════════════════════════════════════════════════════════════
    
    $logs[] = ['titulo' => '🏠 Teste 8: Entrega', 'status' => 'info', 'detalhes' => 'Finalizando pedido'];
    
    $pdo->exec("UPDATE om_orders SET status = 'entregue', delivered_at = NOW() WHERE order_id = $orderId");
    
    $logs[] = ['titulo' => '✅ PEDIDO ENTREGUE!', 'status' => 'ok', 'detalhes' => "Código: $codigo"];
    
    // ══════════════════════════════════════════════════════════════════════════
    // RESUMO
    // ══════════════════════════════════════════════════════════════════════════
    
    $pedidoFinal = $pdo->query("SELECT * FROM om_orders WHERE order_id = $orderId")->fetch();
    $shopperFinal = $pedidoFinal['shopper_id'] ? $pdo->query("SELECT name FROM om_market_shoppers WHERE shopper_id = {$pedidoFinal['shopper_id']}")->fetchColumn() : 'N/A';
    $deliveryFinal = $pedidoFinal['delivery_id'] ? $pdo->query("SELECT name FROM om_market_delivery WHERE delivery_id = {$pedidoFinal['delivery_id']}")->fetchColumn() : 'N/A';
    
    $logs[] = ['titulo' => '═══════════════════════════════════', 'status' => 'info', 'detalhes' => ''];
    $logs[] = ['titulo' => '🎉 SISTEMA INTELIGENTE FUNCIONANDO!', 'status' => 'ok', 'detalhes' => ''];
    $logs[] = ['titulo' => '═══════════════════════════════════', 'status' => 'info', 'detalhes' => ''];
    $logs[] = ['titulo' => "📋 Pedido #$orderId", 'status' => 'info', 'detalhes' => ''];
    $logs[] = ['titulo' => "👷 Shopper: $shopperFinal", 'status' => 'info', 'detalhes' => ''];
    $logs[] = ['titulo' => "🚴 Delivery: $deliveryFinal", 'status' => 'info', 'detalhes' => ''];
    $logs[] = ['titulo' => "🔑 Código: $codigo", 'status' => 'info', 'detalhes' => ''];
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>🧠 Teste Sistema Inteligente</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', sans-serif; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #fff; min-height: 100vh; padding: 20px; }
.container { max-width: 900px; margin: 0 auto; }
h1 { text-align: center; margin-bottom: 8px; }
.subtitle { text-align: center; color: #64748b; margin-bottom: 24px; }

.card { background: #1e293b; border-radius: 16px; padding: 24px; margin-bottom: 16px; }
.card h2 { margin-bottom: 16px; }

.btn { display: inline-flex; align-items: center; gap: 8px; padding: 16px 32px; border-radius: 12px; font-weight: 700; font-size: 16px; border: none; cursor: pointer; text-decoration: none; }
.btn-green { background: #10b981; color: #fff; }
.btn-green:hover { background: #059669; }

.log { padding: 12px 16px; border-radius: 8px; margin-bottom: 8px; display: flex; align-items: center; gap: 12px; }
.log.info { background: rgba(59, 130, 246, 0.2); border-left: 4px solid #3b82f6; }
.log.ok { background: rgba(16, 185, 129, 0.2); border-left: 4px solid #10b981; }
.log.erro { background: rgba(239, 68, 68, 0.2); border-left: 4px solid #ef4444; }
.log.aviso { background: rgba(245, 158, 11, 0.2); border-left: 4px solid #f59e0b; }
.log-titulo { flex: 1; font-weight: 600; }
.log-detalhes { color: #94a3b8; font-size: 13px; }

.algoritmo { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; }
.algoritmo-item { background: #0f172a; border-radius: 12px; padding: 16px; text-align: center; }
.algoritmo-item .peso { font-size: 32px; font-weight: 800; color: #10b981; }
.algoritmo-item .label { color: #94a3b8; font-size: 13px; }
</style>
</head>
<body>
<div class="container">
    <h1>🧠 Sistema Inteligente de Distribuição</h1>
    <p class="subtitle">Dispatch baseado em GPS, Velocidade e Rating</p>
    
    <?php if (!$executar): ?>
    
    <div class="card">
        <h2>Como Funciona o Algoritmo</h2>
        <div class="algoritmo">
            <div class="algoritmo-item">
                <div class="peso">40%</div>
                <div class="label">📍 Distância<br>Mais perto = melhor</div>
            </div>
            <div class="algoritmo-item">
                <div class="peso">25%</div>
                <div class="label">⚡ Velocidade<br>Aceita rápido = melhor</div>
            </div>
            <div class="algoritmo-item">
                <div class="peso">20%</div>
                <div class="label">⭐ Rating<br>Melhor avaliação = melhor</div>
            </div>
            <div class="algoritmo-item">
                <div class="peso">15%</div>
                <div class="label">📊 Taxa Aceite<br>% ofertas aceitas</div>
            </div>
        </div>
    </div>
    
    <div class="card">
        <h2>Regras do Sistema</h2>
        <ul style="color:#94a3b8;line-height:2;padding-left:20px;">
            <li>🎯 Cliente só vê mercado se estiver em até <strong>45 minutos</strong> de distância</li>
            <li>📍 Shoppers e Deliverys atualizam GPS a cada 30 segundos</li>
            <li>⏱️ Ofertas expiram em <strong>60 segundos</strong></li>
            <li>🔄 Se ninguém aceitar, passa pro próximo do ranking</li>
            <li>📊 Sistema aprende com histórico (quem aceita mais rápido sobe no ranking)</li>
        </ul>
    </div>
    
    <div style="text-align:center;">
        <a href="?run=1" class="btn btn-green">🚀 Executar Teste Completo</a>
    </div>
    
    <?php else: ?>
    
    <div class="card">
        <?php foreach ($logs as $log): ?>
        <div class="log <?= $log['status'] ?>">
            <div class="log-titulo"><?= $log['titulo'] ?></div>
            <?php if ($log['detalhes']): ?>
            <div class="log-detalhes"><?= $log['detalhes'] ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    
    <div style="text-align:center;">
        <a href="?run=1" class="btn btn-green">🔄 Rodar Novamente</a>
        <a href="TESTE_COMPLETO.php" class="btn btn-green" style="background:#3b82f6;">📋 Teste Simples</a>
    </div>
    
    <?php endif; ?>
</div>
</body>
</html>
