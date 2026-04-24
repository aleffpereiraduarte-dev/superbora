<?php
/**
 * GET /api/mercado/partner/prescriptions.php
 * Lista pedidos do partner autenticado com prescription_status = 'pending'.
 *
 * Resposta:
 *   {
 *     items: [
 *       {
 *         order_id: 123,
 *         order_number: "SB-260424-ABCD",
 *         customer_name: "Maria Silva",
 *         customer_phone: "+5511...",
 *         total: 89.90,
 *         created_at: "2026-04-24T14:30:00-03:00",
 *         prescription_image_url: "/uploads/prescriptions/...",
 *         items: [{ product_id, name, quantity, price }]
 *       }
 *     ],
 *     pending_count: 3
 *   }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload    = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];

    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        response(false, null, "Método não permitido", 405);
    }

    // Buscar pedidos pendentes do parceiro
    $stmt = $db->prepare("
        SELECT
            o.order_id,
            o.order_number,
            o.customer_id,
            o.customer_name,
            o.customer_phone,
            o.total,
            o.date_added AS created_at,
            o.prescription_image_url,
            o.prescription_status,
            o.status,
            o.notes
        FROM om_market_orders o
        WHERE o.partner_id = ?
          AND o.prescription_status = 'pending'
        ORDER BY o.date_added ASC
    ");
    $stmt->execute([$partner_id]);
    $orders = $stmt->fetchAll();

    // Buscar itens de cada pedido (1 query só com IN, não N+1)
    $items_by_order = [];
    if (!empty($orders)) {
        $orderIds = array_map(fn($o) => (int)$o['order_id'], $orders);
        $in = implode(',', array_fill(0, count($orderIds), '?'));
        $stmtI = $db->prepare("
            SELECT order_id, product_id, name, quantity, price, total
            FROM om_market_order_items
            WHERE order_id IN ($in)
            ORDER BY order_id, order_item_id
        ");
        $stmtI->execute($orderIds);
        foreach ($stmtI->fetchAll() as $it) {
            $oid = (int)$it['order_id'];
            if (!isset($items_by_order[$oid])) $items_by_order[$oid] = [];
            $items_by_order[$oid][] = [
                'product_id' => (int)$it['product_id'],
                'name'       => $it['name'],
                'quantity'   => (int)$it['quantity'],
                'price'      => (float)$it['price'],
                'total'      => (float)$it['total'],
            ];
        }
    }

    $result = [];
    foreach ($orders as $o) {
        $oid = (int)$o['order_id'];
        $result[] = [
            'order_id'               => $oid,
            'order_number'           => $o['order_number'],
            'customer_id'            => (int)$o['customer_id'],
            'customer_name'          => $o['customer_name'],
            'customer_phone'         => $o['customer_phone'],
            'total'                  => (float)$o['total'],
            'created_at'             => $o['created_at'],
            'prescription_image_url' => $o['prescription_image_url'],
            'prescription_status'    => $o['prescription_status'],
            'status'                 => $o['status'],
            'notes'                  => $o['notes'],
            'items'                  => $items_by_order[$oid] ?? [],
            'items_count'            => count($items_by_order[$oid] ?? []),
        ];
    }

    response(true, [
        'items'         => $result,
        'pending_count' => count($result),
    ]);

} catch (Exception $e) {
    error_log("[partner/prescriptions] Erro: " . $e->getMessage());
    response(false, null, "Erro ao listar receitas", 500);
}
