<?php
/**
 * API PUSH SUBSCRIBE - CORRIGIDA
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
    exit;
}

// Criar tabela se não existir
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS om_push_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_type VARCHAR(20) NOT NULL,
            user_id INT NOT NULL,
            endpoint TEXT NOT NULL,
            p256dh VARCHAR(255),
            auth VARCHAR(255),
            user_agent VARCHAR(255),
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

// Ler input
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!$input && !empty($raw)) {
    parse_str($raw, $input);
}

if (!$input) {
    $input = $_POST;
}

// POST - Registrar subscription
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_type = isset($input['user_type']) ? $input['user_type'] : '';
    $user_id = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    $subscription = isset($input['subscription']) ? $input['subscription'] : null;
    
    if (!$user_type || !$user_id) {
        echo json_encode(['success' => false, 'error' => 'user_type e user_id obrigatórios']);
        exit;
    }
    
    if (!$subscription || !isset($subscription['endpoint'])) {
        echo json_encode(['success' => false, 'error' => 'subscription inválida']);
        exit;
    }
    
    $endpoint = $subscription['endpoint'];
    $p256dh = isset($subscription['keys']['p256dh']) ? $subscription['keys']['p256dh'] : '';
    $auth = isset($subscription['keys']['auth']) ? $subscription['keys']['auth'] : '';
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    
    try {
        // Verificar se já existe
        $stmt = $pdo->prepare("SELECT id FROM om_push_subscriptions WHERE user_type = ? AND user_id = ? AND endpoint = ?");
        $stmt->execute([$user_type, $user_id, $endpoint]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            $stmt = $pdo->prepare("UPDATE om_push_subscriptions SET p256dh = ?, auth = ?, is_active = 1, last_used_at = NOW(), user_agent = ? WHERE id = ?");
            $stmt->execute([$p256dh, $auth, $user_agent, $existing['id']]);
            echo json_encode(['success' => true, 'message' => 'Atualizado', 'id' => $existing['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO om_push_subscriptions (user_type, user_id, endpoint, p256dh, auth, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_type, $user_id, $endpoint, $p256dh, $auth, $user_agent]);
            echo json_encode(['success' => true, 'message' => 'Registrado', 'id' => $pdo->lastInsertId()]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// DELETE - Remover subscription
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $user_type = isset($input['user_type']) ? $input['user_type'] : '';
    $user_id = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    
    if (!$user_type || !$user_id) {
        echo json_encode(['success' => false, 'error' => 'user_type e user_id obrigatórios']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("DELETE FROM om_push_subscriptions WHERE user_type = ? AND user_id = ?");
        $stmt->execute([$user_type, $user_id]);
        echo json_encode(['success' => true, 'message' => 'Removido']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Método não suportado']);
