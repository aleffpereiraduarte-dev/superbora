<?php
/**
 * /api/mercado/orders/help.php
 *
 * Customer problem/complaint reporting system.
 *
 * GET  ?order_id=X          - List problems for an order
 * POST { order_id, category, description, photo_evidence? } - Create problem report
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
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Token invalido", 401);
    $customerId = (int)$payload['uid'];

    $method = $_SERVER['REQUEST_METHOD'];

    // =================== GET: List problems for an order ===================
    if ($method === 'GET') {
        $orderId = (int)($_GET['order_id'] ?? 0);
        if (!$orderId) response(false, null, "order_id obrigatorio", 400);

        // Verify order belongs to customer
        $stmtOrder = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
        $stmtOrder->execute([$orderId, $customerId]);
        if (!$stmtOrder->fetch()) response(false, null, "Pedido nao encontrado", 404);

        $stmt = $db->prepare("
            SELECT problem_id as id, order_id, category, COALESCE(category, problem_type) as category_raw, description, photo_evidence, status, resolution, resolved_at, created_at
            FROM om_order_problems
            WHERE order_id = ? AND customer_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$orderId, $customerId]);
        $problems = $stmt->fetchAll();

        $categoryLabels = [
            'wrong_items' => 'Itens errados',
            'missing_items' => 'Itens faltando',
            'damaged' => 'Itens danificados',
            'quality' => 'Qualidade abaixo',
            'late' => 'Pedido atrasou',
            'wrong_order' => 'Pedido trocado',
            'other' => 'Outro',
        ];

        $formatted = [];
        foreach ($problems as $p) {
            $formatted[] = [
                'id' => (int)$p['id'],
                'order_id' => (int)$p['order_id'],
                'category' => $p['category'] ?: $p['category_raw'],
                'category_label' => $categoryLabels[$p['category'] ?: $p['category_raw']] ?? ($p['category'] ?: $p['category_raw']),
                'description' => $p['description'],
                'photo_evidence' => $p['photo_evidence'],
                'status' => $p['status'],
                'status_label' => getStatusLabel($p['status']),
                'resolution' => $p['resolution'],
                'resolved_at' => $p['resolved_at'],
                'created_at' => $p['created_at'],
            ];
        }

        response(true, ['problems' => $formatted, 'total' => count($formatted)]);
    }

    // =================== POST: Create problem report ===================
    if ($method === 'POST') {
        $input = getInput();
        $orderId = (int)($input['order_id'] ?? 0);
        $category = trim($input['category'] ?? '');
        $description = trim(substr($input['description'] ?? '', 0, 2000));
        $photoEvidence = trim($input['photo_evidence'] ?? '');

        if (!$orderId) response(false, null, "order_id obrigatorio", 400);

        $validCategories = ['wrong_items','missing_items','damaged','quality','late','wrong_order','other'];
        if (!in_array($category, $validCategories)) {
            response(false, null, "Categoria invalida. Validas: " . implode(', ', $validCategories), 400);
        }

        if (empty($description) || strlen($description) < 10) {
            response(false, null, "Descricao obrigatoria (min. 10 caracteres)", 400);
        }

        // Verify order belongs to customer
        $stmtOrder = $db->prepare("SELECT order_id, status, delivered_at, date_added FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
        $stmtOrder->execute([$orderId, $customerId]);
        $order = $stmtOrder->fetch();
        if (!$order) response(false, null, "Pedido nao encontrado", 404);

        // Check 7-day window for delivered orders
        $deliveredStatuses = ['entregue'];
        if (in_array($order['status'], $deliveredStatuses) && $order['delivered_at']) {
            $deliveredTime = strtotime($order['delivered_at']);
            $sevenDays = 7 * 24 * 60 * 60;
            if ((time() - $deliveredTime) > $sevenDays) {
                response(false, null, "O prazo para reportar problemas expirou (7 dias apos entrega)", 400);
            }
        }

        // Check for duplicate (same order + same category within 1 hour)
        $stmtDup = $db->prepare("
            SELECT problem_id FROM om_order_problems
            WHERE order_id = ? AND customer_id = ? AND category = ? AND created_at > NOW() - INTERVAL '1 hours'
        ");
        $stmtDup->execute([$orderId, $customerId, $category]);
        if ($stmtDup->fetch()) {
            response(false, null, "Voce ja reportou este problema recentemente. Aguarde a analise.", 400);
        }

        // Sanitize
        $description = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
        if ($photoEvidence) {
            $photoEvidence = filter_var($photoEvidence, FILTER_SANITIZE_URL);
        }

        $stmt = $db->prepare("
            INSERT INTO om_order_problems (order_id, customer_id, category, description, photo_evidence, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'open', NOW())
        ");
        $stmt->execute([$orderId, $customerId, $category, $description, $photoEvidence ?: null]);

        $problemId = (int)$db->lastInsertId();

        // Also insert a system message in chat to notify
        try {
            $categoryLabels = [
                'wrong_items' => 'Itens errados',
                'missing_items' => 'Itens faltando',
                'damaged' => 'Itens danificados',
                'quality' => 'Qualidade abaixo',
                'late' => 'Pedido atrasou',
                'wrong_order' => 'Pedido trocado',
                'other' => 'Outro problema',
            ];
            $catLabel = $categoryLabels[$category] ?? $category;
            $sysMsg = "Problema reportado: {$catLabel}. Nossa equipe ira analisar em breve.";

            $db->prepare("
                INSERT INTO om_order_chat (order_id, sender_type, sender_id, sender_name, message, message_type, chat_type, is_read, created_at)
                VALUES (?, 'system', 0, 'Sistema', ?, 'text', 'support', 0, NOW())
            ")->execute([$orderId, $sysMsg]);
        } catch (Exception $e) {
            // Non-critical, chat table might not have support type yet
            error_log("[help.php] Could not insert system chat message: " . $e->getMessage());
        }

        response(true, [
            'problem' => [
                'id' => $problemId,
                'order_id' => $orderId,
                'category' => $category,
                'description' => $description,
                'status' => 'open',
                'status_label' => 'Aberto',
                'created_at' => date('Y-m-d H:i:s'),
            ]
        ], "Problema reportado com sucesso. Nossa equipe ira analisar.");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[API Order Help] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar solicitacao", 500);
}

function getStatusLabel(string $status): string {
    $labels = [
        'open' => 'Aberto',
        'in_progress' => 'Em analise',
        'resolved' => 'Resolvido',
        'closed' => 'Fechado',
    ];
    return $labels[$status] ?? $status;
}
