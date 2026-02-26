<?php
/**
 * COMPONENTE: Detector de Localização
 * Detecta automaticamente a localização do usuário e encontra mercados próximos
 *
 * Fluxo:
 * 1. Verifica se já tem mercado na sessão
 * 2. Tenta detectar por IP automaticamente
 * 3. Se não conseguir, mostra modal para pedir CEP
 * 4. Se não tiver mercado na região, mostra waitlist
 *
 * Variáveis externas (podem vir do index.php):
 * - $mostrar_detector_localizacao: bool - se deve abrir o modal automaticamente
 */

// Verificar se já tem mercado selecionado (usa variável externa se disponível)
if (!isset($mostrar_detector_localizacao)) {
    $tem_mercado = isset($_SESSION['market_partner_id']) && $_SESSION['market_partner_id'] > 0;
    $location_checked = isset($_SESSION['location_checked']);
    $mostrar_detector_localizacao = !$tem_mercado && !$location_checked;
}
?>

<!-- CSS do Detector de Localização -->
<style>
/* Overlay escuro */
.loc-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.7);
    z-index: 99999;
    backdrop-filter: blur(8px);
    animation: locFadeIn 0.3s ease;
}

@keyframes locFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

/* Modal principal */
.loc-modal {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: #fff;
    border-radius: 28px;
    padding: 0;
    max-width: 420px;
    width: 92%;
    box-shadow: 0 30px 100px rgba(0,0,0,0.4);
    z-index: 100000;
    overflow: hidden;
    animation: locSlideIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes locSlideIn {
    from {
        opacity: 0;
        transform: translate(-50%, -50%) scale(0.85) translateY(30px);
    }
    to {
        opacity: 1;
        transform: translate(-50%, -50%) scale(1) translateY(0);
    }
}

/* Header do modal */
.loc-header {
    background: linear-gradient(135deg, #00b894 0%, #00cec9 100%);
    padding: 32px 28px 28px;
    text-align: center;
    position: relative;
}

.loc-header::after {
    content: '';
    position: absolute;
    bottom: -20px;
    left: 0;
    right: 0;
    height: 40px;
    background: #fff;
    border-radius: 50% 50% 0 0;
}

.loc-icon-container {
    width: 90px;
    height: 90px;
    background: rgba(255,255,255,0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
    position: relative;
    z-index: 1;
}

.loc-icon-container svg {
    width: 48px;
    height: 48px;
    fill: white;
}

.loc-icon-container.loading {
    animation: locPulse 1.5s infinite;
}

@keyframes locPulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.1); opacity: 0.8; }
}

.loc-title {
    color: white;
    font-size: 22px;
    font-weight: 700;
    margin: 0;
    position: relative;
    z-index: 1;
    text-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

/* Corpo do modal */
.loc-body {
    padding: 24px 28px 32px;
    text-align: center;
}

.loc-message {
    color: #636e72;
    font-size: 16px;
    line-height: 1.6;
    margin-bottom: 24px;
}

/* Loading */
.loc-loading {
    padding: 40px 28px;
    text-align: center;
}

.loc-spinner {
    width: 50px;
    height: 50px;
    border: 4px solid #e9ecef;
    border-top-color: #00b894;
    border-radius: 50%;
    animation: locSpin 1s linear infinite;
    margin: 0 auto 20px;
}

@keyframes locSpin {
    to { transform: rotate(360deg); }
}

.loc-loading-text {
    color: #636e72;
    font-size: 16px;
}

/* Formulário CEP */
.loc-form {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.loc-input-group {
    position: relative;
}

.loc-input {
    width: 100%;
    padding: 18px 20px;
    border: 2px solid #e9ecef;
    border-radius: 14px;
    font-size: 20px;
    text-align: center;
    letter-spacing: 3px;
    font-weight: 600;
    transition: all 0.3s ease;
    outline: none;
}

.loc-input:focus {
    border-color: #00b894;
    box-shadow: 0 0 0 4px rgba(0,184,148,0.15);
}

.loc-input::placeholder {
    letter-spacing: 1px;
    color: #adb5bd;
    font-weight: 400;
}

.loc-btn {
    padding: 18px 24px;
    background: linear-gradient(135deg, #00b894 0%, #00a085 100%);
    color: white;
    border: none;
    border-radius: 14px;
    font-size: 17px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.loc-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 30px rgba(0,184,148,0.4);
}

.loc-btn:disabled {
    background: #adb5bd;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.loc-btn svg {
    width: 22px;
    height: 22px;
    fill: currentColor;
}

/* Seção de sucesso */
.loc-success {
    display: none;
}

.loc-success-icon {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #00b894, #00cec9);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
    animation: locBounce 0.6s ease;
}

@keyframes locBounce {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.2); }
}

.loc-success-icon svg {
    width: 40px;
    height: 40px;
    fill: white;
}

.loc-success-title {
    font-size: 20px;
    font-weight: 700;
    color: #2d3436;
    margin-bottom: 8px;
}

.loc-success-message {
    color: #636e72;
    font-size: 15px;
    line-height: 1.5;
}

.loc-market-card {
    background: #f8f9fa;
    border-radius: 14px;
    padding: 16px;
    margin: 20px 0;
    display: flex;
    cursor: pointer;
    transition: all 0.3s ease;
}

.loc-market-card:hover {
    background: #e9ecef;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    align-items: center;
    gap: 14px;
}

.loc-market-logo {
    width: 56px;
    height: 56px;
    background: #fff;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.loc-market-info {
    flex: 1;
    text-align: left;
}

.loc-market-name {
    font-weight: 700;
    color: #2d3436;
    font-size: 16px;
}

.loc-market-details {
    color: #636e72;
    font-size: 13px;
    margin-top: 2px;
}

/* Erro */
.loc-error {
    background: #fff5f5;
    border: 1px solid #ffc9c9;
    border-radius: 10px;
    padding: 12px 16px;
    margin-bottom: 16px;
    color: #c92a2a;
    font-size: 14px;
    display: none;
}

/* Link secundário */
.loc-link {
    color: #00b894;
    text-decoration: none;
    font-weight: 600;
    font-size: 14px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-top: 16px;
}

.loc-link:hover {
    text-decoration: underline;
}

/* Botão GPS */
.loc-gps-btn {
    background: #f1f3f5;
    border: none;
    padding: 12px 20px;
    border-radius: 10px;
    font-size: 14px;
    color: #495057;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
}

.loc-gps-btn:hover {
    background: #e9ecef;
}

.loc-gps-btn svg {
    width: 18px;
    height: 18px;
}

.loc-divider {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 16px 0;
    color: #adb5bd;
    font-size: 13px;
}

.loc-divider::before,
.loc-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: #e9ecef;
}
</style>

<!-- HTML do Modal de Localização -->
<div class="loc-overlay" id="locOverlay">
    <div class="loc-modal">
        <!-- Estado: Loading (detectando localização) -->
        <div id="locStateLoading" style="display: none;">
            <div class="loc-header">
                <div class="loc-icon-container loading">
                    <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>
                </div>
                <h2 class="loc-title">Localizando você...</h2>
            </div>
            <div class="loc-loading">
                <div class="loc-spinner"></div>
                <p class="loc-loading-text">Buscando mercados próximos</p>
            </div>
        </div>

        <!-- Estado: Pedir CEP -->
        <div id="locStateCep" style="display: none;">
            <div class="loc-header">
                <div class="loc-icon-container">
                    <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>
                </div>
                <h2 class="loc-title">Onde você está?</h2>
            </div>
            <div class="loc-body">
                <p class="loc-message">
                    Para mostrar os mercados e produtos disponíveis na sua região, precisamos saber sua localização.
                </p>

                <div class="loc-error" id="locError"></div>

                <form class="loc-form" id="locCepForm" onsubmit="verificarCepLoc(event)">
                    <div class="loc-input-group">
                        <input type="text" class="loc-input" id="locCepInput" placeholder="00000-000" maxlength="9" required autocomplete="postal-code" inputmode="numeric">
                    </div>
                    <button type="submit" class="loc-btn" id="locCepBtn">
                        <svg viewBox="0 0 24 24"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" fill="none" stroke="currentColor" stroke-width="2.5"/></svg>
                        <span>Buscar Mercados</span>
                    </button>
                </form>

                <div class="loc-divider">ou</div>

                <button type="button" class="loc-gps-btn" onclick="usarGPS()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M12 2v4m0 12v4M2 12h4m12 0h4"/>
                    </svg>
                    Usar minha localização GPS
                </button>
            </div>
        </div>

        <!-- Estado: Sucesso (encontrou mercado) -->
        <div id="locStateSuccess" style="display: none;">
            <div class="loc-header" style="background: linear-gradient(135deg, #00b894 0%, #55efc4 100%);">
                <div class="loc-icon-container">
                    <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                </div>
                <h2 class="loc-title">Encontramos!</h2>
            </div>
            <div class="loc-body">
                <div class="loc-success-title" id="locSuccessTitle">Ótimo! Atendemos sua região</div>
                <p class="loc-success-message" id="locSuccessMessage">Encontramos mercados perto de você.</p>

                <div class="loc-market-card" id="locMarketCard" onclick="confirmarMercado()" title="Clique para ir à loja">
                    <div class="loc-market-logo" id="locMarketLogo">🛒</div>
                    <div class="loc-market-info">
                        <div class="loc-market-name" id="locMarketName">Mercado</div>
                        <div class="loc-market-details" id="locMarketDetails">Carregando...</div>
                    </div>
                    <div style="margin-left:auto; color:#00b894;">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                    </div>
                </div>

                <button type="button" class="loc-btn" onclick="confirmarMercado()">
                    <svg viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2.5"/></svg>
                    <span>Começar a Comprar</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Incluir Waitlist Modal -->
<?php include __DIR__ . '/waitlist-modal.php'; ?>

<!-- JavaScript do Detector -->
<script>
// Estado global
let locData = {
    mercado: null,
    localizacao: null
};

// Verificar localização ao carregar (se necessário)
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($mostrar_detector_localizacao): ?>
    // Não tem mercado na sessão - iniciar detecção automática
    iniciarDeteccaoLocalizacao();
    <?php endif; ?>
});

// Iniciar processo de detecção
async function iniciarDeteccaoLocalizacao() {
    mostrarEstado('loading');
    document.getElementById('locOverlay').style.display = 'block';
    document.body.style.overflow = 'hidden';

    try {
        // Tentar detectar por IP
        const response = await fetch('/mercado/api/geoip.php?action=detect');
        const data = await response.json();

        if (data.success) {
            if (data.disponivel) {
                // Encontrou mercado!
                locData.mercado = data.mercado;
                locData.localizacao = data.localizacao;
                mostrarSucesso(data);
            } else if (data.show_waitlist) {
                // Não tem mercado na região - mostrar waitlist
                fecharLocModal();
                abrirWaitlist(data);
            } else if (data.need_cep) {
                // Não conseguiu detectar - pedir CEP
                mostrarEstado('cep');
            }
        } else {
            // Erro - pedir CEP
            mostrarEstado('cep');
        }
    } catch (error) {
        console.error('Erro ao detectar localização:', error);
        mostrarEstado('cep');
    }
}

// Mostrar estado específico do modal
function mostrarEstado(estado) {
    document.getElementById('locStateLoading').style.display = 'none';
    document.getElementById('locStateCep').style.display = 'none';
    document.getElementById('locStateSuccess').style.display = 'none';

    if (estado === 'loading') {
        document.getElementById('locStateLoading').style.display = 'block';
    } else if (estado === 'cep') {
        document.getElementById('locStateCep').style.display = 'block';
        setTimeout(() => document.getElementById('locCepInput').focus(), 100);
    } else if (estado === 'success') {
        document.getElementById('locStateSuccess').style.display = 'block';
    }
}

// Verificar CEP digitado
async function verificarCepLoc(e) {
    e.preventDefault();

    const cep = document.getElementById('locCepInput').value.replace(/\D/g, '');
    const btn = document.getElementById('locCepBtn');
    const errorDiv = document.getElementById('locError');

    if (cep.length !== 8) {
        errorDiv.textContent = 'Por favor, digite um CEP válido com 8 dígitos.';
        errorDiv.style.display = 'block';
        return;
    }

    errorDiv.style.display = 'none';
    btn.disabled = true;
    btn.innerHTML = '<div class="loc-spinner" style="width:22px;height:22px;border-width:2px;margin:0"></div>';

    try {
        const response = await fetch('/mercado/api/localizacao.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'verificar_cep', cep: cep })
        });
        const data = await response.json();

        if (data.success) {
            if (data.disponivel) {
                // Encontrou mercado!
                locData.mercado = data.mercado;
                locData.localizacao = data.localizacao;
                mostrarSucesso(data);
            } else if (data.show_waitlist) {
                // Não tem mercado - mostrar waitlist
                fecharLocModal();
                abrirWaitlist(data);
            }
        } else {
            errorDiv.textContent = data.error || 'CEP não encontrado. Verifique e tente novamente.';
            errorDiv.style.display = 'block';
        }
    } catch (error) {
        console.error('Erro ao verificar CEP:', error);
        errorDiv.textContent = 'Erro de conexão. Tente novamente.';
        errorDiv.style.display = 'block';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" fill="none" stroke="currentColor" stroke-width="2.5"/></svg><span>Buscar Mercados</span>';
    }
}

// Usar GPS do dispositivo
async function usarGPS() {
    if (!navigator.geolocation) {
        alert('Seu navegador não suporta geolocalização.');
        return;
    }

    mostrarEstado('loading');
    document.querySelector('#locStateLoading .loc-loading-text').textContent = 'Obtendo sua localização GPS...';

    navigator.geolocation.getCurrentPosition(
        async (position) => {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;

            try {
                // Buscar mercados por coordenadas
                const response = await fetch(`/mercado/api/location.php?action=nearby_stores&lat=${lat}&lng=${lng}&radius=50`);
                const data = await response.json();

                if (data.success && data.stores && data.stores.length > 0) {
                    // Encontrou mercados - pegar o mais próximo dentro do raio
                    const mercadosProximos = data.stores.filter(s => s.distance_km <= 20);

                    if (mercadosProximos.length > 0) {
                        const mercado = mercadosProximos[0];
                        locData.mercado = {
                            partner_id: mercado.id,
                            nome: mercado.name,
                            distancia_km: mercado.distance_km,
                            tempo_estimado: Math.ceil(mercado.distance_km * 3),
                            taxa_entrega: mercado.delivery_fee
                        };
                        locData.localizacao = { lat, lng, cidade: mercado.address || 'Sua localização' };

                        mostrarSucesso({
                            mercado: locData.mercado,
                            localizacao: locData.localizacao,
                            mensagem: 'Encontramos mercados perto de você!'
                        });
                    } else {
                        // Mercados muito longe
                        mostrarEstado('cep');
                        document.getElementById('locError').textContent = 'Não encontramos mercados dentro do raio de entrega na sua localização GPS. Tente informar o CEP.';
                        document.getElementById('locError').style.display = 'block';
                    }
                } else {
                    mostrarEstado('cep');
                    document.getElementById('locError').textContent = 'Não encontramos mercados na sua região. Tente informar o CEP.';
                    document.getElementById('locError').style.display = 'block';
                }
            } catch (error) {
                console.error('Erro ao buscar mercados:', error);
                mostrarEstado('cep');
            }
        },
        (error) => {
            console.error('Erro GPS:', error);
            mostrarEstado('cep');

            let msg = 'Não foi possível obter sua localização.';
            if (error.code === 1) msg = 'Permissão de localização negada. Por favor, informe seu CEP.';
            else if (error.code === 2) msg = 'Localização indisponível. Por favor, informe seu CEP.';
            else if (error.code === 3) msg = 'Tempo esgotado. Por favor, informe seu CEP.';

            document.getElementById('locError').textContent = msg;
            document.getElementById('locError').style.display = 'block';
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
    );
}

// Mostrar tela de sucesso
function mostrarSucesso(data) {
    mostrarEstado('success');

    const mercado = data.mercado;

    document.getElementById('locSuccessTitle').textContent = 'Ótimo! Atendemos sua região';
    document.getElementById('locSuccessMessage').textContent = data.mensagem || 'Encontramos mercados perto de você.';

    document.getElementById('locMarketName').textContent = mercado.nome;
    document.getElementById('locMarketDetails').textContent =
        `${mercado.distancia_km}km de distância • ~${mercado.tempo_estimado} min`;
}

// Confirmar mercado e começar a comprar
function confirmarMercado() {
    const partnerId = locData.mercado.partner_id;

    // Salvar mercado na sessão
    fetch('/mercado/api/localizacao.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=selecionar_mercado&partner_id=${partnerId}`
    }).then(() => {
        // Fechar modal e recarregar a página para mostrar os produtos do mercado
        fecharLocModal();
        window.location.reload();
    });
}

// Fechar modal de localização
function fecharLocModal() {
    document.getElementById('locOverlay').style.display = 'none';
    document.body.style.overflow = '';
}

// Máscara de CEP
document.getElementById('locCepInput')?.addEventListener('input', function(e) {
    let value = e.target.value.replace(/\D/g, '');
    if (value.length > 5) {
        value = value.substring(0, 5) + '-' + value.substring(5, 8);
    }
    e.target.value = value;
});

// Permitir fechar modal clicando fora (só se já verificou pelo menos uma vez)
document.getElementById('locOverlay')?.addEventListener('click', function(e) {
    if (e.target === this) {
        // Marcar que já foi verificado para não mostrar de novo
        fetch('/mercado/api/session.php?action=set&key=location_checked&value=1').catch(() => {});
        fecharLocModal();
    }
});

// Função para abrir modal de endereço (usada no header)
function abrirModalEndereco(mensagem) {
    mostrarEstado('cep');
    if (mensagem) {
        var msgEl = document.querySelector('#locStateCep .loc-message');
        if (msgEl) msgEl.textContent = mensagem;
    }
    document.getElementById('locOverlay').style.display = 'block';
    document.body.style.overflow = 'hidden';
}
</script>
