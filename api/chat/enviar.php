<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * API: Enviar Mensagem de Chat
 * POST /api/chat/enviar.php
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Body: {
 *   "order_id": 123,
 *   "order_type": "mercado",
 *   "sender_id": 1,
 *   "sender_type": "motorista",
 *   "receiver_type": "cliente",  // ou "shopper"
 *   "message": "Estou a caminho!"
 * }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once dirname(__DIR__, 2) . '/database.php';

try {
    $pdo = getDB();

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $order_id = intval($input['order_id'] ?? 0);
    $order_type = $input['order_type'] ?? 'mercado';
    $sender_id = intval($input['sender_id'] ?? 0);
    $sender_type = $input['sender_type'] ?? '';
    $receiver_type = $input['receiver_type'] ?? '';
    $message = trim($input['message'] ?? '');
    $message_type = $input['message_type'] ?? 'text';

    if (!$order_id || !$sender_id || !$sender_type || !$receiver_type || !$message) {
        echo json_encode(['success' => false, 'error' => 'Campos obrigatórios: order_id, sender_id, sender_type, receiver_type, message']);
        exit;
    }

    // Buscar pedido para obter receiver_id
    $receiver_id = null;

    if ($order_type === 'mercado') {
        $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $pedido = $stmt->fetch();

        if (!$pedido) {
            echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
            exit;
        }

        // Determinar receiver_id baseado no tipo
        switch ($receiver_type) {
            case 'cliente':
                $receiver_id = $pedido['customer_id'];
                break;
            case 'shopper':
                $receiver_id = $pedido['shopper_id'];
                break;
            case 'motorista':
                $receiver_id = $pedido['delivery_driver_id'];
                break;
        }

        // Verificar se sender está envolvido no pedido
        $autorizado = false;
        if ($sender_type === 'cliente' && $pedido['customer_id'] == $sender_id) $autorizado = true;
        if ($sender_type === 'shopper' && $pedido['shopper_id'] == $sender_id) $autorizado = true;
        if ($sender_type === 'motorista' && $pedido['delivery_driver_id'] == $sender_id) $autorizado = true;
        if ($sender_type === 'admin') $autorizado = true;

        if (!$autorizado) {
            echo json_encode(['success' => false, 'error' => 'Você não tem permissão para enviar mensagens neste pedido']);
            exit;
        }
    }

    if (!$receiver_id) {
        echo json_encode(['success' => false, 'error' => 'Destinatário não encontrado. O ' . $receiver_type . ' ainda não foi atribuído ao pedido.']);
        exit;
    }

    // Inserir mensagem
    $stmt = $pdo->prepare("
        INSERT INTO om_chats
        (order_id, order_type, sender_id, sender_type, receiver_id, receiver_type, message, message_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        RETURNING id
    ");
    $stmt->execute([
        $order_id, $order_type,
        $sender_id, $sender_type,
        $receiver_id, $receiver_type,
        $message, $message_type
    ]);
    $message_id = $stmt->fetchColumn();

    // Criar notificação para o receiver
    $sender_nome = obterNomeUsuario($pdo, $sender_id, $sender_type);
    $stmt = $pdo->prepare("
        INSERT INTO om_notifications (user_id, user_type, title, body, data)
        VALUES (?, ?, ?, ?, ?::jsonb)
    ");
    $stmt->execute([
        $receiver_id, $receiver_type,
        'Nova mensagem de ' . $sender_nome,
        substr($message, 0, 100) . (strlen($message) > 100 ? '...' : ''),
        json_encode(['order_id' => $order_id, 'sender_type' => $sender_type, 'ref_type' => 'chat', 'ref_id' => $message_id])
    ]);

    echo json_encode([
        'success' => true,
        'message_id' => $message_id,
        'enviado_em' => date('Y-m-d H:i:s'),
        'para' => [
            'tipo' => $receiver_type,
            'id' => $receiver_id
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("[chat/enviar] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}

function obterNomeUsuario($pdo, $user_id, $user_type) {
    switch ($user_type) {
        case 'cliente':
            $stmt = $pdo->prepare("SELECT nome FROM om_clientes WHERE id = ?");
            break;
        case 'shopper':
            $stmt = $pdo->prepare("SELECT name AS nome FROM om_market_shoppers WHERE shopper_id = ?");
            break;
        case 'motorista':
            $stmt = $pdo->prepare("SELECT nome FROM boraum_motoristas WHERE id = ?");
            break;
        default:
            return ucfirst($user_type);
    }
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    return $result ? $result['nome'] : ucfirst($user_type);
}
