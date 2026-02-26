<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * POST /api/financeiro/estornar-repasse.php
 * Estorna repasse ja liberado (Admin Only)
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REQUER: Autenticacao de Admin
 * Header: Authorization: Bearer <token>
 *
 * Body: {
 *   "repasse_id": 123,
 *   "motivo": "Fraude confirmada - pedido nunca foi entregue"
 * }
 *
 * REGRAS:
 * - So pode estornar repasses em status 'liberado' ou 'pago'
 * - Motivo e OBRIGATORIO
 * - Remove o valor de saldo_disponivel
 * - Se saldo insuficiente, marca saldo como negativo ou bloqueia
 * - Registra transacao negativa na wallet
 * - Registra log de auditoria
 * - Envia notificacao ao destinatario
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once dirname(__DIR__) . "/mercado/config/auth.php";
require_once dirname(__DIR__, 2) . "/includes/classes/PushNotification.php";

try {
    $db = getDB();
    $input = getInput();

    // ═══════════════════════════════════════════════════════════════════════════
    // AUTENTICACAO ADMIN
    // ═══════════════════════════════════════════════════════════════════════════

    $auth = requireAdminAuth();
    $adminId = $auth['uid'];
    $adminName = $auth['name'] ?? "Admin #$adminId";

    // ═══════════════════════════════════════════════════════════════════════════
    // VALIDACAO DE ENTRADA
    // ═══════════════════════════════════════════════════════════════════════════

    $repasseId = (int)($input['repasse_id'] ?? 0);
    $motivo = trim($input['motivo'] ?? '');

    if (!$repasseId) {
        response(false, null, 'repasse_id e obrigatorio', 400);
    }

    if (strlen($motivo) < 10) {
        response(false, null, 'Motivo e obrigatorio (minimo 10 caracteres)', 400);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // BUSCAR REPASSE
    // ═══════════════════════════════════════════════════════════════════════════

    $stmt = $db->prepare("
        SELECT * FROM om_repasses
        WHERE id = ?
        AND status IN ('liberado', 'pago')
    ");
    $stmt->execute([$repasseId]);
    $repasse = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$repasse) {
        response(false, null, 'Repasse nao encontrado ou nao pode ser estornado (status deve ser liberado ou pago)', 404);
    }

    $tipo = $repasse['tipo'];
    $destinatarioId = $repasse['destinatario_id'];
    $valor = floatval($repasse['valor_liquido']);
    $orderId = $repasse['order_id'];

    // ═══════════════════════════════════════════════════════════════════════════
    // PROCESSAR ESTORNO
    // ═══════════════════════════════════════════════════════════════════════════

    $db->beginTransaction();

    try {
        // 1. BUSCAR SALDO ATUAL E DEBITAR
        switch ($tipo) {
            case 'shopper':
                $stmt = $db->prepare("
                    SELECT saldo_disponivel, saldo_pendente, saldo_bloqueado
                    FROM om_shopper_saldo
                    WHERE shopper_id = ?
                    FOR UPDATE
                ");
                $stmt->execute([$destinatarioId]);
                $saldo = $stmt->fetch();

                if (!$saldo) {
                    throw new Exception("Shopper #$destinatarioId nao tem registro de saldo");
                }

                $saldoAnterior = floatval($saldo['saldo_disponivel']);
                $saldoPosterior = $saldoAnterior - $valor;

                // Atualizar saldo (pode ficar negativo se ja sacou)
                $stmt = $db->prepare("
                    UPDATE om_shopper_saldo SET
                        saldo_disponivel = saldo_disponivel - ?,
                        saldo_bloqueado = saldo_bloqueado + CASE WHEN saldo_disponivel < ? THEN (? - saldo_disponivel) ELSE 0 END,
                        updated_at = NOW()
                    WHERE shopper_id = ?
                ");
                $stmt->execute([$valor, $valor, $valor, $destinatarioId]);

                // Registrar estorno na wallet
                $stmt = $db->prepare("
                    INSERT INTO om_shopper_wallet
                    (shopper_id, tipo, valor, saldo_anterior, saldo_posterior, referencia_tipo, referencia_id, descricao, status, created_at)
                    VALUES (?, 'estorno', ?, ?, ?, 'om_repasses', ?, ?, 'concluido', NOW())
                ");
                $stmt->execute([
                    $destinatarioId,
                    -$valor,
                    $saldoAnterior,
                    $saldoPosterior,
                    $repasseId,
                    "ESTORNO Pedido #$orderId: $motivo"
                ]);

                $userType = PushNotification::USER_TYPE_SHOPPER;
                break;

            case 'motorista':
                $stmt = $db->prepare("
                    SELECT saldo_disponivel, saldo_pendente, saldo_bloqueado
                    FROM om_motorista_saldo
                    WHERE motorista_id = ?
                    FOR UPDATE
                ");
                $stmt->execute([$destinatarioId]);
                $saldo = $stmt->fetch();

                if (!$saldo) {
                    throw new Exception("Motorista #$destinatarioId nao tem registro de saldo");
                }

                $saldoAnterior = floatval($saldo['saldo_disponivel']);
                $saldoPosterior = $saldoAnterior - $valor;

                $stmt = $db->prepare("
                    UPDATE om_motorista_saldo SET
                        saldo_disponivel = saldo_disponivel - ?,
                        saldo_bloqueado = saldo_bloqueado + CASE WHEN saldo_disponivel < ? THEN (? - saldo_disponivel) ELSE 0 END,
                        updated_at = NOW()
                    WHERE motorista_id = ?
                ");
                $stmt->execute([$valor, $valor, $valor, $destinatarioId]);

                $stmt = $db->prepare("
                    INSERT INTO om_motorista_wallet
                    (motorista_id, tipo, valor, saldo_anterior, saldo_posterior, referencia_tipo, referencia_id, descricao, status, created_at)
                    VALUES (?, 'estorno', ?, ?, ?, 'om_repasses', ?, ?, 'concluido', NOW())
                ");
                $stmt->execute([
                    $destinatarioId,
                    -$valor,
                    $saldoAnterior,
                    $saldoPosterior,
                    $repasseId,
                    "ESTORNO Entrega #$orderId: $motivo"
                ]);

                $userType = PushNotification::USER_TYPE_MOTORISTA;
                break;

            case 'mercado':
                $stmt = $db->prepare("
                    SELECT saldo_disponivel, saldo_pendente, saldo_bloqueado
                    FROM om_mercado_saldo
                    WHERE partner_id = ?
                    FOR UPDATE
                ");
                $stmt->execute([$destinatarioId]);
                $saldo = $stmt->fetch();

                if (!$saldo) {
                    throw new Exception("Mercado #$destinatarioId nao tem registro de saldo");
                }

                $saldoAnterior = floatval($saldo['saldo_disponivel']);
                $saldoPosterior = $saldoAnterior - $valor;

                $stmt = $db->prepare("
                    UPDATE om_mercado_saldo SET
                        saldo_disponivel = saldo_disponivel - ?,
                        saldo_bloqueado = saldo_bloqueado + CASE WHEN saldo_disponivel < ? THEN (? - saldo_disponivel) ELSE 0 END,
                        updated_at = NOW()
                    WHERE partner_id = ?
                ");
                $stmt->execute([$valor, $valor, $valor, $destinatarioId]);

                $stmt = $db->prepare("
                    INSERT INTO om_mercado_wallet
                    (partner_id, tipo, valor, saldo_anterior, saldo_posterior, referencia_tipo, referencia_id, descricao, status, created_at)
                    VALUES (?, 'estorno', ?, ?, ?, 'om_repasses', ?, ?, 'concluido', NOW())
                ");
                $stmt->execute([
                    $destinatarioId,
                    -$valor,
                    $saldoAnterior,
                    $saldoPosterior,
                    $repasseId,
                    "ESTORNO Pedido #$orderId: $motivo"
                ]);

                $userType = PushNotification::USER_TYPE_PARTNER;
                break;
        }

        // 2. ATUALIZAR STATUS DO REPASSE
        $stmt = $db->prepare("
            UPDATE om_repasses SET
                status = 'estornado',
                motivo_cancelamento = ?,
                cancelado_por_tipo = 'admin',
                cancelado_por_id = ?,
                estornado_em = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$motivo, $adminId, $repasseId]);

        // 3. REGISTRAR LOG
        $stmt = $db->prepare("
            INSERT INTO om_repasses_log
            (repasse_id, status_anterior, status_novo, executado_por_tipo, executado_por_id, observacao, created_at)
            VALUES (?, ?, 'estornado', 'admin', ?, ?, NOW())
        ");
        $stmt->execute([
            $repasseId,
            $repasse['status'],
            $adminId,
            "ESTORNO por $adminName. Valor: R$ " . number_format($valor, 2, ',', '.') . ". Motivo: $motivo"
        ]);

        // 4. LOG DE AUDITORIA
        logAudit('refund', 'repasse', $repasseId, [
            'status' => $repasse['status'],
            'valor' => $valor
        ], [
            'status' => 'estornado',
            'motivo' => $motivo,
            'admin_id' => $adminId
        ], "Admin #$adminId estornou repasse #$repasseId de R$ " . number_format($valor, 2, ',', '.'));

        $db->commit();

        // ═══════════════════════════════════════════════════════════════════════════
        // ENVIAR NOTIFICACAO
        // ═══════════════════════════════════════════════════════════════════════════

        try {
            PushNotification::getInstance()->setDb($db);
            $valorFormatado = 'R$ ' . number_format($valor, 2, ',', '.');

            om_push()->send(
                $userType,
                $destinatarioId,
                'Estorno Realizado',
                "Foi realizado um estorno de $valorFormatado referente ao pedido #$orderId. Motivo: $motivo",
                [
                    'type' => 'payment_refunded',
                    'repasse_id' => $repasseId,
                    'order_id' => $orderId,
                    'valor' => $valor,
                    'motivo' => $motivo,
                    'saldo_anterior' => $saldoAnterior,
                    'saldo_posterior' => $saldoPosterior
                ],
                null,
                ['require_interaction' => true]
            );
        } catch (Exception $pushError) {
            error_log("[estornar-repasse] Push error: " . $pushError->getMessage());
        }

        // ═══════════════════════════════════════════════════════════════════════════
        // NOTIFICACAO REALTIME
        // ═══════════════════════════════════════════════════════════════════════════

        try {
            $stmt = $db->prepare("
                INSERT INTO om_realtime_events
                (event_type, target_type, target_id, data, created_at)
                VALUES ('pagamento_estornado', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $tipo,
                $destinatarioId,
                json_encode([
                    'repasse_id' => $repasseId,
                    'order_id' => $orderId,
                    'valor' => $valor,
                    'motivo' => $motivo,
                    'saldo_posterior' => $saldoPosterior,
                    'mensagem' => 'Estorno realizado pela administracao'
                ], JSON_UNESCAPED_UNICODE)
            ]);
        } catch (Exception $realtimeError) {
            // Nao e critico
        }

        // ═══════════════════════════════════════════════════════════════════════════
        // RESPOSTA
        // ═══════════════════════════════════════════════════════════════════════════

        response(true, [
            'repasse_id' => $repasseId,
            'order_id' => $orderId,
            'tipo' => $tipo,
            'destinatario_id' => $destinatarioId,
            'valor' => $valor,
            'valor_formatado' => 'R$ ' . number_format($valor, 2, ',', '.'),
            'saldo_anterior' => $saldoAnterior,
            'saldo_posterior' => $saldoPosterior,
            'saldo_negativo' => $saldoPosterior < 0,
            'motivo' => $motivo,
            'estornado_por' => $adminName,
            'estornado_em' => date('c')
        ], 'Repasse estornado com sucesso');

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("[estornar-repasse] Erro: " . $e->getMessage());
    response(false, null, 'Erro ao estornar repasse', 500);
}
