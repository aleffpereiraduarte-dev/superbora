<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * API: Listar Mensagens de Chat
 * GET /api/chat/mensagens.php?order_id=X&user_id=Y&user_type=motorista
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Retorna todas as mensagens de um pedido para um usuário
 * Marca mensagens como lidas automaticamente
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once dirname(__DIR__, 2) . '/database.php';

try {
    $pdo = getDB();

    $order_id = intval($_GET['order_id'] ?? 0);
    $user_id = intval($_GET['user_id'] ?? 0);
    $user_type = $_GET['user_type'] ?? '';
    $desde = $_GET['desde'] ?? null; // Para polling de novas mensagens

    if (!$order_id || !$user_id || !$user_type) {
        echo json_encode(['success' => false, 'error' => 'order_id, user_id e user_type obrigatórios']);
        exit;
    }

    // Buscar mensagens
    $sql = "
        SELECT
            c.id,
            c.sender_id,
            c.sender_type,
            c.receiver_id,
            c.receiver_type,
            c.message,
            c.message_type,
            c.is_read,
            c.created_at,
            CASE WHEN c.sender_id = ? AND c.sender_type = ? THEN 1 ELSE 0 END as is_mine
        FROM om_chats c
        WHERE c.order_id = ?
          AND (
              (c.sender_id = ? AND c.sender_type = ?)
              OR (c.receiver_id = ? AND c.receiver_type = ?)
          )
    ";
    $params = [$user_id, $user_type, $order_id, $user_id, $user_type, $user_id, $user_type];

    if ($desde) {
        $sql .= " AND c.created_at > ?";
        $params[] = $desde;
    }

    $sql .= " ORDER BY c.created_at ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $mensagens_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatar mensagens e buscar nomes
    $mensagens = [];
    $ids_para_marcar_lido = [];

    foreach ($mensagens_raw as $m) {
        // Se é mensagem recebida e não lida, marcar para atualizar
        if (!$m['is_mine'] && !$m['is_read']) {
            $ids_para_marcar_lido[] = $m['id'];
        }

        $mensagens[] = [
            'id' => $m['id'],
            'de' => [
                'id' => $m['sender_id'],
                'tipo' => $m['sender_type'],
                'nome' => obterNomeUsuario($pdo, $m['sender_id'], $m['sender_type'])
            ],
            'para' => [
                'id' => $m['receiver_id'],
                'tipo' => $m['receiver_type']
            ],
            'mensagem' => $m['message'],
            'tipo' => $m['message_type'],
            'minha' => (bool)$m['is_mine'],
            'lida' => (bool)$m['is_read'],
            'enviada_em' => $m['created_at']
        ];
    }

    // Marcar mensagens como lidas
    if (count($ids_para_marcar_lido) > 0) {
        $placeholders = implode(',', array_fill(0, count($ids_para_marcar_lido), '?'));
        $stmt = $pdo->prepare("
            UPDATE om_chats SET is_read = 1, read_at = NOW()
            WHERE id IN ($placeholders)
        ");
        $stmt->execute($ids_para_marcar_lido);
    }

    // Buscar participantes do pedido
    $participantes = [];
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $pedido = $stmt->fetch();

    if ($pedido) {
        if ($pedido['customer_id']) {
            $participantes[] = [
                'tipo' => 'cliente',
                'id' => $pedido['customer_id'],
                'nome' => $pedido['customer_name']
            ];
        }
        if ($pedido['shopper_id']) {
            $participantes[] = [
                'tipo' => 'shopper',
                'id' => $pedido['shopper_id'],
                'nome' => $pedido['shopper_name']
            ];
        }
        if ($pedido['delivery_driver_id']) {
            $stmt2 = $pdo->prepare("SELECT nome FROM boraum_motoristas WHERE id = ?");
            $stmt2->execute([$pedido['delivery_driver_id']]);
            $motorista = $stmt2->fetch();
            $participantes[] = [
                'tipo' => 'motorista',
                'id' => $pedido['delivery_driver_id'],
                'nome' => $motorista['nome'] ?? 'Motorista'
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'mensagens' => $mensagens,
        'total' => count($mensagens),
        'participantes' => $participantes,
        'ordem' => [
            'numero' => $pedido['order_number'] ?? null,
            'status' => $pedido['status'] ?? null
        ],
        'polling' => [
            'proximo_request' => 'Adicione ?desde=' . date('Y-m-d H:i:s') . ' para buscar apenas novas mensagens',
            'intervalo_sugerido' => '3 segundos'
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("[chat/mensagens] Erro: " . $e->getMessage());
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
