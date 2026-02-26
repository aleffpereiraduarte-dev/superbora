<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * GET /api/mercado/shopper/saldo.php
 * Retorna saldo e histórico financeiro do shopper
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REQUER: Autenticação de Shopper
 * Header: Authorization: Bearer <token>
 *
 * SEGURANÇA:
 * - ✅ Autenticação obrigatória
 * - ✅ Prepared statements
 */

require_once __DIR__ . "/../config/auth.php";

try {
    $db = getDB();

    // ═══════════════════════════════════════════════════════════════════
    // AUTENTICAÇÃO
    // ═══════════════════════════════════════════════════════════════════
    $auth = requireShopperAuth();
    $shopper_id = $auth['uid'];

    // Buscar saldo
    $stmt = $db->prepare("
        SELECT saldo_disponivel, saldo_bloqueado, total_ganhos, total_saques
        FROM om_shopper_saldo
        WHERE shopper_id = ?
    ");
    $stmt->execute([$shopper_id]);
    $saldo = $stmt->fetch();

    if (!$saldo) {
        // Criar registro de saldo se não existir
        $stmt = $db->prepare("
            INSERT INTO om_shopper_saldo (shopper_id, saldo_disponivel, saldo_bloqueado, total_ganhos, total_saques)
            VALUES (?, 0, 0, 0, 0)
        ");
        $stmt->execute([$shopper_id]);

        $saldo = [
            'saldo_disponivel' => 0,
            'saldo_bloqueado' => 0,
            'total_ganhos' => 0,
            'total_saques' => 0
        ];
    }

    // Buscar últimas movimentações
    $stmt = $db->prepare("
        SELECT tipo, valor, descricao, status, created_at
        FROM om_shopper_wallet
        WHERE shopper_id = ?
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$shopper_id]);
    $movimentacoes = $stmt->fetchAll();

    // Buscar saques pendentes
    $stmt = $db->prepare("
        SELECT id, valor_solicitado, status, created_at
        FROM om_saques
        WHERE usuario_tipo = 'shopper' AND usuario_id = ?
        AND status IN ('solicitado', 'em_analise', 'aprovado', 'processando')
        ORDER BY created_at DESC
    ");
    $stmt->execute([$shopper_id]);
    $saques_pendentes = $stmt->fetchAll();

    response(true, [
        "saldo" => [
            "disponivel" => floatval($saldo['saldo_disponivel']),
            "disponivel_formatado" => "R$ " . number_format($saldo['saldo_disponivel'], 2, ',', '.'),
            "bloqueado" => floatval($saldo['saldo_bloqueado']),
            "bloqueado_formatado" => "R$ " . number_format($saldo['saldo_bloqueado'], 2, ',', '.'),
            "total_ganhos" => floatval($saldo['total_ganhos']),
            "total_saques" => floatval($saldo['total_saques'])
        ],
        "saques_pendentes" => array_map(function($s) {
            return [
                "id" => $s['id'],
                "valor" => floatval($s['valor_solicitado']),
                "status" => $s['status'],
                "data" => $s['created_at']
            ];
        }, $saques_pendentes),
        "ultimas_movimentacoes" => array_map(function($m) {
            return [
                "tipo" => $m['tipo'],
                "valor" => floatval($m['valor']),
                "valor_formatado" => ($m['valor'] >= 0 ? '+' : '') . "R$ " . number_format($m['valor'], 2, ',', '.'),
                "descricao" => $m['descricao'],
                "status" => $m['status'],
                "data" => $m['created_at']
            ];
        }, $movimentacoes)
    ]);

} catch (Exception $e) {
    error_log("[saldo] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar saldo. Tente novamente.", 500);
}
