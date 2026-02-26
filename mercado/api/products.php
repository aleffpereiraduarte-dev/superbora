<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * 📦 API PRODUTOS SIMPLES
 * Upload em: /mercado/api/products.php
 */

header('Content-Type: application/json; charset=utf-8');

error_reporting(0);
ini_set('display_errors', 0);

try {
    $pdo = getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'DB error']);
    exit;
}

$action = $_GET['action'] ?? 'list';
$partnerId = $_GET['partner_id'] ?? null;
$search = $_GET['search'] ?? $_GET['q'] ?? null;

try {
    
    if ($action === 'list' || $action === 'listar') {
        $sql = "SELECT p.*, m.name as partner_name 
                FROM om_market_products p 
                LEFT JOIN om_market_partners m ON p.partner_id = m.partner_id 
                WHERE p.status = 'active'";
        
        if ($partnerId) {
            $sql .= " AND p.partner_id = " . intval($partnerId);
        }
        
        $sql .= " ORDER BY p.name LIMIT 100";
        
        $products = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'products' => $products,
            'total' => count($products)
        ]);
        exit;
    }
    
    if ($action === 'search' || $action === 'buscar') {
        if (!$search) {
            echo json_encode(['success' => false, 'error' => 'search required']);
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT * FROM om_market_products WHERE status = 'active' AND name LIKE ? LIMIT 20");
        $stmt->execute(["%$search%"]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'products' => $products,
            'query' => $search
        ]);
        exit;
    }
    
    if ($action === 'categories' || $action === 'categorias') {
        $categories = $pdo->query("SELECT DISTINCT category, COUNT(*) as count FROM om_market_products WHERE status = 'active' GROUP BY category")->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'categories' => $categories
        ]);
        exit;
    }
    
    if ($action === 'partners' || $action === 'mercados') {
        $partners = $pdo->query("SELECT * FROM om_market_partners WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'partners' => $partners
        ]);
        exit;
    }
    
    // Default
    echo json_encode(['success' => true, 'message' => 'API Products OK', 'actions' => ['list', 'search', 'categories', 'partners']]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
