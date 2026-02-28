<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * GET /api/mercado/vitrine/delivery-proof.php?order_id=X
 * Retorna comprovante de entrega para o cliente
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Query Parameters:
 * - order_id (obrigatorio): ID do pedido
 *
 * Retorna:
 * - delivery_type: "handed" | "left_at_door" | "reception"
 * - photo_url: URL da foto (se houver)
 * - pin_verified: boolean
 * - delivered_at: timestamp da entrega
 * - delivery_notes: notas do entregador
 * - shopper: nome do entregador
 *
 * SEGURANCA:
 * - Requer autenticacao JWT
 * - Verifica ownership do pedido via customer_id do token
 * - Rate limiting
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";

// Rate limiting: 60 requests por minuto
if (!RateLimiter::check(60, 60)) {
    exit;
}

try {
    $db = getDB();

    // ── AUTH REQUIRED ────────────────────────────────────────
    $customer_id = requireCustomerAuth();

    // Parametros
    $order_id = (int)($_GET["order_id"] ?? 0);

    if (!$order_id) {
        response(false, null, "order_id e obrigatorio", 400);
    }

    // Buscar pedido com dados de entrega
    $stmt = $db->prepare("
        SELECT
            o.order_id,
            o.order_number,
            o.customer_id,
            o.status,
            o.delivery_type,
            o.delivery_photo,
            o.delivery_notes,
            o.delivery_pin,
            o.pin_verified_at,
            o.photo_taken_at,
            o.delivered_at,
            o.delivery_lat,
            o.delivery_lng,
            o.delivery_address,
            o.shopper_id,
            s.nome as shopper_nome,
            s.foto as shopper_foto,
            s.rating as shopper_rating,
            p.trade_name as parceiro_nome,
            p.logo as parceiro_logo
        FROM om_market_orders o
        LEFT JOIN om_market_shoppers s ON o.shopper_id = s.shopper_id
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        response(false, null, "Pedido nao encontrado", 404);
    }

    // Verify ownership via authenticated customer_id (not from query param)
    if ((int)$pedido['customer_id'] !== $customer_id) {
        response(false, null, "Acesso negado a este pedido", 403);
    }

    // Verificar se pedido foi entregue
    if ($pedido['status'] !== 'entregue') {
        response(false, [
            "order_id" => $order_id,
            "status" => $pedido['status'],
            "delivery_proof_available" => false
        ], "Pedido ainda nao foi entregue", 400);
    }

    // Montar URL da foto
    $photo_url = null;
    if ($pedido['delivery_photo']) {
        $filename = basename($pedido['delivery_photo']);
        $photo_url = "/uploads/deliveries/" . $filename;

        // Verificar se arquivo existe
        $full_path = "/var/www/html/uploads/deliveries/" . $filename;
        if (!file_exists($full_path)) {
            $photo_url = null;
        }
    }

    // Labels amigaveis para tipo de entrega
    $delivery_type_labels = [
        'handed' => 'Entregue em maos',
        'left_at_door' => 'Deixado na porta',
        'reception' => 'Entregue na recepcao',
        'pickup' => 'Retirado pelo cliente'
    ];

    // Resposta
    $proof = [
        "order_id" => (int)$pedido['order_id'],
        "order_number" => $pedido['order_number'],
        "delivery_proof_available" => true,

        // Dados da entrega
        "delivery" => [
            "type" => $pedido['delivery_type'],
            "type_label" => $delivery_type_labels[$pedido['delivery_type']] ?? 'Entregue',
            "photo_url" => $photo_url,
            "has_photo" => !empty($photo_url),
            "notes" => $pedido['delivery_notes'],
            "pin_verified" => !empty($pedido['pin_verified_at']),
            "pin_verified_at" => $pedido['pin_verified_at'],
            "photo_taken_at" => $pedido['photo_taken_at'],
            "delivered_at" => $pedido['delivered_at'],
            "address" => $pedido['delivery_address'],
            "location" => [
                "lat" => $pedido['delivery_lat'] ? (float)$pedido['delivery_lat'] : null,
                "lng" => $pedido['delivery_lng'] ? (float)$pedido['delivery_lng'] : null
            ]
        ],

        // Dados do entregador
        "shopper" => $pedido['shopper_id'] ? [
            "id" => (int)$pedido['shopper_id'],
            "nome" => $pedido['shopper_nome'] ?? 'Entregador',
            "foto" => $pedido['shopper_foto'],
            "avaliacao" => $pedido['shopper_rating'] ? (float)$pedido['shopper_rating'] : null
        ] : null,

        // Dados do estabelecimento
        "parceiro" => [
            "nome" => $pedido['parceiro_nome'],
            "logo" => $pedido['parceiro_logo']
        ]
    ];

    response(true, $proof, "Comprovante de entrega carregado");

} catch (Exception $e) {
    error_log("[delivery-proof] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar comprovante", 500);
}
