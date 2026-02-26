<?php
require_once __DIR__ . '/includes/theme.php';
if (!isset($_SESSION['worker_id'])) { header('Location: login.php'); exit; }

$orderId = $_GET['id'] ?? 'OM-45893';
$clientName = $_GET['client'] ?? 'João Silva';
$deliveryCode = $_GET['code'] ?? '4829'; // Código que o cliente recebeu por SMS

pageStart('Código de Entrega');
echo renderHeader('Confirmar Entrega');
?>
<style>
.code-input-container {
    display: flex;
    justify-content: center;
    gap: 12px;
    margin: 32px 0;
}
.code-input {
    width: 60px;
    height: 72px;
    border: 2px solid var(--border);
    border-radius: 12px;
    font-size: 28px;
    font-weight: 700;
    text-align: center;
    background: var(--bg);
    color: var(--txt);
    transition: all 0.2s;
}
.code-input:focus {
    outline: none;
    border-color: var(--brand);
    background: var(--brand-lt);
}
.code-input.error {
    border-color: var(--red);
    background: var(--red-lt);
    animation: shake 0.5s;
}
.code-input.success {
    border-color: var(--brand);
    background: var(--brand-lt);
}
@keyframes shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-8px); }
    75% { transform: translateX(8px); }
}
.divider-text {
    display: flex;
    align-items: center;
    gap: 16px;
    margin: 32px 0;
    color: var(--txt3);
    font-size: 13px;
}
.divider-text::before,
.divider-text::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
}
.qr-scanner-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    width: 100%;
    padding: 16px;
    background: var(--bg2);
    border: 2px dashed var(--border);
    border-radius: 12px;
    font-size: 15px;
    font-weight: 500;
    color: var(--txt2);
    cursor: pointer;
    transition: all 0.2s;
}
.qr-scanner-btn:hover {
    border-color: var(--brand);
    color: var(--brand);
}
.qr-scanner-btn svg {
    width: 24px;
    height: 24px;
}
.success-animation {
    text-align: center;
    padding: 40px 0;
}
.success-check {
    width: 100px;
    height: 100px;
    background: var(--brand);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 24px;
    animation: pop 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
}
.success-check svg {
    width: 50px;
    height: 50px;
    color: white;
}
@keyframes pop {
    0% { transform: scale(0); }
    100% { transform: scale(1); }
}
.attempts-warning {
    text-align: center;
    font-size: 13px;
    color: var(--txt3);
    margin-top: 16px;
}
.attempts-warning.danger {
    color: var(--red);
}
</style>

<main class="main">
    <!-- Tela de Input do Código -->
    <div id="screen-input">
        <div class="card" style="text-align: center; padding: 24px;">
            <div class="avatar" style="margin: 0 auto 16px; width: 64px; height: 64px; font-size: 24px;">
                <?= strtoupper(substr($clientName, 0, 2)) ?>
            </div>
            <h2 style="font-size: 18px; font-weight: 600; margin-bottom: 4px;"><?= htmlspecialchars($clientName) ?></h2>
            <p class="text-muted">Pedido <?= $orderId ?></p>
        </div>

        <div style="text-align: center; margin-top: 32px;">
            <h3 style="font-size: 16px; font-weight: 600; margin-bottom: 8px;">Digite o código de entrega</h3>
            <p class="text-muted">O cliente recebeu o código por SMS</p>
        </div>

        <div class="code-input-container">
            <input type="tel" class="code-input" maxlength="1" id="code-1" autofocus>
            <input type="tel" class="code-input" maxlength="1" id="code-2">
            <input type="tel" class="code-input" maxlength="1" id="code-3">
            <input type="tel" class="code-input" maxlength="1" id="code-4">
        </div>

        <p id="attempts-text" class="attempts-warning">3 tentativas restantes</p>

        <div class="divider-text">ou</div>

        <button class="qr-scanner-btn" onclick="openQRScanner()">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
            Escanear QR Code do cliente
        </button>

        <div class="divider"></div>

        <div class="alert alert-warning">
            <div class="alert-icon"><?= icon('warning') ?></div>
            <div class="alert-content">
                <div class="alert-title">Cliente não tem o código?</div>
                <div class="alert-text">Peça para ele verificar o SMS ou o app OneMundo.</div>
            </div>
        </div>

        <button class="btn btn-secondary" onclick="requestNewCode()" style="margin-top: 16px;">
            <?= icon('refresh') ?>
            Reenviar código para cliente
        </button>

        <button class="btn btn-outline" onclick="problemWithCode()" style="margin-top: 12px;">
            Problema com o código
        </button>
    </div>

    <!-- Tela de Sucesso -->
    <div id="screen-success" style="display: none;">
        <div class="success-animation">
            <div class="success-check">
                <?= icon('check') ?>
            </div>
            <h2 style="font-size: 24px; font-weight: 600; margin-bottom: 8px;">Entrega Confirmada!</h2>
            <p class="text-muted" style="margin-bottom: 32px;">O cliente confirmou o recebimento</p>
        </div>

        <div class="hero-card" style="text-align: center;">
            <div class="hero-label">Você ganhou</div>
            <div class="hero-value">R$ 52,00</div>
            <div class="hero-subtitle">+ R$ 5,00 de gorjeta</div>
        </div>

        <div class="stat-grid" style="margin-top: 24px;">
            <div class="stat-card">
                <div class="stat-value" style="color: var(--brand);">4.8 km</div>
                <div class="stat-label">Distância</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--blue);">23 min</div>
                <div class="stat-label">Tempo</div>
            </div>
        </div>

        <button class="btn btn-primary" onclick="goToRating()" style="margin-top: 24px;">
            Avaliar Cliente
        </button>

        <button class="btn btn-secondary" onclick="skipRating()" style="margin-top: 12px;">
            Pular avaliação
        </button>
    </div>
</main>

<script>
const correctCode = '<?= $deliveryCode ?>';
let attempts = 3;
const inputs = [
    document.getElementById('code-1'),
    document.getElementById('code-2'),
    document.getElementById('code-3'),
    document.getElementById('code-4')
];

// Auto-focus next input
inputs.forEach((input, index) => {
    input.addEventListener('input', (e) => {
        const value = e.target.value;
        
        // Só permite números
        e.target.value = value.replace(/[^0-9]/g, '');
        
        if (value && index < 3) {
            inputs[index + 1].focus();
        }
        
        // Verificar se completou
        if (index === 3 && value) {
            checkCode();
        }
    });
    
    input.addEventListener('keydown', (e) => {
        // Backspace volta pro anterior
        if (e.key === 'Backspace' && !e.target.value && index > 0) {
            inputs[index - 1].focus();
        }
    });
    
    // Paste handler
    input.addEventListener('paste', (e) => {
        e.preventDefault();
        const paste = (e.clipboardData || window.clipboardData).getData('text');
        const digits = paste.replace(/[^0-9]/g, '').slice(0, 4);
        
        digits.split('').forEach((digit, i) => {
            if (inputs[i]) inputs[i].value = digit;
        });
        
        if (digits.length === 4) {
            checkCode();
        }
    });
});

function checkCode() {
    const enteredCode = inputs.map(i => i.value).join('');
    
    if (enteredCode.length !== 4) return;
    
    if (enteredCode === correctCode) {
        // Sucesso
        inputs.forEach(i => i.classList.add('success'));
        if (navigator.vibrate) navigator.vibrate([100, 50, 100, 50, 200]);
        
        setTimeout(() => {
            document.getElementById('screen-input').style.display = 'none';
            document.getElementById('screen-success').style.display = 'block';
            
            // Registrar entrega
            completeDelivery();
        }, 500);
    } else {
        // Erro
        attempts--;
        inputs.forEach(i => {
            i.classList.add('error');
            i.value = '';
        });
        inputs[0].focus();
        
        if (navigator.vibrate) navigator.vibrate([100, 50, 100]);
        
        setTimeout(() => {
            inputs.forEach(i => i.classList.remove('error'));
        }, 500);
        
        const attemptsText = document.getElementById('attempts-text');
        if (attempts > 0) {
            attemptsText.textContent = `${attempts} tentativa${attempts > 1 ? 's' : ''} restante${attempts > 1 ? 's' : ''}`;
            attemptsText.classList.toggle('danger', attempts === 1);
        } else {
            attemptsText.textContent = 'Sem tentativas. Contate o suporte.';
            attemptsText.classList.add('danger');
            inputs.forEach(i => i.disabled = true);
        }
    }
}

async function completeDelivery() {
    try {
        await fetch('api/complete-delivery.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                order_id: '<?= $orderId ?>',
                worker_id: <?= $_SESSION['worker_id'] ?? 0 ?>,
                code_verified: true
            })
        });
    } catch (e) {
        console.log('Error completing delivery:', e);
    }
}

function openQRScanner() {
    alert('Abrindo scanner QR...\n\nFuncionalidade em desenvolvimento.');
}

function requestNewCode() {
    if (confirm('Reenviar código para o cliente via SMS?')) {
        alert('Código reenviado!\n\nO cliente receberá um novo SMS em instantes.');
    }
}

function problemWithCode() {
    if (confirm('Está tendo problemas com o código?\n\nVocê pode:\n• Pedir para o cliente verificar o SMS\n• Ligar para o suporte\n• Finalizar sem código (requer foto)')) {
        location.href = 'foto-entrega.php?id=<?= $orderId ?>&no_code=1';
    }
}

function goToRating() {
    location.href = 'avaliar-cliente.php?id=<?= $orderId ?>';
}

function skipRating() {
    location.href = 'app.php';
}
</script>
<?php pageEnd(); ?>
