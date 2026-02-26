<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = $payload['uid'];

    // Table om_order_notes created via migration

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $order_id = (int)($_GET['order_id'] ?? 0);
        if (!$order_id) response(false, null, "order_id obrigatorio", 400);

        $stmt = $db->prepare("
            SELECT n.*, a.name as admin_name
            FROM om_order_notes n
            LEFT JOIN om_admins a ON n.admin_id = a.admin_id
            WHERE n.order_id = ?
            ORDER BY n.created_at DESC
        ");
        $stmt->execute([$order_id]);
        $notes = $stmt->fetchAll();

        response(true, ['notes' => $notes], "Notas do pedido");

    } elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
        $input = getInput();
        $order_id = (int)($input['order_id'] ?? 0);
        $note = trim($input['note'] ?? '');

        if (!$order_id || !$note) response(false, null, "order_id e note obrigatorios", 400);

        // Sanitize note to prevent stored XSS and limit length
        $note = strip_tags($note);
        $note = mb_substr($note, 0, 5000);

        $stmt = $db->prepare("
            INSERT INTO om_order_notes (order_id, admin_id, note, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$order_id, $admin_id, $note]);
        $note_id = (int)$db->lastInsertId();

        om_audit()->log('create', 'order_note', $note_id, null, [
            'order_id' => $order_id,
            'note' => $note
        ]);

        response(true, ['note_id' => $note_id], "Nota adicionada");
    } else {
        response(false, null, "Metodo nao permitido", 405);
    }
} catch (Exception $e) {
    error_log("[admin/order-notes] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
