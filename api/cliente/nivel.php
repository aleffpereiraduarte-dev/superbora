<?php
/**
 * API Nível do Cliente - OneMundo
 * Retorna informações sobre o nível/membership do cliente
 *
 * GET /api/cliente/nivel.php?customer_id=123
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../database.php';

    $customerId = (int)($_GET['customer_id'] ?? 0);

    if (!$customerId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'customer_id é obrigatório']);
        exit;
    }

    $pdo = getConnection();

    // Buscar dados do membership com colunas corretas
    $stmt = $pdo->prepare("
        SELECT m.*, l.slug as level_code, l.name as level_name, l.color, l.color_primary, l.icon,
               l.shipping_discount, l.free_shipping_qty, l.support_priority, l.discount_percent
        FROM om_membership_members m
        JOIN om_membership_levels l ON m.level_id = l.level_id
        WHERE m.customer_id = ? AND m.status = 'active'
    ");
    $stmt->execute([$customerId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        // Cliente não é membro - retornar nível básico
        echo json_encode([
            'success' => true,
            'is_member' => false,
            'level' => [
                'code' => 'BASIC',
                'name' => 'Básico',
                'color' => '#666666',
                'icon' => '⭐'
            ],
            'benefits' => [
                'shipping_discount' => 0,
                'free_shipping_limit' => 0,
                'points_multiplier' => 1,
                'priority_support' => 'basic'
            ],
            'progress' => [
                'current_points' => 0,
                'next_level' => 'BRONZE',
                'points_to_next' => 100
            ],
            'cta' => [
                'message' => 'Assine o Membership e ganhe 50% de desconto no frete!',
                'link' => '/membership'
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Contar uso de frete grátis no mês
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
        // Tabela pode não existir, ignora
    }

    // Buscar próximo nível
    $stmt = $pdo->prepare("
        SELECT slug as level_code, name as level_name, min_points
        FROM om_membership_levels
        WHERE min_points > ?
        ORDER BY min_points ASC
        LIMIT 1
    ");
    $stmt->execute([$member['annual_points']]);
    $nextLevel = $stmt->fetch(PDO::FETCH_ASSOC);

    $freeShippingQty = (int)$member['free_shipping_qty'];
    $freteDisponivel = $freeShippingQty >= 999999
        ? 'unlimited'
        : max(0, $freeShippingQty - $freteUsado);

    // Calcular multiplicador de pontos baseado no desconto (ex: 50% = 1.5x, 100% = 2x)
    $pointsMultiplier = 1 + ((int)$member['discount_percent'] / 100);

    echo json_encode([
        'success' => true,
        'is_member' => true,
        'member_since' => $member['subscription_start'] ?? $member['created_at'],
        'expires_at' => $member['subscription_end'],
        'level' => [
            'code' => $member['level_code'],
            'name' => $member['level_name'],
            'color' => $member['color'] ?? $member['color_primary'],
            'icon' => $member['icon']
        ],
        'benefits' => [
            'shipping_discount' => (float)$member['shipping_discount'],
            'free_shipping_limit' => $freeShippingQty,
            'free_shipping_used' => $freteUsado,
            'free_shipping_available' => $freteDisponivel,
            'points_multiplier' => $pointsMultiplier,
            'priority_support' => $member['support_priority']
        ],
        'points' => [
            'total_miles' => (float)$member['total_miles'],
            'annual_points' => (int)$member['annual_points']
        ],
        'progress' => $nextLevel ? [
            'next_level' => $nextLevel['level_code'],
            'next_level_name' => $nextLevel['level_name'],
            'points_needed' => (int)$nextLevel['min_points'],
            'points_to_go' => (int)$nextLevel['min_points'] - (int)$member['annual_points'],
            'percentage' => min(100, round(($member['annual_points'] / max(1, $nextLevel['min_points'])) * 100))
        ] : [
            'next_level' => null,
            'message' => 'Você está no nível máximo!'
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Cliente Nivel Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao buscar nível']);
}
