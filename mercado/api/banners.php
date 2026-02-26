<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config.php';

try {
    $db = getDB();
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'error' => 'DB Error']));
}

$action = $_GET['action'] ?? '';
$partnerId = intval($_GET['partner_id'] ?? 0);

switch ($action) {
    case 'list':
        $sql = "SELECT * FROM om_market_banners WHERE is_active = 1 
            AND (start_date IS NULL OR start_date <= CURRENT_DATE)
            AND (end_date IS NULL OR end_date >= CURRENT_DATE)";
        if ($partnerId) $sql .= " AND partner_id = $partnerId";
        $sql .= " ORDER BY sort_order ASC";
        
        $stmt = $db->query($sql);
        echo json_encode(['success' => true, 'banners' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;
        
    case 'create':
        $data = json_decode(file_get_contents('php://input'), true);
        $stmt = $db->prepare("INSERT INTO om_market_banners (partner_id, title, subtitle, image, link, background_color, text_color) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['partner_id'], $data['title'], $data['subtitle'] ?? null,
            $data['image'] ?? null, $data['link'] ?? null,
            $data['background_color'] ?? '#22c55e', $data['text_color'] ?? '#ffffff'
        ]);
        echo json_encode(['success' => true, 'banner_id' => $db->lastInsertId()]);
        break;
        
    default:
        echo json_encode(['api' => 'Banners API', 'actions' => ['list', 'create']]);
}