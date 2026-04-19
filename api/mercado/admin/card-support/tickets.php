<?php
/**
 * GET  /admin/card-support/tickets.php            - list tickets
 * POST /admin/card-support/tickets.php            - create or update a ticket
 *
 * GET query:
 *   status    open|in_progress|resolved|closed|all
 *   priority  low|normal|high|urgent|all
 *   category
 *   search    (matches subject + customer)
 *   customer_id
 *   page, per_page
 *
 * POST body (create):
 *   { customer_id, card_id?, subject, description, category, priority }
 *
 * POST body (update):
 *   { id, status?, priority?, assigned_to?, subject?, description?, resolution_note? }
 */

require_once __DIR__ . "/_common.php";

try {
    $ctx = bootstrapCardSupport();
    $db  = $ctx['db'];
    $adminId = $ctx['admin_id'];

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $status      = trim((string)($_GET['status']   ?? 'all'));
        $priority    = trim((string)($_GET['priority'] ?? 'all'));
        $category    = trim((string)($_GET['category'] ?? ''));
        $search      = trim((string)($_GET['search']   ?? ''));
        $customerId  = (int)($_GET['customer_id'] ?? 0);
        $page        = max(1, (int)($_GET['page'] ?? 1));
        $perPage     = min(100, max(5, (int)($_GET['per_page'] ?? 25)));
        $offset      = ($page - 1) * $perPage;

        $conditions = ['1=1'];
        $params = [];

        if ($status !== 'all' && $status !== '') {
            $conditions[] = 't.status = ?'; $params[] = $status;
        }
        if ($priority !== 'all' && $priority !== '') {
            $conditions[] = 't.priority = ?'; $params[] = $priority;
        }
        if ($category !== '') {
            $conditions[] = 't.category = ?'; $params[] = $category;
        }
        if ($customerId > 0) {
            $conditions[] = 't.customer_id = ?'; $params[] = $customerId;
        }
        if ($search !== '') {
            $like = '%' . $search . '%';
            $conditions[] = '(t.subject ILIKE ? OR c.name ILIKE ? OR c.email ILIKE ?)';
            array_push($params, $like, $like, $like);
        }

        $whereSql = implode(' AND ', $conditions);

        $countStmt = $db->prepare("
            SELECT COUNT(*)
            FROM om_card_support_tickets t
            LEFT JOIN om_customers c ON c.customer_id = t.customer_id
            WHERE {$whereSql}
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare("
            SELECT t.*, c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone
            FROM om_card_support_tickets t
            LEFT JOIN om_customers c ON c.customer_id = t.customer_id
            WHERE {$whereSql}
            ORDER BY
                CASE t.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END,
                CASE t.status WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END,
                t.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$perPage, $offset]));

        $tickets = array_map(function ($t) {
            return [
                'id'             => (int)$t['id'],
                'customer_id'    => (int)$t['customer_id'],
                'customer_name'  => $t['customer_name'],
                'customer_email' => $t['customer_email'],
                'customer_phone' => $t['customer_phone'],
                'card_id'        => $t['card_id'] ? (int)$t['card_id'] : null,
                'subject'        => $t['subject'],
                'description'    => $t['description'],
                'category'       => $t['category'],
                'priority'       => $t['priority'],
                'status'         => $t['status'],
                'assigned_to'    => $t['assigned_to'] ? (int)$t['assigned_to'] : null,
                'created_by'     => $t['created_by']  ? (int)$t['created_by']  : null,
                'created_at'     => $t['created_at'],
                'updated_at'     => $t['updated_at'] ?? null,
                'resolved_at'    => $t['resolved_at'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Quick stats for the tab header
        $stats = $db->query("
            SELECT
                COUNT(*) FILTER (WHERE status = 'open')         AS open_count,
                COUNT(*) FILTER (WHERE status = 'in_progress')  AS in_progress_count,
                COUNT(*) FILTER (WHERE priority = 'urgent' AND status IN ('open','in_progress')) AS urgent_count,
                COUNT(*) FILTER (WHERE status = 'resolved')     AS resolved_count,
                COUNT(*) FILTER (WHERE status = 'closed')       AS closed_count
            FROM om_card_support_tickets
        ")->fetch(PDO::FETCH_ASSOC);

        response(true, [
            'tickets' => $tickets,
            'total'   => $total,
            'page'    => $page,
            'per_page'=> $perPage,
            'stats'   => [
                'open'        => (int)($stats['open_count']        ?? 0),
                'in_progress' => (int)($stats['in_progress_count'] ?? 0),
                'urgent'      => (int)($stats['urgent_count']      ?? 0),
                'resolved'    => (int)($stats['resolved_count']    ?? 0),
                'closed'      => (int)($stats['closed_count']      ?? 0),
            ],
        ]);
    }

    if ($method === 'POST') {
        $input = getInput();
        $ticketId = (int)($input['id'] ?? 0);

        // Update existing ticket
        if ($ticketId > 0) {
            $stmt = $db->prepare("SELECT * FROM om_card_support_tickets WHERE id = ? LIMIT 1");
            $stmt->execute([$ticketId]);
            $t = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$t) response(false, null, 'Ticket nao encontrado', 404);

            $sets = [];
            $params = [];

            if (isset($input['status']) && in_array($input['status'], ['open','in_progress','resolved','closed'], true)) {
                $sets[] = 'status = ?';
                $params[] = $input['status'];
                if ($input['status'] === 'resolved' && empty($t['resolved_at'])) {
                    $sets[] = 'resolved_at = NOW()';
                }
            }
            if (isset($input['priority']) && in_array($input['priority'], ['low','normal','high','urgent'], true)) {
                $sets[] = 'priority = ?'; $params[] = $input['priority'];
            }
            if (isset($input['category']) && is_string($input['category'])) {
                $sets[] = 'category = ?'; $params[] = (string)$input['category'];
            }
            if (isset($input['subject']) && is_string($input['subject']) && $input['subject'] !== '') {
                $sets[] = 'subject = ?'; $params[] = (string)$input['subject'];
            }
            if (isset($input['description']) && is_string($input['description'])) {
                $sets[] = 'description = ?'; $params[] = (string)$input['description'];
            }
            if (array_key_exists('assigned_to', $input)) {
                $sets[] = 'assigned_to = ?';
                $params[] = $input['assigned_to'] !== null ? (int)$input['assigned_to'] : null;
            }

            if (empty($sets)) response(false, null, 'Nenhuma alteracao informada', 400);

            $sets[] = 'updated_at = NOW()';
            $params[] = $ticketId;
            $sql = "UPDATE om_card_support_tickets SET " . implode(', ', $sets) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            // Add a resolution note if provided
            if (!empty($input['resolution_note']) && is_string($input['resolution_note'])) {
                $noteStmt = $db->prepare("
                    INSERT INTO om_card_support_notes (customer_id, card_id, agent_id, agent_name, note, visibility)
                    VALUES (?, ?, ?, ?, ?, 'internal')
                ");
                $noteStmt->execute([
                    (int)$t['customer_id'],
                    $t['card_id'] ? (int)$t['card_id'] : null,
                    $adminId,
                    $ctx['admin_name'],
                    sprintf('[Ticket #%d] %s', $ticketId, $input['resolution_note']),
                ]);
            }

            response(true, ['id' => $ticketId, 'updated' => true]);
        }

        // Create new ticket
        $customerId = (int)($input['customer_id'] ?? 0);
        $cardId     = !empty($input['card_id']) ? (int)$input['card_id'] : null;
        $subject    = trim((string)($input['subject']  ?? ''));
        $description= (string)($input['description'] ?? '');
        $category   = (string)($input['category']    ?? 'other');
        $priority   = (string)($input['priority']    ?? 'normal');

        if ($customerId <= 0) response(false, null, 'customer_id obrigatorio', 400);
        if ($subject === '') response(false, null, 'subject obrigatorio', 400);
        if (!in_array($priority, ['low','normal','high','urgent'], true)) $priority = 'normal';
        if (strlen($subject) > 200) $subject = substr($subject, 0, 200);

        $stmt = $db->prepare("
            INSERT INTO om_card_support_tickets
                (customer_id, card_id, subject, description, category, priority, status, created_by, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 'open', ?, NOW())
            RETURNING id
        ");
        $stmt->execute([$customerId, $cardId, $subject, $description, $category, $priority, $adminId]);
        $newId = (int)$stmt->fetchColumn();

        response(true, ['id' => $newId, 'created' => true]);
    }

    response(false, null, 'Metodo nao permitido', 405);
} catch (Exception $e) {
    error_log('[card-support-tickets] ' . $e->getMessage());
    response(false, null, 'Erro no endpoint de tickets', 500);
}
