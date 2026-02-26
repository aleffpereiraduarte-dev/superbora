<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ════════════════════════════════════════════════════════════════════════════════════════════
 * 🚗 CRON DISPATCH DRIVER INTELIGENTE
 * ════════════════════════════════════════════════════════════════════════════════════════════
 * 
 * Este CRON verifica pedidos em andamento e dispara drivers conforme:
 * - Quantidade de drivers disponíveis na região
 * - Progresso do scan do shopper (%)
 * - Prioridade para drivers com entregas no caminho (batching)
 * 
 * Executar a cada 30 segundos:
 * * * * * * php /var/www/html/mercado/cron/dispatch_driver.php
 * * * * * * sleep 30 && php /var/www/html/mercado/cron/dispatch_driver.php
 * 
 * ════════════════════════════════════════════════════════════════════════════════════════════
 */

$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli) header('Content-Type: application/json');

date_default_timezone_set('America/Sao_Paulo');

// Conexão
try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die(json_encode(['error' => 'DB connection failed']));
}

$log = [];

// ════════════════════════════════════════════════════════════════════════════════════════════
// 1. CARREGAR CONFIGURAÇÕES
// ════════════════════════════════════════════════════════════════════════════════════════════

$config = [];
try {
    $rows = $pdo->query("SELECT config_key, config_value FROM om_dispatch_config")->fetchAll(PDO::FETCH_KEY_PAIR);
    $config = $rows;
} catch (Exception $e) {
    // Usar defaults
    $config = [
        'trigger_0_2_drivers' => 0,
        'trigger_3_5_drivers' => 30,
        'trigger_6_10_drivers' => 60,
        'trigger_10plus_drivers' => 85,
        'driver_search_radius_km' => 30,
        'max_batch_orders' => 5,
        'min_score_for_offers' => 30
    ];
}

// ════════════════════════════════════════════════════════════════════════════════════════════
// 2. BUSCAR PEDIDOS EM SHOPPING (shopper comprando)
// ════════════════════════════════════════════════════════════════════════════════════════════

$pedidos = $pdo->query("
    SELECT 
        o.order_id,
        o.partner_id,
        o.shopper_id,
        o.scan_progress,
        o.items_total,
        o.items_scanned,
        o.driver_dispatch_at,
        o.shipping_latitude,
        o.shipping_longitude,
        p.latitude as mercado_lat,
        p.longitude as mercado_lng,
        p.name as mercado_nome
    FROM om_market_orders o
    LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
    WHERE o.status = 'shopping'
    AND o.shopper_id IS NOT NULL
    AND o.delivery_driver_id IS NULL
    AND o.driver_dispatch_at IS NULL
    ORDER BY o.created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

$log[] = "Pedidos em shopping: " . count($pedidos);

foreach ($pedidos as $pedido) {
    $order_id = $pedido['order_id'];
    $partner_id = $pedido['partner_id'];
    $scan_progress = floatval($pedido['scan_progress'] ?? 0);
    
    // ════════════════════════════════════════════════════════════════════════════════════════
    // 3. CONTAR DRIVERS DISPONÍVEIS NA REGIÃO
    // ════════════════════════════════════════════════════════════════════════════════════════
    
    $mercado_lat = $pedido['mercado_lat'] ?? -23.5505;
    $mercado_lng = $pedido['mercado_lng'] ?? -46.6333;
    $radius = floatval($config['driver_search_radius_km'] ?? 30);
    $min_score = intval($config['min_score_for_offers'] ?? 30);
    
    // Buscar drivers online, disponíveis e com score adequado
    $drivers = $pdo->query("
        SELECT * FROM (
            SELECT
                d.delivery_id,
                d.name,
                d.current_latitude,
                d.current_longitude,
                d.score_interno,
                d.can_batch,
                d.current_batch_id,
                (SELECT COUNT(*) FROM om_driver_batches b WHERE b.driver_id = d.delivery_id AND b.status = 'active') as batches_ativos,
                (
                    6371 * acos(
                        cos(radians({$mercado_lat})) * cos(radians(COALESCE(d.current_latitude, 0))) *
                        cos(radians(COALESCE(d.current_longitude, 0)) - radians({$mercado_lng})) +
                        sin(radians({$mercado_lat})) * sin(radians(COALESCE(d.current_latitude, 0)))
                    )
                ) AS distancia_km
            FROM om_market_deliveries d
            WHERE d.is_online = 1
            AND d.status = 'ativo'
            AND COALESCE(d.score_interno, 100) >= {$min_score}
        ) sub
        WHERE distancia_km <= {$radius} OR distancia_km IS NULL
        ORDER BY distancia_km ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $drivers_count = count($drivers);
    $log[] = "Pedido #{$order_id}: {$drivers_count} drivers disponíveis, scan: {$scan_progress}%";
    
    // ════════════════════════════════════════════════════════════════════════════════════════
    // 4. DETERMINAR THRESHOLD DE TRIGGER
    // ════════════════════════════════════════════════════════════════════════════════════════
    
    if ($drivers_count <= 2) {
        $threshold = intval($config['trigger_0_2_drivers'] ?? 0);
    } elseif ($drivers_count <= 5) {
        $threshold = intval($config['trigger_3_5_drivers'] ?? 30);
    } elseif ($drivers_count <= 10) {
        $threshold = intval($config['trigger_6_10_drivers'] ?? 60);
    } else {
        $threshold = intval($config['trigger_10plus_drivers'] ?? 85);
    }
    
    $log[] = "  → Threshold: {$threshold}% (drivers: {$drivers_count})";
    
    // ════════════════════════════════════════════════════════════════════════════════════════
    // 5. VERIFICAR SE DEVE DISPARAR
    // ════════════════════════════════════════════════════════════════════════════════════════
    
    if ($scan_progress >= $threshold) {
        $log[] = "  → DISPARANDO! (scan {$scan_progress}% >= threshold {$threshold}%)";
        
        // Marcar que já disparou
        $pdo->prepare("UPDATE om_market_orders SET driver_dispatch_at = NOW() WHERE order_id = ?")->execute([$order_id]);
        
        // ════════════════════════════════════════════════════════════════════════════════════
        // 6. PRIORIZAR DRIVERS COM ENTREGA NO CAMINHO (BATCHING)
        // ════════════════════════════════════════════════════════════════════════════════════
        
        $dest_lat = $pedido['shipping_latitude'] ?? $mercado_lat;
        $dest_lng = $pedido['shipping_longitude'] ?? $mercado_lng;
        
        $prioritized_drivers = [];
        
        foreach ($drivers as $driver) {
            $driver['priority_score'] = 0;
            
            // Prioridade 1: Driver com batch ativo indo pro mesmo destino
            if ($driver['can_batch'] && $driver['current_batch_id']) {
                // Verificar se destino está no caminho
                $batch = $pdo->prepare("SELECT route_optimized FROM om_driver_batches WHERE batch_id = ?");
                $batch->execute([$driver['current_batch_id']]);
                $batch_data = $batch->fetch(PDO::FETCH_ASSOC);
                
                if ($batch_data && $batch_data['route_optimized']) {
                    // Simplificação: adicionar 50 pontos de prioridade
                    $driver['priority_score'] += 50;
                }
            }
            
            // Prioridade 2: Distância (quanto mais perto, mais pontos)
            $dist = floatval($driver['distancia_km'] ?? 999);
            $driver['priority_score'] += max(0, 30 - ($dist * 2));
            
            // Prioridade 3: Score interno
            $driver['priority_score'] += (intval($driver['score_interno'] ?? 100) / 10);
            
            $prioritized_drivers[] = $driver;
        }
        
        // Ordenar por priority_score DESC
        usort($prioritized_drivers, function($a, $b) {
            return $b['priority_score'] <=> $a['priority_score'];
        });
        
        // ════════════════════════════════════════════════════════════════════════════════════
        // 7. CRIAR OFERTAS (mesma lógica de waves)
        // ════════════════════════════════════════════════════════════════════════════════════
        
        // Pegar os 3 melhores primeiro (wave 1)
        $wave1_drivers = array_slice($prioritized_drivers, 0, 3);
        
        foreach ($wave1_drivers as $driver) {
            // Calcular ganho do driver
            $dist_entrega = calcularDistancia($mercado_lat, $mercado_lng, $dest_lat, $dest_lng);
            $delivery_earning = 8.90 + ($dist_entrega * 1.50); // R$8.90 base + R$1.50/km
            
            try {
                $pdo->prepare("
                    INSERT INTO om_delivery_offers 
                    (order_id, partner_id, delivery_earning, expires_at, current_wave, wave_started_at, priority_score)
                    VALUES (?, ?, ?, NOW() + INTERVAL '60 seconds', 1, NOW(), ?)
                ")->execute([$order_id, $partner_id, $delivery_earning, $driver['priority_score']]);
                
                $offer_id = $pdo->lastInsertId();
                
                // Criar notificação
                $pdo->prepare("
                    INSERT INTO om_delivery_notifications (delivery_id, offer_id, order_id, wave_number)
                    VALUES (?, ?, ?, 1)
                ")->execute([$driver['delivery_id'], $offer_id, $order_id]);
                
                $log[] = "  → Oferta criada para driver #{$driver['delivery_id']} (score: {$driver['priority_score']})";
                
            } catch (Exception $e) {
                $log[] = "  → Erro criando oferta: " . $e->getMessage();
            }
        }
        
        // Log de dispatch
        $pdo->prepare("
            INSERT INTO om_dispatch_log (order_id, action, details, drivers_available, scan_progress, trigger_threshold)
            VALUES (?, 'dispatch_triggered', ?, ?, ?, ?)
        ")->execute([
            $order_id,
            json_encode(['drivers' => array_column($wave1_drivers, 'delivery_id')]),
            $drivers_count,
            $scan_progress,
            $threshold
        ]);
        
        // ════════════════════════════════════════════════════════════════════════════════════
        // 8. SE NÃO TEM DRIVER → ALERTA ADMIN
        // ════════════════════════════════════════════════════════════════════════════════════
        
        if ($drivers_count == 0) {
            $pdo->prepare("
                INSERT INTO om_admin_alerts (type, title, message, order_id, priority, created_at)
                VALUES ('no_driver', 'Sem Driver Disponível', ?, ?, 'high', NOW())
            ")->execute([
                "Pedido #{$order_id} - Nenhum driver disponível na região. Considere: mototáxi, Uber Entrega.",
                $order_id
            ]);
            
            $log[] = "  → ⚠️ ALERTA ADMIN: Sem driver!";
        }
        
    } else {
        $log[] = "  → Aguardando (scan {$scan_progress}% < threshold {$threshold}%)";
    }
}

// ════════════════════════════════════════════════════════════════════════════════════════════
// 9. CALCULAR TAXA DE ESPERA
// ════════════════════════════════════════════════════════════════════════════════════════════

$wait_free = intval($config['wait_fee_free_minutes'] ?? 5);
$wait_per_min = floatval($config['wait_fee_per_minute'] ?? 0.50);
$wait_max = floatval($config['wait_fee_max'] ?? 10.00);

$waiting_orders = $pdo->query("
    SELECT order_id, driver_arrived_at, shopper_finished_at, wait_fee
    FROM om_market_orders
    WHERE status IN ('shopping', 'ready')
    AND driver_arrived_at IS NOT NULL
    AND shopper_finished_at IS NULL
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($waiting_orders as $wo) {
    $arrived = strtotime($wo['driver_arrived_at']);
    $now = time();
    $wait_minutes = floor(($now - $arrived) / 60);
    
    if ($wait_minutes > $wait_free) {
        $billable_minutes = $wait_minutes - $wait_free;
        $fee = min($billable_minutes * $wait_per_min, $wait_max);
        
        $pdo->prepare("UPDATE om_market_orders SET wait_fee = ?, wait_minutes = ? WHERE order_id = ?")
            ->execute([$fee, $wait_minutes, $wo['order_id']]);
        
        $log[] = "Pedido #{$wo['order_id']}: espera {$wait_minutes}min, taxa R$ " . number_format($fee, 2);
    }
}

// ════════════════════════════════════════════════════════════════════════════════════════════
// FUNÇÕES AUXILIARES
// ════════════════════════════════════════════════════════════════════════════════════════════

function calcularDistancia($lat1, $lon1, $lat2, $lon2) {
    if (!$lat1 || !$lon1 || !$lat2 || !$lon2) return 0;
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

// Output
$output = [
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'pedidos_verificados' => count($pedidos),
    'log' => $log
];

if ($is_cli) {
    echo implode("\n", $log) . "\n";
} else {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
