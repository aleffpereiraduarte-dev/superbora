<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * Support FAQ API
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * GET /api/mercado/vitrine/support-faq.php
 *   Lista FAQs para o chatbot
 *   Query params: ?category=pedido|entrega|reembolso|pagamento|conta|fidelidade|cupom
 *
 * GET /api/mercado/vitrine/support-faq.php?search=termo
 *   Busca FAQs por termo
 */

require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

try {
    $db = getDB();

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method !== 'GET') {
        response(false, null, "Metodo nao suportado", 405);
    }

    $category = $_GET['category'] ?? null;
    $search = trim($_GET['search'] ?? '');
    $limit = min((int)($_GET['limit'] ?? 20), 50);

    // ═══════════════════════════════════════════════════════════════════
    // BUSCA POR TERMO
    // ═══════════════════════════════════════════════════════════════════
    if ($search && strlen($search) >= 2) {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
        $searchTerm = '%' . $escaped . '%';

        $limit = (int)$limit;
        $stmt = $db->prepare("
            SELECT id, categoria as category, pergunta as question, resposta as answer
            FROM om_support_faq
            WHERE ativo::text = '1'
              AND (pergunta LIKE ? OR resposta LIKE ?)
            ORDER BY ordem ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(2, $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $faqs = $stmt->fetchAll();

        response(true, [
            'faqs' => array_map(function($faq) {
                return [
                    'id' => (int)$faq['id'],
                    'category' => $faq['category'],
                    'category_label' => getCategoryLabel($faq['category']),
                    'question' => $faq['question'],
                    'answer' => $faq['answer']
                ];
            }, $faqs),
            'total' => count($faqs)
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // LISTAR POR CATEGORIA OU TODAS
    // ═══════════════════════════════════════════════════════════════════
    $sql = "
        SELECT id, categoria as category, pergunta as question, resposta as answer
        FROM om_support_faq
        WHERE ativo::text = '1'
    ";
    $params = [];

    if ($category) {
        $sql .= " AND categoria = ?";
        $params[] = $category;
    }

    $limit = (int)$limit;
    $sql .= " ORDER BY ordem ASC, categoria, pergunta LIMIT ?";
    $params[] = $limit;

    $stmt = $db->prepare($sql);
    // Bind params with proper types
    foreach ($params as $idx => $param) {
        $type = is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($idx + 1, $param, $type);
    }
    $stmt->execute();
    $faqs = $stmt->fetchAll();

    // Agrupar por categoria se nao filtrou
    if (!$category) {
        $grouped = [];
        foreach ($faqs as $faq) {
            $cat = $faq['category'];
            if (!isset($grouped[$cat])) {
                $grouped[$cat] = [
                    'category' => $cat,
                    'category_label' => getCategoryLabel($cat),
                    'items' => []
                ];
            }
            $grouped[$cat]['items'][] = [
                'id' => (int)$faq['id'],
                'question' => $faq['question'],
                'answer' => $faq['answer']
            ];
        }

        response(true, [
            'categories' => array_values($grouped),
            'quick_topics' => getQuickTopics()
        ]);
    }

    // Retorno plano para categoria especifica
    response(true, [
        'category' => $category,
        'category_label' => getCategoryLabel($category),
        'faqs' => array_map(function($faq) {
            return [
                'id' => (int)$faq['id'],
                'question' => $faq['question'],
                'answer' => $faq['answer']
            ];
        }, $faqs)
    ]);

} catch (Exception $e) {
    error_log("[vitrine/support-faq] Erro: " . $e->getMessage());
    response(false, null, "Erro interno do servidor", 500);
}

// ═══════════════════════════════════════════════════════════════════
// FUNCOES AUXILIARES
// ═══════════════════════════════════════════════════════════════════

function getCategoryLabel(string $category): string {
    return [
        'pedido' => 'Pedidos',
        'entrega' => 'Entrega',
        'reembolso' => 'Reembolso',
        'pagamento' => 'Pagamento',
        'conta' => 'Minha Conta',
        'fidelidade' => 'Pontos e Fidelidade',
        'cupom' => 'Cupons'
    ][$category] ?? ucfirst($category);
}

function getQuickTopics(): array {
    return [
        [
            'id' => 'order_status',
            'label' => 'Onde esta meu pedido?',
            'icon' => 'package',
            'message' => 'Quero saber onde esta meu pedido'
        ],
        [
            'id' => 'refund',
            'label' => 'Pedir reembolso',
            'icon' => 'credit-card',
            'message' => 'Quero solicitar reembolso'
        ],
        [
            'id' => 'cancel',
            'label' => 'Cancelar pedido',
            'icon' => 'x-circle',
            'message' => 'Quero cancelar meu pedido'
        ],
        [
            'id' => 'delivery_late',
            'label' => 'Pedido atrasado',
            'icon' => 'clock',
            'message' => 'Meu pedido esta atrasado'
        ],
        [
            'id' => 'wrong_order',
            'label' => 'Pedido errado',
            'icon' => 'alert-triangle',
            'message' => 'Recebi o pedido errado ou com problema'
        ],
        [
            'id' => 'other',
            'label' => 'Outra duvida',
            'icon' => 'help-circle',
            'message' => null
        ]
    ];
}
