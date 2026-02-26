<?php
/**
 * API - Criar Entrega Hoje
 *
 * Quando cliente compra com "Entrega Hoje" ou "Retirada no Ponto":
 * 1. Cria a entrega no sistema
 * 2. Notifica o vendedor
 * 3. Libera o OMSKU para o ponto de apoio receber
 * 4. Se não tem BoraUm, notifica central de suporte
 *
 * POST /api/entrega/criar-entrega-hoje.php
 */

require_once __DIR__ . '/../../database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$input = json_decode(file_get_contents('php://input'), true);

$orderId = intval($input['order_id'] ?? 0);
$customerId = intval($input['customer_id'] ?? 0);
$metodoEntrega = $input['metodo'] ?? 'entrega_hoje'; // entrega_hoje, retirada_ponto
$pontoApoioId = intval($input['ponto_apoio_id'] ?? 0);
$valorFrete = floatval($input['valor_frete'] ?? 0);

if (!$orderId || !$pontoApoioId) {
    echo json_encode(['success' => false, 'error' => 'order_id e ponto_apoio_id obrigatórios']);
    exit;
}

$pdo = getConnection();

// Buscar dados do pedido
$stmt = $pdo->prepare("
    SELECT o.*, c.firstname, c.lastname, c.email, c.telephone
    FROM oc_order o
    LEFT JOIN oc_customer c ON o.customer_id = c.customer_id
    WHERE o.order_id = ?
");
$stmt->execute([$orderId]);
$pedido = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pedido) {
    echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
    exit;
}

// Buscar vendedor do pedido
$stmt = $pdo->prepare("
    SELECT DISTINCT vp.seller_id, v.store_name, v.store_address, v.store_city,
           v.store_latitude, v.store_longitude
    FROM oc_order_product op
    JOIN oc_purpletree_vendor_products vp ON op.product_id = vp.product_id
    JOIN oc_purpletree_vendor_stores v ON vp.seller_id = v.seller_id
    WHERE op.order_id = ?
    LIMIT 1
");
$stmt->execute([$orderId]);
$vendedor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vendedor) {
    echo json_encode(['success' => false, 'error' => 'Vendedor não encontrado']);
    exit;
}

// Buscar ponto de apoio
$stmt = $pdo->prepare("
    SELECT seller_id, store_name, store_address, store_city, store_latitude, store_longitude
    FROM oc_purpletree_vendor_stores
    WHERE seller_id = ? AND is_ponto_apoio = 1
");
$stmt->execute([$pontoApoioId]);
$pontoApoio = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pontoApoio) {
    echo json_encode(['success' => false, 'error' => 'Ponto de apoio não encontrado']);
    exit;
}

// Verificar se tem BoraUm na cidade
$boraUmDisponivel = verificarBoraUmDisponivel($pontoApoio['store_city']);

$pdo->beginTransaction();

try {
    // Gerar PIN de entrega
    $pinEntrega = strtoupper(substr(md5($orderId . time()), 0, 6));

    // 1. Criar entrega
    $stmt = $pdo->prepare("
        INSERT INTO om_entregas (
            tipo, origem_sistema, referencia_id,
            remetente_tipo, remetente_id, remetente_nome, remetente_telefone,
            coleta_endereco, coleta_lat, coleta_lng,
            destinatario_nome, destinatario_telefone,
            entrega_endereco, entrega_lat, entrega_lng,
            valor_declarado, valor_frete,
            metodo_entrega, ponto_apoio_id, pin_entrega,
            status, created_at
        ) VALUES (
            'express', 'ecommerce', ?,
            'vendedor', ?, ?, ?,
            ?, ?, ?,
            ?, ?,
            ?, NULL, NULL,
            ?, ?,
            ?, ?, ?,
            'pendente', NOW()
        )
    ");

    $nomeCompleto = trim($pedido['firstname'] . ' ' . $pedido['lastname']) ?: $pedido['payment_firstname'] . ' ' . $pedido['payment_lastname'];
    $enderecoEntrega = $metodoEntrega === 'retirada_ponto'
        ? $pontoApoio['store_address'] . ', ' . $pontoApoio['store_city']
        : trim($pedido['shipping_address_1'] . ', ' . $pedido['shipping_city'] . ' - ' . $pedido['shipping_zone']);

    $stmt->execute([
        $orderId,
        $vendedor['seller_id'], $vendedor['store_name'], '',
        $vendedor['store_address'], $vendedor['store_latitude'], $vendedor['store_longitude'],
        $nomeCompleto, $pedido['telephone'],
        $enderecoEntrega,
        $pedido['total'], $valorFrete,
        $metodoEntrega, $pontoApoioId, $pinEntrega
    ]);

    $entregaId = $pdo->lastInsertId();

    // 2. Registrar tracking inicial
    $stmt = $pdo->prepare("
        INSERT INTO om_entrega_tracking (entrega_id, status, mensagem)
        VALUES (?, 'criado', 'Pedido confirmado - Aguardando vendedor preparar')
    ");
    $stmt->execute([$entregaId]);

    // 3. Criar OMSKUs para os produtos (se não existir)
    $stmt = $pdo->prepare("
        SELECT op.order_product_id, op.product_id, op.name
        FROM oc_order_product op
        WHERE op.order_id = ?
    ");
    $stmt->execute([$orderId]);
    $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $omskusCriados = [];
    foreach ($produtos as $prod) {
        // Verificar se já existe OMSKU
        $stmt = $pdo->prepare("SELECT omsku FROM om_produto_unidades WHERE order_product_id = ?");
        $stmt->execute([$prod['order_product_id']]);
        $existente = $stmt->fetchColumn();

        if (!$existente) {
            // Gerar novo OMSKU
            $omsku = 'OM-' . strtoupper(base_convert(time(), 10, 36)) . strtoupper(substr(md5(uniqid()), 0, 4));
            $unitCode = 'UC-' . str_pad($prod['product_id'], 6, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(md5(uniqid()), 0, 6));

            $stmt = $pdo->prepare("
                INSERT INTO om_produto_unidades
                (product_id, seller_id, unit_code, omsku, status, order_id, order_product_id, local_atual, created_at)
                VALUES (?, ?, ?, ?, 'vendido', ?, ?, 'vendedor', NOW())
            ");
            $stmt->execute([$prod['product_id'], $vendedor['seller_id'], $unitCode, $omsku, $orderId, $prod['order_product_id']]);

            $omskusCriados[] = $omsku;
        } else {
            $omskusCriados[] = $existente;
        }
    }

    // 4. LIBERAR OMSKUs para o ponto de apoio receber
    // Criar registro de "espera" - o ponto só pode receber se existir esse registro
    $stmt = $pdo->prepare("
        INSERT INTO om_ponto_apoio_espera
        (ponto_apoio_id, entrega_id, order_id, omskus, status, created_at)
        VALUES (?, ?, ?, ?, 'aguardando', NOW())
    ");
    $stmt->execute([$pontoApoioId, $entregaId, $orderId, json_encode($omskusCriados)]);

    // 5. NOTIFICAR VENDEDOR
    $stmt = $pdo->prepare("
        INSERT INTO om_entrega_notificacoes
        (entrega_id, destinatario_tipo, destinatario_id, titulo, mensagem, tipo, acao_url, enviado)
        VALUES (?, 'vendedor', ?, ?, ?, 'acao_necessaria', ?, 1)
    ");
    $tipoEntrega = $metodoEntrega === 'retirada_ponto' ? 'RETIRADA NA LOJA' : 'ENTREGA HOJE';
    $stmt->execute([
        $entregaId,
        $vendedor['seller_id'],
        "🚀 Novo Pedido - {$tipoEntrega}!",
        "Pedido #{$orderId} para {$nomeCompleto}. Prepare e envie ao ponto de apoio o mais rápido possível!",
        "/vendedor/pedidos.php?id={$orderId}"
    ]);

    // 6. NOTIFICAR PONTO DE APOIO - liberar para receber
    $stmt = $pdo->prepare("
        INSERT INTO om_entrega_notificacoes
        (entrega_id, destinatario_tipo, destinatario_id, titulo, mensagem, tipo, acao_url, enviado)
        VALUES (?, 'ponto_apoio', ?, ?, ?, 'info', ?, 1)
    ");
    $stmt->execute([
        $entregaId,
        $pontoApoioId,
        "📦 Pacote Chegando - Pedido #{$orderId}",
        "Vendedor {$vendedor['store_name']} vai enviar pacote. OMSKUs liberados para recebimento: " . implode(', ', $omskusCriados),
        "/vendedor/ponto-apoio/receber.php"
    ]);

    // 7. NOTIFICAR CLIENTE
    $stmt = $pdo->prepare("
        INSERT INTO om_entrega_notificacoes
        (entrega_id, destinatario_tipo, destinatario_id, titulo, mensagem, tipo, enviado)
        VALUES (?, 'cliente', ?, ?, ?, 'sucesso', 1)
    ");
    $msgCliente = $metodoEntrega === 'retirada_ponto'
        ? "Seu pedido será preparado e enviado para {$pontoApoio['store_name']}. Você será notificado quando estiver disponível para retirada!"
        : "Seu pedido será entregue hoje! Você receberá atualizações em tempo real.";
    $stmt->execute([
        $entregaId,
        $customerId ?: 0,
        "✅ Pedido Confirmado!",
        $msgCliente
    ]);

    // 8. Se NÃO tem BoraUm e é entrega (não retirada), notificar SUPORTE CENTRAL
    if (!$boraUmDisponivel && $metodoEntrega === 'entrega_hoje') {
        $stmt = $pdo->prepare("
            INSERT INTO om_entrega_notificacoes
            (entrega_id, destinatario_tipo, destinatario_id, titulo, mensagem, tipo, acao_url, enviado)
            VALUES (?, 'suporte', 0, ?, ?, 'acao_necessaria', ?, 1)
        ");
        $stmt->execute([
            $entregaId,
            "⚠️ AÇÃO NECESSÁRIA - Entrega sem BoraUm",
            "Pedido #{$orderId} em {$pontoApoio['store_city']} não tem BoraUm disponível. " .
            "Entre em contato com o ponto de apoio {$pontoApoio['store_name']} para arranjar entrega alternativa (Uber, motoboy local, etc).",
            "/admin/suporte/entregas.php?id={$entregaId}"
        ]);

        // Também inserir na fila de suporte
        $stmt = $pdo->prepare("
            INSERT INTO om_suporte_fila
            (tipo, referencia_id, titulo, descricao, prioridade, status, created_at)
            VALUES ('entrega_sem_boraum', ?, ?, ?, 'alta', 'aberto', NOW())
        ");
        $stmt->execute([
            $entregaId,
            "Entrega sem BoraUm - Pedido #{$orderId}",
            "Cidade: {$pontoApoio['store_city']}\nPonto: {$pontoApoio['store_name']}\nCliente: {$nomeCompleto}\nValor: R$ " . number_format($valorFrete, 2, ',', '.')
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'entrega_id' => $entregaId,
        'order_id' => $orderId,
        'metodo' => $metodoEntrega,
        'pin_entrega' => $pinEntrega,
        'ponto_apoio' => [
            'id' => $pontoApoioId,
            'nome' => $pontoApoio['store_name'],
            'endereco' => $pontoApoio['store_address']
        ],
        'omskus' => $omskusCriados,
        'boraum_disponivel' => $boraUmDisponivel,
        'notificacoes_enviadas' => [
            'vendedor' => true,
            'ponto_apoio' => true,
            'cliente' => true,
            'suporte' => !$boraUmDisponivel && $metodoEntrega === 'entrega_hoje'
        ],
        'qr_cliente' => "/api/retirada/cliente-qrcode.php?entrega_id={$entregaId}&format=html"
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Criar Entrega Hoje Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao criar entrega']);
}

function verificarBoraUmDisponivel($cidade) {
    $cidadesBoraUm = [
        'São Paulo', 'Rio de Janeiro', 'Belo Horizonte', 'Brasília',
        'Salvador', 'Fortaleza', 'Curitiba', 'Recife', 'Porto Alegre',
        'Guarulhos', 'Campinas', 'Goiânia', 'Manaus', 'Belém'
    ];

    foreach ($cidadesBoraUm as $c) {
        if (stripos($cidade, $c) !== false) {
            return true;
        }
    }
    return false;
}
