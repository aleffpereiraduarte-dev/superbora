<?php

class AuthController {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function login($email, $password) {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM om_market_shoppers WHERE email = ? AND status != 'inactive'");
            $stmt->execute([$email]);
            $shopper = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($shopper && password_verify($password, $shopper['password'] ?? '')) {
                $_SESSION['shopper_id'] = $shopper['shopper_id'];
                $_SESSION['shopper_name'] = $shopper['name'];
                $this->pdo->prepare("UPDATE om_market_shoppers SET status = 'online', last_login = NOW() WHERE shopper_id = ?")->execute([$shopper['shopper_id']]);
                return ['success' => true, 'shopper' => $shopper];
            }
            
            return ['success' => false, 'message' => 'Credenciais inválidas'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Erro interno do servidor'];
        }
    }
    
    public function logout($shopper_id = null) {
        if ($shopper_id) {
            $this->pdo->prepare("UPDATE om_market_shoppers SET status = 'offline', is_busy = 0 WHERE shopper_id = ?")->execute([$shopper_id]);
        }
        session_destroy();
        return ['success' => true];
    }
    
    public function isLoggedIn() {
        return isset($_SESSION['shopper_id']);
    }
    
    public function getCurrentShopper() {
        if (!$this->isLoggedIn()) return null;
        
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM om_market_shoppers WHERE shopper_id = ?");
            $stmt->execute([$_SESSION['shopper_id']]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return null;
        }
    }
}