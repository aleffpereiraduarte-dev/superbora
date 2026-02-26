<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ✅ CHECK PAYMENT - VERIFICA STATUS DO PAGAMENTO
 */

header('Content-Type: application/json');

$centralPath = $_SERVER['DOCUMENT_ROOT'] . '/system/library/PagarmeCenterUltra.php';
if (!file_exists($centralPath)) {
    die(json_encode(['success' => false, 'error' => 'Central não instalada']));
}
require_once $centralPath;

$pagarme = PagarmeCenterUltra::getInstance();

$chargeId = $_POST['charge_id'] ?? $_GET['charge_id'] ?? '';

if (empty($chargeId)) {
    die(json_encode(['success' => false, 'error' => 'charge_id obrigatório']));
}

$resultado = $pagarme->verificarPagamento($chargeId);

// Atualizar no banco se pago
if ($resultado['success'] && $resultado['status'] === 'paid') {
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare("UPDATE om_market_orders SET payment_status = 'paid', status = 'awaiting_shopper' WHERE pagarme_charge_id = ?");
        $stmt->execute([$chargeId]);
    } catch (Exception $e) {
        error_log("[check-payment] Erro ao atualizar pedido: " . $e->getMessage());
    }
}

echo json_encode($resultado);