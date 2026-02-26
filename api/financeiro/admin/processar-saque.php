<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * API: Admin - Processar Saque
 * POST /api/financeiro/admin/processar-saque.php
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REQUER: Autenticação de Admin
 *
 * Body: {
 *   "saque_id": 123,
 *   "acao": "aprovar",  // "aprovar", "rejeitar", "pagar"
 *   "motivo": "..."     // obrigatório se rejeitar
 * }
 *
 * SEGURANÇA:
 * - ✅ Autenticação admin obrigatória
 * - ✅ Validação de transições de estado
 * - ✅ Auditoria completa de todas ações
 * - ✅ Transações ACID com locks
 * - ✅ Prepared statements (sem SQL injection)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

// Carregar middleware de autenticação
require_once __DIR__ . '/../config/auth.php';

try {
    // ═══════════════════════════════════════════════════════════════════
    // AUTENTICAÇÃO OBRIGATÓRIA
    // ═══════════════════════════════════════════════════════════════════
    $admin = requireAdminAuth();
    $admin_id = $admin['uid'];

    $pdo = getDB();

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $saque_id = intval($input['saque_id'] ?? 0);
    $acao = $input['acao'] ?? '';
    $motivo = trim($input['motivo'] ?? '');
    $comprovante = $input['comprovante_url'] ?? null;
    $transaction_id = $input['transaction_id'] ?? null;

    // Validação de entrada
    if (!$saque_id || !$acao) {
        echo json_encode(['success' => false, 'error' => 'saque_id e acao obrigatórios']);
        exit;
    }

    if (!in_array($acao, ['aprovar', 'rejeitar', 'pagar'])) {
        echo json_encode(['success' => false, 'error' => 'Ação inválida. Use: aprovar, rejeitar ou pagar']);
        exit;
    }

    // ═══════════════════════════════════════════════════════════════════
    // TRANSAÇÃO COM LOCK PARA EVITAR RACE CONDITIONS
    // ═══════════════════════════════════════════════════════════════════
    $pdo->beginTransaction();

    try {
        // Buscar saque COM LOCK para evitar processamento duplo
        $stmt = $pdo->prepare("SELECT * FROM om_saques WHERE id = ? FOR UPDATE");
        $stmt->execute([$saque_id]);
        $saque = $stmt->fetch();

        if (!$saque) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Saque não encontrado']);
            exit;
        }

        $status_atual = $saque['status'];
        $tipo = $saque['usuario_tipo'];
        $id = $saque['usuario_id'];
        $valor = floatval($saque['valor_solicitado']);

        // Tabelas dinâmicas (usando whitelists para segurança)
        $tabelas_validas = ['motorista', 'shopper'];
        if (!in_array($tipo, $tabelas_validas)) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Tipo de usuário inválido']);
            exit;
        }

        $tabela_saldo = $tipo === 'motorista' ? 'om_motorista_saldo' : 'om_shopper_saldo';
        $tabela_wallet = $tipo === 'motorista' ? 'om_motorista_wallet' : 'om_shopper_wallet';
        $campo_id = $tipo === 'motorista' ? 'motorista_id' : 'shopper_id';

        // ═══════════════════════════════════════════════════════════════════
        // VALIDAR TRANSIÇÃO DE ESTADO
        // ═══════════════════════════════════════════════════════════════════
        $novo_status = match($acao) {
            'aprovar' => 'aprovado',
            'rejeitar' => 'rejeitado',
            'pagar' => 'pago',
            default => null
        };

        try {
            validateSaqueTransition($status_atual, $novo_status);
        } catch (InvalidArgumentException $e) {
            $pdo->rollBack();
            error_log("[financeiro/admin/processar-saque] Transicao invalida: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Operacao nao permitida para o status atual']);
            exit;
        }

        // ═══════════════════════════════════════════════════════════════════
        // PROCESSAR AÇÃO
        // ═══════════════════════════════════════════════════════════════════

        switch ($acao) {
            case 'aprovar':
                $stmt = $pdo->prepare("
                    UPDATE om_saques SET
                        status = 'aprovado',
                        approved_at = NOW(),
                        approved_by = ?
                    WHERE id = ?
                ");
                $stmt->execute([$admin_id, $saque_id]);

                $mensagem = 'Saque aprovado! Será processado em breve.';
                break;

            case 'rejeitar':
                if (empty($motivo)) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => 'Motivo obrigatório para rejeição']);
                    exit;
                }

                // Lock na tabela de saldo para evitar race condition
                $stmt = $pdo->prepare("SELECT * FROM $tabela_saldo WHERE $campo_id = ? FOR UPDATE");
                $stmt->execute([$id]);

                // Devolver saldo
                $stmt = $pdo->prepare("
                    UPDATE $tabela_saldo SET
                        saldo_disponivel = saldo_disponivel + ?,
                        saldo_bloqueado = GREATEST(0, saldo_bloqueado - ?)
                    WHERE $campo_id = ?
                ");
                $stmt->execute([$valor, $valor, $id]);

                // Atualizar saque
                $stmt = $pdo->prepare("
                    UPDATE om_saques SET
                        status = 'rejeitado',
                        motivo_rejeicao = ?,
                        rejected_at = NOW(),
                        rejected_by = ?
                    WHERE id = ?
                ");
                $stmt->execute([$motivo, $admin_id, $saque_id]);

                // Atualizar wallet
                $stmt = $pdo->prepare("
                    UPDATE $tabela_wallet SET status = 'cancelado'
                    WHERE referencia_tipo = 'om_saques' AND referencia_id = ?
                ");
                $stmt->execute([$saque_id]);

                $mensagem = 'Saque rejeitado. Saldo devolvido.';
                break;

            case 'pagar':
                // Lock na tabela de saldo
                $stmt = $pdo->prepare("SELECT * FROM $tabela_saldo WHERE $campo_id = ? FOR UPDATE");
                $stmt->execute([$id]);

                // Verificar se tem saldo bloqueado suficiente
                $stmt = $pdo->prepare("SELECT saldo_bloqueado FROM $tabela_saldo WHERE $campo_id = ?");
                $stmt->execute([$id]);
                $saldo_bloqueado = floatval($stmt->fetchColumn() ?: 0);

                if ($saldo_bloqueado < $valor) {
                    $pdo->rollBack();
                    echo json_encode([
                        'success' => false,
                        'error' => 'Saldo bloqueado insuficiente para pagamento',
                        'saldo_bloqueado' => $saldo_bloqueado,
                        'valor_saque' => $valor
                    ]);
                    exit;
                }

                // Remover do bloqueado, adicionar ao total de saques
                $stmt = $pdo->prepare("
                    UPDATE $tabela_saldo SET
                        saldo_bloqueado = saldo_bloqueado - ?,
                        total_saques = total_saques + ?
                    WHERE $campo_id = ?
                ");
                $stmt->execute([$valor, $valor, $id]);

                // Atualizar saque
                $stmt = $pdo->prepare("
                    UPDATE om_saques SET
                        status = 'pago',
                        paid_at = NOW(),
                        paid_by = ?,
                        comprovante_url = ?,
                        transaction_id = ?
                    WHERE id = ?
                ");
                $stmt->execute([$admin_id, $comprovante, $transaction_id, $saque_id]);

                // Atualizar wallet
                $stmt = $pdo->prepare("
                    UPDATE $tabela_wallet SET status = 'concluido'
                    WHERE referencia_tipo = 'om_saques' AND referencia_id = ?
                ");
                $stmt->execute([$saque_id]);

                $mensagem = 'Saque pago com sucesso!';
                break;
        }

        $pdo->commit();

        // ═══════════════════════════════════════════════════════════════════
        // AUDITORIA
        // ═══════════════════════════════════════════════════════════════════
        logFinanceiroAudit(
            $acao === 'aprovar' ? 'approve' : ($acao === 'rejeitar' ? 'reject' : 'pay'),
            'saque',
            $saque_id,
            ['status' => $status_atual],
            ['status' => $novo_status, 'motivo' => $motivo, 'valor' => $valor],
            "$acao saque #$saque_id de R$ " . number_format($valor, 2, ',', '.') . " para $tipo #$id"
        );

        // Buscar saque atualizado
        $stmt = $pdo->prepare("SELECT * FROM om_saques WHERE id = ?");
        $stmt->execute([$saque_id]);
        $saque = $stmt->fetch();

        echo json_encode([
            'success' => true,
            'message' => $mensagem,
            'saque' => [
                'id' => $saque['id'],
                'valor' => floatval($saque['valor_solicitado']),
                'status' => $saque['status'],
                'usuario' => $tipo . ' #' . $id
            ],
            'processado_por' => [
                'admin_id' => $admin_id,
                'acao' => $acao,
                'data' => date('c')
            ]
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("[processar-saque] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao processar saque. Tente novamente.']);
}
