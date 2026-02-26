<?php
/**
 * GET /api/mercado/partner/fiscal.php
 * Fiscal reports for partner tax obligations
 *
 * Actions:
 *   (default) — Fiscal summary for period
 *     Params: mes (YYYY-MM), tipo (mensal|trimestral|anual)
 *
 *   action=export — Export CSV of orders for fiscal records
 *     Params: mes (YYYY-MM)
 *
 *   action=notas — List orders for NFC-e manual emission
 *     Params: mes (YYYY-MM)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partnerId = (int)$payload['uid'];

    $action = trim($_GET['action'] ?? '');

    // Parse mes (YYYY-MM) parameter
    $mes = trim($_GET['mes'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
        $mes = date('Y-m');
    }

    $tipo = trim($_GET['tipo'] ?? 'mensal');
    if (!in_array($tipo, ['mensal', 'trimestral', 'anual'], true)) {
        $tipo = 'mensal';
    }

    // Determine date range based on tipo
    $baseYear = (int)substr($mes, 0, 4);
    $baseMonth = (int)substr($mes, 5, 2);

    switch ($tipo) {
        case 'trimestral':
            // Quarter containing the selected month
            $quarterStart = (int)(floor(($baseMonth - 1) / 3) * 3 + 1);
            $quarterEnd = $quarterStart + 2;
            $startDate = sprintf('%04d-%02d-01', $baseYear, $quarterStart);
            $endDate = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $baseYear, $quarterEnd)));
            $periodoLabel = "{$quarterStart}T" . ceil($quarterStart / 3) . "/{$baseYear}";
            break;
        case 'anual':
            $startDate = "{$baseYear}-01-01";
            $endDate = "{$baseYear}-12-31";
            $periodoLabel = "Ano {$baseYear}";
            break;
        case 'mensal':
        default:
            $startDate = sprintf('%04d-%02d-01', $baseYear, $baseMonth);
            $endDate = date('Y-m-t', strtotime($startDate));
            $periodoLabel = sprintf('%02d/%04d', $baseMonth, $baseYear);
            break;
    }

    // ======================== ACTION: EXPORT CSV ========================
    if ($action === 'export') {
        $stmtExport = $db->prepare("
            SELECT
                o.order_id,
                o.order_number,
                DATE(o.date_added) as data,
                o.total,
                o.subtotal,
                o.forma_pagamento,
                o.delivery_fee,
                o.status
            FROM om_market_orders o
            WHERE o.partner_id = ?
              AND DATE(o.date_added) BETWEEN ? AND ?
              AND o.status NOT IN ('cancelado', 'cancelled')
            ORDER BY o.date_added ASC
        ");
        $stmtExport->execute([$partnerId, $startDate, $endDate]);
        $exportRows = $stmtExport->fetchAll();

        // Get commission rate
        $stmtRate = $db->prepare("SELECT COALESCE(commission_rate, 12.00) as rate FROM om_market_partners WHERE partner_id = ?");
        $stmtRate->execute([$partnerId]);
        $commissionRate = (float)($stmtRate->fetch()['rate'] ?? 12);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="fiscal-' . $mes . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        // BOM for Excel UTF-8
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($output, ['Data', 'Pedido', 'Numero', 'Valor Bruto', 'Subtotal', 'Taxa Entrega', 'Forma Pagamento', 'Comissao (' . $commissionRate . '%)', 'Valor Liquido'], ';');

        foreach ($exportRows as $row) {
            $comissao = round((float)$row['total'] * $commissionRate / 100, 2);
            $liquido = round((float)$row['total'] - $comissao, 2);
            fputcsv($output, [
                $row['data'],
                $row['order_id'],
                $row['order_number'] ?? '',
                number_format((float)$row['total'], 2, ',', '.'),
                number_format((float)$row['subtotal'], 2, ',', '.'),
                number_format((float)$row['delivery_fee'], 2, ',', '.'),
                $row['forma_pagamento'] ?? 'N/A',
                number_format($comissao, 2, ',', '.'),
                number_format($liquido, 2, ',', '.'),
            ], ';');
        }

        fclose($output);
        exit;
    }

    // ======================== ACTION: NOTAS (NFC-e reference) ========================
    if ($action === 'notas') {
        $stmtNotas = $db->prepare("
            SELECT
                o.order_id,
                o.order_number,
                o.customer_name,
                o.customer_phone,
                DATE(o.date_added) as data,
                o.date_added,
                o.total,
                o.subtotal,
                o.delivery_fee,
                o.forma_pagamento,
                o.status,
                (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as total_items
            FROM om_market_orders o
            WHERE o.partner_id = ?
              AND DATE(o.date_added) BETWEEN ? AND ?
              AND o.status NOT IN ('cancelado', 'cancelled')
            ORDER BY o.date_added ASC
        ");
        $stmtNotas->execute([$partnerId, $startDate, $endDate]);
        $notasRows = $stmtNotas->fetchAll();

        $notas = [];
        foreach ($notasRows as $row) {
            // Fetch items for each order
            $stmtItems = $db->prepare("
                SELECT name, quantity, price
                FROM om_market_order_items
                WHERE order_id = ?
            ");
            $stmtItems->execute([$row['order_id']]);
            $items = $stmtItems->fetchAll();

            $formattedItems = [];
            foreach ($items as $item) {
                $formattedItems[] = [
                    'nome' => $item['name'],
                    'quantidade' => (int)$item['quantity'],
                    'preco_unitario' => round((float)$item['price'], 2),
                    'total' => round((float)$item['price'] * (int)$item['quantity'], 2),
                ];
            }

            $notas[] = [
                'order_id' => (int)$row['order_id'],
                'order_number' => $row['order_number'],
                'data' => $row['data'],
                'data_hora' => $row['date_added'],
                'cliente' => $row['customer_name'],
                'telefone' => $row['customer_phone'],
                'subtotal' => round((float)$row['subtotal'], 2),
                'taxa_entrega' => round((float)$row['delivery_fee'], 2),
                'total' => round((float)$row['total'], 2),
                'forma_pagamento' => $row['forma_pagamento'],
                'total_items' => (int)$row['total_items'],
                'items' => $formattedItems,
            ];
        }

        response(true, [
            'periodo' => $periodoLabel,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_notas' => count($notas),
            'notas' => $notas,
        ], "Notas fiscais listadas");
        exit;
    }

    // ======================== DEFAULT: FISCAL SUMMARY ========================

    // Get commission rate
    $stmtRate = $db->prepare("SELECT COALESCE(commission_rate, 12.00) as rate FROM om_market_partners WHERE partner_id = ?");
    $stmtRate->execute([$partnerId]);
    $commissionRate = (float)($stmtRate->fetch()['rate'] ?? 12);

    // Main summary
    $stmtSummary = $db->prepare("
        SELECT
            COALESCE(SUM(total), 0) as faturamento_bruto,
            COUNT(*) as total_pedidos,
            COALESCE(AVG(total), 0) as ticket_medio
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status NOT IN ('cancelado', 'cancelled')
    ");
    $stmtSummary->execute([$partnerId, $startDate, $endDate]);
    $summary = $stmtSummary->fetch();

    $faturamentoBruto = round((float)$summary['faturamento_bruto'], 2);
    $totalPedidos = (int)$summary['total_pedidos'];
    $ticketMedio = round((float)$summary['ticket_medio'], 2);

    // Calculate commissions and fees
    $comissoes = round($faturamentoBruto * $commissionRate / 100, 2);

    // Get service fees from orders (taxa_servico column if it exists, else estimate)
    $stmtTaxa = $db->prepare("
        SELECT COALESCE(SUM(delivery_fee), 0) as total_taxa
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status NOT IN ('cancelado', 'cancelled')
    ");
    $stmtTaxa->execute([$partnerId, $startDate, $endDate]);
    $taxasServico = round((float)($stmtTaxa->fetch()['total_taxa'] ?? 0), 2);

    $faturamentoLiquido = round($faturamentoBruto - $comissoes, 2);

    // Breakdown by payment method
    $stmtPayment = $db->prepare("
        SELECT
            COALESCE(forma_pagamento, 'outro') as forma,
            COUNT(*) as qtd,
            COALESCE(SUM(total), 0) as valor
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status NOT IN ('cancelado', 'cancelled')
        GROUP BY forma_pagamento
        ORDER BY valor DESC
    ");
    $stmtPayment->execute([$partnerId, $startDate, $endDate]);
    $paymentRows = $stmtPayment->fetchAll();

    $porFormaPagamento = [];
    foreach ($paymentRows as $row) {
        $forma = strtolower(trim($row['forma'] ?? 'outro'));
        $label = match(true) {
            str_contains($forma, 'pix') => 'PIX',
            str_contains($forma, 'credito') || str_contains($forma, 'credit') => 'Cartao Credito',
            str_contains($forma, 'debito') || str_contains($forma, 'debit') => 'Cartao Debito',
            str_contains($forma, 'dinheiro') || str_contains($forma, 'cash') => 'Dinheiro',
            str_contains($forma, 'cartao') || str_contains($forma, 'card') => 'Cartao',
            default => ucfirst($forma),
        };
        $porFormaPagamento[] = [
            'forma' => $label,
            'forma_raw' => $row['forma'],
            'quantidade' => (int)$row['qtd'],
            'valor' => round((float)$row['valor'], 2),
            'percentual' => $faturamentoBruto > 0 ? round(((float)$row['valor'] / $faturamentoBruto) * 100, 1) : 0,
        ];
    }

    // Weekly breakdown
    $stmtWeekly = $db->prepare("
        SELECT
            EXTRACT(WEEK FROM date_added) as semana,
            MIN(DATE(date_added)) as inicio,
            MAX(DATE(date_added)) as fim,
            COUNT(*) as pedidos,
            COALESCE(SUM(total), 0) as valor
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status NOT IN ('cancelado', 'cancelled')
        GROUP BY EXTRACT(WEEK FROM date_added)
        ORDER BY semana ASC
    ");
    $stmtWeekly->execute([$partnerId, $startDate, $endDate]);
    $weeklyRows = $stmtWeekly->fetchAll();

    $porSemana = [];
    $weekNum = 1;
    foreach ($weeklyRows as $row) {
        $porSemana[] = [
            'semana' => $weekNum,
            'label' => 'Semana ' . $weekNum,
            'inicio' => $row['inicio'],
            'fim' => $row['fim'],
            'pedidos' => (int)$row['pedidos'],
            'valor' => round((float)$row['valor'], 2),
        ];
        $weekNum++;
    }

    // Year-to-date accumulated revenue (for MEI limit tracking)
    $anoAtual = $baseYear;
    $stmtYtd = $db->prepare("
        SELECT COALESCE(SUM(total), 0) as acumulado
        FROM om_market_orders
        WHERE partner_id = ?
          AND EXTRACT(YEAR FROM date_added) = ?
          AND status NOT IN ('cancelado', 'cancelled')
    ");
    $stmtYtd->execute([$partnerId, $anoAtual]);
    $acumuladoAno = round((float)($stmtYtd->fetch()['acumulado'] ?? 0), 2);

    // Monthly revenue breakdown for the year (for chart)
    $stmtMonthly = $db->prepare("
        SELECT
            EXTRACT(MONTH FROM date_added) as mes_num,
            COALESCE(SUM(total), 0) as valor
        FROM om_market_orders
        WHERE partner_id = ?
          AND EXTRACT(YEAR FROM date_added) = ?
          AND status NOT IN ('cancelado', 'cancelled')
        GROUP BY EXTRACT(MONTH FROM date_added)
        ORDER BY mes_num ASC
    ");
    $stmtMonthly->execute([$partnerId, $anoAtual]);
    $monthlyRows = $stmtMonthly->fetchAll();

    $receitaMensal = [];
    $meses = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
    $monthlyMap = [];
    foreach ($monthlyRows as $mr) {
        $monthlyMap[(int)$mr['mes_num']] = round((float)$mr['valor'], 2);
    }
    for ($m = 1; $m <= 12; $m++) {
        $receitaMensal[] = [
            'mes' => $m,
            'label' => $meses[$m - 1],
            'valor' => $monthlyMap[$m] ?? 0,
        ];
    }

    // Tax estimation
    $meiLimiteAnual = 81000.00;
    $meiPercentualAno = $acumuladoAno > 0 ? round(($acumuladoAno / $meiLimiteAnual) * 100, 1) : 0;
    $meiAlerta = $acumuladoAno > ($meiLimiteAnual * 0.8);

    // Simples Nacional estimated rates by revenue bracket (approximate DAS rate for commercio)
    $simplesRate = 6.0; // Default ~6% for first bracket
    if ($acumuladoAno > 720000) {
        $simplesRate = 14.7;
    } elseif ($acumuladoAno > 540000) {
        $simplesRate = 12.74;
    } elseif ($acumuladoAno > 360000) {
        $simplesRate = 10.7;
    } elseif ($acumuladoAno > 180000) {
        $simplesRate = 7.3;
    }

    // Lucro Presumido estimation (assume comercio: 8% presuncao, ~15% IRPJ + 9% CSLL + PIS/COFINS ~3.65%)
    $lpPresuncao = 0.08;
    $lpIrpjCsll = 0.24; // 15% IRPJ + 9% CSLL sobre lucro presumido
    $lpPisCofins = 0.0365;
    $lpRate = round(($lpPresuncao * $lpIrpjCsll + $lpPisCofins) * 100, 2); // ~5.57%

    $impostos = [
        'mei' => [
            'regime' => 'MEI',
            'valor_estimado' => $acumuladoAno <= $meiLimiteAnual ? 75.90 : null, // DAS fixo mensal (2024/2025)
            'aliquota' => $acumuladoAno <= $meiLimiteAnual ? 0 : null,
            'nota' => $acumuladoAno <= $meiLimiteAnual
                ? 'MEI paga apenas o DAS fixo mensal (aprox. R$ 75,90). Isento de impostos sobre faturamento.'
                : 'Faturamento excede o limite MEI de R$ 81.000/ano. Necessario migrar de regime.',
            'elegivel' => $acumuladoAno <= $meiLimiteAnual,
            'limite_anual' => $meiLimiteAnual,
            'percentual_utilizado' => $meiPercentualAno,
            'alerta' => $meiAlerta,
        ],
        'simples_nacional' => [
            'regime' => 'Simples Nacional',
            'valor_estimado' => round($faturamentoBruto * $simplesRate / 100, 2),
            'aliquota' => $simplesRate,
            'nota' => "Aliquota estimada de {$simplesRate}% (Anexo I - Comercio). Valor real depende da faixa de receita bruta acumulada em 12 meses.",
        ],
        'lucro_presumido' => [
            'regime' => 'Lucro Presumido',
            'valor_estimado' => round($faturamentoBruto * $lpRate / 100, 2),
            'aliquota' => $lpRate,
            'nota' => "Estimativa: presuncao de 8% sobre receita + 24% (IRPJ+CSLL) + 3,65% PIS/COFINS. Aliquota efetiva ~{$lpRate}%.",
        ],
    ];

    response(true, [
        'periodo' => [
            'tipo' => $tipo,
            'label' => $periodoLabel,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'mes' => $mes,
        ],
        'faturamento_bruto' => $faturamentoBruto,
        'comissoes' => $comissoes,
        'comissao_rate' => $commissionRate,
        'taxas_servico' => $taxasServico,
        'faturamento_liquido' => $faturamentoLiquido,
        'total_pedidos' => $totalPedidos,
        'ticket_medio' => $ticketMedio,
        'por_forma_pagamento' => $porFormaPagamento,
        'por_semana' => $porSemana,
        'impostos_estimados' => $impostos,
        'acumulado_ano' => $acumuladoAno,
        'receita_mensal' => $receitaMensal,
        'mei_limite' => $meiLimiteAnual,
        'mei_percentual' => $meiPercentualAno,
        'mei_alerta' => $meiAlerta,
    ], "Relatorio fiscal gerado");

} catch (Exception $e) {
    error_log("[partner/fiscal] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
