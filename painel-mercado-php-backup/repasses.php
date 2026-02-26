<?php
/**
 * PAINEL DO MERCADO - Repasses & Saques PIX (Woovi)
 */
session_start();

if (!isset($_SESSION['mercado_id'])) {
    header('Location: login.php');
    exit;
}

require_once dirname(__DIR__, 2) . '/database.php';
$db = getDB();

$mercado_id = (int)$_SESSION['mercado_id'];
$mercado_nome = $_SESSION['mercado_nome'] ?? 'Mercado';

// Saldo real (fonte de verdade)
$stmtSaldo = $db->prepare("
    SELECT
        COALESCE(saldo_disponivel, 0) as saldo_disponivel,
        COALESCE(saldo_pendente, 0) as saldo_pendente,
        COALESCE(saldo_devedor, 0) as saldo_devedor,
        COALESCE(total_recebido, 0) as total_recebido,
        COALESCE(total_sacado, 0) as total_sacado
    FROM om_mercado_saldo WHERE partner_id = ?
");
$stmtSaldo->execute([$mercado_id]);
$saldo = $stmtSaldo->fetch();
if (!$saldo) {
    $saldo = ['saldo_disponivel'=>0,'saldo_pendente'=>0,'saldo_devedor'=>0,'total_recebido'=>0,'total_sacado'=>0];
}

// Config PIX
$stmtConfig = $db->prepare("
    SELECT pix_key, pix_key_type, pix_key_validated, auto_payout, payout_frequency, payout_day, min_payout
    FROM om_payout_config WHERE partner_id = ?
");
$stmtConfig->execute([$mercado_id]);
$pixConfig = $stmtConfig->fetch();

// Saques Woovi
$stmtPayouts = $db->prepare("
    SELECT id, amount, pix_key, pix_key_type, status, type, failure_reason, created_at, processed_at
    FROM om_woovi_payouts WHERE partner_id = ? ORDER BY created_at DESC LIMIT 30
");
$stmtPayouts->execute([$mercado_id]);
$payouts = $stmtPayouts->fetchAll();

// Repasses (creditos por pedido) — coluna correta: destinatario_id, taxa_plataforma
$stmtRepasses = $db->prepare("
    SELECT id, order_id, valor_bruto, taxa_plataforma, valor_liquido, status, created_at, liberado_em
    FROM om_repasses
    WHERE destinatario_id = ? AND tipo = 'mercado'
    ORDER BY created_at DESC LIMIT 30
");
$stmtRepasses->execute([$mercado_id]);
$repasses = $stmtRepasses->fetchAll();

// Payout em andamento?
$stmtPendPayout = $db->prepare("
    SELECT id, amount, status, created_at FROM om_woovi_payouts
    WHERE partner_id = ? AND status IN ('pending','processing')
    ORDER BY created_at DESC LIMIT 1
");
$stmtPendPayout->execute([$mercado_id]);
$pendingPayout = $stmtPendPayout->fetch();

// Pode sacar?
$canWithdraw = !$pendingPayout
    && (float)$saldo['saldo_disponivel'] >= 10
    && $pixConfig && !empty($pixConfig['pix_key']) && $pixConfig['pix_key_validated']
    && (float)$saldo['saldo_devedor'] <= 0;

$statusColors = [
    'pending'=>'#f59e0b','processing'=>'#3b82f6','completed'=>'#10b981',
    'failed'=>'#ef4444','refunded'=>'#8b5cf6','hold'=>'#f59e0b','liberado'=>'#10b981','pago'=>'#10b981',
];
$statusLabels = [
    'pending'=>'Pendente','processing'=>'Processando','completed'=>'Pago',
    'failed'=>'Falhou','refunded'=>'Estornado','hold'=>'Retido (2h)','liberado'=>'Disponivel','pago'=>'Pago',
];

$freqLabels = ['daily'=>'Diario','weekly'=>'Semanal','biweekly'=>'Quinzenal','monthly'=>'Mensal'];
$dayLabels = ['','Segunda','Terca','Quarta','Quinta','Sexta','Sabado','Domingo'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Repasses & Saques - <?= htmlspecialchars($mercado_nome) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lucide-static@latest/font/lucide.min.css">
    <link rel="stylesheet" href="/frontend/src/styles/design-system.css">
    <link rel="stylesheet" href="/frontend/src/styles/components.css">
</head>
<body class="om-app-layout">
    <aside class="om-sidebar" id="sidebar">
        <div class="om-sidebar-header">
            <img src="/assets/img/logo-onemundo-white.png" alt="OneMundo" class="om-sidebar-logo"
                 onerror="this.outerHTML='<span class=\'om-sidebar-logo-text\'>SuperBora</span>'">
        </div>
        <nav class="om-sidebar-nav">
            <a href="index.php" class="om-sidebar-link"><i class="lucide-layout-dashboard"></i><span>Dashboard</span></a>
            <a href="pedidos.php" class="om-sidebar-link"><i class="lucide-shopping-bag"></i><span>Pedidos</span></a>
            <a href="produtos.php" class="om-sidebar-link"><i class="lucide-package"></i><span>Produtos</span></a>
                <a href="cardapio-ia.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a4 4 0 0 1 4 4c0 1.1-.9 2-2 2h-4a2 2 0 0 1-2-2 4 4 0 0 1 4-4z"></path><path d="M8.5 8a6.5 6.5 0 1 0 7 0"></path><path d="M12 18v4"></path><path d="M8 22h8"></path></svg>
                    <span class="om-sidebar-link-text">Cardapio IA</span>
                    <span style="background:#8b5cf6;color:#fff;font-size:9px;padding:2px 6px;border-radius:10px;font-weight:700;">NOVO</span>
                </a>
            <a href="categorias.php" class="om-sidebar-link"><i class="lucide-tags"></i><span>Categorias</span></a>
            <a href="faturamento.php" class="om-sidebar-link"><i class="lucide-bar-chart-3"></i><span>Faturamento</span></a>
            <a href="repasses.php" class="om-sidebar-link active"><i class="lucide-wallet"></i><span>Repasses</span></a>
            <a href="antecipacao.php" class="om-sidebar-link"><i class="lucide-zap"></i><span>Antecipacao</span></a>
            <a href="avaliacoes.php" class="om-sidebar-link"><i class="lucide-star"></i><span>Avaliacoes</span></a>
            <a href="horarios.php" class="om-sidebar-link"><i class="lucide-clock"></i><span>Horarios</span></a>
            <a href="perfil.php" class="om-sidebar-link"><i class="lucide-settings"></i><span>Configuracoes</span></a>
        </nav>
        <div class="om-sidebar-footer">
            <a href="logout.php" class="om-sidebar-link"><i class="lucide-log-out"></i><span>Sair</span></a>
        </div>
    </aside>

    <main class="om-main-content">
        <header class="om-topbar">
            <button class="om-sidebar-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
                <i class="lucide-menu"></i>
            </button>
            <h1 class="om-topbar-title">Repasses & Saques</h1>
            <div class="om-topbar-actions">
                <div class="om-user-menu">
                    <span class="om-user-name"><?= htmlspecialchars($mercado_nome) ?></span>
                    <div class="om-avatar om-avatar-sm"><?= strtoupper(substr($mercado_nome, 0, 2)) ?></div>
                </div>
            </div>
        </header>

        <div class="om-page-content">

            <!-- ===== WALLET CARDS ===== -->
            <div class="wg">
                <div class="wc wc-main">
                    <div class="wc-top">
                        <div class="wc-icon-wrap"><i class="lucide-wallet"></i></div>
                        <div>
                            <div class="wc-label">Saldo Disponivel</div>
                            <div class="wc-value">R$ <?= number_format(max(0, $saldo['saldo_disponivel']), 2, ',', '.') ?></div>
                        </div>
                    </div>
                    <?php if ($canWithdraw): ?>
                    <button class="btn-sacar" onclick="openModal()">
                        <i class="lucide-arrow-up-right"></i> Sacar via PIX
                    </button>
                    <?php elseif (!$pixConfig || empty($pixConfig['pix_key'])): ?>
                    <a href="perfil.php" class="btn-config-pix">Configurar PIX</a>
                    <?php endif; ?>
                </div>

                <div class="wc">
                    <div class="wc-icon-wrap wci-yellow"><i class="lucide-clock"></i></div>
                    <div>
                        <div class="wc-label">Pendente</div>
                        <div class="wc-value-sm">R$ <?= number_format($saldo['saldo_pendente'], 2, ',', '.') ?></div>
                        <div class="wc-hint">Liberado apos 2h</div>
                    </div>
                </div>

                <div class="wc">
                    <div class="wc-icon-wrap wci-green"><i class="lucide-trending-up"></i></div>
                    <div>
                        <div class="wc-label">Total Recebido</div>
                        <div class="wc-value-sm">R$ <?= number_format($saldo['total_recebido'], 2, ',', '.') ?></div>
                    </div>
                </div>

                <div class="wc">
                    <div class="wc-icon-wrap wci-blue"><i class="lucide-banknote"></i></div>
                    <div>
                        <div class="wc-label">Total Sacado</div>
                        <div class="wc-value-sm">R$ <?= number_format($saldo['total_sacado'], 2, ',', '.') ?></div>
                    </div>
                </div>
            </div>

            <!-- Alertas -->
            <?php if ($saldo['saldo_devedor'] > 0): ?>
            <div class="alert alert-red">
                <i class="lucide-alert-triangle"></i>
                <div>
                    <strong>Comissao pendente</strong><br>
                    R$ <?= number_format($saldo['saldo_devedor'], 2, ',', '.') ?> pendente. Saques bloqueados ate quitacao.
                </div>
            </div>
            <?php endif; ?>

            <?php if ($pendingPayout): ?>
            <div class="alert alert-blue">
                <i class="lucide-loader spin"></i>
                <div>
                    <strong>Saque em andamento</strong><br>
                    R$ <?= number_format($pendingPayout['amount'], 2, ',', '.') ?> — <?= $statusLabels[$pendingPayout['status']] ?? $pendingPayout['status'] ?>
                    (<?= date('d/m H:i', strtotime($pendingPayout['created_at'])) ?>)
                </div>
            </div>
            <?php endif; ?>

            <!-- PIX Status -->
            <div class="pix-bar">
                <?php if ($pixConfig && !empty($pixConfig['pix_key']) && $pixConfig['pix_key_validated']): ?>
                    <span class="pix-tag pix-ok"><i class="lucide-check-circle"></i> PIX: <?= htmlspecialchars(substr($pixConfig['pix_key'], 0, 8)) ?>**** (<?= strtoupper($pixConfig['pix_key_type']) ?>)</span>
                    <?php if ($pixConfig['auto_payout']): ?>
                    <span class="pix-tag pix-auto"><i class="lucide-repeat"></i> Auto: <?= $freqLabels[$pixConfig['payout_frequency']] ?? $pixConfig['payout_frequency'] ?>
                        <?php if (in_array($pixConfig['payout_frequency'], ['weekly','biweekly']) && isset($dayLabels[(int)$pixConfig['payout_day']])): ?>
                            (<?= $dayLabels[(int)$pixConfig['payout_day']] ?>)
                        <?php endif; ?>
                    </span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="pix-tag pix-warn"><i class="lucide-alert-circle"></i> PIX nao configurado</span>
                    <a href="perfil.php" class="pix-link">Configurar agora</a>
                <?php endif; ?>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" data-tab="saques">Saques PIX</button>
                <button class="tab" data-tab="repasses">Creditos por Pedido</button>
            </div>

            <!-- Tab: Saques -->
            <div class="om-card tab-panel" id="panel-saques">
                <div class="om-table-responsive">
                    <table class="om-table">
                        <thead><tr><th>#</th><th>Tipo</th><th class="r">Valor</th><th>Chave PIX</th><th>Status</th><th>Data</th></tr></thead>
                        <tbody>
                        <?php if (empty($payouts)): ?>
                            <tr><td colspan="6" class="empty"><i class="lucide-banknote"></i><p>Nenhum saque realizado</p><small>Clique em "Sacar via PIX" para comecar</small></td></tr>
                        <?php else: foreach ($payouts as $p): ?>
                            <tr>
                                <td class="mono">#<?= $p['id'] ?></td>
                                <td><span class="badge <?= $p['type']==='auto'?'badge-blue':'badge-gray' ?>"><?= $p['type']==='auto'?'Auto':'Manual' ?></span></td>
                                <td class="r bold">R$ <?= number_format($p['amount'],2,',','.') ?></td>
                                <td class="muted"><?= htmlspecialchars(substr($p['pix_key'],0,8)) ?>****</td>
                                <td>
                                    <span class="dot" style="background:<?= $statusColors[$p['status']] ?? '#9ca3af' ?>"></span>
                                    <?= $statusLabels[$p['status']] ?? $p['status'] ?>
                                    <?php if ($p['failure_reason']): ?><br><small class="muted"><?= htmlspecialchars(mb_substr($p['failure_reason'],0,50)) ?></small><?php endif; ?>
                                </td>
                                <td>
                                    <?= date('d/m/Y H:i', strtotime($p['created_at'])) ?>
                                    <?php if ($p['processed_at']): ?><br><small class="muted"><?= date('d/m H:i', strtotime($p['processed_at'])) ?></small><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tab: Repasses (creditos) -->
            <div class="om-card tab-panel" id="panel-repasses" style="display:none">
                <div class="om-table-responsive">
                    <table class="om-table">
                        <thead><tr><th>#</th><th>Pedido</th><th class="r">Bruto</th><th class="r">Taxa</th><th class="r">Liquido</th><th>Status</th><th>Data</th></tr></thead>
                        <tbody>
                        <?php if (empty($repasses)): ?>
                            <tr><td colspan="7" class="empty"><i class="lucide-wallet"></i><p>Nenhum repasse ainda</p></td></tr>
                        <?php else: foreach ($repasses as $r): ?>
                            <tr>
                                <td class="mono">#<?= $r['id'] ?></td>
                                <td class="mono">#<?= $r['order_id'] ?></td>
                                <td class="r">R$ <?= number_format($r['valor_bruto'],2,',','.') ?></td>
                                <td class="r muted">-R$ <?= number_format($r['taxa_plataforma'],2,',','.') ?></td>
                                <td class="r bold">R$ <?= number_format($r['valor_liquido'],2,',','.') ?></td>
                                <td>
                                    <span class="dot" style="background:<?= $statusColors[$r['status']] ?? '#9ca3af' ?>"></span>
                                    <?= $statusLabels[$r['status']] ?? $r['status'] ?>
                                </td>
                                <td>
                                    <?= date('d/m/Y H:i', strtotime($r['created_at'])) ?>
                                    <?php if (!empty($r['liberado_em'])): ?><br><small class="muted">Lib: <?= date('d/m H:i', strtotime($r['liberado_em'])) ?></small><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Info -->
            <div class="alert alert-info" style="margin-top:24px">
                <i class="lucide-info"></i>
                <div>
                    <strong>Como funciona</strong><br>
                    Apos cada entrega confirmada, seu valor fica retido por 2 horas (seguranca). Depois, cai no saldo disponivel.
                    Voce pode sacar a qualquer momento via PIX ou configurar repasse automatico semanal. O PIX cai em segundos.
                </div>
            </div>
        </div>
    </main>

    <!-- Modal Saque -->
    <div class="modal-bg" id="modal" onclick="if(event.target===this)closeModal()">
        <div class="modal">
            <div class="modal-head">
                <h3><i class="lucide-arrow-up-right"></i> Sacar via PIX</h3>
                <button onclick="closeModal()" class="modal-x">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-balance">
                    <span>Saldo disponivel</span>
                    <strong>R$ <?= number_format(max(0,$saldo['saldo_disponivel']),2,',','.') ?></strong>
                </div>
                <div class="modal-pix-info">
                    <i class="lucide-key"></i>
                    <?= htmlspecialchars(substr($pixConfig['pix_key'] ?? '',0,8)) ?>**** (<?= strtoupper($pixConfig['pix_key_type'] ?? '') ?>)
                </div>
                <label class="modal-label">Valor do saque</label>
                <div class="modal-input-wrap">
                    <span class="modal-prefix">R$</span>
                    <input type="number" id="inp-valor" min="10" max="<?= max(0,$saldo['saldo_disponivel']) ?>" step="0.01"
                           value="<?= number_format(max(0,$saldo['saldo_disponivel']),2,'.','') ?>">
                </div>
                <small class="modal-hint">Minimo R$ 10,00 &middot; O PIX chega em segundos</small>
                <div id="msg-err" class="modal-msg modal-msg-err" style="display:none"></div>
                <div id="msg-ok" class="modal-msg modal-msg-ok" style="display:none"></div>
            </div>
            <div class="modal-foot">
                <button onclick="closeModal()" class="mbtn mbtn-cancel">Cancelar</button>
                <button id="btn-confirm" onclick="doSaque()" class="mbtn mbtn-go">
                    <i class="lucide-arrow-up-right"></i> Confirmar Saque
                </button>
            </div>
        </div>
    </div>

    <style>
    *{box-sizing:border-box}

    /* Wallet Grid */
    .wg{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:16px;margin-bottom:20px}
    .wc{background:#fff;border-radius:16px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.06);display:flex;align-items:center;gap:14px}
    .wc-main{grid-column:1/-1;background:linear-gradient(135deg,#10b981,#059669);color:#fff;flex-wrap:wrap;justify-content:space-between}
    .wc-top{display:flex;align-items:center;gap:14px}
    .wc-icon-wrap{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.2);font-size:22px}
    .wci-yellow{background:#fef3c7;color:#f59e0b}.wci-green{background:#d1fae5;color:#10b981}.wci-blue{background:#dbeafe;color:#3b82f6}
    .wc-label{font-size:13px;opacity:.85;font-weight:500}
    .wc-value{font-size:28px;font-weight:800;letter-spacing:-.5px}
    .wc-value-sm{font-size:20px;font-weight:700}
    .wc-hint{font-size:11px;opacity:.65;margin-top:2px}
    .btn-sacar{background:rgba(255,255,255,.2);border:1.5px solid rgba(255,255,255,.4);color:#fff;border-radius:12px;padding:10px 22px;font-weight:700;font-size:14px;cursor:pointer;display:flex;align-items:center;gap:6px;transition:.2s}
    .btn-sacar:hover{background:rgba(255,255,255,.35);transform:translateY(-1px)}
    .btn-config-pix{background:rgba(255,255,255,.2);border:1.5px solid rgba(255,255,255,.4);color:#fff;border-radius:12px;padding:10px 22px;font-weight:600;font-size:13px;text-decoration:none;display:flex;align-items:center;gap:6px}

    /* Alerts */
    .alert{display:flex;align-items:flex-start;gap:12px;padding:14px 18px;border-radius:12px;font-size:14px;margin-bottom:16px;line-height:1.5}
    .alert i{flex-shrink:0;margin-top:2px}
    .alert-red{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
    .alert-blue{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
    .alert-info{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
    .spin{animation:spin 1s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}

    /* PIX bar */
    .pix-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:20px}
    .pix-tag{display:flex;align-items:center;gap:6px;padding:7px 14px;border-radius:10px;font-size:13px;font-weight:500}
    .pix-ok{background:#d1fae5;color:#065f46}.pix-warn{background:#fef3c7;color:#92400e}.pix-auto{background:#dbeafe;color:#1e40af}
    .pix-link{color:#3b82f6;font-size:13px;font-weight:600;text-decoration:none}

    /* Tabs */
    .tabs{display:flex;gap:0;border-bottom:2px solid #e5e7eb;margin-bottom:0}
    .tab{padding:12px 24px;border:none;background:none;cursor:pointer;font-size:14px;font-weight:500;color:#9ca3af;border-bottom:2px solid transparent;margin-bottom:-2px;transition:.15s}
    .tab.active{color:#10b981;border-bottom-color:#10b981}
    .tab:hover{color:#374151}

    /* Table helpers */
    .r{text-align:right}.bold{font-weight:600}.mono{font-family:monospace;font-size:13px}.muted{color:#9ca3af}
    .dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:5px}
    .badge{font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px}
    .badge-blue{background:#dbeafe;color:#1e40af}.badge-gray{background:#f3f4f6;color:#374151}
    .empty{text-align:center;padding:48px 16px!important;color:#9ca3af}
    .empty i{font-size:40px;display:block;margin-bottom:12px}
    .empty p{font-size:15px;margin:4px 0}

    /* Modal */
    .modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:1000;backdrop-filter:blur(4px)}
    .modal-bg.open{display:flex}
    .modal{background:#fff;border-radius:20px;width:440px;max-width:92vw;box-shadow:0 24px 80px rgba(0,0,0,.2);animation:modalIn .2s ease-out}
    @keyframes modalIn{from{opacity:0;transform:scale(.95)translateY(10px)}to{opacity:1;transform:none}}
    .modal-head{display:flex;justify-content:space-between;align-items:center;padding:20px 24px;border-bottom:1px solid #f1f5f9}
    .modal-head h3{font-size:17px;font-weight:600;margin:0;display:flex;align-items:center;gap:8px}
    .modal-x{background:none;border:none;font-size:28px;cursor:pointer;color:#9ca3af;line-height:1;padding:0 4px}
    .modal-body{padding:24px}
    .modal-balance{display:flex;justify-content:space-between;align-items:center;background:#f0fdf4;padding:14px 18px;border-radius:12px;margin-bottom:16px}
    .modal-balance span{color:#065f46;font-size:14px}.modal-balance strong{color:#059669;font-size:20px}
    .modal-pix-info{display:flex;align-items:center;gap:8px;font-size:13px;color:#6b7280;margin-bottom:20px;padding:0 4px}
    .modal-label{font-size:13px;font-weight:600;color:#374151;display:block;margin-bottom:6px}
    .modal-input-wrap{display:flex;align-items:center;border:2px solid #e5e7eb;border-radius:12px;overflow:hidden;transition:.2s}
    .modal-input-wrap:focus-within{border-color:#10b981;box-shadow:0 0 0 3px rgba(16,185,129,.15)}
    .modal-prefix{padding:0 4px 0 16px;font-size:16px;font-weight:600;color:#6b7280}
    .modal-input-wrap input{border:none;outline:none;flex:1;padding:14px 16px 14px 4px;font-size:24px;font-weight:700;font-family:inherit}
    .modal-hint{display:block;color:#9ca3af;font-size:12px;margin-top:8px;padding:0 4px}
    .modal-msg{padding:10px 14px;border-radius:10px;font-size:13px;margin-top:14px;font-weight:500}
    .modal-msg-err{background:#fef2f2;color:#dc2626}.modal-msg-ok{background:#f0fdf4;color:#16a34a}
    .modal-foot{display:flex;justify-content:flex-end;gap:10px;padding:16px 24px;border-top:1px solid #f1f5f9}
    .mbtn{padding:10px 22px;border-radius:12px;cursor:pointer;font-weight:600;font-size:14px;border:none;transition:.2s;display:flex;align-items:center;gap:6px}
    .mbtn-cancel{background:#f3f4f6;color:#374151}.mbtn-cancel:hover{background:#e5e7eb}
    .mbtn-go{background:#10b981;color:#fff}.mbtn-go:hover{background:#059669}
    .mbtn-go:disabled{opacity:.5;cursor:not-allowed}

    @media(max-width:900px){.wg{grid-template-columns:1fr 1fr}}
    @media(max-width:560px){.wg{grid-template-columns:1fr}.wc-main{flex-direction:column;align-items:stretch;gap:16px}.btn-sacar{align-self:stretch;justify-content:center}}
    </style>

    <script>
    // Tabs
    document.querySelectorAll('.tab').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach(p => p.style.display = 'none');
            btn.classList.add('active');
            document.getElementById('panel-' + btn.dataset.tab).style.display = 'block';
        });
    });

    // Modal
    function openModal() { document.getElementById('modal').classList.add('open'); }
    function closeModal() { document.getElementById('modal').classList.remove('open'); }

    async function doSaque() {
        const btn = document.getElementById('btn-confirm');
        const err = document.getElementById('msg-err');
        const ok = document.getElementById('msg-ok');
        const valor = parseFloat(document.getElementById('inp-valor').value);

        err.style.display = 'none';
        ok.style.display = 'none';

        if (!valor || valor < 10) {
            err.textContent = 'Valor minimo: R$ 10,00';
            err.style.display = 'block';
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i class="lucide-loader spin"></i> Enviando PIX...';

        try {
            const res = await fetch('/painel/mercado/ajax/sacar.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ amount: valor })
            });
            const data = await res.json();

            if (data.success) {
                ok.textContent = data.message;
                ok.style.display = 'block';
                btn.innerHTML = '<i class="lucide-check"></i> PIX Enviado!';
                setTimeout(() => location.reload(), 2500);
            } else {
                err.textContent = data.message;
                err.style.display = 'block';
                btn.disabled = false;
                btn.innerHTML = '<i class="lucide-arrow-up-right"></i> Confirmar Saque';
            }
        } catch (e) {
            err.textContent = 'Erro de conexao. Tente novamente.';
            err.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '<i class="lucide-arrow-up-right"></i> Confirmar Saque';
        }
    }
    </script>
</body>
</html>
