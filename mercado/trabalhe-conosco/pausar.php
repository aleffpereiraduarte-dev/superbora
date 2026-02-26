<?php
require_once __DIR__ . '/includes/theme.php';
if (!isset($_SESSION['worker_id'])) { header('Location: login.php'); exit; }

pageStart('Pausar');
?>
<style>
.pause-option {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 20px;
    background: var(--bg);
    border: 2px solid var(--border);
    border-radius: 16px;
    margin-bottom: 12px;
    cursor: pointer;
    transition: all 0.2s;
}
.pause-option:hover {
    border-color: var(--brand);
}
.pause-option.selected {
    border-color: var(--brand);
    background: var(--brand-lt);
}
.pause-icon {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
}
.pause-info {
    flex: 1;
}
.pause-title {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 4px;
}
.pause-duration {
    font-size: 14px;
    color: var(--txt2);
}
.pause-radio {
    width: 24px;
    height: 24px;
    border: 2px solid var(--border);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.pause-option.selected .pause-radio {
    border-color: var(--brand);
    background: var(--brand);
}
.pause-option.selected .pause-radio::after {
    content: '';
    width: 8px;
    height: 8px;
    background: white;
    border-radius: 50%;
}
.timer-display {
    text-align: center;
    padding: 32px 0;
}
.timer-circle {
    width: 180px;
    height: 180px;
    border-radius: 50%;
    background: var(--bg2);
    border: 4px solid var(--orange);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    margin: 0 auto 24px;
}
.timer-value {
    font-size: 48px;
    font-weight: 700;
    color: var(--orange);
}
.timer-label {
    font-size: 14px;
    color: var(--txt2);
}
</style>

<header class="header">
    <div class="header-inner">
        <button class="back-btn" onclick="history.back()">
            <?= icon('arrow-left') ?>
        </button>
        <h1 class="header-title">Pausar</h1>
        <div style="width: 40px;"></div>
    </div>
</header>

<main class="main">
    <!-- Tela de Seleção -->
    <div id="screen-select">
        <div style="text-align: center; margin-bottom: 24px;">
            <h2 style="font-size: 20px; font-weight: 600; margin-bottom: 8px;">Precisa de uma pausa?</h2>
            <p class="text-muted">Selecione o motivo e o tempo</p>
        </div>

        <div class="pause-option" onclick="selectPause(this, 5, 'bathroom')">
            <div class="pause-icon" style="background: var(--blue-lt);">🚽</div>
            <div class="pause-info">
                <div class="pause-title">Ir ao banheiro</div>
                <div class="pause-duration">5 minutos</div>
            </div>
            <div class="pause-radio"></div>
        </div>

        <div class="pause-option" onclick="selectPause(this, 15, 'food')">
            <div class="pause-icon" style="background: var(--orange-lt);">🍔</div>
            <div class="pause-info">
                <div class="pause-title">Comer algo</div>
                <div class="pause-duration">15 minutos</div>
            </div>
            <div class="pause-radio"></div>
        </div>

        <div class="pause-option" onclick="selectPause(this, 10, 'fuel')">
            <div class="pause-icon" style="background: var(--purple-lt);">⛽</div>
            <div class="pause-info">
                <div class="pause-title">Abastecer</div>
                <div class="pause-duration">10 minutos</div>
            </div>
            <div class="pause-radio"></div>
        </div>

        <div class="pause-option" onclick="selectPause(this, 30, 'rest')">
            <div class="pause-icon" style="background: var(--brand-lt);">☕</div>
            <div class="pause-info">
                <div class="pause-title">Descansar</div>
                <div class="pause-duration">30 minutos</div>
            </div>
            <div class="pause-radio"></div>
        </div>

        <div class="pause-option" onclick="selectPause(this, 60, 'personal')">
            <div class="pause-icon" style="background: var(--red-lt);">👤</div>
            <div class="pause-info">
                <div class="pause-title">Motivo pessoal</div>
                <div class="pause-duration">1 hora</div>
            </div>
            <div class="pause-radio"></div>
        </div>

        <div class="alert alert-info" style="margin-top: 24px;">
            <div class="alert-icon"><?= icon('info') ?></div>
            <div class="alert-content">
                <div class="alert-title">Pausas não afetam sua avaliação</div>
                <div class="alert-text">Você não receberá pedidos durante a pausa, mas sua pontuação permanece igual.</div>
            </div>
        </div>

        <button class="btn btn-primary" id="start-pause-btn" onclick="startPause()" disabled style="margin-top: 24px;">
            Iniciar Pausa
        </button>
    </div>

    <!-- Tela de Pausa Ativa -->
    <div id="screen-active" style="display: none;">
        <div class="timer-display">
            <div class="timer-circle">
                <div class="timer-value" id="timer-value">05:00</div>
                <div class="timer-label">restantes</div>
            </div>
            <h2 style="font-size: 20px; font-weight: 600; margin-bottom: 8px;">Pausa ativa</h2>
            <p class="text-muted" id="pause-reason">Indo ao banheiro</p>
        </div>

        <div class="alert alert-warning" style="margin-bottom: 24px;">
            <div class="alert-icon"><?= icon('warning') ?></div>
            <div class="alert-content">
                <div class="alert-title">Você está offline</div>
                <div class="alert-text">Não receberá pedidos até retomar.</div>
            </div>
        </div>

        <button class="btn btn-primary" onclick="endPause()">
            <?= icon('check') ?>
            Voltar a trabalhar
        </button>

        <button class="btn btn-secondary" onclick="extendPause()" style="margin-top: 12px;">
            <?= icon('clock') ?>
            Estender pausa (+5 min)
        </button>
    </div>
</main>

<script>
let selectedMinutes = 0;
let selectedReason = '';
let timerInterval = null;
let remainingSeconds = 0;

const reasons = {
    'bathroom': 'Indo ao banheiro',
    'food': 'Comendo',
    'fuel': 'Abastecendo',
    'rest': 'Descansando',
    'personal': 'Motivo pessoal'
};

function selectPause(element, minutes, reason) {
    // Remover seleção anterior
    document.querySelectorAll('.pause-option').forEach(el => el.classList.remove('selected'));
    
    // Adicionar nova seleção
    element.classList.add('selected');
    
    selectedMinutes = minutes;
    selectedReason = reason;
    
    // Habilitar botão
    document.getElementById('start-pause-btn').disabled = false;
    
    // Vibrar
    if (navigator.vibrate) navigator.vibrate(30);
}

function startPause() {
    if (!selectedMinutes) return;
    
    // Vibrar
    if (navigator.vibrate) navigator.vibrate([50, 30, 50]);
    
    // Mostrar tela ativa
    document.getElementById('screen-select').style.display = 'none';
    document.getElementById('screen-active').style.display = 'block';
    
    // Configurar timer
    remainingSeconds = selectedMinutes * 60;
    document.getElementById('pause-reason').textContent = reasons[selectedReason];
    updateTimerDisplay();
    
    // Iniciar countdown
    timerInterval = setInterval(() => {
        remainingSeconds--;
        updateTimerDisplay();
        
        if (remainingSeconds <= 0) {
            endPause();
        }
        
        // Alerta nos últimos 30 segundos
        if (remainingSeconds === 30) {
            if (navigator.vibrate) navigator.vibrate([100, 50, 100]);
        }
    }, 1000);
    
    // Notificar API
    notifyPause(true);
}

function updateTimerDisplay() {
    const minutes = Math.floor(remainingSeconds / 60);
    const seconds = remainingSeconds % 60;
    document.getElementById('timer-value').textContent = 
        String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
}

function endPause() {
    clearInterval(timerInterval);
    
    if (navigator.vibrate) navigator.vibrate([100, 50, 200]);
    
    // Notificar API
    notifyPause(false);
    
    // Voltar para o app
    alert('Pausa encerrada!\n\nVocê está online novamente.');
    location.href = 'app.php?resumed=1';
}

function extendPause() {
    remainingSeconds += 5 * 60;
    if (navigator.vibrate) navigator.vibrate(50);
    alert('Pausa estendida em 5 minutos!');
}

async function notifyPause(isPaused) {
    try {
        await fetch('api/toggle-online.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                worker_id: <?= $_SESSION['worker_id'] ?? 0 ?>,
                online: !isPaused,
                pause_reason: isPaused ? selectedReason : null
            })
        });
    } catch (e) {
        console.log('Error:', e);
    }
}

// Limpar timer ao sair
window.addEventListener('beforeunload', () => {
    if (timerInterval) {
        clearInterval(timerInterval);
        notifyPause(false);
    }
});
</script>
<?php pageEnd(); ?>
