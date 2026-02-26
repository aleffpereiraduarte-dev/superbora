<?php
/**
 * API de Analytics do Mercado
 * Retorna dados completos para dashboard de analytics
 *
 * GET /api/mercado/painel/analytics.php?partner_id=100&periodo=30
 */

session_start();
require_once dirname(__DIR__, 3) . '/database.php';
require_once dirname(__DIR__, 3) . '/includes/classes/OmCache.php';

// CORS: origin whitelist (replaces Access-Control-Allow-Origin: *)
$allowedOrigins = ['https://superbora.com.br', 'https://www.superbora.com.br', 'https://onemundo.com.br', 'https://www.onemundo.com.br'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET');

// Verificar autenticacao - requer sessao valida
if (!isset($_SESSION['mercado_id'])) {
    echo json_encode(['success' => false, 'error' => 'Nao autorizado']);
    exit;
}

try {
$db = getDB();
} catch (Exception $e) {
    http_response_code(500);
    error_log("[painel/analytics] DB connection error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro de conexao'], JSON_UNESCAPED_UNICODE);
    exit;
}
$partner_id = $_SESSION['mercado_id'];
$periodo = intval($_GET['periodo'] ?? 30);

// Validar periodo
$periodos_validos = [7, 30, 90, 180, 365];
if (!in_array($periodo, $periodos_validos) || $periodo <= 0) {
    $periodo = 30;
}

// Datas customizadas
$data_inicio = $_GET['data_inicio'] ?? null;
$data_fim = $_GET['data_fim'] ?? null;

if ($data_inicio && $data_fim) {
    // SECURITY: Validate date format to prevent injection
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_inicio) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_fim)) {
        echo json_encode(['success' => false, 'error' => 'Formato de data invalido. Use YYYY-MM-DD']);
        exit;
    }
    $inicio = $data_inicio;
    $fim = $data_fim;
    $periodo_dias = max(1, (strtotime($fim) - strtotime($inicio)) / 86400);
} else {
    $fim = date('Y-m-d');
    $inicio = date('Y-m-d', strtotime("-{$periodo} days"));
    $periodo_dias = $periodo;
}

// Periodo anterior para comparacao
$inicio_anterior = date('Y-m-d', strtotime($inicio . " -{$periodo_dias} days"));
$fim_anterior = date('Y-m-d', strtotime($inicio . ' -1 day'));

try {
    // Cache key
    $cache = OmCache::getInstance();
    $cache_key = "analytics_{$partner_id}_{$periodo}_{$inicio}_{$fim}";

    // Tentar pegar do cache (5 minutos)
    $cached = $cache->get($cache_key);
    if ($cached && !isset($_GET['nocache'])) {
        echo json_encode($cached);
        exit;
    }

    // ========================================================================
    // METRICAS PRINCIPAIS
    // ========================================================================

    // Periodo atual
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN status IN ('entregue', 'finalizado') THEN total ELSE 0 END), 0) as receita_total,
            COUNT(*) as total_pedidos,
            COUNT(CASE WHEN status IN ('entregue', 'finalizado') THEN 1 END) as pedidos_finalizados,
            COUNT(CASE WHEN status = 'cancelado' THEN 1 END) as pedidos_cancelados,
            COALESCE(AVG(CASE WHEN status IN ('entregue', 'finalizado') THEN total END), 0) as ticket_medio
        FROM om_market_orders
        WHERE partner_id = ?
        AND DATE(date_added) BETWEEN ? AND ?
    ");
    $stmt->execute([$partner_id, $inicio, $fim]);
    $metricas_atual = $stmt->fetch();

    // Periodo anterior
    $stmt->execute([$partner_id, $inicio_anterior, $fim_anterior]);
    $metricas_anterior = $stmt->fetch();

    // Calcular variacoes
    $variacao_receita = $metricas_anterior['receita_total'] > 0
        ? (($metricas_atual['receita_total'] - $metricas_anterior['receita_total']) / $metricas_anterior['receita_total']) * 100
        : 0;
    $variacao_pedidos = $metricas_anterior['total_pedidos'] > 0
        ? (($metricas_atual['total_pedidos'] - $metricas_anterior['total_pedidos']) / $metricas_anterior['total_pedidos']) * 100
        : 0;

    // Taxa de conversao (pedidos finalizados / total)
    $taxa_conversao = $metricas_atual['total_pedidos'] > 0
        ? ($metricas_atual['pedidos_finalizados'] / $metricas_atual['total_pedidos']) * 100
        : 0;

    // ========================================================================
    // VENDAS DIARIAS
    // ========================================================================

    $stmt = $db->prepare("
        SELECT
            DATE(date_added) as data,
            COALESCE(SUM(CASE WHEN status IN ('entregue', 'finalizado') THEN total ELSE 0 END), 0) as receita,
            COUNT(*) as pedidos
        FROM om_market_orders
        WHERE partner_id = ?
        AND DATE(date_added) BETWEEN ? AND ?
        GROUP BY DATE(date_added)
        ORDER BY data ASC
    ");
    $stmt->execute([$partner_id, $inicio, $fim]);
    $vendas_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Periodo anterior para comparacao no grafico
    $stmt->execute([$partner_id, $inicio_anterior, $fim_anterior]);
    $vendas_anterior_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mapear vendas anteriores por indice de dia
    $vendas_anterior_map = [];
    foreach ($vendas_anterior_raw as $i => $v) {
        $vendas_anterior_map[$i] = floatval($v['receita']);
    }

    // Preencher dias sem vendas
    $vendas_diarias = [];
    $vendas_anteriores = [];
    $current_date = strtotime($inicio);
    $end_date = strtotime($fim);
    $day_index = 0;

    while ($current_date <= $end_date) {
        $date_str = date('Y-m-d', $current_date);
        $found = false;

        foreach ($vendas_raw as $v) {
            if ($v['data'] === $date_str) {
                $vendas_diarias[] = [
                    'data' => $date_str,
                    'receita' => floatval($v['receita']),
                    'pedidos' => intval($v['pedidos'])
                ];
                $found = true;
                break;
            }
        }

        if (!$found) {
            $vendas_diarias[] = [
                'data' => $date_str,
                'receita' => 0,
                'pedidos' => 0
            ];
        }

        // Vendas do periodo anterior
        $vendas_anteriores[] = [
            'data' => $date_str,
            'receita' => $vendas_anterior_map[$day_index] ?? 0
        ];

        $current_date = strtotime('+1 day', $current_date);
        $day_index++;
    }

    // ========================================================================
    // TOP PRODUTOS
    // ========================================================================

    $stmt = $db->prepare("
        SELECT
            oi.product_id as id,
            oi.product_name as nome,
            SUM(oi.quantity) as quantidade,
            SUM(oi.quantity * oi.price) as receita
        FROM om_market_order_items oi
        JOIN om_market_orders o ON oi.order_id = o.order_id
        WHERE o.partner_id = ?
        AND o.status IN ('entregue', 'finalizado')
        AND DATE(o.date_added) BETWEEN ? AND ?
        GROUP BY oi.product_id, oi.product_name
        ORDER BY quantidade DESC
        LIMIT 10
    ");
    $stmt->execute([$partner_id, $inicio, $fim]);
    $top_produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatar
    $top_produtos = array_map(function($p) {
        return [
            'id' => intval($p['id']),
            'nome' => $p['nome'],
            'quantidade' => intval($p['quantidade']),
            'receita' => floatval($p['receita'])
        ];
    }, $top_produtos);

    // ========================================================================
    // CATEGORIAS
    // ========================================================================

    $stmt = $db->prepare("
        SELECT
            COALESCE(c.name, 'Sem Categoria') as nome,
            SUM(oi.quantity * oi.price) as receita
        FROM om_market_order_items oi
        JOIN om_market_orders o ON oi.order_id = o.order_id
        LEFT JOIN om_market_products p ON oi.product_id = p.product_id
        LEFT JOIN om_market_categories c ON p.category_id = c.category_id
        WHERE o.partner_id = ?
        AND o.status IN ('entregue', 'finalizado')
        AND DATE(o.date_added) BETWEEN ? AND ?
        GROUP BY c.category_id, c.name
        ORDER BY receita DESC
    ");
    $stmt->execute([$partner_id, $inicio, $fim]);
    $categorias_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $receita_total_cat = array_sum(array_column($categorias_raw, 'receita'));
    $categorias = array_map(function($c) use ($receita_total_cat) {
        $receita = floatval($c['receita']);
        return [
            'nome' => $c['nome'],
            'receita' => $receita,
            'percentual' => $receita_total_cat > 0 ? round(($receita / $receita_total_cat) * 100, 1) : 0
        ];
    }, $categorias_raw);

    // ========================================================================
    // HORARIOS DE PICO
    // ========================================================================

    // Por hora
    $stmt = $db->prepare("
        SELECT
            EXTRACT(HOUR FROM date_added)::int as hora,
            COUNT(*) as pedidos
        FROM om_market_orders
        WHERE partner_id = ?
        AND DATE(date_added) BETWEEN ? AND ?
        GROUP BY EXTRACT(HOUR FROM date_added)
        ORDER BY hora
    ");
    $stmt->execute([$partner_id, $inicio, $fim]);
    $pedidos_hora = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Preencher todas as horas
    $por_hora = [];
    for ($h = 0; $h < 24; $h++) {
        $por_hora[] = intval($pedidos_hora[$h] ?? 0);
    }

    // Por dia da semana (0=domingo, 6=sabado)
    $stmt = $db->prepare("
        SELECT
            EXTRACT(DOW FROM date_added)::int as dia,
            COUNT(*) as pedidos
        FROM om_market_orders
        WHERE partner_id = ?
        AND DATE(date_added) BETWEEN ? AND ?
        GROUP BY EXTRACT(DOW FROM date_added)
        ORDER BY dia
    ");
    $stmt->execute([$partner_id, $inicio, $fim]);
    $pedidos_dia = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // EXTRACT(DOW) returns 0=domingo, 1=segunda ... 6=sabado
    $por_dia = [];
    for ($d = 0; $d <= 6; $d++) {
        $por_dia[] = intval($pedidos_dia[$d] ?? 0);
    }

    // ========================================================================
    // SUGESTOES DE PROMOCOES (IA Baseada em Regras)
    // ========================================================================

    $sugestoes = [];

    // 1. Produtos com baixa saida
    $stmt = $db->prepare("
        SELECT
            p.product_id,
            p.name,
            p.price,
            p.stock,
            COALESCE(SUM(oi.quantity), 0) as vendido
        FROM om_market_products p
        LEFT JOIN om_market_order_items oi ON p.product_id = oi.product_id
        LEFT JOIN om_market_orders o ON oi.order_id = o.order_id
            AND o.status IN ('entregue', 'finalizado')
            AND DATE(o.date_added) BETWEEN ? AND ?
        WHERE p.partner_id = ?
        AND p.status::text = '1'
        AND p.stock > 10
        GROUP BY p.product_id, p.name, p.price, p.stock
        HAVING COALESCE(SUM(oi.quantity), 0) < 10
        ORDER BY vendido ASC
        LIMIT 5
    ");
    $stmt->execute([$inicio, $fim, $partner_id]);
    $baixa_saida = $stmt->fetchAll();

    foreach ($baixa_saida as $p) {
        if ($p['vendido'] < 10) {
            $desconto = $p['vendido'] <= 2 ? 30 : ($p['vendido'] <= 5 ? 20 : 15);
            $sugestoes[] = [
                'tipo' => 'baixa_saida',
                'produto_id' => intval($p['product_id']),
                'produto' => $p['name'],
                'motivo' => "Vendeu apenas {$p['vendido']} unidades nos ultimos {$periodo} dias",
                'sugestao' => "Desconto de {$desconto}% pode aumentar vendas",
                'desconto_sugerido' => $desconto,
                'estoque_atual' => intval($p['stock']),
                'preco_atual' => floatval($p['price']),
                'preco_sugerido' => round($p['price'] * (1 - $desconto/100), 2)
            ];
        }
    }

    // 2. Produtos proximos da validade (se tiver campo expiry_date)
    $stmt = $db->prepare("
        SELECT
            p.product_id,
            p.name,
            p.price,
            p.expiry_date,
            (p.expiry_date::date - CURRENT_DATE) as dias_validade
        FROM om_market_products p
        WHERE p.partner_id = ?
        AND p.status::text = '1'
        AND p.expiry_date IS NOT NULL
        AND p.expiry_date > CURRENT_DATE
        AND (p.expiry_date::date - CURRENT_DATE) <= 15
        ORDER BY p.expiry_date ASC
        LIMIT 5
    ");
    $stmt->execute([$partner_id]);
    $proximos_vencimento = $stmt->fetchAll();

    foreach ($proximos_vencimento as $p) {
        $dias = intval($p['dias_validade']);
        $desconto = $dias <= 3 ? 50 : ($dias <= 7 ? 35 : 20);
        $sugestoes[] = [
            'tipo' => 'proxima_validade',
            'produto_id' => intval($p['product_id']),
            'produto' => $p['name'],
            'motivo' => "Vence em {$dias} dias ({$p['expiry_date']})",
            'sugestao' => "Aplicar desconto de {$desconto}% para acelerar saida",
            'desconto_sugerido' => $desconto,
            'dias_validade' => $dias,
            'preco_atual' => floatval($p['price']),
            'preco_sugerido' => round($p['price'] * (1 - $desconto/100), 2)
        ];
    }

    // 3. Combos - Produtos frequentemente comprados juntos
    $stmt = $db->prepare("
        SELECT
            oi1.product_name as produto1,
            oi1.product_id as produto1_id,
            oi2.product_name as produto2,
            oi2.product_id as produto2_id,
            COUNT(*) as vezes_juntos
        FROM om_market_order_items oi1
        JOIN om_market_order_items oi2 ON oi1.order_id = oi2.order_id AND oi1.product_id < oi2.product_id
        JOIN om_market_orders o ON oi1.order_id = o.order_id
        WHERE o.partner_id = ?
        AND o.status IN ('entregue', 'finalizado')
        AND DATE(o.date_added) BETWEEN ? AND ?
        GROUP BY oi1.product_id, oi2.product_id, oi1.product_name, oi2.product_name
        HAVING COUNT(*) >= 5
        ORDER BY vezes_juntos DESC
        LIMIT 3
    ");
    $stmt->execute([$partner_id, $inicio, $fim]);
    $combos = $stmt->fetchAll();

    foreach ($combos as $c) {
        // Calcular percentual
        $stmt2 = $db->prepare("
            SELECT COUNT(DISTINCT order_id) as total
            FROM om_market_order_items
            WHERE product_id = ?
        ");
        $stmt2->execute([$c['produto1_id']]);
        $total_p1 = $stmt2->fetchColumn() ?: 1;
        $percentual = round(($c['vezes_juntos'] / $total_p1) * 100);

        $sugestoes[] = [
            'tipo' => 'combo',
            'produtos' => [$c['produto1'], $c['produto2']],
            'produto_ids' => [intval($c['produto1_id']), intval($c['produto2_id'])],
            'motivo' => "{$percentual}% dos clientes que compram {$c['produto1']} tambem compram {$c['produto2']}",
            'sugestao' => "Criar combo com 10% de desconto",
            'vezes_juntos' => intval($c['vezes_juntos']),
            'desconto_sugerido' => 10
        ];
    }

    // 4. Produtos sazonais (baseado em nome)
    $mes_atual = intval(date('n'));
    $produtos_sazonais = [];

    // Mapping de produtos sazonais
    $sazonalidade = [
        12 => ['panetone', 'chester', 'peru', 'champagne', 'sidra', 'lentilha', 'natal'],
        1 => ['protetor solar', 'cerveja', 'refrigerante', 'gelo', 'carvao', 'agua'],
        2 => ['cerveja', 'agua', 'refrigerante', 'carnaval'],
        6 => ['vinho', 'fondue', 'chocolate quente', 'sao joao', 'milho', 'pipoca'],
        7 => ['vinho', 'chocolate', 'fondue'],
    ];

    if (isset($sazonalidade[$mes_atual])) {
        $termos = $sazonalidade[$mes_atual];
        $placeholders = str_repeat('?,', count($termos) - 1) . '?';
        $like_conditions = implode(' OR ', array_fill(0, count($termos), 'LOWER(p.name) LIKE ?'));
        $like_params = array_map(fn($t) => "%$t%", $termos);

        $sql = "
            SELECT
                p.product_id,
                p.name,
                p.price,
                p.stock
            FROM om_market_products p
            WHERE p.partner_id = ?
            AND p.status::text = '1'
            AND ({$like_conditions})
            ORDER BY p.stock DESC
            LIMIT 3
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge([$partner_id], $like_params));
        $sazonais = $stmt->fetchAll();

        foreach ($sazonais as $p) {
            $sugestoes[] = [
                'tipo' => 'sazonal',
                'produto_id' => intval($p['product_id']),
                'produto' => $p['name'],
                'motivo' => "Produto sazonal - alta demanda neste periodo",
                'sugestao' => "Destaque na vitrine e considere aumentar estoque",
                'estoque_atual' => intval($p['stock'])
            ];
        }
    }

    // 5. Produtos mais vendidos que podem ter preco aumentado
    $stmt = $db->prepare("
        SELECT
            oi.product_id,
            p.name,
            p.price,
            SUM(oi.quantity) as quantidade,
            COUNT(DISTINCT oi.order_id) as pedidos
        FROM om_market_order_items oi
        JOIN om_market_orders o ON oi.order_id = o.order_id
        JOIN om_market_products p ON oi.product_id = p.product_id
        WHERE o.partner_id = ?
        AND o.status IN ('entregue', 'finalizado')
        AND DATE(o.date_added) BETWEEN ? AND ?
        GROUP BY oi.product_id, p.name, p.price
        HAVING SUM(oi.quantity) >= 50
        ORDER BY quantidade DESC
        LIMIT 2
    ");
    $stmt->execute([$partner_id, $inicio, $fim]);
    $alta_demanda = $stmt->fetchAll();

    foreach ($alta_demanda as $p) {
        $sugestoes[] = [
            'tipo' => 'alta_demanda',
            'produto_id' => intval($p['product_id']),
            'produto' => $p['name'],
            'motivo' => "Alta demanda: {$p['quantidade']} unidades vendidas em {$periodo} dias",
            'sugestao' => "Considere ajuste de preco de 5-10% ou garantir estoque",
            'quantidade_vendida' => intval($p['quantidade']),
            'preco_atual' => floatval($p['price'])
        ];
    }

    // ========================================================================
    // MONTAR RESPOSTA
    // ========================================================================

    $response = [
        'success' => true,
        'periodo' => "{$periodo} dias",
        'data_inicio' => $inicio,
        'data_fim' => $fim,
        'metricas' => [
            'receita_total' => floatval($metricas_atual['receita_total']),
            'receita_anterior' => floatval($metricas_anterior['receita_total']),
            'variacao_receita' => round($variacao_receita, 1),
            'total_pedidos' => intval($metricas_atual['total_pedidos']),
            'pedidos_anterior' => intval($metricas_anterior['total_pedidos']),
            'variacao_pedidos' => round($variacao_pedidos, 1),
            'pedidos_finalizados' => intval($metricas_atual['pedidos_finalizados']),
            'pedidos_cancelados' => intval($metricas_atual['pedidos_cancelados']),
            'ticket_medio' => round(floatval($metricas_atual['ticket_medio']), 2),
            'taxa_conversao' => round($taxa_conversao, 1)
        ],
        'vendas_diarias' => $vendas_diarias,
        'vendas_anteriores' => $vendas_anteriores,
        'top_produtos' => $top_produtos,
        'categorias' => $categorias,
        'horarios_pico' => [
            'por_hora' => $por_hora,
            'por_dia' => $por_dia,
            'dias_semana' => ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab'],
            'melhor_hora' => array_search(max($por_hora), $por_hora),
            'pior_hora' => array_search(min(array_filter($por_hora) ?: [0]), $por_hora),
            'melhor_dia' => array_search(max($por_dia), $por_dia)
        ],
        'sugestoes_promocao' => $sugestoes,
        'gerado_em' => date('c')
    ];

    // Cachear por 5 minutos
    $cache->set($cache_key, $response, 300);

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    error_log("[painel/analytics] Erro: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao gerar analytics'
    ], JSON_UNESCAPED_UNICODE);
}
