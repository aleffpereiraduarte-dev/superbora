<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * GET /api/mercado/shopper/status.php
 * Retorna status atual do shopper e pedido ativo
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REQUER: Autenticação de Shopper
 * Header: Authorization: Bearer <token>
 *
 * SEGURANÇA:
 * - ✅ Autenticação obrigatória
 * - ✅ Prepared statements
 */

require_once __DIR__ . "/../config/auth.php";

try {
    $db = getDB();

    // ═══════════════════════════════════════════════════════════════════
    // AUTENTICAÇÃO
    // ═══════════════════════════════════════════════════════════════════
    $auth = requireShopperAuth();
    $shopper_id = $auth['uid'];

    // Buscar dados do shopper
    $stmt = $db->prepare("
        SELECT shopper_id, name, email, phone, photo, status, disponivel,
               pedido_atual_id, latitude, longitude, ultima_atividade,
               data_aprovacao, rating, total_entregas
        FROM om_market_shoppers
        WHERE shopper_id = ?
    ");
    $stmt->execute([$shopper_id]);
    $shopper = $stmt->fetch();

    if (!$shopper) {
        response(false, null, "Shopper não encontrado", 404);
    }

    // Verificar status de aprovação RH
    $statusInfo = om_auth()->getShopperStatus($shopper_id);

    // Buscar pedido ativo se houver
    $pedido_ativo = null;
    if ($shopper['pedido_atual_id']) {
        $stmt = $db->prepare("
            SELECT o.*, p.name as parceiro_nome, p.address as parceiro_endereco, p.logo as parceiro_logo,
                   (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as total_itens,
                   (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id AND collected = 1) as itens_coletados
            FROM om_market_orders o
            INNER JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.order_id = ?
        ");
        $stmt->execute([$shopper['pedido_atual_id']]);
        $pedido = $stmt->fetch();

        if ($pedido) {
            $pedido_ativo = [
                "order_id" => $pedido['order_id'],
                "status" => $pedido['status'],
                "status_label" => om_status()->getOrderStatusLabel($pedido['status']),
                "parceiro" => [
                    "nome" => $pedido['parceiro_nome'],
                    "endereco" => $pedido['parceiro_endereco'],
                    "logo" => $pedido['parceiro_logo']
                ],
                "endereco_entrega" => $pedido['delivery_address'],
                "codigo_entrega" => $pedido['codigo_entrega'],
                "valor_total" => floatval($pedido['total']),
                "progresso_coleta" => [
                    "total" => (int)$pedido['total_itens'],
                    "coletados" => (int)$pedido['itens_coletados'],
                    "porcentagem" => $pedido['total_itens'] > 0
                        ? round(($pedido['itens_coletados'] / $pedido['total_itens']) * 100)
                        : 0
                ]
            ];
        }
    }

    // Buscar saldo resumido
    $stmt = $db->prepare("SELECT saldo_disponivel FROM om_shopper_saldo WHERE shopper_id = ?");
    $stmt->execute([$shopper_id]);
    $saldo = floatval($stmt->fetchColumn() ?: 0);

    response(true, [
        "shopper" => [
            "id" => $shopper['shopper_id'],
            "nome" => $shopper['name'],
            "email" => $shopper['email'],
            "telefone" => $shopper['phone'],
            "foto" => $shopper['photo'],
            "disponivel" => (bool)$shopper['disponivel'],
            "rating" => floatval($shopper['rating'] ?? 5.0),
            "total_entregas" => (int)($shopper['total_entregas'] ?? 0)
        ],
        "aprovacao_rh" => $statusInfo,
        "saldo_disponivel" => $saldo,
        "saldo_formatado" => "R$ " . number_format($saldo, 2, ',', '.'),
        "pedido_ativo" => $pedido_ativo,
        "localizacao" => [
            "latitude" => $shopper['latitude'] ? floatval($shopper['latitude']) : null,
            "longitude" => $shopper['longitude'] ? floatval($shopper['longitude']) : null,
            "ultima_atualizacao" => $shopper['ultima_atividade']
        ]
    ]);

} catch (Exception $e) {
    error_log("[status] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar status. Tente novamente.", 500);
}
