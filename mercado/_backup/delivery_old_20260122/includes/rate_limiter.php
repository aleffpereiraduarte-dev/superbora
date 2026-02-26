<?php
/**
 * Rate Limiter - OneMundo Delivery
 * Protege contra brute force e DDoS
 */

class RateLimiter {
    private $pdo;
    private $maxAttempts = 5;
    private $decayMinutes = 15;
    private $blockMinutes = 30;
    
    public function __construct($pdo = null) {
        $this->pdo = $pdo;
    }
    
    public function setDatabase($pdo) {
        $this->pdo = $pdo;
    }
    
    public function getClientIP() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        
        // Se estiver atrás de proxy confiável
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
            $ip = $ips[0];
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
    
    public function tooManyAttempts($key, $maxAttempts = null) {
        $maxAttempts = $maxAttempts ?? $this->maxAttempts;
        return $this->attempts($key) >= $maxAttempts;
    }
    
    public function attempts($key) {
        if (!$this->pdo) {
            return $this->getSessionAttempts($key);
        }
        
        try {
            // Limpar tentativas antigas
            $this->clearOldAttempts();
            
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as attempts 
                FROM om_rate_limits 
                WHERE rate_key = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ");
            $stmt->execute([$key, $this->decayMinutes]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($result['attempts'] ?? 0);
        } catch (Exception $e) {
            return $this->getSessionAttempts($key);
        }
    }
    
    public function hit($key) {
        if (!$this->pdo) {
            return $this->hitSession($key);
        }
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO om_rate_limits (rate_key, ip_address, created_at) 
                VALUES (?, ?, NOW())
            ");
            $stmt->execute([$key, $this->getClientIP()]);
        } catch (Exception $e) {
            $this->hitSession($key);
        }
    }
    
    public function clear($key) {
        if (!$this->pdo) {
            unset($_SESSION['rate_limits'][$key]);
            return;
        }
        
        try {
            $stmt = $this->pdo->prepare("DELETE FROM om_rate_limits WHERE rate_key = ?");
            $stmt->execute([$key]);
        } catch (Exception $e) {
            // Ignore
        }
    }
    
    public function availableIn($key) {
        if (!$this->pdo) {
            return $this->blockMinutes * 60;
        }
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT created_at 
                FROM om_rate_limits 
                WHERE rate_key = ? 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$key]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $lastAttempt = strtotime($result['created_at']);
                $unlockTime = $lastAttempt + ($this->decayMinutes * 60);
                return max(0, $unlockTime - time());
            }
        } catch (Exception $e) {
            // Ignore
        }
        
        return $this->decayMinutes * 60;
    }
    
    private function clearOldAttempts() {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM om_rate_limits 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ");
            $stmt->execute([$this->blockMinutes]);
        } catch (Exception $e) {
            // Ignore - tabela pode não existir
        }
    }
    
    // Fallback para session se banco não disponível
    private function getSessionAttempts($key) {
        return $_SESSION['rate_limits'][$key]['count'] ?? 0;
    }
    
    private function hitSession($key) {
        if (!isset($_SESSION['rate_limits'][$key])) {
            $_SESSION['rate_limits'][$key] = ['count' => 0, 'time' => time()];
        }
        
        // Reset se passou o tempo de decay
        if (time() - $_SESSION['rate_limits'][$key]['time'] > $this->decayMinutes * 60) {
            $_SESSION['rate_limits'][$key] = ['count' => 0, 'time' => time()];
        }
        
        $_SESSION['rate_limits'][$key]['count']++;
    }
    
    public function createTable() {
        if (!$this->pdo) return false;
        
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS om_rate_limits (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    rate_key VARCHAR(255) NOT NULL,
                    ip_address VARCHAR(45) NOT NULL,
                    created_at DATETIME NOT NULL,
                    INDEX idx_rate_key (rate_key),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
