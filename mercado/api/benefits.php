<?php
/**
 * ⚡ API DE BENEFÍCIOS DO VENDEDOR
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
    exit;
}

// GET - Buscar benefícios
if ($method === 'GET') {
    $partner_id = (int)($_GET['partner_id'] ?? 0);
    
    if ($action === 'fees') {
        $stmt = $pdo->query("SELECT * FROM om_pagarme_fees WHERE active = 1 ORDER BY installments");
        echo json_encode(['success' => true, 'fees' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    
    if ($action === 'coupons' && $partner_id) {
        $stmt = $pdo->prepare("SELECT * FROM om_seller_coupons WHERE partner_id = ? AND active = 1");
        $stmt->execute([$partner_id]);
        echo json_encode(['success' => true, 'coupons' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    
    if ($partner_id) {
        $stmt = $pdo->prepare("SELECT * FROM om_seller_benefits WHERE partner_id = ?");
        $stmt->execute([$partner_id]);
        $benefits = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'benefits' => $benefits ?: []]);
    } else {
        echo json_encode(['success' => false, 'error' => 'partner_id obrigatório']);
    }
    exit;
}

// POST - Salvar benefícios
if ($method === 'POST') {
    $partner_id = (int)($input['partner_id'] ?? 0);
    
    if (!$partner_id) {
        echo json_encode(['success' => false, 'error' => 'partner_id obrigatório']);
        exit;
    }
    
    // Verificar se existe
    $check = $pdo->prepare("SELECT 1 FROM om_seller_benefits WHERE partner_id = ?");
    $check->execute([$partner_id]);
    
    $campos = [
        'installments_enabled', 'max_installments', 'installments_min_value', 'installments_fee_type',
        'free_shipping_enabled', 'free_shipping_min_value', 'shipping_discount_percent',
        'first_purchase_discount', 'pix_discount', 'boleto_discount',
        'cashback_enabled', 'cashback_percent', 'cashback_max_value',
        'extended_warranty_days', 'satisfaction_guarantee', 'free_return_days',
        'gift_enabled', 'gift_min_value', 'gift_description', 'express_shipping_free'
    ];
    
    $sets = [];
    $values = [];
    
    foreach ($campos as $campo) {
        if (isset($input[$campo])) {
            $sets[] = "$campo = ?";
            $values[] = $input[$campo];
        }
    }
    
    if ($check->fetch()) {
        $values[] = $partner_id;
        $sql = "UPDATE om_seller_benefits SET " . implode(', ', $sets) . " WHERE partner_id = ?";
    } else {
        $insertCampos = ['partner_id'];
        $insertValues = [$partner_id];
        foreach ($campos as $campo) {
            if (isset($input[$campo])) {
                $insertCampos[] = $campo;
                $insertValues[] = $input[$campo];
            }
        }
        $sql = "INSERT INTO om_seller_benefits (" . implode(', ', $insertCampos) . ") VALUES (" . implode(', ', array_fill(0, count($insertCampos), '?')) . ")";
        $values = $insertValues;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    
    echo json_encode(['success' => true, 'message' => 'Benefícios salvos']);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Método não suportado']);