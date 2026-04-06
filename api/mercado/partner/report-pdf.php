<?php
/**
 * GET /api/mercado/partner/report-pdf.php
 * Generates an HTML financial report optimized for printing/saving as PDF.
 *
 * Params:
 *   type=monthly  &month=2026-04   (Monthly report for specified month)
 *   type=weekly                     (Last 7 days summary)
 *
 * Auth: Partner JWT (OmAuth::requirePartner)
 * Returns: text/html with @media print CSS
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

// No CORS headers — this is opened directly in a browser tab for printing
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: strict-origin-when-cross-origin");

function esc($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function fmtBRL($v) {
    return 'R$ ' . number_format((float)$v, 2, ',', '.');
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partnerId = (int)$payload['uid'];

    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        http_response_code(405);
        echo "Metodo nao permitido";
        exit;
    }

    $type = $_GET['type'] ?? 'monthly';
    if (!in_array($type, ['monthly', 'weekly'], true)) {
        $type = 'monthly';
    }

    // ── Get partner info ──
    $stmtPartner = $db->prepare("
        SELECT name, logo, commission_rate
        FROM om_market_partners
        WHERE partner_id = ?
    ");
    $stmtPartner->execute([$partnerId]);
    $partner = $stmtPartner->fetch();

    $partnerName = $partner['name'] ?? 'Parceiro #' . $partnerId;
    $commissionRate = (float)($partner['commission_rate'] ?? 15.0);

    // ── Date range ──
    $now = new DateTime();

    if ($type === 'weekly') {
        $startDate = (clone $now)->modify('-6 days')->format('Y-m-d');
        $endDate = $now->format('Y-m-d');
        $periodLabel = 'Ultimos 7 dias (' . (clone $now)->modify('-6 days')->format('d/m') . ' a ' . $now->format('d/m/Y') . ')';
    } else {
        $monthParam = $_GET['month'] ?? $now->format('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
            $monthParam = $now->format('Y-m');
        }
        $startDate = $monthParam . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));
        $monthNames = [
            1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Marco', 4 => 'Abril',
            5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
            9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
        ];
        $m = (int)date('n', strtotime($startDate));
        $y = date('Y', strtotime($startDate));
        $periodLabel = $monthNames[$m] . ' ' . $y;
    }

    // ── Revenue summary ──
    $stmtSummary = $db->prepare("
        SELECT
            COALESCE(SUM(total), 0) as revenue,
            COUNT(*) as orders,
            COALESCE(AVG(total), 0) as avg_ticket
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status NOT IN ('cancelado')
    ");
    $stmtSummary->execute([$partnerId, $startDate, $endDate]);
    $summary = $stmtSummary->fetch();

    $totalRevenue = round((float)$summary['revenue'], 2);
    $totalOrders = (int)$summary['orders'];
    $avgTicket = round((float)$summary['avg_ticket'], 2);
    $commission = round($totalRevenue * ($commissionRate / 100), 2);
    $netRevenue = round($totalRevenue - $commission, 2);

    // ── Delivered vs cancelled counts ──
    $stmtStatus = $db->prepare("
        SELECT status, COUNT(*) as cnt
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
        GROUP BY status
    ");
    $stmtStatus->execute([$partnerId, $startDate, $endDate]);
    $statusCounts = [];
    while ($row = $stmtStatus->fetch()) {
        $statusCounts[$row['status']] = (int)$row['cnt'];
    }
    $deliveredCount = $statusCounts['entregue'] ?? 0;
    $cancelledCount = $statusCounts['cancelado'] ?? 0;

    // ── Daily breakdown ──
    $stmtDaily = $db->prepare("
        SELECT
            DATE(date_added) as date,
            COALESCE(SUM(total), 0) as total,
            COUNT(*) as orders
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status NOT IN ('cancelado')
        GROUP BY DATE(date_added)
        ORDER BY DATE(date_added) ASC
    ");
    $stmtDaily->execute([$partnerId, $startDate, $endDate]);
    $dailyResults = $stmtDaily->fetchAll();

    $dailyMap = [];
    $maxDailyRevenue = 0;
    foreach ($dailyResults as $row) {
        $val = round((float)$row['total'], 2);
        $dailyMap[$row['date']] = [
            'date' => $row['date'],
            'total' => $val,
            'orders' => (int)$row['orders'],
        ];
        if ($val > $maxDailyRevenue) $maxDailyRevenue = $val;
    }

    $dailyData = [];
    $currentDate = $startDate;
    $today = date('Y-m-d');
    while ($currentDate <= $endDate && $currentDate <= $today) {
        $dailyData[] = $dailyMap[$currentDate] ?? [
            'date' => $currentDate,
            'total' => 0,
            'orders' => 0,
        ];
        $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
    }

    // ── Top products ──
    $stmtTop = $db->prepare("
        SELECT
            oi.name,
            SUM(oi.quantity) as total_qty,
            SUM(oi.price * oi.quantity) as total_revenue,
            COUNT(DISTINCT oi.order_id) as order_count
        FROM om_market_order_items oi
        INNER JOIN om_market_orders o ON o.order_id = oi.order_id
        WHERE o.partner_id = ?
          AND DATE(o.date_added) BETWEEN ? AND ?
          AND o.status NOT IN ('cancelado')
        GROUP BY oi.name
        ORDER BY total_qty DESC
        LIMIT 15
    ");
    $stmtTop->execute([$partnerId, $startDate, $endDate]);
    $topProducts = $stmtTop->fetchAll();

    // ── Payment methods ──
    $stmtPayment = $db->prepare("
        SELECT
            COALESCE(NULLIF(payment_method, ''), 'outros') as method,
            COUNT(*) as cnt,
            COALESCE(SUM(total), 0) as total
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) BETWEEN ? AND ?
          AND status NOT IN ('cancelado')
        GROUP BY COALESCE(NULLIF(payment_method, ''), 'outros')
        ORDER BY total DESC
    ");
    $stmtPayment->execute([$partnerId, $startDate, $endDate]);
    $paymentMethods = $stmtPayment->fetchAll();

    $paymentLabels = [
        'pix' => 'PIX',
        'cartao' => 'Cartao',
        'card' => 'Cartao',
        'dinheiro' => 'Dinheiro',
        'cash' => 'Dinheiro',
        'outros' => 'Outros',
    ];

    // ── Build SVG bar chart for daily revenue ──
    $chartW = 700;
    $chartH = 200;
    $barCount = count($dailyData);
    $barGap = $barCount > 0 ? max(1, floor(($chartW - 40) / $barCount)) : 10;
    $barW = max(3, $barGap - 2);

    $bars = '';
    if ($maxDailyRevenue > 0 && $barCount > 0) {
        foreach ($dailyData as $i => $day) {
            $barH = ($day['total'] / $maxDailyRevenue) * ($chartH - 30);
            $x = 30 + $i * $barGap;
            $y = $chartH - 20 - $barH;
            $dateLabel = date('d/m', strtotime($day['date']));
            $valLabel = number_format($day['total'], 2, ',', '.');
            $bars .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . $barW . '" height="' . round(max(1, $barH), 1) . '" fill="#22c55e" rx="2"><title>' . $dateLabel . ': R$ ' . $valLabel . ' (' . $day['orders'] . ' pedidos)</title></rect>';
        }
    }

    // X-axis labels
    $xLabels = '';
    $labelStep = max(1, (int)ceil($barCount / 7));
    for ($i = 0; $i < $barCount; $i += $labelStep) {
        $x = 30 + $i * $barGap + $barW / 2;
        $xLabels .= '<text x="' . round($x, 1) . '" y="' . ($chartH - 4) . '" font-size="10" fill="#666" text-anchor="middle">' . date('d/m', strtotime($dailyData[$i]['date'])) . '</text>';
    }

    // Y-axis labels
    $yLabels = '';
    for ($i = 0; $i <= 4; $i++) {
        $val = ($maxDailyRevenue / 4) * (4 - $i);
        $y = 10 + $i * (($chartH - 30) / 4);
        $yLabels .= '<text x="25" y="' . round($y + 3, 1) . '" font-size="9" fill="#999" text-anchor="end">R$' . number_format($val, 0, ',', '.') . '</text>';
        $yLabels .= '<line x1="30" y1="' . round($y, 1) . '" x2="' . $chartW . '" y2="' . round($y, 1) . '" stroke="#eee" stroke-width="1"/>';
    }

    // ── Build SVG pie chart for payment methods ──
    $pieR = 70;
    $pieCx = 90;
    $pieCy = 90;
    $pieColors = ['#22c55e', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6', '#6b7280'];
    $piePaths = '';
    $pieLegendHtml = '';
    $totalPaymentAmount = 0;
    foreach ($paymentMethods as $pm) {
        $totalPaymentAmount += (float)$pm['total'];
    }

    if ($totalPaymentAmount > 0) {
        $currentAngle = 0;
        foreach ($paymentMethods as $ci => $pm) {
            $pct = (float)$pm['total'] / $totalPaymentAmount;
            $angle = $pct * 360;
            $endAngle = $currentAngle + $angle;

            $startRad = deg2rad($currentAngle - 90);
            $endRad = deg2rad($endAngle - 90);

            $x1 = $pieCx + $pieR * cos($startRad);
            $y1 = $pieCy + $pieR * sin($startRad);
            $x2 = $pieCx + $pieR * cos($endRad);
            $y2 = $pieCy + $pieR * sin($endRad);

            $largeArc = $angle > 180 ? 1 : 0;
            $color = $pieColors[$ci % count($pieColors)];

            if ($pct >= 0.999) {
                $piePaths .= '<circle cx="' . $pieCx . '" cy="' . $pieCy . '" r="' . $pieR . '" fill="' . $color . '"/>';
            } else {
                $piePaths .= '<path d="M ' . $pieCx . ' ' . $pieCy . ' L ' . round($x1, 2) . ' ' . round($y1, 2) . ' A ' . $pieR . ' ' . $pieR . ' 0 ' . $largeArc . ' 1 ' . round($x2, 2) . ' ' . round($y2, 2) . ' Z" fill="' . $color . '"/>';
            }

            $label = $paymentLabels[strtolower($pm['method'])] ?? ucfirst($pm['method']);
            $pieLegendHtml .= '<div style="display:flex;align-items:center;gap:6px;margin:4px 0"><span style="display:inline-block;width:12px;height:12px;border-radius:2px;background:' . $color . '"></span>' . esc($label) . ': R$ ' . number_format((float)$pm['total'], 2, ',', '.') . ' (' . number_format($pct * 100, 1, ',', '.') . '%)</div>';

            $currentAngle = $endAngle;
        }
    }

    $generatedAt = date('d/m/Y H:i');

    // ══════════════════════════════════════════════════════════════════════════
    // OUTPUT HTML
    // ══════════════════════════════════════════════════════════════════════════
    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Relatorio Financeiro - <?= esc($periodLabel) ?> - SuperBora</title>
<style>
:root {
  --green: #22c55e; --green-dark: #16a34a;
  --bg: #f8fafc; --card: #ffffff; --border: #e2e8f0;
  --text: #1e293b; --muted: #64748b; --light: #94a3b8;
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  background: var(--bg); color: var(--text); font-size: 14px; line-height: 1.5; padding: 20px;
}
.container { max-width: 800px; margin: 0 auto; }
.header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 24px; background: var(--card); border-radius: 12px;
  border: 1px solid var(--border); margin-bottom: 20px;
}
.header-left { display: flex; align-items: center; gap: 16px; }
.logo {
  width: 48px; height: 48px; background: var(--green); border-radius: 10px;
  display: flex; align-items: center; justify-content: center; color: white;
  font-weight: 700; font-size: 18px;
}
.header h1 { font-size: 20px; font-weight: 700; }
.header .period { color: var(--muted); font-size: 13px; margin-top: 2px; }
.summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }
.summary-card {
  background: var(--card); border-radius: 10px; padding: 16px;
  border: 1px solid var(--border);
}
.summary-card .label { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; }
.summary-card .value { font-size: 22px; font-weight: 700; margin-top: 4px; }
.summary-card .sub { font-size: 12px; color: var(--light); margin-top: 2px; }
.summary-card.green .value { color: var(--green-dark); }
.section {
  background: var(--card); border-radius: 10px; padding: 20px;
  border: 1px solid var(--border); margin-bottom: 20px;
}
.section h2 {
  font-size: 16px; font-weight: 600; margin-bottom: 16px;
  padding-bottom: 8px; border-bottom: 1px solid var(--border);
}
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th {
  text-align: left; padding: 8px 10px; color: var(--muted); font-weight: 600;
  font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;
  border-bottom: 2px solid var(--border);
}
td { padding: 8px 10px; border-bottom: 1px solid var(--border); }
tr:last-child td { border-bottom: none; }
.text-right { text-align: right; }
.text-center { text-align: center; }
.font-mono { font-family: 'SF Mono', Monaco, monospace; font-size: 12px; }
.charts-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
.pie-layout { display: flex; align-items: center; gap: 20px; }
.pie-legend { font-size: 12px; }
.footer {
  text-align: center; padding: 16px; color: var(--light); font-size: 11px;
  border-top: 1px solid var(--border); margin-top: 20px;
}
@media print {
  body { background: white; padding: 0; font-size: 12px; }
  .container { max-width: 100%; }
  .header, .summary-card, .section { border: 1px solid #ddd; }
  .section { break-inside: avoid; }
  .summary-grid { grid-template-columns: repeat(4, 1fr); }
  .charts-row { grid-template-columns: 1fr 1fr; break-inside: avoid; }
  .no-print { display: none !important; }
  @page { margin: 1.5cm; size: A4; }
}
@media (max-width: 600px) {
  .summary-grid { grid-template-columns: repeat(2, 1fr); }
  .charts-row { grid-template-columns: 1fr; }
  .header { flex-direction: column; gap: 12px; text-align: center; }
}
</style>
</head>
<body>
<div class="container">

  <div class="no-print" style="text-align:right;margin-bottom:12px">
    <button onclick="window.print()" style="background:var(--green);color:white;border:none;padding:10px 20px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer">
      Imprimir / Salvar PDF
    </button>
  </div>

  <!-- Header -->
  <div class="header">
    <div class="header-left">
      <div class="logo">SB</div>
      <div>
        <h1>Relatorio Financeiro</h1>
        <div class="period"><?= esc($periodLabel) ?></div>
      </div>
    </div>
    <div style="color:var(--muted);font-size:15px"><?= esc($partnerName) ?></div>
  </div>

  <!-- Summary Cards -->
  <div class="summary-grid">
    <div class="summary-card">
      <div class="label">Faturamento</div>
      <div class="value"><?= fmtBRL($totalRevenue) ?></div>
      <div class="sub"><?= $totalOrders ?> pedidos</div>
    </div>
    <div class="summary-card">
      <div class="label">Comissao (<?= $commissionRate ?>%)</div>
      <div class="value" style="color:var(--muted)"><?= fmtBRL($commission) ?></div>
      <div class="sub">Taxa SuperBora</div>
    </div>
    <div class="summary-card green">
      <div class="label">Liquido</div>
      <div class="value"><?= fmtBRL($netRevenue) ?></div>
      <div class="sub">Valor a receber</div>
    </div>
    <div class="summary-card">
      <div class="label">Ticket Medio</div>
      <div class="value"><?= fmtBRL($avgTicket) ?></div>
      <div class="sub"><?= $deliveredCount ?> entregues / <?= $cancelledCount ?> cancelados</div>
    </div>
  </div>

  <!-- Charts -->
  <div class="charts-row">
    <div class="section" style="margin-bottom:0">
      <h2>Faturamento Diario</h2>
      <svg viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" xmlns="http://www.w3.org/2000/svg">
        <?= $yLabels ?>
        <?= $bars ?>
        <?= $xLabels ?>
      </svg>
    </div>
    <div class="section" style="margin-bottom:0">
      <h2>Metodos de Pagamento</h2>
      <?php if ($totalPaymentAmount > 0): ?>
      <div class="pie-layout">
        <svg width="180" height="180" viewBox="0 0 180 180" xmlns="http://www.w3.org/2000/svg">
          <?= $piePaths ?>
        </svg>
        <div class="pie-legend"><?= $pieLegendHtml ?></div>
      </div>
      <?php else: ?>
      <p style="color:var(--light);text-align:center;padding:20px">Nenhum pedido no periodo</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- Daily Breakdown Table -->
  <div class="section">
    <h2>Detalhamento Diario</h2>
    <table>
      <thead>
        <tr>
          <th>Data</th>
          <th class="text-center">Pedidos</th>
          <th class="text-right">Faturamento</th>
          <th class="text-right">Comissao</th>
          <th class="text-right">Liquido</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($dailyData as $day): ?>
        <?php
          $dayComm = round($day['total'] * ($commissionRate / 100), 2);
          $dayNet = round($day['total'] - $dayComm, 2);
        ?>
        <tr>
          <td><?= date('d/m/Y', strtotime($day['date'])) ?></td>
          <td class="text-center"><?= $day['orders'] ?></td>
          <td class="text-right font-mono"><?= fmtBRL($day['total']) ?></td>
          <td class="text-right font-mono" style="color:var(--muted)"><?= fmtBRL($dayComm) ?></td>
          <td class="text-right font-mono" style="color:var(--green-dark)"><?= fmtBRL($dayNet) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="font-weight:700;border-top:2px solid var(--border)">
          <td>TOTAL</td>
          <td class="text-center"><?= $totalOrders ?></td>
          <td class="text-right font-mono"><?= fmtBRL($totalRevenue) ?></td>
          <td class="text-right font-mono" style="color:var(--muted)"><?= fmtBRL($commission) ?></td>
          <td class="text-right font-mono" style="color:var(--green-dark)"><?= fmtBRL($netRevenue) ?></td>
        </tr>
      </tbody>
    </table>
  </div>

  <!-- Top Products -->
  <div class="section">
    <h2>Top Produtos</h2>
    <?php if (count($topProducts) > 0): ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Produto</th>
          <th class="text-center">Qtd Vendida</th>
          <th class="text-center">Pedidos</th>
          <th class="text-right">Faturamento</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($topProducts as $idx => $prod): ?>
        <tr>
          <td style="color:var(--light)"><?= $idx + 1 ?></td>
          <td><?= esc($prod['name']) ?></td>
          <td class="text-center"><?= (int)$prod['total_qty'] ?></td>
          <td class="text-center"><?= (int)$prod['order_count'] ?></td>
          <td class="text-right font-mono"><?= fmtBRL($prod['total_revenue']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <p style="color:var(--light);text-align:center;padding:20px">Nenhum produto vendido no periodo</p>
    <?php endif; ?>
  </div>

  <!-- Payment Methods Table -->
  <div class="section">
    <h2>Pagamentos por Metodo</h2>
    <?php if (count($paymentMethods) > 0): ?>
    <table>
      <thead>
        <tr>
          <th>Metodo</th>
          <th class="text-center">Pedidos</th>
          <th class="text-right">Valor Total</th>
          <th class="text-right">% do Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($paymentMethods as $pm): ?>
        <?php
          $pmLabel = $paymentLabels[strtolower($pm['method'])] ?? ucfirst($pm['method']);
          $pmPct = $totalPaymentAmount > 0 ? ((float)$pm['total'] / $totalPaymentAmount) * 100 : 0;
        ?>
        <tr>
          <td><?= esc($pmLabel) ?></td>
          <td class="text-center"><?= (int)$pm['cnt'] ?></td>
          <td class="text-right font-mono"><?= fmtBRL($pm['total']) ?></td>
          <td class="text-right"><?= number_format($pmPct, 1, ',', '.') ?>%</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <p style="color:var(--light);text-align:center;padding:20px">Nenhum pagamento no periodo</p>
    <?php endif; ?>
  </div>

  <!-- Footer -->
  <div class="footer">
    <p>Relatorio gerado em <?= $generatedAt ?> &bull; SuperBora &copy; <?= date('Y') ?></p>
    <p style="margin-top:4px">Este documento e de uso interno e confidencial do parceiro.</p>
  </div>

</div>
</body>
</html>
<?php
} catch (Exception $e) {
    error_log("[partner/report-pdf] Erro: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body><h1>Erro</h1><p>Erro interno ao gerar relatorio. Tente novamente.</p></body></html>';
    exit;
}
