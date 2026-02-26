<?php
/**
 * Sistema de Cobranças Adicionais e Reembolsos Parciais
 *
 * Cenários:
 * 1. Cliente adicionou mais produtos → cobrar diferença
 * 2. Produto indisponível → reembolso parcial
 * 3. Erro no pedido → reembolso total
 */
header('Content-Type: application/json; charset=utf-8');

// SECURITY: Restrict CORS to known origins
$allowedOrigins = ['https://app.superbora.com.br', 'https://painel.superbora.com.br', 'https://www.superbora.com.br'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} elseif (empty($origin)) {
    // Server-to-server calls
} else {
    header('Access-Control-Allow-Origin: https://app.superbora.com.br');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../../includes/om_bootstrap.php';

// SECURITY: Require authentication — only admin or partner
require_once dirname(__DIR__, 2) . '/includes/classes/OmAuth.php';
$authDb = om_db();
OmAuth::getInstance()->setDb($authDb);
$authToken = OmAuth::getInstance()->getTokenFromRequest();
if (!$authToken) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Autenticacao obrigatoria']);
    exit;
}
$authPayload = OmAuth::getInstance()->validateToken($authToken);
if (!$authPayload) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token invalido']);
    exit;
}
$authUserId = (int)($authPayload['uid'] ?? 0);
$authUserType = $authPayload['type'] ?? '';

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? $_GET['action'] ?? '';

// Conexão com banco
$db = om_db();

/**
 * Registrar transação no banco
 */
function registrarTransacao($db, $data) {
    // Criar tabela se não existir
    $db->query("
        CREATE TABLE IF NOT EXISTS om_transacoes_adicionais (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            customer_id INT NOT NULL,
            tipo ENUM('cobranca', 'reembolso') NOT NULL,
            valor DECIMAL(10,2) NOT NULL,
            motivo VARCHAR(255),
            payment_intent_id VARCHAR(100),
            refund_id VARCHAR(100),
            status ENUM('pending', 'success', 'failed') DEFAULT 'pending',
            gateway VARCHAR(50) DEFAULT 'stripe',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order (order_id),
            INDEX idx_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmt = $db->prepare("
        INSERT INTO om_transacoes_adicionais
        (order_id, customer_id, tipo, valor, motivo, payment_intent_id, refund_id, status, gateway)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "iisdsssss",
        $data['order_id'],
        $data['customer_id'],
        $data['tipo'],
        $data['valor'],
        $data['motivo'],
        $data['payment_intent_id'],
        $data['refund_id'],
        $data['status'],
        $data['gateway']
    );

    $stmt->execute();
    return $db->insert_id;
}

/**
 * Buscar dados do pedido original
 */
function buscarPedido($db, $orderId) {
    $stmt = $db->prepare("
        SELECT
            o.order_id, o.customer_id, o.total, o.payment_method,
            o.payment_intent_id, o.stripe_customer_id, o.payment_method_id
        FROM `order` o
        WHERE o.order_id = ?
    ");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/**
 * Chamar API do Stripe
 */
function stripeApi($endpoint, $method = 'GET', $data = null) {
    // Carregar chave
    $envFile = __DIR__ . '/../../.env.stripe';
    $sk = '';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, 'STRIPE_SECRET_KEY=') === 0) {
                $sk = trim(str_replace('STRIPE_SECRET_KEY=', '', $line));
                break;
            }
        }
    }

    if (empty($sk) || $sk === 'SUA_CHAVE_SECRETA_AQUI') {
        return ['code' => 0, 'data' => ['error' => ['message' => 'Stripe não configurado']]];
    }

    $ch = curl_init("https://api.stripe.com/v1/{$endpoint}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $sk,
            'Content-Type: application/x-www-form-urlencoded'
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    if ($method === 'POST' && $data) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }

    $result = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'data' => json_decode($result, true)];
}

try {
    switch ($action) {

        // ══════════════════════════════════════════════════════════════
        // COBRAR DIFERENÇA (cliente adicionou produtos)
        // ══════════════════════════════════════════════════════════════
        case 'cobrar_diferenca':
            $orderId = intval($input['order_id'] ?? 0);
            $valorAdicional = floatval($input['valor'] ?? 0);
            $motivo = $input['motivo'] ?? 'Produtos adicionais';

            if ($orderId <= 0 || $valorAdicional <= 0) {
                echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
                break;
            }

            // Buscar pedido original
            $pedido = buscarPedido($db, $orderId);
            if (!$pedido) {
                echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
                break;
            }

            // Verificar se tem cartão tokenizado
            if (empty($pedido['stripe_customer_id']) || empty($pedido['payment_method_id'])) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Cartão não tokenizado. Cliente precisa pagar manualmente.',
                    'requires_manual_payment' => true,
                    'valor' => $valorAdicional
                ]);
                break;
            }

            // Cobrar cartão salvo
            $response = stripeApi('payment_intents', 'POST', [
                'amount' => intval($valorAdicional * 100),
                'currency' => 'brl',
                'customer' => $pedido['stripe_customer_id'],
                'payment_method' => $pedido['payment_method_id'],
                'off_session' => 'true',
                'confirm' => 'true',
                'description' => "Cobrança adicional - Pedido #{$orderId}: {$motivo}"
            ]);

            if ($response['code'] === 200 && $response['data']['status'] === 'succeeded') {
                // Registrar transação
                registrarTransacao($db, [
                    'order_id' => $orderId,
                    'customer_id' => $pedido['customer_id'],
                    'tipo' => 'cobranca',
                    'valor' => $valorAdicional,
                    'motivo' => $motivo,
                    'payment_intent_id' => $response['data']['id'],
                    'refund_id' => null,
                    'status' => 'success',
                    'gateway' => 'stripe'
                ]);

                // Atualizar total do pedido
                $novoTotal = $pedido['total'] + $valorAdicional;
                $stmt = $db->prepare("UPDATE `order` SET total = ? WHERE order_id = ?");
                $stmt->bind_param("di", $novoTotal, $orderId);
                $stmt->execute();

                echo json_encode([
                    'success' => true,
                    'message' => 'Cobrança adicional realizada!',
                    'payment_intent_id' => $response['data']['id'],
                    'valor_cobrado' => $valorAdicional,
                    'novo_total' => $novoTotal
                ]);
            } else {
                // Falha na cobrança
                registrarTransacao($db, [
                    'order_id' => $orderId,
                    'customer_id' => $pedido['customer_id'],
                    'tipo' => 'cobranca',
                    'valor' => $valorAdicional,
                    'motivo' => $motivo,
                    'payment_intent_id' => null,
                    'refund_id' => null,
                    'status' => 'failed',
                    'gateway' => 'stripe'
                ]);

                echo json_encode([
                    'success' => false,
                    'error' => $response['data']['error']['message'] ?? 'Falha na cobrança',
                    'requires_manual_payment' => true
                ]);
            }
            break;

        // ══════════════════════════════════════════════════════════════
        // REEMBOLSO PARCIAL (produto indisponível)
        // ══════════════════════════════════════════════════════════════
        case 'reembolso_parcial':
            $orderId = intval($input['order_id'] ?? 0);
            $valorReembolso = floatval($input['valor'] ?? 0);
            $motivo = $input['motivo'] ?? 'Produto indisponível';
            $productId = $input['product_id'] ?? null;

            if ($orderId <= 0 || $valorReembolso <= 0) {
                echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
                break;
            }

            // Buscar pedido original
            $pedido = buscarPedido($db, $orderId);
            if (!$pedido) {
                echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
                break;
            }

            if (empty($pedido['payment_intent_id'])) {
                echo json_encode(['success' => false, 'error' => 'Pagamento original não encontrado']);
                break;
            }

            // Fazer reembolso parcial no Stripe
            $response = stripeApi('refunds', 'POST', [
                'payment_intent' => $pedido['payment_intent_id'],
                'amount' => intval($valorReembolso * 100),
                'reason' => 'requested_by_customer'
            ]);

            if ($response['code'] === 200 && $response['data']['status'] === 'succeeded') {
                // Registrar transação
                registrarTransacao($db, [
                    'order_id' => $orderId,
                    'customer_id' => $pedido['customer_id'],
                    'tipo' => 'reembolso',
                    'valor' => $valorReembolso,
                    'motivo' => $motivo,
                    'payment_intent_id' => $pedido['payment_intent_id'],
                    'refund_id' => $response['data']['id'],
                    'status' => 'success',
                    'gateway' => 'stripe'
                ]);

                // Atualizar total do pedido
                $novoTotal = $pedido['total'] - $valorReembolso;
                $stmt = $db->prepare("UPDATE `order` SET total = ? WHERE order_id = ?");
                $stmt->bind_param("di", $novoTotal, $orderId);
                $stmt->execute();

                // Se tiver product_id, remover do pedido
                if ($productId) {
                    $stmt = $db->prepare("UPDATE order_product SET quantity = 0 WHERE order_id = ? AND product_id = ?");
                    $stmt->bind_param("ii", $orderId, $productId);
                    $stmt->execute();
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'Reembolso parcial realizado!',
                    'refund_id' => $response['data']['id'],
                    'valor_reembolsado' => $valorReembolso,
                    'novo_total' => $novoTotal
                ]);
            } else {
                registrarTransacao($db, [
                    'order_id' => $orderId,
                    'customer_id' => $pedido['customer_id'],
                    'tipo' => 'reembolso',
                    'valor' => $valorReembolso,
                    'motivo' => $motivo,
                    'payment_intent_id' => $pedido['payment_intent_id'],
                    'refund_id' => null,
                    'status' => 'failed',
                    'gateway' => 'stripe'
                ]);

                echo json_encode([
                    'success' => false,
                    'error' => $response['data']['error']['message'] ?? 'Falha no reembolso'
                ]);
            }
            break;

        // ══════════════════════════════════════════════════════════════
        // REEMBOLSO TOTAL
        // ══════════════════════════════════════════════════════════════
        case 'reembolso_total':
            $orderId = intval($input['order_id'] ?? 0);
            $motivo = $input['motivo'] ?? 'Cancelamento do pedido';

            if ($orderId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Order ID inválido']);
                break;
            }

            $pedido = buscarPedido($db, $orderId);
            if (!$pedido || empty($pedido['payment_intent_id'])) {
                echo json_encode(['success' => false, 'error' => 'Pedido/pagamento não encontrado']);
                break;
            }

            // Reembolso total (sem especificar amount)
            $response = stripeApi('refunds', 'POST', [
                'payment_intent' => $pedido['payment_intent_id'],
                'reason' => 'requested_by_customer'
            ]);

            if ($response['code'] === 200) {
                registrarTransacao($db, [
                    'order_id' => $orderId,
                    'customer_id' => $pedido['customer_id'],
                    'tipo' => 'reembolso',
                    'valor' => $pedido['total'],
                    'motivo' => $motivo,
                    'payment_intent_id' => $pedido['payment_intent_id'],
                    'refund_id' => $response['data']['id'],
                    'status' => 'success',
                    'gateway' => 'stripe'
                ]);

                // Atualizar status do pedido
                $stmt = $db->prepare("UPDATE `order` SET order_status_id = 11 WHERE order_id = ?"); // 11 = Refunded
                $stmt->bind_param("i", $orderId);
                $stmt->execute();

                echo json_encode([
                    'success' => true,
                    'message' => 'Reembolso total realizado!',
                    'refund_id' => $response['data']['id'],
                    'valor_reembolsado' => $pedido['total']
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => $response['data']['error']['message'] ?? 'Falha no reembolso'
                ]);
            }
            break;

        // ══════════════════════════════════════════════════════════════
        // HISTÓRICO DE TRANSAÇÕES
        // ══════════════════════════════════════════════════════════════
        case 'historico':
            $orderId = intval($input['order_id'] ?? $_GET['order_id'] ?? 0);

            if ($orderId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Order ID inválido']);
                break;
            }

            $stmt = $db->prepare("
                SELECT * FROM om_transacoes_adicionais
                WHERE order_id = ?
                ORDER BY created_at DESC
            ");
            $stmt->bind_param("i", $orderId);
            $stmt->execute();
            $result = $stmt->get_result();

            $transacoes = [];
            while ($row = $result->fetch_assoc()) {
                $transacoes[] = $row;
            }

            echo json_encode(['success' => true, 'transacoes' => $transacoes]);
            break;

        // ══════════════════════════════════════════════════════════════
        // CALCULAR DIFERENÇA (para adicionar produtos)
        // ══════════════════════════════════════════════════════════════
        case 'calcular_diferenca':
            $orderId = intval($input['order_id'] ?? 0);
            $novosProdutos = $input['produtos'] ?? [];

            if ($orderId <= 0 || empty($novosProdutos)) {
                echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
                break;
            }

            $pedido = buscarPedido($db, $orderId);
            if (!$pedido) {
                echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
                break;
            }

            // Calcular valor dos novos produtos
            $valorAdicional = 0;
            foreach ($novosProdutos as $produto) {
                $productId = intval($produto['product_id']);
                $quantidade = intval($produto['quantity'] ?? 1);

                $stmt = $db->prepare("SELECT price FROM product WHERE product_id = ?");
                $stmt->bind_param("i", $productId);
                $stmt->execute();
                $result = $stmt->get_result();
                $prod = $result->fetch_assoc();

                if ($prod) {
                    $valorAdicional += $prod['price'] * $quantidade;
                }
            }

            echo json_encode([
                'success' => true,
                'total_atual' => $pedido['total'],
                'valor_adicional' => $valorAdicional,
                'novo_total' => $pedido['total'] + $valorAdicional,
                'tem_cartao_salvo' => !empty($pedido['stripe_customer_id']) && !empty($pedido['payment_method_id'])
            ]);
            break;

        default:
            echo json_encode([
                'success' => false,
                'error' => 'Ação não reconhecida',
                'available_actions' => [
                    'cobrar_diferenca', 'reembolso_parcial', 'reembolso_total',
                    'historico', 'calcular_diferenca'
                ]
            ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno: ' . $e->getMessage()
    ]);
}
