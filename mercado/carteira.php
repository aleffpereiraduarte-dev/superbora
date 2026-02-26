<?php
require_once __DIR__ . '/includes/theme.php';
if (!isset($_SESSION['worker_id'])) { header('Location: login.php'); exit; }

$worker = getWorker();
$balance = $worker['balance'] ?? 3247.85;
$pending = $worker['pending_balance'] ?? 428.50;
$todayEarnings = 342.00;
$weekEarnings = 2156.00;
$monthEarnings = 9847.00;

pageStart('Carteira');
echo renderHeader('Carteira');
?>
<main class="main">
    <div class="hero-card orange">
        <div class="hero-label">Saldo disponível</div>
        <div class="hero-value">R$ <?= number_format($balance, 2, ',', '.') ?></div>
        <div class="hero-subtitle">R$ <?= number_format($pending, 2, ',', '.') ?> pendente</div>
    </div>

    <div class="btn-group" style="margin-bottom: 24px;">
        <button class="btn btn-primary" onclick="openWithdraw()">
            <?= icon('credit-card') ?>
            Sacar PIX
        </button>
        <button class="btn btn-secondary" onclick="location.href='extrato.php'">
            <?= icon('document') ?>
            Extrato
        </button>
    </div>

    <section class="section">
        <div class="section-header">
            <h3 class="section-title">Resumo</h3>
        </div>
        
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-value" style="color: var(--brand);">R$ <?= number_format($todayEarnings, 0) ?></div>
                <div class="stat-label">Hoje</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--blue);">R$ <?= number_format($weekEarnings, 0) ?></div>
                <div class="stat-label">Esta semana</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--orange);">R$ <?= number_format($monthEarnings, 0) ?></div>
                <div class="stat-label">Este mês</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--purple);">R$ 312</div>
                <div class="stat-label">Bônus</div>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="section-header">
            <h3 class="section-title">Dados Bancários</h3>
            <span class="section-link" onclick="editBank()">Editar</span>
        </div>
        
        <div class="card">
            <div class="card-header">
                <div class="card-icon purple"><?= icon('credit-card') ?></div>
                <div class="card-info">
                    <div class="card-title">Chave PIX</div>
                    <div class="card-subtitle">CPF: ***.***.***-00</div>
                </div>
                <span class="badge badge-success">Ativo</span>
            </div>
            
            <div class="divider"></div>
            
            <div class="info-row">
                <span class="info-label">Banco</span>
                <span class="info-value">Nubank</span>
            </div>
            <div class="info-row">
                <span class="info-label">Titular</span>
                <span class="info-value"><?= htmlspecialchars($worker['name'] ?? 'Usuário') ?></span>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="section-header">
            <h3 class="section-title">Últimos Saques</h3>
        </div>
        
        <div class="list-item">
            <div class="list-icon green"><?= icon('check') ?></div>
            <div class="list-body">
                <div class="list-title">Saque PIX</div>
                <div class="list-subtitle">12/12/2024 às 18:30</div>
            </div>
            <div class="list-meta">
                <div class="list-value">R$ 500,00</div>
            </div>
        </div>
        
        <div class="list-item">
            <div class="list-icon green"><?= icon('check') ?></div>
            <div class="list-body">
                <div class="list-title">Saque PIX</div>
                <div class="list-subtitle">10/12/2024 às 14:15</div>
            </div>
            <div class="list-meta">
                <div class="list-value">R$ 800,00</div>
            </div>
        </div>
    </section>
</main>

<!-- Modal Saque -->
<div id="withdraw-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:flex-end;justify-content:center;">
    <div style="background:var(--bg);border-radius:20px 20px 0 0;width:100%;max-width:430px;padding:24px;padding-bottom:calc(24px + var(--safe-b));">
        <div style="width:40px;height:4px;background:var(--border);border-radius:2px;margin:0 auto 20px;"></div>
        <h3 style="font-size:20px;font-weight:600;margin-bottom:8px;">Sacar via PIX</h3>
        <p style="font-size:14px;color:var(--txt2);margin-bottom:24px;">Disponível: R$ <?= number_format($balance, 2, ',', '.') ?></p>
        
        <div class="input-group">
            <label class="input-label">Valor do saque</label>
            <input type="text" class="input" id="withdraw-amount" placeholder="R$ 0,00" style="font-size:24px;font-weight:600;text-align:center;">
        </div>
        
        <div style="display:flex;gap:8px;margin-bottom:24px;">
            <button class="btn btn-secondary" style="flex:1;padding:10px;" onclick="setAmount(100)">R$ 100</button>
            <button class="btn btn-secondary" style="flex:1;padding:10px;" onclick="setAmount(500)">R$ 500</button>
            <button class="btn btn-secondary" style="flex:1;padding:10px;" onclick="setAmount(<?= $balance ?>)">Tudo</button>
        </div>
        
        <button class="btn btn-primary" onclick="confirmWithdraw()">Confirmar Saque</button>
        <button class="btn btn-secondary" style="margin-top:12px;" onclick="closeWithdraw()">Cancelar</button>
    </div>
</div>

<script>
function openWithdraw() { document.getElementById('withdraw-modal').style.display = 'flex'; }
function closeWithdraw() { document.getElementById('withdraw-modal').style.display = 'none'; }
function setAmount(v) { document.getElementById('withdraw-amount').value = 'R$ ' + v.toFixed(2).replace('.', ','); }
function confirmWithdraw() { alert('Saque solicitado!\n\nO valor será creditado em até 1 hora.'); closeWithdraw(); }
function editBank() { alert('Função de edição em desenvolvimento'); }
</script>
<?php pageEnd(); ?>
