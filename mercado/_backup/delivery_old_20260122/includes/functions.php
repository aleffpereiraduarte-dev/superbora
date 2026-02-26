<?php
/**
 * Helper Functions - OneMundo Delivery
 * Funções utilitárias para o sistema de delivery
 */

// Verificar se está logado
function requireLogin() {
    if (!isset($_SESSION['delivery_id']) || empty($_SESSION['delivery_id'])) {
        header('Location: login.php');
        exit;
    }
}

// Obter dados do entregador logado
function getDelivery() {
    if (!isset($_SESSION['delivery_id'])) {
        return null;
    }
    
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM om_market_deliveries WHERE delivery_id = ?");
    $stmt->execute([$_SESSION['delivery_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Obter pedidos pendentes do entregador
function getPendingOrders($delivery_id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT o.*, 
               (SELECT COUNT(*) FROM om_market_order_products WHERE order_id = o.order_id) as total_items,
               c.firstname, c.lastname, c.telephone as customer_phone
        FROM om_market_orders o
        LEFT JOIN oc_customer c ON o.customer_id = c.customer_id
        WHERE o.delivery_id = ? AND o.status IN ('ready_delivery', 'delivering')
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$delivery_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Obter ofertas disponíveis para o entregador
function getAvailableOffers($delivery_id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT do.*, o.total, o.delivery_address,
               (SELECT COUNT(*) FROM om_market_order_products WHERE order_id = o.order_id) as total_items
        FROM om_market_delivery_offers do
        JOIN om_market_orders o ON do.order_id = o.order_id
        WHERE do.delivery_id = ? AND do.status = 'pending' AND do.expires_at > NOW()
        ORDER BY do.created_at DESC
    ");
    $stmt->execute([$delivery_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Atualizar localização do entregador
function updateDeliveryLocation($delivery_id, $lat, $lng) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        UPDATE om_market_deliveries 
        SET latitude = ?, longitude = ?, location_updated_at = NOW()
        WHERE delivery_id = ?
    ");
    return $stmt->execute([$lat, $lng, $delivery_id]);
}

// Atualizar status online
function setOnlineStatus($delivery_id, $status) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        UPDATE om_market_deliveries 
        SET is_online = ?, last_seen = NOW()
        WHERE delivery_id = ?
    ");
    return $stmt->execute([$status ? 1 : 0, $delivery_id]);
}

// Calcular ganhos do dia
function getTodayEarnings($delivery_id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(delivery_fee), 0) as total
        FROM om_market_orders
        WHERE delivery_id = ? 
        AND status = 'delivered'
        AND DATE(delivered_at) = CURRENT_DATE
    ");
    $stmt->execute([$delivery_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return (float)($result['total'] ?? 0);
}

// Obter estatísticas do entregador
function getDeliveryStats($delivery_id) {
    $pdo = getDB();
    
    // Total de entregas
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM om_market_orders
        WHERE delivery_id = ? AND status = 'delivered'
    ");
    $stmt->execute([$delivery_id]);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Avaliação média
    $stmt = $pdo->prepare("
        SELECT AVG(delivery_rating) as avg_rating
        FROM om_market_orders
        WHERE delivery_id = ? AND delivery_rating IS NOT NULL
    ");
    $stmt->execute([$delivery_id]);
    $rating = $stmt->fetch(PDO::FETCH_ASSOC)['avg_rating'] ?? 5.0;
    
    // Ganhos do mês
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(delivery_fee), 0) as total
        FROM om_market_orders
        WHERE delivery_id = ? 
        AND status = 'delivered'
        AND MONTH(delivered_at) = MONTH(CURRENT_DATE)
        AND YEAR(delivered_at) = YEAR(CURRENT_DATE)
    ");
    $stmt->execute([$delivery_id]);
    $monthEarnings = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    return [
        'total_deliveries' => (int)$total,
        'rating' => round((float)$rating, 1),
        'month_earnings' => (float)$monthEarnings
    ];
}

// Gerar código de entrega
function generateDeliveryCode() {
    $palavras = ['BANANA', 'LARANJA', 'MORANGO', 'ABACAXI', 'MELANCIA', 'MANGA', 'UVA', 'LIMAO', 'COCO', 'PERA'];
    return $palavras[array_rand($palavras)] . '-' . random_int(1000, 9999);
}

// Sanitizar User Agent
function sanitizeUserAgent($ua) {
    if (empty($ua)) return 'unknown';
    $ua = substr($ua, 0, 255);
    return preg_replace('/[^\w\s\.\/\-\(\)\;\:,]/', '', $ua);
}

// Formatar moeda brasileira
function formatMoney($value) {
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
}

// Formatar telefone
function formatPhone($phone) {
    $phone = preg_replace('/\D/', '', $phone);
    if (strlen($phone) === 11) {
        return '(' . substr($phone, 0, 2) . ') ' . substr($phone, 2, 5) . '-' . substr($phone, 7);
    }
    return $phone;
}

// Calcular distância entre dois pontos (Haversine)
function calculateDistance($lat1, $lng1, $lat2, $lng2) {
    $earthRadius = 6371; // km
    
    $latDiff = deg2rad($lat2 - $lat1);
    $lngDiff = deg2rad($lng2 - $lng1);
    
    $a = sin($latDiff / 2) * sin($latDiff / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($lngDiff / 2) * sin($lngDiff / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    return $earthRadius * $c;
}
