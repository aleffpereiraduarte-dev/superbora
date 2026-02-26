<?php
/**
 * GET/POST /api/mercado/orders/bill-split.php
 * GET  ?code=XXX  - Ver divisao por codigo
 * POST            - Criar divisao ou pagar parte
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/rate-limit.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Rate limiting: 20 requests per 15 minutes per IP
    $ip = getRateLimitIP();
    if (!checkRateLimit("bill_split_{$ip}", 20, 15)) {
        response(false, null, "Muitas requisicoes. Tente novamente em 15 minutos.", 429);
    }

    $method = $_SERVER['REQUEST_METHOD'];

    // GET - Ver divisao por codigo (publico)
    if ($method === 'GET') {
        $code = strtoupper(trim($_GET['code'] ?? ''));

        if (empty($code)) {
            response(false, null, "Codigo obrigatorio", 400);
        }

        $stmt = $db->prepare("
            SELECT bs.*, o.order_number, o.total as order_total
            FROM om_bill_splits bs
            INNER JOIN om_market_orders o ON bs.order_id = o.order_id
            WHERE bs.share_code = ? AND bs.status != 'cancelled'
        ");
        $stmt->execute([$code]);
        $split = $stmt->fetch();

        if (!$split) {
            response(false, null, "Divisao nao encontrada", 404);
        }

        // Buscar participantes
        $stmt = $db->prepare("
            SELECT id, name, email, amount, status, paid_at
            FROM om_bill_split_participants
            WHERE split_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$split['id']]);
        $participants = $stmt->fetchAll();

        $paidAmount = 0;
        foreach ($participants as $p) {
            if ($p['status'] === 'paid') {
                $paidAmount += (float)$p['amount'];
            }
        }

        response(true, [
            'split' => [
                'id' => (int)$split['id'],
                'order_number' => $split['order_number'],
                'total_amount' => (float)$split['total_amount'],
                'split_type' => $split['split_type'],
                'status' => $split['status'],
                'paid_amount' => $paidAmount,
                'remaining_amount' => (float)$split['total_amount'] - $paidAmount,
                'expires_at' => $split['expires_at'],
            ],
            'participants' => array_map(function($p) {
                return [
                    'id' => (int)$p['id'],
                    'name' => $p['name'],
                    'email' => $p['email'],
                    'amount' => (float)$p['amount'],
                    'amount_formatted' => 'R$ ' . number_format($p['amount'], 2, ',', '.'),
                    'status' => $p['status'],
                    'paid_at' => $p['paid_at'],
                ];
            }, $participants)
        ]);
    }

    // POST - Criar divisao ou pagar
    if ($method === 'POST') {
        $token = om_auth()->getTokenFromRequest();
        if (!$token) response(false, null, "Token ausente", 401);

        $payload = om_auth()->validateToken($token);
        if (!$payload || $payload['type'] !== 'customer') {
            response(false, null, "Nao autorizado", 401);
        }

        $customerId = (int)$payload['uid'];
        $input = getInput();
        $action = $input['action'] ?? 'create';

        // Criar divisao
        if ($action === 'create') {
            $orderId = (int)($input['order_id'] ?? 0);
            $splitType = $input['split_type'] ?? 'equal';
            $participants = $input['participants'] ?? [];

            if (!$orderId) {
                response(false, null, "ID do pedido obrigatorio", 400);
            }

            if (empty($participants) || count($participants) < 2) {
                response(false, null, "Minimo 2 participantes", 400);
            }

            // Verificar pedido
            $stmt = $db->prepare("
                SELECT order_id, total, customer_id
                FROM om_market_orders
                WHERE order_id = ? AND customer_id = ?
            ");
            $stmt->execute([$orderId, $customerId]);
            $order = $stmt->fetch();

            if (!$order) {
                response(false, null, "Pedido nao encontrado", 404);
            }

            // Verificar se ja tem divisao
            $stmt = $db->prepare("
                SELECT id FROM om_bill_splits
                WHERE order_id = ? AND status != 'cancelled'
            ");
            $stmt->execute([$orderId]);
            if ($stmt->fetch()) {
                response(false, null, "Este pedido ja tem uma divisao ativa", 400);
            }

            $total = (float)$order['total'];
            $shareCode = strtoupper(bin2hex(random_bytes(4)));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $db->beginTransaction();

            try {
                // Criar split
                $stmt = $db->prepare("
                    INSERT INTO om_bill_splits
                    (order_id, created_by, share_code, total_amount, split_type, expires_at)
                    VALUES (?, ?, ?, ?, ?, ?)
                    RETURNING id
                ");
                $stmt->execute([$orderId, $customerId, $shareCode, $total, $splitType, $expiresAt]);
                $splitId = (int)$stmt->fetch()['id'];

                // Calcular valores por participante
                $participantCount = count($participants);
                $amountEach = round($total / $participantCount, 2);
                $remainder = $total - ($amountEach * $participantCount);

                // Adicionar participantes
                $stmtPart = $db->prepare("
                    INSERT INTO om_bill_split_participants
                    (split_id, customer_id, name, email, phone, amount)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                foreach ($participants as $i => $p) {
                    $partAmount = $amountEach;
                    if ($i === 0) {
                        $partAmount += $remainder; // Primeiro participante pega a diferenca
                    }

                    $stmtPart->execute([
                        $splitId,
                        $p['customer_id'] ?? null,
                        $p['name'] ?? 'Participante ' . ($i + 1),
                        $p['email'] ?? null,
                        $p['phone'] ?? null,
                        $partAmount
                    ]);
                }

                $db->commit();

                response(true, [
                    'split_id' => $splitId,
                    'share_code' => $shareCode,
                    'share_url' => "https://superbora.com/dividir/{$shareCode}",
                    'amount_each' => $amountEach,
                    'expires_at' => $expiresAt,
                    'message' => 'Divisao criada! Compartilhe o codigo com os participantes.'
                ]);

            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        }

        // Pagar parte
        if ($action === 'pay') {
            $participantId = (int)($input['participant_id'] ?? 0);
            $paymentMethod = $input['payment_method'] ?? 'pix';

            if (!$participantId) {
                response(false, null, "ID do participante obrigatorio", 400);
            }

            $stmt = $db->prepare("
                SELECT p.*, s.id as split_id, s.total_amount, s.status as split_status
                FROM om_bill_split_participants p
                INNER JOIN om_bill_splits s ON p.split_id = s.id
                WHERE p.id = ?
            ");
            $stmt->execute([$participantId]);
            $participant = $stmt->fetch();

            if (!$participant) {
                response(false, null, "Participante nao encontrado", 404);
            }

            // SECURITY: Verify the authenticated customer is the participant
            if ((int)($participant['customer_id'] ?? 0) !== $customerId) {
                response(false, null, "Voce nao tem permissao para pagar esta parte", 403);
            }

            if ($participant['status'] === 'paid') {
                response(false, null, "Esta parte ja foi paga", 400);
            }

            // Marcar como pago
            $db->prepare("
                UPDATE om_bill_split_participants
                SET status = 'paid', paid_at = NOW(), payment_reference = ?
                WHERE id = ?
            ")->execute(['MANUAL_' . time(), $participantId]);

            // Verificar se todos pagaram
            $stmt = $db->prepare("
                SELECT COUNT(*) as total,
                       SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid
                FROM om_bill_split_participants
                WHERE split_id = ?
            ");
            $stmt->execute([$participant['split_id']]);
            $counts = $stmt->fetch();

            if ($counts['total'] == $counts['paid']) {
                $db->prepare("
                    UPDATE om_bill_splits SET status = 'complete'
                    WHERE id = ?
                ")->execute([$participant['split_id']]);
            } else {
                $db->prepare("
                    UPDATE om_bill_splits SET status = 'partial'
                    WHERE id = ?
                ")->execute([$participant['split_id']]);
            }

            response(true, [
                'message' => 'Pagamento registrado!',
                'amount' => (float)$participant['amount']
            ]);
        }
    }

} catch (Exception $e) {
    error_log("[orders/bill-split] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar divisao", 500);
}
