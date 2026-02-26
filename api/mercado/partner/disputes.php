<?php
/**
 * /api/mercado/partner/disputes.php
 *
 * Partner dispute management API - view customer disputes, respond, upload evidence.
 *
 * GET  ?action=stats                              - Dispute stats
 * GET  ?action=list&status=X&period=30d&page=1    - List disputes with filters
 * GET  ?action=detail&dispute_id=X                - Dispute detail + timeline + evidence
 * POST { action: respond, dispute_id, response_type, note }  - Accept/contest
 * POST (FormData) action=upload_evidence, dispute_id, photo  - Upload evidence photo
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

    $method = $_SERVER['REQUEST_METHOD'];

    // =================== GET ===================
    if ($method === 'GET') {
        $action = trim($_GET['action'] ?? 'list');

        // ---------- STATS ----------
        if ($action === 'stats') {
            $stmt = $db->prepare("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status IN ('open','awaiting_evidence') THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'in_review' THEN 1 ELSE 0 END) as in_review,
                    SUM(CASE WHEN status = 'escalated' THEN 1 ELSE 0 END) as escalated,
                    SUM(CASE WHEN status IN ('auto_resolved','resolved','closed') THEN 1 ELSE 0 END) as resolved,
                    COALESCE(SUM(approved_amount), 0) as total_amount
                FROM om_order_disputes
                WHERE partner_id = ?
            ");
            $stmt->execute([$partnerId]);
            $stats = $stmt->fetch();

            response(true, [
                'total' => (int)$stats['total'],
                'pending' => (int)$stats['pending'],
                'in_review' => (int)$stats['in_review'],
                'escalated' => (int)$stats['escalated'],
                'resolved' => (int)$stats['resolved'],
                'total_amount' => round((float)$stats['total_amount'], 2),
            ]);
        }

        // ---------- DETAIL ----------
        if ($action === 'detail') {
            $disputeId = (int)($_GET['dispute_id'] ?? 0);
            if (!$disputeId) response(false, null, "dispute_id obrigatorio", 400);

            $stmt = $db->prepare("
                SELECT d.dispute_id, d.order_id, d.customer_id, d.partner_id,
                       d.category, d.subcategory, d.severity, d.description,
                       d.photo_urls, d.affected_items, d.status, d.auto_resolved,
                       d.requested_amount, d.approved_amount, d.credit_amount,
                       d.compensation_type, d.partner_response, d.resolution_note,
                       d.order_total, d.created_at, d.resolved_at,
                       o.total as real_order_total,
                       c.name as customer_name, c.phone as customer_phone
                FROM om_order_disputes d
                LEFT JOIN om_market_orders o ON o.order_id = d.order_id
                LEFT JOIN om_customers c ON c.customer_id = d.customer_id
                WHERE d.dispute_id = ? AND d.partner_id = ?
            ");
            $stmt->execute([$disputeId, $partnerId]);
            $dispute = $stmt->fetch();
            if (!$dispute) response(false, null, "Disputa nao encontrada", 404);

            // Timeline
            $stmtTl = $db->prepare("
                SELECT timeline_id, dispute_id, action, actor_type, actor_id, description, created_at
                FROM om_dispute_timeline
                WHERE dispute_id = ?
                ORDER BY created_at ASC
            ");
            $stmtTl->execute([$disputeId]);
            $timeline = $stmtTl->fetchAll();

            // Evidence
            $stmtEv = $db->prepare("
                SELECT evidence_id, dispute_id, photo_url, caption, created_at
                FROM om_dispute_evidence
                WHERE dispute_id = ?
                ORDER BY created_at ASC
            ");
            $stmtEv->execute([$disputeId]);
            $evidence = $stmtEv->fetchAll();

            $statusLabels = [
                'open' => 'Aberto', 'awaiting_evidence' => 'Aguardando fotos',
                'auto_resolved' => 'Resolvido (auto)', 'in_review' => 'Em analise',
                'escalated' => 'Escalado', 'resolved' => 'Resolvido', 'closed' => 'Fechado',
            ];

            $photoUrls = [];
            if ($dispute['photo_urls']) {
                $decoded = json_decode($dispute['photo_urls'], true);
                if (is_array($decoded)) $photoUrls = $decoded;
            }

            $affectedItems = [];
            if ($dispute['affected_items']) {
                $decoded = json_decode($dispute['affected_items'], true);
                if (is_array($decoded)) $affectedItems = $decoded;
            }

            response(true, [
                'dispute' => [
                    'id' => (int)$dispute['dispute_id'],
                    'order_id' => (int)$dispute['order_id'],
                    'customer_name' => $dispute['customer_name'] ?? 'Cliente',
                    'category' => $dispute['category'],
                    'subcategory' => $dispute['subcategory'],
                    'severity' => $dispute['severity'],
                    'description' => $dispute['description'],
                    'photo_urls' => $photoUrls,
                    'affected_items' => $affectedItems,
                    'status' => $dispute['status'],
                    'status_label' => $statusLabels[$dispute['status']] ?? $dispute['status'],
                    'auto_resolved' => (bool)$dispute['auto_resolved'],
                    'requested_amount' => (float)$dispute['requested_amount'],
                    'approved_amount' => (float)$dispute['approved_amount'],
                    'credit_amount' => (float)($dispute['credit_amount'] ?? 0),
                    'compensation_type' => $dispute['compensation_type'] ?? null,
                    'partner_response' => $dispute['partner_response'],
                    'resolution_note' => $dispute['resolution_note'],
                    'order_total' => (float)($dispute['real_order_total'] ?? $dispute['order_total'] ?? 0),
                    'created_at' => $dispute['created_at'],
                    'resolved_at' => $dispute['resolved_at'],
                ],
                'timeline' => array_map(function($t) {
                    return [
                        'id' => (int)$t['timeline_id'],
                        'action' => $t['action'],
                        'actor_type' => $t['actor_type'],
                        'description' => $t['description'],
                        'created_at' => $t['created_at'],
                    ];
                }, $timeline),
                'evidence' => array_map(function($e) {
                    return [
                        'id' => (int)$e['evidence_id'],
                        'photo_url' => $e['photo_url'],
                        'caption' => $e['caption'] ?? '',
                        'created_at' => $e['created_at'],
                    ];
                }, $evidence),
            ]);
        }

        // ---------- LIST (default) ----------
        $status = trim($_GET['status'] ?? '');
        $period = trim($_GET['period'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = "WHERE d.partner_id = ?";
        $params = [$partnerId];

        if ($status) {
            if ($status === 'pending') {
                $where .= " AND d.status IN ('open','awaiting_evidence')";
            } elseif ($status === 'resolved') {
                $where .= " AND d.status IN ('auto_resolved','resolved','closed')";
            } else {
                $where .= " AND d.status = ?";
                $params[] = $status;
            }
        }

        if ($period) {
            $days = 30;
            if ($period === '7d') $days = 7;
            elseif ($period === '90d') $days = 90;
            elseif ($period === '180d') $days = 180;
            $days = (int)$days;
            $where .= " AND d.created_at >= NOW() - INTERVAL '1 day' * ?";
            $params[] = $days;
        }

        $stmtCount = $db->prepare("SELECT COUNT(*) as total FROM om_order_disputes d {$where}");
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetch()['total'];

        $params[] = $limit;
        $params[] = $offset;

        $stmt = $db->prepare("
            SELECT d.dispute_id, d.order_id, d.customer_id, d.partner_id,
                   d.category, d.subcategory, d.severity, d.description,
                   d.status, d.auto_resolved, d.requested_amount, d.approved_amount,
                   d.partner_response, d.order_total, d.created_at, d.resolved_at,
                   o.total as real_order_total,
                   c.name as customer_name
            FROM om_order_disputes d
            LEFT JOIN om_market_orders o ON o.order_id = d.order_id
            LEFT JOIN om_customers c ON c.customer_id = d.customer_id
            {$where}
            ORDER BY d.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $disputes = $stmt->fetchAll();

        $statusLabels = [
            'open' => 'Aberto', 'awaiting_evidence' => 'Aguardando fotos',
            'auto_resolved' => 'Resolvido (auto)', 'in_review' => 'Em analise',
            'escalated' => 'Escalado', 'resolved' => 'Resolvido', 'closed' => 'Fechado',
        ];

        $severityLabels = [
            'low' => 'Baixa', 'medium' => 'Media', 'high' => 'Alta', 'critical' => 'Critica',
        ];

        $formatted = array_map(function($d) use ($statusLabels, $severityLabels) {
            return [
                'id' => (int)$d['dispute_id'],
                'order_id' => (int)$d['order_id'],
                'customer_name' => $d['customer_name'] ?? 'Cliente',
                'category' => $d['category'],
                'subcategory' => $d['subcategory'],
                'severity' => $d['severity'],
                'severity_label' => $severityLabels[$d['severity']] ?? $d['severity'],
                'description' => $d['description'],
                'status' => $d['status'],
                'status_label' => $statusLabels[$d['status']] ?? $d['status'],
                'auto_resolved' => (bool)$d['auto_resolved'],
                'requested_amount' => (float)$d['requested_amount'],
                'approved_amount' => (float)$d['approved_amount'],
                'partner_response' => $d['partner_response'],
                'order_total' => (float)($d['real_order_total'] ?? $d['order_total'] ?? 0),
                'created_at' => $d['created_at'],
                'resolved_at' => $d['resolved_at'],
            ];
        }, $disputes);

        response(true, [
            'disputes' => $formatted,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $limit),
        ]);
    }

    // =================== POST ===================
    if ($method === 'POST') {
        // Check if this is a FormData upload
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $isUpload = strpos($contentType, 'multipart/form-data') !== false;

        if ($isUpload) {
            // Upload evidence photo
            $action = $_POST['action'] ?? 'upload_evidence';
            $disputeId = (int)($_POST['dispute_id'] ?? 0);

            if (!$disputeId) response(false, null, "dispute_id obrigatorio", 400);

            // Verify dispute belongs to partner
            $stmtD = $db->prepare("SELECT dispute_id, status FROM om_order_disputes WHERE dispute_id = ? AND partner_id = ?");
            $stmtD->execute([$disputeId, $partnerId]);
            if (!$stmtD->fetch()) response(false, null, "Disputa nao encontrada", 404);

            if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                response(false, null, "Foto obrigatoria", 400);
            }

            $file = $_FILES['photo'];
            $maxSize = 10 * 1024 * 1024;
            if ($file['size'] > $maxSize) response(false, null, "Foto muito grande (max 10MB)", 400);

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                response(false, null, "Formato invalido. Use JPG, PNG ou WebP", 400);
            }

            // SECURITY: Validate actual MIME type (not just extension)
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
                response(false, null, "Tipo de arquivo nao permitido", 400);
            }

            $year = date('Y');
            $month = date('m');
            $uploadDir = "/var/www/html/uploads/dispute-evidence/{$year}/{$month}";
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            $filename = "partner_{$partnerId}_d{$disputeId}_" . time() . "_" . bin2hex(random_bytes(4)) . ".{$ext}";
            $filepath = "{$uploadDir}/{$filename}";
            $publicUrl = "/uploads/dispute-evidence/{$year}/{$month}/{$filename}";

            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                response(false, null, "Erro ao salvar foto", 500);
            }

            // Save to evidence table
            $db->prepare("
                INSERT INTO om_dispute_evidence (dispute_id, order_id, customer_id, photo_url, file_size, caption, created_at)
                VALUES (?, 0, 0, ?, ?, ?, NOW())
            ")->execute([$disputeId, $publicUrl, $file['size'], "Evidencia do parceiro"]);

            // Add timeline entry
            $db->prepare("
                INSERT INTO om_dispute_timeline (dispute_id, action, actor_type, actor_id, description, created_at)
                VALUES (?, 'partner_evidence_uploaded', 'partner', ?, 'Parceiro enviou foto de evidencia', NOW())
            ")->execute([$disputeId, $partnerId]);

            response(true, ['photo_url' => $publicUrl], "Foto enviada com sucesso");
        }

        // JSON actions
        $input = getInput();
        $action = trim($input['action'] ?? '');

        if ($action === 'respond') {
            $disputeId = (int)($input['dispute_id'] ?? 0);
            $responseType = trim($input['response_type'] ?? '');
            $note = trim(substr($input['note'] ?? '', 0, 2000));

            if (!$disputeId) response(false, null, "dispute_id obrigatorio", 400);
            if (!in_array($responseType, ['accept', 'contest'])) {
                response(false, null, "response_type deve ser 'accept' ou 'contest'", 400);
            }

            // Verify dispute belongs to partner
            $stmtDispute = $db->prepare("
                SELECT dispute_id, status, partner_response FROM om_order_disputes WHERE dispute_id = ? AND partner_id = ?
            ");
            $stmtDispute->execute([$disputeId, $partnerId]);
            $dispute = $stmtDispute->fetch();
            if (!$dispute) response(false, null, "Disputa nao encontrada", 404);

            if (in_array($dispute['status'], ['auto_resolved', 'resolved', 'closed'])) {
                response(false, null, "Esta disputa ja foi resolvida", 400);
            }

            $db->beginTransaction();

            if ($responseType === 'accept') {
                $db->prepare("
                    UPDATE om_order_disputes
                    SET partner_response = ?, status = 'resolved', resolution_type = 'partner_accepted',
                        resolution_note = 'Parceiro aceitou a disputa', resolved_at = NOW(), updated_at = NOW()
                    WHERE dispute_id = ?
                ")->execute([$note ?: 'Aceito', $disputeId]);

                $db->prepare("
                    INSERT INTO om_dispute_timeline (dispute_id, action, actor_type, actor_id, description, created_at)
                    VALUES (?, 'partner_accepted', 'partner', ?, ?, NOW())
                ")->execute([$disputeId, $partnerId, 'Parceiro aceitou a disputa' . ($note ? ': ' . $note : '')]);
            } else {
                if (empty($note) || strlen($note) < 10) {
                    $db->rollBack();
                    response(false, null, "Para contestar, descreva o motivo (min. 10 caracteres)", 400);
                }

                $note = htmlspecialchars($note, ENT_QUOTES, 'UTF-8');

                $db->prepare("
                    UPDATE om_order_disputes
                    SET partner_response = ?, status = 'escalated', updated_at = NOW()
                    WHERE dispute_id = ?
                ")->execute([$note, $disputeId]);

                $db->prepare("
                    INSERT INTO om_dispute_timeline (dispute_id, action, actor_type, actor_id, description, created_at)
                    VALUES (?, 'partner_contested', 'partner', ?, ?, NOW())
                ")->execute([$disputeId, $partnerId, 'Parceiro contestou: ' . $note]);

                // Notify admin
                try {
                    $db->prepare("
                        INSERT INTO om_notifications (user_id, user_type, title, body, type, reference_type, reference_id, created_at)
                        VALUES (1, 'admin', ?, ?, 'dispute_escalated', 'dispute', ?, NOW())
                    ")->execute([
                        "Disputa #{$disputeId} contestada",
                        "Parceiro contestou. Pedido #{$dispute['order_id']}",
                        $disputeId,
                    ]);
                } catch (Exception $e) {
                    error_log("[partner/disputes] Admin notification failed: " . $e->getMessage());
                }
            }

            $db->commit();

            $statusMsg = $responseType === 'accept' ? 'Disputa aceita com sucesso' : 'Contestacao enviada para analise';
            response(true, ['status' => $responseType === 'accept' ? 'resolved' : 'escalated'], $statusMsg);
        }

        response(false, null, "action obrigatoria", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[partner/disputes] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar", 500);
}
