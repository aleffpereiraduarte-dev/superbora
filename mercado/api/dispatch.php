<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * 🧠 SISTEMA INTELIGENTE DE DISTRIBUIÇÃO - ONEMUNDO MERCADO
 * ══════════════════════════════════════════════════════════════════════════════
 * 
 * ALGORITMO DE PRIORIDADE:
 * 1. 📍 DISTÂNCIA - Quem está mais perto do mercado/cliente
 * 2. ⚡ VELOCIDADE - Histórico de tempo de aceite
 * 3. ⭐ RATING - Avaliação média
 * 4. 📊 TAXA ACEITE - % de ofertas aceitas
 * 5. 🎯 DISPONIBILIDADE - Online e não ocupado
 * 
 * REGRAS:
 * - Mercado só aparece se cliente está em até 45min (raio configurável)
 * - Ofertas expiram em 60 segundos
 * - Se ninguém aceitar, passa pro próximo da fila
 * - Máximo 3 tentativas por oferta
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$pdo = getPDO();

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST ?? [];
$action = $input['action'] ?? $_GET['action'] ?? '';

// ══════════════════════════════════════════════════════════════════════════════
// CONFIGURAÇÕES
// ══════════════════════════════════════════════════════════════════════════════

$CONFIG = [
    'raio_mercado_km' => 15,           // Raio máximo para cliente ver o mercado
    'tempo_max_minutos' => 45,         // Tempo máximo de entrega
    'oferta_expira_segundos' => 60,    // Tempo para aceitar oferta
    'max_tentativas_oferta' => 3,      // Máximo de pessoas para ofertar
    'velocidade_media_kmh' => 20,      // Velocidade média em cidade
    
    // Pesos do algoritmo de ranking
    'peso_distancia' => 40,            // 40% - Mais perto = melhor
    'peso_velocidade' => 25,           // 25% - Aceita rápido = melhor
    'peso_rating' => 20,               // 20% - Melhor avaliação = melhor
    'peso_taxa_aceite' => 15,          // 15% - Aceita mais = melhor
];

// ══════════════════════════════════════════════════════════════════════════════
// FUNÇÕES AUXILIARES
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Calcula distância entre 2 pontos (Haversine)
 */
function calcularDistanciaKm($lat1, $lon1, $lat2, $lon2) {
    if (!$lat1 || !$lon1 || !$lat2 || !$lon2) return 999;
    
    $R = 6371; // Raio da Terra em km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return round($R * $c, 2);
}

/**
 * Calcula tempo estimado em minutos
 */
function calcularTempoMinutos($distanciaKm, $velocidadeKmh = 20) {
    if ($distanciaKm <= 0) return 5;
    return ceil(($distanciaKm / $velocidadeKmh) * 60);
}

/**
 * Calcula score de um worker (shopper ou delivery)
 */
function calcularScore($worker, $distanciaKm, $config) {
    // Score de distância (0-100, mais perto = maior)
    $maxDist = $config['raio_mercado_km'];
    $scoreDistancia = max(0, 100 - (($distanciaKm / $maxDist) * 100));
    
    // Score de velocidade de aceite (baseado no tempo médio)
    $tempoMedioAceite = $worker['avg_accept_time'] ?? 30; // segundos
    $scoreVelocidade = max(0, 100 - ($tempoMedioAceite / 60 * 100));
    
    // Score de rating
    $rating = $worker['rating'] ?? 5;
    $scoreRating = ($rating / 5) * 100;
    
    // Score de taxa de aceite
    $taxaAceite = $worker['accept_rate'] ?? 80;
    $scoreTaxaAceite = $taxaAceite;
    
    // Score final ponderado
    $scoreFinal = (
        ($scoreDistancia * $config['peso_distancia']) +
        ($scoreVelocidade * $config['peso_velocidade']) +
        ($scoreRating * $config['peso_rating']) +
        ($scoreTaxaAceite * $config['peso_taxa_aceite'])
    ) / 100;
    
    return [
        'score_final' => round($scoreFinal, 2),
        'score_distancia' => round($scoreDistancia, 2),
        'score_velocidade' => round($scoreVelocidade, 2),
        'score_rating' => round($scoreRating, 2),
        'score_taxa_aceite' => round($scoreTaxaAceite, 2),
        'distancia_km' => $distanciaKm,
        'tempo_estimado' => calcularTempoMinutos($distanciaKm)
    ];
}

/**
 * Garantir colunas necessárias existem
 */
function garantirColunas($pdo) {
    $alteracoes = [
        // Shoppers
        "ALTER TABLE om_market_shoppers ADD COLUMN current_lat DECIMAL(10,8) DEFAULT NULL",
        "ALTER TABLE om_market_shoppers ADD COLUMN current_lng DECIMAL(11,8) DEFAULT NULL",
        "ALTER TABLE om_market_shoppers ADD COLUMN last_location_at DATETIME DEFAULT NULL",
        "ALTER TABLE om_market_shoppers ADD COLUMN rating DECIMAL(3,2) DEFAULT 5.00",
        "ALTER TABLE om_market_shoppers ADD COLUMN total_ratings INT DEFAULT 0",
        "ALTER TABLE om_market_shoppers ADD COLUMN accept_rate DECIMAL(5,2) DEFAULT 100.00",
        "ALTER TABLE om_market_shoppers ADD COLUMN avg_accept_time INT DEFAULT 30",
        "ALTER TABLE om_market_shoppers ADD COLUMN total_offers INT DEFAULT 0",
        "ALTER TABLE om_market_shoppers ADD COLUMN total_accepts INT DEFAULT 0",
        
        // Delivery
        "ALTER TABLE om_market_delivery ADD COLUMN current_lat DECIMAL(10,8) DEFAULT NULL",
        "ALTER TABLE om_market_delivery ADD COLUMN current_lng DECIMAL(11,8) DEFAULT NULL",
        "ALTER TABLE om_market_delivery ADD COLUMN last_location_at DATETIME DEFAULT NULL",
        "ALTER TABLE om_market_delivery ADD COLUMN rating DECIMAL(3,2) DEFAULT 5.00",
        "ALTER TABLE om_market_delivery ADD COLUMN total_ratings INT DEFAULT 0",
        "ALTER TABLE om_market_delivery ADD COLUMN accept_rate DECIMAL(5,2) DEFAULT 100.00",
        "ALTER TABLE om_market_delivery ADD COLUMN avg_accept_time INT DEFAULT 30",
        "ALTER TABLE om_market_delivery ADD COLUMN total_offers INT DEFAULT 0",
        "ALTER TABLE om_market_delivery ADD COLUMN total_accepts INT DEFAULT 0",
    ];
    
    foreach ($alteracoes as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) {}
    }
    
    // Criar tabela de ofertas
    $pdo->exec("CREATE TABLE IF NOT EXISTS om_dispatch_offers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        worker_type ENUM('shopper', 'delivery') NOT NULL,
        worker_id INT NOT NULL,
        status ENUM('pending', 'accepted', 'rejected', 'expired') DEFAULT 'pending',
        score DECIMAL(5,2) DEFAULT 0,
        distancia_km DECIMAL(5,2) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        responded_at DATETIME DEFAULT NULL,
        response_time_seconds INT DEFAULT NULL,
        INDEX idx_order (order_id),
        INDEX idx_worker (worker_type, worker_id),
        INDEX idx_status (status)
    )");
}

// Garantir estrutura
garantirColunas($pdo);

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: Verificar se mercado está no raio do cliente
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'check_mercado_raio') {
    $clienteLat = floatval($input['lat'] ?? $input['latitude'] ?? 0);
    $clienteLng = floatval($input['lng'] ?? $input['longitude'] ?? 0);
    $clienteCep = $input['cep'] ?? '';
    
    if (!$clienteLat || !$clienteLng) {
        // Tentar geocodificar CEP (simplificado)
        echo json_encode(['success' => false, 'error' => 'Localização necessária']);
        exit;
    }
    
    // Buscar mercados próximos
    $mercados = $pdo->query("
        SELECT partner_id, name, 
               COALESCE(lat, latitude) as lat,
               COALESCE(lng, longitude) as lng,
               COALESCE(delivery_radius, delivery_radius_km, 15) as raio_km,
               delivery_fee, min_order, delivery_time_min, delivery_time_max
        FROM om_market_partners 
        WHERE status = '1' OR status = 'active'
    ")->fetchAll();
    
    $mercadosProximos = [];
    
    foreach ($mercados as $m) {
        $distancia = calcularDistanciaKm($clienteLat, $clienteLng, $m['lat'], $m['lng']);
        $tempoEstimado = calcularTempoMinutos($distancia);
        
        // Verificar se está no raio (por distância ou tempo)
        $raioKm = $m['raio_km'] ?: $CONFIG['raio_mercado_km'];
        $dentroRaio = ($distancia <= $raioKm) && ($tempoEstimado <= $CONFIG['tempo_max_minutos']);
        
        if ($dentroRaio) {
            $mercadosProximos[] = [
                'partner_id' => $m['partner_id'],
                'name' => $m['name'],
                'distancia_km' => $distancia,
                'tempo_estimado' => $tempoEstimado,
                'delivery_fee' => $m['delivery_fee'],
                'min_order' => $m['min_order'],
                'delivery_time' => $m['delivery_time_min'] . '-' . $m['delivery_time_max'] . ' min'
            ];
        }
    }
    
    // Ordenar por distância
    usort($mercadosProximos, fn($a, $b) => $a['distancia_km'] <=> $b['distancia_km']);
    
    echo json_encode([
        'success' => true,
        'total' => count($mercadosProximos),
        'mercados' => $mercadosProximos,
        'cliente_location' => ['lat' => $clienteLat, 'lng' => $clienteLng]
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: Atualizar localização do worker (shopper/delivery)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'update_location') {
    $tipo = $input['tipo'] ?? $input['type'] ?? 'shopper';
    $workerId = intval($input['worker_id'] ?? $input['shopper_id'] ?? $input['delivery_id'] ?? 0);
    $lat = floatval($input['lat'] ?? $input['latitude'] ?? 0);
    $lng = floatval($input['lng'] ?? $input['longitude'] ?? 0);
    
    if (!$workerId || !$lat || !$lng) {
        echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
        exit;
    }
    
    $tabela = $tipo === 'delivery' ? 'om_market_delivery' : 'om_market_shoppers';
    $idCol = $tipo === 'delivery' ? 'delivery_id' : 'shopper_id';
    
    $stmt = $pdo->prepare("UPDATE $tabela SET current_lat = ?, current_lng = ?, last_location_at = NOW() WHERE $idCol = ?");
    $stmt->execute([$lat, $lng, $workerId]);
    
    echo json_encode(['success' => true]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: Disparar oferta para shoppers (sistema inteligente)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'dispatch_shopper') {
    $orderId = intval($input['order_id'] ?? 0);
    
    if (!$orderId) {
        echo json_encode(['success' => false, 'error' => 'order_id obrigatório']);
        exit;
    }
    
    // Buscar pedido
    $pedido = $pdo->prepare("SELECT * FROM om_orders WHERE order_id = ?");
    $pedido->execute([$orderId]);
    $pedido = $pedido->fetch();
    
    if (!$pedido) {
        echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
        exit;
    }
    
    // Buscar mercado
    $mercado = $pdo->prepare("SELECT * FROM om_market_partners WHERE partner_id = ?");
    $mercado->execute([$pedido['partner_id']]);
    $mercado = $mercado->fetch();
    
    $mercadoLat = $mercado['lat'] ?? $mercado['latitude'] ?? -23.55;
    $mercadoLng = $mercado['lng'] ?? $mercado['longitude'] ?? -46.63;
    
    // Buscar shoppers disponíveis
    $shoppers = $pdo->query("
        SELECT s.*, 
               COALESCE(s.current_lat, s.lat, -23.55) as lat,
               COALESCE(s.current_lng, s.lng, -46.63) as lng
        FROM om_market_shoppers s
        WHERE (s.is_online = 1 OR s.status IN ('online', 'disponivel'))
        AND (s.is_busy = 0 OR s.is_busy IS NULL)
        AND s.current_order_id IS NULL
    ")->fetchAll();
    
    if (empty($shoppers)) {
        echo json_encode(['success' => false, 'error' => 'Nenhum shopper disponível']);
        exit;
    }
    
    // Calcular score de cada shopper
    $ranking = [];
    foreach ($shoppers as $s) {
        $distancia = calcularDistanciaKm($s['lat'], $s['lng'], $mercadoLat, $mercadoLng);
        
        // Só considerar se estiver em raio aceitável (30km)
        if ($distancia > 30) continue;
        
        $scores = calcularScore($s, $distancia, $CONFIG);
        $ranking[] = [
            'shopper_id' => $s['shopper_id'],
            'name' => $s['name'],
            'distancia_km' => $distancia,
            'tempo_estimado' => $scores['tempo_estimado'],
            'score_final' => $scores['score_final'],
            'scores' => $scores
        ];
    }
    
    // Ordenar por score (maior = melhor)
    usort($ranking, fn($a, $b) => $b['score_final'] <=> $a['score_final']);
    
    // Pegar os top 3 para ofertar
    $topShoppers = array_slice($ranking, 0, $CONFIG['max_tentativas_oferta']);
    
    // Criar ofertas
    $ofertas = [];
    foreach ($topShoppers as $idx => $s) {
        $pdo->prepare("INSERT INTO om_dispatch_offers (order_id, worker_type, worker_id, score, distancia_km) VALUES (?, 'shopper', ?, ?, ?)")
            ->execute([$orderId, $s['shopper_id'], $s['score_final'], $s['distancia_km']]);
        
        $ofertas[] = [
            'offer_id' => $pdo->lastInsertId(),
            'shopper_id' => $s['shopper_id'],
            'name' => $s['name'],
            'prioridade' => $idx + 1,
            'distancia_km' => $s['distancia_km'],
            'tempo_estimado' => $s['tempo_estimado'],
            'score' => $s['score_final']
        ];
        
        // Incrementar total de ofertas do shopper
        $pdo->exec("UPDATE om_market_shoppers SET total_offers = COALESCE(total_offers, 0) + 1 WHERE shopper_id = {$s['shopper_id']}");
    }
    
    // Atualizar pedido
    $pdo->exec("UPDATE om_orders SET status = 'aguardando_shopper' WHERE order_id = $orderId");
    
    echo json_encode([
        'success' => true,
        'total_dispatchs' => count($ofertas),
        'ofertas' => $ofertas,
        'expira_em' => $CONFIG['oferta_expira_segundos'] . ' segundos'
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: Shopper aceita oferta
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'accept_offer') {
    $offerId = intval($input['offer_id'] ?? 0);
    $shopperId = intval($input['shopper_id'] ?? 0);
    
    if (!$offerId || !$shopperId) {
        echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
        exit;
    }
    
    // Buscar oferta
    $oferta = $pdo->prepare("SELECT * FROM om_dispatch_offers WHERE id = ? AND worker_id = ? AND status = 'pending'");
    $oferta->execute([$offerId, $shopperId]);
    $oferta = $oferta->fetch();
    
    if (!$oferta) {
        echo json_encode(['success' => false, 'error' => 'Oferta não disponível ou já expirada']);
        exit;
    }
    
    // Verificar se pedido ainda disponível
    $pedido = $pdo->prepare("SELECT * FROM om_orders WHERE order_id = ? AND shopper_id IS NULL");
    $pedido->execute([$oferta['order_id']]);
    $pedido = $pedido->fetch();
    
    if (!$pedido) {
        $pdo->exec("UPDATE om_dispatch_offers SET status = 'expired' WHERE id = $offerId");
        echo json_encode(['success' => false, 'error' => 'Pedido já foi aceito por outro shopper']);
        exit;
    }
    
    // Calcular tempo de resposta
    $tempoResposta = time() - strtotime($oferta['created_at']);
    
    // Aceitar oferta
    $pdo->prepare("UPDATE om_dispatch_offers SET status = 'accepted', responded_at = NOW(), response_time_seconds = ? WHERE id = ?")
        ->execute([$tempoResposta, $offerId]);
    
    // Marcar outras ofertas como expiradas
    $pdo->exec("UPDATE om_dispatch_offers SET status = 'expired' WHERE order_id = {$oferta['order_id']} AND id != $offerId");
    
    // Calcular ganho (5% do total, mínimo R$ 5)
    $ganho = max(5, $pedido['total'] * 0.05);
    
    // Atualizar pedido
    $pdo->prepare("UPDATE om_orders SET status = 'shopper_aceito', shopper_id = ?, shopper_earning = ?, shopper_accepted_at = NOW() WHERE order_id = ?")
        ->execute([$shopperId, $ganho, $oferta['order_id']]);
    
    // Atualizar shopper
    $pdo->exec("UPDATE om_market_shoppers SET 
                is_busy = 1, 
                current_order_id = {$oferta['order_id']},
                total_accepts = COALESCE(total_accepts, 0) + 1,
                accept_rate = (COALESCE(total_accepts, 0) + 1) / COALESCE(total_offers, 1) * 100,
                avg_accept_time = (COALESCE(avg_accept_time, 30) + $tempoResposta) / 2
                WHERE shopper_id = $shopperId");
    
    echo json_encode([
        'success' => true,
        'order_id' => $oferta['order_id'],
        'ganho_estimado' => $ganho,
        'tempo_resposta' => $tempoResposta . ' segundos'
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: Disparar oferta para delivery
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'dispatch_delivery') {
    $orderId = intval($input['order_id'] ?? 0);
    
    if (!$orderId) {
        echo json_encode(['success' => false, 'error' => 'order_id obrigatório']);
        exit;
    }
    
    // Buscar pedido
    $pedido = $pdo->prepare("SELECT o.*, p.lat as mercado_lat, p.lng as mercado_lng, p.latitude, p.longitude
                             FROM om_orders o 
                             LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
                             WHERE o.order_id = ?");
    $pedido->execute([$orderId]);
    $pedido = $pedido->fetch();
    
    if (!$pedido) {
        echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
        exit;
    }
    
    $mercadoLat = $pedido['mercado_lat'] ?? $pedido['latitude'] ?? -23.55;
    $mercadoLng = $pedido['mercado_lng'] ?? $pedido['longitude'] ?? -46.63;
    
    // Buscar deliverys disponíveis
    $deliverys = $pdo->query("
        SELECT d.*,
               COALESCE(d.current_lat, -23.55) as lat,
               COALESCE(d.current_lng, -46.63) as lng
        FROM om_market_delivery d
        WHERE (d.is_online = 1 OR d.status IN ('online', 'disponivel'))
        AND d.active_order_id IS NULL
    ")->fetchAll();
    
    if (empty($deliverys)) {
        echo json_encode(['success' => false, 'error' => 'Nenhum delivery disponível']);
        exit;
    }
    
    // Calcular score de cada delivery
    $ranking = [];
    foreach ($deliverys as $d) {
        $distancia = calcularDistanciaKm($d['lat'], $d['lng'], $mercadoLat, $mercadoLng);
        
        if ($distancia > 30) continue;
        
        $scores = calcularScore($d, $distancia, $CONFIG);
        $ranking[] = [
            'delivery_id' => $d['delivery_id'],
            'name' => $d['name'],
            'vehicle' => $d['vehicle'] ?? 'moto',
            'distancia_km' => $distancia,
            'tempo_estimado' => $scores['tempo_estimado'],
            'score_final' => $scores['score_final'],
            'scores' => $scores
        ];
    }
    
    usort($ranking, fn($a, $b) => $b['score_final'] <=> $a['score_final']);
    
    $topDeliverys = array_slice($ranking, 0, $CONFIG['max_tentativas_oferta']);
    
    $ofertas = [];
    foreach ($topDeliverys as $idx => $d) {
        $pdo->prepare("INSERT INTO om_dispatch_offers (order_id, worker_type, worker_id, score, distancia_km) VALUES (?, 'delivery', ?, ?, ?)")
            ->execute([$orderId, $d['delivery_id'], $d['score_final'], $d['distancia_km']]);
        
        $ofertas[] = [
            'offer_id' => $pdo->lastInsertId(),
            'delivery_id' => $d['delivery_id'],
            'name' => $d['name'],
            'vehicle' => $d['vehicle'],
            'prioridade' => $idx + 1,
            'distancia_km' => $d['distancia_km'],
            'tempo_estimado' => $d['tempo_estimado'],
            'score' => $d['score_final']
        ];
        
        $pdo->exec("UPDATE om_market_delivery SET total_offers = COALESCE(total_offers, 0) + 1 WHERE delivery_id = {$d['delivery_id']}");
    }
    
    $pdo->exec("UPDATE om_orders SET status = 'aguardando_delivery' WHERE order_id = $orderId");
    
    echo json_encode([
        'success' => true,
        'total_dispatchs' => count($ofertas),
        'ofertas' => $ofertas,
        'expira_em' => $CONFIG['oferta_expira_segundos'] . ' segundos'
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: Delivery aceita oferta
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'accept_delivery_offer') {
    $offerId = intval($input['offer_id'] ?? 0);
    $deliveryId = intval($input['delivery_id'] ?? 0);
    
    if (!$offerId || !$deliveryId) {
        echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
        exit;
    }
    
    $oferta = $pdo->prepare("SELECT * FROM om_dispatch_offers WHERE id = ? AND worker_id = ? AND worker_type = 'delivery' AND status = 'pending'");
    $oferta->execute([$offerId, $deliveryId]);
    $oferta = $oferta->fetch();
    
    if (!$oferta) {
        echo json_encode(['success' => false, 'error' => 'Oferta não disponível']);
        exit;
    }
    
    $pedido = $pdo->prepare("SELECT * FROM om_orders WHERE order_id = ? AND (delivery_id IS NULL OR delivery_id = 0)");
    $pedido->execute([$oferta['order_id']]);
    $pedido = $pedido->fetch();
    
    if (!$pedido) {
        $pdo->exec("UPDATE om_dispatch_offers SET status = 'expired' WHERE id = $offerId");
        echo json_encode(['success' => false, 'error' => 'Pedido já foi aceito por outro delivery']);
        exit;
    }
    
    $tempoResposta = time() - strtotime($oferta['created_at']);
    
    $pdo->prepare("UPDATE om_dispatch_offers SET status = 'accepted', responded_at = NOW(), response_time_seconds = ? WHERE id = ?")
        ->execute([$tempoResposta, $offerId]);
    
    $pdo->exec("UPDATE om_dispatch_offers SET status = 'expired' WHERE order_id = {$oferta['order_id']} AND worker_type = 'delivery' AND id != $offerId");
    
    $ganho = 5.00; // Fixo por enquanto
    
    $pdo->prepare("UPDATE om_orders SET status = 'delivery_aceito', delivery_id = ?, delivery_earning = ?, delivery_accepted_at = NOW() WHERE order_id = ?")
        ->execute([$deliveryId, $ganho, $oferta['order_id']]);
    
    $pdo->exec("UPDATE om_market_delivery SET 
                active_order_id = {$oferta['order_id']},
                total_accepts = COALESCE(total_accepts, 0) + 1,
                accept_rate = (COALESCE(total_accepts, 0) + 1) / COALESCE(total_offers, 1) * 100,
                avg_accept_time = (COALESCE(avg_accept_time, 30) + $tempoResposta) / 2
                WHERE delivery_id = $deliveryId");
    
    echo json_encode([
        'success' => true,
        'order_id' => $oferta['order_id'],
        'ganho_estimado' => $ganho,
        'tempo_resposta' => $tempoResposta . ' segundos'
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: Verificar ofertas pendentes (para expirar)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'check_expired_offers') {
    $expiraSegundos = $CONFIG['oferta_expira_segundos'];
    
    // Expirar ofertas antigas
    $pdo->exec("UPDATE om_dispatch_offers SET status = 'expired' WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL $expiraSegundos SECOND)");
    
    // Contar expiradas
    $expiradas = $pdo->query("SELECT COUNT(*) as c FROM om_dispatch_offers WHERE status = 'expired' AND responded_at IS NULL")->fetch();
    
    echo json_encode(['success' => true, 'expired' => $expiradas['c']]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: Buscar ofertas pendentes para um worker
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'get_pending_offers') {
    $tipo = $input['tipo'] ?? 'shopper';
    $workerId = intval($input['worker_id'] ?? 0);
    
    if (!$workerId) {
        echo json_encode(['success' => false, 'error' => 'worker_id obrigatório']);
        exit;
    }
    
    $ofertas = $pdo->prepare("
        SELECT o.*, 
               ord.order_number, ord.total, ord.delivery_address,
               p.name as mercado_name
        FROM om_dispatch_offers o
        JOIN om_orders ord ON o.order_id = ord.order_id
        LEFT JOIN om_market_partners p ON ord.partner_id = p.partner_id
        WHERE o.worker_type = ? AND o.worker_id = ? AND o.status = 'pending'
        AND o.created_at > DATE_SUB(NOW(), INTERVAL {$CONFIG['oferta_expira_segundos']} SECOND)
        ORDER BY o.score DESC
    ");
    $ofertas->execute([$tipo, $workerId]);
    $ofertas = $ofertas->fetchAll();
    
    echo json_encode([
        'success' => true,
        'ofertas' => $ofertas
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: Ranking de workers
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'ranking') {
    $tipo = $input['tipo'] ?? $_GET['tipo'] ?? 'shopper';
    
    if ($tipo === 'delivery') {
        $ranking = $pdo->query("
            SELECT delivery_id as id, name, rating, accept_rate, total_accepts, avg_accept_time
            FROM om_market_delivery
            ORDER BY rating DESC, accept_rate DESC
            LIMIT 20
        ")->fetchAll();
    } else {
        $ranking = $pdo->query("
            SELECT shopper_id as id, name, rating, accept_rate, total_accepts, avg_accept_time
            FROM om_market_shoppers
            ORDER BY rating DESC, accept_rate DESC
            LIMIT 20
        ")->fetchAll();
    }
    
    echo json_encode(['success' => true, 'ranking' => $ranking]);
    exit;
}

// Default
echo json_encode([
    'success' => false,
    'error' => 'Ação não reconhecida',
    'actions' => [
        'check_mercado_raio',
        'update_location', 
        'dispatch_shopper',
        'accept_offer',
        'dispatch_delivery',
        'accept_delivery_offer',
        'check_expired_offers',
        'get_pending_offers',
        'ranking'
    ]
]);
