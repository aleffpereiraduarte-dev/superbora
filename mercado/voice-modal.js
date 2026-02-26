/**
 * ONE Voice Modal v91 - CONVERSATIONAL EDITION
 * Design conversacional estilo ChatGPT Voice
 * Criador: Aleff Duarte, CEO OneMundo
 */
console.log("voice-modal.js v91 - CONVERSATIONAL 🎙️");

// ═══════════════════════════════════════════════════════════════
// ESTADO GLOBAL
// ═══════════════════════════════════════════════════════════════
var voiceActive = false;
var voiceBusy = false;
var isListening = false;
var hasSpoken = false;
var canInterrupt = false;

// Áudio
var voiceAudioCtx = null;
var voiceGlobalAudio = null;
var mediaRecorder = null;
var audioChunks = [];
var audioStream = null;
var analyser = null;
var animationFrame = null;

// Timers
var silenceStart = null;
var volumeHistory = [];

// Config
var VOICE_SILENCE_THRESHOLD = 12;
var VOICE_SILENCE_DURATION = 1000;
var VOICE_MIN_RECORDING_TIME = 500;
var VOICE_MAX_RECORDING_TIME = 15000;

// MimeType
var supportedMimeType = 'audio/webm';
if (typeof MediaRecorder !== 'undefined') {
    if (MediaRecorder.isTypeSupported('audio/webm;codecs=opus')) {
        supportedMimeType = 'audio/webm;codecs=opus';
    } else if (MediaRecorder.isTypeSupported('audio/mp4')) {
        supportedMimeType = 'audio/mp4';
    }
}
console.log('[Voice] MimeType:', supportedMimeType);

// Flag global
window.voiceModalOpen = false;

// ═══════════════════════════════════════════════════════════════
// SONS PREMIUM (ESTILO ALEXA)
// ═══════════════════════════════════════════════════════════════
function getAudioCtx() {
    if (!voiceAudioCtx || voiceAudioCtx.state === 'closed') {
        voiceAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
    if (voiceAudioCtx.state === 'suspended') voiceAudioCtx.resume();
    return voiceAudioCtx;
}

function playWakeSound() {
    try {
        var ctx = getAudioCtx();
        var now = ctx.currentTime;
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.frequency.setValueAtTime(659, now);
        osc.frequency.setValueAtTime(880, now + 0.08);
        osc.type = 'sine';
        gain.gain.setValueAtTime(0, now);
        gain.gain.linearRampToValueAtTime(0.2, now + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.01, now + 0.25);
        osc.start(now);
        osc.stop(now + 0.25);
    } catch(e) {}
}

function playListeningSound() {
    try {
        var ctx = getAudioCtx();
        var now = ctx.currentTime;
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.frequency.setValueAtTime(880, now);
        osc.frequency.exponentialRampToValueAtTime(440, now + 0.08);
        osc.type = 'sine';
        gain.gain.setValueAtTime(0.1, now);
        gain.gain.exponentialRampToValueAtTime(0.01, now + 0.1);
        osc.start(now);
        osc.stop(now + 0.1);
    } catch(e) {}
}

function playUnderstoodSound() {
    try {
        var ctx = getAudioCtx();
        var now = ctx.currentTime;
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.frequency.setValueAtTime(523, now);
        osc.frequency.setValueAtTime(659, now + 0.06);
        osc.frequency.setValueAtTime(784, now + 0.12);
        osc.type = 'sine';
        gain.gain.setValueAtTime(0.15, now);
        gain.gain.exponentialRampToValueAtTime(0.01, now + 0.2);
        osc.start(now);
        osc.stop(now + 0.2);
    } catch(e) {}
}

function playErrorSound() {
    try {
        var ctx = getAudioCtx();
        var now = ctx.currentTime;
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.frequency.setValueAtTime(330, now);
        osc.frequency.exponentialRampToValueAtTime(220, now + 0.15);
        osc.type = 'triangle';
        gain.gain.setValueAtTime(0.12, now);
        gain.gain.exponentialRampToValueAtTime(0.01, now + 0.2);
        osc.start(now);
        osc.stop(now + 0.2);
    } catch(e) {}
}

function playEndSound() {
    try {
        var ctx = getAudioCtx();
        var now = ctx.currentTime;
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.frequency.setValueAtTime(659, now);
        osc.frequency.exponentialRampToValueAtTime(330, now + 0.2);
        osc.type = 'sine';
        gain.gain.setValueAtTime(0.1, now);
        gain.gain.exponentialRampToValueAtTime(0.01, now + 0.25);
        osc.start(now);
        osc.stop(now + 0.25);
    } catch(e) {}
}

// ═══════════════════════════════════════════════════════════════
// MODAL UI PREMIUM
// ═══════════════════════════════════════════════════════════════
function abrirVoiceModal() {
    console.log('[Voice] abrirVoiceModal chamado');

    if (document.getElementById('voiceOrb')) {
        console.log('[Voice] Modal ja existe, ignorando');
        return;
    }

    console.log('[Voice] Criando modal...');
    unlockAudio();
    playWakeSound();
    
    // Vibra no celular
    if (navigator.vibrate) navigator.vibrate([50, 30, 50]);
    
    var modal = document.createElement('div');
    modal.id = 'voiceOrb';
    modal.innerHTML = `
        <div class="vo-header">
            <div class="vo-header-left">
                <div class="vo-avatar">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                    </svg>
                </div>
                <span class="vo-title">ONE Voice</span>
            </div>
            <div class="vo-close" onclick="fecharVoiceModal()">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M18 6L6 18M6 6l12 12"/>
                </svg>
            </div>
        </div>

        <div class="vo-chat" id="voChat">
            <div class="vo-welcome">
                <div class="vo-welcome-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 15c1.66 0 2.99-1.34 2.99-3L15 6c0-1.66-1.34-3-3-3S9 4.34 9 6v6c0 1.66 1.34 3 3 3zm5.3-3c0 3-2.54 5.1-5.3 5.1S6.7 15 6.7 12H5c0 3.42 2.72 6.23 6 6.72V22h2v-3.28c3.28-.48 6-3.3 6-6.72h-1.7z"/>
                    </svg>
                </div>
                <div class="vo-welcome-text">Estou ouvindo...</div>
            </div>
        </div>

        <div class="vo-bottom">
            <div class="vo-status-bar" id="voStatusBar">
                <span class="vo-status-dot"></span>
                <span class="vo-status" id="voStatus">Iniciando...</span>
            </div>

            <div class="vo-orb-container">
                <div class="vo-rings">
                    <div class="vo-ring vo-ring-1"></div>
                    <div class="vo-ring vo-ring-2"></div>
                </div>
                <div class="vo-orb" id="voOrb">
                    <div class="vo-bars" id="voBars">
                        <div class="vo-bar"></div>
                        <div class="vo-bar"></div>
                        <div class="vo-bar"></div>
                        <div class="vo-bar"></div>
                        <div class="vo-bar"></div>
                    </div>
                </div>
            </div>

            <div class="vo-hint">Toque para interromper</div>
        </div>
        
        <style>
            #voiceOrb {
                position: fixed;
                inset: 0;
                background: linear-gradient(180deg, #0d0d0d 0%, #1a1a1a 100%);
                z-index: 999999;
                display: flex;
                flex-direction: column;
                animation: voFadeIn 0.3s ease-out;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            }

            @keyframes voFadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }

            /* Header */
            .vo-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 16px 20px;
                border-bottom: 1px solid rgba(255,255,255,0.08);
            }

            .vo-header-left {
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .vo-avatar {
                width: 36px;
                height: 36px;
                border-radius: 50%;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
            }

            .vo-title {
                font-size: 16px;
                font-weight: 600;
                color: #fff;
            }

            .vo-close {
                width: 40px;
                height: 40px;
                border-radius: 50%;
                background: rgba(255,255,255,0.08);
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                color: rgba(255,255,255,0.6);
                transition: all 0.2s;
            }

            .vo-close:hover {
                background: rgba(255,255,255,0.15);
                color: #fff;
            }

            /* Chat Area */
            .vo-chat {
                flex: 1;
                overflow-y: auto;
                padding: 20px;
                display: flex;
                flex-direction: column;
                gap: 16px;
            }

            .vo-welcome {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                height: 100%;
                gap: 16px;
                opacity: 0.6;
            }

            .vo-welcome-icon {
                width: 64px;
                height: 64px;
                border-radius: 50%;
                background: rgba(255,255,255,0.1);
                display: flex;
                align-items: center;
                justify-content: center;
                color: rgba(255,255,255,0.5);
            }

            .vo-welcome-text {
                font-size: 15px;
                color: rgba(255,255,255,0.5);
            }

            /* Messages */
            .vo-msg {
                max-width: 85%;
                padding: 12px 16px;
                border-radius: 18px;
                font-size: 15px;
                line-height: 1.5;
                animation: voMsgIn 0.3s ease-out;
            }

            @keyframes voMsgIn {
                from { opacity: 0; transform: translateY(10px); }
                to { opacity: 1; transform: translateY(0); }
            }

            .vo-msg.user {
                align-self: flex-end;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: #fff;
                border-bottom-right-radius: 6px;
            }

            .vo-msg.one {
                align-self: flex-start;
                background: rgba(255,255,255,0.1);
                color: rgba(255,255,255,0.95);
                border-bottom-left-radius: 6px;
            }

            .vo-msg.one.speaking {
                background: linear-gradient(135deg, rgba(17, 153, 142, 0.3) 0%, rgba(56, 239, 125, 0.3) 100%);
                border: 1px solid rgba(56, 239, 125, 0.3);
            }

            .vo-msg.typing {
                display: flex;
                gap: 4px;
                padding: 16px 20px;
            }

            .vo-msg.typing span {
                width: 8px;
                height: 8px;
                background: rgba(255,255,255,0.5);
                border-radius: 50%;
                animation: voTyping 1.4s infinite;
            }

            .vo-msg.typing span:nth-child(2) { animation-delay: 0.2s; }
            .vo-msg.typing span:nth-child(3) { animation-delay: 0.4s; }

            @keyframes voTyping {
                0%, 60%, 100% { transform: translateY(0); opacity: 0.5; }
                30% { transform: translateY(-6px); opacity: 1; }
            }

            /* Bottom Area */
            .vo-bottom {
                padding: 20px;
                border-top: 1px solid rgba(255,255,255,0.08);
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 16px;
                background: rgba(0,0,0,0.3);
            }

            .vo-status-bar {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 8px 16px;
                background: rgba(255,255,255,0.05);
                border-radius: 20px;
            }

            .vo-status-dot {
                width: 8px;
                height: 8px;
                border-radius: 50%;
                background: #666;
                transition: background 0.3s;
            }

            #voiceOrb.listening .vo-status-dot {
                background: #4facfe;
                box-shadow: 0 0 8px #4facfe;
                animation: voDotPulse 1s infinite;
            }

            #voiceOrb.processing .vo-status-dot {
                background: #fa709a;
                animation: voDotPulse 0.5s infinite;
            }

            #voiceOrb.speaking .vo-status-dot {
                background: #38ef7d;
                box-shadow: 0 0 8px #38ef7d;
            }

            @keyframes voDotPulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.4; }
            }

            .vo-status {
                font-size: 13px;
                color: rgba(255,255,255,0.7);
            }

            .vo-orb-container {
                position: relative;
                width: 100px;
                height: 100px;
            }

            .vo-rings {
                position: absolute;
                inset: -20px;
                pointer-events: none;
            }

            .vo-ring {
                position: absolute;
                inset: 0;
                border-radius: 50%;
                border: 1px solid rgba(255,255,255,0.1);
                opacity: 0;
            }

            #voiceOrb.listening .vo-ring {
                animation: voRingPulse 2s ease-out infinite;
            }

            .vo-ring-1 { animation-delay: 0s !important; }
            .vo-ring-2 { animation-delay: 0.5s !important; }

            @keyframes voRingPulse {
                0% { transform: scale(0.7); opacity: 0.5; border-color: rgba(79, 172, 254, 0.5); }
                100% { transform: scale(1.4); opacity: 0; }
            }

            .vo-orb {
                width: 100px;
                height: 100px;
                border-radius: 50%;
                background: linear-gradient(135deg, #333 0%, #222 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                position: relative;
                z-index: 2;
                transition: all 0.3s;
                box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            }

            .vo-orb:active {
                transform: scale(0.95);
            }

            #voiceOrb.listening .vo-orb {
                background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
                box-shadow: 0 4px 30px rgba(79, 172, 254, 0.4);
            }

            #voiceOrb.processing .vo-orb {
                background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
                animation: voProcessPulse 0.6s ease-in-out infinite;
            }

            @keyframes voProcessPulse {
                0%, 100% { transform: scale(1); }
                50% { transform: scale(1.05); }
            }

            #voiceOrb.speaking .vo-orb {
                background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
                box-shadow: 0 4px 30px rgba(56, 239, 125, 0.4);
            }

            #voiceOrb.error .vo-orb {
                background: linear-gradient(135deg, #eb3349 0%, #f45c43 100%);
            }

            .vo-bars {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 4px;
                height: 40px;
            }

            .vo-bar {
                width: 4px;
                background: rgba(255,255,255,0.9);
                border-radius: 2px;
                transition: height 0.05s ease-out;
            }

            .vo-bar:nth-child(1) { height: 12px; }
            .vo-bar:nth-child(2) { height: 20px; }
            .vo-bar:nth-child(3) { height: 28px; }
            .vo-bar:nth-child(4) { height: 20px; }
            .vo-bar:nth-child(5) { height: 12px; }

            #voiceOrb:not(.listening):not(.speaking) .vo-bar {
                animation: voBarIdle 1.5s ease-in-out infinite;
            }

            .vo-bar:nth-child(1) { animation-delay: 0s; }
            .vo-bar:nth-child(2) { animation-delay: 0.1s; }
            .vo-bar:nth-child(3) { animation-delay: 0.2s; }
            .vo-bar:nth-child(4) { animation-delay: 0.3s; }
            .vo-bar:nth-child(5) { animation-delay: 0.4s; }

            @keyframes voBarIdle {
                0%, 100% { height: 12px; }
                50% { height: 20px; }
            }

            .vo-hint {
                font-size: 12px;
                color: rgba(255,255,255,0.3);
            }

            #voiceOrb.speaking .vo-hint {
                color: rgba(255,255,255,0.5);
            }

            /* Scrollbar */
            .vo-chat::-webkit-scrollbar {
                width: 4px;
            }
            .vo-chat::-webkit-scrollbar-track {
                background: transparent;
            }
            .vo-chat::-webkit-scrollbar-thumb {
                background: rgba(255,255,255,0.1);
                border-radius: 2px;
            }
        </style>
    `;
    
    document.body.appendChild(modal);
    document.body.style.overflow = 'hidden';
    console.log('[Voice] Modal adicionado ao DOM');

    voiceActive = true;
    window.voiceModalOpen = true;

    // Para recognition do one.php
    if (window.recognition) {
        try { window.recognition.stop(); } catch(e) {}
    }

    console.log('[Voice] Iniciando microfone...');
    
    // Click no orb para interromper
    document.getElementById('voOrb').onclick = function() {
        if (voiceBusy && voiceGlobalAudio && !voiceGlobalAudio.paused) {
            // Interrompe TTS
            voiceGlobalAudio.pause();
            voiceGlobalAudio.currentTime = 0;
            voiceBusy = false;
            playListeningSound();
            restartListening();
        }
    };
    
    initMicrophone();
}

function unlockAudio() {
    if (!voiceAudioCtx) {
        voiceAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
    if (voiceAudioCtx.state === 'suspended') {
        voiceAudioCtx.resume();
    }
    
    voiceGlobalAudio = new Audio();
    voiceGlobalAudio.src = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQAAAAA=';
    voiceGlobalAudio.volume = 0.01;
    voiceGlobalAudio.play().catch(function(){});
}

function fecharVoiceModal() {
    playEndSound();
    
    voiceActive = false;
    window.voiceModalOpen = false;
    voiceBusy = false;
    isListening = false;
    
    stopMicrophone();
    cancelAnimationFrame(animationFrame);
    
    if (voiceGlobalAudio) {
        try { voiceGlobalAudio.pause(); } catch(e) {}
    }
    
    var modal = document.getElementById('voiceOrb');
    if (modal) {
        modal.style.transition = 'opacity 0.2s';
        modal.style.opacity = '0';
        setTimeout(function() {
            modal.remove();
            document.body.style.overflow = '';
        }, 200);
    }
}

// ═══════════════════════════════════════════════════════════════
// MICROFONE E GRAVAÇÃO
// ═══════════════════════════════════════════════════════════════
function initMicrophone() {
    console.log('[Voice] initMicrophone chamado');

    navigator.mediaDevices.getUserMedia({
        audio: {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true,
            sampleRate: 44100
        }
    })
    .then(function(stream) {
        console.log('[Voice] Microfone liberado com sucesso');
        audioStream = stream;

        var ctx = getAudioCtx();
        var source = ctx.createMediaStreamSource(stream);
        analyser = ctx.createAnalyser();
        analyser.fftSize = 256;
        analyser.smoothingTimeConstant = 0.8;
        source.connect(analyser);

        setTimeout(function() {
            console.log('[Voice] Iniciando escuta...');
            playListeningSound();
            startListening();
        }, 300);
    })
    .catch(function(err) {
        console.error('[Voice] Erro no microfone:', err);
        updateStatus('Erro no microfone');
        setModalState('error');
        playErrorSound();
    });
}

function stopMicrophone() {
    isListening = false;
    
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        try { mediaRecorder.stop(); } catch(e) {}
    }
    
    if (audioStream) {
        audioStream.getTracks().forEach(function(t) { t.stop(); });
        audioStream = null;
    }
}

// ═══════════════════════════════════════════════════════════════
// ESCUTA E DETECÇÃO DE VOZ
// ═══════════════════════════════════════════════════════════════
function startListening() {
    if (!voiceActive || !audioStream || isListening) return;

    console.log('[Voice] Comecando a ouvir...');

    isListening = true;
    voiceBusy = false;
    audioChunks = [];
    hasSpoken = false;
    silenceStart = null;
    volumeHistory = [];

    mediaRecorder = new MediaRecorder(audioStream, { mimeType: supportedMimeType });

    mediaRecorder.ondataavailable = function(e) {
        if (e.data.size > 0) audioChunks.push(e.data);
    };

    mediaRecorder.onstop = function() {
        if (hasSpoken && audioChunks.length > 0 && voiceActive) {
            var blob = new Blob(audioChunks, { type: supportedMimeType });
            console.log('[Voice] Blob:', blob.size, 'bytes');

            if (blob.size > 2000) {
                playUnderstoodSound();
                processAudio(blob);
            } else {
                console.log('[Voice] Audio muito curto');
                restartListening();
            }
        } else {
            restartListening();
        }
    };

    mediaRecorder.start(100);

    setModalState('listening');
    updateStatus('Ouvindo...');

    detectVoice();
}

function restartListening() {
    isListening = false;
    if (voiceActive && audioStream && !voiceBusy) {
        setTimeout(startListening, 300);
    }
}

function detectVoice() {
    if (!voiceActive || !analyser || voiceBusy) return;

    var data = new Uint8Array(analyser.frequencyBinCount);
    analyser.getByteFrequencyData(data);

    // Calcula volume medio
    var sum = 0;
    for (var i = 0; i < data.length; i++) {
        sum += data[i];
    }
    var volume = sum / data.length;

    // Historico para suavizacao
    volumeHistory.push(volume);
    if (volumeHistory.length > 5) volumeHistory.shift();
    var avgVolume = volumeHistory.reduce(function(a, b) { return a + b; }, 0) / volumeHistory.length;

    // Atualiza visualizacao das barras
    updateBars(avgVolume);

    // Detecta fala
    if (avgVolume > VOICE_SILENCE_THRESHOLD) {
        hasSpoken = true;
        silenceStart = null;
    } else if (hasSpoken) {
        if (!silenceStart) {
            silenceStart = Date.now();
        }

        var silenceTime = Date.now() - silenceStart;

        if (silenceTime > VOICE_SILENCE_DURATION) {
            console.log('[Voice] Silencio detectado apos', silenceTime, 'ms');
            silenceStart = null;
            isListening = false;

            if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                mediaRecorder.stop();
            }
            return;
        }
    }

    if (voiceActive && isListening && !voiceBusy) {
        animationFrame = requestAnimationFrame(detectVoice);
    }
}

function updateBars(volume) {
    var bars = document.querySelectorAll('.vo-bar');
    if (!bars.length) return;

    var normalized = Math.min(volume / 60, 1);
    var heights = [
        12 + normalized * 20,
        20 + normalized * 25,
        28 + normalized * 30,
        20 + normalized * 25,
        12 + normalized * 20
    ];

    bars.forEach(function(bar, i) {
        bar.style.height = heights[i] + 'px';
    });
}

// ═══════════════════════════════════════════════════════════════
// CHAT MESSAGES
// ═══════════════════════════════════════════════════════════════
function addVoiceMessage(text, isUser, isSpeaking) {
    var chat = document.getElementById('voChat');
    if (!chat) return;

    // Remove welcome message on first message
    var welcome = chat.querySelector('.vo-welcome');
    if (welcome) welcome.remove();

    // Remove typing indicator
    var typing = chat.querySelector('.vo-msg.typing');
    if (typing) typing.remove();

    var msg = document.createElement('div');
    msg.className = 'vo-msg ' + (isUser ? 'user' : 'one');
    if (isSpeaking) msg.classList.add('speaking');
    msg.textContent = text;

    chat.appendChild(msg);
    chat.scrollTop = chat.scrollHeight;

    return msg;
}

function showTypingIndicator() {
    var chat = document.getElementById('voChat');
    if (!chat) return;

    // Remove welcome message
    var welcome = chat.querySelector('.vo-welcome');
    if (welcome) welcome.remove();

    // Remove existing typing indicator
    var existing = chat.querySelector('.vo-msg.typing');
    if (existing) existing.remove();

    var typing = document.createElement('div');
    typing.className = 'vo-msg one typing';
    typing.innerHTML = '<span></span><span></span><span></span>';

    chat.appendChild(typing);
    chat.scrollTop = chat.scrollHeight;
}

function removeTypingIndicator() {
    var typing = document.querySelector('.vo-msg.typing');
    if (typing) typing.remove();
}

function markMessageSpeaking(msgEl, isSpeaking) {
    if (!msgEl) return;
    if (isSpeaking) {
        msgEl.classList.add('speaking');
    } else {
        msgEl.classList.remove('speaking');
    }
}

var currentOneMessage = null;

// ═══════════════════════════════════════════════════════════════
// PROCESSAMENTO E CHAT
// ═══════════════════════════════════════════════════════════════
function processAudio(blob) {
    voiceBusy = true;
    setModalState('processing');
    updateStatus('Transcrevendo...');

    var reader = new FileReader();
    reader.onloadend = function() {
        fetch('/mercado/one_voice.php?action=transcribe', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ audio: reader.result })
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            console.log('[Voice] Transcribe:', d);

            if (d.success && d.text && d.text.trim()) {
                var userText = d.text.trim();
                console.log('[Voice] Transcrito:', userText);

                // Add user message to chat
                addVoiceMessage(userText, true);

                // Show typing indicator
                showTypingIndicator();
                updateStatus('Pensando...');

                sendToChat(userText);
            } else {
                console.log('[Voice] Transcricao vazia');
                updateStatus('Nao entendi...');
                playErrorSound();
                setTimeout(function() {
                    voiceBusy = false;
                    restartListening();
                }, 1000);
            }
        })
        .catch(function(e) {
            console.error('[Voice] Erro transcricao:', e);
            updateStatus('Erro de conexao');
            setModalState('error');
            playErrorSound();
            setTimeout(function() {
                voiceBusy = false;
                restartListening();
            }, 1500);
        });
    };
    reader.readAsDataURL(blob);
}

function sendToChat(userText) {
    var baseUrl = window.location.href.split('?')[0];

    fetch(baseUrl + '?action=send&message=' + encodeURIComponent(userText))
    .then(function(r) { return r.json(); })
    .then(function(data) {
        console.log('[Voice] Resposta:', data);

        // Remove typing indicator
        removeTypingIndicator();

        if (data.response) {
            // Adiciona no chat do one.php (background)
            if (typeof window.addMessage === 'function') {
                window.addMessage(userText, true);
                window.addMessage(data.response, false, data.imagem || null);
            }

            // Add ONE's response to voice chat (marked as speaking)
            currentOneMessage = addVoiceMessage(data.response, false, true);
            updateStatus('Falando...');
            voicePlayTTS(data.response);
        } else {
            updateStatus('Sem resposta');
            playErrorSound();
            voiceBusy = false;
            restartListening();
        }
    })
    .catch(function(e) {
        console.error('[Voice] Erro chat:', e);
        removeTypingIndicator();
        updateStatus('Erro de conexao');
        setModalState('error');
        playErrorSound();
        voiceBusy = false;
        setTimeout(restartListening, 1500);
    });
}

// ═══════════════════════════════════════════════════════════════
// TTS (Text-to-Speech)
// ═══════════════════════════════════════════════════════════════
function voicePlayTTS(text) {
    console.log('[Voice] TTS:', text.substring(0, 50));

    setModalState('speaking');
    updateStatus('Falando...');
    canInterrupt = true;

    fetch('/mercado/one_voice.php?action=tts', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ text: text })
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        console.log('[Voice] TTS response:', d);

        if (d.success && d.url) {
            if (!voiceGlobalAudio) voiceGlobalAudio = new Audio();

            voiceGlobalAudio.src = '/mercado/' + d.url + '?t=' + Date.now();
            voiceGlobalAudio.volume = 1.0;

            voiceGlobalAudio.onended = function() {
                console.log('[Voice] TTS terminou');
                canInterrupt = false;
                voiceBusy = false;

                // Remove speaking state from message
                markMessageSpeaking(currentOneMessage, false);
                currentOneMessage = null;

                if (voiceActive) {
                    playListeningSound();
                    setModalState('listening');
                    updateStatus('Ouvindo...');
                    restartListening();
                }
            };

            voiceGlobalAudio.onerror = function(e) {
                console.log('[Voice] TTS erro:', e);
                canInterrupt = false;
                voiceBusy = false;
                markMessageSpeaking(currentOneMessage, false);
                currentOneMessage = null;
                playErrorSound();
                restartListening();
            };

            voiceGlobalAudio.play()
                .then(function() {
                    console.log('[Voice] Tocando...');
                    animateSpeaking();
                })
                .catch(function(e) {
                    console.log('[Voice] Play falhou:', e.message);
                    canInterrupt = false;
                    voiceBusy = false;
                    markMessageSpeaking(currentOneMessage, false);
                    currentOneMessage = null;
                    restartListening();
                });
        } else {
            // Fallback: Browser TTS
            if ('speechSynthesis' in window) {
                var utterance = new SpeechSynthesisUtterance(text);
                utterance.lang = 'pt-BR';
                utterance.onend = function() {
                    canInterrupt = false;
                    voiceBusy = false;
                    markMessageSpeaking(currentOneMessage, false);
                    currentOneMessage = null;
                    if (voiceActive) {
                        setModalState('listening');
                        updateStatus('Ouvindo...');
                        restartListening();
                    }
                };
                speechSynthesis.speak(utterance);
            } else {
                voiceBusy = false;
                markMessageSpeaking(currentOneMessage, false);
                currentOneMessage = null;
                restartListening();
            }
        }
    })
    .catch(function(e) {
        console.log('[Voice] TTS fetch erro:', e);
        voiceBusy = false;
        markMessageSpeaking(currentOneMessage, false);
        currentOneMessage = null;
        playErrorSound();
        restartListening();
    });
}

function animateSpeaking() {
    if (!voiceActive || !voiceBusy || !voiceGlobalAudio || voiceGlobalAudio.paused) return;

    // Animacao aleatoria das barras enquanto fala
    var bars = document.querySelectorAll('.vo-bar');
    bars.forEach(function(bar, i) {
        var h = 15 + Math.random() * 35;
        bar.style.height = h + 'px';
    });

    requestAnimationFrame(animateSpeaking);
}

// ═══════════════════════════════════════════════════════════════
// HELPERS UI
// ═══════════════════════════════════════════════════════════════
function setModalState(state) {
    var modal = document.getElementById('voiceOrb');
    if (!modal) return;
    
    modal.className = '';
    modal.id = 'voiceOrb';
    
    if (state) {
        modal.classList.add(state);
    }
}

function updateStatus(text) {
    var el = document.getElementById('voStatus');
    if (el) el.textContent = text;
}
