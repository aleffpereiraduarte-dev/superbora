<?php
/**
 * API: Hotspots (Mapa de demanda)
 */
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/HotspotsHelper.php';
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$action = $_GET['action'] ?? '';
$pdo = getDB();
$hotspots = new HotspotsHelper($pdo);

switch ($action) {
    case 'get-all':
        $data = $hotspots->getActiveHotspots();
        echo json_encode(['success' => true, 'hotspots' => $data]);
        break;
        
    case 'get-nearby':
        $lat = $_GET['lat'] ?? 0;
        $lng = $_GET['lng'] ?? 0;
        $radius = $_GET['radius'] ?? 5;
        $data = $hotspots->getNearbyHotspots($lat, $lng, $radius);
        echo json_encode(['success' => true, 'hotspots' => $data]);
        break;
        
    case 'get-heatmap':
        $data = $hotspots->getHeatmapData();
        echo json_encode(['success' => true, 'heatmap' => $data]);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Ação inválida']);
}