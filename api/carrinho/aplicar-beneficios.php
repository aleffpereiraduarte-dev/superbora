<?php
/**
 * API Aplicar Benefícios ao Carrinho - OneMundo
 *
 * POST /api/carrinho/aplicar-beneficios.php
 * {
 *   "customer_id": 123,
 *   "subtotal": 150.00,
 *   "shipping": 25.00
 * }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

try {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../database.php';

    $input = json_decode(file_get_contents('php://input'), true);

    $customerId = (int)($input['customer_id'] ?? 0);
    $subtotal = (float)($input['subtotal'] ?? 0);
    $shipping = (float)($input['shipping'] ?? 0);

    if (!$customerId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'customer_id é obrigatório']);
        exit;
    }

    $pdo = getConnection();

    // Buscar membership do cliente com colunas corretas
    $stmt = $pdo->prepare("
        SELECT m.*, l.slug as level_code, l.name as level_name, l.shipping_discount,
               l.free_shipping_qty, l.discount_percent
        FROM om_membership_members m
        JOIN om_membership_levels l ON m.level_id = l.level_id
        WHERE m.customer_id = ? AND m.status = 'active'
    ");
    $stmt->execute([$customerId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    $benefits = [
        'is_member' => false,
        'level' => null,
        'shipping_discount' => 0,
        'shipping_discount_amount' => 0,
        'free_shipping' => false,
        'points_earned' => 0,
        'points_multiplier' => 1,
        'total_discount' => 0
    ];

    if (!$member) {
        // Não é membro - sem benefícios
        echo json_encode([
            'success' => true,
            'benefits' => $benefits,
            'totals' => [
                'subtotal' => $subtotal,
                'shipping' => $shipping,
                'shipping_final' => $shipping,
                'total' => $subtotal + $shipping
            ],
            'cta' => [
                'message' => 'Assine o Membership e ganhe até 100% de desconto no frete!',
                'savings' => $shipping,
                'link' => '/membership'
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // É membro - calcular benefícios
    $benefits['is_member'] = true;
    $benefits['level'] = $member['level_code'];
    $benefits['level_name'] = $member['level_name'];

    // Calcular multiplicador de pontos (50% desconto = 1.5x pontos)
    $pointsMultiplier = 1 + ((int)$member['discount_percent'] / 100);
    $benefits['points_multiplier'] = $pointsMultiplier;

    // Verificar uso de frete grátis no mês
    $freteUsado = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM om_membership_shipping_usage
            WHERE member_id = ?
            AND MONTH(used_at) = MONTH(CURRENT_DATE())
            AND YEAR(used_at) = YEAR(CURRENT_DATE())
        ");
        $stmt->execute([$member['member_id']]);
        $freteUsado = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        // Tabela pode não existir, usa valor padrão
    }

    $limiteFreteGratis = (int)$member['free_shipping_qty'];
    // 999999 = ilimitado
    $temFreteGratisDisponivel = ($limiteFreteGratis >= 999999) || ($freteUsado < $limiteFreteGratis && $limiteFreteGratis > 0);

    $shippingFinal = $shipping;
    $shippingDiscount = 0;

    if ($shipping > 0) {
        if ($temFreteGratisDisponivel && $limiteFreteGratis > 0) {
            // Frete grátis!
            $benefits['free_shipping'] = true;
            $shippingDiscount = $shipping;
            $shippingFinal = 0;
        } else {
            // Desconto no frete
            $discountPercent = (float)$member['shipping_discount'];
            $shippingDiscount = $shipping * ($discountPercent / 100);
            $shippingFinal = $shipping - $shippingDiscount;
            $benefits['shipping_discount'] = $discountPercent;
        }
    }

    $benefits['shipping_discount_amount'] = round($shippingDiscount, 2);
    $benefits['total_discount'] = round($shippingDiscount, 2);

    // Calcular pontos a ganhar
    $pointsBase = floor($subtotal);
    $benefits['points_earned'] = (int)($pointsBase * $benefits['points_multiplier']);

    echo json_encode([
        'success' => true,
        'benefits' => $benefits,
        'totals' => [
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'shipping_discount' => round($shippingDiscount, 2),
            'shipping_final' => round($shippingFinal, 2),
            'total' => round($subtotal + $shippingFinal, 2)
        ],
        'usage' => [
            'free_shipping_used' => $freteUsado,
            'free_shipping_limit' => $limiteFreteGratis,
            'free_shipping_available' => $temFreteGratisDisponivel
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Aplicar Beneficios Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao aplicar benefícios']);
}
