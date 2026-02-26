<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

try {
    $db = getPDO();
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'error' => 'DB Error']));
}

$orderId = intval($_POST['order_id'] ?? 0);
$lat = floatval($_POST['lat'] ?? 0);
$lng = floatval($_POST['lng'] ?? 0);

if (!$orderId || !isset($_FILES['photo'])) {
    die(json_encode(['success' => false, 'error' => 'Missing data']));
}

$uploadDir = __DIR__ . '/../uploads/delivery_photos/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION) ?: 'jpg';
$filename = "delivery_{$orderId}_" . time() . ".$ext";
$filepath = $uploadDir . $filename;

if (move_uploaded_file($_FILES['photo']['tmp_name'], $filepath)) {
    $relativePath = "/mercado/uploads/delivery_photos/$filename";
    
    $stmt = $db->prepare("UPDATE om_market_orders SET 
        delivery_photo = ?, delivery_photo_at = NOW(), delivery_photo_lat = ?, delivery_photo_lng = ?
        WHERE order_id = ?");
    $stmt->execute([$relativePath, $lat, $lng, $orderId]);
    
    echo json_encode(['success' => true, 'photo_url' => $relativePath]);
} else {
    echo json_encode(['success' => false, 'error' => 'Upload failed']);
}