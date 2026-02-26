<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
/**
 * API - Pagamento aos Mercados
 * /mercado/financeiro/api/market_payment.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $pdo = getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    require_once __DIR__ . '/../includes/MarketPayment.php';
    
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    $payment = new MarketPayment($pdo);
    
    switch ($action) {
        // Listar pagamentos
        case 'list':
            $partnerId = $_GET['partner_id'] ?? null;
            $status = $_GET['status'] ?? null;
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-d');
            
            $payments = $payment->getPayments($partnerId, $status, $startDate, $endDate);
            echo json_encode(['success' => true, 'payments' => $payments]);
            break;
            
        // Detalhes de um pagamento
        case 'get':
            $paymentId = $_GET['id'] ?? 0;
            $data = $payment->getPayment($paymentId);
            if ($data) {
                echo json_encode(['success' => true, 'payment' => $data]);
            } else {
                throw new Exception('Pagamento não encontrado');
            }
            break;
            
        // Criar pagamento manual
        case 'create':
            $input = json_decode(file_get_contents('php://input'), true);
            $id = $payment->createPayment(
                $input['partner_id'],
                $input['amount'],
                $input['reference_date'] ?? date('Y-m-d'),
                $input['notes'] ?? null
            );
            echo json_encode(['success' => true, 'payment_id' => $id]);
            break;
            
        // Aprovar pagamento
        case 'approve':
            $input = json_decode(file_get_contents('php://input'), true);
            $payment->approvePayment($input['payment_id'], $input['admin_id'] ?? 1);
            echo json_encode(['success' => true, 'message' => 'Pagamento aprovado']);
            break;
            
        // Processar pagamento (executar PIX)
        case 'process':
            $input = json_decode(file_get_contents('php://input'), true);
            $result = $payment->processPayment($input['payment_id']);
            echo json_encode(['success' => true, 'result' => $result]);
            break;
            
        // Rejeitar pagamento
        case 'reject':
            $input = json_decode(file_get_contents('php://input'), true);
            $payment->rejectPayment($input['payment_id'], $input['reason'], $input['admin_id'] ?? 1);
            echo json_encode(['success' => true, 'message' => 'Pagamento rejeitado']);
            break;
            
        // Resumo financeiro do parceiro
        case 'partner_summary':
            $partnerId = $_GET['partner_id'] ?? 0;
            $month = $_GET['month'] ?? date('Y-m');
            
            $summary = $payment->getPartnerSummary($partnerId, $month);
            echo json_encode(['success' => true, 'summary' => $summary]);
            break;
            
        // Pendentes de pagamento
        case 'pending':
            $pending = $payment->getPendingPayments();
            echo json_encode(['success' => true, 'payments' => $pending]);
            break;
            
        // Totais do dia/mês
        case 'totals':
            $period = $_GET['period'] ?? 'day'; // day, week, month
            $totals = $payment->getTotals($period);
            echo json_encode(['success' => true, 'totals' => $totals]);
            break;
            
        default:
            throw new Exception('Ação não especificada ou inválida');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
