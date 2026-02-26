<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════════════════════════════════╗
 * ║  ⚡ CRON OFERTAS - MOTOR DE MATCHING PRINCIPAL                                                           ║
 * ╠══════════════════════════════════════════════════════════════════════════════════════════════════════════╣
 * ║  Executa a cada minuto via crontab:                                                                      ║
 * ║  * * * * * php /home/.../public_html/mercado/cron/cron_ofertas.php                                       ║
 * ║                                                                                                          ║
 * ║  OU via URL com token:                                                                                   ║
 * ║  https://onemundo.com.br/mercado/cron/cron_ofertas.php?token=ONEMUNDO_CRON_2024                         ║
 * ╠══════════════════════════════════════════════════════════════════════════════════════════════════════════╣
 * ║  FUNÇÕES:                                                                                                ║
 * ║    1. Expirar ofertas antigas (>60s sem resposta)                                                        ║
 * ║    2. Buscar pedidos pagos sem shopper                                                                   ║
 * ║    3. Criar novas waves de ofertas                                                                       ║
 * ║    4. Notificar shoppers online                                                                          ║
 * ║    5. Alertar admin se pedido sem shopper por muito tempo                                                ║
 * ╚══════════════════════════════════════════════════════════════════════════════════════════════════════════╝
 */

// Só executa via CLI ou com token
$is_cli = php_sapi_name() === 'cli';
$has_token = isset($_GET['token']) && $_GET['token'] === 'ONEMUNDO_CRON_2024';

if (!$is_cli && !$has_token) {
    http_response_code(403);
    die(json_encode(['error' => 'Acesso negado']));
}

// Se for HTTP, retorna JSON
if (!$is_cli) {
    header('Content-Type: application/json; charset=utf-8');
}

$start_time = microtime(true);
$log = [];

function cron_log($msg, $type = 'info') {
    global $log, $is_cli;
    $line = "[" . date('H:i:s') . "] $msg";
    $log[] = ['time' => date('H:i:s'), 'msg' => $msg, 'type' => $type];
    if ($is_cli) echo $line . "\n";
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════
// CONEXÃO
// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════

// Usar config centralizado do mercado
require_once dirname(__DIR__) . '/config/database.php';

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    cron_log("❌ Erro DB: " . $e->getMessage(), 'error');
    if (!$is_cli) echo json_encode(['success' => false, 'error' => $e->getMessage(), 'log' => $log]);
    exit(1);
}

cron_log("═══════════════════════════════════════════════════════════════");
cron_log("🚀 CRON OFERTAS INICIADO");

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════
// BUSCAR CONFIGURAÇÕES
// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════

function getConfig($pdo, $key, $default = null) {
    try {
        $stmt = $pdo->prepare("SELECT config_value FROM om_matching_config WHERE config_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    } catch (Exception $e) {
        return $default;
    }
}

$OFFER_TIMEOUT = intval(getConfig($pdo, 'shopper_accept_timeout_sec', 60));
$WAVE_TIMEOUT = intval(getConfig($pdo, 'wave_timeout', 120));
$MAX_WAVES = intval(getConfig($pdo, 'max_waves', 5));
$WAVE1_COUNT = intval(getConfig($pdo, 'wave1_count', 3));
$WAVE2_COUNT = intval(getConfig($pdo, 'wave2_count', 5));
$WAVE3_COUNT = intval(getConfig($pdo, 'wave3_count', 10));
$SHOPPER_BASE_FEE = floatval(getConfig($pdo, 'delivery_fee_base', 8.90));

cron_log("⚙️ Config: Timeout={$OFFER_TIMEOUT}s, MaxWaves={$MAX_WAVES}, Wave1={$WAVE1_COUNT}, Wave2={$WAVE2_COUNT}");

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════
// 1. EXPIRAR OFERTAS ANTIGAS
// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════

cron_log("📋 1. Verificando ofertas expiradas...");

try {
    // Expirar ofertas que passaram do timeout
    $stmt = $pdo->prepare("UPDATE om_shopper_offers
        SET status = 'expired', responded_at = NOW()
        WHERE status = 'pending'
        AND (expires_at < NOW() OR created_at < NOW() - (? || ' seconds')::INTERVAL)");
    $stmt->execute([$OFFER_TIMEOUT]);
    $expired = $stmt->rowCount();
    
    if ($expired > 0) {
        cron_log("   ⏰ {$expired} oferta(s) expirada(s)", 'warning');
    } else {
        cron_log("   ✅ Nenhuma oferta expirada");
    }
} catch (Exception $e) {
    cron_log("   ❌ Erro: " . $e->getMessage(), 'error');
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════
// 2. BUSCAR PEDIDOS QUE PRECISAM DE SHOPPER
// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════

cron_log("📋 2. Buscando pedidos sem shopper...");

try {
    // Pedidos pagos sem shopper
    $stmt = $pdo->query("
        SELECT o.order_id, o.order_number, o.partner_id, o.total, o.total_items,
               o.matching_status, o.matching_wave, o.matching_started_at,
               o.created_at, p.name as partner_name, p.address as partner_address,
               EXTRACT(EPOCH FROM (NOW() - o.created_at))/60 as minutes_waiting
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.shopper_id IS NULL
          AND o.status IN ('pending', 'paid', 'confirmed', 'aguardando_shopper')
          AND o.payment_status IN ('paid', 'confirmed', 'aprovado')
          AND o.created_at > NOW() - INTERVAL '24 hours'
        ORDER BY o.created_at ASC
    ");
    $orders = $stmt->fetchAll();
    
    cron_log("   📦 " . count($orders) . " pedido(s) aguardando shopper");
    
    foreach ($orders as $order) {
        $orderId = $order['order_id'];
        $orderNum = $order['order_number'];
        $wave = intval($order['matching_wave'] ?? 0);
        $minutesWaiting = intval($order['minutes_waiting']);
        
        cron_log("   → Pedido #{$orderId} ({$orderNum}): Wave {$wave}, {$minutesWaiting} min esperando");
        
        // Verificar se já tem ofertas pendentes
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_shopper_offers WHERE order_id = ? AND status = 'pending'");
        $stmt->execute([$orderId]);
        $pendingOffers = $stmt->fetchColumn();
        
        if ($pendingOffers > 0) {
            cron_log("     ⏳ Já tem {$pendingOffers} oferta(s) pendente(s), aguardando...");
            continue;
        }
        
        // Verificar se pode criar nova wave
        if ($wave >= $MAX_WAVES) {
            cron_log("     ⚠️ Máximo de waves atingido ({$MAX_WAVES}), alertando admin...", 'warning');
            alertAdmin($pdo, $orderId, "Pedido #{$orderNum} sem shopper após {$MAX_WAVES} waves");
            continue;
        }
        
        // Verificar tempo desde última wave
        $lastWaveTime = $order['matching_started_at'] ?? $order['created_at'];
        $stmt = $pdo->prepare("SELECT MAX(created_at) FROM om_shopper_offers WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $lastOfferTime = $stmt->fetchColumn();
        
        if ($lastOfferTime) {
            $secondsSinceLastWave = time() - strtotime($lastOfferTime);
            if ($secondsSinceLastWave < $WAVE_TIMEOUT) {
                cron_log("     ⏳ Wave anterior há {$secondsSinceLastWave}s, aguardando {$WAVE_TIMEOUT}s...");
                continue;
            }
        }
        
        // Criar nova wave de ofertas
        $newWave = $wave + 1;
        $shoppersCount = $newWave == 1 ? $WAVE1_COUNT : ($newWave == 2 ? $WAVE2_COUNT : $WAVE3_COUNT);
        
        cron_log("     🚀 Criando Wave {$newWave} com {$shoppersCount} shoppers...");
        
        $created = createOffersForOrder($pdo, $order, $newWave, $shoppersCount, $OFFER_TIMEOUT, $SHOPPER_BASE_FEE);
        
        if ($created > 0) {
            // Atualizar pedido
            $pdo->prepare("UPDATE om_market_orders SET 
                matching_status = 'searching', 
                matching_wave = ?,
                matching_started_at = COALESCE(matching_started_at, NOW())
                WHERE order_id = ?")->execute([$newWave, $orderId]);
            
            cron_log("     ✅ {$created} oferta(s) criada(s)!", 'success');
            
            // Registrar timeline
            $pdo->prepare("INSERT INTO om_order_timeline (order_id, action, details, created_at) VALUES (?, ?, ?, NOW())")
                ->execute([$orderId, 'matching_wave_' . $newWave, "Criadas {$created} ofertas para shoppers"]);
        } else {
            cron_log("     ⚠️ Nenhum shopper disponível!", 'warning');
        }
    }
    
} catch (Exception $e) {
    cron_log("   ❌ Erro: " . $e->getMessage(), 'error');
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════
// 3. VERIFICAR PEDIDOS MUITO ANTIGOS
// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════

cron_log("📋 3. Verificando pedidos antigos...");

try {
    $stmt = $pdo->query("
        SELECT order_id, order_number, EXTRACT(EPOCH FROM (NOW() - created_at))/60 as minutes
        FROM om_market_orders
        WHERE shopper_id IS NULL
          AND status IN ('pending', 'paid', 'confirmed')
          AND payment_status IN ('paid', 'confirmed')
          AND created_at < NOW() - INTERVAL '30 minutes'
          AND created_at > NOW() - INTERVAL '24 hours'
    ");
    $oldOrders = $stmt->fetchAll();
    
    foreach ($oldOrders as $old) {
        cron_log("   ⚠️ Pedido #{$old['order_id']} esperando há {$old['minutes']} minutos!", 'warning');
    }
    
    if (empty($oldOrders)) {
        cron_log("   ✅ Nenhum pedido muito antigo");
    }
} catch (Exception $e) {}

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════
// FUNÇÕES AUXILIARES
// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════

function createOffersForOrder($pdo, $order, $wave, $count, $timeout, $baseFee) {
    $orderId = $order['order_id'];
    $partnerId = $order['partner_id'];
    $total = floatval($order['total'] ?? 0);
    
    // Calcular ganho do shopper (base + % do pedido)
    $shopperEarning = $baseFee + ($total * 0.05); // 5% do pedido
    
    // Buscar shoppers online que não recusaram este pedido
    $stmt = $pdo->prepare("
        SELECT s.shopper_id, s.name, s.rating, s.push_token
        FROM om_market_shoppers s
        WHERE (s.status = 'online' OR s.status = '1' OR s.is_online = 1)
          AND s.shopper_id NOT IN (
              SELECT shopper_id FROM om_shopper_offers 
              WHERE order_id = ? AND status IN ('rejected', 'expired')
          )
        ORDER BY s.rating DESC, RANDOM()
        LIMIT ?
    ");
    $stmt->execute([$orderId, $count]);
    $shoppers = $stmt->fetchAll();
    
    if (empty($shoppers)) {
        // Tentar buscar QUALQUER shopper
        $stmt = $pdo->prepare("
            SELECT s.shopper_id, s.name, s.rating, s.push_token
            FROM om_market_shoppers s
            WHERE s.shopper_id NOT IN (
                SELECT shopper_id FROM om_shopper_offers 
                WHERE order_id = ? AND status IN ('rejected', 'expired')
            )
            ORDER BY s.rating DESC, RANDOM()
            LIMIT ?
        ");
        $stmt->execute([$orderId, $count]);
        $shoppers = $stmt->fetchAll();
    }
    
    $created = 0;
    $expiresAt = date('Y-m-d H:i:s', time() + $timeout);
    
    foreach ($shoppers as $shopper) {
        try {
            // Tentar diferentes estruturas de INSERT
            $inserted = false;
            $insertQueries = [
                ["INSERT INTO om_shopper_offers (order_id, shopper_id, status, earning, wave, expires_at, created_at) VALUES (?, ?, 'pending', ?, ?, ?, NOW())", [$orderId, $shopper['shopper_id'], $shopperEarning, $wave, $expiresAt]],
                ["INSERT INTO om_shopper_offers (order_id, shopper_id, status, amount, wave, expires_at, created_at) VALUES (?, ?, 'pending', ?, ?, ?, NOW())", [$orderId, $shopper['shopper_id'], $shopperEarning, $wave, $expiresAt]],
                ["INSERT INTO om_shopper_offers (order_id, shopper_id, status, wave, expires_at, created_at) VALUES (?, ?, 'pending', ?, ?, NOW())", [$orderId, $shopper['shopper_id'], $wave, $expiresAt]],
            ];
            
            foreach ($insertQueries as $q) {
                try {
                    $pdo->prepare($q[0])->execute($q[1]);
                    $inserted = true;
                    break;
                } catch (Exception $e) {
                    continue;
                }
            }
            
            if (!$inserted) continue;
            
            $created++;
            
            // Criar notificação
            $pdo->prepare("INSERT INTO om_notifications 
                (user_type, user_id, title, body, type, data, created_at)
                VALUES ('shopper', ?, '🛒 Nova Oferta!', ?, 'new_offer', ?, NOW())")
                ->execute([
                    $shopper['shopper_id'],
                    "R$ " . number_format($shopperEarning, 2, ',', '.') . " - Aceite em {$timeout}s!",
                    json_encode(['order_id' => $orderId, 'amount' => $shopperEarning])
                ]);
            
            // TODO: Enviar push notification se tiver token
            // if ($shopper['push_token']) { sendPush(...) }
            
        } catch (Exception $e) {
            // Ignorar duplicatas
        }
    }
    
    return $created;
}

function alertAdmin($pdo, $orderId, $message) {
    try {
        $pdo->prepare("INSERT INTO om_admin_alerts (type, order_id, message, created_at) VALUES ('no_shopper', ?, ?, NOW())")
            ->execute([$orderId, $message]);
    } catch (Exception $e) {
        // Tabela pode não existir
    }
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════
// FINALIZAÇÃO
// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════

$duration = round((microtime(true) - $start_time) * 1000);
cron_log("═══════════════════════════════════════════════════════════════");
cron_log("✅ CRON FINALIZADO em {$duration}ms");

// Retornar resultado
if (!$is_cli) {
    echo json_encode([
        'success' => true,
        'duration_ms' => $duration,
        'log' => $log
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
