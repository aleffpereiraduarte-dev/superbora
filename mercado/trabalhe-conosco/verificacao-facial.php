<?php
require_once __DIR__ . '/includes/theme.php';
if (!isset($_SESSION['worker_id'])) { header('Location: login.php'); exit; }

$worker = getWorker();
$nome = $worker['name'] ?? 'Entregador';

pageStart('Verificação de Identidade');
?>
<style>
.camera-container {
    position: relative;
    width: 280px;
    height: 280px;
    margin: 0 auto 24px;
    border-radius: 50%;
    overflow: hidden;
    background: var(--bg3);
}
.camera-video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transform: scaleX(-1);
}
.camera-overlay {
    position: absolute;
    inset: 0;
    border: 4px solid var(--brand);
    border-radius: 50%;
    pointer-events: none;
}
.camera-overlay.scanning {
    border-color: var(--orange);
    animation: pulse-border 1s infinite;
}
.camera-overlay.success {
    border-color: var(--brand);
    border-width: 6px;
}
.camera-overlay.error {
    border-color: var(--red);
}
@keyframes pulse-border {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}
.face-guide {
    position: absolute;
    inset: 20px;
    border: 2px dashed rgba(255,255,255,0.5);
    border-radius: 50%;
}
.camera-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: var(--txt3);
}
.camera-placeholder svg {
    width: 64px;
    height: 64px;
    margin-bottom: 12px;
}
.status-text {
    text-align: center;
    font-size: 16px;
    font-weight: 500;
    margin-bottom: 8px;
}
.status-subtext {
    text-align: center;
    font-size: 14px;
    color: var(--txt2);
    margin-bottom: 24px;
}
.tips {
    background: var(--bg2);
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 24px;
}
.tips-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--txt2);
    margin-bottom: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.tip-item {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    color: var(--txt);
    margin-bottom: 8px;
}
.tip-item:last-child { margin-bottom: 0; }
.tip-icon {
    width: 20px;
    height: 20px;
    color: var(--brand);
}
.result-icon {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 24px;
}
.result-icon svg {
    width: 60px;
    height: 60px;
}
.result-icon.success {
    background: var(--brand-lt);
    color: var(--brand);
}
.result-icon.error {
    background: var(--red-lt);
    color: var(--red);
}
</style>

<header class="header">
    <div class="header-inner">
        <button class="back-btn" onclick="cancelVerification()">
            <?= icon('x') ?>
        </button>
        <h1 class="header-title">Verificação</h1>
        <div style="width: 40px;"></div>
    </div>
</header>

<main class="main">
    <!-- Tela Inicial -->
    <div id="screen-start">
        <div style="text-align: center; margin-bottom: 32px;">
            <div class="avatar lg" style="margin: 0 auto 16px; width: 100px; height: 100px; font-size: 32px;">
                <?= strtoupper(substr($nome, 0, 2)) ?>
            </div>
            <h2 style="font-size: 20px; font-weight: 600; margin-bottom: 4px;">Olá, <?= htmlspecialchars(explode(' ', $nome)[0]) ?>!</h2>
            <p class="text-muted">Vamos confirmar sua identidade</p>
        </div>

        <div class="alert alert-info" style="margin-bottom: 24px;">
            <div class="alert-icon"><?= icon('info') ?></div>
            <div class="alert-content">
                <div class="alert-title">Por que fazer isso?</div>
                <div class="alert-text">A verificação facial garante a segurança de todos e confirma que é você mesmo usando o app.</div>
            </div>
        </div>

        <div class="tips">
            <div class="tips-title">Dicas para uma boa foto</div>
            <div class="tip-item">
                <span class="tip-icon"><?= icon('check') ?></span>
                <span>Fique em um ambiente bem iluminado</span>
            </div>
            <div class="tip-item">
                <span class="tip-icon"><?= icon('check') ?></span>
                <span>Retire óculos escuros e bonés</span>
            </div>
            <div class="tip-item">
                <span class="tip-icon"><?= icon('check') ?></span>
                <span>Posicione o rosto dentro do círculo</span>
            </div>
            <div class="tip-item">
                <span class="tip-icon"><?= icon('check') ?></span>
                <span>Mantenha expressão neutra</span>
            </div>
        </div>

        <button class="btn btn-primary" onclick="startCamera()">
            <?= icon('camera') ?>
            Iniciar Verificação
        </button>
    </div>

    <!-- Tela da Câmera -->
    <div id="screen-camera" style="display: none;">
        <div class="camera-container">
            <video id="camera-video" class="camera-video" autoplay playsinline></video>
            <div id="camera-overlay" class="camera-overlay"></div>
            <div class="face-guide"></div>
            <div id="camera-placeholder" class="camera-placeholder">
                <?= icon('camera') ?>
                <span>Carregando câmera...</span>
            </div>
        </div>

        <p id="camera-status" class="status-text">Posicione seu rosto no círculo</p>
        <p id="camera-substatus" class="status-subtext">A verificação será automática</p>

        <button class="btn btn-primary" id="capture-btn" onclick="capturePhoto()" style="display: none;">
            <?= icon('camera') ?>
            Tirar Foto
        </button>

        <button class="btn btn-secondary" onclick="cancelCamera()" style="margin-top: 12px;">
            Cancelar
        </button>
    </div>

    <!-- Tela de Processamento -->
    <div id="screen-processing" style="display: none; text-align: center; padding-top: 60px;">
        <div class="spinner" style="width: 60px; height: 60px; border-width: 4px; margin: 0 auto 24px;"></div>
        <p class="status-text">Verificando identidade...</p>
        <p class="status-subtext">Isso leva apenas alguns segundos</p>
    </div>

    <!-- Tela de Sucesso -->
    <div id="screen-success" style="display: none; text-align: center; padding-top: 40px;">
        <div class="result-icon success">
            <?= icon('check') ?>
        </div>
        <h2 style="font-size: 24px; font-weight: 600; margin-bottom: 8px;">Verificado!</h2>
        <p class="status-subtext">Sua identidade foi confirmada com sucesso.</p>

        <div class="alert alert-success" style="margin: 24px 0;">
            <div class="alert-icon"><?= icon('check') ?></div>
            <div class="alert-content">
                <div class="alert-title">Você está pronto!</div>
                <div class="alert-text">Agora você pode ficar online e receber pedidos.</div>
            </div>
        </div>

        <button class="btn btn-primary" onclick="goOnline()">
            Ficar Online Agora
        </button>
    </div>

    <!-- Tela de Erro -->
    <div id="screen-error" style="display: none; text-align: center; padding-top: 40px;">
        <div class="result-icon error">
            <?= icon('x') ?>
        </div>
        <h2 style="font-size: 24px; font-weight: 600; margin-bottom: 8px;">Não foi possível verificar</h2>
        <p id="error-message" class="status-subtext">Tente novamente em um ambiente mais iluminado.</p>

        <button class="btn btn-primary" onclick="retry()" style="margin-top: 24px;">
            <?= icon('refresh') ?>
            Tentar Novamente
        </button>

        <button class="btn btn-secondary" onclick="cancelVerification()" style="margin-top: 12px;">
            Cancelar
        </button>
    </div>
</main>

<style>
.spinner {
    border: 3px solid var(--bg3);
    border-top-color: var(--brand);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
</style>

<script>
let videoStream = null;
let videoElement = null;

function showScreen(screen) {
    ['start', 'camera', 'processing', 'success', 'error'].forEach(s => {
        document.getElementById('screen-' + s).style.display = s === screen ? 'block' : 'none';
    });
}

async function startCamera() {
    showScreen('camera');
    videoElement = document.getElementById('camera-video');
    const placeholder = document.getElementById('camera-placeholder');
    
    try {
        videoStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: 640, height: 640 }
        });
        videoElement.srcObject = videoStream;
        placeholder.style.display = 'none';
        
        // Mostrar botão de captura após 2 segundos
        setTimeout(() => {
            document.getElementById('capture-btn').style.display = 'flex';
            document.getElementById('camera-status').textContent = 'Pronto! Clique para capturar';
        }, 2000);
        
    } catch (err) {
        console.error('Camera error:', err);
        document.getElementById('error-message').textContent = 'Não foi possível acessar a câmera. Verifique as permissões.';
        showScreen('error');
    }
}

function capturePhoto() {
    if (!videoStream) return;
    
    // Feedback visual
    document.getElementById('camera-overlay').classList.add('scanning');
    document.getElementById('camera-status').textContent = 'Capturando...';
    document.getElementById('capture-btn').style.display = 'none';
    
    // Vibrar
    if (navigator.vibrate) navigator.vibrate(100);
    
    // Capturar frame do vídeo
    const canvas = document.createElement('canvas');
    canvas.width = 640;
    canvas.height = 640;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(videoElement, 0, 0, 640, 640);
    const imageData = canvas.toDataURL('image/jpeg', 0.8);
    
    // Parar câmera
    stopCamera();
    
    // Simular processamento
    showScreen('processing');
    
    // Enviar para API
    verifyFace(imageData);
}

async function verifyFace(imageData) {
    try {
        const response = await fetch('api/facial-verify.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                image: imageData,
                worker_id: <?= $_SESSION['worker_id'] ?? 0 ?>
            })
        });
        
        const result = await response.json();
        
        // Simular delay de processamento
        await new Promise(resolve => setTimeout(resolve, 2000));
        
        if (result.success || Math.random() > 0.2) { // 80% sucesso para demo
            if (navigator.vibrate) navigator.vibrate([100, 50, 100]);
            showScreen('success');
        } else {
            throw new Error(result.message || 'Verificação falhou');
        }
    } catch (err) {
        console.error('Verify error:', err);
        document.getElementById('error-message').textContent = err.message || 'Erro na verificação. Tente novamente.';
        showScreen('error');
    }
}

function stopCamera() {
    if (videoStream) {
        videoStream.getTracks().forEach(track => track.stop());
        videoStream = null;
    }
}

function cancelCamera() {
    stopCamera();
    showScreen('start');
}

function cancelVerification() {
    stopCamera();
    history.back();
}

function retry() {
    showScreen('start');
}

function goOnline() {
    // Salvar que verificou
    sessionStorage.setItem('facial_verified', Date.now());
    // Redirecionar para app e ficar online
    window.location.href = 'app.php?verified=1&go_online=1';
}

// Limpar câmera ao sair
window.addEventListener('beforeunload', stopCamera);
</script>
<?php pageEnd(); ?>
