<?php
/**
 * API Cupom do Mercado - OneMundo
 *
 * POST /mercado/api/coupon.php
 * { "code": "DESCONTO10", "partner_id": 1, "subtotal": 50.00 }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

try {
    require_once __DIR__ . '/../config.php';

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $code = strtoupper(trim($input['code'] ?? ''));
    $partnerId = (int)($input['partner_id'] ?? 0);
    $subtotal = (float)($input['subtotal'] ?? 0);
    $customerId = (int)($input['customer_id'] ?? 0);

    if (empty($code)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'valid' => false, 'error' => 'Código do cupom é obrigatório']);
        exit;
    }

    $db = getDB();

    // Buscar cupom do mercado
    $stmt = $db->prepare("
        SELECT * FROM om_market_coupons
        WHERE UPPER(code) = ? AND status = 'active'
    ");
    $stmt->execute([$code]);
    $coupon = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$coupon) {
        echo json_encode(['success' => true, 'valid' => false, 'error' => 'Cupom não encontrado ou inativo']);
        exit;
    }

    // Verificar se é cupom do parceiro específico
    if ($coupon['partner_id'] && $coupon['partner_id'] != $partnerId) {
        echo json_encode(['success' => true, 'valid' => false, 'error' => 'Cupom não válido para este parceiro']);
        exit;
    }

    // Verificar datas
    $now = date('Y-m-d H:i:s');
    if ($coupon['start_date'] && $now < $coupon['start_date']) {
        echo json_encode(['success' => true, 'valid' => false, 'error' => 'Cupom ainda não está ativo']);
        exit;
    }
    if ($coupon['end_date'] && $now > $coupon['end_date']) {
        echo json_encode(['success' => true, 'valid' => false, 'error' => 'Cupom expirado']);
        exit;
    }

    // Verificar limite de uso
    if ($coupon['max_uses'] > 0 && $coupon['used_count'] >= $coupon['max_uses']) {
        echo json_encode(['success' => true, 'valid' => false, 'error' => 'Cupom esgotado']);
        exit;
    }

    // Verificar valor mínimo
    if ($subtotal > 0 && $subtotal < $coupon['min_order']) {
        echo json_encode([
            'success' => true,
            'valid' => false,
            'error' => 'Pedido mínimo: R$ ' . number_format($coupon['min_order'], 2, ',', '.')
        ]);
        exit;
    }

    // Calcular desconto
    $discount = 0;
    if ($coupon['discount_type'] === 'percentage') {
        $discount = $subtotal * ($coupon['discount_value'] / 100);
        if ($coupon['max_discount'] > 0) {
            $discount = min($discount, $coupon['max_discount']);
        }
    } else {
        $discount = $coupon['discount_value'];
    }

    echo json_encode([
        'success' => true,
        'valid' => true,
        'coupon' => [
            'code' => $coupon['code'],
            'description' => $coupon['description'],
            'type' => $coupon['discount_type'],
            'discount_value' => (float)$coupon['discount_value'],
            'discount_calculated' => round($discount, 2),
            'free_delivery' => (bool)$coupon['free_delivery'],
            'min_order' => (float)$coupon['min_order']
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Mercado Coupon Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'valid' => false, 'error' => 'Erro ao validar cupom']);
}
