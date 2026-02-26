<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * 💳 API DE PAGAMENTO - MERCADO
 * Usa Central Ultra para todos os métodos
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Carregar Central Ultra
require_once $_SERVER['DOCUMENT_ROOT'] . '/system/library/PagarmeCenterUltra.php';
$pagarme = PagarmeCenterUltra::getInstance();

// Receber dados
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        
        // ═══════════════════════════════════════════════════════════════════
        // PIX
        // ═══════════════════════════════════════════════════════════════════
        case 'pix':
            $valor = floatval($input['valor'] ?? 0);
            $cliente = [
                'nome' => $input['nome'] ?? '',
                'email' => $input['email'] ?? '',
                'cpf' => $input['cpf'] ?? '',
                'telefone' => $input['telefone'] ?? ''
            ];
            $items = $input['items'] ?? [['id' => 'MERCADO', 'nome' => 'Compra Mercado', 'preco' => $valor, 'quantidade' => 1]];
            $pedidoId = $input['pedido_id'] ?? 'MKT' . time();
            $endereco = $input['endereco'] ?? null;
            
            $resultado = $pagarme->gerarPixUltra($valor, $cliente, $items, $pedidoId, $endereco);
            
            // Salvar no banco do mercado
            if ($resultado['success']) {
                try {
                    $pdo = getPDO();
                    $stmt = $pdo->prepare("UPDATE om_market_orders SET 
                        pagarme_order_id = ?, 
                        pagarme_charge_id = ?, 
                        payment_status = 'pending',
                        payment_method = 'pix'
                        WHERE id = ? OR order_code = ?");
                    $stmt->execute([
                        $resultado['order_id'],
                        $resultado['charge_id'],
                        $input['order_id'] ?? 0,
                        $pedidoId
                    ]);
                } catch (Exception $e) {}
            }
            
            echo json_encode($resultado);
            break;
            
        // ═══════════════════════════════════════════════════════════════════
        // CARTÃO
        // ═══════════════════════════════════════════════════════════════════
        case 'cartao':
        case 'card':
            $valor = floatval($input['valor'] ?? 0);
            $cardToken = $input['card_token'] ?? '';
            $parcelas = intval($input['parcelas'] ?? 1);
            
            $cliente = [
                'nome' => $input['nome'] ?? '',
                'email' => $input['email'] ?? '',
                'cpf' => $input['cpf'] ?? '',
                'telefone' => $input['telefone'] ?? ''
            ];
            
            $items = $input['items'] ?? [['id' => 'MERCADO', 'nome' => 'Compra Mercado', 'preco' => $valor, 'quantidade' => 1]];
            $pedidoId = $input['pedido_id'] ?? 'MKT' . time();
            
            // Endereço de cobrança (OBRIGATÓRIO para anti-fraude)
            $endereco = [
                'rua' => $input['rua'] ?? $input['street'] ?? '',
                'numero' => $input['numero'] ?? $input['number'] ?? '',
                'complemento' => $input['complemento'] ?? '',
                'bairro' => $input['bairro'] ?? $input['neighborhood'] ?? '',
                'cidade' => $input['cidade'] ?? $input['city'] ?? '',
                'estado' => $input['estado'] ?? $input['state'] ?? '',
                'cep' => $input['cep'] ?? $input['zip_code'] ?? ''
            ];
            
            // Anti-fraude data
            $antifraud = [
                'latitude' => $input['latitude'] ?? null,
                'longitude' => $input['longitude'] ?? null
            ];
            
            $resultado = $pagarme->cobrarCartaoUltra(
                $valor, $cardToken, $cliente, $items, $pedidoId,
                $parcelas, $endereco, $endereco, $antifraud
            );
            
            // Salvar no banco
            if ($resultado['success']) {
                try {
                    $pdo = getPDO();
                    $status = $resultado['status'] === 'paid' ? 'paid' : 'pending';
                    $stmt = $pdo->prepare("UPDATE om_market_orders SET 
                        pagarme_order_id = ?, 
                        pagarme_charge_id = ?, 
                        payment_status = ?,
                        payment_method = 'credit_card'
                        WHERE id = ? OR order_code = ?");
                    $stmt->execute([
                        $resultado['order_id'],
                        $resultado['charge_id'],
                        $status,
                        $input['order_id'] ?? 0,
                        $pedidoId
                    ]);
                } catch (Exception $e) {}
            }
            
            echo json_encode($resultado);
            break;
            
        // ═══════════════════════════════════════════════════════════════════
        // BOLETO
        // ═══════════════════════════════════════════════════════════════════
        case 'boleto':
            $valor = floatval($input['valor'] ?? 0);
            $cliente = [
                'nome' => $input['nome'] ?? '',
                'email' => $input['email'] ?? '',
                'cpf' => $input['cpf'] ?? '',
                'telefone' => $input['telefone'] ?? ''
            ];
            $items = $input['items'] ?? [['id' => 'MERCADO', 'nome' => 'Compra Mercado', 'preco' => $valor, 'quantidade' => 1]];
            $pedidoId = $input['pedido_id'] ?? 'MKT' . time();
            
            $endereco = [
                'rua' => $input['rua'] ?? '',
                'numero' => $input['numero'] ?? '',
                'bairro' => $input['bairro'] ?? '',
                'cidade' => $input['cidade'] ?? '',
                'estado' => $input['estado'] ?? '',
                'cep' => $input['cep'] ?? ''
            ];
            
            $resultado = $pagarme->gerarBoletoUltra($valor, $cliente, $items, $pedidoId, $endereco);
            echo json_encode($resultado);
            break;
            
        // ═══════════════════════════════════════════════════════════════════
        // VERIFICAR STATUS
        // ═══════════════════════════════════════════════════════════════════
        case 'verificar':
        case 'check':
        case 'status':
            $chargeId = $input['charge_id'] ?? $_GET['charge_id'] ?? '';
            $resultado = $pagarme->verificarPagamento($chargeId);
            
            // Atualizar banco se pago
            if ($resultado['success'] && $resultado['status'] === 'paid') {
                try {
                    $pdo = getPDO();
                    $stmt = $pdo->prepare("UPDATE om_market_orders SET payment_status = 'paid', status = 'awaiting_shopper' WHERE pagarme_charge_id = ?");
                    $stmt->execute([$chargeId]);
                } catch (Exception $e) {}
            }
            
            echo json_encode($resultado);
            break;
            
        // ═══════════════════════════════════════════════════════════════════
        // CHAVE PÚBLICA (para tokenização no frontend)
        // ═══════════════════════════════════════════════════════════════════
        case 'public_key':
        case 'config':
            echo json_encode([
                'success' => true,
                'public_key' => $pagarme->getPublicKey(),
                'pix_enabled' => true,
                'card_enabled' => true,
                'boleto_enabled' => true,
                'max_installments' => 12
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Ação não reconhecida: ' . $action]);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}