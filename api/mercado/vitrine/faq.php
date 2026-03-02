<?php
/**
 * GET /api/mercado/vitrine/faq.php
 * Public endpoint — returns FAQ entries grouped by category for the mobile app.
 * Filters to customer-facing FAQs (entidade_tipo = 'cliente' or IS NULL).
 * No auth required.
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

try {
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, "Metodo nao suportado", 405);
    }

    $stmt = $db->prepare("
        SELECT id, categoria, pergunta, resposta
        FROM om_support_faq
        WHERE ativo = 1
          AND (entidade_tipo = 'cliente' OR entidade_tipo IS NULL)
        ORDER BY categoria, ordem ASC
    ");
    $stmt->execute();
    $faqs = $stmt->fetchAll();

    // Icon mapping for categories
    $iconMap = [
        'pedidos'    => 'cart',
        'pedido'     => 'cart',
        'entrega'    => 'bicycle',
        'pagamento'  => 'card',
        'pagamentos' => 'card',
        'conta'      => 'person',
        'reembolso'  => 'card',
        'fidelidade' => 'star',
        'cupom'      => 'ticket',
        'geral'      => 'help-circle',
    ];

    // Label mapping for categories
    $labelMap = [
        'pedidos'    => 'Pedidos',
        'pedido'     => 'Pedidos',
        'entrega'    => 'Entrega',
        'pagamento'  => 'Pagamento',
        'pagamentos' => 'Pagamento',
        'conta'      => 'Minha Conta',
        'reembolso'  => 'Reembolso',
        'fidelidade' => 'Fidelidade',
        'cupom'      => 'Cupons',
        'geral'      => 'Geral',
    ];

    // Group by category
    $grouped = [];
    foreach ($faqs as $faq) {
        $cat = $faq['categoria'];
        if (!isset($grouped[$cat])) {
            $grouped[$cat] = [
                'name' => $labelMap[$cat] ?? ucfirst($cat),
                'icon' => $iconMap[$cat] ?? 'help-circle',
                'category' => $cat,
                'items' => [],
            ];
        }
        $grouped[$cat]['items'][] = [
            'id' => (int)$faq['id'],
            'question' => $faq['pergunta'],
            'answer' => $faq['resposta'],
        ];
    }

    response(true, [
        'categories' => array_values($grouped),
    ]);

} catch (Exception $e) {
    error_log("[vitrine/faq] Erro: " . $e->getMessage());
    response(false, null, "Erro interno do servidor", 500);
}
