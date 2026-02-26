<?php
require_once __DIR__ . '/config/database.php';
/**
 * ╔══════════════════════════════════════════════════════════════════════════════════════╗
 * ║                    🛒 API DE OFERTAS PARA SHOPPERS                                   ║
 * ║                      Sistema de Waves estilo Uber/Instacart                          ║
 * ╠══════════════════════════════════════════════════════════════════════════════════════╣
 * ║                                                                                      ║
 * ║  AÇÕES DISPONÍVEIS:                                                                  ║
 * ║  • create_offer    - Cria oferta e dispara Wave 1                                    ║
 * ║  • process_waves   - Processa waves pendentes (cron)                                 ║
 * ║  • accept_offer    - Shopper aceita oferta                                           ║
 * ║  • reject_offer    - Shopper recusa oferta                                           ║
 * ║  • get_offers      - Lista ofertas para um shopper                                   ║
 * ║  • get_offer       - Detalhes de uma oferta                                          ║
 * ║  • update_location - Atualiza GPS do shopper                                         ║
 * ║  • toggle_online   - Shopper fica online/offline                                     ║
 * ║                                                                                      ║
 * ╚══════════════════════════════════════════════════════════════════════════════════════╝
 */

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();
date_default_timezone_set('America/Sao_Paulo');

// ════════════════════════════════════════════════════════════════════════════════════════
// CONFIGURAÇÕES
// ════════════════════════════════════════════════════════════════════════════════════════

define('WAVE1_COUNT', 3);           // Shoppers na wave 1
define('WAVE2_COUNT', 5);           // Shoppers adicionais na wave 2
define('WAVE_TIMEOUT', 120);        // Segundos entre waves
define('OFFER_TIMEOUT', 1800);      // Timeout total (30 min)
define('MAX_DISTANCE_KM', 15);      // Distância máxima
define('DEFAULT_COMMISSION', 10);   // % comissão padrão
define('MIN_EARNING', 8.00);        // Ganho mínimo

// Z-API (WhatsApp)
define('ZAPI_INSTANCE', '3EB5EA8848393161FED3AEC2FCF0DF7D');
define('ZAPI_TOKEN', 'F8435919A26A2D35D60DAEBE');
define('ZAPI_URL', 'https://api.z-api.io/instances/' . ZAPI_INSTANCE . '/token/' . ZAPI_TOKEN);

// ════════════════════════════════════════════════════════════════════════════════════════
// CONEXÃO
// ════════════════════════════════════════════════════════════════════════════════════════

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'error' => 'Erro de conexão']));
}

// ════════════════════════════════════════════════════════════════════════════════════════
// FUNÇÕES AUXILIARES
// ════════════════════════════════════════════════════════════════════════════════════════

/**
 * Calcula distância entre duas coordenadas (Haversine)
 */
function calcularDistancia($lat1, $lon1, $lat2, $lon2) {
    if (!$lat1 || !$lon1 || !$lat2 || !$lon2) return 999;
    $R = 6371; // Raio da Terra em km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

/**
 * Envia mensagem WhatsApp via Z-API
 */
function enviarWhatsApp($telefone, $mensagem) {
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    if (strlen($telefone) == 11 || strlen($telefone) == 10) {
        $telefone = '55' . $telefone;
    }
    
    $ch = curl_init(ZAPI_URL . '/send-text');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['phone' => $telefone, 'message' => $mensagem]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Client-Token: F78499793bdac4071996955c45c8511cdS'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $json = json_decode($response, true);
    return [
        'success' => isset($json['zaapId']) || isset($json['messageId']),
        'http_code' => $httpCode,
        'response' => $json
    ];
}

/**
 * Busca shoppers disponíveis ordenados por distância
 */
function buscarShoppersDisponiveis($pdo, $partner_id, $exclude_ids = []) {
    // Pegar coordenadas do mercado
    $stmt = $pdo->prepare("SELECT latitude, longitude FROM om_market_partners WHERE partner_id = ?");
    $stmt->execute([$partner_id]);
    $mercado = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$mercado || !$mercado['latitude']) {
        // Usar coordenadas padrão de SP se não tiver
        $mercado = ['latitude' => -23.5505, 'longitude' => -46.6333];
    }
    
    // Buscar shoppers online e disponíveis
    $sql = "SELECT 
                shopper_id, name, phone, email, avatar,
                current_lat, current_lng,
                commission_rate, min_earning,
                rating_avg, orders_total,
                push_token
            FROM om_shoppers 
            WHERE is_online = 1 
            AND availability = 'disponivel'
            AND status = 'ativo'";
    
    if (!empty($exclude_ids)) {
        $placeholders = implode(',', array_fill(0, count($exclude_ids), '?'));
        $sql .= " AND shopper_id NOT IN ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($exclude_ids);
    } else {
        $stmt = $pdo->query($sql);
    }
    
    $shoppers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular distância e ordenar
    foreach ($shoppers as &$s) {
        $s['distance_km'] = calcularDistancia(
            $mercado['latitude'], $mercado['longitude'],
            $s['current_lat'], $s['current_lng']
        );
    }
    
    // Ordenar por distância
    usort($shoppers, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
    
    // Filtrar por distância máxima
    $shoppers = array_filter($shoppers, fn($s) => $s['distance_km'] <= MAX_DISTANCE_KM);
    
    return array_values($shoppers);
}

/**
 * Calcula ganho do shopper
 */
function calcularGanhoShopper($order_total, $commission_rate = null, $min_earning = null) {
    $rate = $commission_rate ?? DEFAULT_COMMISSION;
    $min = $min_earning ?? MIN_EARNING;
    
    $earning = $order_total * ($rate / 100);
    return max($earning, $min);
}

/**
 * Notifica um shopper sobre oferta
 */
function notificarShopper($pdo, $shopper, $offer, $order, $mercado_nome, $wave) {
    // Inserir notificação no banco
    $stmt = $pdo->prepare("
        INSERT INTO om_shopper_notifications 
        (shopper_id, offer_id, order_id, distance_km, wave_number, sent_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $shopper['shopper_id'],
        $offer['offer_id'],
        $offer['order_id'],
        $shopper['distance_km'],
        $wave
    ]);
    
    $notification_id = $pdo->lastInsertId();
    $resultado = ['push_sent' => false, 'whatsapp_sent' => false];
    
    // Enviar WhatsApp
    $telefone = $shopper['phone'];
    if ($telefone) {
        $dist = $shopper['distance_km'] < 999 ? ' (' . round($shopper['distance_km'], 1) . 'km)' : '';
        $itens = $order['items_count'] ?? '?';
        
        $msg = "🛒 *NOVA COMPRA DISPONÍVEL!*\n\n";
        $msg .= "📍 *Mercado:* {$mercado_nome}{$dist}\n";
        $msg .= "📦 *Itens:* {$itens} produtos\n";
        $msg .= "💰 *Ganho:* R$ " . number_format($offer['shopper_earning'], 2, ',', '.') . "\n";
        $msg .= "⏰ *Aceite rápido!*\n\n";
        $msg .= "Abra o app OneMundo Shopper para aceitar";
        
        $result = enviarWhatsApp($telefone, $msg);
        if ($result['success']) {
            $pdo->prepare("UPDATE om_shopper_notifications SET whatsapp_sent = 1, whatsapp_sent_at = NOW() WHERE notification_id = ?")
                ->execute([$notification_id]);
            $resultado['whatsapp_sent'] = true;
        }
    }
    
    // TODO: Enviar Push notification
    if ($shopper['push_token']) {
        // Implementar push notification aqui
        $resultado['push_sent'] = true;
    }
    
    return $resultado;
}

/**
 * Cria alerta no sistema
 */
function criarAlerta($pdo, $order_id, $target_type, $target_id, $alert_type, $title, $message, $data = null) {
    $stmt = $pdo->prepare("
        INSERT INTO om_order_alerts 
        (order_id, target_type, target_id, alert_type, title, message, data, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([
        $order_id,
        $target_type,
        $target_id,
        $alert_type,
        $title,
        $message,
        $data ? json_encode($data) : null
    ]);
    return $pdo->lastInsertId();
}

/**
 * Log de ação
 */
function logAction($pdo, $actor_type, $actor_id, $action, $entity_type, $entity_id, $description = null) {
    $stmt = $pdo->prepare("
        INSERT INTO om_action_log 
        (actor_type, actor_id, action, entity_type, entity_id, description, ip_address, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $actor_type,
        $actor_id,
        $action,
        $entity_type,
        $entity_id,
        $description,
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
}

// ════════════════════════════════════════════════════════════════════════════════════════
// PROCESSAR REQUISIÇÃO
// ════════════════════════════════════════════════════════════════════════════════════════

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

switch ($action) {

// ════════════════════════════════════════════════════════════════════════════════════════
// 📤 CREATE_OFFER - Criar oferta e disparar Wave 1
// ════════════════════════════════════════════════════════════════════════════════════════
case 'create_offer':
    $order_id = intval($input['order_id'] ?? 0);
    
    if (!$order_id) {
        echo json_encode(['success' => false, 'error' => 'order_id obrigatório']);
        exit;
    }
    
    // Verificar se já existe oferta pendente
    $stmt = $pdo->prepare("SELECT offer_id FROM om_shopper_offers WHERE order_id = ? AND status = 'pending'");
    $stmt->execute([$order_id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => true, 'message' => 'Oferta já existe para este pedido']);
        exit;
    }
    
    // Buscar dados do pedido (tentar ambas as tabelas)
    $order = null;
    foreach (['om_market_orders', 'om_orders'] as $table) {
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($order) break;
    }
    
    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
        exit;
    }
    
    $partner_id = $order['partner_id'] ?? $order['market_id'] ?? 1;
    $order_total = $order['total'] ?? $order['order_total'] ?? 0;
    
    // Calcular ganho do shopper
    $shopper_earning = calcularGanhoShopper($order_total);
    $platform_fee = $order_total * 0.15; // 15% plataforma
    
    // Contar itens
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_market_order_items WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $items_count = $stmt->fetchColumn() ?: 0;
    
    if ($items_count == 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_order_items WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $items_count = $stmt->fetchColumn() ?: 0;
    }
    
    // Criar oferta
    $expires_at = date('Y-m-d H:i:s', time() + OFFER_TIMEOUT);
    
    $stmt = $pdo->prepare("
        INSERT INTO om_shopper_offers 
        (order_id, partner_id, order_total, shopper_earning, platform_fee, items_count, expires_at, current_wave, wave_started_at, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
    ");
    $stmt->execute([$order_id, $partner_id, $order_total, $shopper_earning, $platform_fee, $items_count, $expires_at]);
    $offer_id = $pdo->lastInsertId();
    
    $offer = [
        'offer_id' => $offer_id,
        'order_id' => $order_id,
        'shopper_earning' => $shopper_earning
    ];
    
    // Buscar nome do mercado
    $stmt = $pdo->prepare("SELECT COALESCE(trade_name, name) as nome FROM om_market_partners WHERE partner_id = ?");
    $stmt->execute([$partner_id]);
    $mercado_nome = $stmt->fetchColumn() ?: 'Mercado OneMundo';
    
    // Buscar shoppers disponíveis
    $shoppers = buscarShoppersDisponiveis($pdo, $partner_id);
    
    // Wave 1: primeiros N shoppers
    $wave1_shoppers = array_slice($shoppers, 0, WAVE1_COUNT);
    $notified = 0;
    $whatsapp_sent = 0;
    
    foreach ($wave1_shoppers as $shopper) {
        $result = notificarShopper($pdo, $shopper, $offer, $order, $mercado_nome, 1);
        $notified++;
        if ($result['whatsapp_sent']) $whatsapp_sent++;
    }
    
    // Criar alertas para mercado e admin
    criarAlerta($pdo, $order_id, 'partner', $partner_id, 'new_order', 
        '🛒 Novo Pedido #' . ($order['order_number'] ?? $order_id),
        "Pedido de R$ " . number_format($order_total, 2, ',', '.') . " - Buscando shopper...",
        ['items_count' => $items_count, 'total' => $order_total]
    );
    
    criarAlerta($pdo, $order_id, 'admin', null, 'new_order',
        '🛒 Novo Pedido',
        "Pedido #{$order_id} - {$items_count} itens - R$ " . number_format($order_total, 2, ',', '.'),
        ['partner_id' => $partner_id, 'shoppers_notified' => $notified]
    );
    
    // Log
    logAction($pdo, 'system', null, 'create_offer', 'offer', $offer_id, 
        "Oferta criada para pedido #{$order_id}, {$notified} shoppers notificados");
    
    echo json_encode([
        'success' => true,
        'offer_id' => $offer_id,
        'wave' => 1,
        'shoppers_notified' => $notified,
        'whatsapp_sent' => $whatsapp_sent,
        'total_available' => count($shoppers),
        'shopper_earning' => $shopper_earning,
        'expires_at' => $expires_at
    ]);
    break;

// ════════════════════════════════════════════════════════════════════════════════════════
// ⏱️ PROCESS_WAVES - Processar waves pendentes (chamar via cron)
// ════════════════════════════════════════════════════════════════════════════════════════
case 'process_waves':
    // Buscar ofertas que precisam avançar wave
    $stmt = $pdo->query("
        SELECT o.*, 
               ord.partner_id as order_partner_id,
               COALESCE(p.trade_name, p.name) as mercado_nome
        FROM om_shopper_offers o
        LEFT JOIN om_market_orders ord ON o.order_id = ord.order_id
        LEFT JOIN om_market_partners p ON COALESCE(o.partner_id, ord.partner_id) = p.partner_id
        WHERE o.status = 'pending'
        AND o.wave_started_at < DATE_SUB(NOW(), INTERVAL " . WAVE_TIMEOUT . " SECOND)
        AND o.current_wave < 3
        AND o.expires_at > NOW()
    ");
    $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $processed = 0;
    $total_notified = 0;
    
    foreach ($offers as $offer) {
        $new_wave = $offer['current_wave'] + 1;
        
        // Atualizar wave
        $pdo->prepare("UPDATE om_shopper_offers SET current_wave = ?, wave_started_at = NOW() WHERE offer_id = ?")
            ->execute([$new_wave, $offer['offer_id']]);
        
        // Buscar shoppers já notificados
        $stmt = $pdo->prepare("SELECT shopper_id FROM om_shopper_notifications WHERE offer_id = ?");
        $stmt->execute([$offer['offer_id']]);
        $already_notified = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Buscar novos shoppers
        $shoppers = buscarShoppersDisponiveis($pdo, $offer['partner_id'], $already_notified);
        
        // Wave 2: +5 shoppers, Wave 3: todos
        $to_notify = ($new_wave >= 3) ? $shoppers : array_slice($shoppers, 0, WAVE2_COUNT);
        
        foreach ($to_notify as $shopper) {
            notificarShopper($pdo, $shopper, $offer, $offer, $offer['mercado_nome'] ?? 'Mercado', $new_wave);
            $total_notified++;
        }
        
        $processed++;
        
        logAction($pdo, 'system', null, 'process_wave', 'offer', $offer['offer_id'],
            "Wave {$new_wave} disparada, " . count($to_notify) . " shoppers notificados");
    }
    
    // Expirar ofertas antigas
    $stmt = $pdo->query("
        UPDATE om_shopper_offers 
        SET status = 'expired'
        WHERE status = 'pending' AND expires_at < NOW()
    ");
    $expired = $stmt->rowCount();
    
    // Criar alerta para ofertas expiradas sem shopper
    if ($expired > 0) {
        criarAlerta($pdo, 0, 'admin', null, 'no_shopper',
            '⚠️ Ofertas Expiradas',
            "{$expired} pedidos não encontraram shopper",
            ['count' => $expired]
        );
    }
    
    echo json_encode([
        'success' => true,
        'offers_processed' => $processed,
        'shoppers_notified' => $total_notified,
        'offers_expired' => $expired
    ]);
    break;

// ════════════════════════════════════════════════════════════════════════════════════════
// ✅ ACCEPT_OFFER - Shopper aceita oferta
// ════════════════════════════════════════════════════════════════════════════════════════
case 'accept_offer':
    $offer_id = intval($input['offer_id'] ?? 0);
    $shopper_id = intval($input['shopper_id'] ?? $_SESSION['shopper_id'] ?? 0);
    
    if (!$offer_id || !$shopper_id) {
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Bloquear oferta para evitar race condition
        $stmt = $pdo->prepare("SELECT * FROM om_shopper_offers WHERE offer_id = ? FOR UPDATE");
        $stmt->execute([$offer_id]);
        $offer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$offer) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Oferta não encontrada']);
            exit;
        }
        
        // Verificar se já foi aceita
        if ($offer['status'] !== 'pending') {
            $pdo->rollBack();
            
            // Buscar quem aceitou
            $stmt = $pdo->prepare("SELECT name FROM om_shoppers WHERE shopper_id = ?");
            $stmt->execute([$offer['accepted_by']]);
            $winner = $stmt->fetchColumn() ?: 'outro shopper';
            
            echo json_encode([
                'success' => false,
                'error' => 'Oferta já foi aceita',
                'accepted_by' => $winner
            ]);
            exit;
        }
        
        // Verificar se não expirou
        if (strtotime($offer['expires_at']) < time()) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Oferta expirada']);
            exit;
        }
        
        // Aceitar oferta
        $stmt = $pdo->prepare("
            UPDATE om_shopper_offers 
            SET status = 'accepted', accepted_by = ?, accepted_at = NOW()
            WHERE offer_id = ?
        ");
        $stmt->execute([$shopper_id, $offer_id]);
        
        // Atualizar notificação do shopper
        $pdo->prepare("
            UPDATE om_shopper_notifications 
            SET status = 'accepted', responded_at = NOW()
            WHERE offer_id = ? AND shopper_id = ?
        ")->execute([$offer_id, $shopper_id]);
        
        // Marcar outras notificações como expiradas
        $pdo->prepare("
            UPDATE om_shopper_notifications 
            SET status = 'expired'
            WHERE offer_id = ? AND shopper_id != ? AND status = 'sent'
        ")->execute([$offer_id, $shopper_id]);
        
        // Atualizar pedido
        $order_table = 'om_market_orders';
        $stmt_check = $pdo->prepare("SELECT 1 FROM om_market_orders WHERE order_id = ?");
        $stmt_check->execute([$offer['order_id']]);
        if (!$stmt_check->fetch()) {
            $order_table = 'om_orders';
        }
        
        $pdo->prepare("
            UPDATE {$order_table}
            SET shopper_id = ?, 
                shopper_accepted_at = NOW(),
                shopper_earning = ?,
                status = 'shopper_aceito'
            WHERE order_id = ?
        ")->execute([$shopper_id, $offer['shopper_earning'], $offer['order_id']]);
        
        // Atualizar shopper
        $pdo->prepare("
            UPDATE om_shoppers 
            SET availability = 'ocupado', 
                orders_today = orders_today + 1,
                orders_total = orders_total + 1
            WHERE shopper_id = ?
        ")->execute([$shopper_id]);
        
        // Buscar dados do shopper
        $stmt = $pdo->prepare("SELECT name, phone, avatar FROM om_shoppers WHERE shopper_id = ?");
        $stmt->execute([$shopper_id]);
        $shopper = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $pdo->commit();
        
        // Criar alertas
        criarAlerta($pdo, $offer['order_id'], 'customer', null, 'order_accepted',
            '✅ Shopper encontrado!',
            "👩‍🦰 {$shopper['name']} vai fazer suas compras!",
            ['shopper_name' => $shopper['name'], 'shopper_avatar' => $shopper['avatar']]
        );
        
        criarAlerta($pdo, $offer['order_id'], 'partner', $offer['partner_id'], 'order_accepted',
            '✅ Pedido Aceito',
            "Shopper {$shopper['name']} aceitou o pedido #{$offer['order_id']}",
            ['shopper_id' => $shopper_id]
        );
        
        // Notificar outros shoppers que perderam
        $stmt = $pdo->prepare("
            SELECT s.phone, s.name 
            FROM om_shopper_notifications n
            JOIN om_shoppers s ON n.shopper_id = s.shopper_id
            WHERE n.offer_id = ? AND n.shopper_id != ? AND n.status = 'expired'
        ");
        $stmt->execute([$offer_id, $shopper_id]);
        $losers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($losers as $loser) {
            if ($loser['phone']) {
                enviarWhatsApp($loser['phone'], 
                    "😕 *A oferta de compra já foi aceita por outro shopper.*\n\nFique de olho, novas ofertas chegam a todo momento! 💪"
                );
            }
        }
        
        logAction($pdo, 'shopper', $shopper_id, 'accept_offer', 'offer', $offer_id,
            "Shopper {$shopper['name']} aceitou pedido #{$offer['order_id']}");
        
        echo json_encode([
            'success' => true,
            'message' => 'Oferta aceita com sucesso!',
            'order_id' => $offer['order_id'],
            'earning' => $offer['shopper_earning'],
            'items_count' => $offer['items_count']
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Erro ao aceitar: ' . $e->getMessage()]);
    }
    break;

// ════════════════════════════════════════════════════════════════════════════════════════
// ❌ REJECT_OFFER - Shopper recusa oferta
// ════════════════════════════════════════════════════════════════════════════════════════
case 'reject_offer':
    $offer_id = intval($input['offer_id'] ?? 0);
    $shopper_id = intval($input['shopper_id'] ?? $_SESSION['shopper_id'] ?? 0);
    $reason = $input['reason'] ?? null;
    
    if (!$offer_id || !$shopper_id) {
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
        exit;
    }
    
    $pdo->prepare("
        UPDATE om_shopper_notifications 
        SET status = 'rejected', responded_at = NOW()
        WHERE offer_id = ? AND shopper_id = ?
    ")->execute([$offer_id, $shopper_id]);
    
    logAction($pdo, 'shopper', $shopper_id, 'reject_offer', 'offer', $offer_id, $reason);
    
    echo json_encode(['success' => true, 'message' => 'Oferta recusada']);
    break;

// ════════════════════════════════════════════════════════════════════════════════════════
// 📋 GET_OFFERS - Lista ofertas para um shopper
// ════════════════════════════════════════════════════════════════════════════════════════
case 'get_offers':
    $shopper_id = intval($input['shopper_id'] ?? $_SESSION['shopper_id'] ?? $_GET['shopper_id'] ?? 0);
    
    if (!$shopper_id) {
        echo json_encode(['success' => false, 'error' => 'shopper_id obrigatório']);
        exit;
    }
    
    // Buscar ofertas pendentes para este shopper
    $stmt = $pdo->prepare("
        SELECT 
            o.*,
            n.notification_id,
            n.distance_km,
            n.sent_at,
            n.status as notification_status,
            COALESCE(p.trade_name, p.name) as market_name,
            p.latitude as market_lat,
            p.longitude as market_lng,
            TIMESTAMPDIFF(SECOND, NOW(), o.expires_at) as seconds_remaining
        FROM om_shopper_notifications n
        JOIN om_shopper_offers o ON n.offer_id = o.offer_id
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE n.shopper_id = ?
        AND n.status = 'sent'
        AND o.status = 'pending'
        AND o.expires_at > NOW()
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$shopper_id]);
    $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Marcar como visualizadas
    $offer_ids = array_column($offers, 'offer_id');
    if (!empty($offer_ids)) {
        $placeholders = implode(',', array_fill(0, count($offer_ids), '?'));
        $pdo->prepare("
            UPDATE om_shopper_notifications 
            SET viewed_at = COALESCE(viewed_at, NOW()), status = 'viewed'
            WHERE shopper_id = ? AND offer_id IN ($placeholders) AND viewed_at IS NULL
        ")->execute(array_merge([$shopper_id], $offer_ids));
    }
    
    echo json_encode([
        'success' => true,
        'offers' => $offers,
        'count' => count($offers)
    ]);
    break;

// ════════════════════════════════════════════════════════════════════════════════════════
// 📍 UPDATE_LOCATION - Atualiza GPS do shopper
// ════════════════════════════════════════════════════════════════════════════════════════
case 'update_location':
    $shopper_id = intval($input['shopper_id'] ?? $_SESSION['shopper_id'] ?? 0);
    $lat = floatval($input['lat'] ?? $input['latitude'] ?? 0);
    $lng = floatval($input['lng'] ?? $input['longitude'] ?? 0);
    
    if (!$shopper_id || !$lat || !$lng) {
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
        exit;
    }
    
    $pdo->prepare("
        UPDATE om_shoppers 
        SET current_lat = ?, current_lng = ?, last_location_update = NOW()
        WHERE shopper_id = ?
    ")->execute([$lat, $lng, $shopper_id]);
    
    echo json_encode(['success' => true]);
    break;

// ════════════════════════════════════════════════════════════════════════════════════════
// 🔘 TOGGLE_ONLINE - Shopper fica online/offline
// ════════════════════════════════════════════════════════════════════════════════════════
case 'toggle_online':
    $shopper_id = intval($input['shopper_id'] ?? $_SESSION['shopper_id'] ?? 0);
    $online = isset($input['online']) ? (bool)$input['online'] : null;
    
    if (!$shopper_id) {
        echo json_encode(['success' => false, 'error' => 'shopper_id obrigatório']);
        exit;
    }
    
    // Se não especificou, alternar
    if ($online === null) {
        $stmt = $pdo->prepare("SELECT is_online FROM om_shoppers WHERE shopper_id = ?");
        $stmt->execute([$shopper_id]);
        $current = $stmt->fetchColumn();
        $online = !$current;
    }
    
    $availability = $online ? 'disponivel' : 'offline';
    
    $pdo->prepare("
        UPDATE om_shoppers 
        SET is_online = ?, availability = ?, last_activity = NOW()
        WHERE shopper_id = ?
    ")->execute([$online ? 1 : 0, $availability, $shopper_id]);
    
    logAction($pdo, 'shopper', $shopper_id, $online ? 'go_online' : 'go_offline', 'shopper', $shopper_id, null);
    
    echo json_encode([
        'success' => true,
        'is_online' => $online,
        'availability' => $availability
    ]);
    break;

// ════════════════════════════════════════════════════════════════════════════════════════
// 📊 GET_STATS - Estatísticas do shopper
// ════════════════════════════════════════════════════════════════════════════════════════
case 'get_stats':
    $shopper_id = intval($input['shopper_id'] ?? $_SESSION['shopper_id'] ?? $_GET['shopper_id'] ?? 0);
    
    if (!$shopper_id) {
        echo json_encode(['success' => false, 'error' => 'shopper_id obrigatório']);
        exit;
    }
    
    // Dados do shopper
    $stmt = $pdo->prepare("SELECT * FROM om_shoppers WHERE shopper_id = ?");
    $stmt->execute([$shopper_id]);
    $shopper = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Ganhos
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN DATE(created_at) = CURRENT_DATE THEN amount ELSE 0 END) as today,
            SUM(CASE WHEN YEARWEEK(created_at) = YEARWEEK(NOW()) THEN amount ELSE 0 END) as week,
            SUM(CASE WHEN MONTH(created_at) = MONTH(NOW()) THEN amount ELSE 0 END) as month
        FROM om_shopper_earnings 
        WHERE shopper_id = ? AND status != 'cancelado'
    ");
    $stmt->execute([$shopper_id]);
    $earnings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'shopper' => $shopper,
        'earnings' => [
            'today' => floatval($earnings['today'] ?? 0),
            'week' => floatval($earnings['week'] ?? 0),
            'month' => floatval($earnings['month'] ?? 0),
            'balance' => floatval($shopper['balance'] ?? 0)
        ],
        'orders' => [
            'today' => intval($shopper['orders_today'] ?? 0),
            'week' => intval($shopper['orders_week'] ?? 0),
            'month' => intval($shopper['orders_month'] ?? 0),
            'total' => intval($shopper['orders_total'] ?? 0)
        ],
        'rating' => floatval($shopper['rating_avg'] ?? 5)
    ]);
    break;

// ════════════════════════════════════════════════════════════════════════════════════════
// DEFAULT
// ════════════════════════════════════════════════════════════════════════════════════════
default:
    echo json_encode([
        'success' => false,
        'error' => 'Ação inválida',
        'available_actions' => [
            'create_offer',
            'process_waves',
            'accept_offer',
            'reject_offer',
            'get_offers',
            'update_location',
            'toggle_online',
            'get_stats'
        ]
    ]);
}
