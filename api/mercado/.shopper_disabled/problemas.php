<?php
/**
 * /api/mercado/shopper/problemas.php
 *
 * Shopper problem reporting - 7 categories, 52 subcategories.
 *
 * GET  ?action=list&status=X&category=X&page=1  - List shopper's reported problems
 * GET  ?action=stats                              - Problem stats
 * POST { subcategory, order_id?, description, photo_urls? }  - Create problem report
 */
require_once __DIR__ . "/../config/auth.php";

try {
    $db = getDB();
    $auth = requireShopperAuth();
    $shopper_id = $auth["uid"];

    // Ensure columns exist
    $db->exec("ALTER TABLE om_order_problems ADD COLUMN IF NOT EXISTS category VARCHAR(50)");
    $db->exec("ALTER TABLE om_order_problems ADD COLUMN IF NOT EXISTS subcategory VARCHAR(80)");
    $db->exec("ALTER TABLE om_order_problems ADD COLUMN IF NOT EXISTS severity VARCHAR(20) DEFAULT 'medium'");
    $db->exec("ALTER TABLE om_order_problems ADD COLUMN IF NOT EXISTS photo_urls JSONB DEFAULT '[]'");
    $db->exec("ALTER TABLE om_order_problems ADD COLUMN IF NOT EXISTS resolution_note TEXT");
    $db->exec("ALTER TABLE om_order_problems ADD COLUMN IF NOT EXISTS resolved_at TIMESTAMP");

    // Subcategory rules (7 categories, 52 subcategories)
    $RULES = [
        // Coleta na Loja (9)
        'pickup_store_closed'   => ['category' => 'pickup',   'severity' => 'high',    'label' => 'Loja fechada ao chegar'],
        'pickup_long_wait'      => ['category' => 'pickup',   'severity' => 'medium',  'label' => 'Espera muito longa (>20min)'],
        'pickup_order_not_ready'=> ['category' => 'pickup',   'severity' => 'medium',  'label' => 'Pedido nao estava pronto'],
        'pickup_wrong_items'    => ['category' => 'pickup',   'severity' => 'high',    'label' => 'Loja entregou itens errados'],
        'pickup_missing_items'  => ['category' => 'pickup',   'severity' => 'high',    'label' => 'Itens faltando no pacote'],
        'pickup_store_rude'     => ['category' => 'pickup',   'severity' => 'low',     'label' => 'Funcionario grosseiro'],
        'pickup_packaging_bad'  => ['category' => 'pickup',   'severity' => 'medium',  'label' => 'Embalagem inadequada'],
        'pickup_store_not_found'=> ['category' => 'pickup',   'severity' => 'medium',  'label' => 'Nao encontrou a loja'],
        'pickup_store_refused'  => ['category' => 'pickup',   'severity' => 'high',    'label' => 'Loja recusou entregar'],

        // Entrega ao Cliente (8)
        'delivery_address_wrong'      => ['category' => 'delivery', 'severity' => 'high',   'label' => 'Endereco incorreto/inexistente'],
        'delivery_customer_absent'    => ['category' => 'delivery', 'severity' => 'medium', 'label' => 'Cliente ausente'],
        'delivery_no_access'          => ['category' => 'delivery', 'severity' => 'medium', 'label' => 'Sem acesso ao local (portaria)'],
        'delivery_customer_rude'      => ['category' => 'delivery', 'severity' => 'medium', 'label' => 'Cliente grosseiro/agressivo'],
        'delivery_refused'            => ['category' => 'delivery', 'severity' => 'high',   'label' => 'Cliente recusou pedido'],
        'delivery_unsafe_area'        => ['category' => 'delivery', 'severity' => 'high',   'label' => 'Area insegura'],
        'delivery_long_distance'      => ['category' => 'delivery', 'severity' => 'medium', 'label' => 'Distancia muito maior que indicada'],
        'delivery_instructions_unclear'=> ['category' => 'delivery','severity' => 'low',    'label' => 'Instrucoes confusas'],

        // Veiculo/Equipamento (6)
        'vehicle_breakdown'     => ['category' => 'vehicle',  'severity' => 'high',    'label' => 'Veiculo quebrou'],
        'vehicle_flat_tire'     => ['category' => 'vehicle',  'severity' => 'high',    'label' => 'Pneu furou'],
        'vehicle_accident'      => ['category' => 'vehicle',  'severity' => 'critical','label' => 'Acidente de transito'],
        'vehicle_theft'         => ['category' => 'vehicle',  'severity' => 'critical','label' => 'Roubo/furto do veiculo'],
        'equipment_bag_damaged' => ['category' => 'vehicle',  'severity' => 'medium',  'label' => 'Bag termica danificada'],
        'equipment_phone_issue' => ['category' => 'vehicle',  'severity' => 'medium',  'label' => 'Celular com problema'],

        // Financeiro (8)
        'financial_wrong_payment'  => ['category' => 'financial','severity' => 'high',   'label' => 'Valor recebido incorreto'],
        'financial_no_payment'     => ['category' => 'financial','severity' => 'critical','label' => 'Nao recebeu pagamento'],
        'financial_bonus_missing'  => ['category' => 'financial','severity' => 'medium', 'label' => 'Bonus nao creditado'],
        'financial_tip_missing'    => ['category' => 'financial','severity' => 'medium', 'label' => 'Gorjeta nao recebida'],
        'financial_fuel_cost'      => ['category' => 'financial','severity' => 'low',    'label' => 'Custo de combustivel alto demais'],
        'financial_toll_not_paid'  => ['category' => 'financial','severity' => 'medium', 'label' => 'Pedagio nao reembolsado'],
        'financial_tax_help'       => ['category' => 'financial','severity' => 'low',    'label' => 'Duvida sobre imposto'],
        'financial_deduction_unfair'=> ['category' => 'financial','severity' => 'high',  'label' => 'Desconto injusto'],

        // App/Plataforma (7)
        'app_crash'             => ['category' => 'app',      'severity' => 'high',    'label' => 'App travou/fechou'],
        'app_gps_wrong'         => ['category' => 'app',      'severity' => 'high',    'label' => 'GPS com localizacao errada'],
        'app_notifications'     => ['category' => 'app',      'severity' => 'medium',  'label' => 'Nao recebe notificacoes'],
        'app_order_disappeared' => ['category' => 'app',      'severity' => 'critical','label' => 'Pedido sumiu do app'],
        'app_cant_confirm'      => ['category' => 'app',      'severity' => 'high',    'label' => 'Nao consegue confirmar entrega'],
        'app_chat_broken'       => ['category' => 'app',      'severity' => 'medium',  'label' => 'Chat nao funciona'],
        'app_login_issue'       => ['category' => 'app',      'severity' => 'high',    'label' => 'Problema para fazer login'],

        // Seguranca (7)
        'safety_assault'        => ['category' => 'safety',   'severity' => 'critical','label' => 'Assalto/agressao'],
        'safety_harassment'     => ['category' => 'safety',   'severity' => 'critical','label' => 'Assedio'],
        'safety_theft_of_delivery'=> ['category' => 'safety', 'severity' => 'critical','label' => 'Roubo da entrega'],
        'safety_dog_attack'     => ['category' => 'safety',   'severity' => 'high',    'label' => 'Ataque de animal'],
        'safety_weather_extreme'=> ['category' => 'safety',   'severity' => 'medium',  'label' => 'Condicao climatica extrema'],
        'safety_traffic_incident'=> ['category' => 'safety',  'severity' => 'high',    'label' => 'Incidente no transito'],
        'safety_threats'        => ['category' => 'safety',   'severity' => 'critical','label' => 'Ameacas'],

        // Conta/Suporte (7)
        'account_deactivated'   => ['category' => 'account',  'severity' => 'critical','label' => 'Conta desativada sem motivo'],
        'account_documents'     => ['category' => 'account',  'severity' => 'medium',  'label' => 'Problema com documentos'],
        'account_rating_unfair' => ['category' => 'account',  'severity' => 'medium',  'label' => 'Avaliacao injusta'],
        'account_schedule_issue'=> ['category' => 'account',  'severity' => 'low',     'label' => 'Problema com escala/horario'],
        'account_region_change' => ['category' => 'account',  'severity' => 'low',     'label' => 'Mudanca de regiao'],
        'support_no_response'   => ['category' => 'account',  'severity' => 'medium',  'label' => 'Suporte nao responde'],
        'support_unresolved'    => ['category' => 'account',  'severity' => 'high',    'label' => 'Problema nao resolvido'],
    ];

    $method = $_SERVER["REQUEST_METHOD"];

    // =================== GET ===================
    if ($method === "GET") {
        $action = trim($_GET['action'] ?? 'list');

        if ($action === 'stats') {
            $stmt = $db->prepare("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'aberto' OR status = 'open' THEN 1 ELSE 0 END) as open,
                    SUM(CASE WHEN status = 'em_analise' OR status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN status = 'resolvido' OR status = 'resolved' THEN 1 ELSE 0 END) as resolved
                FROM om_order_problems
                WHERE shopper_id = ?
            ");
            $stmt->execute([$shopper_id]);
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
        $page = max(1, (int)($_GET["page"] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $where = "WHERE pr.shopper_id = ?";
        $params = [$shopper_id];

        if ($status) {
            $where .= " AND pr.status = ?";
            $params[] = $status;
        }
        if ($category) {
            $where .= " AND pr.category = ?";
            $params[] = $category;
        }

        $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_order_problems pr {$where}");
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        $params[] = $limit;
        $params[] = $offset;

        $sql = "SELECT pr.problem_id, pr.order_id, pr.problem_type, pr.category, pr.subcategory,
                       pr.severity, pr.description, pr.status, pr.photo_urls, pr.resolution_note,
                       pr.created_at, pr.resolved_at,
                       o.delivery_address, p.name as partner_name
                FROM om_order_problems pr
                LEFT JOIN om_market_orders o ON pr.order_id = o.order_id
                LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
                {$where}
                ORDER BY pr.created_at DESC
                LIMIT ? OFFSET ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $problems = $stmt->fetchAll();

        $statusLabels = [
            'aberto' => 'Aberto', 'open' => 'Aberto',
            'em_analise' => 'Em Analise', 'in_progress' => 'Em Analise',
            'resolvido' => 'Resolvido', 'resolved' => 'Resolvido',
            'closed' => 'Fechado',
        ];

        $result = array_map(function($pr) use ($statusLabels, $RULES) {
            $subcat = $pr['subcategory'] ?? '';
            $rule = $RULES[$subcat] ?? null;
            $photos = [];
            if ($pr['photo_urls']) {
                $decoded = json_decode($pr['photo_urls'], true);
                if (is_array($decoded)) $photos = $decoded;
            }
            return [
                "id" => (int)$pr["problem_id"],
                "order_id" => $pr["order_id"] ? (int)$pr["order_id"] : null,
                "type" => $pr["problem_type"],
                "category" => $pr["category"] ?? ($rule ? $rule['category'] : ''),
                "subcategory" => $subcat,
                "label" => $rule ? $rule['label'] : ($pr["problem_type"] ?? $subcat),
                "severity" => $pr["severity"] ?? 'medium',
                "description" => $pr["description"],
                "photo_urls" => $photos,
                "status" => $pr["status"],
                "status_label" => $statusLabels[$pr["status"]] ?? $pr["status"],
                "partner_name" => $pr["partner_name"],
                "resolution_note" => $pr["resolution_note"],
                "created_at" => $pr["created_at"],
                "resolved_at" => $pr["resolved_at"],
            ];
        }, $problems);

        response(true, [
            "problems" => $result,
            "pagination" => [
                "page" => $page,
                "limit" => $limit,
                "total" => $total,
                "total_pages" => ceil($total / $limit),
                "has_more" => ($offset + $limit) < $total,
            ],
        ], "Problemas carregados");
    }

    // =================== POST: Create problem ===================
    if ($method === "POST") {
        $input = getInput();
        $subcategory = trim($input["subcategory"] ?? "");
        $order_id = (int)($input["order_id"] ?? 0);
        $description = trim($input["description"] ?? "");
        $photoUrls = $input["photo_urls"] ?? [];

        // Backward compatibility: accept old 'type' field
        if (!$subcategory && !empty($input["type"])) {
            $oldType = trim($input["type"]);
            $legacyMap = [
                'item_faltando' => 'pickup_missing_items',
                'item_danificado' => 'pickup_packaging_bad',
                'item_errado' => 'pickup_wrong_items',
                'endereco_incorreto' => 'delivery_address_wrong',
                'cliente_ausente' => 'delivery_customer_absent',
            ];
            $subcategory = $legacyMap[$oldType] ?? $oldType;
        }

        if (!$subcategory || !isset($RULES[$subcategory])) {
            response(false, null, "Subcategoria invalida", 400);
        }

        $rule = $RULES[$subcategory];
        $category = $rule['category'];
        $severity = $rule['severity'];

        if (!$description || strlen($description) < 3) {
            response(false, null, "Descricao obrigatoria (min. 3 caracteres)", 400);
        }
        if (strlen($description) > 2000) {
            response(false, null, "Descricao muito longa (max 2000)", 400);
        }

        $description = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');

        // Validate order belongs to shopper if provided
        if ($order_id) {
            $stmtO = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND shopper_id = ?");
            $stmtO->execute([$order_id, $shopper_id]);
            if (!$stmtO->fetch()) {
                $order_id = 0;
            }
        }

        if (!is_array($photoUrls)) $photoUrls = [];
        $photoUrlsJson = json_encode(array_slice($photoUrls, 0, 5));

        $stmt = $db->prepare("
            INSERT INTO om_order_problems (order_id, shopper_id, problem_type, category, subcategory, severity, description, photo_urls, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'aberto', NOW())
        ");
        $stmt->execute([
            $order_id ?: null,
            $shopper_id,
            $subcategory, // also store in problem_type for backward compat
            $category,
            $subcategory,
            $severity,
            $description,
            $photoUrlsJson,
        ]);
        $problem_id = (int)$db->lastInsertId();

        // Notify admin for critical/safety issues
        if ($severity === 'critical' || $category === 'safety') {
            try {
                $db->prepare("
                    INSERT INTO om_notifications (user_id, user_type, title, body, type, reference_type, reference_id, created_at)
                    VALUES (1, 'admin', ?, ?, 'shopper_problem', 'problem', ?, NOW())
                ")->execute([
                    ($category === 'safety' ? "URGENTE: " : "") . "Problema do shopper #{$shopper_id}",
                    "Tipo: {$rule['label']}. " . ($order_id ? "Pedido #{$order_id}" : "Sem pedido"),
                    $problem_id,
                ]);
            } catch (Exception $e) {
                error_log("[shopper/problemas] Admin notification failed: " . $e->getMessage());
            }
        }

        response(true, [
            "id" => $problem_id,
            "order_id" => $order_id ?: null,
            "category" => $category,
            "subcategory" => $subcategory,
            "severity" => $severity,
            "description" => $description,
            "status" => "aberto",
        ], "Problema reportado com sucesso.");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[shopper/problemas] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar problema", 500);
}
