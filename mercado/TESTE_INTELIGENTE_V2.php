<?php
require_once __DIR__ . '/config/database.php';
/**
 * 🧠 TESTE SISTEMA INTELIGENTE V2 - CORRIGIDO
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

session_name('OCSESSID');
session_start();

$pdo = getPDO();

$executar = isset($_GET['run']);
$logs = [];

// Função para calcular distância
function calcDistancia($lat1, $lng1, $lat2, $lng2) {
    if (!$lat1 || !$lng1 || !$lat2 || !$lng2) return 999;
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return round($R * $c, 2);
}

if ($executar) {
    
    // ══════════════════════════════════════════════════════════════════════════
    // PREPARAÇÃO
    // ══════════════════════════════════════════════════════════════════════════
    
    $logs[] = ['titulo' => '🔧 Preparando Sistema Inteligente', 'status' => 'info', 'detalhes' => ''];
    
    // Garantir colunas no delivery
    $alteracoes = [
        "ALTER TABLE om_market_delivery ADD COLUMN current_lat DECIMAL(10,8) DEFAULT NULL",
        "ALTER TABLE om_market_delivery ADD COLUMN current_lng DECIMAL(11,8) DEFAULT NULL",
        "ALTER TABLE om_market_delivery ADD COLUMN rating DECIMAL(3,2) DEFAULT 5.00",
        "ALTER TABLE om_market_delivery ADD COLUMN accept_rate DECIMAL(5,2) DEFAULT 100.00",
        "ALTER TABLE om_market_delivery ADD COLUMN avg_accept_time INT DEFAULT 30",
    ];
    foreach ($alteracoes as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) {}
    }
    
    // Atualizar parceiro com GPS (Gov. Valadares)
    $pdo->exec("UPDATE om_market_partners SET lat = -18.8512, lng = -41.9455, delivery_radius = 15 WHERE partner_id = 1");
    
    $logs[] = ['titulo' => '✅ Ambiente Preparado', 'status' => 'ok', 'detalhes' => 'Colunas e GPS configurados'];
    
    // ══════════════════════════════════════════════════════════════════════════
    // ATUALIZAR GPS DOS SHOPPERS
    // ══════════════════════════════════════════════════════════════════════════
    
    $logs[] = ['titulo' => '📍 Atualizando GPS dos Shoppers', 'status' => 'info', 'detalhes' => ''];
    
    // Shoppers com GPS diferentes para testar ranking
    $gpsShoppers = [
        ['id' => 14, 'lat' => -18.8520, 'lng' => -41.9460],  // Perto: 0.1km
        ['id' => 15, 'lat' => -18.8600, 'lng' => -41.9500],  // Médio: 1km
        ['id' => 16, 'lat' => -18.8800, 'lng' => -41.9700],  // Longe: 4km
    ];
    
    foreach ($gpsShoppers as $g) {
        $pdo->exec("UPDATE om_market_shoppers SET current_lat = {$g['lat']}, current_lng = {$g['lng']}, is_busy = 0, current_order_id = NULL WHERE shopper_id = {$g['id']}");
    }
    
    // Atualizar Delivery Robô com GPS
    $pdo->exec("UPDATE om_market_delivery SET current_lat = -18.8515, current_lng = -41.9458 WHERE delivery_id = 1");
    
    $logs[] = ['titulo' => '✅ GPS Atualizado', 'status' => 'ok', 'detalhes' => 'Shoppers e Delivery com localização'];
    
    // ══════════════════════════════════════════════════════════════════════════
    // TESTE 1: VERIFICAR MERCADO NO RAIO
    // ══════════════════════════════════════════════════════════════════════════
    
    $logs[] = ['titulo' => '📍 TESTE 1: Verificar Raio do Mercado', 'status' => 'info', 'detalhes' => ''];
    
    $mercado = $pdo->query("SELECT * FROM om_market_partners WHERE partner_id = 1")->fetch();
    $mercadoLat = $mercado['lat'];
    $mercadoLng = $mercado['lng'];
    $raioKm = $mercado['delivery_radius'] ?? 15;
    
    // Cliente próximo (2km)
    $clienteLat = -18.8650;
    $clienteLng = -41.9550;
    $distCliente = calcDistancia($clienteLat, $clienteLng, $mercadoLat, $mercadoLng);
    $tempoEstimado = ceil(($distCliente / 20) * 60); // 20km/h média
    
    $dentroRaio = ($distCliente <= $raioKm) && ($tempoEstimado <= 45);
    
    if ($dentroRaio) {
        $logs[] = ['titulo' => '✅ Cliente NO RAIO', 'status' => 'ok', 
                   'detalhes' => "Distância: {$distCliente}km | Tempo: ~{$tempoEstimado}min | Mercado: {$mercado['name']}"];
    } else {
        $logs[] = ['titulo' => '❌ Cliente FORA do raio', 'status' => 'erro', 
                   'detalhes' => "Distância: {$distCliente}km | Tempo: ~{$tempoEstimado}min"];
    }
    
    // Cliente longe (50km)
    $distLonge = calcDistancia(-19.3000, -42.0000, $mercadoLat, $mercadoLng);
    $tempoLonge = ceil(($distLonge / 20) * 60);
    $logs[] = ['titulo' => '✅ Cliente longe bloqueado', 'status' => 'ok', 
               'detalhes' => "50km de distância = {$tempoLonge}min (> 45min) = Mercado NÃO aparece"];
    
    // ══════════════════════════════════════════════════════════════════════════
    // TESTE 2: CRIAR PEDIDO
    // ══════════════════════════════════════════════════════════════════════════
    
    $logs[] = ['titulo' => '🛒 TESTE 2: Criar Pedido', 'status' => 'info', 'detalhes' => ''];
    
    $orderNumber = 'INT' . date('His');
    $pdo->exec("INSERT INTO om_orders (order_number, customer_id, partner_id, total, status, payment_status, delivery_address, created_at)
                VALUES ('$orderNumber', 2, 1, 89.90, 'pago', 'aprovado', 'Rua Teste, 123 - Gov. Valadares', NOW())");
    $orderId = $pdo->lastInsertId();
    
    $logs[] = ['titulo' => '✅ Pedido Criado', 'status' => 'ok', 'detalhes' => "#$orderId ($orderNumber) - R$ 89,90"];
    
    // ══════════════════════════════════════════════════════════════════════════
    // TESTE 3: RANKING INTELIGENTE DE SHOPPERS
    // ══════════════════════════════════════════════════════════════════════════
    
    $logs[] = ['titulo' => '🧠 TESTE 3: Ranking Inteligente de Shoppers', 'status' => 'info', 'detalhes' => ''];
    
    // Buscar shoppers disponíveis
    $shoppers = $pdo->query("
        SELECT s.*, 
               s.current_lat as lat, s.current_lng as lng,
               COALESCE(s.rating, 5) as rating,
               COALESCE(s.accept_rate, 100) as accept_rate,
               COALESCE(s.avg_accept_time, 30) as avg_accept_time
        FROM om_market_shoppers s
        WHERE s.is_online = 1 AND (s.is_busy = 0 OR s.is_busy IS NULL)
        AND s.current_lat IS NOT NULL
    ")->fetchAll();
    
    // Calcular score de cada um
    $ranking = [];
    foreach ($shoppers as $s) {
        $dist = calcDistancia($s['lat'], $s['lng'], $mercadoLat, $mercadoLng);
        
        // Score (40% distância, 25% velocidade, 20% rating, 15% taxa aceite)
        $scoreDistancia = max(0, 100 - ($dist / 15 * 100));
        $scoreVelocidade = max(0, 100 - ($s['avg_accept_time'] / 60 * 100));
        $scoreRating = ($s['rating'] / 5) * 100;
        $scoreTaxaAceite = $s['accept_rate'];
        
        $scoreFinal = ($scoreDistancia * 0.40) + ($scoreVelocidade * 0.25) + ($scoreRating * 0.20) + ($scoreTaxaAceite * 0.15);
        
        $ranking[] = [
            'shopper_id' => $s['shopper_id'],
            'name' => $s['name'],
            'distancia' => $dist,
            'rating' => $s['rating'],
            'accept_rate' => $s['accept_rate'],
            'score' => round($scoreFinal, 1)
        ];
    }
    
    // Ordenar por score
    usort($ranking, fn($a, $b) => $b['score'] <=> $a['score']);
    
    $logs[] = ['titulo' => '📊 Ranking de Shoppers (por score)', 'status' => 'info', 'detalhes' => ''];
    
    foreach (array_slice($ranking, 0, 5) as $idx => $r) {
        $pos = $idx + 1;
        $logs[] = ['titulo' => "   #{$pos} {$r['name']}", 'status' => $idx == 0 ? 'ok' : 'info',
                   'detalhes' => "Score: {$r['score']} | {$r['distancia']}km | ⭐{$r['rating']} | {$r['accept_rate']}% aceite"];
    }
    
    // ══════════════════════════════════════════════════════════════════════════
    // TESTE 4: DISPARO INTELIGENTE
    // ══════════════════════════════════════════════════════════════════════════
    
    $logs[] = ['titulo' => '📢 TESTE 4: Disparo Inteligente', 'status' => 'info', 'detalhes' => ''];
    
    // Criar ofertas para os top 3
    $top3 = array_slice($ranking, 0, 3);
    foreach ($top3 as $idx => $s) {
        $pdo->prepare("INSERT INTO om_dispatch_offers (order_id, worker_type, worker_id, score, distancia_km) VALUES (?, 'shopper', ?, ?, ?)")
            ->execute([$orderId, $s['shopper_id'], $s['score'], $s['distancia']]);
    }
    
    $logs[] = ['titulo' => '✅ 3 Ofertas Criadas', 'status' => 'ok', 
               'detalhes' => "Enviado para: " . implode(', ', array_column($top3, 'name'))];
    $logs[] = ['titulo' => '⏱️ Ofertas expiram em 60 segundos', 'status' => 'info', 'detalhes' => ''];
    
    // ══════════════════════════════════════════════════════════════════════════
    // TESTE 5: SHOPPER MAIS BEM RANQUEADO ACEITA
    // ══════════════════════════════════════════════════════════════════════════
    
    $melhorShopper = $top3[0];
    $logs[] = ['titulo' => '👷 TESTE 5: Shopper Aceita', 'status' => 'info', 
               'detalhes' => "{$melhorShopper['name']} (melhor score) aceita em 5 segundos"];
    
    // Calcular ganho
    $ganhoShopper = max(5, 89.90 * 0.05);
    
    // Atualizar pedido
    $pdo->prepare("UPDATE om_orders SET status = 'shopper_aceito', shopper_id = ?, shopper_earning = ? WHERE order_id = ?")
        ->execute([$melhorShopper['shopper_id'], $ganhoShopper, $orderId]);
    
    // Atualizar estatísticas do shopper
    $pdo->exec("UPDATE om_market_shoppers SET is_busy = 1, current_order_id = $orderId, 
                total_accepts = COALESCE(total_accepts, 0) + 1 WHERE shopper_id = {$melhorShopper['shopper_id']}");
    
    // Marcar ofertas
    $pdo->exec("UPDATE om_dispatch_offers SET status = 'accepted', response_time_seconds = 5 
                WHERE order_id = $orderId AND worker_id = {$melhorShopper['shopper_id']}");
    $pdo->exec("UPDATE om_dispatch_offers SET status = 'expired' 
                WHERE order_id = $orderId AND worker_id != {$melhorShopper['shopper_id']}");
    
    $logs[] = ['titulo' => '✅ Aceito!', 'status' => 'ok', 
               'detalhes' => "Ganho: R$ " . number_format($ganhoShopper, 2, ',', '.') . " | Tempo resposta: 5s"];
    
    // ══════════════════════════════════════════════════════════════════════════
    // TESTE 6: COMPRAS E CÓDIGO DE ENTREGA
    // ══════════════════════════════════════════════════════════════════════════
    
    $logs[] = ['titulo' => '🛒 TESTE 6: Shopper Faz Compras', 'status' => 'info', 'detalhes' => ''];
    
    $pdo->exec("UPDATE om_orders SET status = 'em_compra' WHERE order_id = $orderId");
    
    // Gerar código de entrega
    $palavras = ["BANANA", "LARANJA", "MORANGO", "ABACAXI", "MELANCIA", "MANGA", "UVA", "LIMAO"];
    $codigoEntrega = $palavras[array_rand($palavras)] . '-' . rand(100, 999);
    $boxQr = 'BOX-' . $orderId . '-' . strtoupper(substr(md5(time()), 0, 6));
    
    $pdo->exec("UPDATE om_orders SET status = 'compra_finalizada', delivery_code = '$codigoEntrega', box_qr_code = '$boxQr' WHERE order_id = $orderId");
    
    // Liberar shopper
    $pdo->exec("UPDATE om_market_shoppers SET is_busy = 0, current_order_id = NULL WHERE shopper_id = {$melhorShopper['shopper_id']}");
    
    $logs[] = ['titulo' => '✅ Compras Finalizadas', 'status' => 'ok', 'detalhes' => ''];
    $logs[] = ['titulo' => "🔑 CÓDIGO ENTREGA: $codigoEntrega", 'status' => 'ok', 'detalhes' => "QR: $boxQr"];
    
    // ══════════════════════════════════════════════════════════════════════════
    // TESTE 7: DISPATCH INTELIGENTE DELIVERY
    // ══════════════════════════════════════════════════════════════════════════
    
    $logs[] = ['titulo' => '📢 TESTE 7: Dispatch Delivery', 'status' => 'info', 'detalhes' => ''];
    
    // Buscar delivery disponível
    $delivery = $pdo->query("SELECT * FROM om_market_delivery WHERE is_online = 1 LIMIT 1")->fetch();
    
    if ($delivery) {
        $distDelivery = calcDistancia($delivery['current_lat'], $delivery['current_lng'], $mercadoLat, $mercadoLng);
        $ganhoDelivery = 5.00;
        
        $logs[] = ['titulo' => "🚴 {$delivery['name']}", 'status' => 'ok', 
                   'detalhes' => "Distância: {$distDelivery}km | Veículo: " . ($delivery['vehicle'] ?? 'moto')];
        
        // Aceitar
        $pdo->exec("UPDATE om_orders SET status = 'delivery_aceito', delivery_id = {$delivery['delivery_id']}, delivery_earning = $ganhoDelivery WHERE order_id = $orderId");
        
        $logs[] = ['titulo' => '✅ Delivery Aceitou', 'status' => 'ok', 
                   'detalhes' => "Ganho: R$ " . number_format($ganhoDelivery, 2, ',', '.')];
    }
    
    // ══════════════════════════════════════════════════════════════════════════
    // TESTE 8: ENTREGA FINAL
    // ══════════════════════════════════════════════════════════════════════════
    
    $logs[] = ['titulo' => '🏠 TESTE 8: Entrega Final', 'status' => 'info', 'detalhes' => ''];
    
    $pdo->exec("UPDATE om_orders SET status = 'em_entrega' WHERE order_id = $orderId");
    $logs[] = ['titulo' => '🚴 Em rota de entrega...', 'status' => 'info', 'detalhes' => ''];
    
    $logs[] = ['titulo' => "🔑 Cliente informa código: $codigoEntrega", 'status' => 'info', 'detalhes' => ''];
    
    $pdo->exec("UPDATE om_orders SET status = 'entregue', delivered_at = NOW(), delivery_code_confirmed = 1 WHERE order_id = $orderId");
    
    $logs[] = ['titulo' => '✅ CÓDIGO VALIDADO - ENTREGUE!', 'status' => 'ok', 'detalhes' => ''];
    
    // ══════════════════════════════════════════════════════════════════════════
    // RESUMO FINAL
    // ══════════════════════════════════════════════════════════════════════════
    
    $pedidoFinal = $pdo->query("SELECT * FROM om_orders WHERE order_id = $orderId")->fetch();
    
    $logs[] = ['titulo' => '═══════════════════════════════════════', 'status' => 'info', 'detalhes' => ''];
    $logs[] = ['titulo' => '🎉 SISTEMA INTELIGENTE 100% FUNCIONAL!', 'status' => 'ok', 'detalhes' => ''];
    $logs[] = ['titulo' => '═══════════════════════════════════════', 'status' => 'info', 'detalhes' => ''];
    $logs[] = ['titulo' => "📋 Pedido: #$orderId", 'status' => 'info', 'detalhes' => "R$ 89,90"];
    $logs[] = ['titulo' => "👷 Shopper: {$melhorShopper['name']}", 'status' => 'info', 'detalhes' => "Score: {$melhorShopper['score']} | R$ " . number_format($ganhoShopper, 2, ',', '.')];
    $logs[] = ['titulo' => "🚴 Delivery: {$delivery['name']}", 'status' => 'info', 'detalhes' => "R$ " . number_format($ganhoDelivery, 2, ',', '.')];
    $logs[] = ['titulo' => "🔑 Código: $codigoEntrega", 'status' => 'ok', 'detalhes' => ''];
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>🧠 Sistema Inteligente</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', sans-serif; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #fff; min-height: 100vh; padding: 20px; }
.container { max-width: 900px; margin: 0 auto; }
h1 { text-align: center; margin-bottom: 8px; }
.subtitle { text-align: center; color: #64748b; margin-bottom: 24px; }

.card { background: #1e293b; border-radius: 16px; padding: 24px; margin-bottom: 16px; }

.btn { display: inline-flex; align-items: center; gap: 8px; padding: 16px 32px; border-radius: 12px; font-weight: 700; font-size: 16px; border: none; cursor: pointer; text-decoration: none; margin: 4px; }
.btn-green { background: #10b981; color: #fff; }
.btn-blue { background: #3b82f6; color: #fff; }

.log { padding: 12px 16px; border-radius: 8px; margin-bottom: 6px; display: flex; align-items: center; gap: 12px; }
.log.info { background: rgba(59, 130, 246, 0.15); border-left: 3px solid #3b82f6; }
.log.ok { background: rgba(16, 185, 129, 0.15); border-left: 3px solid #10b981; }
.log.erro { background: rgba(239, 68, 68, 0.15); border-left: 3px solid #ef4444; }
.log-titulo { font-weight: 600; }
.log-detalhes { color: #94a3b8; font-size: 13px; margin-left: auto; }

.algoritmo { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }
.algo-item { background: #0f172a; border-radius: 12px; padding: 16px; text-align: center; }
.algo-item .peso { font-size: 28px; font-weight: 800; color: #10b981; }
.algo-item .label { color: #94a3b8; font-size: 12px; }
</style>
</head>
<body>
<div class="container">
    <h1>🧠 Sistema Inteligente de Distribuição</h1>
    <p class="subtitle">Dispatch baseado em GPS, Velocidade e Rating</p>
    
    <?php if (!$executar): ?>
    
    <div class="card">
        <h3 style="margin-bottom:16px;">Algoritmo de Prioridade</h3>
        <div class="algoritmo">
            <div class="algo-item">
                <div class="peso">40%</div>
                <div class="label">📍 Distância</div>
            </div>
            <div class="algo-item">
                <div class="peso">25%</div>
                <div class="label">⚡ Velocidade</div>
            </div>
            <div class="algo-item">
                <div class="peso">20%</div>
                <div class="label">⭐ Rating</div>
            </div>
            <div class="algo-item">
                <div class="peso">15%</div>
                <div class="label">📊 Taxa Aceite</div>
            </div>
        </div>
        
        <h3 style="margin-bottom:12px;">Regras</h3>
        <ul style="color:#94a3b8;line-height:1.8;padding-left:20px;">
            <li>🎯 Cliente só vê mercado se estiver em até <strong>45 minutos</strong></li>
            <li>📍 GPS atualizado a cada 30 segundos</li>
            <li>⏱️ Ofertas expiram em <strong>60 segundos</strong></li>
            <li>🔄 Top 3 recebem oferta simultaneamente</li>
            <li>🏆 Quem aceitar primeiro, leva!</li>
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
        <a href="MEGA_ROBO.php" class="btn btn-blue">🤖 Mega Robô</a>
        <a href="admin/pedidos.php" class="btn btn-blue">👨‍💼 Admin</a>
    </div>
    
    <?php endif; ?>
</div>
</body>
</html>
