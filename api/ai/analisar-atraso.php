<?php
/**
 * API - Análise Inteligente de Atrasos
 *
 * Quando vendedor atrasa, a AI analisa e recomenda:
 * 1. ABSORVER_PREJUIZO - Produto similar de outro vendedor, vale absorver a diferença
 * 2. OFERECER_ALTERNATIVA - Produto similar (cor, modelo diferente) com desconto
 * 3. CANCELAR_REEMBOLSAR - Não vale a pena, melhor cancelar e dar cupom
 *
 * POST /api/ai/analisar-atraso.php
 * {
 *   "order_id": 123,
 *   "produto_original": {...},
 *   "seller_id_excluir": 456
 * }
 */

require_once __DIR__ . '/../../database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$input = json_decode(file_get_contents('php://input'), true);

$orderId = intval($input['order_id'] ?? 0);
$produtoOriginal = $input['produto_original'] ?? null;
$sellerIdExcluir = intval($input['seller_id_excluir'] ?? 0);

if (!$orderId) {
    echo json_encode(['success' => false, 'error' => 'order_id obrigatório']);
    exit;
}

$pdo = getConnection();

// Buscar dados do pedido se não passou produto
if (!$produtoOriginal) {
    $stmt = $pdo->prepare("
        SELECT op.product_id, op.name, op.price, op.quantity, p.image,
               vp.seller_id
        FROM oc_order_product op
        LEFT JOIN oc_product p ON op.product_id = p.product_id
        LEFT JOIN oc_purpletree_vendor_products vp ON op.product_id = vp.product_id
        WHERE op.order_id = ?
        LIMIT 1
    ");
    $stmt->execute([$orderId]);
    $produtoOriginal = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($produtoOriginal) {
        $sellerIdExcluir = $produtoOriginal['seller_id'];
    }
}

if (!$produtoOriginal) {
    echo json_encode(['success' => false, 'error' => 'Produto não encontrado']);
    exit;
}

$precoOriginal = floatval($produtoOriginal['price']);
$nomeOriginal = $produtoOriginal['name'];

// ANÁLISE 1: Buscar produto IGUAL de outro vendedor
$stmt = $pdo->prepare("
    SELECT p.product_id, pd.name, p.price, p.image, p.quantity,
           vp.seller_id, vs.store_name
    FROM oc_product p
    JOIN oc_product_description pd ON p.product_id = pd.product_id AND pd.language_id = 1
    JOIN oc_purpletree_vendor_products vp ON p.product_id = vp.product_id
    JOIN oc_purpletree_vendor_stores vs ON vp.seller_id = vs.seller_id AND vs.store_status = 1
    WHERE p.status = 1
      AND p.quantity > 0
      AND vp.seller_id != ?
      AND (
          pd.name LIKE ? OR
          p.model = (SELECT model FROM oc_product WHERE product_id = ?)
      )
    ORDER BY ABS(p.price - ?) ASC
    LIMIT 5
");
$nomeBusca = '%' . substr($nomeOriginal, 0, 30) . '%';
$stmt->execute([$sellerIdExcluir, $nomeBusca, $produtoOriginal['product_id'], $precoOriginal]);
$produtosSimilares = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ANÁLISE 2: Buscar produtos da mesma categoria (alternativas)
$stmt = $pdo->prepare("
    SELECT p.product_id, pd.name, p.price, p.image, p.quantity,
           vp.seller_id, vs.store_name
    FROM oc_product p
    JOIN oc_product_description pd ON p.product_id = pd.product_id AND pd.language_id = 1
    JOIN oc_product_to_category ptc ON p.product_id = ptc.product_id
    JOIN oc_purpletree_vendor_products vp ON p.product_id = vp.product_id
    JOIN oc_purpletree_vendor_stores vs ON vp.seller_id = vs.seller_id AND vs.store_status = 1
    WHERE p.status = 1
      AND p.quantity > 0
      AND vp.seller_id != ?
      AND ptc.category_id IN (SELECT category_id FROM oc_product_to_category WHERE product_id = ?)
      AND p.price BETWEEN ? AND ?
    ORDER BY p.price ASC
    LIMIT 10
");
$stmt->execute([$sellerIdExcluir, $produtoOriginal['product_id'], $precoOriginal * 0.5, $precoOriginal * 1.5]);
$alternativas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// LÓGICA DE DECISÃO (simula análise de AI)
$recomendacao = analisarERecomendar($produtoOriginal, $produtosSimilares, $alternativas);

// Registrar análise
$stmt = $pdo->prepare("
    INSERT INTO om_ponto_apoio_logs
    (ponto_id, tipo, mensagem, dados, created_at)
    VALUES (0, 'analise_ai_atraso', ?, ?, NOW())
");
$stmt->execute([
    "Análise de atraso - Pedido #{$orderId}",
    json_encode([
        'order_id' => $orderId,
        'produto_original' => $produtoOriginal,
        'recomendacao' => $recomendacao,
        'produtos_similares' => count($produtosSimilares),
        'alternativas' => count($alternativas)
    ])
]);

echo json_encode([
    'success' => true,
    'order_id' => $orderId,
    'produto_original' => [
        'nome' => $produtoOriginal['name'],
        'preco' => $precoOriginal
    ],
    'analise' => $recomendacao,
    'produtos_similares' => $produtosSimilares,
    'alternativas' => array_slice($alternativas, 0, 5)
]);

/**
 * Análise inteligente para recomendar ação
 */
function analisarERecomendar($original, $similares, $alternativas) {
    $precoOriginal = floatval($original['price']);

    // Caso 1: Encontrou produto IGUAL ou muito similar
    foreach ($similares as $similar) {
        $precoSimilar = floatval($similar['price']);
        $diferenca = $precoSimilar - $precoOriginal;
        $percentualDif = abs($diferenca) / $precoOriginal * 100;

        // Se diferença é menor que 20%, vale absorver
        if ($percentualDif <= 20) {
            return [
                'acao' => 'ABSORVER_PREJUIZO',
                'confianca' => 95,
                'razao' => "Produto idêntico encontrado por R$ " . number_format($precoSimilar, 2, ',', '.') .
                           " (diferença de " . number_format($percentualDif, 1) . "%). " .
                           "Recomendo absorver o prejuízo de R$ " . number_format(abs($diferenca), 2, ',', '.') .
                           " para manter o cliente satisfeito.",
                'produto_sugerido' => $similar,
                'prejuizo_estimado' => max(0, $diferenca),
                'valor_cupom_sugerido' => 50,
                'mensagem_rh' => "🤖 AI RECOMENDA: Enviar produto do vendedor {$similar['store_name']}. " .
                                 "Prejuízo: R$ " . number_format(max(0, $diferenca), 2, ',', '.') . ". VALE A PENA."
            ];
        }

        // Se diferença é entre 20-50%, analisar valor absoluto
        if ($percentualDif <= 50 && $diferenca < 100) {
            return [
                'acao' => 'ABSORVER_PREJUIZO',
                'confianca' => 75,
                'razao' => "Produto similar por R$ " . number_format($precoSimilar, 2, ',', '.') .
                           ". Diferença de R$ " . number_format(abs($diferenca), 2, ',', '.') .
                           " é aceitável para manter reputação.",
                'produto_sugerido' => $similar,
                'prejuizo_estimado' => max(0, $diferenca),
                'valor_cupom_sugerido' => 50,
                'mensagem_rh' => "🤖 AI RECOMENDA: Absorver prejuízo moderado. " .
                                 "Vendedor: {$similar['store_name']}. RECOMENDADO."
            ];
        }
    }

    // Caso 2: Não tem igual, mas tem alternativas
    if (count($alternativas) > 0) {
        $melhorAlternativa = $alternativas[0];
        $precoAlt = floatval($melhorAlternativa['price']);
        $diferenca = abs($precoAlt - $precoOriginal);

        // Se alternativa é mais barata ou similar
        if ($precoAlt <= $precoOriginal * 1.1) {
            return [
                'acao' => 'OFERECER_ALTERNATIVA',
                'confianca' => 85,
                'razao' => "Produto original indisponível. Alternativa encontrada: " .
                           "{$melhorAlternativa['name']} por R$ " . number_format($precoAlt, 2, ',', '.') .
                           ". Oferecer ao cliente com desconto adicional.",
                'produto_sugerido' => $melhorAlternativa,
                'alternativas' => array_slice($alternativas, 0, 3),
                'desconto_sugerido' => 10, // 10% de desconto
                'valor_cupom_sugerido' => 50,
                'mensagem_rh' => "🤖 AI RECOMENDA: Oferecer alternativa ao cliente. " .
                                 "{$melhorAlternativa['name']} com 10% OFF + cupom R$50. " .
                                 "Vendedor: {$melhorAlternativa['store_name']}."
            ];
        }

        // Alternativa mais cara - verificar se vale
        if ($diferenca < 80) {
            return [
                'acao' => 'OFERECER_ALTERNATIVA',
                'confianca' => 70,
                'razao' => "Alternativa disponível por R$ " . number_format($precoAlt, 2, ',', '.') .
                           ". Diferença de R$ " . number_format($diferenca, 2, ',', '.') .
                           ". Oferecer ao cliente - ele pode optar por pagar a diferença ou receber cupom.",
                'produto_sugerido' => $melhorAlternativa,
                'alternativas' => array_slice($alternativas, 0, 3),
                'valor_cupom_sugerido' => 50,
                'mensagem_rh' => "🤖 AI RECOMENDA: Contatar cliente e oferecer opções. " .
                                 "Opção 1: {$melhorAlternativa['name']} (paga diferença). " .
                                 "Opção 2: Reembolso total + cupom R$50."
            ];
        }
    }

    // Caso 3: Não encontrou nada viável - cancelar e reembolsar
    return [
        'acao' => 'CANCELAR_REEMBOLSAR',
        'confianca' => 90,
        'razao' => "Não encontrei produto similar ou alternativa viável. " .
                   "Recomendo cancelar o pedido, reembolsar integralmente e dar cupom de desculpas.",
        'produto_sugerido' => null,
        'valor_reembolso' => $precoOriginal,
        'valor_cupom_sugerido' => 50,
        'mensagem_rh' => "🤖 AI RECOMENDA: CANCELAR pedido. Não há alternativa viável. " .
                         "Reembolsar R$ " . number_format($precoOriginal, 2, ',', '.') . " + cupom R$50. " .
                         "NÃO VALE A PENA absorver prejuízo."
    ];
}
