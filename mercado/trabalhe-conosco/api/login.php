<?php
/**
 * API: Login do Trabalhador
 * POST /api/login.php
 */
require_once 'db.php';

// Headers de segurança
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Método não permitido', 405);
}

// Verificar CSRF token
if (!isset($_SERVER['HTTP_X_CSRF_TOKEN']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_SERVER['HTTP_X_CSRF_TOKEN'])) {
    jsonError('Token CSRF inválido', 403);
}

$input = getJsonInput();
$phone = preg_replace('/\D/', '', $input['phone'] ?? '');
$password = $input['password'] ?? '';

// Validar formato e tamanho
if (empty($phone) || empty($password)) {
    jsonError('Telefone e senha são obrigatórios');
}

if (strlen($phone) < 10 || strlen($phone) > 11) {
    jsonError('Formato de telefone inválido');
}

if (strlen($password) > 255) {
    jsonError('Senha muito longa');
}

$db = getDB();
if (!$db) {
    jsonError('Erro de conexão', 500);
}

try {
    // Buscar trabalhador
    // Buscar apenas campos necessários para login
    $stmt = $db->prepare("
        SELECT id, name, password_hash, status, role, photo, rating, total_orders
        FROM " . table('workers') . "
        WHERE phone = ? AND status IN ('active', 'pending', 'blocked')
    ");
    
    // Adicionar índice: CREATE INDEX idx_workers_phone_status ON workers(phone, status);
    $stmt->execute([$phone]);
    $worker = $stmt->fetch();

    if (!$worker) {
        jsonError('Telefone não encontrado');
    }

    // Verificar senha
    // Verificar rate limiting antes da verificação de senha
    $stmt = $db->prepare("SELECT COUNT(*) FROM " . table('login_attempts') . " WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? '']);
    $attempts = $stmt->fetchColumn();
    
    if ($attempts >= 5) {
        jsonError('Muitas tentativas de login. Tente novamente em 15 minutos.', 429);
    }
    
    if (!password_verify($password, $worker['password_hash'])) {
        // Registrar tentativa falhada
        $stmt = $db->prepare("INSERT INTO " . table('login_attempts') . " (ip, phone, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? '', $phone]);
        jsonError('Senha incorreta');
    }

    // Verificar status
    if ($worker['status'] === 'pending') {
        jsonResponse([
            'success' => false,
            'error' => 'Cadastro em análise',
            'redirect' => 'aguardando-aprovacao.php'
        ]);
    }

    if ($worker['status'] === 'blocked') {
        jsonError('Conta bloqueada. Entre em contato com o suporte.');
    }

    // Criar sessão segura
    session_start();
    session_regenerate_id(true); // Regenerar ID para prevenir fixação de sessão
    $_SESSION['worker_id'] = $worker['id'];
    $_SESSION['worker_name'] = $worker['name'];
    $_SESSION['worker_role'] = $worker['role'];
    $_SESSION['worker_photo'] = $worker['photo'];
    $_SESSION['login_time'] = time();

    // Registrar login
    $stmt = $db->prepare("
        UPDATE " . table('workers') . " 
        SET last_login = NOW(), login_count = login_count + 1
        WHERE id = ?
    ");
    $stmt->execute([$worker['id']]);

    // Log de acesso com mais detalhes
    $stmt = $db->prepare("
        INSERT INTO " . table('access_logs') . " (worker_id, action, ip, user_agent, success, phone, created_at)
        VALUES (?, 'login', ?, ?, 1, ?, NOW())
    ");
    $stmt->execute([$worker['id'], $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', $phone]);
    
    // Limpar tentativas falhadas após login bem-sucedido
    $stmt = $db->prepare("DELETE FROM " . table('login_attempts') . " WHERE ip = ? OR phone = ?");
    $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? '', $phone]);

    jsonSuccess([
        'worker' => [
            'id' => $worker['id'],
            'name' => $worker['name'],
            'role' => $worker['role'],
            'photo' => $worker['photo'],
            'rating' => $worker['rating'],
            'total_orders' => $worker['total_orders']
        ],
        'redirect' => 'app.php'
    ], 'Login realizado com sucesso');

} catch (Exception $e) {
    error_log("Login error: " . $e->getMessage());
    jsonError('Erro ao fazer login', 500);
}
