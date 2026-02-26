/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ORDER TRACKING JS - Acompanhamento em Tempo Real
 * ══════════════════════════════════════════════════════════════════════════════
 */

let addedItemsCount = 0;
let productsCache = [];
let chatMessages = [];

// ═══════════════════════════════════════════════════════════════════════════════
// INITIALIZATION
// ═══════════════════════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function() {
    // Start polling for status updates
    if (!['delivered', 'cancelled'].includes(orderData.status)) {
        startStatusPolling();
    }

    // Load AI suggestion
    loadAISuggestion();

    // Load products for add items modal
    if (orderData.canAddItems) {
        loadProducts();
    }
});

// ═══════════════════════════════════════════════════════════════════════════════
// STATUS POLLING
// ═══════════════════════════════════════════════════════════════════════════════

function startStatusPolling() {
    setInterval(async () => {
        try {
            const response = await fetch(`/mercado/api/pedido.php?action=status&order_id=${orderData.orderId}`);
            const data = await response.json();

            if (data.success && data.status !== orderData.status) {
                // Status changed, reload page
                location.reload();
            }

            // Also check if can still add items
            if (orderData.canAddItems) {
                const canAddResponse = await fetch(`/mercado/api/pedido.php?action=can_add_items&order_id=${orderData.orderId}`);
                const canAddData = await canAddResponse.json();

                if (!canAddData.can_add && orderData.canAddItems) {
                    // Can't add items anymore, reload
                    location.reload();
                }
            }
        } catch (error) {
            console.error('Polling error:', error);
        }
    }, 10000); // Poll every 10 seconds
}

// ═══════════════════════════════════════════════════════════════════════════════
// AI SUGGESTIONS
// ═══════════════════════════════════════════════════════════════════════════════

async function loadAISuggestion() {
    const aiMessage = document.getElementById('ai-message');

    try {
        const response = await fetch('/mercado/api/checkout-ai.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'tracking_suggestion',
                order_id: orderData.orderId,
                status: orderData.status,
                can_add_items: orderData.canAddItems,
                scan_progress: orderData.scanProgress
            })
        });

        const data = await response.json();

        if (data.success && data.suggestion) {
            aiMessage.textContent = data.suggestion;
        } else {
            aiMessage.textContent = getDefaultSuggestion();
        }
    } catch (error) {
        aiMessage.textContent = getDefaultSuggestion();
    }
}

function getDefaultSuggestion() {
    const suggestions = {
        pending: 'Estamos buscando o melhor shopper para seu pedido. Isso leva apenas alguns instantes!',
        confirmed: 'Seu shopper está a caminho do mercado. Você ainda pode adicionar itens ao pedido!',
        accepted: 'Shopper aceitou! Se lembrar de algo, adicione agora antes das compras começarem.',
        shopping: orderData.scanProgress < 30
            ? 'O shopper está começando as compras. Ainda dá tempo de adicionar mais itens!'
            : 'Seu pedido está sendo separado com todo cuidado. Logo estará pronto!',
        packing: 'Finalizando a separação. Seu pedido logo sairá para entrega!',
        ready: 'Tudo pronto! Um entregador será designado em instantes.',
        delivering: 'Seu pedido está a caminho! Fique atento ao interfone.',
        delivered: 'Pedido entregue! Que tal avaliar sua experiência?'
    };

    return suggestions[orderData.status] || 'Acompanhe seu pedido em tempo real.';
}

// ═══════════════════════════════════════════════════════════════════════════════
// ADD ITEMS MODAL
// ═══════════════════════════════════════════════════════════════════════════════

function showAddItemsModal() {
    document.getElementById('add-items-modal').classList.add('active');
}

function hideAddItemsModal() {
    document.getElementById('add-items-modal').classList.remove('active');
}

async function loadProducts(categoryId = 0, search = '') {
    const grid = document.getElementById('products-grid');
    grid.innerHTML = '<div class="loading-products"><i class="fas fa-spinner fa-spin"></i><p>Carregando produtos...</p></div>';

    try {
        let url = `/mercado/api/products.php?partner_id=${orderData.partnerId}&limit=20`;
        if (categoryId) url += `&category_id=${categoryId}`;
        if (search) url += `&search=${encodeURIComponent(search)}`;

        const response = await fetch(url);
        const data = await response.json();

        if (data.success && data.products) {
            productsCache = data.products;
            renderProducts(data.products);
        } else {
            grid.innerHTML = '<div class="loading-products"><p>Nenhum produto encontrado</p></div>';
        }
    } catch (error) {
        // Use demo products
        productsCache = getDemoProducts();
        renderProducts(productsCache);
    }
}

function getDemoProducts() {
    return [
        { product_id: 1, name: 'Pão Francês', price: 0.50, emoji: '🥖' },
        { product_id: 2, name: 'Leite Integral 1L', price: 5.49, emoji: '🥛' },
        { product_id: 3, name: 'Banana Prata kg', price: 5.99, emoji: '🍌' },
        { product_id: 4, name: 'Café 500g', price: 15.90, emoji: '☕' },
        { product_id: 5, name: 'Açúcar 1kg', price: 4.99, emoji: '🍬' },
        { product_id: 6, name: 'Arroz 5kg', price: 24.90, emoji: '🍚' },
        { product_id: 7, name: 'Feijão 1kg', price: 8.99, emoji: '🫘' },
        { product_id: 8, name: 'Óleo 900ml', price: 7.49, emoji: '🫒' },
        { product_id: 9, name: 'Maçã kg', price: 8.99, emoji: '🍎' },
        { product_id: 10, name: 'Queijo Mussarela', price: 39.90, emoji: '🧀' },
        { product_id: 11, name: 'Presunto', price: 29.90, emoji: '🥩' },
        { product_id: 12, name: 'Ovos 12un', price: 12.99, emoji: '🥚' }
    ];
}

function renderProducts(products) {
    const grid = document.getElementById('products-grid');
    const emojis = ['🍚', '🫘', '🥛', '🥖', '🍎', '🥩', '🧀', '🥚', '🍌', '🥤', '☕', '🧈'];

    grid.innerHTML = products.map((product, i) => `
        <div class="product-card-mini" id="product-${product.product_id}">
            <div class="product-emoji">${product.emoji || emojis[i % emojis.length]}</div>
            <div class="product-name">${product.name}</div>
            <div class="product-price">R$ ${parseFloat(product.price).toFixed(2).replace('.', ',')}</div>
            <button class="btn-add-mini" onclick="addItemToOrder(${product.product_id}, '${product.name.replace(/'/g, "\\'")}', ${product.price})">
                <i class="fas fa-plus"></i> Adicionar
            </button>
        </div>
    `).join('');
}

function searchProducts(query) {
    clearTimeout(window.searchTimeout);
    window.searchTimeout = setTimeout(() => {
        if (query.length >= 2) {
            loadProducts(0, query);
        } else if (query.length === 0) {
            loadProducts(0);
        }
    }, 300);
}

function filterCategory(categoryId, btn) {
    document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    loadProducts(categoryId);
}

async function addItemToOrder(productId, productName, price) {
    const card = document.getElementById(`product-${productId}`);
    const btn = card.querySelector('.btn-add-mini');

    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;

    try {
        const response = await fetch('/mercado/api/pedido.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'add_item',
                order_id: orderData.orderId,
                product_id: productId,
                quantity: 1
            })
        });

        const data = await response.json();

        if (data.success) {
            addedItemsCount++;
            card.classList.add('added');
            btn.innerHTML = '<i class="fas fa-check"></i> Adicionado';

            document.getElementById('added-count').innerHTML = `<span>${addedItemsCount} ${addedItemsCount === 1 ? 'item adicionado' : 'itens adicionados'}</span>`;

            showToast(`${productName} adicionado ao pedido!`, 'success');

            // Update total
            if (data.new_total) {
                orderData.total = data.new_total;
            }
        } else {
            showToast(data.error || 'Erro ao adicionar item', 'error');
            btn.innerHTML = '<i class="fas fa-plus"></i> Adicionar';
            btn.disabled = false;
        }
    } catch (error) {
        showToast('Erro de conexão', 'error');
        btn.innerHTML = '<i class="fas fa-plus"></i> Adicionar';
        btn.disabled = false;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// CHAT
// ═══════════════════════════════════════════════════════════════════════════════

function openChat() {
    document.getElementById('chat-modal').classList.add('active');
    loadChatMessages();
}

function closeChatModal() {
    document.getElementById('chat-modal').classList.remove('active');
}

async function loadChatMessages() {
    const container = document.getElementById('chat-messages');

    try {
        const response = await fetch(`/mercado/api/chat.php?order_id=${orderData.orderId}`);
        const data = await response.json();

        if (data.success && data.messages) {
            chatMessages = data.messages;
            renderChatMessages(data.messages);
        } else {
            container.innerHTML = '<div class="chat-message system">Inicie uma conversa com o shopper</div>';
        }
    } catch (error) {
        container.innerHTML = '<div class="chat-message system">Chat indisponível</div>';
    }
}

function renderChatMessages(messages) {
    const container = document.getElementById('chat-messages');

    container.innerHTML = messages.map(msg => {
        const type = msg.remetente_tipo === 'customer' ? 'sent' :
                     msg.remetente_tipo === 'system' ? 'system' : 'received';
        return `<div class="chat-message ${type}">${msg.mensagem}</div>`;
    }).join('');

    container.scrollTop = container.scrollHeight;
}

async function sendMessage() {
    const input = document.getElementById('chat-input');
    const message = input.value.trim();

    if (!message) return;

    input.value = '';

    // Add message locally
    const container = document.getElementById('chat-messages');
    container.innerHTML += `<div class="chat-message sent">${message}</div>`;
    container.scrollTop = container.scrollHeight;

    try {
        await fetch('/mercado/api/chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                order_id: orderData.orderId,
                remetente_tipo: 'customer',
                remetente_id: orderData.customerId,
                mensagem: message
            })
        });
    } catch (error) {
        showToast('Erro ao enviar mensagem', 'error');
    }
}

// Send on Enter
document.getElementById('chat-input')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        sendMessage();
    }
});

// ═══════════════════════════════════════════════════════════════════════════════
// ACTIONS
// ═══════════════════════════════════════════════════════════════════════════════

async function cancelOrder() {
    if (!confirm('Tem certeza que deseja cancelar este pedido?')) return;

    try {
        const response = await fetch('/mercado/api/cancel.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'cancel',
                order_id: orderData.orderId,
                reason: 'changed_mind',
                cancelled_by: 'customer'
            })
        });

        const data = await response.json();

        if (data.success) {
            showToast('Pedido cancelado', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(data.error || 'Erro ao cancelar', 'error');
        }
    } catch (error) {
        showToast('Erro de conexão', 'error');
    }
}

function shareOrder() {
    const text = `Meu pedido #${orderData.orderNumber} do SuperBora está ${getStatusText(orderData.status)}!`;
    const url = window.location.href;

    if (navigator.share) {
        navigator.share({ title: 'Meu Pedido - SuperBora', text, url });
    } else {
        navigator.clipboard.writeText(url);
        showToast('Link copiado!', 'success');
    }
}

function getStatusText(status) {
    const texts = {
        pending: 'sendo processado',
        confirmed: 'confirmado',
        shopping: 'sendo preparado',
        ready: 'pronto para entrega',
        delivering: 'a caminho',
        delivered: 'entregue'
    };
    return texts[status] || status;
}

async function reorder() {
    showToast('Adicionando itens ao carrinho...', '');

    try {
        const response = await fetch(`/mercado/api/orders.php?action=reorder&order_id=${orderData.orderId}`);
        const data = await response.json();

        if (data.success) {
            showToast('Itens adicionados ao carrinho!', 'success');
            setTimeout(() => {
                window.location.href = '/mercado/carrinho.php';
            }, 1000);
        } else {
            showToast(data.error || 'Erro ao repetir pedido', 'error');
        }
    } catch (error) {
        showToast('Erro de conexão', 'error');
    }
}

function showHelp() {
    window.location.href = '/mercado/suporte.php?order_id=' + orderData.orderId;
}

// ═══════════════════════════════════════════════════════════════════════════════
// TOAST
// ═══════════════════════════════════════════════════════════════════════════════

function showToast(message, type = '') {
    const toast = document.getElementById('toast');
    const icons = {
        success: 'check-circle',
        error: 'exclamation-circle',
        '': 'info-circle'
    };

    toast.innerHTML = `<i class="fas fa-${icons[type]}"></i> ${message}`;
    toast.className = 'toast show ' + type;

    setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}

// ═══════════════════════════════════════════════════════════════════════════════
// MODAL CLOSE ON OVERLAY
// ═══════════════════════════════════════════════════════════════════════════════

document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
        }
    });
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
    }
});
