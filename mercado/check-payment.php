<?php
require_once __DIR__ . '/config/database.php';
/**
 * ✅ CHECK PAYMENT - VERIFICAR STATUS DO PAGAMENTO
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Carregar Central Ultra
require_once $_SERVER['DOCUMENT_ROOT'] . '/system/library/PagarmeCenterUltra.php';
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
        // Log error
    }
}

echo json_encode($resultado);