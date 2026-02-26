<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * API DE FAVORITOS - OneMundo Mercado
 * ══════════════════════════════════════════════════════════════════════════════
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

session_name('OCSESSID');
session_start();

$customer_id = $_SESSION['customer_id'] ?? 0;

if (!$customer_id) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

try {
    // Usar config do OpenCart
    $_oc_root = dirname(__DIR__);
    if (file_exists($_oc_root . '/config.php') && !defined('DB_HOSTNAME')) {
        require_once($_oc_root . '/config.php');
    }

    $db_host = defined('DB_HOSTNAME') ? DB_HOSTNAME : '127.0.0.1';
    $db_name = defined('DB_DATABASE') ? DB_DATABASE : 'love1';
    $db_user = defined('DB_USERNAME') ? DB_USERNAME : 'root';
    $db_pass = defined('DB_PASSWORD') ? DB_PASSWORD : '';

    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Criar tabela se não existir
    $pdo->exec("CREATE TABLE IF NOT EXISTS om_customer_favorites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        product_id INT NOT NULL,
        date_added DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_fav (customer_id, product_id),
        INDEX idx_customer (customer_id)
    )");
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';
$product_id = (int)($input['product_id'] ?? 0);

switch ($action) {
    
    case 'add':
        if (!$product_id) {
            echo json_encode(['success' => false, 'error' => 'Produto inválido']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO om_customer_favorites (customer_id, product_id, date_added) 
            VALUES (?, ?, NOW())
        ");
        $stmt->execute([$customer_id, $product_id]);
        
        echo json_encode(['success' => true, 'message' => 'Adicionado aos favoritos']);
        break;
    
    case 'remove':
        if (!$product_id) {
            echo json_encode(['success' => false, 'error' => 'Produto inválido']);
            exit;
        }
        
        $stmt = $pdo->prepare("DELETE FROM om_customer_favorites WHERE customer_id = ? AND product_id = ?");
        $stmt->execute([$customer_id, $product_id]);
        
        echo json_encode(['success' => true, 'message' => 'Removido dos favoritos']);
        break;
    
    case 'toggle':
        if (!$product_id) {
            echo json_encode(['success' => false, 'error' => 'Produto inválido']);
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT id FROM om_customer_favorites WHERE customer_id = ? AND product_id = ?");
        $stmt->execute([$customer_id, $product_id]);
        
        if ($stmt->fetch()) {
            $pdo->prepare("DELETE FROM om_customer_favorites WHERE customer_id = ? AND product_id = ?")
                ->execute([$customer_id, $product_id]);
            echo json_encode(['success' => true, 'is_favorite' => false]);
        } else {
            $pdo->prepare("INSERT INTO om_customer_favorites (customer_id, product_id) VALUES (?, ?)")
                ->execute([$customer_id, $product_id]);
            echo json_encode(['success' => true, 'is_favorite' => true]);
        }
        break;
    
    case 'check':
        if (!$product_id) {
            echo json_encode(['success' => false, 'error' => 'Produto inválido']);
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT id FROM om_customer_favorites WHERE customer_id = ? AND product_id = ?");
        $stmt->execute([$customer_id, $product_id]);
        
        echo json_encode(['success' => true, 'is_favorite' => (bool)$stmt->fetch()]);
        break;
    
    case 'list':
    default:
        $stmt = $pdo->prepare("
            SELECT f.product_id, p.price, p.image, pd.name,
                   COALESCE(ps.price, p.price) as special_price
            FROM om_customer_favorites f
            JOIN oc_product p ON f.product_id = p.product_id AND p.status = 1
            JOIN oc_product_description pd ON p.product_id = pd.product_id AND pd.language_id = 2
            LEFT JOIN oc_product_special ps ON p.product_id = ps.product_id AND ps.customer_group_id = 1
            WHERE f.customer_id = ?
            ORDER BY f.date_added DESC
        ");
        $stmt->execute([$customer_id]);
        $favorites = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'favorites' => $favorites,
            'count' => count($favorites)
        ]);
        break;
}
