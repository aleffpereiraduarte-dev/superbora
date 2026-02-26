<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * POST /api/financeiro/cancelar-repasse.php
 * Cancela repasse antes de liberar (Admin Only)
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REQUER: Autenticacao de Admin
 * Header: Authorization: Bearer <token>
 *
 * Body: {
 *   "repasse_id": 123,
 *   "motivo": "Reclamacao do cliente sobre qualidade da entrega"
 * }
 *
 * OU para cancelar todos de um pedido:
 * Body: {
 *   "order_id": 456,
 *   "motivo": "Pedido cancelado pelo cliente"
 * }
 *
 * REGRAS:
 * - So pode cancelar repasses em status 'hold' ou 'pendente'
 * - Motivo e OBRIGATORIO
 * - Remove o valor de saldo_pendente
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
    $orderId = (int)($input['order_id'] ?? 0);
    $motivo = trim($input['motivo'] ?? '');

    if (!$repasseId && !$orderId) {
        response(false, null, 'repasse_id ou order_id e obrigatorio', 400);
    }

    if (strlen($motivo) < 10) {
        response(false, null, 'Motivo e obrigatorio (minimo 10 caracteres)', 400);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // BUSCAR REPASSES A CANCELAR
    // ═══════════════════════════════════════════════════════════════════════════

    if ($repasseId) {
        // Cancelar repasse especifico
        $stmt = $db->prepare("
            SELECT * FROM om_repasses
            WHERE id = ?
            AND status IN ('hold', 'pendente')
        ");
        $stmt->execute([$repasseId]);
        $repasses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($repasses)) {
            response(false, null, 'Repasse nao encontrado ou ja foi processado', 404);
        }
    } else {
        // Cancelar todos os repasses do pedido
        $stmt = $db->prepare("
            SELECT * FROM om_repasses
            WHERE order_id = ?
            AND status IN ('hold', 'pendente')
        ");
        $stmt->execute([$orderId]);
        $repasses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($repasses)) {
            response(false, null, 'Nenhum repasse pendente encontrado para este pedido', 404);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // PROCESSAR CANCELAMENTOS
    // ═══════════════════════════════════════════════════════════════════════════

    $db->beginTransaction();

    try {
        $cancelados = [];
        $notificacoes = [];

        foreach ($repasses as $repasse) {
            $rId = $repasse['id'];
            $tipo = $repasse['tipo'];
            $destinatarioId = $repasse['destinatario_id'];
            $valor = floatval($repasse['valor_liquido']);
            $orderIdRepasse = $repasse['order_id'];

            // 1. ATUALIZAR STATUS DO REPASSE
            $stmt = $db->prepare("
                UPDATE om_repasses SET
                    status = 'cancelado',
                    motivo_cancelamento = ?,
                    cancelado_por_tipo = 'admin',
                    cancelado_por_id = ?,
                    cancelado_em = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$motivo, $adminId, $rId]);

            // 2. REMOVER DE SALDO_PENDENTE
            switch ($tipo) {
                case 'shopper':
                    $stmt = $db->prepare("
                        UPDATE om_shopper_saldo SET
                            saldo_pendente = GREATEST(0, saldo_pendente - ?),
                            total_ganhos = GREATEST(0, total_ganhos - ?),
                            updated_at = NOW()
                        WHERE shopper_id = ?
                    ");
                    $stmt->execute([$valor, $valor, $destinatarioId]);
                    $userType = PushNotification::USER_TYPE_SHOPPER;
                    break;

                case 'motorista':
                    $stmt = $db->prepare("
                        UPDATE om_motorista_saldo SET
                            saldo_pendente = GREATEST(0, saldo_pendente - ?),
                            total_ganhos = GREATEST(0, total_ganhos - ?),
                            updated_at = NOW()
                        WHERE motorista_id = ?
                    ");
                    $stmt->execute([$valor, $valor, $destinatarioId]);
                    $userType = PushNotification::USER_TYPE_MOTORISTA;
                    break;

                case 'mercado':
                    $stmt = $db->prepare("
                        UPDATE om_mercado_saldo SET
                            saldo_pendente = GREATEST(0, saldo_pendente - ?),
                            total_recebido = GREATEST(0, total_recebido - ?),
                            updated_at = NOW()
                        WHERE partner_id = ?
                    ");
                    $stmt->execute([$valor, $valor, $destinatarioId]);
                    $userType = PushNotification::USER_TYPE_PARTNER;
                    break;
            }

            // 3. REGISTRAR LOG
            $stmt = $db->prepare("
                INSERT INTO om_repasses_log
                (repasse_id, status_anterior, status_novo, executado_por_tipo, executado_por_id, observacao, created_at)
                VALUES (?, ?, 'cancelado', 'admin', ?, ?, NOW())
            ");
            $stmt->execute([
                $rId,
                $repasse['status'],
                $adminId,
                "Cancelado por $adminName. Motivo: $motivo"
            ]);

            $cancelados[] = [
                'repasse_id' => $rId,
                'tipo' => $tipo,
                'destinatario_id' => $destinatarioId,
                'valor' => $valor,
                'order_id' => $orderIdRepasse
            ];

            // Agrupar notificacoes por destinatario
            $key = "$tipo:$destinatarioId";
            if (!isset($notificacoes[$key])) {
                $notificacoes[$key] = [
                    'tipo' => $tipo,
                    'user_type' => $userType,
                    'destinatario_id' => $destinatarioId,
                    'valor_total' => 0,
                    'pedidos' => []
                ];
            }
            $notificacoes[$key]['valor_total'] += $valor;
            $notificacoes[$key]['pedidos'][] = $orderIdRepasse;
        }

        // 4. LOG DE AUDITORIA
        logAudit('cancel', 'repasses', null, null, [
            'admin_id' => $adminId,
            'motivo' => $motivo,
            'repasses' => $cancelados
        ], "Admin #$adminId cancelou " . count($cancelados) . " repasse(s). Motivo: $motivo");

        $db->commit();

        // ═══════════════════════════════════════════════════════════════════════════
        // ENVIAR NOTIFICACOES
        // ═══════════════════════════════════════════════════════════════════════════

        try {
            PushNotification::getInstance()->setDb($db);

            // Buscar configuracao
            $stmt = $db->prepare("SELECT valor FROM om_repasses_config WHERE chave = 'notificar_cancelamento'");
            $stmt->execute();
            $notificar = (bool)($stmt->fetchColumn() ?? true);

            if ($notificar) {
                foreach ($notificacoes as $notif) {
                    $valorFormatado = 'R$ ' . number_format($notif['valor_total'], 2, ',', '.');
                    $pedidosStr = implode(', #', $notif['pedidos']);

                    om_push()->send(
                        $notif['user_type'],
                        $notif['destinatario_id'],
                        'Pagamento Cancelado',
                        "O pagamento de $valorFormatado do(s) pedido(s) #$pedidosStr foi cancelado. Motivo: $motivo",
                        [
                            'type' => 'payment_cancelled',
                            'valor' => $notif['valor_total'],
                            'motivo' => $motivo,
                            'pedidos' => $notif['pedidos']
                        ],
                        null,
                        ['require_interaction' => true]
                    );
                }
            }
        } catch (Exception $pushError) {
            error_log("[cancelar-repasse] Push error: " . $pushError->getMessage());
        }

        // ═══════════════════════════════════════════════════════════════════════════
        // NOTIFICACAO REALTIME
        // ═══════════════════════════════════════════════════════════════════════════

        try {
            foreach ($notificacoes as $notif) {
                $stmt = $db->prepare("
                    INSERT INTO om_realtime_events
                    (event_type, target_type, target_id, data, created_at)
                    VALUES ('pagamento_cancelado', ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $notif['tipo'],
                    $notif['destinatario_id'],
                    json_encode([
                        'valor' => $notif['valor_total'],
                        'motivo' => $motivo,
                        'pedidos' => $notif['pedidos'],
                        'mensagem' => 'Pagamento cancelado pela administracao'
                    ], JSON_UNESCAPED_UNICODE)
                ]);
            }
        } catch (Exception $realtimeError) {
            // Nao e critico
        }

        // ═══════════════════════════════════════════════════════════════════════════
        // RESPOSTA
        // ═══════════════════════════════════════════════════════════════════════════

        $valorTotal = array_sum(array_column($cancelados, 'valor'));

        response(true, [
            'cancelados' => count($cancelados),
            'valor_total' => $valorTotal,
            'valor_total_formatado' => 'R$ ' . number_format($valorTotal, 2, ',', '.'),
            'detalhes' => $cancelados,
            'motivo' => $motivo,
            'cancelado_por' => $adminName,
            'cancelado_em' => date('c')
        ], count($cancelados) . ' repasse(s) cancelado(s) com sucesso');

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("[cancelar-repasse] Erro: " . $e->getMessage());
    response(false, null, 'Erro ao cancelar repasse', 500);
}
