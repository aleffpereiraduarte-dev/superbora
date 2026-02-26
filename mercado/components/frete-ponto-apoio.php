<?php
/**
 * COMPONENTE: Seletor de Frete com Ponto de Apoio
 * Inclua este arquivo no checkout para mostrar opcoes de entrega inteligentes
 *
 * Uso: include 'components/frete-ponto-apoio.php';
 */
?>

<style>
/* ════════════════════════════════════════════════════════════════════════════ */
/* ESTILOS DO SELETOR DE FRETE COM PONTO DE APOIO                              */
/* ════════════════════════════════════════════════════════════════════════════ */

.frete-container {
    margin-bottom: 24px;
}

.frete-loading {
    text-align: center;
    padding: 40px;
    color: #6B6B6B;
}

.frete-loading .spinner {
    width: 32px;
    height: 32px;
    border: 3px solid #E5E5E5;
    border-top-color: #0AAD0A;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin: 0 auto 12px;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.frete-opcoes {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.frete-opcao {
    position: relative;
    padding: 16px 20px;
    border: 2px solid #E5E5E5;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    background: #fff;
}

.frete-opcao:hover {
    border-color: #B3B3B3;
}

.frete-opcao.selected {
    border-color: #0AAD0A;
    background: #E8F5E8;
}

.frete-opcao input {
    display: none;
}

.frete-radio {
    width: 20px;
    height: 20px;
    border: 2px solid #B3B3B3;
    border-radius: 50%;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    margin-top: 2px;
}

.frete-opcao.selected .frete-radio {
    border-color: #0AAD0A;
    background: #0AAD0A;
}

.frete-radio::after {
    content: '';
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #fff;
    opacity: 0;
    transition: opacity 0.2s;
}

.frete-opcao.selected .frete-radio::after {
    opacity: 1;
}

.frete-content {
    flex: 1;
}

.frete-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 4px;
}

.frete-nome {
    font-size: 15px;
    font-weight: 600;
    color: #1A1A1A;
    display: flex;
    align-items: center;
    gap: 8px;
}

.frete-badge {
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 100px;
    background: #0AAD0A;
    color: #fff;
}

.frete-badge.rapido {
    background: #FF6B00;
}

.frete-preco {
    font-size: 16px;
    font-weight: 700;
    color: #1A1A1A;
}

.frete-preco.gratis {
    color: #0AAD0A;
}

.frete-descricao {
    font-size: 13px;
    color: #6B6B6B;
    margin-bottom: 4px;
}

.frete-prazo {
    font-size: 12px;
    color: #8A8A8A;
}

.frete-local {
    margin-top: 8px;
    padding: 10px 12px;
    background: #F5F5F5;
    border-radius: 8px;
    font-size: 13px;
}

.frete-local-nome {
    font-weight: 600;
    color: #1A1A1A;
    margin-bottom: 2px;
}

.frete-local-endereco {
    color: #6B6B6B;
    font-size: 12px;
}

.frete-local-horario {
    color: #0AAD0A;
    font-size: 11px;
    margin-top: 4px;
}

/* Rota detalhada */
.frete-rota {
    margin-top: 8px;
    padding: 10px 12px;
    background: #F0F4FF;
    border-radius: 8px;
    font-size: 12px;
}

.frete-rota-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px 0;
}

.frete-rota-item:not(:last-child) {
    border-bottom: 1px dashed #D0D8F0;
}

.frete-rota-icon {
    width: 20px;
    height: 20px;
    background: #3B82F6;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 10px;
}

.frete-rota-texto {
    flex: 1;
    color: #4A4A4A;
}

.frete-rota-preco {
    color: #6B6B6B;
    font-weight: 500;
}

/* Secao de mapa */
.frete-mapa-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 12px;
    margin-top: 12px;
    border: 2px dashed #D9D9D9;
    border-radius: 10px;
    background: none;
    color: #0AAD0A;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.frete-mapa-btn:hover {
    border-color: #0AAD0A;
    background: #E8F5E8;
}

/* Modal do mapa */
.frete-mapa-modal {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s;
    padding: 20px;
}

.frete-mapa-modal.show {
    opacity: 1;
    visibility: visible;
}

.frete-mapa-content {
    background: #fff;
    border-radius: 16px;
    width: 100%;
    max-width: 600px;
    max-height: 90vh;
    overflow: hidden;
    transform: translateY(20px);
    transition: transform 0.3s;
}

.frete-mapa-modal.show .frete-mapa-content {
    transform: translateY(0);
}

.frete-mapa-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid #E5E5E5;
}

.frete-mapa-titulo {
    font-size: 18px;
    font-weight: 700;
}

.frete-mapa-fechar {
    width: 32px;
    height: 32px;
    border: none;
    background: #F0F0F0;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

.frete-mapa-iframe {
    width: 100%;
    height: 400px;
    border: none;
}

.frete-pontos-lista {
    max-height: 200px;
    overflow-y: auto;
    padding: 12px 20px;
}

.frete-ponto-item {
    padding: 12px;
    border: 1px solid #E5E5E5;
    border-radius: 10px;
    margin-bottom: 8px;
    cursor: pointer;
    transition: all 0.2s;
}

.frete-ponto-item:hover {
    border-color: #0AAD0A;
    background: #E8F5E8;
}

.frete-ponto-nome {
    font-weight: 600;
    margin-bottom: 2px;
}

.frete-ponto-endereco {
    font-size: 13px;
    color: #6B6B6B;
}

.frete-ponto-dist {
    font-size: 12px;
    color: #0AAD0A;
    font-weight: 600;
}

/* Erro */
.frete-erro {
    text-align: center;
    padding: 24px;
    background: #FEE2E2;
    border-radius: 12px;
    color: #991B1B;
}

.frete-erro button {
    margin-top: 12px;
    padding: 10px 20px;
    background: #DC2626;
    color: #fff;
    border: none;
    border-radius: 8px;
    cursor: pointer;
}

/* Responsive */
@media (max-width: 600px) {
    .frete-header {
        flex-direction: column;
        gap: 4px;
    }

    .frete-preco {
        font-size: 15px;
    }
}
</style>

<div id="freteContainer" class="frete-container">
    <div id="freteLoading" class="frete-loading">
        <div class="spinner"></div>
        <div>Calculando opcoes de entrega...</div>
    </div>

    <div id="freteOpcoes" class="frete-opcoes" style="display: none;"></div>

    <div id="freteErro" class="frete-erro" style="display: none;">
        <div>Erro ao calcular frete</div>
        <button onclick="calcularFreteInteligente()">Tentar novamente</button>
    </div>
</div>

<!-- Modal do Mapa -->
<div id="freteMapaModal" class="frete-mapa-modal">
    <div class="frete-mapa-content">
        <div class="frete-mapa-header">
            <span class="frete-mapa-titulo">Pontos de Apoio Proximos</span>
            <button class="frete-mapa-fechar" onclick="fecharMapaPontos()">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div id="freteMapaIframe"></div>
        <div id="fretePontosLista" class="frete-pontos-lista"></div>
    </div>
</div>

<script>
// ════════════════════════════════════════════════════════════════════════════
// SISTEMA DE FRETE INTELIGENTE COM PONTO DE APOIO
// ════════════════════════════════════════════════════════════════════════════

let freteOpcoes = [];
let freteSelecionado = null;

/**
 * Calcular frete inteligente usando a API
 */
async function calcularFreteInteligente() {
    const container = document.getElementById('freteOpcoes');
    const loading = document.getElementById('freteLoading');
    const erro = document.getElementById('freteErro');

    // Pegar dados necessarios
    const cep = document.getElementById('selectedCep')?.textContent?.replace(/\D/g, '') ||
                orderData?.address?.zipcode ||
                orderData?.address?.postcode?.replace(/\D/g, '') || '';

    const sellerId = orderData?.items?.[0]?.seller_id ||
                     window.currentSellerId ||
                     1;

    const subtotal = orderData?.subtotal || 0;

    if (!cep || cep.length !== 8) {
        console.log('CEP invalido para calculo de frete:', cep);
        return;
    }

    loading.style.display = 'block';
    container.style.display = 'none';
    erro.style.display = 'none';

    try {
        const response = await fetch('/mercado/api/frete-ponto-apoio.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'calcular',
                seller_id: sellerId,
                cep: cep,
                subtotal: subtotal,
                peso: 1
            })
        });

        const data = await response.json();
        console.log('Frete calculado:', data);

        if (data.success && data.opcoes && data.opcoes.length > 0) {
            freteOpcoes = data.opcoes;
            renderizarOpcoesFrete(data.opcoes);

            loading.style.display = 'none';
            container.style.display = 'flex';
        } else {
            throw new Error(data.error || 'Nenhuma opcao de frete disponivel');
        }
    } catch (err) {
        console.error('Erro ao calcular frete:', err);
        loading.style.display = 'none';
        erro.style.display = 'block';
        erro.querySelector('div').textContent = err.message;
    }
}

/**
 * Renderizar opcoes de frete
 */
function renderizarOpcoesFrete(opcoes) {
    const container = document.getElementById('freteOpcoes');
    container.innerHTML = '';

    opcoes.forEach((opcao, index) => {
        const div = document.createElement('label');
        div.className = 'frete-opcao' + (index === 0 ? ' selected' : '');
        div.onclick = () => selecionarFrete(opcao.id);

        // Icone baseado no tipo
        let icone = '📦';
        if (opcao.tipo === 'retirada' || opcao.tipo === 'retirada_ponto') icone = '📍';
        else if (opcao.tipo === 'moto') icone = '🏍️';
        else if (opcao.tipo === 'via_ponto') icone = '🔄';
        else if (opcao.tipo === 'correios') icone = '📬';

        // Badge
        let badgeHtml = '';
        if (opcao.badge) {
            const badgeClass = opcao.badge.includes('rapido') || opcao.badge.includes('Mais') ? 'rapido' : '';
            badgeHtml = `<span class="frete-badge ${badgeClass}">${opcao.badge}</span>`;
        }

        // Local (para retirada)
        let localHtml = '';
        if (opcao.local) {
            localHtml = `
                <div class="frete-local">
                    <div class="frete-local-nome">📍 ${opcao.local.nome}</div>
                    <div class="frete-local-endereco">${opcao.local.endereco || ''}</div>
                    <div class="frete-local-horario">⏰ ${opcao.local.horario} (${opcao.local.dias})</div>
                </div>
            `;
        }

        // Rota (para via_ponto)
        let rotaHtml = '';
        if (opcao.rota) {
            rotaHtml = `
                <div class="frete-rota">
                    <div class="frete-rota-item">
                        <div class="frete-rota-icon">1</div>
                        <span class="frete-rota-texto">${opcao.rota.trecho1.de} → ${opcao.rota.trecho1.para}</span>
                        <span class="frete-rota-preco">R$ ${opcao.rota.trecho1.preco.toFixed(2)}</span>
                    </div>
                    <div class="frete-rota-item">
                        <div class="frete-rota-icon">2</div>
                        <span class="frete-rota-texto">${opcao.rota.trecho2.de} → Voce</span>
                        <span class="frete-rota-preco">R$ ${opcao.rota.trecho2.preco.toFixed(2)}</span>
                    </div>
                    <div class="frete-rota-item">
                        <div class="frete-rota-icon">+</div>
                        <span class="frete-rota-texto">Taxa do Ponto de Apoio</span>
                        <span class="frete-rota-preco">R$ ${opcao.rota.taxa_ponto.toFixed(2)}</span>
                    </div>
                </div>
            `;
        }

        div.innerHTML = `
            <input type="radio" name="frete_opcao" value="${opcao.id}" ${index === 0 ? 'checked' : ''}>
            <div class="frete-radio"></div>
            <div class="frete-content">
                <div class="frete-header">
                    <div class="frete-nome">
                        ${icone} ${opcao.nome}
                        ${badgeHtml}
                    </div>
                    <div class="frete-preco ${opcao.is_free ? 'gratis' : ''}">
                        ${opcao.is_free ? 'GRATIS' : opcao.preco_texto}
                    </div>
                </div>
                <div class="frete-descricao">${opcao.descricao || opcao.empresa || ''}</div>
                <div class="frete-prazo">${opcao.prazo_texto}</div>
                ${localHtml}
                ${rotaHtml}
            </div>
        `;

        container.appendChild(div);
    });

    // Selecionar primeira opcao
    if (opcoes.length > 0) {
        selecionarFrete(opcoes[0].id);
    }

    // Botao para ver mapa
    const temRetirada = opcoes.some(o => o.tipo === 'retirada' || o.tipo === 'retirada_ponto');
    if (temRetirada) {
        const mapaBtn = document.createElement('button');
        mapaBtn.className = 'frete-mapa-btn';
        mapaBtn.onclick = abrirMapaPontos;
        mapaBtn.innerHTML = `
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            Ver todos os pontos de retirada no mapa
        `;
        container.appendChild(mapaBtn);
    }
}

/**
 * Selecionar opcao de frete
 */
function selecionarFrete(opcaoId) {
    // Atualizar visual
    document.querySelectorAll('.frete-opcao').forEach(el => {
        el.classList.remove('selected');
        if (el.querySelector('input').value === opcaoId) {
            el.classList.add('selected');
            el.querySelector('input').checked = true;
        }
    });

    // Guardar selecionado
    freteSelecionado = freteOpcoes.find(o => o.id === opcaoId);

    // Atualizar totais
    if (freteSelecionado && typeof atualizarTotalFrete === 'function') {
        atualizarTotalFrete(freteSelecionado);
    }

    // Atualizar orderData se existir
    if (window.orderData) {
        orderData.delivery_fee = freteSelecionado?.preco || 0;
        orderData.delivery_type = freteSelecionado?.tipo || 'standard';
        orderData.delivery_option = freteSelecionado;

        // Atualizar total
        orderData.total = orderData.subtotal + orderData.delivery_fee;

        // Atualizar display
        atualizarDisplayTotal();
    }

    console.log('Frete selecionado:', freteSelecionado);
}

/**
 * Atualizar display do total
 */
function atualizarDisplayTotal() {
    if (!window.orderData) return;

    // Atualizar na sidebar
    const deliveryEl = document.querySelector('.summary-row:nth-child(2) span:last-child');
    const totalEl = document.querySelector('.summary-row.total span:last-child');
    const mobileTotal = document.querySelector('.mobile-total-value');

    if (deliveryEl) {
        if (orderData.delivery_fee === 0) {
            deliveryEl.className = 'free-badge';
            deliveryEl.textContent = 'GRATIS';
        } else {
            deliveryEl.className = '';
            deliveryEl.textContent = 'R$ ' + orderData.delivery_fee.toFixed(2).replace('.', ',');
        }
    }

    if (totalEl) {
        totalEl.textContent = 'R$ ' + orderData.total.toFixed(2).replace('.', ',');
    }

    if (mobileTotal) {
        mobileTotal.textContent = 'R$ ' + orderData.total.toFixed(2).replace('.', ',');
    }
}

/**
 * Abrir modal do mapa com pontos de apoio
 */
async function abrirMapaPontos() {
    const modal = document.getElementById('freteMapaModal');
    const iframe = document.getElementById('freteMapaIframe');
    const lista = document.getElementById('fretePontosLista');

    modal.classList.add('show');

    // Buscar pontos
    const cep = orderData?.address?.zipcode || orderData?.address?.postcode?.replace(/\D/g, '') || '';

    try {
        const response = await fetch(`/mercado/api/frete-ponto-apoio.php?action=pontos&cep=${cep}`);
        const data = await response.json();

        if (data.success && data.pontos) {
            lista.innerHTML = data.pontos.map(p => `
                <div class="frete-ponto-item" onclick="selecionarPontoMapa(${p.id})">
                    <div class="frete-ponto-nome">${p.nome}</div>
                    <div class="frete-ponto-endereco">${p.endereco}</div>
                    <div class="frete-ponto-dist">${p.distancia_km} km de voce - ${p.horario}</div>
                </div>
            `).join('');

            // Mostrar mapa com OpenStreetMap
            if (data.pontos.length > 0) {
                const lat = data.pontos[0].lat;
                const lng = data.pontos[0].lng;
                iframe.innerHTML = `
                    <iframe
                        src="https://www.openstreetmap.org/export/embed.html?bbox=${lng-0.1}%2C${lat-0.1}%2C${lng+0.1}%2C${lat+0.1}&layer=mapnik&marker=${lat}%2C${lng}"
                        style="width: 100%; height: 300px; border: none;"
                    ></iframe>
                `;
            }
        }
    } catch (err) {
        lista.innerHTML = '<p style="color: #991B1B; text-align: center;">Erro ao carregar pontos</p>';
    }
}

/**
 * Fechar modal do mapa
 */
function fecharMapaPontos() {
    document.getElementById('freteMapaModal').classList.remove('show');
}

/**
 * Selecionar ponto no mapa
 */
function selecionarPontoMapa(pontoId) {
    const opcaoId = 'retirada_ponto_' + pontoId;

    // Verificar se essa opcao existe
    const opcao = freteOpcoes.find(o => o.id === opcaoId);
    if (opcao) {
        selecionarFrete(opcaoId);
        fecharMapaPontos();
    }
}

// Inicializar quando o DOM estiver pronto
document.addEventListener('DOMContentLoaded', function() {
    // Aguardar um pouco para garantir que orderData foi carregado
    setTimeout(() => {
        if (typeof orderData !== 'undefined' && orderData.address) {
            calcularFreteInteligente();
        }
    }, 500);
});

// Recalcular quando endereco mudar
const originalSelectAddress = window.selectAddress;
if (typeof originalSelectAddress === 'function') {
    window.selectAddress = function(el, addressId) {
        originalSelectAddress(el, addressId);
        // Aguardar atualização e recalcular
        setTimeout(calcularFreteInteligente, 300);
    };
}
</script>
