<?php
require_once dirname(__DIR__) . '/config/database.php';
header('Content-Type: application/json');
session_start();

$pdo = getPDO();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$cid = $_SESSION['customer_id'] ?? $_GET['customer_id'] ?? 0;
$response = ['success' => false];

try {
    switch ($action) {
        case 'status':
            $first = true;
            if ($cid) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_market_orders WHERE customer_id = ? AND status NOT IN ('cancelled','failed')");
                $stmt->execute([$cid]);
                $first = $stmt->fetchColumn() == 0;
            }
            
            $streak = 0;
            if ($cid) {
                $stmt = $pdo->prepare("SELECT current_streak FROM om_customer_streak WHERE customer_id = ? AND (last_purchase_date = CURRENT_DATE OR last_purchase_date = DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY))");
                $stmt->execute([$cid]);
                $streak = (int)$stmt->fetchColumn();
            }
            
            $points = 0;
            if ($cid) {
                $stmt = $pdo->prepare("SELECT points FROM om_customer_points WHERE customer_id = ?");
                $stmt->execute([$cid]);
                $points = (int)$stmt->fetchColumn();
            }
            
            $missions = 0;
            if ($cid) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_customer_missions WHERE customer_id = ? AND mission_date = CURRENT_DATE AND completed = 1");
                $stmt->execute([$cid]);
                $missions = (int)$stmt->fetchColumn();
            }
            
            $response = ['success' => true, 'is_first_order' => $first, 'streak' => $streak, 'streak_bonus' => min($streak*2,20), 'points' => $points, 'missions_completed' => $missions, 'missions_total' => 3];
            break;
            
        case 'validate_coupon':
            $code = strtoupper($_GET['code'] ?? '');
            $total = (float)($_GET['total'] ?? 0);
            
            $stmt = $pdo->prepare("SELECT * FROM om_coupons WHERE code = ? AND status = '1'");
            $stmt->execute([$code]);
            $c = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$c) { $response = ['success' => false, 'message' => 'Cupom não encontrado']; break; }
            
            if ($c['first_order_only'] && $cid) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_market_orders WHERE customer_id = ? AND status NOT IN ('cancelled','failed')");
                $stmt->execute([$cid]);
                if ($stmt->fetchColumn() > 0) { $response = ['success' => false, 'message' => 'Cupom só para primeira compra']; break; }
            }
            
            if ($c['single_use'] && $cid) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_coupon_usage WHERE coupon_id = ? AND customer_id = ?");
                $stmt->execute([$c['id'], $cid]);
                if ($stmt->fetchColumn() > 0) { $response = ['success' => false, 'message' => 'Você já usou este cupom']; break; }
            }
            
            if ($c['min_order'] > 0 && $total < $c['min_order']) {
                $response = ['success' => false, 'message' => 'Pedido mínimo: R$ ' . number_format($c['min_order'], 2, ',', '.')];
                break;
            }
            
            $discount = 0;
            if ($c['type'] === 'percent') {
                $discount = $total * ($c['value'] / 100);
                if ($c['max_discount'] > 0) $discount = min($discount, $c['max_discount']);
            } elseif ($c['type'] === 'fixed') {
                $discount = $c['value'];
            }
            
            $response = ['success' => true, 'valid' => true, 'discount' => round($discount, 2), 'type' => $c['type'], 'message' => 'Cupom válido!'];
            break;
            
        case 'apply_coupon':
            $input = json_decode(file_get_contents('php://input'), true);
            $code = strtoupper($input['code'] ?? '');
            $total = (float)($input['total'] ?? 0);
            
            $stmt = $pdo->prepare("SELECT * FROM om_coupons WHERE code = ? AND status = '1'");
            $stmt->execute([$code]);
            $c = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$c) { $response = ['success' => false, 'message' => 'Cupom inválido']; break; }
            
            $discount = $c['type'] === 'percent' ? $total * ($c['value'] / 100) : $c['value'];
            if ($c['max_discount'] > 0) $discount = min($discount, $c['max_discount']);
            
            $_SESSION['applied_coupon'] = ['code' => $code, 'discount' => $discount, 'type' => $c['type'], 'coupon_id' => $c['id']];
            $response = ['success' => true, 'discount' => round($discount, 2), 'message' => 'Cupom aplicado!'];
            break;
            
        case 'remove_coupon':
            unset($_SESSION['applied_coupon']);
            $response = ['success' => true];
            break;
            
        case 'complete_mission':
            $input = json_decode(file_get_contents('php://input'), true);
            $type = $input['mission_type'] ?? '';
            if (!$cid || !$type) break;
            
            $pts = ['first_purchase' => 50, 'add_3_items' => 30, 'try_hortifruti' => 40][$type] ?? 20;
            
            $stmt = $pdo->prepare("INSERT IGNORE INTO om_customer_missions (customer_id, mission_type, mission_date, completed, completed_at, points_earned) VALUES (?, ?, CURRENT_DATE, 1, NOW(), ?)");
            $stmt->execute([$cid, $type, $pts]);
            
            if ($stmt->rowCount() > 0) {
                $pdo->prepare("INSERT INTO om_customer_points (customer_id, points, total_earned) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE points = points + ?, total_earned = total_earned + ?")
                    ->execute([$cid, $pts, $pts, $pts, $pts]);
                $response = ['success' => true, 'points' => $pts];
            } else {
                $response = ['success' => true, 'already' => true];
            }
            break;
            
        case 'update_streak':
            if (!$cid) break;
            $today = date('Y-m-d');
            
            $stmt = $pdo->prepare("SELECT * FROM om_customer_streak WHERE customer_id = ?");
            $stmt->execute([$cid]);
            $s = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$s) {
                $pdo->prepare("INSERT INTO om_customer_streak (customer_id, current_streak, max_streak, last_purchase_date) VALUES (?, 1, 1, ?)")->execute([$cid, $today]);
                $response = ['success' => true, 'streak' => 1];
            } else {
                $yesterday = date('Y-m-d', strtotime('-1 day'));
                if ($s['last_purchase_date'] === $today) {
                    $response = ['success' => true, 'streak' => $s['current_streak']];
                } elseif ($s['last_purchase_date'] === $yesterday) {
                    $new = $s['current_streak'] + 1;
                    $pdo->prepare("UPDATE om_customer_streak SET current_streak = ?, max_streak = GREATEST(max_streak, ?), last_purchase_date = ? WHERE customer_id = ?")->execute([$new, $new, $today, $cid]);
                    $response = ['success' => true, 'streak' => $new, 'increased' => true];
                } else {
                    $pdo->prepare("UPDATE om_customer_streak SET current_streak = 1, last_purchase_date = ? WHERE customer_id = ?")->execute([$today, $cid]);
                    $response = ['success' => true, 'streak' => 1, 'reset' => true];
                }
            }
            break;
    }
} catch (Exception $e) {
    $response = ['success' => false, 'message' => $e->getMessage()];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);