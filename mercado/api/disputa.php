<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ONEMUNDO - API DE DISPUTAS
 * Endpoints para criar e gerenciar disputas
 * ══════════════════════════════════════════════════════════════════════════════
 */

header('Content-Type: application/json; charset=utf-8');

session_name('OCSESSID');
session_start();

require_once __DIR__ . '/../config/database.php';

$pdo = getPDO();

$customer_id = $_SESSION['customer_id'] ?? 0;

if (!$customer_id) {
    echo json_encode(['success' => false, 'error' => 'Nao autenticado']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    case 'criar':
        $order_id = intval($_POST['order_id'] ?? 0);
        $tipo = $_POST['tipo'] ?? '';
        $motivo = trim($_POST['motivo'] ?? '');

        if (!$order_id) {
            echo json_encode(['success' => false, 'error' => 'Pedido nao informado']);
            exit;
        }

        if (empty($tipo) || empty($motivo)) {
            echo json_encode(['success' => false, 'error' => 'Tipo e motivo sao obrigatorios']);
            exit;
        }

        // Verificar se o pedido pertence ao cliente
        $stmt = $pdo->prepare("SELECT * FROM oc_order WHERE order_id = ? AND customer_id = ?");
        $stmt->execute([$order_id, $customer_id]);
        $order = $stmt->fetch();

        if (!$order) {
            echo json_encode(['success' => false, 'error' => 'Pedido nao encontrado']);
            exit;
        }

        // Verificar se ja existe disputa aberta para este pedido
        $stmt = $pdo->prepare("
            SELECT id FROM om_disputes
            WHERE order_id = ? AND customer_id = ?
            AND status NOT IN ('encerrada', 'resolvida_cliente', 'resolvida_vendedor')
        ");
        $stmt->execute([$order_id, $customer_id]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Ja existe uma disputa aberta para este pedido']);
            exit;
        }

        // Buscar vendedor do pedido
        $seller_id = 0;
        $stmt = $pdo->prepare("
            SELECT DISTINCT vp.seller_id
            FROM oc_order_product op
            JOIN oc_purpletree_vendor_products vp ON op.product_id = vp.product_id
            WHERE op.order_id = ?
            LIMIT 1
        ");
        $stmt->execute([$order_id]);
        $seller = $stmt->fetch();
        if ($seller) {
            $seller_id = $seller['seller_id'];
        }

        // Criar disputa
        try {
            $stmt = $pdo->prepare("
                INSERT INTO om_disputes (order_id, customer_id, seller_id, tipo, motivo, status, data_abertura)
                VALUES (?, ?, ?, ?, ?, 'aberta', NOW())
            ");
            $stmt->execute([$order_id, $customer_id, $seller_id, $tipo, $motivo]);

            $dispute_id = $pdo->lastInsertId();

            echo json_encode([
                'success' => true,
                'message' => 'Disputa criada com sucesso',
                'dispute_id' => $dispute_id
            ]);

            // Redirecionar se for POST de formulario
            if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'text/html') !== false) {
                header('Location: /mercado/minhas-disputas.php?msg=criada');
                exit;
            }

        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Erro ao criar disputa']);
        }
        break;

    case 'mensagem':
        $dispute_id = intval($_POST['dispute_id'] ?? 0);
        $mensagem = trim($_POST['mensagem'] ?? '');

        if (!$dispute_id || empty($mensagem)) {
            echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
            exit;
        }

        // Verificar se a disputa pertence ao cliente
        $stmt = $pdo->prepare("SELECT * FROM om_disputes WHERE id = ? AND customer_id = ?");
        $stmt->execute([$dispute_id, $customer_id]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Disputa nao encontrada']);
            exit;
        }

        // Inserir mensagem
        $stmt = $pdo->prepare("
            INSERT INTO om_dispute_messages (dispute_id, sender_type, sender_id, mensagem)
            VALUES (?, 'customer', ?, ?)
        ");
        $stmt->execute([$dispute_id, $customer_id, $mensagem]);

        // Atualizar status para aguardando vendedor
        $pdo->prepare("UPDATE om_disputes SET status = 'aguardando_vendedor' WHERE id = ?")->execute([$dispute_id]);

        echo json_encode(['success' => true, 'message' => 'Mensagem enviada']);
        break;

    case 'listar':
        $stmt = $pdo->prepare("
            SELECT d.*, v.nome_loja as vendedor_nome
            FROM om_disputes d
            LEFT JOIN om_vendedores v ON d.seller_id = v.vendedor_id
            WHERE d.customer_id = ?
            ORDER BY d.data_abertura DESC
        ");
        $stmt->execute([$customer_id]);
        $disputas = $stmt->fetchAll();

        echo json_encode(['success' => true, 'disputas' => $disputas]);
        break;

    case 'detalhes':
        $dispute_id = intval($_GET['id'] ?? 0);

        $stmt = $pdo->prepare("
            SELECT d.*, v.nome_loja as vendedor_nome
            FROM om_disputes d
            LEFT JOIN om_vendedores v ON d.seller_id = v.vendedor_id
            WHERE d.id = ? AND d.customer_id = ?
        ");
        $stmt->execute([$dispute_id, $customer_id]);
        $disputa = $stmt->fetch();

        if (!$disputa) {
            echo json_encode(['success' => false, 'error' => 'Disputa nao encontrada']);
            exit;
        }

        // Buscar mensagens
        $stmt = $pdo->prepare("SELECT * FROM om_dispute_messages WHERE dispute_id = ? ORDER BY created_at");
        $stmt->execute([$dispute_id]);
        $mensagens = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'disputa' => $disputa,
            'mensagens' => $mensagens
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Acao invalida']);
}
