<?php
require_once __DIR__ . '/config/database.php';
/**
 * ╔══════════════════════════════════════════════════════════════════════════════════════════╗
 * ║              🛠️ INSTALADOR DISPATCH INTELIGENTE v1.0                                     ║
 * ║                   OneMundo Market - Sistema Completo                                      ║
 * ╠══════════════════════════════════════════════════════════════════════════════════════════╣
 * ║                                                                                          ║
 * ║  Este instalador cria TUDO que está faltando para o Dispatch Inteligente:                ║
 * ║                                                                                          ║
 * ║  ✅ Colunas em om_market_orders (scan_progress, wait_fee, etc)                           ║
 * ║  ✅ Colunas em om_market_deliveries (score_interno, etc)                                 ║
 * ║  ✅ Tabelas novas (om_driver_batches, om_dispatch_config, om_dispatch_log)               ║
 * ║  ✅ CRON dispatch_driver.php                                                             ║
 * ║  ✅ API dispatch_inteligente.php                                                         ║
 * ║  ✅ Configurações padrão                                                                 ║
 * ║                                                                                          ║
 * ╚══════════════════════════════════════════════════════════════════════════════════════════╝
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 300);

$BASE = __DIR__;
$logs = [];

function logMsg($msg, $type = 'info') {
    global $logs;
    $logs[] = ['msg' => $msg, 'type' => $type];
    echo "<div class='log {$type}'>" . ($type === 'ok' ? '✅' : ($type === 'error' ? '❌' : ($type === 'warning' ? '⚠️' : 'ℹ️'))) . " {$msg}</div>";
    flush();
}

// ════════════════════════════════════════════════════════════════════════════════════════════
// CONEXÃO
// ════════════════════════════════════════════════════════════════════════════════════════════

try {
    $pdo = getPDO();
    $db_ok = true;
} catch (PDOException $e) {
    $db_ok = false;
    die("❌ Erro de conexão: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🛠️ Instalador Dispatch Inteligente</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #e2e8f0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; }
        h1 { font-size: 24px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
        .section { background: #1e293b; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .section h2 { font-size: 16px; margin-bottom: 15px; color: #94a3b8; }
        .log { padding: 8px 12px; margin: 4px 0; border-radius: 6px; font-size: 14px; }
        .log.ok { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        .log.error { background: rgba(239, 68, 68, 0.2); color: #f87171; }
        .log.warning { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
        .log.info { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; border-radius: 10px; font-weight: 600; text-decoration: none; border: none; cursor: pointer; font-size: 14px; margin-top: 20px; }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-success { background: #10b981; color: white; }
        pre { background: #0f172a; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 12px; margin-top: 10px; }
    </style>
</head>
<body>
<div class="container">
    <h1>🛠️ Instalador Dispatch Inteligente</h1>
    
    <!-- SEÇÃO 1: COLUNAS OM_MARKET_ORDERS -->
    <div class="section">
        <h2>1️⃣ Colunas em om_market_orders</h2>
        <?php
        $order_columns = [
            'items_scanned' => 'INT DEFAULT 0 COMMENT "Itens já escaneados"',
            'scan_progress' => 'DECIMAL(5,2) DEFAULT 0 COMMENT "Progresso do scan em %"',
            'driver_dispatch_at' => 'DATETIME DEFAULT NULL COMMENT "Quando disparou para drivers"',
            'driver_arrived_at' => 'DATETIME DEFAULT NULL COMMENT "Quando driver chegou no mercado"',
            'wait_fee' => 'DECIMAL(10,2) DEFAULT 0 COMMENT "Taxa de espera acumulada"',
            'wait_minutes' => 'INT DEFAULT 0 COMMENT "Minutos de espera do driver"',
            'batch_id' => 'INT DEFAULT NULL COMMENT "ID do batch se estiver em batching"'
        ];
        
        foreach ($order_columns as $col => $definition) {
            try {
                $check = $pdo->query("SHOW COLUMNS FROM om_market_orders LIKE '{$col}'")->rowCount();
                if ($check == 0) {
                    $pdo->exec("ALTER TABLE om_market_orders ADD COLUMN {$col} {$definition}");
                    logMsg("Coluna {$col} criada", 'ok');
                } else {
                    logMsg("Coluna {$col} já existe", 'info');
                }
            } catch (Exception $e) {
                logMsg("Erro em {$col}: " . $e->getMessage(), 'error');
            }
        }
        ?>
    </div>
    
    <!-- SEÇÃO 2: COLUNAS OM_MARKET_DELIVERIES -->
    <div class="section">
        <h2>2️⃣ Colunas em om_market_deliveries</h2>
        <?php
        $driver_columns = [
            'score_interno' => 'INT DEFAULT 100 COMMENT "Score interno 0-100 (penalidade)"',
            'total_entregas' => 'INT DEFAULT 0 COMMENT "Total de entregas realizadas"',
            'total_desistencias' => 'INT DEFAULT 0 COMMENT "Total de desistências"',
            'rating_avg' => 'DECIMAL(3,2) DEFAULT 5.00 COMMENT "Avaliação média"',
            'can_batch' => 'TINYINT(1) DEFAULT 1 COMMENT "Pode fazer batching"',
            'max_batch_orders' => 'INT DEFAULT 5 COMMENT "Máximo de pedidos em batch"',
            'current_batch_id' => 'INT DEFAULT NULL COMMENT "Batch atual"',
            'last_delivery_at' => 'DATETIME DEFAULT NULL COMMENT "Última entrega"'
        ];
        
        foreach ($driver_columns as $col => $definition) {
            try {
                $check = $pdo->query("SHOW COLUMNS FROM om_market_deliveries LIKE '{$col}'")->rowCount();
                if ($check == 0) {
                    $pdo->exec("ALTER TABLE om_market_deliveries ADD COLUMN {$col} {$definition}");
                    logMsg("Coluna {$col} criada", 'ok');
                } else {
                    logMsg("Coluna {$col} já existe", 'info');
                }
            } catch (Exception $e) {
                logMsg("Erro em {$col}: " . $e->getMessage(), 'error');
            }
        }
        ?>
    </div>
    
    <!-- SEÇÃO 3: TABELAS NOVAS -->
    <div class="section">
        <h2>3️⃣ Tabelas Novas</h2>
        <?php
        
        // om_dispatch_config
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS om_dispatch_config (
                    config_key VARCHAR(50) PRIMARY KEY,
                    config_value TEXT NOT NULL,
                    description TEXT,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            logMsg("Tabela om_dispatch_config criada/verificada", 'ok');
            
            // Inserir configs padrão
            $configs = [
                ['driver_search_radius_km', '30', 'Raio máximo de busca de drivers (em km ou minutos)'],
                ['wait_fee_free_minutes', '5', 'Minutos grátis de espera'],
                ['wait_fee_per_minute', '0.50', 'Taxa por minuto após período grátis (R$)'],
                ['wait_fee_max', '10.00', 'Taxa máxima de espera (R$)'],
                ['max_batch_orders', '5', 'Máximo de pedidos por batch'],
                ['trigger_0_2_drivers', '0', '% escaneado para disparar com 0-2 drivers'],
                ['trigger_3_5_drivers', '30', '% escaneado para disparar com 3-5 drivers'],
                ['trigger_6_10_drivers', '60', '% escaneado para disparar com 6-10 drivers'],
                ['trigger_10plus_drivers', '85', '% escaneado para disparar com 10+ drivers'],
                ['penalty_desistencia', '10', 'Pontos perdidos por desistência'],
                ['min_score_for_offers', '30', 'Score mínimo para receber ofertas']
            ];
            
            $stmt = $pdo->prepare("INSERT IGNORE INTO om_dispatch_config (config_key, config_value, description) VALUES (?, ?, ?)");
            foreach ($configs as $c) {
                $stmt->execute($c);
            }
            logMsg("Configurações padrão inseridas", 'ok');
            
        } catch (Exception $e) {
            logMsg("Erro om_dispatch_config: " . $e->getMessage(), 'error');
        }
        
        // om_driver_batches
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS om_driver_batches (
                    batch_id INT AUTO_INCREMENT PRIMARY KEY,
                    driver_id INT NOT NULL,
                    orders_json JSON COMMENT 'Array de order_ids',
                    route_optimized JSON COMMENT 'Rota otimizada',
                    total_orders INT DEFAULT 0,
                    total_distance_km DECIMAL(10,2) DEFAULT 0,
                    estimated_time_min INT DEFAULT 0,
                    status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
                    started_at DATETIME DEFAULT NULL,
                    completed_at DATETIME DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_driver (driver_id),
                    INDEX idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            logMsg("Tabela om_driver_batches criada/verificada", 'ok');
        } catch (Exception $e) {
            logMsg("Erro om_driver_batches: " . $e->getMessage(), 'error');
        }
        
        // om_dispatch_log
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS om_dispatch_log (
                    log_id INT AUTO_INCREMENT PRIMARY KEY,
                    order_id INT NOT NULL,
                    action VARCHAR(50) NOT NULL,
                    details JSON,
                    drivers_available INT DEFAULT 0,
                    scan_progress DECIMAL(5,2) DEFAULT 0,
                    trigger_threshold INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_order (order_id),
                    INDEX idx_action (action)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            logMsg("Tabela om_dispatch_log criada/verificada", 'ok');
        } catch (Exception $e) {
            logMsg("Erro om_dispatch_log: " . $e->getMessage(), 'error');
        }
        
        // om_driver_penalties
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS om_driver_penalties (
                    penalty_id INT AUTO_INCREMENT PRIMARY KEY,
                    driver_id INT NOT NULL,
                    order_id INT DEFAULT NULL,
                    reason ENUM('desistencia', 'atraso', 'reclamacao', 'cancelamento') NOT NULL,
                    points_lost INT DEFAULT 0,
                    description TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_driver (driver_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            logMsg("Tabela om_driver_penalties criada/verificada", 'ok');
        } catch (Exception $e) {
            logMsg("Erro om_driver_penalties: " . $e->getMessage(), 'error');
        }
        ?>
    </div>
    
    <!-- SEÇÃO 4: CRON DISPATCH DRIVER -->
    <div class="section">
        <h2>4️⃣ CRON Dispatch Driver Inteligente</h2>
        <?php
        
        $cron_dispatch = '<?php
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

$is_cli = (php_sapi_name() === \'cli\');
if (!$is_cli) header(\'Content-Type: application/json\');

date_default_timezone_set(\'America/Sao_Paulo\');

// Conexão
try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die(json_encode([\'error\' => \'DB connection failed\']));
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
        \'trigger_0_2_drivers\' => 0,
        \'trigger_3_5_drivers\' => 30,
        \'trigger_6_10_drivers\' => 60,
        \'trigger_10plus_drivers\' => 85,
        \'driver_search_radius_km\' => 30,
        \'max_batch_orders\' => 5,
        \'min_score_for_offers\' => 30
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
    WHERE o.status = \'shopping\'
    AND o.shopper_id IS NOT NULL
    AND o.delivery_driver_id IS NULL
    AND o.driver_dispatch_at IS NULL
    ORDER BY o.created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

$log[] = "Pedidos em shopping: " . count($pedidos);

foreach ($pedidos as $pedido) {
    $order_id = $pedido[\'order_id\'];
    $partner_id = $pedido[\'partner_id\'];
    $scan_progress = floatval($pedido[\'scan_progress\'] ?? 0);
    
    // ════════════════════════════════════════════════════════════════════════════════════════
    // 3. CONTAR DRIVERS DISPONÍVEIS NA REGIÃO
    // ════════════════════════════════════════════════════════════════════════════════════════
    
    $mercado_lat = $pedido[\'mercado_lat\'] ?? -23.5505;
    $mercado_lng = $pedido[\'mercado_lng\'] ?? -46.6333;
    $radius = floatval($config[\'driver_search_radius_km\'] ?? 30);
    $min_score = intval($config[\'min_score_for_offers\'] ?? 30);
    
    // Buscar drivers online, disponíveis e com score adequado
    $drivers = $pdo->query("
        SELECT 
            d.delivery_id,
            d.name,
            d.current_latitude,
            d.current_longitude,
            d.score_interno,
            d.can_batch,
            d.current_batch_id,
            (SELECT COUNT(*) FROM om_driver_batches b WHERE b.driver_id = d.delivery_id AND b.status = \'active\') as batches_ativos,
            (
                6371 * acos(
                    cos(radians({$mercado_lat})) * cos(radians(COALESCE(d.current_latitude, 0))) *
                    cos(radians(COALESCE(d.current_longitude, 0)) - radians({$mercado_lng})) +
                    sin(radians({$mercado_lat})) * sin(radians(COALESCE(d.current_latitude, 0)))
                )
            ) AS distancia_km
        FROM om_market_deliveries d
        WHERE d.is_online = 1
        AND d.status = \'ativo\'
        AND COALESCE(d.score_interno, 100) >= {$min_score}
        HAVING distancia_km <= {$radius} OR distancia_km IS NULL
        ORDER BY distancia_km ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $drivers_count = count($drivers);
    $log[] = "Pedido #{$order_id}: {$drivers_count} drivers disponíveis, scan: {$scan_progress}%";
    
    // ════════════════════════════════════════════════════════════════════════════════════════
    // 4. DETERMINAR THRESHOLD DE TRIGGER
    // ════════════════════════════════════════════════════════════════════════════════════════
    
    if ($drivers_count <= 2) {
        $threshold = intval($config[\'trigger_0_2_drivers\'] ?? 0);
    } elseif ($drivers_count <= 5) {
        $threshold = intval($config[\'trigger_3_5_drivers\'] ?? 30);
    } elseif ($drivers_count <= 10) {
        $threshold = intval($config[\'trigger_6_10_drivers\'] ?? 60);
    } else {
        $threshold = intval($config[\'trigger_10plus_drivers\'] ?? 85);
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
        
        $dest_lat = $pedido[\'shipping_latitude\'] ?? $mercado_lat;
        $dest_lng = $pedido[\'shipping_longitude\'] ?? $mercado_lng;
        
        $prioritized_drivers = [];
        
        foreach ($drivers as $driver) {
            $driver[\'priority_score\'] = 0;
            
            // Prioridade 1: Driver com batch ativo indo pro mesmo destino
            if ($driver[\'can_batch\'] && $driver[\'current_batch_id\']) {
                // Verificar se destino está no caminho
                $batch = $pdo->prepare("SELECT route_optimized FROM om_driver_batches WHERE batch_id = ?");
                $batch->execute([$driver[\'current_batch_id\']]);
                $batch_data = $batch->fetch(PDO::FETCH_ASSOC);
                
                if ($batch_data && $batch_data[\'route_optimized\']) {
                    // Simplificação: adicionar 50 pontos de prioridade
                    $driver[\'priority_score\'] += 50;
                }
            }
            
            // Prioridade 2: Distância (quanto mais perto, mais pontos)
            $dist = floatval($driver[\'distancia_km\'] ?? 999);
            $driver[\'priority_score\'] += max(0, 30 - ($dist * 2));
            
            // Prioridade 3: Score interno
            $driver[\'priority_score\'] += (intval($driver[\'score_interno\'] ?? 100) / 10);
            
            $prioritized_drivers[] = $driver;
        }
        
        // Ordenar por priority_score DESC
        usort($prioritized_drivers, function($a, $b) {
            return $b[\'priority_score\'] <=> $a[\'priority_score\'];
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
                    VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 60 SECOND), 1, NOW(), ?)
                ")->execute([$order_id, $partner_id, $delivery_earning, $driver[\'priority_score\']]);
                
                $offer_id = $pdo->lastInsertId();
                
                // Criar notificação
                $pdo->prepare("
                    INSERT INTO om_delivery_notifications (delivery_id, offer_id, order_id, wave_number)
                    VALUES (?, ?, ?, 1)
                ")->execute([$driver[\'delivery_id\'], $offer_id, $order_id]);
                
                $log[] = "  → Oferta criada para driver #{$driver[\'delivery_id\']} (score: {$driver[\'priority_score\']})";
                
            } catch (Exception $e) {
                $log[] = "  → Erro criando oferta: " . $e->getMessage();
            }
        }
        
        // Log de dispatch
        $pdo->prepare("
            INSERT INTO om_dispatch_log (order_id, action, details, drivers_available, scan_progress, trigger_threshold)
            VALUES (?, \'dispatch_triggered\', ?, ?, ?, ?)
        ")->execute([
            $order_id,
            json_encode([\'drivers\' => array_column($wave1_drivers, \'delivery_id\')]),
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
                VALUES (\'no_driver\', \'Sem Driver Disponível\', ?, ?, \'high\', NOW())
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

$wait_free = intval($config[\'wait_fee_free_minutes\'] ?? 5);
$wait_per_min = floatval($config[\'wait_fee_per_minute\'] ?? 0.50);
$wait_max = floatval($config[\'wait_fee_max\'] ?? 10.00);

$waiting_orders = $pdo->query("
    SELECT order_id, driver_arrived_at, shopper_finished_at, wait_fee
    FROM om_market_orders
    WHERE status IN (\'shopping\', \'ready\')
    AND driver_arrived_at IS NOT NULL
    AND shopper_finished_at IS NULL
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($waiting_orders as $wo) {
    $arrived = strtotime($wo[\'driver_arrived_at\']);
    $now = time();
    $wait_minutes = floor(($now - $arrived) / 60);
    
    if ($wait_minutes > $wait_free) {
        $billable_minutes = $wait_minutes - $wait_free;
        $fee = min($billable_minutes * $wait_per_min, $wait_max);
        
        $pdo->prepare("UPDATE om_market_orders SET wait_fee = ?, wait_minutes = ? WHERE order_id = ?")
            ->execute([$fee, $wait_minutes, $wo[\'order_id\']]);
        
        $log[] = "Pedido #{$wo[\'order_id\']}: espera {$wait_minutes}min, taxa R$ " . number_format($fee, 2);
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
    \'success\' => true,
    \'timestamp\' => date(\'Y-m-d H:i:s\'),
    \'pedidos_verificados\' => count($pedidos),
    \'log\' => $log
];

if ($is_cli) {
    echo implode("\n", $log) . "\n";
} else {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
';
        
        $cronDir = $BASE . '/cron';
        if (!is_dir($cronDir)) {
            mkdir($cronDir, 0755, true);
        }
        
        if (file_put_contents($cronDir . '/dispatch_driver.php', $cron_dispatch)) {
            logMsg("CRON dispatch_driver.php criado", 'ok');
        } else {
            logMsg("Erro ao criar dispatch_driver.php", 'error');
        }
        ?>
    </div>
    
    <!-- SEÇÃO 5: API ATUALIZAR SCAN PROGRESS -->
    <div class="section">
        <h2>5️⃣ API Atualizar Scan Progress</h2>
        <?php
        
        $api_scan = '<?php
/**
 * API para atualizar progresso do scan
 * Chamada pelo app do shopper quando escaneia um produto
 */

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die(json_encode(["error" => "DB error"]));
}

$input = json_decode(file_get_contents("php://input"), true);
$order_id = intval($input["order_id"] ?? $_GET["order_id"] ?? 0);
$action = $input["action"] ?? $_GET["action"] ?? "";

if (!$order_id) {
    die(json_encode(["error" => "order_id required"]));
}

switch ($action) {
    case "scan_item":
        // Incrementar items_scanned e recalcular progress
        $pdo->prepare("
            UPDATE om_market_orders 
            SET items_scanned = items_scanned + 1,
                scan_progress = CASE 
                    WHEN items_total > 0 THEN ROUND((items_scanned + 1) / items_total * 100, 2)
                    ELSE 0
                END
            WHERE order_id = ?
        ")->execute([$order_id]);
        
        // Buscar valores atualizados
        $order = $pdo->query("SELECT items_scanned, items_total, scan_progress FROM om_market_orders WHERE order_id = {$order_id}")->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            "success" => true,
            "items_scanned" => intval($order["items_scanned"]),
            "items_total" => intval($order["items_total"]),
            "scan_progress" => floatval($order["scan_progress"])
        ]);
        break;
        
    case "get_progress":
        $order = $pdo->query("SELECT items_scanned, items_total, scan_progress, driver_dispatch_at FROM om_market_orders WHERE order_id = {$order_id}")->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            "success" => true,
            "items_scanned" => intval($order["items_scanned"] ?? 0),
            "items_total" => intval($order["items_total"] ?? 0),
            "scan_progress" => floatval($order["scan_progress"] ?? 0),
            "driver_dispatched" => !empty($order["driver_dispatch_at"])
        ]);
        break;
        
    default:
        echo json_encode(["error" => "action required: scan_item or get_progress"]);
}
';
        
        $apiDir = $BASE . '/api';
        if (!is_dir($apiDir)) {
            mkdir($apiDir, 0755, true);
        }
        
        if (file_put_contents($apiDir . '/scan_progress.php', $api_scan)) {
            logMsg("API scan_progress.php criada", 'ok');
        } else {
            logMsg("Erro ao criar scan_progress.php", 'error');
        }
        ?>
    </div>
    
    <!-- SEÇÃO 6: API DRIVER CHEGOU -->
    <div class="section">
        <h2>6️⃣ API Driver Chegou no Mercado</h2>
        <?php
        
        $api_driver_arrived = '<?php
/**
 * API para registrar quando driver chegou no mercado
 * Inicia contagem de tempo de espera
 */

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die(json_encode(["error" => "DB error"]));
}

$input = json_decode(file_get_contents("php://input"), true);
$order_id = intval($input["order_id"] ?? $_POST["order_id"] ?? 0);
$driver_id = intval($input["driver_id"] ?? $_POST["driver_id"] ?? 0);

if (!$order_id || !$driver_id) {
    die(json_encode(["error" => "order_id and driver_id required"]));
}

// Verificar se pedido pertence ao driver
$order = $pdo->query("SELECT delivery_driver_id, driver_arrived_at FROM om_market_orders WHERE order_id = {$order_id}")->fetch(PDO::FETCH_ASSOC);

if (!$order || intval($order["delivery_driver_id"]) !== $driver_id) {
    die(json_encode(["error" => "Pedido não encontrado ou não pertence a este driver"]));
}

if ($order["driver_arrived_at"]) {
    die(json_encode(["success" => true, "message" => "Chegada já registrada", "arrived_at" => $order["driver_arrived_at"]]));
}

// Registrar chegada
$pdo->prepare("UPDATE om_market_orders SET driver_arrived_at = NOW() WHERE order_id = ?")->execute([$order_id]);

// Log
$pdo->prepare("
    INSERT INTO om_dispatch_log (order_id, action, details)
    VALUES (?, \'driver_arrived\', ?)
")->execute([$order_id, json_encode(["driver_id" => $driver_id])]);

echo json_encode([
    "success" => true,
    "message" => "Chegada registrada",
    "arrived_at" => date("Y-m-d H:i:s"),
    "wait_free_minutes" => 5,
    "wait_fee_per_minute" => 0.50
]);
';
        
        if (file_put_contents($apiDir . '/driver_arrived.php', $api_driver_arrived)) {
            logMsg("API driver_arrived.php criada", 'ok');
        } else {
            logMsg("Erro ao criar driver_arrived.php", 'error');
        }
        ?>
    </div>
    
    <!-- SEÇÃO 7: API PENALIDADE DRIVER -->
    <div class="section">
        <h2>7️⃣ API Penalidade Driver (Desistência)</h2>
        <?php
        
        $api_penalty = '<?php
/**
 * API para aplicar penalidade quando driver desiste
 * Reduz score_interno e registra desistência
 */

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die(json_encode(["error" => "DB error"]));
}

$input = json_decode(file_get_contents("php://input"), true);
$driver_id = intval($input["driver_id"] ?? $_POST["driver_id"] ?? 0);
$order_id = intval($input["order_id"] ?? $_POST["order_id"] ?? 0);
$reason = $input["reason"] ?? "desistencia";

if (!$driver_id) {
    die(json_encode(["error" => "driver_id required"]));
}

// Buscar config de penalidade
$penalty_points = 10; // default
try {
    $config = $pdo->query("SELECT config_value FROM om_dispatch_config WHERE config_key = \'penalty_desistencia\'")->fetchColumn();
    if ($config) $penalty_points = intval($config);
} catch (Exception $e) {}

// Aplicar penalidade
$pdo->prepare("
    UPDATE om_market_deliveries 
    SET score_interno = GREATEST(0, COALESCE(score_interno, 100) - ?),
        total_desistencias = COALESCE(total_desistencias, 0) + 1
    WHERE delivery_id = ?
")->execute([$penalty_points, $driver_id]);

// Registrar penalidade
$pdo->prepare("
    INSERT INTO om_driver_penalties (driver_id, order_id, reason, points_lost, description)
    VALUES (?, ?, ?, ?, ?)
")->execute([$driver_id, $order_id ?: null, $reason, $penalty_points, "Desistência de pedido"]);

// Buscar novo score
$new_score = $pdo->query("SELECT score_interno FROM om_market_deliveries WHERE delivery_id = {$driver_id}")->fetchColumn();

// Se tinha pedido, liberar para outro driver
if ($order_id) {
    $pdo->prepare("
        UPDATE om_market_orders 
        SET delivery_driver_id = NULL, 
            driver_dispatch_at = NULL,
            driver_arrived_at = NULL,
            wait_fee = 0
        WHERE order_id = ?
    ")->execute([$order_id]);
    
    // Cancelar oferta do driver
    $pdo->prepare("
        UPDATE om_delivery_offers 
        SET status = \'cancelled\', cancelled_reason = \'driver_desistiu\'
        WHERE order_id = ? AND accepted_by = ?
    ")->execute([$order_id, $driver_id]);
}

echo json_encode([
    "success" => true,
    "message" => "Penalidade aplicada",
    "points_lost" => $penalty_points,
    "new_score" => intval($new_score),
    "order_released" => $order_id ? true : false
]);
';
        
        if (file_put_contents($apiDir . '/driver_penalty.php', $api_penalty)) {
            logMsg("API driver_penalty.php criada", 'ok');
        } else {
            logMsg("Erro ao criar driver_penalty.php", 'error');
        }
        ?>
    </div>
    
    <!-- SEÇÃO 8: ATUALIZAR ITEMS_TOTAL DOS PEDIDOS -->
    <div class="section">
        <h2>8️⃣ Atualizar items_total dos pedidos existentes</h2>
        <?php
        try {
            // Atualizar items_total baseado nos itens do pedido
            $updated = $pdo->exec("
                UPDATE om_market_orders o
                SET items_total = (
                    SELECT COALESCE(SUM(quantity), 0) 
                    FROM om_market_order_items 
                    WHERE order_id = o.order_id
                )
                WHERE items_total IS NULL OR items_total = 0
            ");
            logMsg("Pedidos atualizados com items_total: {$updated}", 'ok');
        } catch (Exception $e) {
            logMsg("Erro ao atualizar items_total: " . $e->getMessage(), 'warning');
        }
        ?>
    </div>
    
    <!-- RESUMO FINAL -->
    <div class="section" style="background: linear-gradient(135deg, #065f46, #047857);">
        <h2 style="color: white;">✅ Instalação Concluída!</h2>
        <p style="color: #d1fae5; margin-bottom: 15px;">
            Todos os componentes do Dispatch Inteligente foram instalados.
        </p>
        
        <h3 style="color: white; margin-top: 20px;">📋 Próximos passos:</h3>
        <ol style="color: #d1fae5; margin-left: 20px; line-height: 2;">
            <li>Configurar CRON para dispatch_driver.php (a cada 30s)</li>
            <li>Testar fluxo: criar pedido → shopper aceita → escanear itens → ver driver ser notificado</li>
            <li>Ajustar configs em om_dispatch_config se necessário</li>
        </ol>
        
        <h3 style="color: white; margin-top: 20px;">⚙️ Comando CRON sugerido:</h3>
        <pre style="color: #10b981;">
# Executar a cada minuto (30s + 30s)
* * * * * php <?= $BASE ?>/cron/dispatch_driver.php >> /var/log/dispatch.log 2>&1
* * * * * sleep 30 && php <?= $BASE ?>/cron/dispatch_driver.php >> /var/log/dispatch.log 2>&1
        </pre>
        
        <a href="DIAGNOSTICO_DISPATCH_INTELIGENTE.php" class="btn btn-primary">🔬 Rodar Diagnóstico Novamente</a>
        <a href="admin/" class="btn btn-success">📊 Ir para Admin</a>
    </div>
    
    <p style="margin-top: 20px; color: #64748b; font-size: 13px;">
        ⚠️ Instalação executada em <?= date('d/m/Y H:i:s') ?> - <strong>Apague este arquivo após usar!</strong>
    </p>
</div>
</body>
</html>
