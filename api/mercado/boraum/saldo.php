<?php
/**
 * GET /api/mercado/boraum/saldo.php
 * Saldo e historico da carteira do passageiro BoraUm
 *
 * GET                      - Saldo atual + ultimas 10 transacoes
 * GET ?historico=1&page=N  - Historico completo paginado (20 por pagina)
 * GET ?resumo=1            - Resumo: total creditos, total debitos, saldo atual
 *
 * Tabelas: boraum_passageiros.saldo, om_boraum_passenger_wallet
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
setCorsHeaders();
$db = getDB();
$user = requirePassageiro($db);

$passageiroId = $user['passageiro_id'];

if ($passageiroId <= 0) {
    response(false, null, "Perfil nao vinculado", 403);
}
$method = $_SERVER['REQUEST_METHOD'];

// Labels em portugues para tipos de transacao
$tipoLabels = [
    'credit'  => 'Credito',
    'debit'   => 'Debito',
    'bonus'   => 'Bonus',
    'refund'  => 'Reembolso',
    'topup'   => 'Recarga',
];

/**
 * Formata uma transacao para resposta da API
 */
function formatTransaction(array $row, array $labels): array {
    $tipo = $row['tipo'] ?? '';
    $valor = (float)$row['valor'];
    $isPositive = in_array($tipo, ['credit', 'bonus', 'refund', 'topup'], true);

    return [
        "id"          => (int)$row['id'],
        "tipo"        => $tipo,
        "tipo_label"  => $labels[$tipo] ?? ucfirst($tipo),
        "valor"       => $valor,
        "valor_formatado" => ($isPositive ? '+' : '-') . 'R$ ' . number_format(abs($valor), 2, ',', '.'),
        "descricao"   => $row['descricao'] ?? '',
        "referencia"  => $row['referencia'] ?? null,
        "saldo_apos"  => (float)($row['saldo_apos'] ?? 0),
        "saldo_apos_formatado" => 'R$ ' . number_format((float)($row['saldo_apos'] ?? 0), 2, ',', '.'),
        "created_at"  => $row['created_at'],
    ];
}

try {

    if ($method !== 'GET') {
        response(false, null, "Metodo nao permitido.", 405);
    }

    // =========================================================================
    // GET ?resumo=1 - Resumo financeiro
    // =========================================================================
    if (!empty($_GET['resumo'])) {

        $saldoAtual = (float)$user['saldo'];

        // Total de creditos (credit + bonus + refund + topup)
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(valor), 0) AS total
            FROM om_boraum_passenger_wallet
            WHERE passageiro_id = ? AND tipo IN ('credit', 'bonus', 'refund', 'topup')
        ");
        $stmt->execute([$passageiroId]);
        $totalCreditos = (float)$stmt->fetch()['total'];

        // Total de debitos
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(valor), 0) AS total
            FROM om_boraum_passenger_wallet
            WHERE passageiro_id = ? AND tipo = 'debit'
        ");
        $stmt->execute([$passageiroId]);
        $totalDebitos = (float)$stmt->fetch()['total'];

        // Contagem de transacoes
        $stmt = $db->prepare("
            SELECT COUNT(*) AS total
            FROM om_boraum_passenger_wallet
            WHERE passageiro_id = ?
        ");
        $stmt->execute([$passageiroId]);
        $totalTransacoes = (int)$stmt->fetch()['total'];

        response(true, [
            "saldo" => $saldoAtual,
            "saldo_formatado" => 'R$ ' . number_format($saldoAtual, 2, ',', '.'),
            "total_creditos" => $totalCreditos,
            "total_creditos_formatado" => 'R$ ' . number_format($totalCreditos, 2, ',', '.'),
            "total_debitos" => abs($totalDebitos),
            "total_debitos_formatado" => 'R$ ' . number_format(abs($totalDebitos), 2, ',', '.'),
            "total_transacoes" => $totalTransacoes,
        ]);
    }

    // =========================================================================
    // GET ?historico=1&page=N - Historico completo paginado
    // =========================================================================
    elseif (!empty($_GET['historico'])) {

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        // Contagem total
        $stmt = $db->prepare("
            SELECT COUNT(*) AS total
            FROM om_boraum_passenger_wallet
            WHERE passageiro_id = ?
        ");
        $stmt->execute([$passageiroId]);
        $totalRows = (int)$stmt->fetch()['total'];
        $totalPages = max(1, (int)ceil($totalRows / $perPage));

        // Buscar transacoes da pagina
        $stmt = $db->prepare("
            SELECT id, tipo, valor, descricao, referencia, saldo_apos, created_at
            FROM om_boraum_passenger_wallet
            WHERE passageiro_id = ?
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $passageiroId, PDO::PARAM_INT);
        $stmt->bindValue(2, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $transacoes = [];
        foreach ($rows as $r) {
            $transacoes[] = formatTransaction($r, $tipoLabels);
        }

        response(true, [
            "saldo" => (float)$user['saldo'],
            "saldo_formatado" => 'R$ ' . number_format((float)$user['saldo'], 2, ',', '.'),
            "transacoes" => $transacoes,
            "paginacao" => [
                "page"        => $page,
                "per_page"    => $perPage,
                "total"       => $totalRows,
                "total_pages" => $totalPages,
                "has_next"    => $page < $totalPages,
                "has_prev"    => $page > 1,
            ],
        ]);
    }

    // =========================================================================
    // GET (padrao) - Saldo atual + ultimas 10 transacoes
    // =========================================================================
    else {

        $saldoAtual = (float)$user['saldo'];

        // Ultimas 10 transacoes
        $stmt = $db->prepare("
            SELECT id, tipo, valor, descricao, referencia, saldo_apos, created_at
            FROM om_boraum_passenger_wallet
            WHERE passageiro_id = ?
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$passageiroId]);
        $rows = $stmt->fetchAll();

        $transacoes = [];
        foreach ($rows as $r) {
            $transacoes[] = formatTransaction($r, $tipoLabels);
        }

        response(true, [
            "saldo" => $saldoAtual,
            "saldo_formatado" => 'R$ ' . number_format($saldoAtual, 2, ',', '.'),
            "transacoes" => $transacoes,
            "total_transacoes" => count($transacoes),
        ]);
    }

} catch (Exception $e) {
    error_log("[BoraUm Saldo] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar saldo. Tente novamente.", 500);
}
