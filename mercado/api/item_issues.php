<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$db = getPDO();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$params = array_merge($_GET, $_POST, $input);

switch ($action) {
    
    case 'report':
        $stmt = $db->prepare("INSERT INTO om_market_order_item_issues 
            (order_id, order_item_id, product_id, issue_type, substitute_product_id, substitute_name, substitute_price, shopper_notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $params['order_id'],
            $params['order_item_id'],
            $params['product_id'],
            $params['issue_type'] ?? 'unavailable',
            $params['substitute_product_id'] ?? null,
            $params['substitute_name'] ?? null,
            $params['substitute_price'] ?? null,
            $params['notes'] ?? null
        ]);
        
        echo json_encode(['success' => true, 'issue_id' => $db->lastInsertId()]);
        break;
        
    case 'respond':
        $issueId = intval($params['issue_id'] ?? 0);
        $response = $params['response'] ?? '';
        
        if (!in_array($response, ['accept_substitute', 'remove_item', 'refund'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid response']);
            break;
        }
        
        $stmt = $db->prepare("UPDATE om_market_order_item_issues SET customer_response = ?, responded_at = NOW() WHERE issue_id = ?");
        $stmt->execute([$response, $issueId]);
        
        if ($response === 'accept_substitute') {
            $stmt = $db->prepare("SELECT * FROM om_market_order_item_issues WHERE issue_id = ?");
            $stmt->execute([$issueId]);
            $issue = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($issue && $issue['substitute_name']) {
                $stmt = $db->prepare("UPDATE om_market_order_items SET 
                    name = ?, price = ?, substituted = 1 
                    WHERE order_item_id = ?");
                $stmt->execute([$issue['substitute_name'], $issue['substitute_price'], $issue['order_item_id']]);
            }
        } elseif ($response === 'remove_item') {
            $stmt = $db->prepare("UPDATE om_market_order_items SET quantity = 0, total = 0 WHERE order_item_id = ?");
            $stmt->execute([$params['order_item_id'] ?? 0]);
        }
        
        echo json_encode(['success' => true]);
        break;
        
    case 'pending':
        $orderId = intval($params['order_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM om_market_order_item_issues WHERE order_id = ? AND customer_response = 'pending'");
        $stmt->execute([$orderId]);
        echo json_encode(['success' => true, 'issues' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;
        
    default:
        echo json_encode(['api' => 'Item Issues', 'actions' => ['report', 'respond', 'pending']]);
}