<?php
/**
 * POST /painel/mercado/ajax/sacar.php
 * Saque via PIX - Endpoint interno do painel (autenticacao por session)
 *
 * Body JSON: {amount}
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo nao permitido']);
    exit;
}

if (!isset($_SESSION['mercado_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nao autenticado']);
    exit;
}

require_once dirname(__DIR__, 2) . '/database.php';
require_once dirname(__DIR__, 2) . '/includes/classes/WooviClient.php';
require_once dirname(__DIR__, 2) . '/includes/classes/PusherService.php';

$partner_id = (int)$_SESSION['mercado_id'];

$input = json_decode(file_get_contents('php://input'), true);
$amount = (float)($input['amount'] ?? 0);

if ($amount < 10) {
    echo json_encode(['success' => false, 'message' => 'Valor minimo: R$ 10,00']);
    exit;
}

try {
    $db = getDB();

    // Buscar config PIX
    $stmtConfig = $db->prepare("SELECT pix_key, pix_key_type, pix_key_validated FROM om_payout_config WHERE partner_id = ?");
    $stmtConfig->execute([$partner_id]);
    $config = $stmtConfig->fetch();

    if (!$config || empty($config['pix_key'])) {
        echo json_encode(['success' => false, 'message' => 'Configure sua chave PIX em Configuracoes antes de sacar']);
        exit;
    }

    if (!$config['pix_key_validated']) {
        echo json_encode(['success' => false, 'message' => 'Sua chave PIX precisa ser validada']);
        exit;
    }

    $pixKey = $config['pix_key'];
    $pixKeyType = $config['pix_key_type'];

    $db->beginTransaction();

    // Lock saldo
    $stmtSaldo = $db->prepare("
        SELECT saldo_disponivel, COALESCE(saldo_devedor, 0) as saldo_devedor
        FROM om_mercado_saldo WHERE partner_id = ? FOR UPDATE
    ");
    $stmtSaldo->execute([$partner_id]);
    $saldo = $stmtSaldo->fetch();

    if (!$saldo) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Saldo nao encontrado']);
        exit;
    }

    $saldoDisp = (float)$saldo['saldo_disponivel'];
    $saldoDev = (float)$saldo['saldo_devedor'];

    if ($saldoDev > 0) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Saque bloqueado: comissao pendente de R$ ' . number_format($saldoDev, 2, ',', '.')]);
        exit;
    }

    if ($amount > $saldoDisp) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Saldo insuficiente. Disponivel: R$ ' . number_format($saldoDisp, 2, ',', '.')]);
        exit;
    }

    // Verificar payout pendente
    $stmtPend = $db->prepare("SELECT COUNT(*) FROM om_woovi_payouts WHERE partner_id = ? AND status IN ('pending','processing')");
    $stmtPend->execute([$partner_id]);
    if ((int)$stmtPend->fetchColumn() > 0) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Voce ja tem um saque em processamento. Aguarde.']);
        exit;
    }

    $correlationId = 'sb_painel_' . $partner_id . '_' . time() . '_' . bin2hex(random_bytes(4));
    $amountCents = (int)round($amount * 100);

    // Debitar
    $db->prepare("UPDATE om_mercado_saldo SET saldo_disponivel = saldo_disponivel - ?, total_sacado = COALESCE(total_sacado, 0) + ?, updated_at = NOW() WHERE partner_id = ?")->execute([$amount, $amount, $partner_id]);

    // Registrar payout
    $stmtIns = $db->prepare("
        INSERT INTO om_woovi_payouts (partner_id, correlation_id, amount_cents, amount, pix_key, pix_key_type, status, type, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', 'manual', NOW())
    ");
    $stmtIns->execute([$partner_id, $correlationId, $amountCents, $amount, $pixKey, $pixKeyType]);
    $payoutId = (int)$db->lastInsertId();

    // Log wallet
    $newBalance = $saldoDisp - $amount;
    $db->prepare("
        INSERT INTO om_mercado_wallet (partner_id, tipo, valor, saldo_anterior, saldo_atual, saldo_posterior, descricao, status, created_at)
        VALUES (?, 'saque', ?, ?, ?, ?, ?, 'processing', NOW())
    ")->execute([$partner_id, -$amount, $saldoDisp, $newBalance, $newBalance, "Saque PIX - " . substr($pixKey, 0, 4) . "****"]);

    $db->commit();

    // Chamar Woovi
    try {
        $woovi = new WooviClient();
        $result = $woovi->createPayout($amountCents, $correlationId, $pixKey, $pixKeyType, "Repasse SuperBora #$partner_id");

        $wooviTxId = $result['data']['transaction']['transactionID'] ?? $result['data']['correlationID'] ?? '';
        $db->prepare("UPDATE om_woovi_payouts SET status='processing', woovi_transaction_id=?, woovi_raw_response=? WHERE id=?")->execute([$wooviTxId, $result['raw'] ?? '', $payoutId]);

        echo json_encode([
            'success' => true,
            'message' => 'PIX enviado! Voce recebera R$ ' . number_format($amount, 2, ',', '.') . ' em instantes.',
            'payout_id' => $payoutId,
            'new_balance' => round($saldoDisp - $amount, 2)
        ]);

    } catch (\Exception $apiErr) {
        // Devolver saldo
        $db->beginTransaction();
        $db->prepare("UPDATE om_mercado_saldo SET saldo_disponivel = saldo_disponivel + ?, total_sacado = GREATEST(0, COALESCE(total_sacado, 0) - ?), updated_at = NOW() WHERE partner_id = ?")->execute([$amount, $amount, $partner_id]);
        $db->prepare("UPDATE om_woovi_payouts SET status='failed', failure_reason=? WHERE id=?")->execute([$apiErr->getMessage(), $payoutId]);
        $db->prepare("INSERT INTO om_mercado_wallet (partner_id, tipo, valor, descricao, status, created_at) VALUES (?, 'saque_estornado', ?, ?, 'refunded', NOW())")->execute([$partner_id, $amount, 'Falhou: ' . $apiErr->getMessage()]);
        $db->commit();

        echo json_encode(['success' => false, 'message' => 'Erro ao enviar PIX: ' . $apiErr->getMessage() . '. Saldo devolvido.']);
    }

    // Pusher (best effort)
    try { PusherService::walletUpdate($partner_id, ['balance' => round($saldoDisp - $amount, 2)]); } catch (\Exception $e) {}

} catch (\Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[painel/sacar] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno. Tente novamente.']);
}
