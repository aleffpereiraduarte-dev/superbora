<?php
/**
 * COMPONENTE: Modal de Lista de Espera
 * Exibe mensagem amigável quando não há mercado na região
 * e permite cadastro para notificação futura
 */
?>

<!-- CSS do Modal Waitlist -->
<style>
.waitlist-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.6);
    z-index: 9999;
    backdrop-filter: blur(4px);
}

.waitlist-modal {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: linear-gradient(145deg, #ffffff, #f8f9fa);
    border-radius: 24px;
    padding: 40px;
    max-width: 450px;
    width: 90%;
    box-shadow: 0 25px 80px rgba(0,0,0,0.3);
    z-index: 10000;
    text-align: center;
    animation: modalSlideIn 0.4s ease;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translate(-50%, -50%) scale(0.9);
    }
    to {
        opacity: 1;
        transform: translate(-50%, -50%) scale(1);
    }
}

.waitlist-icon {
    width: 100px;
    height: 100px;
    margin: 0 auto 20px;
    background: linear-gradient(135deg, #ff6b6b, #ee5a5a);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    animation: iconPulse 2s infinite;
}

.waitlist-icon.success {
    background: linear-gradient(135deg, #51cf66, #40c057);
}

@keyframes iconPulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

.waitlist-icon svg {
    width: 50px;
    height: 50px;
    fill: white;
}

.waitlist-title {
    font-size: 24px;
    font-weight: 700;
    color: #2d3436;
    margin-bottom: 12px;
}

.waitlist-message {
    font-size: 16px;
    color: #636e72;
    line-height: 1.6;
    margin-bottom: 8px;
}

.waitlist-cta {
    font-size: 15px;
    color: #00b894;
    font-weight: 600;
    margin-bottom: 24px;
}

.waitlist-location {
    background: #f1f3f4;
    border-radius: 12px;
    padding: 12px 16px;
    margin-bottom: 24px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.waitlist-location svg {
    width: 18px;
    height: 18px;
    fill: #636e72;
}

.waitlist-location span {
    color: #2d3436;
    font-weight: 500;
}

.waitlist-form {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.waitlist-input {
    padding: 16px 20px;
    border: 2px solid #e9ecef;
    border-radius: 12px;
    font-size: 16px;
    transition: all 0.3s ease;
    outline: none;
}

.waitlist-input:focus {
    border-color: #00b894;
    box-shadow: 0 0 0 4px rgba(0,184,148,0.1);
}

.waitlist-input::placeholder {
    color: #adb5bd;
}

.waitlist-btn {
    padding: 16px 24px;
    background: linear-gradient(135deg, #00b894, #00a085);
    color: white;
    border: none;
    border-radius: 12px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.waitlist-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,184,148,0.4);
}

.waitlist-btn:disabled {
    background: #adb5bd;
    cursor: not-allowed;
    transform: none;
}

.waitlist-btn svg {
    width: 20px;
    height: 20px;
    fill: white;
}

.waitlist-close {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: none;
    background: #f1f3f4;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
}

.waitlist-close:hover {
    background: #e9ecef;
}

.waitlist-close svg {
    width: 18px;
    height: 18px;
    fill: #636e72;
}

.waitlist-success-msg {
    display: none;
}

.waitlist-success-msg.show {
    display: block;
}

.waitlist-form-container.hide {
    display: none;
}

.waitlist-secondary {
    font-size: 14px;
    color: #868e96;
    margin-top: 16px;
}

.waitlist-loading {
    display: none;
}

.waitlist-btn.loading .waitlist-btn-text {
    display: none;
}

.waitlist-btn.loading .waitlist-loading {
    display: block;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.spinner {
    width: 20px;
    height: 20px;
    border: 2px solid rgba(255,255,255,0.3);
    border-top-color: white;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}
</style>

<!-- HTML do Modal -->
<div class="waitlist-overlay" id="waitlistOverlay">
    <div class="waitlist-modal">
        <button class="waitlist-close" onclick="fecharWaitlist()">
            <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        </button>

        <!-- Conteúdo Inicial (Formulário) -->
        <div class="waitlist-form-container" id="waitlistFormContainer">
            <div class="waitlist-icon" id="waitlistIcon">
                <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>
            </div>

            <h2 class="waitlist-title" id="waitlistTitle">Ops! Ainda não chegamos aí</h2>
            <p class="waitlist-message" id="waitlistMessage">Que pena! Ainda não temos mercados parceiros na sua região.</p>
            <p class="waitlist-cta" id="waitlistCta">Deixe seu e-mail e avisamos assim que chegarmos!</p>

            <div class="waitlist-location" id="waitlistLocation">
                <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/></svg>
                <span id="waitlistCidade">Sua cidade</span>
            </div>

            <form class="waitlist-form" id="waitlistForm" onsubmit="enviarWaitlist(event)">
                <input type="text" class="waitlist-input" id="waitlistNome" name="nome" placeholder="Seu nome" required>
                <input type="email" class="waitlist-input" id="waitlistEmail" name="email" placeholder="Seu melhor e-mail" required>
                <input type="hidden" id="waitlistCep" name="cep" value="">

                <button type="submit" class="waitlist-btn" id="waitlistBtn">
                    <span class="waitlist-btn-text">Me avise quando chegar!</span>
                    <span class="waitlist-loading"><div class="spinner"></div></span>
                </button>
            </form>
        </div>

        <!-- Conteúdo de Sucesso -->
        <div class="waitlist-success-msg" id="waitlistSuccess">
            <div class="waitlist-icon success">
                <svg viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
            </div>
            <h2 class="waitlist-title" id="waitlistSuccessTitle">Você está na lista!</h2>
            <p class="waitlist-message" id="waitlistSuccessMessage">Salvamos seu interesse!</p>
            <p class="waitlist-secondary" id="waitlistSuccessSecondary">Fique de olho no seu e-mail!</p>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script>
// Dados salvos da verificação
let waitlistData = {};

// Abrir modal de waitlist com dados da API
function abrirWaitlist(dados) {
    waitlistData = dados;

    // Preencher dados
    document.getElementById('waitlistTitle').textContent = dados.mensagem_titulo || 'Ops! Ainda não chegamos aí';
    document.getElementById('waitlistMessage').textContent = dados.mensagem || 'Ainda não atendemos sua região.';
    document.getElementById('waitlistCta').textContent = dados.mensagem_cta || 'Deixe seu e-mail!';

    if (dados.localizacao) {
        document.getElementById('waitlistCidade').textContent =
            dados.localizacao.cidade + ' - ' + dados.localizacao.uf;
        document.getElementById('waitlistCep').value = dados.localizacao.cep;
    }

    // Mostrar overlay
    document.getElementById('waitlistOverlay').style.display = 'block';
    document.body.style.overflow = 'hidden';

    // Resetar para estado inicial
    document.getElementById('waitlistFormContainer').classList.remove('hide');
    document.getElementById('waitlistSuccess').classList.remove('show');
}

// Fechar modal
function fecharWaitlist() {
    document.getElementById('waitlistOverlay').style.display = 'none';
    document.body.style.overflow = '';
}

// Fechar ao clicar fora
document.getElementById('waitlistOverlay').addEventListener('click', function(e) {
    if (e.target === this) {
        fecharWaitlist();
    }
});

// Enviar formulário
async function enviarWaitlist(e) {
    e.preventDefault();

    const btn = document.getElementById('waitlistBtn');
    btn.classList.add('loading');
    btn.disabled = true;

    const nome = document.getElementById('waitlistNome').value;
    const email = document.getElementById('waitlistEmail').value;
    const cep = document.getElementById('waitlistCep').value;

    try {
        const formData = new FormData();
        formData.append('action', 'salvar_waitlist');
        formData.append('nome', nome);
        formData.append('email', email);
        formData.append('cep', cep);

        const response = await fetch('/mercado/api/localizacao.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            // Mostrar sucesso
            document.getElementById('waitlistFormContainer').classList.add('hide');
            document.getElementById('waitlistSuccess').classList.add('show');

            document.getElementById('waitlistSuccessTitle').textContent = data.mensagem_titulo || 'Você está na lista!';
            document.getElementById('waitlistSuccessMessage').textContent = data.mensagem || 'Salvamos seu interesse!';
            document.getElementById('waitlistSuccessSecondary').textContent = data.mensagem_secundaria || '';

            // Fechar automaticamente após 4 segundos
            setTimeout(() => {
                fecharWaitlist();
            }, 4000);
        } else {
            alert(data.error || 'Erro ao salvar. Tente novamente.');
        }
    } catch (error) {
        console.error('Erro:', error);
        alert('Erro de conexão. Tente novamente.');
    } finally {
        btn.classList.remove('loading');
        btn.disabled = false;
    }
}

// Função para verificar CEP e mostrar modal se necessário
async function verificarCepComWaitlist(cep) {
    try {
        const response = await fetch(`/mercado/api/localizacao.php?action=verificar_cep&cep=${cep}`);
        const data = await response.json();

        if (data.success && data.disponivel === false && data.show_waitlist) {
            // Não tem mercado disponível - mostrar modal de waitlist
            abrirWaitlist(data);
            return { disponivel: false, data: data };
        } else if (data.success && data.disponivel) {
            // Tem mercado - retornar dados
            return { disponivel: true, data: data };
        } else {
            return { disponivel: false, error: data.error || 'Erro desconhecido' };
        }
    } catch (error) {
        console.error('Erro ao verificar CEP:', error);
        return { disponivel: false, error: 'Erro de conexão' };
    }
}
</script>
