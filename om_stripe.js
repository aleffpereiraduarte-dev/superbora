/**
 * OneMundo Stripe Integration v1.0
 * Cartão de Crédito + Apple Pay + Google Pay
 * Com tokenização e cobranças adicionais
 */
(function() {
    'use strict';

    // Evitar duplicação
    if (window._OMStripe_) return;
    window._OMStripe_ = true;

    const API = '/api_stripe.php';
    let stripe = null;
    let elements = null;
    let cardElement = null;
    let paymentRequest = null;
    let config = null;

    // Estado
    const State = {
        customerId: null,
        paymentIntentId: null,
        clientSecret: null,
        savedCards: [],
        initialized: false
    };

    // ══════════════════════════════════════════════════════════════════
    // INICIALIZAÇÃO
    // ══════════════════════════════════════════════════════════════════
    async function init() {
        if (State.initialized) return;

        try {
            // Buscar configuração
            const res = await fetch(API + '?action=get_config');
            config = await res.json();

            if (!config.success || !config.publishable_key) {
                console.error('Stripe não configurado');
                return;
            }

            // Inicializar Stripe
            stripe = Stripe(config.publishable_key);
            State.initialized = true;
            console.log('Stripe inicializado');

            // Tentar criar Payment Request (Apple Pay / Google Pay)
            await initPaymentRequest();

        } catch (e) {
            console.error('Erro ao inicializar Stripe:', e);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // PAYMENT REQUEST (Apple Pay / Google Pay)
    // ══════════════════════════════════════════════════════════════════
    async function initPaymentRequest(amount = 1000, label = 'Total') {
        if (!stripe) return null;

        paymentRequest = stripe.paymentRequest({
            country: 'BR',
            currency: 'brl',
            total: {
                label: label,
                amount: amount
            },
            requestPayerName: true,
            requestPayerEmail: true
        });

        // Verificar se Apple Pay / Google Pay está disponível
        const result = await paymentRequest.canMakePayment();
        return result;
    }

    // ══════════════════════════════════════════════════════════════════
    // CRIAR ELEMENTOS DO STRIPE (formulário de cartão)
    // ══════════════════════════════════════════════════════════════════
    function createCardElement(containerId) {
        if (!stripe) {
            console.error('Stripe não inicializado');
            return null;
        }

        const container = document.getElementById(containerId);
        if (!container) {
            console.error('Container não encontrado:', containerId);
            return null;
        }

        // Criar elements
        elements = stripe.elements({
            locale: 'pt-BR',
            fonts: [
                { cssSrc: 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap' }
            ]
        });

        // Estilo do card element
        const style = {
            base: {
                color: '#1e293b',
                fontFamily: 'Inter, -apple-system, sans-serif',
                fontSmoothing: 'antialiased',
                fontSize: '16px',
                '::placeholder': {
                    color: '#94a3b8'
                }
            },
            invalid: {
                color: '#ef4444',
                iconColor: '#ef4444'
            }
        };

        // Criar card element
        cardElement = elements.create('card', {
            style: style,
            hidePostalCode: true
        });

        // Montar no container
        cardElement.mount(container);

        // Eventos
        cardElement.on('change', (event) => {
            const errorDiv = document.getElementById('stripe-card-errors');
            if (errorDiv) {
                errorDiv.textContent = event.error ? event.error.message : '';
            }
        });

        return cardElement;
    }

    // ══════════════════════════════════════════════════════════════════
    // CRIAR PAYMENT INTENT
    // ══════════════════════════════════════════════════════════════════
    async function createPaymentIntent(amount, options = {}) {
        const response = await fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'create_payment_intent',
                amount: amount,
                customer_email: options.email || '',
                customer_name: options.name || '',
                order_id: options.orderId || '',
                description: options.description || 'Pedido OneMundo'
            })
        });

        const data = await response.json();

        if (data.success) {
            State.paymentIntentId = data.payment_intent_id;
            State.clientSecret = data.client_secret;
        }

        return data;
    }

    // ══════════════════════════════════════════════════════════════════
    // PAGAR COM CARTÃO (novo cartão)
    // ══════════════════════════════════════════════════════════════════
    async function payWithCard(amount, options = {}) {
        if (!stripe || !cardElement) {
            return { success: false, error: 'Stripe não inicializado' };
        }

        try {
            // 1. Criar Payment Intent
            const intentResult = await createPaymentIntent(amount, options);
            if (!intentResult.success) {
                return intentResult;
            }

            // 2. Confirmar com o cartão
            const { error, paymentIntent } = await stripe.confirmCardPayment(
                State.clientSecret,
                {
                    payment_method: {
                        card: cardElement,
                        billing_details: {
                            name: options.name || '',
                            email: options.email || ''
                        }
                    },
                    setup_future_usage: options.saveCard ? 'off_session' : undefined
                }
            );

            if (error) {
                return {
                    success: false,
                    error: error.message,
                    code: error.code
                };
            }

            // 3. Verificar status
            if (paymentIntent.status === 'succeeded') {
                return {
                    success: true,
                    paid: true,
                    payment_intent_id: paymentIntent.id,
                    amount: paymentIntent.amount
                };
            } else if (paymentIntent.status === 'requires_action') {
                // 3D Secure necessário
                return {
                    success: true,
                    paid: false,
                    requires_action: true,
                    status: paymentIntent.status
                };
            }

            return {
                success: false,
                error: 'Status inesperado: ' + paymentIntent.status
            };

        } catch (e) {
            return { success: false, error: e.message };
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // PAGAR COM APPLE PAY / GOOGLE PAY
    // ══════════════════════════════════════════════════════════════════
    async function payWithWallet(amount, options = {}) {
        if (!stripe || !paymentRequest) {
            return { success: false, error: 'Wallet não disponível' };
        }

        return new Promise(async (resolve) => {
            // Atualizar valor
            paymentRequest.update({
                total: {
                    label: options.label || 'Total',
                    amount: Math.round(amount * 100)
                }
            });

            // Criar Payment Intent primeiro
            const intentResult = await createPaymentIntent(amount, options);
            if (!intentResult.success) {
                resolve(intentResult);
                return;
            }

            // Listener para pagamento
            paymentRequest.on('paymentmethod', async (ev) => {
                const { error, paymentIntent } = await stripe.confirmCardPayment(
                    State.clientSecret,
                    { payment_method: ev.paymentMethod.id },
                    { handleActions: false }
                );

                if (error) {
                    ev.complete('fail');
                    resolve({ success: false, error: error.message });
                    return;
                }

                ev.complete('success');

                if (paymentIntent.status === 'requires_action') {
                    const { error: actionError } = await stripe.confirmCardPayment(State.clientSecret);
                    if (actionError) {
                        resolve({ success: false, error: actionError.message });
                        return;
                    }
                }

                resolve({
                    success: true,
                    paid: true,
                    payment_intent_id: paymentIntent.id
                });
            });

            // Mostrar modal de pagamento
            paymentRequest.show();
        });
    }

    // ══════════════════════════════════════════════════════════════════
    // TOKENIZAÇÃO - SALVAR CARTÃO
    // ══════════════════════════════════════════════════════════════════
    async function saveCard(customerId) {
        if (!stripe || !cardElement) {
            return { success: false, error: 'Stripe não inicializado' };
        }

        try {
            // Criar payment method
            const { error, paymentMethod } = await stripe.createPaymentMethod({
                type: 'card',
                card: cardElement
            });

            if (error) {
                return { success: false, error: error.message };
            }

            // Salvar no backend
            const response = await fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'save_card',
                    customer_id: customerId,
                    payment_method_id: paymentMethod.id
                })
            });

            return await response.json();

        } catch (e) {
            return { success: false, error: e.message };
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // COBRAR CARTÃO SALVO (para cobranças adicionais)
    // ══════════════════════════════════════════════════════════════════
    async function chargeCard(customerId, paymentMethodId, amount, description = '') {
        const response = await fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'charge_saved_card',
                customer_id: customerId,
                payment_method_id: paymentMethodId,
                amount: amount,
                description: description || 'Cobrança adicional'
            })
        });

        return await response.json();
    }

    // ══════════════════════════════════════════════════════════════════
    // LISTAR CARTÕES SALVOS
    // ══════════════════════════════════════════════════════════════════
    async function listCards(customerId) {
        const response = await fetch(API + '?action=list_cards&customer_id=' + customerId);
        const data = await response.json();

        if (data.success) {
            State.savedCards = data.cards;
        }

        return data;
    }

    // ══════════════════════════════════════════════════════════════════
    // CRIAR/OBTER CUSTOMER
    // ══════════════════════════════════════════════════════════════════
    async function getOrCreateCustomer(email, name = '', phone = '') {
        const response = await fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'create_customer',
                email: email,
                name: name,
                phone: phone
            })
        });

        const data = await response.json();

        if (data.success) {
            State.customerId = data.customer_id;
        }

        return data;
    }

    // ══════════════════════════════════════════════════════════════════
    // REEMBOLSO (parcial ou total)
    // ══════════════════════════════════════════════════════════════════
    async function refund(paymentIntentId, amount = null, reason = 'requested_by_customer') {
        const payload = {
            action: 'refund',
            payment_intent_id: paymentIntentId,
            reason: reason
        };

        if (amount !== null) {
            payload.amount = amount; // Reembolso parcial
        }

        const response = await fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        return await response.json();
    }

    // ══════════════════════════════════════════════════════════════════
    // VERIFICAR STATUS
    // ══════════════════════════════════════════════════════════════════
    async function checkStatus(paymentIntentId) {
        const response = await fetch(API + '?action=check_status&payment_intent_id=' + paymentIntentId);
        return await response.json();
    }

    // ══════════════════════════════════════════════════════════════════
    // VERIFICAR SE APPLE PAY / GOOGLE PAY DISPONÍVEL
    // ══════════════════════════════════════════════════════════════════
    async function isWalletAvailable() {
        if (!paymentRequest) {
            await initPaymentRequest();
        }

        if (!paymentRequest) return { applePay: false, googlePay: false };

        const result = await paymentRequest.canMakePayment();
        return {
            applePay: result?.applePay || false,
            googlePay: result?.googlePay || false,
            available: !!result
        };
    }

    // ══════════════════════════════════════════════════════════════════
    // CRIAR BOTÃO APPLE PAY / GOOGLE PAY
    // ══════════════════════════════════════════════════════════════════
    function createWalletButton(containerId) {
        if (!stripe || !paymentRequest) {
            console.error('Payment Request não disponível');
            return null;
        }

        const container = document.getElementById(containerId);
        if (!container) return null;

        const prButton = elements.create('paymentRequestButton', {
            paymentRequest: paymentRequest,
            style: {
                paymentRequestButton: {
                    type: 'buy',
                    theme: 'dark',
                    height: '48px'
                }
            }
        });

        prButton.mount(container);
        return prButton;
    }

    // ══════════════════════════════════════════════════════════════════
    // EXPOR API PÚBLICA
    // ══════════════════════════════════════════════════════════════════
    window.OMStripe = {
        init,
        createCardElement,
        payWithCard,
        payWithWallet,
        saveCard,
        chargeCard,
        listCards,
        getOrCreateCustomer,
        refund,
        checkStatus,
        isWalletAvailable,
        createWalletButton,
        getState: () => ({ ...State }),
        getStripe: () => stripe
    };

    // Auto-inicializar se Stripe.js já estiver carregado
    if (typeof Stripe !== 'undefined') {
        init();
    } else {
        // Aguardar Stripe.js carregar
        window.addEventListener('load', () => {
            if (typeof Stripe !== 'undefined') {
                init();
            }
        });
    }

})();
