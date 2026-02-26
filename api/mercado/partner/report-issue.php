<?php
/**
 * /api/mercado/partner/report-issue.php
 *
 * Partner issue reporting - report problems with shoppers, customers, orders, finances, platform.
 *
 * GET  ?action=list&status=X&category=X&page=1  - List partner's reported issues
 * GET  ?action=stats                              - Issue stats
 * POST { category, subcategory, order_id?, description, photo_urls? }  - Create issue
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'partner') response(false, null, "Acesso restrito a parceiros", 403);
    $partnerId = (int)$payload['uid'];

    // Table om_partner_issues created via migration

    // Subcategory rules
    $RULES = [
        // Shopper/Entregador
        'shopper_late'            => ['category' => 'shopper', 'severity' => 'medium', 'label' => 'Shopper atrasou muito para coletar'],
        'shopper_rude'            => ['category' => 'shopper', 'severity' => 'medium', 'label' => 'Shopper foi grosseiro'],
        'shopper_damaged'         => ['category' => 'shopper', 'severity' => 'high',   'label' => 'Shopper danificou pedido'],
        'shopper_no_show'         => ['category' => 'shopper', 'severity' => 'high',   'label' => 'Shopper nao apareceu'],
        'shopper_wrong_delivery'  => ['category' => 'shopper', 'severity' => 'high',   'label' => 'Entregou no endereco errado'],
        'shopper_ate_food'        => ['category' => 'shopper', 'severity' => 'critical','label' => 'Shopper mexeu/consumiu itens'],
        'shopper_fake_pickup'     => ['category' => 'shopper', 'severity' => 'critical','label' => 'Confirmou coleta sem coletar'],
        'shopper_multiple_orders' => ['category' => 'shopper', 'severity' => 'medium', 'label' => 'Priorizou outro pedido'],
        'shopper_unprofessional'  => ['category' => 'shopper', 'severity' => 'low',    'label' => 'Comportamento inadequado'],
        'shopper_contact'         => ['category' => 'shopper', 'severity' => 'medium', 'label' => 'Nao atende telefone/mensagem'],

        // Cliente
        'customer_fraud'          => ['category' => 'customer', 'severity' => 'critical','label' => 'Disputa fraudulenta'],
        'customer_abuse'          => ['category' => 'customer', 'severity' => 'high',   'label' => 'Abuso de reembolso'],
        'customer_no_show'        => ['category' => 'customer', 'severity' => 'medium', 'label' => 'Cliente ausente na entrega'],
        'customer_wrong_address'  => ['category' => 'customer', 'severity' => 'medium', 'label' => 'Endereco falso/errado'],
        'customer_rude'           => ['category' => 'customer', 'severity' => 'low',    'label' => 'Cliente grosseiro'],
        'customer_scam'           => ['category' => 'customer', 'severity' => 'critical','label' => 'Golpe (diz que nao recebeu)'],
        'customer_chargeback'     => ['category' => 'customer', 'severity' => 'critical','label' => 'Chargeback indevido'],
        'customer_fake_complaint' => ['category' => 'customer', 'severity' => 'high',   'label' => 'Reclamacao falsa'],

        // Pedido/Operacional
        'order_cancelled_late'       => ['category' => 'order', 'severity' => 'high',   'label' => 'Cancelamento tardio (ja preparando)'],
        'order_wrong_items_received' => ['category' => 'order', 'severity' => 'medium', 'label' => 'Recebeu itens errados do fornecedor'],
        'order_missing_items_stock'  => ['category' => 'order', 'severity' => 'medium', 'label' => 'Item sem estoque durante preparo'],
        'order_system_error'         => ['category' => 'order', 'severity' => 'high',   'label' => 'Erro no sistema ao receber pedido'],
        'order_duplicate'            => ['category' => 'order', 'severity' => 'medium', 'label' => 'Pedido duplicado'],
        'order_modification_late'    => ['category' => 'order', 'severity' => 'medium', 'label' => 'Modificacao depois de aceitar'],
        'order_high_volume'          => ['category' => 'order', 'severity' => 'low',    'label' => 'Volume alto demais'],
        'order_printer_issue'        => ['category' => 'order', 'severity' => 'medium', 'label' => 'Impressora nao recebeu pedido'],

        // Financeiro
        'financial_payment_delay'    => ['category' => 'financial', 'severity' => 'high',   'label' => 'Repasse atrasado'],
        'financial_wrong_amount'     => ['category' => 'financial', 'severity' => 'high',   'label' => 'Valor repassado incorreto'],
        'financial_commission_error' => ['category' => 'financial', 'severity' => 'high',   'label' => 'Comissao calculada errada'],
        'financial_tax_issue'        => ['category' => 'financial', 'severity' => 'medium', 'label' => 'Problema com nota fiscal'],
        'financial_promotion_loss'   => ['category' => 'financial', 'severity' => 'medium', 'label' => 'Promocao causou prejuizo'],
        'financial_refund_unfair'    => ['category' => 'financial', 'severity' => 'high',   'label' => 'Reembolso injusto debitado'],
        'financial_fee_unexpected'   => ['category' => 'financial', 'severity' => 'medium', 'label' => 'Taxa inesperada cobrada'],

        // Plataforma
        'platform_app_bug'           => ['category' => 'platform', 'severity' => 'medium', 'label' => 'Bug no app/painel'],
        'platform_menu_sync'         => ['category' => 'platform', 'severity' => 'high',   'label' => 'Cardapio nao sincroniza'],
        'platform_photos_issue'      => ['category' => 'platform', 'severity' => 'low',    'label' => 'Fotos dos produtos com problema'],
        'platform_reviews_unfair'    => ['category' => 'platform', 'severity' => 'medium', 'label' => 'Avaliacao injusta'],
        'platform_support_slow'      => ['category' => 'platform', 'severity' => 'low',    'label' => 'Suporte demora para responder'],
        'platform_visibility'        => ['category' => 'platform', 'severity' => 'high',   'label' => 'Loja nao aparece nas buscas'],
    ];

    $method = $_SERVER['REQUEST_METHOD'];

    // =================== GET ===================
    if ($method === 'GET') {
        $action = trim($_GET['action'] ?? 'list');

        if ($action === 'stats') {
            $stmt = $db->prepare("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN status IN ('resolved','closed') THEN 1 ELSE 0 END) as resolved
                FROM om_partner_issues
                WHERE partner_id = ?
            ");
            $stmt->execute([$partnerId]);
            $stats = $stmt->fetch();

            response(true, [
                'total' => (int)$stats['total'],
                'open' => (int)$stats['open'],
                'in_progress' => (int)$stats['in_progress'],
                'resolved' => (int)$stats['resolved'],
            ]);
        }

        // LIST
        $status = trim($_GET['status'] ?? '');
        $category = trim($_GET['category'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = "WHERE partner_id = ?";
        $params = [$partnerId];

        if ($status) {
            $where .= " AND status = ?";
            $params[] = $status;
        }
        if ($category) {
            $where .= " AND category = ?";
            $params[] = $category;
        }

        $stmtCount = $db->prepare("SELECT COUNT(*) as total FROM om_partner_issues {$where}");
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetch()['total'];

        $params[] = $limit;
        $params[] = $offset;

        $stmt = $db->prepare("
            SELECT * FROM om_partner_issues
            {$where}
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $issues = $stmt->fetchAll();

        $statusLabels = [
            'open' => 'Aberto', 'in_progress' => 'Em andamento',
            'resolved' => 'Resolvido', 'closed' => 'Fechado',
        ];

        $formatted = array_map(function($i) use ($statusLabels, $RULES) {
            $rule = $RULES[$i['subcategory']] ?? null;
            $photos = json_decode($i['photo_urls'] ?? '[]', true) ?: [];
            return [
                'id' => (int)$i['issue_id'],
                'order_id' => $i['order_id'] ? (int)$i['order_id'] : null,
                'category' => $i['category'],
                'subcategory' => $i['subcategory'],
                'label' => $rule['label'] ?? $i['subcategory'],
                'severity' => $i['severity'],
                'description' => $i['description'],
                'photo_urls' => $photos,
                'status' => $i['status'],
                'status_label' => $statusLabels[$i['status']] ?? $i['status'],
                'resolution_note' => $i['resolution_note'],
                'created_at' => $i['created_at'],
                'resolved_at' => $i['resolved_at'],
            ];
        }, $issues);

        response(true, [
            'issues' => $formatted,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $limit),
        ]);
    }

    // =================== POST: Create issue ===================
    if ($method === 'POST') {
        $input = getInput();
        $subcategory = trim($input['subcategory'] ?? '');
        $orderId = (int)($input['order_id'] ?? 0);
        $description = trim(substr($input['description'] ?? '', 0, 3000));
        $photoUrls = $input['photo_urls'] ?? [];

        if (!$subcategory || !isset($RULES[$subcategory])) {
            response(false, null, "Subcategoria invalida", 400);
        }

        $rule = $RULES[$subcategory];
        $category = $rule['category'];
        $severity = $rule['severity'];

        if (!$description || strlen($description) < 5) {
            response(false, null, "Descricao obrigatoria (min. 5 caracteres)", 400);
        }

        $description = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');

        // Validate order belongs to partner if provided
        if ($orderId) {
            $stmtOrder = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND partner_id = ?");
            $stmtOrder->execute([$orderId, $partnerId]);
            if (!$stmtOrder->fetch()) {
                $orderId = 0; // reset if invalid
            }
        }

        if (!is_array($photoUrls)) $photoUrls = [];
        $photoUrlsJson = json_encode(array_slice($photoUrls, 0, 5));

        $stmt = $db->prepare("
            INSERT INTO om_partner_issues (partner_id, order_id, category, subcategory, severity, description, photo_urls, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'open', NOW(), NOW())
        ");
        $stmt->execute([
            $partnerId,
            $orderId ?: null,
            $category,
            $subcategory,
            $severity,
            $description,
            $photoUrlsJson,
        ]);
        $issueId = (int)$db->lastInsertId();

        // Notify admin for critical issues
        if ($severity === 'critical') {
            try {
                $db->prepare("
                    INSERT INTO om_notifications (user_id, user_type, title, body, type, reference_type, reference_id, created_at)
                    VALUES (1, 'admin', ?, ?, 'partner_issue', 'partner_issue', ?, NOW())
                ")->execute([
                    "Problema critico do parceiro #{$partnerId}",
                    "Tipo: {$rule['label']}. " . ($orderId ? "Pedido #{$orderId}" : "Sem pedido associado"),
                    $issueId,
                ]);
            } catch (Exception $e) {
                error_log("[partner/report-issue] Admin notification failed: " . $e->getMessage());
            }
        }

        response(true, [
            'id' => $issueId,
            'category' => $category,
            'subcategory' => $subcategory,
            'severity' => $severity,
            'status' => 'open',
        ], "Problema reportado com sucesso. ID: #{$issueId}");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/report-issue] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar", 500);
}
