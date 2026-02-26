<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
/**
 * API: Obter Ganhos
 * GET /api/get-earnings.php?period=today|week|month
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

session_start();

try {
    $workerId = $_GET['worker_id'] ?? $_SESSION['worker_id'] ?? null;
    $period = $_GET['period'] ?? 'today';
    
    if (!$workerId) {
        throw new Exception('worker_id é obrigatório');
    }
    
    $conn = getMySQLi();
    $conn->set_charset('utf8mb4');
    
    if ($conn->connect_error) {
        throw new Exception('Erro de conexão');
    }
    
    // Definir período
    switch ($period) {
        case 'today':
            $dateFilter = "DATE(delivered_at) = CURRENT_DATE";
            break;
        case 'week':
            $dateFilter = "delivered_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)";
            break;
        case 'month':
            $dateFilter = "delivered_at >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)";
            break;
        default:
            $dateFilter = "1=1";
    }
    
    // Buscar ganhos
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_deliveries,
            COALESCE(SUM(earnings), 0) as earnings,
            COALESCE(SUM(tip), 0) as tips,
            COALESCE(SUM(bonus), 0) as bonus,
            COALESCE(SUM(earnings + tip + bonus), 0) as total,
            COALESCE(AVG(TIMESTAMPDIFF(MINUTE, accepted_at, delivered_at)), 0) as avg_time_minutes,
            COALESCE(SUM(distance_km), 0) as total_distance
        FROM om_worker_deliveries 
        WHERE worker_id = ? 
        AND status = 'delivered'
        AND $dateFilter
    ");
    $stmt->bind_param("i", $workerId);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc();
    
    // Buscar últimas entregas
    $stmt = $conn->prepare("
        SELECT order_id, store_name, earnings, tip, delivered_at
        FROM om_worker_deliveries 
        WHERE worker_id = ? 
        AND status = 'delivered'
        AND $dateFilter
        ORDER BY delivered_at DESC
        LIMIT 10
    ");
    $stmt->bind_param("i", $workerId);
    $stmt->execute();
    $deliveries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Buscar comparação com período anterior
    switch ($period) {
        case 'today':
            $prevFilter = "DATE(delivered_at) = DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY)";
            break;
        case 'week':
            $prevFilter = "delivered_at >= DATE_SUB(CURRENT_DATE, INTERVAL 14 DAY) AND delivered_at < DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)";
            break;
        case 'month':
            $prevFilter = "delivered_at >= DATE_SUB(CURRENT_DATE, INTERVAL 60 DAY) AND delivered_at < DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)";
            break;
        default:
            $prevFilter = "1=0";
    }
    
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(earnings + tip + bonus), 0) as total
        FROM om_worker_deliveries 
        WHERE worker_id = ? AND status = 'delivered' AND $prevFilter
    ");
    $stmt->bind_param("i", $workerId);
    $stmt->execute();
    $previous = $stmt->get_result()->fetch_assoc();
    
    $prevTotal = floatval($previous['total']);
    $currTotal = floatval($summary['total']);
    $variation = $prevTotal > 0 ? (($currTotal - $prevTotal) / $prevTotal) * 100 : 0;
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'data' => [
            'period' => $period,
            'summary' => [
                'total_deliveries' => intval($summary['total_deliveries']),
                'earnings' => floatval($summary['earnings']),
                'tips' => floatval($summary['tips']),
                'bonus' => floatval($summary['bonus']),
                'total' => floatval($summary['total']),
                'avg_time_minutes' => round(floatval($summary['avg_time_minutes'])),
                'total_distance_km' => round(floatval($summary['total_distance']), 1)
            ],
            'variation' => round($variation, 1),
            'deliveries' => $deliveries
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
