<?php
/**
 * OneMundo - API de Pagamentos para Pontos de Apoio
 * Gerencia comissões, saldos e pagamentos
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../database.php';

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? $_GET['action'] ?? 'resumo';

try {
    $pdo = getConnection();

    switch ($action) {

        // ═══════════════════════════════════════════════════════════════════
        // RESUMO: Ver saldo e comissões de um ponto de apoio
        // ═══════════════════════════════════════════════════════════════════
        case 'resumo':
            $ponto_id = (int)($input['ponto_apoio_id'] ?? $_GET['ponto_apoio_id'] ?? 0);

            if (!$ponto_id) {
                echo json_encode(['success' => false, 'error' => 'ponto_apoio_id obrigatório']);
                exit;
            }

            // Saldo atual
            $stmt = $pdo->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN tipo LIKE 'comissao%' OR tipo = 'bonus' THEN valor ELSE 0 END), 0) as total_ganhos,
                    COALESCE(SUM(CASE WHEN tipo = 'pagamento' THEN ABS(valor) ELSE 0 END), 0) as total_pagamentos,
                    COALESCE(SUM(valor), 0) as saldo_atual
                FROM om_ponto_apoio_financeiro
                WHERE ponto_apoio_id = ?
            ");
            $stmt->execute([$ponto_id]);
            $saldo = $stmt->fetch(PDO::FETCH_ASSOC);

            // Ganhos do mês
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(valor), 0) as ganhos_mes
                FROM om_ponto_apoio_financeiro
                WHERE ponto_apoio_id = ?
                AND tipo LIKE 'comissao%'
                AND MONTH(created_at) = MONTH(CURDATE())
                AND YEAR(created_at) = YEAR(CURDATE())
            ");
            $stmt->execute([$ponto_id]);
            $ganhosMes = $stmt->fetchColumn();

            // Quantidade de operações do mês
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(CASE WHEN tipo = 'comissao_devolucao' THEN 1 END) as recebimentos,
                    COUNT(CASE WHEN tipo = 'comissao_entrega' THEN 1 END) as despachos,
                    COUNT(CASE WHEN tipo = 'comissao_consolidacao' THEN 1 END) as consolidacoes
                FROM om_ponto_apoio_financeiro
                WHERE ponto_apoio_id = ?
                AND MONTH(created_at) = MONTH(CURDATE())
                AND YEAR(created_at) = YEAR(CURDATE())
            ");
            $stmt->execute([$ponto_id]);
            $operacoes = $stmt->fetch(PDO::FETCH_ASSOC);

            // Último pagamento
            $stmt = $pdo->prepare("
                SELECT created_at, ABS(valor) as valor
                FROM om_ponto_apoio_financeiro
                WHERE ponto_apoio_id = ? AND tipo = 'pagamento'
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$ponto_id]);
            $ultimoPagamento = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'ponto_apoio_id' => $ponto_id,
                'saldo_atual' => (float)$saldo['saldo_atual'],
                'total_ganhos' => (float)$saldo['total_ganhos'],
                'total_pagamentos' => (float)$saldo['total_pagamentos'],
                'ganhos_mes' => (float)$ganhosMes,
                'operacoes_mes' => $operacoes,
                'ultimo_pagamento' => $ultimoPagamento
            ]);
            break;

        // ═══════════════════════════════════════════════════════════════════
        // EXTRATO: Histórico de movimentações
        // ═══════════════════════════════════════════════════════════════════
        case 'extrato':
            $ponto_id = (int)($input['ponto_apoio_id'] ?? $_GET['ponto_apoio_id'] ?? 0);
            $limite = min(100, (int)($input['limite'] ?? $_GET['limite'] ?? 50));

            if (!$ponto_id) {
                echo json_encode(['success' => false, 'error' => 'ponto_apoio_id obrigatório']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT id, tipo, valor, saldo_posterior, descricao, created_at
                FROM om_ponto_apoio_financeiro
                WHERE ponto_apoio_id = ?
                ORDER BY id DESC
                LIMIT ?
            ");
            $stmt->execute([$ponto_id, $limite]);
            $extrato = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'extrato' => $extrato,
                'total' => count($extrato)
            ]);
            break;

        // ═══════════════════════════════════════════════════════════════════
        // SOLICITAR_PAGAMENTO: Ponto solicita pagamento do saldo
        // ═══════════════════════════════════════════════════════════════════
        case 'solicitar_pagamento':
            $ponto_id = (int)($input['ponto_apoio_id'] ?? 0);
            $valor = (float)($input['valor'] ?? 0);
            $tipo_pagamento = $input['tipo_pagamento'] ?? 'pix';
            $chave_pix = $input['chave_pix'] ?? '';

            if (!$ponto_id) {
                echo json_encode(['success' => false, 'error' => 'ponto_apoio_id obrigatório']);
                exit;
            }

            // Verificar saldo disponível
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(valor), 0) as saldo FROM om_ponto_apoio_financeiro WHERE ponto_apoio_id = ?");
            $stmt->execute([$ponto_id]);
            $saldoDisponivel = (float)$stmt->fetchColumn();

            // Valor mínimo para saque: R$ 20
            $valorMinimo = 20.00;

            if ($valor <= 0) {
                $valor = $saldoDisponivel; // Sacar tudo
            }

            if ($valor < $valorMinimo) {
                echo json_encode(['success' => false, 'error' => "Valor mínimo para saque: R$ " . number_format($valorMinimo, 2, ',', '.')]);
                exit;
            }

            if ($valor > $saldoDisponivel) {
                echo json_encode(['success' => false, 'error' => 'Saldo insuficiente. Disponível: R$ ' . number_format($saldoDisponivel, 2, ',', '.')]);
                exit;
            }

            // Criar solicitação de pagamento
            $stmt = $pdo->prepare("
                INSERT INTO om_ponto_apoio_pagamentos (
                    ponto_apoio_id, valor, tipo_pagamento, chave_pix, status, created_at
                ) VALUES (?, ?, ?, ?, 'pendente', NOW())
                RETURNING id
            ");
            $stmt->execute([$ponto_id, $valor, $tipo_pagamento, $chave_pix]);
            $pagamentoId = $stmt->fetchColumn();

            // Registrar débito no financeiro
            $stmt = $pdo->prepare("
                INSERT INTO om_ponto_apoio_financeiro (
                    ponto_apoio_id, tipo, referencia_tipo, referencia_id, valor,
                    saldo_anterior, saldo_posterior, descricao
                ) VALUES (?, 'pagamento', 'solicitacao', ?, ?, ?, ?, ?)
            ");
            $novoSaldo = $saldoDisponivel - $valor;
            $stmt->execute([
                $ponto_id, $pagamentoId, -$valor,
                $saldoDisponivel, $novoSaldo,
                'Solicitação de pagamento #' . $pagamentoId
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Solicitação de pagamento criada',
                'pagamento_id' => $pagamentoId,
                'valor' => $valor,
                'novo_saldo' => $novoSaldo
            ]);
            break;

        // ═══════════════════════════════════════════════════════════════════
        // PROCESSAR_PAGAMENTO: Admin processa pagamento (PIX, transferência)
        // ═══════════════════════════════════════════════════════════════════
        case 'processar_pagamento':
            $pagamento_id = (int)($input['pagamento_id'] ?? 0);
            $comprovante = $input['comprovante'] ?? '';

            if (!$pagamento_id) {
                echo json_encode(['success' => false, 'error' => 'pagamento_id obrigatório']);
                exit;
            }

            // Atualizar pagamento
            $stmt = $pdo->prepare("
                UPDATE om_ponto_apoio_pagamentos
                SET status = 'pago', comprovante = ?, pago_em = NOW()
                WHERE id = ? AND status = 'pendente'
            ");
            $stmt->execute([$comprovante, $pagamento_id]);

            if ($stmt->rowCount() === 0) {
                echo json_encode(['success' => false, 'error' => 'Pagamento não encontrado ou já processado']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'message' => 'Pagamento processado com sucesso',
                'pagamento_id' => $pagamento_id
            ]);
            break;

        // ═══════════════════════════════════════════════════════════════════
        // LISTAR_PENDENTES: Lista todos os pagamentos pendentes (para admin)
        // ═══════════════════════════════════════════════════════════════════
        case 'listar_pendentes':
            $stmt = $pdo->query("
                SELECT p.*, pa.nome_fantasia, pa.telefone, pa.email
                FROM om_ponto_apoio_pagamentos p
                JOIN om_pontos_apoio pa ON pa.id = p.ponto_apoio_id
                WHERE p.status = 'pendente'
                ORDER BY p.created_at ASC
            ");
            $pendentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'pagamentos_pendentes' => $pendentes,
                'total' => count($pendentes)
            ]);
            break;

        // ═══════════════════════════════════════════════════════════════════
        // TAXAS: Configurar taxas do ponto de apoio
        // ═══════════════════════════════════════════════════════════════════
        case 'taxas':
            $ponto_id = (int)($input['ponto_apoio_id'] ?? $_GET['ponto_apoio_id'] ?? 0);

            if (!$ponto_id) {
                echo json_encode(['success' => false, 'error' => 'ponto_apoio_id obrigatório']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT taxa_recebimento, taxa_despacho, dias_guarda_max FROM om_pontos_apoio WHERE id = ?");
            $stmt->execute([$ponto_id]);
            $taxas = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'taxas' => [
                    'recebimento' => (float)$taxas['taxa_recebimento'],
                    'despacho' => (float)$taxas['taxa_despacho'],
                    'dias_guarda_max' => (int)$taxas['dias_guarda_max']
                ]
            ]);
            break;

        default:
            echo json_encode([
                'success' => false,
                'error' => 'Ação inválida',
                'acoes_disponiveis' => ['resumo', 'extrato', 'solicitar_pagamento', 'processar_pagamento', 'listar_pendentes', 'taxas']
            ]);
    }

} catch (Exception $e) {
    error_log("[ponto-apoio-pagamentos] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}
