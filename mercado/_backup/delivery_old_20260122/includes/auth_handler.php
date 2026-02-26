<?php
/**
 * Auth Handler - OneMundo Delivery
 * Gerencia autenticação de entregadores
 */

class AuthHandler {
    private $pdo;
    
    public function __construct($pdo = null) {
        $this->pdo = $pdo;
    }
    
    public function setDatabase($pdo) {
        $this->pdo = $pdo;
    }
    
    public function authenticate($email, $password) {
        if (!$this->pdo) {
            throw new Exception('Database connection not set');
        }
        
        // Buscar entregador por email ou telefone
        $stmt = $this->pdo->prepare("
            SELECT delivery_id, name, email, phone, password, status, photo
            FROM om_market_deliveries 
            WHERE (email = ? OR phone = ?) AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$email, preg_replace('/\D/', '', $email)]);
        $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$delivery) {
            return ['success' => false, 'error' => 'Credenciais inválidas'];
        }
        
        // Verificar senha
        if (!password_verify($password, $delivery['password'])) {
            return ['success' => false, 'error' => 'Credenciais inválidas'];
        }
        
        // Login bem sucedido
        return [
            'success' => true,
            'delivery' => [
                'delivery_id' => $delivery['delivery_id'],
                'name' => $delivery['name'],
                'email' => $delivery['email'],
                'phone' => $delivery['phone'],
                'photo' => $delivery['photo']
            ]
        ];
    }
    
    public function createSession($deliveryData) {
        $_SESSION['delivery_id'] = $deliveryData['delivery_id'];
        $_SESSION['delivery_name'] = $deliveryData['name'];
        $_SESSION['delivery_email'] = $deliveryData['email'];
        $_SESSION['delivery_logged_at'] = time();
        
        // Regenerar session ID para prevenir fixation
        session_regenerate_id(true);
        
        // Atualizar último login
        if ($this->pdo) {
            $stmt = $this->pdo->prepare("
                UPDATE om_market_deliveries 
                SET last_login = NOW() 
                WHERE delivery_id = ?
            ");
            $stmt->execute([$deliveryData['delivery_id']]);
        }
    }
    
    public function isLoggedIn() {
        return isset($_SESSION['delivery_id']) && $_SESSION['delivery_id'] > 0;
    }
    
    public function logout() {
        // Atualizar status para offline
        if ($this->pdo && isset($_SESSION['delivery_id'])) {
            $stmt = $this->pdo->prepare("
                UPDATE om_market_deliveries 
                SET is_online = 0 
                WHERE delivery_id = ?
            ");
            $stmt->execute([$_SESSION['delivery_id']]);
        }
        
        // Destruir sessão
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }
    
    public function getCurrentDelivery() {
        if (!$this->isLoggedIn() || !$this->pdo) {
            return null;
        }
        
        $stmt = $this->pdo->prepare("
            SELECT * FROM om_market_deliveries 
            WHERE delivery_id = ?
        ");
        $stmt->execute([$_SESSION['delivery_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
    }
}
