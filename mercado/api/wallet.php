<?php
require_once dirname(__DIR__) . '/config/database.php';
header('Content-Type: application/json');

try {
    $db = getPDO();
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'error' => 'DB Error']));
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$params = array_merge($_GET, $_POST, $input);

switch ($action) {
    
    case 'balance':
        $workerId = intval($params['worker_id'] ?? 0);
        
        // Garantir que wallet existe
        $db->prepare("INSERT IGNORE INTO om_worker_wallet (worker_id) VALUES (?)")->execute([$workerId]);
        
        $stmt = $db->prepare("SELECT * FROM om_worker_wallet WHERE worker_id = ?");
        $stmt->execute([$workerId]);
        
        echo json_encode(['success' => true, 'wallet' => $stmt->fetch(PDO::FETCH_ASSOC)]);
        break;
        
    case 'add_earning':
        $workerId = intval($params['worker_id'] ?? 0);
        $orderId = intval($params['order_id'] ?? 0);
        $amount = floatval($params['amount'] ?? 0);
        $type = $params['type'] ?? 'earning';
        $description = $params['description'] ?? '';
        
        $db->beginTransaction();
        try {
            // Inserir transação
            $stmt = $db->prepare("INSERT INTO om_worker_transactions (worker_id, order_id, type, amount, description) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$workerId, $orderId ?: null, $type, $amount, $description]);
            
            // Atualizar wallet
            $db->prepare("INSERT INTO om_worker_wallet (worker_id, balance, total_earned) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance), total_earned = total_earned + VALUES(total_earned)")
                ->execute([$workerId, $amount, $amount]);
            
            $db->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
        
    case 'withdraw':
        $workerId = intval($params['worker_id'] ?? 0);
        $amount = floatval($params['amount'] ?? 0);
        $pixKey = $params['pix_key'] ?? '';
        
        // Verificar saldo
        $stmt = $db->prepare("SELECT balance FROM om_worker_wallet WHERE worker_id = ?");
        $stmt->execute([$workerId]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$wallet || $wallet['balance'] < $amount) {
            echo json_encode(['success' => false, 'error' => 'Saldo insuficiente']);
            break;
        }
        
        $db->beginTransaction();
        try {
            // Criar solicitação de saque
            $stmt = $db->prepare("INSERT INTO om_worker_withdrawals (worker_id, amount, pix_key) VALUES (?, ?, ?)");
            $stmt->execute([$workerId, $amount, $pixKey]);
            
            // Debitar do saldo
            $db->prepare("UPDATE om_worker_wallet SET balance = balance - ?, pending = pending + ? WHERE worker_id = ?")
                ->execute([$amount, $amount, $workerId]);
            
            // Transação de saque
            $db->prepare("INSERT INTO om_worker_transactions (worker_id, type, amount, description, status) VALUES (?, 'withdrawal', ?, 'Solicitação de saque', 'pending')")
                ->execute([$workerId, -$amount]);
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Saque solicitado!']);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
        
    case 'history':
        $workerId = intval($params['worker_id'] ?? 0);
        $limit = intval($params['limit'] ?? 50);
        
        $stmt = $db->prepare("SELECT * FROM om_worker_transactions WHERE worker_id = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$workerId, $limit]);
        
        echo json_encode(['success' => true, 'transactions' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;
        
    default:
        echo json_encode(['api' => 'Wallet API', 'actions' => ['balance', 'add_earning', 'withdraw', 'history']]);
}