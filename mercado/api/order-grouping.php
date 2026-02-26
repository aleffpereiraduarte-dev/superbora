<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * API DE AGRUPAMENTO DE PEDIDOS - OneMundo
 * Permite agrupar multiplos pedidos do mesmo vendedor em uma unica entrega
 * ══════════════════════════════════════════════════════════════════════════════
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Configuracoes
define('JANELA_AGRUPAMENTO_MINUTOS', 15); // Tempo para adicionar mais itens

session_name('OCSESSID');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getPDO();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexao']);
    exit;
}

$customer_id = $_SESSION['customer_id'] ?? 0;

if (!$customer_id) {
    echo json_encode(['success' => false, 'error' => 'Nao autenticado']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? $_GET['action'] ?? $_POST['action'] ?? 'status';

switch ($action) {

    // ════════════════════════════════════════════════════════════════════════════
    // STATUS - Verificar se existe janela de agrupamento ativa
    // ════════════════════════════════════════════════════════════════════════════
    case 'status':
        // Buscar janelas de agrupamento ativas
        $stmt = $pdo->prepare("
            SELECT ogw.*, v.store_name as vendedor_nome
            FROM om_order_grouping_window ogw
            LEFT JOIN oc_purpletree_vendor_stores v ON ogw.seller_id = v.seller_id
            WHERE ogw.customer_id = ?
              AND ogw.status = 'ativa'
              AND ogw.expires_at > NOW()
            ORDER BY ogw.expires_at ASC
        ");
        $stmt->execute([$customer_id]);
        $janelas = $stmt->fetchAll();

        $janelas_formatadas = [];
        foreach ($janelas as $j) {
            $expires = new DateTime($j['expires_at']);
            $now = new DateTime();
            $diff = $expires->getTimestamp() - $now->getTimestamp();

            if ($diff > 0) {
                $janelas_formatadas[] = [
                    'id' => $j['id'],
                    'seller_id' => $j['seller_id'],
                    'vendedor_nome' => $j['vendedor_nome'],
                    'expires_at' => $j['expires_at'],
                    'segundos_restantes' => $diff,
                    'minutos_restantes' => ceil($diff / 60),
                    'total_pedidos' => $j['total_orders'],
                    'total_itens' => $j['total_items'],
                    'valor_total' => floatval($j['total_value'])
                ];
            }
        }

        echo json_encode([
            'success' => true,
            'tem_janela_ativa' => !empty($janelas_formatadas),
            'janelas' => $janelas_formatadas,
            'total_janelas' => count($janelas_formatadas)
        ]);
        break;

    // ════════════════════════════════════════════════════════════════════════════
    // CRIAR - Criar nova janela de agrupamento apos um pedido
    // ════════════════════════════════════════════════════════════════════════════
    case 'criar':
        $order_id = intval($input['order_id'] ?? $_POST['order_id'] ?? 0);
        $seller_id = intval($input['seller_id'] ?? $_POST['seller_id'] ?? 0);

        if (!$order_id || !$seller_id) {
            echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
            exit;
        }

        // Verificar se pedido pertence ao cliente
        $stmt = $pdo->prepare("SELECT * FROM oc_order WHERE order_id = ? AND customer_id = ?");
        $stmt->execute([$order_id, $customer_id]);
        $order = $stmt->fetch();

        if (!$order) {
            echo json_encode(['success' => false, 'error' => 'Pedido nao encontrado']);
            exit;
        }

        // Verificar se ja existe janela ativa para este vendedor
        $stmt = $pdo->prepare("
            SELECT * FROM om_order_grouping_window
            WHERE customer_id = ? AND seller_id = ? AND status = 'ativa' AND expires_at > NOW()
        ");
        $stmt->execute([$customer_id, $seller_id]);
        $janela_existente = $stmt->fetch();

        if ($janela_existente) {
            // Adicionar pedido a janela existente
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO om_order_grouping_items (window_id, order_id, added_at)
                VALUES (?, ?, NOW())
            ");
            $stmt->execute([$janela_existente['id'], $order_id]);

            // Atualizar totais
            atualizarTotaisJanela($pdo, $janela_existente['id']);

            echo json_encode([
                'success' => true,
                'message' => 'Pedido adicionado a janela existente',
                'window_id' => $janela_existente['id'],
                'expires_at' => $janela_existente['expires_at']
            ]);
        } else {
            // Criar nova janela
            $expires_at = date('Y-m-d H:i:s', strtotime('+' . JANELA_AGRUPAMENTO_MINUTOS . ' minutes'));

            $stmt = $pdo->prepare("
                INSERT INTO om_order_grouping_window
                (customer_id, seller_id, status, expires_at, total_orders, total_items, total_value, created_at)
                VALUES (?, ?, 'ativa', ?, 1, 0, 0, NOW())
            ");
            $stmt->execute([$customer_id, $seller_id, $expires_at]);
            $window_id = $pdo->lastInsertId();

            // Adicionar pedido
            $stmt = $pdo->prepare("
                INSERT INTO om_order_grouping_items (window_id, order_id, added_at)
                VALUES (?, ?, NOW())
            ");
            $stmt->execute([$window_id, $order_id]);

            // Atualizar totais
            atualizarTotaisJanela($pdo, $window_id);

            echo json_encode([
                'success' => true,
                'message' => 'Janela de agrupamento criada',
                'window_id' => $window_id,
                'expires_at' => $expires_at,
                'minutos' => JANELA_AGRUPAMENTO_MINUTOS
            ]);
        }
        break;

    // ════════════════════════════════════════════════════════════════════════════
    // FINALIZAR - Finalizar janela e gerar batch de entrega
    // ════════════════════════════════════════════════════════════════════════════
    case 'finalizar':
        $window_id = intval($input['window_id'] ?? $_POST['window_id'] ?? 0);

        if (!$window_id) {
            echo json_encode(['success' => false, 'error' => 'Janela nao informada']);
            exit;
        }

        // Verificar janela
        $stmt = $pdo->prepare("
            SELECT * FROM om_order_grouping_window
            WHERE id = ? AND customer_id = ? AND status = 'ativa'
        ");
        $stmt->execute([$window_id, $customer_id]);
        $janela = $stmt->fetch();

        if (!$janela) {
            echo json_encode(['success' => false, 'error' => 'Janela nao encontrada']);
            exit;
        }

        // Buscar pedidos da janela
        $stmt = $pdo->prepare("
            SELECT ogi.order_id FROM om_order_grouping_items ogi
            WHERE ogi.window_id = ?
        ");
        $stmt->execute([$window_id]);
        $order_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Criar batch se mais de um pedido
        if (count($order_ids) > 1) {
            $batch_code = 'BATCH-' . strtoupper(substr(md5(time() . $customer_id), 0, 8));

            $stmt = $pdo->prepare("
                INSERT INTO om_order_grouping_batches
                (batch_code, seller_id, customer_id, status, total_orders, created_at)
                VALUES (?, ?, ?, 'aguardando', ?, NOW())
            ");
            $stmt->execute([$batch_code, $janela['seller_id'], $customer_id, count($order_ids)]);
            $batch_id = $pdo->lastInsertId();

            // Atualizar janela
            $stmt = $pdo->prepare("
                UPDATE om_order_grouping_window
                SET status = 'finalizada', batch_id = ?, finalized_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$batch_id, $window_id]);

            echo json_encode([
                'success' => true,
                'message' => 'Pedidos agrupados com sucesso!',
                'batch_id' => $batch_id,
                'batch_code' => $batch_code,
                'total_pedidos' => count($order_ids),
                'order_ids' => $order_ids
            ]);
        } else {
            // Apenas finalizar (sem batch)
            $stmt = $pdo->prepare("
                UPDATE om_order_grouping_window
                SET status = 'finalizada', finalized_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$window_id]);

            echo json_encode([
                'success' => true,
                'message' => 'Janela finalizada',
                'total_pedidos' => 1
            ]);
        }
        break;

    // ════════════════════════════════════════════════════════════════════════════
    // CANCELAR - Cancelar janela de agrupamento
    // ════════════════════════════════════════════════════════════════════════════
    case 'cancelar':
        $window_id = intval($input['window_id'] ?? $_POST['window_id'] ?? 0);

        $stmt = $pdo->prepare("
            UPDATE om_order_grouping_window
            SET status = 'cancelada'
            WHERE id = ? AND customer_id = ? AND status = 'ativa'
        ");
        $stmt->execute([$window_id, $customer_id]);

        echo json_encode([
            'success' => true,
            'message' => 'Janela cancelada'
        ]);
        break;

    // ════════════════════════════════════════════════════════════════════════════
    // PRODUTOS_VENDEDOR - Listar produtos do vendedor para adicionar
    // ════════════════════════════════════════════════════════════════════════════
    case 'produtos_vendedor':
        $seller_id = intval($input['seller_id'] ?? $_GET['seller_id'] ?? 0);
        $limit = intval($input['limit'] ?? $_GET['limit'] ?? 12);

        if (!$seller_id) {
            echo json_encode(['success' => false, 'error' => 'Vendedor nao informado']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT p.product_id, pd.name, p.price, p.image, p.quantity as estoque
            FROM oc_product p
            JOIN oc_purpletree_vendor_products vp ON p.product_id = vp.product_id
            JOIN oc_product_description pd ON p.product_id = pd.product_id AND pd.language_id = 2
            WHERE vp.seller_id = ? AND p.status = '1' AND p.quantity > 0
            ORDER BY p.viewed DESC, p.date_added DESC
            LIMIT ?
        ");
        $stmt->execute([$seller_id, $limit]);
        $produtos = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'produtos' => array_map(function($p) {
                return [
                    'id' => $p['product_id'],
                    'nome' => $p['name'],
                    'preco' => floatval($p['price']),
                    'preco_formatado' => 'R$ ' . number_format($p['price'], 2, ',', '.'),
                    'imagem' => '/image/' . ($p['image'] ?: 'placeholder.png'),
                    'estoque' => $p['estoque']
                ];
            }, $produtos),
            'total' => count($produtos)
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Acao invalida']);
}

/**
 * Atualiza totais de uma janela de agrupamento
 */
function atualizarTotaisJanela($pdo, $window_id) {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT ogi.order_id) as total_orders,
               COALESCE(SUM(op.quantity), 0) as total_items,
               COALESCE(SUM(o.total), 0) as total_value
        FROM om_order_grouping_items ogi
        JOIN oc_order o ON ogi.order_id = o.order_id
        LEFT JOIN oc_order_product op ON o.order_id = op.order_id
        WHERE ogi.window_id = ?
    ");
    $stmt->execute([$window_id]);
    $totais = $stmt->fetch();

    $stmt = $pdo->prepare("
        UPDATE om_order_grouping_window
        SET total_orders = ?, total_items = ?, total_value = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $totais['total_orders'],
        $totais['total_items'],
        $totais['total_value'],
        $window_id
    ]);
}
