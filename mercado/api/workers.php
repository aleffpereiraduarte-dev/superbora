<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * API Workers - OneMundo Mercado
 * /mercado/api/workers.php
 * 
 * Gerencia workers (shoppers/drivers) do Mercado
 * Usado pelo app shopper, delivery e admin
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $pdo = getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$data = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    switch ($action) {
        
        // ==================== LISTAGEM ====================
        case 'list':
            $status = $_GET['status'] ?? '';
            $type = $_GET['type'] ?? '';
            $online = $_GET['online'] ?? '';
            $limit = min((int)($_GET['limit'] ?? 50), 100);
            $offset = (int)($_GET['offset'] ?? 0);
            
            $sql = "SELECT worker_id, worker_number, name, email, phone, worker_type, 
                           status, application_status, is_online, is_active, is_verified,
                           rating, total_orders, total_deliveries, balance,
                           current_lat, current_lng, last_online_at, created_at
                    FROM om_market_workers WHERE 1=1";
            $params = [];
            
            if ($status) {
                $sql .= " AND (status = ? OR application_status = ?)";
                $params[] = $status;
                $params[] = $status;
            }
            if ($type) {
                $sql .= " AND worker_type = ?";
                $params[] = $type;
            }
            if ($online !== '') {
                $sql .= " AND is_online = ?";
                $params[] = (int)$online;
            }
            
            $sql .= " ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $workers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Contar total
            $countSql = "SELECT COUNT(*) FROM om_market_workers WHERE 1=1";
            if ($status) $countSql .= " AND (status = '$status' OR application_status = '$status')";
            if ($type) $countSql .= " AND worker_type = '$type'";
            $total = $pdo->query($countSql)->fetchColumn();
            
            echo json_encode([
                'success' => true,
                'workers' => $workers,
                'total' => (int)$total,
                'limit' => $limit,
                'offset' => $offset
            ]);
            break;
            
        // ==================== DETALHES ====================
        case 'get':
            $workerId = $_GET['id'] ?? $data['worker_id'] ?? 0;
            
            if (!$workerId) {
                throw new Exception('ID do worker não informado');
            }
            
            $stmt = $pdo->prepare("SELECT * FROM om_market_workers WHERE worker_id = ?");
            $stmt->execute([$workerId]);
            $worker = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$worker) {
                throw new Exception('Worker não encontrado');
            }
            
            // Buscar métricas
            $stmt = $pdo->prepare("SELECT * FROM om_market_worker_metrics WHERE worker_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$workerId]);
            $metrics = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Buscar ganhos do mês
            $stmt = $pdo->prepare("SELECT SUM(amount) as month_earnings FROM om_market_worker_earnings 
                                   WHERE worker_id = ? AND EXTRACT(MONTH FROM created_at) = EXTRACT(MONTH FROM NOW()) AND EXTRACT(YEAR FROM created_at) = EXTRACT(YEAR FROM NOW())");
            $stmt->execute([$workerId]);
            $monthEarnings = $stmt->fetchColumn() ?: 0;
            
            echo json_encode([
                'success' => true,
                'worker' => $worker,
                'metrics' => $metrics,
                'month_earnings' => (float)$monthEarnings
            ]);
            break;
            
        // ==================== ONLINE/OFFLINE ====================
        case 'set_online':
            $workerId = $data['worker_id'] ?? 0;
            $online = $data['online'] ?? false;
            $lat = $data['lat'] ?? null;
            $lng = $data['lng'] ?? null;
            
            if (!$workerId) {
                throw new Exception('ID do worker não informado');
            }
            
            $sql = "UPDATE om_market_workers SET is_online = ?, last_online_at = NOW()";
            $params = [(int)$online];
            
            if ($lat && $lng) {
                $sql .= ", current_lat = ?, current_lng = ?, last_location_at = NOW()";
                $params[] = $lat;
                $params[] = $lng;
            }
            
            $sql .= " WHERE worker_id = ?";
            $params[] = $workerId;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            echo json_encode(['success' => true, 'online' => (bool)$online]);
            break;
            
        // ==================== ATUALIZAR LOCALIZAÇÃO ====================
        case 'update_location':
            $workerId = $data['worker_id'] ?? 0;
            $lat = $data['lat'] ?? null;
            $lng = $data['lng'] ?? null;
            
            if (!$workerId || !$lat || !$lng) {
                throw new Exception('Dados incompletos');
            }
            
            $stmt = $pdo->prepare("UPDATE om_market_workers SET 
                current_lat = ?, current_lng = ?, last_location_at = NOW() 
                WHERE worker_id = ?");
            $stmt->execute([$lat, $lng, $workerId]);
            
            // Salvar histórico
            $stmt = $pdo->prepare("INSERT INTO om_market_worker_locations (worker_id, lat, lng) VALUES (?, ?, ?)");
            $stmt->execute([$workerId, $lat, $lng]);
            
            echo json_encode(['success' => true]);
            break;
            
        // ==================== WORKERS DISPONÍVEIS (para matching) ====================
        case 'available':
            $lat = $_GET['lat'] ?? null;
            $lng = $_GET['lng'] ?? null;
            $type = $_GET['type'] ?? ''; // shopper, driver, full_service
            $radius = $_GET['radius'] ?? 10; // km
            
            $sql = "SELECT worker_id, worker_number, name, worker_type, rating, 
                           total_orders, current_lat, current_lng, vehicle_type,
                           avg_shopping_time, avg_delivery_time, acceptance_rate";
            
            // Se tem coordenadas, calcular distância
            if ($lat && $lng) {
                $sql .= ", (6371 * acos(cos(radians(?)) * cos(radians(current_lat)) * 
                          cos(radians(current_lng) - radians(?)) + sin(radians(?)) * 
                          sin(radians(current_lat)))) AS distance";
            }
            
            $sql .= " FROM om_market_workers 
                      WHERE is_online = 1 
                      AND is_active = 1 
                      AND is_verified = 1
                      AND (status = 'active' OR application_status = 'active')
                      AND (current_orders < max_orders OR current_orders IS NULL)";
            
            $params = [];
            if ($lat && $lng) {
                $params = [$lat, $lng, $lat];
            }
            
            if ($type) {
                $sql .= " AND (worker_type = ? OR worker_type = 'full_service')";
                $params[] = $type;
            }
            
            if ($lat && $lng) {
                $sql .= " HAVING distance <= ?";
                $params[] = $radius;
                $sql .= " ORDER BY distance ASC, rating DESC";
            } else {
                $sql .= " ORDER BY rating DESC, total_orders DESC";
            }
            
            $sql .= " LIMIT 20";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $workers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'workers' => $workers,
                'count' => count($workers)
            ]);
            break;
            
        // ==================== ESTATÍSTICAS ====================
        case 'stats':
            $stats = [
                'total' => $pdo->query("SELECT COUNT(*) FROM om_market_workers")->fetchColumn(),
                'active' => $pdo->query("SELECT COUNT(*) FROM om_market_workers WHERE status = 'active' OR application_status = 'active' OR is_active = 1")->fetchColumn(),
                'online' => $pdo->query("SELECT COUNT(*) FROM om_market_workers WHERE is_online = 1")->fetchColumn(),
                'pending' => $pdo->query("SELECT COUNT(*) FROM om_market_workers WHERE application_status IN ('submitted','analyzing','pending_rh') OR application_status IS NULL")->fetchColumn(),
                'shoppers' => $pdo->query("SELECT COUNT(*) FROM om_market_workers WHERE worker_type = 'shopper'")->fetchColumn(),
                'drivers' => $pdo->query("SELECT COUNT(*) FROM om_market_workers WHERE worker_type = 'driver'")->fetchColumn(),
                'full_service' => $pdo->query("SELECT COUNT(*) FROM om_market_workers WHERE worker_type = 'full_service'")->fetchColumn(),
            ];
            
            echo json_encode(['success' => true, 'stats' => $stats]);
            break;
            
        // ==================== GANHOS ====================
        case 'earnings':
            $workerId = $_GET['worker_id'] ?? $data['worker_id'] ?? 0;
            $period = $_GET['period'] ?? 'month'; // day, week, month, year
            
            if (!$workerId) {
                throw new Exception('ID do worker não informado');
            }
            
            $dateFilter = match($period) {
                'day' => "DATE(created_at) = CURRENT_DATE",
                'week' => "YEARWEEK(created_at) = YEARWEEK(NOW())",
                'month' => "EXTRACT(MONTH FROM created_at) = EXTRACT(MONTH FROM NOW()) AND EXTRACT(YEAR FROM created_at) = EXTRACT(YEAR FROM NOW())",
                'year' => "EXTRACT(YEAR FROM created_at) = EXTRACT(YEAR FROM NOW())",
                default => "1=1"
            };
            
            $stmt = $pdo->prepare("SELECT SUM(amount) as total, COUNT(*) as count 
                                   FROM om_market_worker_earnings 
                                   WHERE worker_id = ? AND $dateFilter");
            $stmt->execute([$workerId]);
            $earnings = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Histórico recente
            $stmt = $pdo->prepare("SELECT * FROM om_market_worker_earnings 
                                   WHERE worker_id = ? ORDER BY created_at DESC LIMIT 20");
            $stmt->execute([$workerId]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Saldo atual
            $stmt = $pdo->prepare("SELECT balance FROM om_market_workers WHERE worker_id = ?");
            $stmt->execute([$workerId]);
            $balance = $stmt->fetchColumn();
            
            echo json_encode([
                'success' => true,
                'period' => $period,
                'total' => (float)($earnings['total'] ?? 0),
                'count' => (int)($earnings['count'] ?? 0),
                'balance' => (float)$balance,
                'history' => $history
            ]);
            break;
            
        // ==================== LOGIN WORKER ====================
        case 'login':
            $email = $data['email'] ?? '';
            $password = $data['password'] ?? '';
            
            if (!$email || !$password) {
                throw new Exception('Email e senha obrigatórios');
            }
            
            $stmt = $pdo->prepare("SELECT * FROM om_market_workers WHERE email = ? OR corporate_email = ?");
            $stmt->execute([$email, $email]);
            $worker = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$worker) {
                throw new Exception('Worker não encontrado');
            }
            
            // Verificar se está ativo
            if ($worker['status'] !== 'active' && $worker['application_status'] !== 'active' && !$worker['is_active']) {
                throw new Exception('Conta não está ativa');
            }
            
            // Verificar senha (se existir campo de senha)
            // Por enquanto, aceitar qualquer senha para workers novos
            // TODO: implementar autenticação completa
            
            // Gerar token simples
            $token = bin2hex(random_bytes(32));
            
            echo json_encode([
                'success' => true,
                'worker' => [
                    'worker_id' => $worker['worker_id'],
                    'worker_number' => $worker['worker_number'],
                    'name' => $worker['name'],
                    'email' => $worker['email'],
                    'worker_type' => $worker['worker_type'],
                    'rating' => $worker['rating'],
                    'balance' => $worker['balance'],
                ],
                'token' => $token
            ]);
            break;
            
        // ==================== CADASTRO (do trabalhe-conosco) ====================
        case 'register':
            $required = ['name', 'email', 'phone', 'cpf', 'worker_type'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Campo obrigatório: $field");
                }
            }
            
            // Verificar se já existe
            $stmt = $pdo->prepare("SELECT worker_id FROM om_market_workers WHERE email = ? OR cpf = ?");
            $stmt->execute([$data['email'], $data['cpf']]);
            if ($stmt->fetch()) {
                throw new Exception('Email ou CPF já cadastrado');
            }
            
            // Inserir
            $stmt = $pdo->prepare("INSERT INTO om_market_workers 
                (name, email, phone, cpf, worker_type, birth_date, address, address_number, 
                 neighborhood, city, state, cep, application_status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', NOW())");
            
            $stmt->execute([
                $data['name'],
                $data['email'],
                $data['phone'],
                $data['cpf'],
                $data['worker_type'],
                $data['birth_date'] ?? null,
                $data['address'] ?? null,
                $data['address_number'] ?? null,
                $data['neighborhood'] ?? null,
                $data['city'] ?? null,
                $data['state'] ?? null,
                $data['cep'] ?? null,
            ]);
            
            $workerId = $pdo->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'message' => 'Cadastro realizado! Aguarde aprovação do RH.',
                'worker_id' => $workerId
            ]);
            break;
            
        default:
            throw new Exception('Ação não reconhecida: ' . $action);
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
