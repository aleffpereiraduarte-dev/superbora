<?php
/**
 * API de Customer Cards - Mercado Pago
 * Salva cartões no Mercado Pago para pagamentos futuros com apenas CVV
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

try {
    // Carrega config
    $configFile = dirname(__FILE__) . '/config.php';
    if (!file_exists($configFile)) {
        throw new Exception('Config não encontrado');
    }
    
    $configContent = file_get_contents($configFile);
    preg_match("/define\('DB_HOSTNAME',\s*'([^']+)'\)/", $configContent, $m); $dbHost = $m[1] ?? 'localhost';
    preg_match("/define\('DB_USERNAME',\s*'([^']+)'\)/", $configContent, $m); $dbUser = $m[1] ?? '';
    preg_match("/define\('DB_PASSWORD',\s*'([^']+)'\)/", $configContent, $m); $dbPass = $m[1] ?? '';
    preg_match("/define\('DB_DATABASE',\s*'([^']+)'\)/", $configContent, $m); $dbName = $m[1] ?? '';
    preg_match("/define\('DB_PREFIX',\s*'([^']+)'\)/", $configContent, $m); $dbPrefix = $m[1] ?? 'oc_';
    
    $pdo = new PDO("pgsql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Busca Access Token
    $stmt = $pdo->query("SELECT \"value\" FROM {$dbPrefix}setting WHERE \"key\" = 'payment_mercadopago_transparente_access_token' LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $access_token = $row['value'] ?? '';
    
    if (!$access_token) {
        throw new Exception('Access Token não configurado');
    }
    
    // Cria/atualiza tabela de cartões com mp_card_id
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS om_cartoes_salvos (
            id SERIAL PRIMARY KEY,
            customer_id INT NOT NULL,
            mp_customer_id VARCHAR(100) DEFAULT NULL,
            mp_card_id VARCHAR(100) DEFAULT NULL,
            card_token VARCHAR(255) DEFAULT NULL,
            card_brand VARCHAR(50) NOT NULL,
            card_last4 VARCHAR(4) NOT NULL,
            card_holder VARCHAR(255) NOT NULL,
            card_expiry VARCHAR(7) DEFAULT NULL,
            first_six_digits VARCHAR(6) DEFAULT NULL,
            is_default SMALLINT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cartoes_customer ON om_cartoes_salvos(customer_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cartoes_mp_card ON om_cartoes_salvos(mp_card_id)");
    
    // Adiciona colunas se não existirem (PostgreSQL não suporta AFTER)
    try {
        $pdo->exec("ALTER TABLE om_cartoes_salvos ADD COLUMN IF NOT EXISTS mp_customer_id VARCHAR(100) DEFAULT NULL");
    } catch(Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE om_cartoes_salvos ADD COLUMN IF NOT EXISTS mp_card_id VARCHAR(100) DEFAULT NULL");
    } catch(Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE om_cartoes_salvos ADD COLUMN IF NOT EXISTS first_six_digits VARCHAR(6) DEFAULT NULL");
    } catch(Exception $e) {}
    
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    switch ($action) {
        
        case 'get_or_create_customer':
            // Busca ou cria customer no Mercado Pago
            $customer_id = intval($_POST['customer_id'] ?? 0);
            $email = trim($_POST['email'] ?? '');
            
            if (!$email) {
                throw new Exception('Email é obrigatório');
            }
            
            // Verifica se já tem mp_customer_id salvo
            if ($customer_id) {
                $stmt = $pdo->prepare("SELECT mp_customer_id FROM om_cartoes_salvos WHERE customer_id = ? AND mp_customer_id IS NOT NULL LIMIT 1");
                $stmt->execute([$customer_id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && $row['mp_customer_id']) {
                    echo json_encode(['success' => true, 'mp_customer_id' => $row['mp_customer_id']]);
                    exit;
                }
            }
            
            // Busca customer no MP pelo email
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.mercadopago.com/v1/customers/search?email=' . urlencode($email));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $access_token
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            
            $result = json_decode($response, true);
            
            if ($result && isset($result['results']) && count($result['results']) > 0) {
                $mp_customer_id = $result['results'][0]['id'];
                echo json_encode(['success' => true, 'mp_customer_id' => $mp_customer_id, 'exists' => true]);
                exit;
            }
            
            // Cria novo customer no MP
            $customer_data = [
                'email' => $email
            ];
            
            // Busca nome do cliente
            if ($customer_id) {
                $stmt = $pdo->prepare("SELECT firstname, lastname FROM {$dbPrefix}customer WHERE customer_id = ?");
                $stmt->execute([$customer_id]);
                $customer = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($customer) {
                    $customer_data['first_name'] = $customer['firstname'];
                    $customer_data['last_name'] = $customer['lastname'];
                }
            }
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.mercadopago.com/v1/customers');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($customer_data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $access_token
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            
            $result = json_decode($response, true);
            
            if ($result && isset($result['id'])) {
                echo json_encode(['success' => true, 'mp_customer_id' => $result['id'], 'created' => true]);
            } else {
                throw new Exception('Erro ao criar customer: ' . ($result['message'] ?? json_encode($result)));
            }
            break;
            
        case 'save_card':
            // Salva cartão no Mercado Pago e no banco
            $customer_id = intval($_POST['customer_id'] ?? 0);
            $mp_customer_id = $_POST['mp_customer_id'] ?? '';
            $token = $_POST['token'] ?? '';
            $card_brand = $_POST['card_brand'] ?? '';
            $card_last4 = $_POST['card_last4'] ?? '';
            $card_holder = $_POST['card_holder'] ?? '';
            $card_expiry = $_POST['card_expiry'] ?? '';
            $first_six = $_POST['first_six_digits'] ?? '';
            
            if (!$mp_customer_id || !$token) {
                throw new Exception('Dados incompletos');
            }
            
            // Salva cartão no Mercado Pago
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.mercadopago.com/v1/customers/' . $mp_customer_id . '/cards');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['token' => $token]));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $access_token
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            
            $result = json_decode($response, true);
            
            if ($result && isset($result['id'])) {
                $mp_card_id = $result['id'];
                
                // Atualiza dados do cartão com info do MP
                $card_brand = $result['payment_method']['id'] ?? $card_brand;
                $card_last4 = $result['last_four_digits'] ?? $card_last4;
                $first_six = $result['first_six_digits'] ?? $first_six;
                $card_holder = $result['cardholder']['name'] ?? $card_holder;
                
                // Verifica se já existe
                $stmt = $pdo->prepare("SELECT id FROM om_cartoes_salvos WHERE customer_id = ? AND mp_card_id = ?");
                $stmt->execute([$customer_id, $mp_card_id]);
                
                if ($stmt->rowCount() == 0) {
                    // Insere novo
                    $stmt = $pdo->prepare("INSERT INTO om_cartoes_salvos 
                        (customer_id, mp_customer_id, mp_card_id, card_brand, card_last4, card_holder, card_expiry, first_six_digits, is_default) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
                    $stmt->execute([$customer_id, $mp_customer_id, $mp_card_id, $card_brand, $card_last4, $card_holder, $card_expiry, $first_six]);
                    
                    // Remove default dos outros
                    $pdo->prepare("UPDATE om_cartoes_salvos SET is_default = 0 WHERE customer_id = ? AND mp_card_id != ?")->execute([$customer_id, $mp_card_id]);
                }
                
                echo json_encode([
                    'success' => true, 
                    'mp_card_id' => $mp_card_id,
                    'message' => 'Cartão salvo com sucesso'
                ]);
            } else {
                // Se falhou no MP, salva localmente mesmo assim
                $stmt = $pdo->prepare("INSERT INTO om_cartoes_salvos
                    (customer_id, mp_customer_id, card_token, card_brand, card_last4, card_holder, card_expiry, first_six_digits, is_default)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                    ON CONFLICT (customer_id, card_token) DO UPDATE SET card_token = EXCLUDED.card_token");
                $stmt->execute([$customer_id, $mp_customer_id, $token, $card_brand, $card_last4, $card_holder, $card_expiry, $first_six]);
                
                echo json_encode([
                    'success' => true,
                    'local_only' => true,
                    'message' => 'Cartão salvo localmente'
                ]);
            }
            break;
            
        case 'pay_with_saved':
            // Paga com cartão salvo (apenas CVV)
            $card_id = intval($_POST['card_id'] ?? 0);
            $cvv = $_POST['cvv'] ?? '';
            $customer_id = intval($_POST['customer_id'] ?? 0);
            $email = trim($_POST['email'] ?? '');
            $cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
            
            if (!$card_id || !$cvv) {
                throw new Exception('Dados incompletos');
            }
            
            // Busca cartão salvo
            $stmt = $pdo->prepare("SELECT * FROM om_cartoes_salvos WHERE id = ? AND customer_id = ?");
            $stmt->execute([$card_id, $customer_id]);
            $card = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$card) {
                throw new Exception('Cartão não encontrado');
            }
            
            if (!$card['mp_card_id']) {
                throw new Exception('Este cartão precisa ser recadastrado. Clique em "Adicionar Outro Cartão".');
            }
            
            // Busca pedido
            $stmt = $pdo->prepare("SELECT * FROM {$dbPrefix}order WHERE customer_id = ? AND order_status_id = 0 ORDER BY order_id DESC LIMIT 1");
            $stmt->execute([$customer_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) {
                throw new Exception('Pedido não encontrado');
            }
            
            $order_id = $order['order_id'];
            $total = (float)$order['total'];
            
            // Monta pagamento
            $payment_data = [
                'transaction_amount' => $total,
                'token' => $card['mp_card_id'], // Usa o card_id como token
                'description' => 'Pedido #' . $order_id . ' - OneMundo',
                'installments' => 1,
                'payment_method_id' => $card['card_brand'],
                'payer' => [
                    'id' => $card['mp_customer_id'],
                    'email' => $email ?: $order['email'],
                    'identification' => [
                        'type' => 'CPF',
                        'number' => $cpf
                    ]
                ],
                'external_reference' => (string)$order_id
            ];
            
            // Envia pagamento
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.mercadopago.com/v1/payments');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payment_data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $access_token,
                'X-Idempotency-Key: om_saved_' . $order_id . '_' . time()
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            
            $result = json_decode($response, true);
            
            $status = $result['status'] ?? '';
            
            if ($status === 'approved') {
                $orderCode = 'OM' . date('ym') . str_pad($order_id, 6, '0', STR_PAD_LEFT);
                $stmt = $pdo->prepare("UPDATE {$dbPrefix}order SET om_order_code = ?, order_status_id = 2, date_modified = NOW() WHERE order_id = ?");
                $stmt->execute([$orderCode, $order_id]);
                
                echo json_encode([
                    'success' => true,
                    'status' => 'approved',
                    'order_id' => $order_id,
                    'order_code' => $orderCode,
                    'customer_id' => $customer_id
                ]);
            } elseif ($status === 'in_process' || $status === 'pending') {
                echo json_encode([
                    'success' => true,
                    'status' => $status,
                    'order_id' => $order_id
                ]);
            } else {
                throw new Exception($result['message'] ?? 'Pagamento recusado');
            }
            break;
            
        case 'list':
            // Lista cartões do cliente
            $customer_id = intval($_GET['customer_id'] ?? 0);
            
            $stmt = $pdo->prepare("SELECT id, card_brand, card_last4, card_holder, card_expiry, first_six_digits, is_default, mp_card_id FROM om_cartoes_salvos WHERE customer_id = ? ORDER BY is_default DESC, created_at DESC");
            $stmt->execute([$customer_id]);
            $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $formatted = [];
            foreach ($cards as $card) {
                $formatted[] = [
                    'id' => $card['id'],
                    'brand' => $card['card_brand'],
                    'last4' => $card['card_last4'],
                    'holder' => $card['card_holder'],
                    'expiry' => $card['card_expiry'],
                    'first_six' => $card['first_six_digits'],
                    'is_default' => (bool)$card['is_default'],
                    'has_mp_card' => !empty($card['mp_card_id']),
                    'display' => strtoupper($card['card_brand']) . ' •••• ' . $card['card_last4']
                ];
            }
            
            echo json_encode(['success' => true, 'cards' => $formatted, 'count' => count($formatted)]);
            break;
            
        default:
            throw new Exception('Ação não reconhecida');
    }
    
} catch (Exception $e) {
    error_log("[api_mp_cards] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}
