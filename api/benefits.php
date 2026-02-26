<?php
/**
 * ⚡ API DE BENEFÍCIOS DO VENDEDOR
 * Endpoints para gerenciar benefícios
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';

// ═══════════════════════════════════════════════════════════════════════════════
// AUTHENTICATION CHECK - Required for all endpoints
// ═══════════════════════════════════════════════════════════════════════════════
$token = om_auth()->getTokenFromRequest();
if (!$token) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token não fornecido']);
    exit;
}

$tokenData = om_auth()->validateToken($token);
if (!$tokenData) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token inválido ou expirado']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// GET - Buscar benefícios
// ═══════════════════════════════════════════════════════════════════════════════
if ($method === 'GET') {
    $partner_id = (int)($_GET['partner_id'] ?? 0);
    
    if ($action === 'fees') {
        // Buscar taxas do Pagar.me
        $stmt = $pdo->query("SELECT * FROM om_pagarme_fees WHERE active = 1 ORDER BY installments");
        echo json_encode(['success' => true, 'fees' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    
    if ($action === 'coupons' && $partner_id) {
        // Buscar cupons do vendedor
        $stmt = $pdo->prepare("SELECT * FROM om_seller_coupons WHERE partner_id = ? AND active = 1");
        $stmt->execute([$partner_id]);
        echo json_encode(['success' => true, 'coupons' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    
    if ($partner_id) {
        // Buscar benefícios do vendedor
        $stmt = $pdo->prepare("SELECT * FROM om_seller_benefits WHERE partner_id = ?");
        $stmt->execute([$partner_id]);
        $benefits = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'benefits' => $benefits ?: []]);
    } else {
        echo json_encode(['success' => false, 'error' => 'partner_id obrigatório']);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// POST - Salvar/Atualizar benefícios
// ═══════════════════════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $partner_id = (int)($input['partner_id'] ?? 0);
    
    if (!$partner_id) {
        echo json_encode(['success' => false, 'error' => 'partner_id obrigatório']);
        exit;
    }
    
    if ($action === 'coupon') {
        // Criar cupom
        $stmt = $pdo->prepare("INSERT INTO om_seller_coupons 
            (partner_id, code, discount_type, discount_value, min_order_value, max_discount, usage_limit, start_date, end_date, first_purchase_only)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $partner_id,
            strtoupper($input['code'] ?? 'CUPOM' . rand(100,999)),
            $input['discount_type'] ?? 'percent',
            $input['discount_value'] ?? 10,
            $input['min_order_value'] ?? 0,
            $input['max_discount'] ?? null,
            $input['usage_limit'] ?? null,
            $input['start_date'] ?? null,
            $input['end_date'] ?? null,
            $input['first_purchase_only'] ?? 0
        ]);
        echo json_encode(['success' => true, 'coupon_id' => $pdo->lastInsertId()]);
        exit;
    }
    
    // Salvar benefícios
    $campos = [
        'installments_enabled', 'max_installments', 'installments_min_value', 'installments_fee_type',
        'free_shipping_enabled', 'free_shipping_min_value', 'shipping_discount_percent', 'fixed_shipping_value',
        'first_purchase_discount', 'pix_discount', 'boleto_discount',
        'cashback_enabled', 'cashback_percent', 'cashback_max_value',
        'extended_warranty_days', 'satisfaction_guarantee', 'free_return_days',
        'gift_enabled', 'gift_min_value', 'gift_description', 'express_shipping_free'
    ];
    
    $sets = [];
    $values = [$partner_id];
    
    foreach ($campos as $campo) {
        if (isset($input[$campo])) {
            $sets[] = "$campo = ?";
            $values[] = $input[$campo];
        }
    }
    
    if (empty($sets)) {
        echo json_encode(['success' => false, 'error' => 'Nenhum campo para atualizar']);
        exit;
    }
    
    // Upsert
    $sql = "INSERT INTO om_seller_benefits (partner_id, " . implode(', ', array_keys(array_filter($input, fn($k) => in_array($k, $campos), ARRAY_FILTER_USE_KEY))) . ") 
            VALUES (?" . str_repeat(', ?', count($sets)) . ")
            ON DUPLICATE KEY UPDATE " . implode(', ', $sets);
    
    // Simplificado
    $checkStmt = $pdo->prepare("SELECT 1 FROM om_seller_benefits WHERE partner_id = ?");
    $checkStmt->execute([$partner_id]);
    
    if ($checkStmt->fetch()) {
        // Update
        $sql = "UPDATE om_seller_benefits SET " . implode(', ', $sets) . " WHERE partner_id = ?";
        $values[] = $partner_id;
        array_shift($values); // Remove primeiro partner_id
    } else {
        // Insert
        $insertCampos = ['partner_id'];
        $insertValues = [$partner_id];
        $placeholders = ['?'];
        
        foreach ($campos as $campo) {
            if (isset($input[$campo])) {
                $insertCampos[] = $campo;
                $insertValues[] = $input[$campo];
                $placeholders[] = '?';
            }
        }
        
        $sql = "INSERT INTO om_seller_benefits (" . implode(', ', $insertCampos) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $values = $insertValues;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    
    echo json_encode(['success' => true, 'message' => 'Benefícios salvos']);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// DELETE - Remover cupom
// ═══════════════════════════════════════════════════════════════════════════════
if ($method === 'DELETE') {
    $coupon_id = (int)($_GET['coupon_id'] ?? 0);
    if ($coupon_id) {
        $stmt = $pdo->prepare("DELETE FROM om_seller_coupons WHERE coupon_id = ?");
        $stmt->execute([$coupon_id]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'coupon_id obrigatório']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Método não suportado']);