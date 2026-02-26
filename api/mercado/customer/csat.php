<?php
/**
 * GET/POST /api/mercado/customer/csat.php
 * Customer satisfaction survey after ticket/dispute resolution
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Table om_support_csat created via migration

    $method = $_SERVER['REQUEST_METHOD'];

    // FIX: requireCustomerAuth() takes no arguments and returns int (customer_id), not a payload
    $customerId = requireCustomerAuth();

    // GET — check if already rated + list pending
    if ($method === 'GET') {
        $refType = $_GET['ref_type'] ?? null;
        $refId = (int)($_GET['ref_id'] ?? 0);

        // Check specific rating — FIX: validate refType on GET too
        if ($refType && $refId) {
            if (!in_array($refType, ['ticket', 'dispute'], true)) {
                response(false, null, "ref_type invalido", 400);
            }
            $stmt = $db->prepare("
                SELECT * FROM om_support_csat
                WHERE customer_id = ? AND referencia_tipo = ? AND referencia_id = ?
            ");
            $stmt->execute([$customerId, $refType, $refId]);
            $existing = $stmt->fetch();
            response(true, ['rated' => (bool)$existing, 'csat' => $existing ?: null]);
        }

        // List pending surveys (resolved tickets/disputes without CSAT)
        $pending = [];

        // Resolved tickets
        try {
            $stmt = $db->prepare("
                SELECT t.id, t.assunto as subject, 'ticket' as ref_type, t.updated_at
                FROM om_support_tickets t
                LEFT JOIN om_support_csat c ON c.referencia_tipo = 'ticket' AND c.referencia_id = t.id AND c.customer_id = ?
                WHERE t.entidade_tipo = 'customer' AND t.entidade_id = ?
                AND t.status IN ('resolvido','fechado')
                AND t.updated_at > NOW() - INTERVAL '7 days'
                AND c.id IS NULL
                ORDER BY t.updated_at DESC
                LIMIT 5
            ");
            $stmt->execute([$customerId, $customerId]);
            foreach ($stmt->fetchAll() as $row) {
                $pending[] = ['ref_type' => 'ticket', 'ref_id' => $row['id'], 'subject' => $row['subject'], 'date' => $row['updated_at']];
            }
        } catch (Exception $e) {
            // Table may not exist yet
        }

        // Resolved disputes
        try {
            $stmt = $db->prepare("
                SELECT d.dispute_id as id, CONCAT('Disputa #', d.dispute_id) as subject, 'dispute' as ref_type, d.updated_at
                FROM om_order_disputes d
                LEFT JOIN om_support_csat c ON c.referencia_tipo = 'dispute' AND c.referencia_id = d.dispute_id AND c.customer_id = ?
                WHERE d.customer_id = ?
                AND d.status IN ('resolved','closed','auto_resolved')
                AND d.updated_at > NOW() - INTERVAL '7 days'
                AND c.id IS NULL
                ORDER BY d.updated_at DESC
                LIMIT 5
            ");
            $stmt->execute([$customerId, $customerId]);
            foreach ($stmt->fetchAll() as $row) {
                $pending[] = ['ref_type' => 'dispute', 'ref_id' => $row['id'], 'subject' => $row['subject'], 'date' => $row['updated_at']];
            }
        } catch (Exception $e) {
            // Table may not exist yet
        }

        response(true, ['pending' => $pending]);
    }

    // POST — submit rating
    if ($method === 'POST') {
        $input = getInput();
        $refType = trim($input['ref_type'] ?? '');
        $refId = (int)($input['ref_id'] ?? 0);
        $rating = (int)($input['rating'] ?? 0);
        $comentario = trim($input['comentario'] ?? '');

        if (!in_array($refType, ['ticket', 'dispute'], true)) response(false, null, "ref_type invalido", 400);
        if (!$refId) response(false, null, "ref_id obrigatorio", 400);
        if ($rating < 1 || $rating > 5) response(false, null, "rating deve ser 1-5", 400);

        // FIX: Enforce length limit on comentario to prevent abuse
        if (mb_strlen($comentario) > 2000) {
            response(false, null, "Comentario muito longo (max 2000 caracteres)", 400);
        }

        // Ownership check: verify the referenced entity belongs to this customer
        if ($refType === 'ticket') {
            $stmtOwner = $db->prepare("SELECT 1 FROM om_support_tickets WHERE id = ? AND entidade_tipo = 'customer' AND entidade_id = ?");
            $stmtOwner->execute([$refId, $customerId]);
            if (!$stmtOwner->fetch()) {
                response(false, null, "Ticket nao encontrado ou nao pertence a voce", 404);
            }
        } elseif ($refType === 'dispute') {
            $stmtOwner = $db->prepare("SELECT 1 FROM om_order_disputes WHERE dispute_id = ? AND customer_id = ?");
            $stmtOwner->execute([$refId, $customerId]);
            if (!$stmtOwner->fetch()) {
                response(false, null, "Disputa nao encontrada ou nao pertence a voce", 404);
            }
        }

        // Check not already rated
        $stmt = $db->prepare("SELECT id FROM om_support_csat WHERE customer_id = ? AND referencia_tipo = ? AND referencia_id = ?");
        $stmt->execute([$customerId, $refType, $refId]);
        if ($stmt->fetch()) response(false, null, "Ja avaliado", 409);

        $db->prepare("
            INSERT INTO om_support_csat (customer_id, referencia_tipo, referencia_id, rating, comentario)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$customerId, $refType, $refId, $rating, $comentario ?: null]);

        response(true, ['message' => 'Avaliacao registrada']);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[customer/csat] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
