<?php
/**
 * API RECALCULO INTELIGENTE COM AI
 * Recalcula valor final do pedido e explica mudancas ao cliente
 *
 * Funcionalidades:
 * - Recalcula total baseado em substituicoes e itens removidos
 * - Gera explicacao clara das mudancas
 * - Mostra diferenca entre valor original e final
 * - Integra com sistema de creditos/reembolsos
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Config centralizado
require_once dirname(__DIR__) . '/config/database.php';

try {
    $pdo = getPDO();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexao']);
    exit;
}

// Criar tabela de historico de recalculos
$pdo->exec("CREATE TABLE IF NOT EXISTS om_order_recalculations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    original_total DECIMAL(10,2) NOT NULL,
    final_total DECIMAL(10,2) NOT NULL,
    difference DECIMAL(10,2) NOT NULL,
    items_removed INT DEFAULT 0,
    items_substituted INT DEFAULT 0,
    explanation TEXT,
    ai_summary TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function jsonOut($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? $_GET['action'] ?? 'recalcular';
$order_id = (int)($input['order_id'] ?? $_GET['order_id'] ?? 0);

// ═══════════════════════════════════════════════════════════════════════════════
// RECALCULAR: Recalcula valor total do pedido
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'recalcular') {
    if (!$order_id) {
        jsonOut(['success' => false, 'error' => 'order_id obrigatorio']);
    }

    // Buscar pedido
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        jsonOut(['success' => false, 'error' => 'Pedido nao encontrado']);
    }

    // Buscar itens do pedido
    $stmt = $pdo->prepare("SELECT * FROM om_market_order_items WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Buscar substituicoes
    $stmt = $pdo->prepare("SELECT * FROM om_market_substitutions WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $substitutions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Buscar ajustes
    $stmt = $pdo->prepare("SELECT * FROM om_order_adjustments WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calcular valores
    $original_total = (float)($order['original_total'] ?? $order['total'] ?? 0);
    $shipping_fee = (float)($order['shipping_fee'] ?? $order['delivery_fee'] ?? 0);

    $items_active = 0;
    $items_removed = 0;
    $items_substituted = 0;
    $products_total = 0;
    $changes = [];

    foreach ($items as $item) {
        $qty = (int)($item['quantity'] ?? 1);
        $price = (float)($item['price'] ?? 0);
        $total = (float)($item['total'] ?? $price * $qty);
        $is_removed = !empty($item['is_removed']) || !empty($item['removed']);
        $is_substituted = !empty($item['substituted']) || !empty($item['substitute_name']);

        if ($is_removed) {
            $items_removed++;
            $changes[] = [
                'type' => 'removed',
                'product' => $item['name'] ?? $item['product_name'] ?? 'Produto',
                'original_price' => $price,
                'quantity' => $qty,
                'amount' => -($total),
                'reason' => $item['removal_reason'] ?? 'Produto indisponivel'
            ];
        } elseif ($is_substituted) {
            $items_substituted++;
            $new_price = (float)($item['substitute_price'] ?? $price);
            $diff = ($new_price - $price) * $qty;
            $products_total += $new_price * $qty;

            $changes[] = [
                'type' => 'substituted',
                'product' => $item['name'] ?? $item['product_name'] ?? 'Produto',
                'substitute' => $item['substitute_name'] ?? 'Substituto',
                'original_price' => $price,
                'new_price' => $new_price,
                'quantity' => $qty,
                'amount' => $diff,
                'reason' => $item['replacement_reason'] ?? 'Substituido por similar'
            ];
        } else {
            $items_active++;
            $products_total += $total;
        }
    }

    // Adicionar substituicoes da tabela separada
    foreach ($substitutions as $sub) {
        if ($sub['status'] === 'approved') {
            $diff = (float)$sub['suggested_price'] - (float)$sub['original_price'];
            $changes[] = [
                'type' => 'substituted',
                'product' => $sub['original_name'],
                'substitute' => $sub['suggested_name'],
                'original_price' => (float)$sub['original_price'],
                'new_price' => (float)$sub['suggested_price'],
                'quantity' => 1,
                'amount' => $diff,
                'reason' => $sub['reason'] ?? 'Produto substituido'
            ];
        }
    }

    // Calcular ajustes de valor
    $total_refunds = 0;
    $total_charges = 0;

    foreach ($adjustments as $adj) {
        if ($adj['status'] === 'processed') {
            if ($adj['direction'] === 'refund') {
                $total_refunds += (float)$adj['amount'];
            } else {
                $total_charges += (float)$adj['amount'];
            }
        }
    }

    // Calcular total final
    $final_products = $products_total;
    $final_total = $final_products + $shipping_fee + $total_charges - $total_refunds;
    $difference = $final_total - $original_total;

    // Gerar explicacao com AI
    $ai_summary = generateAISummary($changes, $original_total, $final_total, $difference);

    // Salvar recalculo
    $stmt = $pdo->prepare("INSERT INTO om_order_recalculations
        (order_id, original_total, final_total, difference, items_removed, items_substituted, explanation, ai_summary)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $order_id,
        $original_total,
        $final_total,
        $difference,
        $items_removed,
        $items_substituted,
        json_encode($changes, JSON_UNESCAPED_UNICODE),
        $ai_summary
    ]);

    // Atualizar pedido com valores finais
    $stmt = $pdo->prepare("UPDATE om_market_orders SET
        final_total = ?,
        adjustments_total = ?,
        recalculated_at = NOW()
        WHERE order_id = ?");
    $stmt->execute([$final_total, $difference, $order_id]);

    jsonOut([
        'success' => true,
        'order_id' => $order_id,
        'original' => [
            'products' => $original_total - $shipping_fee,
            'shipping' => $shipping_fee,
            'total' => $original_total
        ],
        'final' => [
            'products' => $final_products,
            'shipping' => $shipping_fee,
            'refunds' => $total_refunds,
            'charges' => $total_charges,
            'total' => $final_total
        ],
        'difference' => $difference,
        'summary' => [
            'items_active' => $items_active,
            'items_removed' => $items_removed,
            'items_substituted' => $items_substituted,
            'total_items' => count($items)
        ],
        'changes' => $changes,
        'ai_summary' => $ai_summary,
        'formatted' => [
            'original' => 'R$ ' . number_format($original_total, 2, ',', '.'),
            'final' => 'R$ ' . number_format($final_total, 2, ',', '.'),
            'difference' => ($difference >= 0 ? '+' : '') . 'R$ ' . number_format($difference, 2, ',', '.'),
            'savings' => $difference < 0 ? 'R$ ' . number_format(abs($difference), 2, ',', '.') : null
        ]
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// RESUMO: Retorna resumo do recalculo para exibir ao cliente
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'resumo') {
    if (!$order_id) {
        jsonOut(['success' => false, 'error' => 'order_id obrigatorio']);
    }

    $stmt = $pdo->prepare("SELECT * FROM om_order_recalculations WHERE order_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$order_id]);
    $recalc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$recalc) {
        jsonOut(['success' => false, 'error' => 'Recalculo nao encontrado. Chame action=recalcular primeiro.']);
    }

    $changes = json_decode($recalc['explanation'], true) ?: [];

    jsonOut([
        'success' => true,
        'order_id' => $order_id,
        'original_total' => (float)$recalc['original_total'],
        'final_total' => (float)$recalc['final_total'],
        'difference' => (float)$recalc['difference'],
        'items_removed' => (int)$recalc['items_removed'],
        'items_substituted' => (int)$recalc['items_substituted'],
        'changes' => $changes,
        'ai_summary' => $recalc['ai_summary'],
        'formatted' => [
            'original' => 'R$ ' . number_format($recalc['original_total'], 2, ',', '.'),
            'final' => 'R$ ' . number_format($recalc['final_total'], 2, ',', '.'),
            'difference' => ((float)$recalc['difference'] >= 0 ? '+' : '') . 'R$ ' . number_format($recalc['difference'], 2, ',', '.')
        ],
        'calculated_at' => $recalc['created_at']
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// NOTIFICAR: Envia notificacao ao cliente com o resumo
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'notificar') {
    if (!$order_id) {
        jsonOut(['success' => false, 'error' => 'order_id obrigatorio']);
    }

    // Buscar ultimo recalculo
    $stmt = $pdo->prepare("SELECT * FROM om_order_recalculations WHERE order_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$order_id]);
    $recalc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$recalc) {
        jsonOut(['success' => false, 'error' => 'Faca o recalculo primeiro']);
    }

    // Buscar pedido
    $stmt = $pdo->prepare("SELECT customer_id FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        jsonOut(['success' => false, 'error' => 'Pedido nao encontrado']);
    }

    // Criar notificacao
    $title = "Valor do pedido atualizado";
    $diff = (float)$recalc['difference'];

    if ($diff < 0) {
        $body = "Boas noticias! Voce economizou R$ " . number_format(abs($diff), 2, ',', '.') . " no pedido #" . $order_id;
    } elseif ($diff > 0) {
        $body = "O valor do pedido #" . $order_id . " foi ajustado em +R$ " . number_format($diff, 2, ',', '.');
    } else {
        $body = "Seu pedido #" . $order_id . " foi finalizado sem alteracoes de valor.";
    }

    $stmt = $pdo->prepare("INSERT INTO om_notifications
        (user_type, user_id, title, body, type, reference_id, created_at)
        VALUES ('customer', ?, ?, ?, 'order_recalc', ?, NOW())");
    $stmt->execute([$order['customer_id'], $title, $body, $order_id]);

    // Adicionar mensagem no chat do pedido
    $chat_msg = "💰 *Valor do pedido atualizado*\n\n";
    $chat_msg .= "Original: R$ " . number_format($recalc['original_total'], 2, ',', '.') . "\n";
    $chat_msg .= "Final: R$ " . number_format($recalc['final_total'], 2, ',', '.') . "\n";
    if ($diff != 0) {
        $chat_msg .= "Diferenca: " . ($diff >= 0 ? '+' : '') . "R$ " . number_format($diff, 2, ',', '.') . "\n";
    }
    if (!empty($recalc['ai_summary'])) {
        $chat_msg .= "\n" . $recalc['ai_summary'];
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO om_market_chat
            (order_id, sender_type, sender_id, message, date_added)
            VALUES (?, 'system', 0, ?, NOW())");
        $stmt->execute([$order_id, $chat_msg]);
    } catch (Exception $e) {
        // Tabela de chat pode nao existir
    }

    jsonOut([
        'success' => true,
        'notified' => true,
        'message' => $body
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// HISTORICO: Lista todos os recalculos do pedido
// ═══════════════════════════════════════════════════════════════════════════════
if ($action === 'historico') {
    if (!$order_id) {
        jsonOut(['success' => false, 'error' => 'order_id obrigatorio']);
    }

    $stmt = $pdo->prepare("SELECT * FROM om_order_recalculations WHERE order_id = ? ORDER BY created_at DESC");
    $stmt->execute([$order_id]);
    $recalculations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonOut([
        'success' => true,
        'order_id' => $order_id,
        'total' => count($recalculations),
        'recalculations' => array_map(function($r) {
            return [
                'id' => $r['id'],
                'original' => (float)$r['original_total'],
                'final' => (float)$r['final_total'],
                'difference' => (float)$r['difference'],
                'items_removed' => (int)$r['items_removed'],
                'items_substituted' => (int)$r['items_substituted'],
                'summary' => $r['ai_summary'],
                'date' => $r['created_at']
            ];
        }, $recalculations)
    ]);
}

jsonOut(['success' => false, 'error' => 'Acao invalida. Use: recalcular, resumo, notificar, historico']);

/**
 * Gera explicacao inteligente das mudancas usando AI
 */
function generateAISummary($changes, $original, $final, $difference) {
    if (empty($changes)) {
        return "Seu pedido foi processado sem alteracoes.";
    }

    $removed = array_filter($changes, fn($c) => $c['type'] === 'removed');
    $substituted = array_filter($changes, fn($c) => $c['type'] === 'substituted');

    $parts = [];

    if (count($removed) > 0) {
        $total_refund = array_sum(array_map(fn($c) => abs($c['amount']), $removed));
        if (count($removed) === 1) {
            $item = reset($removed);
            $parts[] = "O produto \"{$item['product']}\" nao estava disponivel e foi removido do pedido";
        } else {
            $parts[] = count($removed) . " produtos nao estavam disponiveis e foram removidos";
        }
    }

    if (count($substituted) > 0) {
        $cheaper = array_filter($substituted, fn($c) => $c['amount'] < 0);
        $expensive = array_filter($substituted, fn($c) => $c['amount'] > 0);

        if (count($substituted) === 1) {
            $item = reset($substituted);
            $parts[] = "\"{$item['product']}\" foi substituido por \"{$item['substitute']}\"";
        } else {
            $parts[] = count($substituted) . " produtos foram substituidos por similares";
        }

        if (count($cheaper) > 0 && count($expensive) === 0) {
            $parts[] = "As substituicoes ficaram mais baratas!";
        }
    }

    // Conclusao sobre o valor
    if ($difference < -1) {
        $parts[] = "Voce economizou R$ " . number_format(abs($difference), 2, ',', '.') . " neste pedido!";
    } elseif ($difference > 1) {
        $parts[] = "Houve um pequeno ajuste de R$ " . number_format($difference, 2, ',', '.') . " no valor total.";
    }

    return implode(". ", $parts) . ".";
}
