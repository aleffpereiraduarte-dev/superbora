<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ONE 4.0 - API Mercado
 * Conversação 100% IA - Smooth e Natural
 *
 * Usa SmartConversation para respostas inteligentes
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

// Carrega autoloader
require_once dirname(__DIR__, 2) . '/one/autoload.php';

use One\Services\SmartConversation;

// Input
$input = json_decode(file_get_contents('php://input'), true);
$message = trim($input['message'] ?? '');
$customerId = $_SESSION['customer_id'] ?? ($input['customer_id'] ?? null);
$partnerId = $_SESSION['market_partner_id'] ?? ($input['partner_id'] ?? 100);

// Ação especial: login
if (($input['action'] ?? '') === 'login') {
    // Processa login
    $email = $input['email'] ?? '';
    $password = $input['password'] ?? '';

    if ($email && $password) {
        $result = processLogin($email, $password);
        echo json_encode($result);
        exit;
    }
}

// Ação especial: set_partner
if (($input['action'] ?? '') === 'set_partner') {
    $_SESSION['market_partner_id'] = (int) ($input['partner_id'] ?? 100);
    echo json_encode(['success' => true, 'partner_id' => $_SESSION['market_partner_id']]);
    exit;
}

// Ação especial: clear_history
if (($input['action'] ?? '') === 'clear') {
    $_SESSION['one_smart_history'] = [];
    echo json_encode(['success' => true, 'message' => 'Histórico limpo!']);
    exit;
}

// Mensagem vazia
if (empty($message)) {
    echo json_encode([
        'success' => false,
        'error' => 'Mensagem vazia',
        'suggestions' => ['Oi!', 'Ver promoções', 'Buscar produto']
    ]);
    exit;
}

// Processa com SmartConversation
try {
    $smart = new SmartConversation($customerId, $partnerId);
    $result = $smart->process($message);

    // Se precisa de login, adiciona flag
    if ($result['requires_login']) {
        $result['show_login'] = true;
        $result['login_message'] = getLoginMessage($result['login_reason']);
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno',
        'response' => 'Ops, tive um probleminha... pode tentar de novo? 😅',
        'debug' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

// ============================================================
// FUNÇÕES AUXILIARES
// ============================================================

/**
 * Processa login
 */
function processLogin(string $email, string $password): array
{
    try {
        $pdo = getPDO();

        $stmt = $pdo->prepare("SELECT customer_id, name, password_hash FROM om_market_customers WHERE email = ?");
        $stmt->execute([$email]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($customer && password_verify($password, $customer['password_hash'])) {
            $_SESSION['customer_id'] = $customer['customer_id'];
            $_SESSION['customer_name'] = $customer['name'];

            return [
                'success' => true,
                'message' => "Oba, {$customer['name']}! Agora sim podemos continuar 😊",
                'customer' => [
                    'id' => $customer['customer_id'],
                    'name' => $customer['name']
                ]
            ];
        }

        return [
            'success' => false,
            'message' => 'Hmm, email ou senha não bateram... quer tentar de novo?'
        ];

    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Ops, tive um problema no login... tenta de novo?'
        ];
    }
}

/**
 * Mensagem amigável para pedir login
 */
function getLoginMessage(?string $reason): string
{
    $messages = [
        'add_to_cart' => 'Pra eu guardar os produtos no seu carrinho, preciso que você entre na sua conta rapidinho! É bem rápido, prometo 😊',
        'show_cart' => 'Pra ver seu carrinho, precisa entrar na conta primeiro!',
        'request_ride' => 'Pra chamar o Boraum pra você, preciso que faça login primeiro!',
        'default' => 'Pra continuar, preciso que você entre na sua conta. É rapidinho!'
    ];

    return $messages[$reason] ?? $messages['default'];
}
