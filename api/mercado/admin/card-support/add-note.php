<?php
/**
 * POST /admin/card-support/add-note.php
 *
 * Add a support note to a customer.
 *
 * Body:
 *   { customer_id, card_id?, note, visibility? ('internal' | 'customer') }
 */

require_once __DIR__ . "/_common.php";

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $ctx = bootstrapCardSupport();
    $db  = $ctx['db'];
    $adminId   = $ctx['admin_id'];
    $adminName = $ctx['admin_name'];

    $input = getInput();
    $customerId = (int)($input['customer_id'] ?? 0);
    $cardId     = !empty($input['card_id']) ? (int)$input['card_id'] : null;
    $note       = trim((string)($input['note'] ?? ''));
    $visibility = (string)($input['visibility'] ?? 'internal');

    if ($customerId <= 0) response(false, null, 'customer_id obrigatorio', 400);
    if ($note === '')     response(false, null, 'note obrigatorio',        400);
    if (!in_array($visibility, ['internal', 'customer'], true)) $visibility = 'internal';

    $stmt = $db->prepare("
        INSERT INTO om_card_support_notes (customer_id, card_id, agent_id, agent_name, note, visibility)
        VALUES (?, ?, ?, ?, ?, ?)
        RETURNING id, created_at
    ");
    $stmt->execute([$customerId, $cardId, $adminId, $adminName, $note, $visibility]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    response(true, [
        'id'         => (int)$row['id'],
        'created_at' => $row['created_at'],
        'agent_name' => $adminName,
    ]);
} catch (Exception $e) {
    error_log('[card-support-add-note] ' . $e->getMessage());
    response(false, null, 'Erro ao adicionar nota', 500);
}
