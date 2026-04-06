<?php
/**
 * GET/POST /api/mercado/partner/gestao-negocio.php
 * Business Management / Financial Health dashboard
 *
 * GET (default): Full business dashboard (revenue, costs, profit, health indicators, forecast)
 * GET action=costs: List all active business costs
 * GET action=revenue_external: List external revenue entries
 * POST action=add_cost: Add a new business cost
 * POST action=update_cost: Update existing cost
 * POST action=delete_cost: Soft-delete a cost
 * POST action=add_revenue: Add/update external revenue source
 * POST action=ai_analysis: AI-powered business analysis
 * POST action=simulate: Cost/revenue simulation
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partnerId = (int)$payload['uid'];

    // ── Ensure tables exist ──────────────────────────────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_business_costs (
            id SERIAL PRIMARY KEY,
            partner_id INTEGER NOT NULL,
            category VARCHAR(50) NOT NULL,
            name VARCHAR(255) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            frequency VARCHAR(20) DEFAULT 'mensal',
            due_day INTEGER,
            is_fixed BOOLEAN DEFAULT TRUE,
            notes TEXT,
            active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bc_partner ON om_business_costs(partner_id, active)");

    $db->exec("
        CREATE TABLE IF NOT EXISTS om_business_revenue_external (
            id SERIAL PRIMARY KEY,
            partner_id INTEGER NOT NULL,
            source VARCHAR(100) NOT NULL,
            month VARCHAR(7) NOT NULL,
            revenue DECIMAL(10,2) NOT NULL DEFAULT 0,
            orders_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT NOW(),
            UNIQUE(partner_id, source, month)
        )
    ");

    $method = $_SERVER['REQUEST_METHOD'];
    $action = trim($_GET['action'] ?? '');

    // ======================== GET: DASHBOARD (default) ========================
    if ($method === 'GET' && $action === '') {
        $now = new DateTime();
        $currentMonth = $now->format('Y-m');
        $prevMonth = (clone $now)->modify('-1 month')->format('Y-m');
        $prev2Month = (clone $now)->modify('-2 months')->format('Y-m');

        // ── Fixed costs (monthly equivalent) ──
        $stmtFixed = dbQuery($db, "
            SELECT category, name, amount, frequency, due_day, is_fixed, id
            FROM om_business_costs
            WHERE partner_id = ? AND active = TRUE
            ORDER BY category, name
        ", [$partnerId]);
        $allCosts = $stmtFixed->fetchAll(PDO::FETCH_ASSOC);

        $totalFixedMonthly = 0;
        $totalVariableMonthly = 0;
        $categoryTotals = [];
        $upcomingBills = [];

        foreach ($allCosts as $c) {
            $monthlyAmount = 0;
            switch ($c['frequency']) {
                case 'mensal': $monthlyAmount = (float)$c['amount']; break;
                case 'semanal': $monthlyAmount = (float)$c['amount'] * 4.33; break;
                case 'diario': $monthlyAmount = (float)$c['amount'] * 30; break;
                case 'anual': $monthlyAmount = (float)$c['amount'] / 12; break;
                case 'unico': $monthlyAmount = 0; break; // one-time, don't add to monthly
            }

            if ($c['is_fixed']) {
                $totalFixedMonthly += $monthlyAmount;
            } else {
                $totalVariableMonthly += $monthlyAmount;
            }

            $cat = $c['category'];
            if (!isset($categoryTotals[$cat])) {
                $categoryTotals[$cat] = 0;
            }
            $categoryTotals[$cat] += $monthlyAmount;

            // Upcoming bills in next 30 days
            if ($c['due_day'] && $c['frequency'] === 'mensal') {
                $dueDay = (int)$c['due_day'];
                $today = (int)$now->format('d');
                $daysUntil = $dueDay - $today;
                if ($daysUntil < 0) $daysUntil += 30;
                if ($daysUntil <= 30) {
                    $dueDate = (clone $now);
                    if ($dueDay < $today) {
                        $dueDate->modify('+1 month');
                    }
                    $dueDate->setDate((int)$dueDate->format('Y'), (int)$dueDate->format('m'), min($dueDay, (int)$dueDate->format('t')));
                    $upcomingBills[] = [
                        'name' => $c['name'],
                        'category' => $c['category'],
                        'amount' => (float)$c['amount'],
                        'due_date' => $dueDate->format('Y-m-d'),
                        'days_until' => $daysUntil,
                    ];
                }
            }
        }

        usort($upcomingBills, fn($a, $b) => $a['days_until'] - $b['days_until']);

        $totalCostsMonthly = $totalFixedMonthly + $totalVariableMonthly;

        // ── SuperBora Revenue (current month) ──
        $stmtSB = dbQuery($db, "
            SELECT COALESCE(SUM(total), 0) as revenue, COUNT(*) as orders
            FROM om_market_orders
            WHERE partner_id = ? AND status = 'entregue'
              AND TO_CHAR(date_added, 'YYYY-MM') = ?
        ", [$partnerId, $currentMonth]);
        $sbRow = $stmtSB->fetch(PDO::FETCH_ASSOC);
        $sbRevenue = (float)$sbRow['revenue'];
        $sbOrders = (int)$sbRow['orders'];

        // ── SuperBora Revenue (previous months for comparison) ──
        $stmtSBPrev = dbQuery($db, "
            SELECT TO_CHAR(date_added, 'YYYY-MM') as month,
                   COALESCE(SUM(total), 0) as revenue,
                   COUNT(*) as orders
            FROM om_market_orders
            WHERE partner_id = ? AND status = 'entregue'
              AND date_added >= NOW() - INTERVAL '6 months'
            GROUP BY TO_CHAR(date_added, 'YYYY-MM')
            ORDER BY month DESC
        ", [$partnerId]);
        $sbMonthly = $stmtSBPrev->fetchAll(PDO::FETCH_ASSOC);
        $sbByMonth = [];
        foreach ($sbMonthly as $r) {
            $sbByMonth[$r['month']] = ['revenue' => (float)$r['revenue'], 'orders' => (int)$r['orders']];
        }

        // ── External revenue (current month) ──
        $stmtExt = dbQuery($db, "
            SELECT source, revenue, orders_count
            FROM om_business_revenue_external
            WHERE partner_id = ? AND month = ?
        ", [$partnerId, $currentMonth]);
        $extRows = $stmtExt->fetchAll(PDO::FETCH_ASSOC);
        $totalExternal = 0;
        $externalSources = [];
        foreach ($extRows as $r) {
            $totalExternal += (float)$r['revenue'];
            $externalSources[] = [
                'source' => $r['source'],
                'revenue' => (float)$r['revenue'],
                'orders_count' => (int)$r['orders_count'],
            ];
        }

        // ── External revenue by month (last 6 months) ──
        $stmtExtMonthly = dbQuery($db, "
            SELECT month, SUM(revenue) as revenue, SUM(orders_count) as orders
            FROM om_business_revenue_external
            WHERE partner_id = ? AND month >= ?
            GROUP BY month
            ORDER BY month DESC
        ", [$partnerId, (clone $now)->modify('-6 months')->format('Y-m')]);
        $extByMonth = [];
        foreach ($stmtExtMonthly->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $extByMonth[$r['month']] = ['revenue' => (float)$r['revenue'], 'orders' => (int)$r['orders']];
        }

        $totalRevenue = $sbRevenue + $totalExternal;
        $netProfit = $totalRevenue - $totalCostsMonthly;
        $profitMargin = $totalRevenue > 0 ? round(($netProfit / $totalRevenue) * 100, 1) : 0;

        // ── Health indicators ──
        $totalOrders = $sbOrders;
        foreach ($extRows as $r) $totalOrders += (int)$r['orders_count'];
        $costPerOrder = $totalOrders > 0 ? round($totalCostsMonthly / $totalOrders, 2) : 0;

        // Count employees from costs
        $employeeCosts = array_filter($allCosts, fn($c) => $c['category'] === 'funcionarios');
        $employeeCount = count($employeeCosts);
        $revenuePerEmployee = $employeeCount > 0 ? round($totalRevenue / $employeeCount, 2) : 0;

        // Break-even: orders needed
        $avgOrderValue = $sbOrders > 0 ? $sbRevenue / $sbOrders : 0;
        $breakEvenOrders = $avgOrderValue > 0 ? (int)ceil($totalCostsMonthly / $avgOrderValue) : 0;

        // ── Monthly comparison (last 6 months) ──
        $monthlyComparison = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = (clone $now)->modify("-{$i} months")->format('Y-m');
            $sbRev = $sbByMonth[$m]['revenue'] ?? 0;
            $sbOrd = $sbByMonth[$m]['orders'] ?? 0;
            $extRev = $extByMonth[$m]['revenue'] ?? 0;
            $extOrd = $extByMonth[$m]['orders'] ?? 0;
            $totalRev = $sbRev + $extRev;
            $monthlyComparison[] = [
                'month' => $m,
                'superbora_revenue' => $sbRev,
                'superbora_orders' => $sbOrd,
                'external_revenue' => $extRev,
                'external_orders' => $extOrd,
                'total_revenue' => $totalRev,
                'costs' => $totalCostsMonthly, // assume costs constant for projection
                'profit' => $totalRev - $totalCostsMonthly,
            ];
        }

        // ── Category chart data ──
        $categoryColors = [
            'aluguel' => '#e74c3c',
            'funcionarios' => '#3498db',
            'energia' => '#f39c12',
            'agua' => '#1abc9c',
            'gas' => '#e67e22',
            'internet' => '#9b59b6',
            'contador' => '#2ecc71',
            'impostos' => '#e84393',
            'embalagens' => '#00cec9',
            'marketing' => '#fd79a8',
            'manutencao' => '#636e72',
            'ingredientes' => '#d63031',
            'delivery' => '#0984e3',
            'software' => '#6c5ce7',
            'seguros' => '#ffeaa7',
            'outros' => '#b2bec3',
        ];
        $categoryChart = [];
        foreach ($categoryTotals as $cat => $total) {
            if ($total > 0) {
                $categoryChart[] = [
                    'category' => $cat,
                    'amount' => round($total, 2),
                    'color' => $categoryColors[$cat] ?? '#b2bec3',
                    'percentage' => $totalCostsMonthly > 0 ? round(($total / $totalCostsMonthly) * 100, 1) : 0,
                ];
            }
        }
        usort($categoryChart, fn($a, $b) => $b['amount'] <=> $a['amount']);

        // ── Revenue chart data ──
        $revenueChart = [];
        if ($sbRevenue > 0) {
            $revenueChart[] = [
                'source' => 'SuperBora',
                'revenue' => $sbRevenue,
                'color' => '#F97316',
                'percentage' => $totalRevenue > 0 ? round(($sbRevenue / $totalRevenue) * 100, 1) : 100,
            ];
        }
        foreach ($externalSources as $ext) {
            $sourceColors = [
                'balcao' => '#3498db',
                'ifood' => '#ea1d2c',
                'rappi' => '#ff441f',
                'uber_eats' => '#06c167',
                'telefone' => '#9b59b6',
                'whatsapp' => '#25d366',
                'outros' => '#b2bec3',
            ];
            if ($ext['revenue'] > 0) {
                $revenueChart[] = [
                    'source' => $ext['source'],
                    'revenue' => $ext['revenue'],
                    'color' => $sourceColors[$ext['source']] ?? '#b2bec3',
                    'percentage' => $totalRevenue > 0 ? round(($ext['revenue'] / $totalRevenue) * 100, 1) : 0,
                ];
            }
        }

        // ── Cash flow forecast (next 30 days) ──
        $avgDailyRevenue = $totalRevenue / max((int)$now->format('d'), 1);
        $cashFlow = [];
        for ($d = 1; $d <= 30; $d++) {
            $date = (clone $now)->modify("+{$d} days");
            $dateStr = $date->format('Y-m-d');
            $dayBills = array_filter($upcomingBills, fn($b) => $b['due_date'] === $dateStr);
            $dayExpense = 0;
            $billNames = [];
            foreach ($dayBills as $b) {
                $dayExpense += $b['amount'];
                $billNames[] = $b['name'];
            }
            $cashFlow[] = [
                'date' => $dateStr,
                'projected_revenue' => round($avgDailyRevenue, 2),
                'expenses' => round($dayExpense, 2),
                'bills' => $billNames,
                'net' => round($avgDailyRevenue - $dayExpense, 2),
            ];
        }

        response(true, [
            'summary' => [
                'total_revenue' => round($totalRevenue, 2),
                'superbora_revenue' => round($sbRevenue, 2),
                'superbora_orders' => $sbOrders,
                'external_revenue' => round($totalExternal, 2),
                'total_costs' => round($totalCostsMonthly, 2),
                'fixed_costs' => round($totalFixedMonthly, 2),
                'variable_costs' => round($totalVariableMonthly, 2),
                'net_profit' => round($netProfit, 2),
                'profit_margin' => $profitMargin,
                'current_month' => $currentMonth,
            ],
            'health' => [
                'cost_per_order' => $costPerOrder,
                'revenue_per_employee' => $revenuePerEmployee,
                'employee_count' => $employeeCount,
                'break_even_orders' => $breakEvenOrders,
                'avg_order_value' => round($avgOrderValue, 2),
                'total_orders' => $totalOrders,
            ],
            'revenue_chart' => $revenueChart,
            'cost_chart' => $categoryChart,
            'monthly_comparison' => $monthlyComparison,
            'upcoming_bills' => array_slice($upcomingBills, 0, 15),
            'cash_flow' => $cashFlow,
            'external_sources' => $externalSources,
        ]);
        exit;
    }

    // ======================== GET: COSTS ========================
    if ($method === 'GET' && $action === 'costs') {
        $stmtCosts = dbQuery($db, "
            SELECT id, category, name, amount, frequency, due_day, is_fixed, notes, active, created_at, updated_at
            FROM om_business_costs
            WHERE partner_id = ? AND active = TRUE
            ORDER BY category, name
        ", [$partnerId]);
        $costs = $stmtCosts->fetchAll(PDO::FETCH_ASSOC);

        foreach ($costs as &$c) {
            $c['amount'] = (float)$c['amount'];
            $c['due_day'] = $c['due_day'] ? (int)$c['due_day'] : null;
            $c['is_fixed'] = (bool)$c['is_fixed'];
            $c['active'] = (bool)$c['active'];
        }

        response(true, ['costs' => $costs]);
        exit;
    }

    // ======================== GET: REVENUE_EXTERNAL ========================
    if ($method === 'GET' && $action === 'revenue_external') {
        $month = $_GET['month'] ?? (new DateTime())->format('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            response(false, null, "Formato de mes invalido (YYYY-MM)", 400);
        }

        $stmtRev = dbQuery($db, "
            SELECT id, source, month, revenue, orders_count, created_at
            FROM om_business_revenue_external
            WHERE partner_id = ? AND month = ?
            ORDER BY source
        ", [$partnerId, $month]);
        $revenues = $stmtRev->fetchAll(PDO::FETCH_ASSOC);

        foreach ($revenues as &$r) {
            $r['revenue'] = (float)$r['revenue'];
            $r['orders_count'] = (int)$r['orders_count'];
        }

        // Also get SuperBora revenue for this month
        $stmtSB = dbQuery($db, "
            SELECT COALESCE(SUM(total), 0) as revenue, COUNT(*) as orders
            FROM om_market_orders
            WHERE partner_id = ? AND status = 'entregue'
              AND TO_CHAR(date_added, 'YYYY-MM') = ?
        ", [$partnerId, $month]);
        $sb = $stmtSB->fetch(PDO::FETCH_ASSOC);

        response(true, [
            'month' => $month,
            'revenues' => $revenues,
            'superbora' => [
                'revenue' => (float)$sb['revenue'],
                'orders' => (int)$sb['orders'],
            ],
        ]);
        exit;
    }

    // ======================== POST ACTIONS ========================
    if ($method !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $input = getInput();
    $postAction = $input['action'] ?? $action;

    // ── ADD COST ──
    if ($postAction === 'add_cost') {
        $category = trim($input['category'] ?? '');
        $name = trim($input['name'] ?? '');
        $amount = (float)($input['amount'] ?? 0);
        $frequency = trim($input['frequency'] ?? 'mensal');
        $dueDay = isset($input['due_day']) && $input['due_day'] !== '' ? (int)$input['due_day'] : null;
        $isFixed = isset($input['is_fixed']) ? (bool)$input['is_fixed'] : true;
        $notes = trim($input['notes'] ?? '');

        $validCategories = ['aluguel','funcionarios','energia','agua','gas','internet','contador','impostos','embalagens','marketing','manutencao','ingredientes','delivery','software','seguros','outros'];
        if (!in_array($category, $validCategories)) {
            response(false, null, "Categoria invalida", 400);
        }
        if (empty($name)) {
            response(false, null, "Nome obrigatorio", 400);
        }
        if ($amount <= 0) {
            response(false, null, "Valor deve ser positivo", 400);
        }
        $validFreq = ['mensal','semanal','diario','anual','unico'];
        if (!in_array($frequency, $validFreq)) {
            response(false, null, "Frequencia invalida", 400);
        }
        if ($dueDay !== null && ($dueDay < 1 || $dueDay > 31)) {
            response(false, null, "Dia de vencimento invalido (1-31)", 400);
        }

        $stmtInsert = dbQuery($db, "
            INSERT INTO om_business_costs (partner_id, category, name, amount, frequency, due_day, is_fixed, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ", [$partnerId, $category, $name, $amount, $frequency, $dueDay, $isFixed ? 'true' : 'false', $notes ?: null]);
        $row = $stmtInsert->fetch(PDO::FETCH_ASSOC);

        response(true, ['id' => (int)$row['id']], "Custo adicionado com sucesso");
        exit;
    }

    // ── UPDATE COST ──
    if ($postAction === 'update_cost') {
        $costId = (int)($input['id'] ?? 0);
        if ($costId <= 0) {
            response(false, null, "ID obrigatorio", 400);
        }

        // Verify ownership
        $stmtCheck = dbQuery($db, "
            SELECT id FROM om_business_costs WHERE id = ? AND partner_id = ? AND active = TRUE
        ", [$costId, $partnerId]);
        if (!$stmtCheck->fetch()) {
            response(false, null, "Custo nao encontrado", 404);
        }

        $updates = [];
        $params = [];

        if (isset($input['category'])) {
            $validCategories = ['aluguel','funcionarios','energia','agua','gas','internet','contador','impostos','embalagens','marketing','manutencao','ingredientes','delivery','software','seguros','outros'];
            if (!in_array($input['category'], $validCategories)) {
                response(false, null, "Categoria invalida", 400);
            }
            $updates[] = "category = ?";
            $params[] = $input['category'];
        }
        if (isset($input['name'])) {
            $updates[] = "name = ?";
            $params[] = trim($input['name']);
        }
        if (isset($input['amount'])) {
            $amt = (float)$input['amount'];
            if ($amt <= 0) response(false, null, "Valor deve ser positivo", 400);
            $updates[] = "amount = ?";
            $params[] = $amt;
        }
        if (isset($input['frequency'])) {
            $validFreq = ['mensal','semanal','diario','anual','unico'];
            if (!in_array($input['frequency'], $validFreq)) {
                response(false, null, "Frequencia invalida", 400);
            }
            $updates[] = "frequency = ?";
            $params[] = $input['frequency'];
        }
        if (array_key_exists('due_day', $input)) {
            $dd = $input['due_day'] !== null && $input['due_day'] !== '' ? (int)$input['due_day'] : null;
            if ($dd !== null && ($dd < 1 || $dd > 31)) {
                response(false, null, "Dia de vencimento invalido (1-31)", 400);
            }
            $updates[] = "due_day = ?";
            $params[] = $dd;
        }
        if (isset($input['is_fixed'])) {
            $updates[] = "is_fixed = ?";
            $params[] = (bool)$input['is_fixed'] ? 'true' : 'false';
        }
        if (isset($input['notes'])) {
            $updates[] = "notes = ?";
            $params[] = trim($input['notes']) ?: null;
        }

        if (empty($updates)) {
            response(false, null, "Nenhum campo para atualizar", 400);
        }

        $updates[] = "updated_at = NOW()";
        $params[] = $costId;
        $params[] = $partnerId;

        dbQuery($db, "
            UPDATE om_business_costs SET " . implode(', ', $updates) . "
            WHERE id = ? AND partner_id = ?
        ", $params);

        response(true, ['id' => $costId], "Custo atualizado");
        exit;
    }

    // ── DELETE COST (soft) ──
    if ($postAction === 'delete_cost') {
        $costId = (int)($input['id'] ?? 0);
        if ($costId <= 0) {
            response(false, null, "ID obrigatorio", 400);
        }

        $stmtDel = dbQuery($db, "
            UPDATE om_business_costs SET active = FALSE, updated_at = NOW()
            WHERE id = ? AND partner_id = ? AND active = TRUE
        ", [$costId, $partnerId]);

        if ($stmtDel->rowCount() === 0) {
            response(false, null, "Custo nao encontrado", 404);
        }

        response(true, ['id' => $costId], "Custo removido");
        exit;
    }

    // ── ADD REVENUE ──
    if ($postAction === 'add_revenue') {
        $source = trim($input['source'] ?? '');
        $month = trim($input['month'] ?? '');
        $revenue = (float)($input['revenue'] ?? 0);
        $ordersCount = (int)($input['orders_count'] ?? 0);

        $validSources = ['balcao','ifood','rappi','uber_eats','telefone','whatsapp','outros'];
        if (!in_array($source, $validSources)) {
            response(false, null, "Fonte invalida", 400);
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            response(false, null, "Formato de mes invalido (YYYY-MM)", 400);
        }
        if ($revenue < 0) {
            response(false, null, "Receita nao pode ser negativa", 400);
        }

        dbQuery($db, "
            INSERT INTO om_business_revenue_external (partner_id, source, month, revenue, orders_count)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT (partner_id, source, month)
            DO UPDATE SET revenue = EXCLUDED.revenue, orders_count = EXCLUDED.orders_count
        ", [$partnerId, $source, $month, $revenue, $ordersCount]);

        response(true, [
            'source' => $source,
            'month' => $month,
            'revenue' => $revenue,
            'orders_count' => $ordersCount,
        ], "Receita atualizada");
        exit;
    }

    // ── AI ANALYSIS ──
    if ($postAction === 'ai_analysis') {
        require_once __DIR__ . "/../helpers/claude-client.php";

        // Gather all data for analysis
        $stmtCosts = dbQuery($db, "
            SELECT category, name, amount, frequency, is_fixed
            FROM om_business_costs WHERE partner_id = ? AND active = TRUE
        ", [$partnerId]);
        $costs = $stmtCosts->fetchAll(PDO::FETCH_ASSOC);

        $now = new DateTime();
        $currentMonth = $now->format('Y-m');

        $stmtSB = dbQuery($db, "
            SELECT COALESCE(SUM(total), 0) as revenue, COUNT(*) as orders
            FROM om_market_orders
            WHERE partner_id = ? AND status = 'entregue'
              AND TO_CHAR(date_added, 'YYYY-MM') = ?
        ", [$partnerId, $currentMonth]);
        $sbData = $stmtSB->fetch(PDO::FETCH_ASSOC);

        // Last 3 months SuperBora revenue
        $stmtHist = dbQuery($db, "
            SELECT TO_CHAR(date_added, 'YYYY-MM') as month,
                   COALESCE(SUM(total), 0) as revenue, COUNT(*) as orders
            FROM om_market_orders
            WHERE partner_id = ? AND status = 'entregue'
              AND date_added >= NOW() - INTERVAL '3 months'
            GROUP BY TO_CHAR(date_added, 'YYYY-MM')
            ORDER BY month
        ", [$partnerId]);
        $histRevenue = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

        $stmtExt = dbQuery($db, "
            SELECT source, month, revenue, orders_count
            FROM om_business_revenue_external
            WHERE partner_id = ? AND month >= ?
            ORDER BY month
        ", [$partnerId, (clone $now)->modify('-3 months')->format('Y-m')]);
        $extRevenue = $stmtExt->fetchAll(PDO::FETCH_ASSOC);

        // Get partner category
        $stmtPartner = dbQuery($db, "
            SELECT nome, categoria FROM om_market_partners WHERE partner_id = ?
        ", [$partnerId]);
        $partnerInfo = $stmtPartner->fetch(PDO::FETCH_ASSOC);

        $dataContext = json_encode([
            'partner' => $partnerInfo,
            'costs' => $costs,
            'superbora_current' => $sbData,
            'superbora_history' => $histRevenue,
            'external_revenue' => $extRevenue,
            'current_month' => $currentMonth,
        ], JSON_UNESCAPED_UNICODE);

        $systemPrompt = "Voce e um consultor de negocios especializado em restaurantes e mercados no Brasil. Analise os dados financeiros do parceiro e forneca recomendacoes praticas e acionaveis. Responda em JSON com a seguinte estrutura exata:
{
  \"score\": 0-100 (saude geral do negocio),
  \"score_label\": \"Critico|Atencao|Bom|Excelente\",
  \"reducao_custos\": {\"titulo\": \"...\", \"descricao\": \"...\", \"acoes\": [\"acao1\", \"acao2\", \"acao3\"]},
  \"aumento_receita\": {\"titulo\": \"...\", \"descricao\": \"...\", \"acoes\": [\"acao1\", \"acao2\", \"acao3\"]},
  \"benchmark\": {\"titulo\": \"...\", \"descricao\": \"...\", \"indicadores\": [{\"nome\": \"...\", \"valor_parceiro\": \"...\", \"media_setor\": \"...\", \"status\": \"bom|atencao|critico\"}]},
  \"previsao\": {\"titulo\": \"...\", \"descricao\": \"...\", \"meses\": [{\"mes\": \"YYYY-MM\", \"receita_projetada\": 0, \"custo_projetado\": 0, \"lucro_projetado\": 0}]},
  \"resumo\": \"Breve resumo de 2-3 frases sobre a situacao do negocio\"
}";

        $claude = new ClaudeClient();
        $result = $claude->send($systemPrompt, [
            ['role' => 'user', 'content' => "Analise estes dados financeiros do meu negocio e de recomendacoes praticas:\n\n" . $dataContext]
        ], 4096);

        if (!$result['success']) {
            response(false, null, "Erro na analise IA: " . ($result['error'] ?? 'desconhecido'), 500);
        }

        $text = $result['text'] ?? '';
        // Extract JSON from response
        if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            $analysis = json_decode($matches[0], true);
            if ($analysis) {
                response(true, ['analysis' => $analysis]);
                exit;
            }
        }

        // Fallback: return raw text
        response(true, ['analysis' => ['resumo' => $text, 'score' => 50, 'score_label' => 'Bom']]);
        exit;
    }

    // ── SIMULATE ──
    if ($postAction === 'simulate') {
        $changes = $input['changes'] ?? [];
        if (!is_array($changes) || empty($changes)) {
            response(false, null, "Lista de mudancas obrigatoria", 400);
        }

        // Get current state
        $stmtCosts = dbQuery($db, "
            SELECT id, category, name, amount, frequency, is_fixed
            FROM om_business_costs WHERE partner_id = ? AND active = TRUE
        ", [$partnerId]);
        $currentCosts = $stmtCosts->fetchAll(PDO::FETCH_ASSOC);

        $now = new DateTime();
        $currentMonth = $now->format('Y-m');

        $stmtSB = dbQuery($db, "
            SELECT COALESCE(SUM(total), 0) as revenue, COUNT(*) as orders
            FROM om_market_orders
            WHERE partner_id = ? AND status = 'entregue'
              AND TO_CHAR(date_added, 'YYYY-MM') = ?
        ", [$partnerId, $currentMonth]);
        $sb = $stmtSB->fetch(PDO::FETCH_ASSOC);

        $stmtExt = dbQuery($db, "
            SELECT COALESCE(SUM(revenue), 0) as revenue
            FROM om_business_revenue_external
            WHERE partner_id = ? AND month = ?
        ", [$partnerId, $currentMonth]);
        $ext = $stmtExt->fetch(PDO::FETCH_ASSOC);

        // Calculate current totals
        $currentTotalCost = 0;
        foreach ($currentCosts as $c) {
            $monthly = 0;
            switch ($c['frequency']) {
                case 'mensal': $monthly = (float)$c['amount']; break;
                case 'semanal': $monthly = (float)$c['amount'] * 4.33; break;
                case 'diario': $monthly = (float)$c['amount'] * 30; break;
                case 'anual': $monthly = (float)$c['amount'] / 12; break;
            }
            $currentTotalCost += $monthly;
        }

        $currentRevenue = (float)$sb['revenue'] + (float)$ext['revenue'];
        $currentProfit = $currentRevenue - $currentTotalCost;
        $currentMargin = $currentRevenue > 0 ? round(($currentProfit / $currentRevenue) * 100, 1) : 0;

        // Apply simulated changes
        $simulatedCost = $currentTotalCost;
        $simulatedRevenue = $currentRevenue;
        $descriptions = [];

        foreach ($changes as $change) {
            $type = $change['type'] ?? '';
            $value = (float)($change['value'] ?? 0);
            $desc = $change['description'] ?? '';

            switch ($type) {
                case 'add_cost':
                    $simulatedCost += $value;
                    $descriptions[] = $desc ?: "Novo custo: +R$ " . number_format($value, 2, ',', '.');
                    break;
                case 'remove_cost':
                    $simulatedCost -= $value;
                    $descriptions[] = $desc ?: "Remover custo: -R$ " . number_format($value, 2, ',', '.');
                    break;
                case 'adjust_cost':
                    $simulatedCost += $value; // positive = increase, negative = decrease
                    $descriptions[] = $desc ?: "Ajuste de custo: " . ($value >= 0 ? '+' : '') . "R$ " . number_format($value, 2, ',', '.');
                    break;
                case 'adjust_revenue':
                    $simulatedRevenue += $value;
                    $descriptions[] = $desc ?: "Ajuste de receita: " . ($value >= 0 ? '+' : '') . "R$ " . number_format($value, 2, ',', '.');
                    break;
            }
        }

        $simulatedProfit = $simulatedRevenue - $simulatedCost;
        $simulatedMargin = $simulatedRevenue > 0 ? round(($simulatedProfit / $simulatedRevenue) * 100, 1) : 0;

        response(true, [
            'current' => [
                'revenue' => round($currentRevenue, 2),
                'costs' => round($currentTotalCost, 2),
                'profit' => round($currentProfit, 2),
                'margin' => $currentMargin,
            ],
            'simulated' => [
                'revenue' => round($simulatedRevenue, 2),
                'costs' => round($simulatedCost, 2),
                'profit' => round($simulatedProfit, 2),
                'margin' => $simulatedMargin,
            ],
            'impact' => [
                'revenue_change' => round($simulatedRevenue - $currentRevenue, 2),
                'cost_change' => round($simulatedCost - $currentTotalCost, 2),
                'profit_change' => round($simulatedProfit - $currentProfit, 2),
                'margin_change' => round($simulatedMargin - $currentMargin, 1),
            ],
            'descriptions' => $descriptions,
        ]);
        exit;
    }

    response(false, null, "Acao invalida", 400);

} catch (Exception $e) {
    $code = $e->getCode();
    $httpCode = ($code >= 400 && $code < 600) ? $code : 500;
    response(false, null, $e->getMessage(), $httpCode);
}
