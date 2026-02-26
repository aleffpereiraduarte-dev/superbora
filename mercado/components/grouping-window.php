<?php
/**
 * ONEMUNDO - Componente de Janela de Agrupamento
 * Exibe banner flutuante para adicionar mais itens ao pedido
 */
?>
<style>
.om-grouping-banner {
    position: fixed;
    bottom: 80px;
    left: 50%;
    transform: translateX(-50%);
    max-width: 400px;
    width: calc(100% - 32px);
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    color: white;
    border-radius: 16px;
    padding: 16px 20px;
    box-shadow: 0 8px 30px rgba(79, 70, 229, 0.4);
    z-index: 9999;
    display: none;
    animation: slideUp 0.4s ease;
}

@keyframes slideUp {
    from { transform: translateX(-50%) translateY(100px); opacity: 0; }
    to { transform: translateX(-50%) translateY(0); opacity: 1; }
}

.om-grouping-banner.active { display: block; }

.om-grouping-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}

.om-grouping-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 700;
    font-size: 15px;
}

.om-grouping-title i {
    font-size: 20px;
}

.om-grouping-close {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 14px;
}

.om-grouping-timer {
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(0,0,0,0.2);
    border-radius: 12px;
    padding: 12px 16px;
    margin-bottom: 14px;
}

.om-grouping-timer-icon {
    width: 44px;
    height: 44px;
    background: rgba(255,255,255,0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}

.om-grouping-timer-text {
    flex: 1;
}

.om-grouping-timer-label {
    font-size: 12px;
    opacity: 0.9;
    margin-bottom: 2px;
}

.om-grouping-timer-value {
    font-size: 24px;
    font-weight: 800;
    font-family: 'SF Mono', monospace;
}

.om-grouping-info {
    font-size: 13px;
    line-height: 1.5;
    opacity: 0.95;
    margin-bottom: 14px;
}

.om-grouping-vendedor {
    font-weight: 700;
}

.om-grouping-stats {
    display: flex;
    gap: 16px;
    margin-bottom: 14px;
    font-size: 13px;
}

.om-grouping-stat {
    display: flex;
    align-items: center;
    gap: 6px;
}

.om-grouping-actions {
    display: flex;
    gap: 10px;
}

.om-grouping-btn {
    flex: 1;
    padding: 12px;
    border: none;
    border-radius: 10px;
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s;
}

.om-grouping-btn-add {
    background: white;
    color: #4f46e5;
}

.om-grouping-btn-add:hover {
    transform: scale(1.02);
}

.om-grouping-btn-done {
    background: rgba(255,255,255,0.2);
    color: white;
}

.om-grouping-btn-done:hover {
    background: rgba(255,255,255,0.3);
}

/* Mobile */
@media (max-width: 480px) {
    .om-grouping-banner {
        bottom: 70px;
        max-width: none;
        width: calc(100% - 24px);
        border-radius: 14px;
    }
}
</style>

<div class="om-grouping-banner" id="groupingBanner">
    <div class="om-grouping-header">
        <div class="om-grouping-title">
            <i class="fas fa-layer-group"></i>
            <span>Adicione mais itens!</span>
        </div>
        <button class="om-grouping-close" onclick="fecharJanelaAgrupamento()">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <div class="om-grouping-timer">
        <div class="om-grouping-timer-icon">
            <i class="fas fa-clock"></i>
        </div>
        <div class="om-grouping-timer-text">
            <div class="om-grouping-timer-label">Tempo restante</div>
            <div class="om-grouping-timer-value" id="groupingTimer">14:59</div>
        </div>
    </div>

    <div class="om-grouping-info">
        Compre mais produtos de <span class="om-grouping-vendedor" id="groupingVendedor">-</span> e receba tudo junto, economizando no frete!
    </div>

    <div class="om-grouping-stats">
        <div class="om-grouping-stat">
            <i class="fas fa-shopping-bag"></i>
            <span><strong id="groupingPedidos">1</strong> pedido(s)</span>
        </div>
        <div class="om-grouping-stat">
            <i class="fas fa-box"></i>
            <span><strong id="groupingItens">0</strong> item(s)</span>
        </div>
        <div class="om-grouping-stat">
            <i class="fas fa-money-bill"></i>
            <span>R$ <strong id="groupingValor">0,00</strong></span>
        </div>
    </div>

    <div class="om-grouping-actions">
        <button class="om-grouping-btn om-grouping-btn-add" onclick="adicionarMaisItens()">
            <i class="fas fa-plus"></i> Adicionar Itens
        </button>
        <button class="om-grouping-btn om-grouping-btn-done" onclick="finalizarAgrupamento()">
            <i class="fas fa-check"></i> Pronto
        </button>
    </div>
</div>

<script>
(function() {
    let janelaAtiva = null;
    let timerInterval = null;

    // Verificar se existe janela ativa
    function verificarJanelaAgrupamento() {
        fetch('/mercado/api/order-grouping.php?action=status')
            .then(r => r.json())
            .then(data => {
                if (data.success && data.tem_janela_ativa && data.janelas.length > 0) {
                    janelaAtiva = data.janelas[0];
                    mostrarBanner(janelaAtiva);
                }
            })
            .catch(err => console.log('Erro ao verificar janela:', err));
    }

    // Mostrar banner
    function mostrarBanner(janela) {
        document.getElementById('groupingVendedor').textContent = janela.vendedor_nome || 'Vendedor';
        document.getElementById('groupingPedidos').textContent = janela.total_pedidos || 1;
        document.getElementById('groupingItens').textContent = janela.total_itens || 0;
        document.getElementById('groupingValor').textContent = (janela.valor_total || 0).toFixed(2).replace('.', ',');

        iniciarTimer(janela.segundos_restantes);
        document.getElementById('groupingBanner').classList.add('active');
    }

    // Timer
    function iniciarTimer(segundos) {
        if (timerInterval) clearInterval(timerInterval);

        let remaining = segundos;

        function updateDisplay() {
            const min = Math.floor(remaining / 60);
            const sec = remaining % 60;
            document.getElementById('groupingTimer').textContent =
                String(min).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
        }

        updateDisplay();

        timerInterval = setInterval(function() {
            remaining--;
            if (remaining <= 0) {
                clearInterval(timerInterval);
                finalizarAgrupamentoAuto();
            } else {
                updateDisplay();
            }
        }, 1000);
    }

    // Adicionar mais itens
    window.adicionarMaisItens = function() {
        if (janelaAtiva && janelaAtiva.seller_id) {
            // Redirecionar para produtos do vendedor
            window.location.href = '/mercado/vendedor-loja.php?slug=' + janelaAtiva.seller_id +
                                   '&grouping=' + janelaAtiva.id;
        }
    };

    // Finalizar agrupamento
    window.finalizarAgrupamento = function() {
        if (!janelaAtiva) return;

        fetch('/mercado/api/order-grouping.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'finalizar',
                window_id: janelaAtiva.id
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                fecharJanelaAgrupamento();
                if (data.total_pedidos > 1) {
                    alert('Pedidos agrupados! Voce economizara no frete.');
                }
            }
        });
    };

    // Finalizar automaticamente quando timer acabar
    function finalizarAgrupamentoAuto() {
        console.log('Janela de agrupamento expirou');
        finalizarAgrupamento();
    }

    // Fechar banner
    window.fecharJanelaAgrupamento = function() {
        document.getElementById('groupingBanner').classList.remove('active');
        if (timerInterval) clearInterval(timerInterval);
        janelaAtiva = null;
    };

    // Criar janela apos pedido (chamar do pedido-confirmado.php)
    window.criarJanelaAgrupamento = function(orderId, sellerId) {
        fetch('/mercado/api/order-grouping.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'criar',
                order_id: orderId,
                seller_id: sellerId
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                verificarJanelaAgrupamento();
            }
        });
    };

    // Inicializar
    document.addEventListener('DOMContentLoaded', function() {
        verificarJanelaAgrupamento();
    });

    // Expor funcao globalmente
    window.verificarJanelaAgrupamento = verificarJanelaAgrupamento;
})();
</script>
