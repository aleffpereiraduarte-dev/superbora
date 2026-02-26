<?php
/**
 * POST /api/mercado/group-order/join.php
 * Join a group order by share code
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

try {
    $input = getInput();
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $shareCode = strtoupper(trim($input['share_code'] ?? ''));
    $guestName = trim($input['customer_name'] ?? '');

    if (empty($shareCode)) response(false, null, "Codigo obrigatorio", 400);

    // Find group order
    $stmt = $db->prepare("
        SELECT g.*, p.name as partner_name, p.logo, p.banner, p.categoria
        FROM om_market_group_orders g
        JOIN om_market_partners p ON p.partner_id = g.partner_id
        WHERE g.share_code = ?
    ");
    $stmt->execute([$shareCode]);
    $group = $stmt->fetch();

    if (!$group) response(false, null, "Pedido em grupo nao encontrado", 404);
    if ($group['status'] !== 'active') response(false, null, "Este pedido em grupo ja foi encerrado", 400);
    if (strtotime($group['expires_at']) < time()) {
        $db->prepare("UPDATE om_market_group_orders SET status = 'expired' WHERE id = ?")->execute([$group['id']]);
        response(false, null, "Este pedido em grupo expirou", 400);
    }

    // Check if user is authenticated
    $customerId = null;
    $token = om_auth()->getTokenFromRequest();
    if ($token) {
        $payload = om_auth()->validateToken($token);
        if ($payload && $payload['type'] === 'customer') {
            $customerId = (int)$payload['uid'];
        }
    }

    // Check if already a participant and add if not, using a transaction to prevent race conditions
    $participantId = null;
    $db->beginTransaction();
    try {
        if ($customerId) {
            // Lock the row if it exists to prevent duplicate inserts
            $check = $db->prepare("SELECT id FROM om_market_group_order_participants WHERE group_order_id = ? AND customer_id = ? FOR UPDATE");
            $check->execute([$group['id'], $customerId]);
            $existing = $check->fetch();
            if ($existing) {
                $participantId = (int)$existing['id'];
            }
        }

        // Add as participant if not already
        if (!$participantId) {
            if (!$customerId && empty($guestName)) {
                $db->rollBack();
                response(false, null, "Informe seu nome para participar", 400);
            }
            $stmt = $db->prepare("
                INSERT INTO om_market_group_order_participants (group_order_id, customer_id, guest_name)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$group['id'], $customerId, $customerId ? null : $guestName]);
            $participantId = (int)$db->lastInsertId();
        }
        $db->commit();
    } catch (Exception $txEx) {
        $db->rollBack();
        // If it's a unique constraint violation, the participant was inserted concurrently — retry fetch
        if ($customerId && strpos($txEx->getMessage(), 'unique') !== false) {
            $check = $db->prepare("SELECT id FROM om_market_group_order_participants WHERE group_order_id = ? AND customer_id = ?");
            $check->execute([$group['id'], $customerId]);
            $existing = $check->fetch();
            if ($existing) {
                $participantId = (int)$existing['id'];
            } else {
                throw $txEx;
            }
        } else {
            throw $txEx;
        }
    }

    // Get participant name
    $participantName = $guestName;
    if ($customerId) {
        $custStmt = $db->prepare("SELECT name FROM om_customers WHERE customer_id = ?");
        $custStmt->execute([$customerId]);
        $cust = $custStmt->fetch();
        $participantName = $cust ? $cust['name'] : 'Participante';
    }

    response(true, [
        'group_order_id' => (int)$group['id'],
        'participant_id' => $participantId,
        'participant_name' => $participantName,
        'is_creator' => ($customerId && $customerId === (int)$group['creator_id']),
        'partner_id' => (int)$group['partner_id'],
        'partner' => [
            'id' => (int)$group['partner_id'],
            'nome' => $group['partner_name'],
            'logo' => $group['logo'],
            'banner' => $group['banner'],
            'categoria' => $group['categoria'],
        ],
        'expires_at' => $group['expires_at'],
    ], "Voce entrou no pedido em grupo!");

} catch (Exception $e) {
    error_log("[API Group Order Join] Erro: " . $e->getMessage());
    response(false, null, "Erro ao entrar no pedido em grupo", 500);
}
