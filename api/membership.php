<?php
/**
 * API de Assinatura Membership - OneMundo
 * Permite assinar o membership direto no checkout
 *
 * Endpoint: /api/membership/subscribe
 * Método: POST
 */
error_reporting(E_ALL);
ini_set('display_errors', 0); // Desabilitado em produção - erros vão para log
ini_set('log_errors', 1);

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Usar config.php do OpenCart
require_once dirname(__DIR__) . '/config.php';

define('MEMBERSHIP_PRICE', 19.90);

class MembershipAPI {
    private $pdo;

    public function __construct() {
        try {
            $this->pdo = new PDO(
                "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
                DB_USERNAME, DB_PASSWORD,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            $this->response(false, 'Erro de conexão');
        }
    }
    
    /**
     * Assinar Membership
     */
    public function subscribe($customer_id) {
        if (!$customer_id) {
            return $this->response(false, 'Cliente não identificado');
        }
        
        // Verifica se já é membro
        $stmt = $this->pdo->prepare("
            SELECT member_id, status FROM om_membership_members WHERE customer_id = ?
        ");
        $stmt->execute([$customer_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing && $existing['status'] === 'active') {
            return $this->response(false, 'Você já é um membro ativo!');
        }
        
        $this->pdo->beginTransaction();
        
        try {
            // Busca nível Bronze (inicial)
            $stmt = $this->pdo->prepare("SELECT level_id FROM om_membership_levels WHERE level_code = 'BRONZE'");
            $stmt->execute();
            $level = $stmt->fetch(PDO::FETCH_ASSOC);
            $level_id = $level['level_id'] ?? 1;
            
            if ($existing) {
                // Reativa membership
                $stmt = $this->pdo->prepare("
                    UPDATE om_membership_members 
                    SET status = 'active',
                        activated_at = NOW(),
                        expires_at = NOW() + INTERVAL '1 month',
                        updated_at = NOW()
                    WHERE customer_id = ?
                ");
                $stmt->execute([$customer_id]);
                $member_id = $existing['member_id'];
            } else {
                // Cria novo membership
                $stmt = $this->pdo->prepare("
                    INSERT INTO om_membership_members 
                    (customer_id, level_id, status, total_miles, annual_points, activated_at, expires_at, created_at)
                    VALUES (?, ?, 'active', 0, 0, NOW(), NOW() + INTERVAL '1 month', NOW())
                    RETURNING member_id
                ");
                $stmt->execute([$customer_id, $level_id]);
                $member_id = $stmt->fetchColumn();
            }
            
            // Registra transação de assinatura
            $stmt = $this->pdo->prepare("
                INSERT INTO om_membership_transactions 
                (member_id, type, amount, description, created_at)
                VALUES (?, 'subscription', ?, 'Assinatura Membership - Checkout', NOW())
            ");
            $stmt->execute([$member_id, MEMBERSHIP_PRICE]);
            
            // Salva na sessão para adicionar ao pedido
            $_SESSION['membership_subscribed'] = true;
            $_SESSION['membership_price'] = MEMBERSHIP_PRICE;
            $_SESSION['membership_member_id'] = $member_id;
            
            $this->pdo->commit();
            
            return $this->response(true, 'Membership ativado com sucesso!', [
                'member_id' => $member_id,
                'level' => 'BRONZE',
                'level_name' => 'Bronze',
                'discount_percent' => 50,
                'price' => MEMBERSHIP_PRICE,
                'price_formatted' => 'R$ ' . number_format(MEMBERSHIP_PRICE, 2, ',', '.'),
                'benefits' => [
                    '50% de desconto no frete desta compra',
                    '50% de desconto nos próximos 2 fretes do mês',
                    'Acumule pontos a cada compra',
                    'Suba de nível e ganhe frete GRÁTIS'
                ]
            ]);
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("[membership] Erro ao processar assinatura: " . $e->getMessage());
            return $this->response(false, 'Erro ao processar assinatura');
        }
    }
    
    /**
     * Verifica status do membership
     */
    public function status($customer_id) {
        if (!$customer_id) {
            return $this->response(true, 'OK', ['is_member' => false]);
        }
        
        $stmt = $this->pdo->prepare("
            SELECT m.*, l.level_code, l.level_name, l.shipping_discount_percent,
                   l.free_shipping_per_month, l.badge_color, l.badge_icon
            FROM om_membership_members m
            JOIN om_membership_levels l ON m.level_id = l.level_id
            WHERE m.customer_id = ? AND m.status = 'active'
        ");
        $stmt->execute([$customer_id]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$member) {
            return $this->response(true, 'OK', ['is_member' => false]);
        }
        
        // Conta fretes usados no mês
        $stmt2 = $this->pdo->prepare("
            SELECT COUNT(*) as usado
            FROM om_membership_shipping_usage
            WHERE member_id = ? 
            AND MONTH(used_at) = MONTH(CURRENT_DATE())
            AND YEAR(used_at) = YEAR(CURRENT_DATE())
        ");
        $stmt2->execute([$member['member_id']]);
        $uso = $stmt2->fetch(PDO::FETCH_ASSOC);
        
        $usado = $uso['usado'] ?? 0;
        $limite = $member['free_shipping_per_month'];
        
        if ($limite == -1) {
            $disponivel = 'ilimitado';
            $disponivel_texto = 'Ilimitado';
        } else {
            $disponivel = max(0, $limite - $usado);
            $disponivel_texto = $disponivel . ' de ' . $limite;
        }
        
        return $this->response(true, 'OK', [
            'is_member' => true,
            'member_id' => $member['member_id'],
            'level_code' => $member['level_code'],
            'level_name' => $member['level_name'],
            'discount_percent' => (float)$member['shipping_discount_percent'],
            'free_shipping_limit' => $limite,
            'free_shipping_used' => $usado,
            'free_shipping_available' => $disponivel,
            'free_shipping_texto' => $disponivel_texto,
            'total_miles' => (float)$member['total_miles'],
            'annual_points' => (float)$member['annual_points'],
            'badge_color' => $member['badge_color'],
            'badge_icon' => $member['badge_icon'],
            'expires_at' => $member['expires_at']
        ]);
    }
    
    /**
     * Cancela assinatura pendente (se desistir antes de finalizar)
     */
    public function cancelPending($customer_id) {
        unset($_SESSION['membership_subscribed']);
        unset($_SESSION['membership_price']);
        unset($_SESSION['membership_member_id']);
        
        return $this->response(true, 'Assinatura cancelada');
    }
    
    /**
     * Retorna resposta JSON
     */
    private function response($success, $message, $data = []) {
        return [
            'success' => $success,
            'message' => $message,
            'data' => $data
        ];
    }
}

// Processar requisição
$api = new MembershipAPI();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'status';

// Pega customer_id da sessão do OpenCart
if (file_exists('config.php')) {
    require_once('config.php');
}
$customer_id = $_SESSION['customer_id'] ?? null;

$data = json_decode(file_get_contents('php://input'), true) ?: $_REQUEST;

// Permite passar customer_id via request (para testes)
if (isset($data['customer_id'])) {
    $customer_id = $data['customer_id'];
}

$result = [];

switch ($action) {
    case 'subscribe':
        $result = $api->subscribe($customer_id);
        break;
        
    case 'status':
        $result = $api->status($customer_id);
        break;
        
    case 'cancel':
        $result = $api->cancelPending($customer_id);
        break;
        
    default:
        $result = ['success' => false, 'message' => 'Ação inválida'];
}

echo json_encode($result);
