<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * API: Consultar Saldo
 * GET /api/financeiro/saldo.php?tipo=motorista&id=123
 * ══════════════════════════════════════════════════════════════════════════════
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once dirname(__DIR__, 2) . '/database.php';

try {
    $pdo = getDB();

    $tipo = $_GET['tipo'] ?? 'motorista';
    $id = intval($_GET['id'] ?? 0);

    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'id obrigatório']);
        exit;
    }

    if ($tipo === 'motorista') {
        // Buscar saldo
        $stmt = $pdo->prepare("SELECT * FROM om_motorista_saldo WHERE motorista_id = ?");
        $stmt->execute([$id]);
        $saldo = $stmt->fetch();

        if (!$saldo) {
            $saldo = [
                'saldo_disponivel' => 0,
                'saldo_pendente' => 0,
                'saldo_bloqueado' => 0,
                'total_ganhos' => 0,
                'total_saques' => 0
            ];
        }

        // Buscar últimas transações
        $stmt = $pdo->prepare("
            SELECT tipo, valor, descricao, created_at
            FROM om_motorista_wallet
            WHERE motorista_id = ?
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$id]);
        $transacoes = $stmt->fetchAll();

        // Buscar ganhos pendentes
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as qtd, SUM(valor_liquido) as total
            FROM om_motorista_ganhos
            WHERE motorista_id = ? AND status = 'pendente'
        ");
        $stmt->execute([$id]);
        $pendentes = $stmt->fetch();

    } else {
        // Shopper
        $stmt = $pdo->prepare("SELECT * FROM om_shopper_saldo WHERE shopper_id = ?");
        $stmt->execute([$id]);
        $saldo = $stmt->fetch();

        if (!$saldo) {
            $saldo = [
                'saldo_disponivel' => 0,
                'saldo_pendente' => 0,
                'saldo_bloqueado' => 0,
                'total_ganhos' => 0,
                'total_saques' => 0
            ];
        }

        $stmt = $pdo->prepare("
            SELECT tipo, valor, descricao, created_at
            FROM om_shopper_wallet
            WHERE shopper_id = ?
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$id]);
        $transacoes = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as qtd, SUM(valor_liquido) as total
            FROM om_shopper_ganhos
            WHERE shopper_id = ? AND status = 'pendente'
        ");
        $stmt->execute([$id]);
        $pendentes = $stmt->fetch();
    }

    echo json_encode([
        'success' => true,
        'tipo' => $tipo,
        'id' => $id,
        'saldo' => [
            'disponivel' => floatval($saldo['saldo_disponivel']),
            'disponivel_formatado' => 'R$ ' . number_format($saldo['saldo_disponivel'], 2, ',', '.'),
            'pendente' => floatval($saldo['saldo_pendente']),
            'bloqueado' => floatval($saldo['saldo_bloqueado']),
            'total' => floatval($saldo['saldo_disponivel']) + floatval($saldo['saldo_pendente'])
        ],
        'historico' => [
            'total_ganhos' => floatval($saldo['total_ganhos']),
            'total_saques' => floatval($saldo['total_saques'])
        ],
        'pendentes' => [
            'quantidade' => intval($pendentes['qtd'] ?? 0),
            'valor' => floatval($pendentes['total'] ?? 0)
        ],
        'ultimas_transacoes' => array_map(function($t) {
            return [
                'tipo' => $t['tipo'],
                'valor' => floatval($t['valor']),
                'descricao' => $t['descricao'],
                'data' => $t['created_at']
            ];
        }, $transacoes),
        'pode_sacar' => floatval($saldo['saldo_disponivel']) >= 20,
        'valor_minimo_saque' => 20.00
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("[financeiro/saldo] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}
