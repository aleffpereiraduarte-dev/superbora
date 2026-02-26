/**
 * ══════════════════════════════════════════════════════════════════════════════
 * CHECKOUT PREMIUM JS v1.0
 * One-Page Checkout com Claude AI Integration
 * ══════════════════════════════════════════════════════════════════════════════
 */

// ═══════════════════════════════════════════════════════════════════════════════
// STATE MANAGEMENT
// ═══════════════════════════════════════════════════════════════════════════════

const checkoutState = {
    currentSection: 1,
    completedSections: [],
    selectedAddress: null,
    selectedDelivery: 'normal',
    deliveryPrice: checkoutData.deliveryFee,
    selectedPayment: 'pix',
    cardData: {},
    couponApplied: null,
    discountAmount: 0,
    total: checkoutData.total
};

// ═══════════════════════════════════════════════════════════════════════════════
// INITIALIZATION
// ═══════════════════════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function() {
    initCheckout();
    initCardForm();
    initMasks();
    loadAISuggestion();
});

function initCheckout() {
    // Auto-select first address
    const firstAddress = document.querySelector('.address-card');
    if (firstAddress) {
        selectAddress(firstAddress);
    }

    // Auto-select first delivery
    const firstDelivery = document.querySelector('.delivery-option');
    if (firstDelivery) {
        selectDelivery(firstDelivery);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// ACCORDION SECTIONS
// ═══════════════════════════════════════════════════════════════════════════════

function toggleSection(sectionNum) {
    const sections = document.querySelectorAll('.checkout-section');
    const targetSection = document.querySelector(`[data-section="${sectionNum}"]`);

    if (!targetSection) return;

    // If section is completed or current, toggle it
    if (checkoutState.completedSections.includes(sectionNum) || sectionNum <= checkoutState.currentSection) {
        sections.forEach(s => {
            if (s.dataset.section == sectionNum) {
                s.classList.toggle('active');
            } else {
                s.classList.remove('active');
            }
        });

        // Update progress bar
        updateProgressBar(sectionNum);
    }
}

function completeSection(sectionNum) {
    const section = document.querySelector(`[data-section="${sectionNum}"]`);

    // Validate section before completing
    if (!validateSection(sectionNum)) {
        return;
    }

    // Mark as completed
    section.classList.remove('active');
    section.classList.add('completed');

    if (!checkoutState.completedSections.includes(sectionNum)) {
        checkoutState.completedSections.push(sectionNum);
    }

    // Update summary
    updateSectionSummary(sectionNum);

    // Open next section
    const nextSection = sectionNum + 1;
    if (nextSection <= 4) {
        checkoutState.currentSection = nextSection;
        const nextEl = document.querySelector(`[data-section="${nextSection}"]`);
        if (nextEl) {
            nextEl.classList.add('active');

            // Scroll to section
            setTimeout(() => {
                nextEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 300);
        }
    }

    // Update progress bar
    updateProgressBar(nextSection);

    // Show AI suggestion on section 3 completion
    if (sectionNum === 3) {
        showPaymentAISuggestion();
    }
}

function validateSection(sectionNum) {
    switch (sectionNum) {
        case 1:
            if (!checkoutState.selectedAddress) {
                showToast('Selecione um endereço de entrega', 'error');
                return false;
            }
            return true;

        case 2:
            if (!checkoutState.selectedDelivery) {
                showToast('Selecione o tipo de entrega', 'error');
                return false;
            }
            return true;

        case 3:
            return validatePayment();

        case 4:
            return true;

        default:
            return true;
    }
}

function validatePayment() {
    const paymentMethod = checkoutState.selectedPayment;

    if (paymentMethod === 'card') {
        const cardNumber = document.getElementById('card-number').value.replace(/\s/g, '');
        const cardName = document.getElementById('card-name').value;
        const cardExpiry = document.getElementById('card-expiry').value;
        const cardCvv = document.getElementById('card-cvv').value;

        if (cardNumber.length < 16) {
            showToast('Número do cartão inválido', 'error');
            document.getElementById('card-number').focus();
            return false;
        }

        if (cardName.length < 3) {
            showToast('Nome no cartão inválido', 'error');
            document.getElementById('card-name').focus();
            return false;
        }

        if (cardExpiry.length < 5) {
            showToast('Validade inválida', 'error');
            document.getElementById('card-expiry').focus();
            return false;
        }

        if (cardCvv.length < 3) {
            showToast('CVV inválido', 'error');
            document.getElementById('card-cvv').focus();
            return false;
        }

        // Store card data
        checkoutState.cardData = {
            number: cardNumber,
            name: cardName,
            expiry: cardExpiry,
            cvv: cardCvv,
            installments: document.getElementById('installments').value
        };
    }

    if (paymentMethod === 'boleto') {
        const cpf = document.getElementById('boleto-cpf').value.replace(/\D/g, '');
        if (cpf.length !== 11 || !validateCPF(cpf)) {
            showToast('CPF inválido', 'error');
            document.getElementById('boleto-cpf').focus();
            return false;
        }
    }

    return true;
}

function updateSectionSummary(sectionNum) {
    switch (sectionNum) {
        case 1:
            const selectedAddr = document.querySelector('.address-card.selected');
            if (selectedAddr) {
                const addressLine = selectedAddr.querySelector('.address-line').textContent;
                document.getElementById('address-summary').textContent = addressLine;
                document.getElementById('review-address').textContent = addressLine;
            }
            break;

        case 2:
            const selectedDel = document.querySelector('.delivery-option.selected');
            if (selectedDel) {
                const deliveryName = selectedDel.querySelector('h3').textContent;
                const deliveryTime = selectedDel.querySelector('p').textContent;
                document.getElementById('delivery-summary').textContent = `${deliveryName} - ${deliveryTime}`;
                document.getElementById('review-delivery').textContent = `${deliveryName} - ${deliveryTime}`;
            }
            break;

        case 3:
            const paymentNames = {
                pix: 'PIX',
                card: 'Cartão de Crédito',
                boleto: 'Boleto Bancário'
            };
            document.getElementById('payment-summary').textContent = paymentNames[checkoutState.selectedPayment];
            document.getElementById('review-payment').textContent = paymentNames[checkoutState.selectedPayment];
            break;
    }
}

function updateProgressBar(activeStep) {
    const steps = document.querySelectorAll('.progress-step');
    const lines = document.querySelectorAll('.progress-line');

    steps.forEach((step, index) => {
        const stepNum = index + 1;

        step.classList.remove('active', 'completed');

        if (checkoutState.completedSections.includes(stepNum)) {
            step.classList.add('completed');
        } else if (stepNum === activeStep) {
            step.classList.add('active');
        }
    });

    lines.forEach((line, index) => {
        if (checkoutState.completedSections.includes(index + 1)) {
            line.classList.add('completed');
        } else {
            line.classList.remove('completed');
        }
    });
}

// ═══════════════════════════════════════════════════════════════════════════════
// ADDRESS SELECTION
// ═══════════════════════════════════════════════════════════════════════════════

function selectAddress(element) {
    document.querySelectorAll('.address-card').forEach(card => {
        card.classList.remove('selected');
    });
    element.classList.add('selected');
    checkoutState.selectedAddress = element.dataset.addressId;
}

function showAddressModal() {
    document.getElementById('address-modal').classList.add('active');
}

function hideAddressModal() {
    document.getElementById('address-modal').classList.remove('active');
}

function editAddress(addressId) {
    showToast('Edição de endereço em breve!', 'info');
}

function saveNewAddress() {
    const cep = document.getElementById('new-cep').value;
    const rua = document.getElementById('new-rua').value;
    const numero = document.getElementById('new-numero').value;
    const bairro = document.getElementById('new-bairro').value;
    const cidade = document.getElementById('new-cidade').value;
    const estado = document.getElementById('new-estado').value;

    if (!cep || !rua || !numero || !bairro || !cidade || !estado) {
        showToast('Preencha todos os campos obrigatórios', 'error');
        return;
    }

    // Create new address card
    const addressList = document.getElementById('addresses-list');
    const newCard = document.createElement('div');
    newCard.className = 'address-card selected';
    newCard.dataset.addressId = 'new-' + Date.now();
    newCard.onclick = function() { selectAddress(this); };
    newCard.innerHTML = `
        <div class="address-radio">
            <div class="radio-dot"></div>
        </div>
        <div class="address-content">
            <div class="address-tag"><i class="fas fa-map-marker-alt"></i> Novo</div>
            <p class="address-line">${rua}, ${numero}</p>
            <p class="address-city">${cidade} - ${estado}, ${cep}</p>
        </div>
        <button class="btn-edit-address" onclick="event.stopPropagation(); editAddress('new')">
            <i class="fas fa-pen"></i>
        </button>
    `;

    // Unselect other addresses
    document.querySelectorAll('.address-card').forEach(card => {
        card.classList.remove('selected');
    });

    addressList.insertBefore(newCard, addressList.firstChild);
    checkoutState.selectedAddress = newCard.dataset.addressId;

    hideAddressModal();
    showToast('Endereço adicionado!', 'success');
}

// ═══════════════════════════════════════════════════════════════════════════════
// DELIVERY SELECTION
// ═══════════════════════════════════════════════════════════════════════════════

function selectDelivery(element) {
    document.querySelectorAll('.delivery-option').forEach(opt => {
        opt.classList.remove('selected');
    });
    element.classList.add('selected');

    checkoutState.selectedDelivery = element.dataset.delivery;
    checkoutState.deliveryPrice = parseFloat(element.dataset.price);

    // Show/hide schedule picker
    const schedulePicker = document.getElementById('schedule-picker');
    if (checkoutState.selectedDelivery === 'scheduled') {
        schedulePicker.style.display = 'block';
    } else {
        schedulePicker.style.display = 'none';
    }

    // Update totals
    updateTotals();
}

function selectScheduleDay(element) {
    document.querySelectorAll('.schedule-day').forEach(d => d.classList.remove('selected'));
    element.classList.add('selected');
}

function selectScheduleTime(element) {
    document.querySelectorAll('.schedule-time').forEach(t => t.classList.remove('selected'));
    element.classList.add('selected');
}

// Initialize schedule buttons
document.querySelectorAll('.schedule-day').forEach(btn => {
    btn.addEventListener('click', function() { selectScheduleDay(this); });
});

document.querySelectorAll('.schedule-time').forEach(btn => {
    btn.addEventListener('click', function() { selectScheduleTime(this); });
});

// ═══════════════════════════════════════════════════════════════════════════════
// PAYMENT SELECTION
// ═══════════════════════════════════════════════════════════════════════════════

function selectPaymentTab(element) {
    const tab = element.dataset.tab;

    document.querySelectorAll('.payment-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.payment-content').forEach(c => c.classList.remove('active'));

    element.classList.add('active');
    document.getElementById('payment-' + tab).classList.add('active');

    checkoutState.selectedPayment = tab;
}

function selectSavedCard(element) {
    document.querySelectorAll('.saved-card').forEach(c => c.classList.remove('selected'));
    element.classList.add('selected');
}

// ═══════════════════════════════════════════════════════════════════════════════
// CARD FORM (3D EFFECT)
// ═══════════════════════════════════════════════════════════════════════════════

function initCardForm() {
    const cardNumber = document.getElementById('card-number');
    const cardName = document.getElementById('card-name');
    const cardExpiry = document.getElementById('card-expiry');
    const cardCvv = document.getElementById('card-cvv');
    const card3d = document.querySelector('.card-3d');

    if (cardNumber) {
        cardNumber.addEventListener('input', function() {
            const value = this.value.replace(/\s/g, '');
            document.getElementById('card-number-display').textContent =
                value.padEnd(16, '•').replace(/(.{4})/g, '$1 ').trim();

            // Detect brand
            detectCardBrand(value);
        });

        cardNumber.addEventListener('focus', () => card3d?.classList.remove('flipped'));
    }

    if (cardName) {
        cardName.addEventListener('input', function() {
            document.getElementById('card-holder-display').textContent =
                this.value.toUpperCase() || 'NOME DO TITULAR';
        });

        cardName.addEventListener('focus', () => card3d?.classList.remove('flipped'));
    }

    if (cardExpiry) {
        cardExpiry.addEventListener('input', function() {
            document.getElementById('card-expiry-display').textContent =
                this.value || 'MM/AA';
        });

        cardExpiry.addEventListener('focus', () => card3d?.classList.remove('flipped'));
    }

    if (cardCvv) {
        cardCvv.addEventListener('input', function() {
            document.getElementById('card-cvv-display').textContent =
                this.value || '•••';
        });

        cardCvv.addEventListener('focus', () => card3d?.classList.add('flipped'));
        cardCvv.addEventListener('blur', () => card3d?.classList.remove('flipped'));
    }
}

function detectCardBrand(number) {
    const brandIndicator = document.getElementById('card-brand-indicator');
    const brandLogo = document.getElementById('card-brand-logo');
    let brand = '';
    let brandClass = '';

    if (/^4/.test(number)) {
        brand = '<i class="fab fa-cc-visa"></i>';
        brandClass = 'visa';
    } else if (/^5[1-5]/.test(number) || /^2[2-7]/.test(number)) {
        brand = '<i class="fab fa-cc-mastercard"></i>';
        brandClass = 'mastercard';
    } else if (/^3[47]/.test(number)) {
        brand = '<i class="fab fa-cc-amex"></i>';
        brandClass = 'amex';
    } else if (/^(636368|438935|504175|451416|636297|5067|4576|4011|506699)/.test(number)) {
        brand = '<span style="font-size:14px;font-weight:bold;">ELO</span>';
        brandClass = 'elo';
    } else if (/^(6011|65|64[4-9]|622)/.test(number)) {
        brand = '<i class="fab fa-cc-discover"></i>';
        brandClass = 'discover';
    }

    if (brandIndicator) brandIndicator.innerHTML = brand;
    if (brandLogo) brandLogo.innerHTML = brand;
}

function showCvvHelp() {
    showToast('O CVV são os 3 dígitos no verso do cartão', 'info');
}

// ═══════════════════════════════════════════════════════════════════════════════
// INPUT MASKS
// ═══════════════════════════════════════════════════════════════════════════════

function initMasks() {
    // Card number mask
    const cardNumber = document.getElementById('card-number');
    if (cardNumber) {
        cardNumber.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            value = value.replace(/(.{4})/g, '$1 ').trim();
            e.target.value = value.substring(0, 19);
        });
    }

    // Card expiry mask
    const cardExpiry = document.getElementById('card-expiry');
    if (cardExpiry) {
        cardExpiry.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length >= 2) {
                value = value.substring(0, 2) + '/' + value.substring(2);
            }
            e.target.value = value.substring(0, 5);
        });
    }

    // CPF mask
    const cpfInputs = document.querySelectorAll('#boleto-cpf');
    cpfInputs.forEach(input => {
        input.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            value = value.replace(/(\d{3})(\d)/, '$1.$2');
            value = value.replace(/(\d{3})(\d)/, '$1.$2');
            value = value.replace(/(\d{3})(\d{1,2})/, '$1-$2');
            e.target.value = value.substring(0, 14);
        });
    });

    // CEP mask with autocomplete
    const cepInput = document.getElementById('new-cep');
    if (cepInput) {
        cepInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            value = value.replace(/(\d{5})(\d)/, '$1-$2');
            e.target.value = value.substring(0, 9);

            // Fetch address if CEP is complete
            if (value.replace(/\D/g, '').length === 8) {
                fetchAddressByCep(value.replace(/\D/g, ''));
            }
        });
    }
}

async function fetchAddressByCep(cep) {
    const loader = document.getElementById('cep-loader');
    if (loader) loader.classList.add('active');

    try {
        const response = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
        const data = await response.json();

        if (!data.erro) {
            document.getElementById('new-rua').value = data.logradouro || '';
            document.getElementById('new-bairro').value = data.bairro || '';
            document.getElementById('new-cidade').value = data.localidade || '';
            document.getElementById('new-estado').value = data.uf || '';
            document.getElementById('new-numero').focus();
        }
    } catch (error) {
        console.error('Erro ao buscar CEP:', error);
    } finally {
        if (loader) loader.classList.remove('active');
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// CPF VALIDATION
// ═══════════════════════════════════════════════════════════════════════════════

function validateCPF(cpf) {
    cpf = cpf.replace(/\D/g, '');

    if (cpf.length !== 11 || /^(\d)\1+$/.test(cpf)) {
        return false;
    }

    let sum = 0;
    for (let i = 0; i < 9; i++) {
        sum += parseInt(cpf.charAt(i)) * (10 - i);
    }
    let check = 11 - (sum % 11);
    if (check === 10 || check === 11) check = 0;
    if (check !== parseInt(cpf.charAt(9))) return false;

    sum = 0;
    for (let i = 0; i < 10; i++) {
        sum += parseInt(cpf.charAt(i)) * (11 - i);
    }
    check = 11 - (sum % 11);
    if (check === 10 || check === 11) check = 0;
    if (check !== parseInt(cpf.charAt(10))) return false;

    return true;
}

// ═══════════════════════════════════════════════════════════════════════════════
// COUPON
// ═══════════════════════════════════════════════════════════════════════════════

function applyCoupon() {
    const couponInput = document.getElementById('coupon-input');
    const code = couponInput.value.trim().toUpperCase();

    if (!code) {
        showToast('Digite um código de cupom', 'error');
        return;
    }

    // Simulate coupon validation
    const validCoupons = {
        'FRETEGRATIS': { type: 'delivery', discount: checkoutState.deliveryPrice },
        'SUPER15': { type: 'percent', discount: 15 },
        'HORTI10': { type: 'fixed', discount: 10 },
        'PRIMEIRA': { type: 'percent', discount: 20 }
    };

    if (validCoupons[code]) {
        const coupon = validCoupons[code];
        checkoutState.couponApplied = code;

        if (coupon.type === 'delivery') {
            checkoutState.discountAmount = coupon.discount;
        } else if (coupon.type === 'percent') {
            checkoutState.discountAmount = checkoutData.cartTotal * (coupon.discount / 100);
        } else {
            checkoutState.discountAmount = coupon.discount;
        }

        updateTotals();
        document.getElementById('discount-row').style.display = 'flex';
        document.getElementById('discount-value').textContent = `-R$ ${checkoutState.discountAmount.toFixed(2).replace('.', ',')}`;

        showToast(`Cupom ${code} aplicado!`, 'success');
        couponInput.disabled = true;
    } else {
        showToast('Cupom inválido', 'error');
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// TOTALS
// ═══════════════════════════════════════════════════════════════════════════════

function updateTotals() {
    const subtotal = checkoutData.cartTotal;
    const delivery = checkoutState.deliveryPrice;
    const discount = checkoutState.discountAmount;
    const total = subtotal + delivery - discount;

    checkoutState.total = total;

    // Update all total displays
    const totalStr = `R$ ${total.toFixed(2).replace('.', ',')}`;
    const deliveryStr = `R$ ${delivery.toFixed(2).replace('.', ',')}`;

    document.getElementById('delivery-total').textContent = deliveryStr;
    document.getElementById('final-total').textContent = totalStr;
    document.getElementById('bottom-total').textContent = totalStr;

    const sidebarDelivery = document.getElementById('sidebar-delivery');
    const sidebarTotal = document.getElementById('sidebar-total');
    if (sidebarDelivery) sidebarDelivery.textContent = deliveryStr;
    if (sidebarTotal) sidebarTotal.textContent = totalStr;

    // Update button price
    const btnPrice = document.querySelector('.btn-price');
    if (btnPrice) btnPrice.textContent = totalStr;

    // Update installments
    updateInstallments();
}

function updateInstallments() {
    const select = document.getElementById('installments');
    if (!select) return;

    const total = checkoutState.total;
    select.innerHTML = '';

    for (let i = 1; i <= 12; i++) {
        let valor = total;
        let juros = '';

        if (i > 3) {
            const taxaJuros = 1 + ((i - 3) * 0.0199);
            valor = (total * taxaJuros) / i;
            juros = ` (${((taxaJuros - 1) * 100).toFixed(2).replace('.', ',')}% a.m.)`;
        } else {
            valor = total / i;
            juros = ' (sem juros)';
        }

        const option = document.createElement('option');
        option.value = i;
        option.textContent = `${i}x de R$ ${valor.toFixed(2).replace('.', ',')}${juros}`;
        select.appendChild(option);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// FINALIZAR PEDIDO
// ═══════════════════════════════════════════════════════════════════════════════

async function finalizarPedido() {
    // Validate all sections
    for (let i = 1; i <= 3; i++) {
        if (!checkoutState.completedSections.includes(i)) {
            if (!validateSection(i)) {
                toggleSection(i);
                return;
            }
        }
    }

    // Disable buttons
    const btnDesktop = document.getElementById('btn-finalize');
    const btnMobile = document.getElementById('btn-finalize-mobile');

    if (btnDesktop) {
        btnDesktop.disabled = true;
        btnDesktop.innerHTML = '<span class="btn-text"><i class="fas fa-spinner fa-spin"></i> Processando...</span>';
    }
    if (btnMobile) {
        btnMobile.disabled = true;
        btnMobile.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';
    }

    try {
        // Process payment based on selected method
        if (checkoutState.selectedPayment === 'pix') {
            await processPixPayment();
        } else if (checkoutState.selectedPayment === 'card') {
            await processCardPayment();
        } else if (checkoutState.selectedPayment === 'boleto') {
            await processBoletoPayment();
        }
    } catch (error) {
        console.error('Erro no checkout:', error);
        showToast('Erro ao processar pedido. Tente novamente.', 'error');

        // Re-enable buttons
        if (btnDesktop) {
            btnDesktop.disabled = false;
            btnDesktop.innerHTML = `<span class="btn-text">Finalizar Pedido</span><span class="btn-price">R$ ${checkoutState.total.toFixed(2).replace('.', ',')}</span>`;
        }
        if (btnMobile) {
            btnMobile.disabled = false;
            btnMobile.innerHTML = 'Finalizar Pedido';
        }
    }
}

async function processPixPayment() {
    // Show PIX modal
    showPixModal();

    // Call API to generate PIX
    const response = await fetch('/mercado/api/checkout.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'pix',
            nome: checkoutData.customer.name,
            email: checkoutData.customer.email,
            cpf: checkoutData.customer.cpf || '00000000000',
            telefone: checkoutData.customer.phone,
            valor: checkoutState.total,
            items: JSON.stringify(checkoutData.items)
        })
    });

    const data = await response.json();

    if (data.success) {
        // Show QR Code
        document.getElementById('pix-qr-loading').style.display = 'none';
        document.getElementById('pix-qr-ready').style.display = 'block';

        if (data.qr_code_url) {
            document.getElementById('pix-qr-image').src = data.qr_code_url;
        } else {
            // Generate QR code from text
            document.getElementById('pix-qr-image').src = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(data.qr_code)}`;
        }

        document.getElementById('pix-code').value = data.qr_code || '';

        // Start timer
        startPixTimer(data.charge_id);
    } else {
        hidePixModal();
        showToast(data.error || 'Erro ao gerar PIX', 'error');
    }
}

async function processCardPayment() {
    const response = await fetch('/mercado/api/checkout.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'cartao',
            nome: checkoutData.customer.name,
            email: checkoutData.customer.email,
            cpf: checkoutData.customer.cpf || '00000000000',
            telefone: checkoutData.customer.phone,
            valor: checkoutState.total,
            card_number: checkoutState.cardData.number,
            card_name: checkoutState.cardData.name,
            card_exp_month: checkoutState.cardData.expiry.split('/')[0],
            card_exp_year: '20' + checkoutState.cardData.expiry.split('/')[1],
            card_cvv: checkoutState.cardData.cvv,
            parcelas: checkoutState.cardData.installments,
            items: JSON.stringify(checkoutData.items)
        })
    });

    const data = await response.json();

    if (data.success && (data.status === 'paid' || data.status === 'authorized')) {
        showSuccessModal(data.order_id || 'MKT' + Date.now());
    } else {
        showToast(data.error || 'Pagamento recusado', 'error');
        throw new Error(data.error);
    }
}

async function processBoletoPayment() {
    const cpf = document.getElementById('boleto-cpf').value.replace(/\D/g, '');

    const response = await fetch('/mercado/api/checkout.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'boleto',
            nome: checkoutData.customer.name,
            email: checkoutData.customer.email,
            cpf: cpf,
            telefone: checkoutData.customer.phone,
            valor: checkoutState.total,
            items: JSON.stringify(checkoutData.items)
        })
    });

    const data = await response.json();

    if (data.success) {
        // Open boleto URL
        if (data.boleto_url) {
            window.open(data.boleto_url, '_blank');
        }
        showSuccessModal(data.order_id || 'MKT' + Date.now());
    } else {
        showToast(data.error || 'Erro ao gerar boleto', 'error');
        throw new Error(data.error);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// PIX MODAL
// ═══════════════════════════════════════════════════════════════════════════════

function showPixModal() {
    document.getElementById('pix-modal').classList.add('active');
    document.getElementById('pix-qr-loading').style.display = 'block';
    document.getElementById('pix-qr-ready').style.display = 'none';
}

function hidePixModal() {
    document.getElementById('pix-modal').classList.remove('active');
    if (window.pixCheckInterval) {
        clearInterval(window.pixCheckInterval);
    }
    if (window.pixTimerInterval) {
        clearInterval(window.pixTimerInterval);
    }
}

function copyPixCode() {
    const code = document.getElementById('pix-code').value;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(code);
        showToast('Código PIX copiado!', 'success');
    }
}

function startPixTimer(chargeId) {
    let timeLeft = 15 * 60; // 15 minutes
    const timerDisplay = document.getElementById('pix-timer');
    const progressBar = document.getElementById('pix-progress-bar');
    const totalTime = timeLeft;

    // Timer countdown
    window.pixTimerInterval = setInterval(() => {
        timeLeft--;
        const minutes = Math.floor(timeLeft / 60);
        const seconds = timeLeft % 60;
        timerDisplay.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;

        const progress = (timeLeft / totalTime) * 100;
        progressBar.style.width = progress + '%';

        if (timeLeft <= 0) {
            clearInterval(window.pixTimerInterval);
            hidePixModal();
            showToast('PIX expirado. Gere um novo.', 'error');
        }
    }, 1000);

    // Poll for payment status
    window.pixCheckInterval = setInterval(async () => {
        try {
            const response = await fetch(`/mercado/api/checkout.php?action=check&charge_id=${chargeId}`);
            const data = await response.json();

            if (data.status === 'paid') {
                clearInterval(window.pixCheckInterval);
                clearInterval(window.pixTimerInterval);

                document.getElementById('pix-status').className = 'pix-status success';
                document.getElementById('pix-status').innerHTML = '<i class="fas fa-check-circle"></i> Pagamento confirmado!';

                setTimeout(() => {
                    hidePixModal();
                    showSuccessModal(data.order_id || chargeId);
                }, 1500);
            }
        } catch (error) {
            console.error('Erro ao verificar pagamento:', error);
        }
    }, 3000);
}

// ═══════════════════════════════════════════════════════════════════════════════
// SUCCESS MODAL
// ═══════════════════════════════════════════════════════════════════════════════

function showSuccessModal(orderId) {
    document.getElementById('order-number').textContent = '#' + orderId;
    document.getElementById('success-modal').classList.add('active');

    // Update tracking link with order ID
    const trackingLink = document.getElementById('tracking-link');
    if (trackingLink) {
        trackingLink.href = '/mercado/acompanhar-pedido.php?id=' + orderId;
    }

    // Create confetti
    createConfetti();

    // Clear cart
    fetch('/mercado/api/cart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'clear' })
    }).catch(e => console.log(e));

    // Auto-redirect to tracking after 5 seconds
    setTimeout(() => {
        window.location.href = '/mercado/acompanhar-pedido.php?id=' + orderId;
    }, 5000);
}

function createConfetti() {
    const container = document.getElementById('confetti-container');
    const colors = ['#10b981', '#f59e0b', '#3b82f6', '#ec4899', '#8b5cf6', '#22c55e'];

    for (let i = 0; i < 50; i++) {
        const confetti = document.createElement('div');
        confetti.className = 'confetti';
        confetti.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
        confetti.style.left = (Math.random() * 200 - 100) + 'px';
        confetti.style.animationDelay = (Math.random() * 0.5) + 's';
        confetti.style.animationDuration = (0.5 + Math.random() * 0.5) + 's';
        container.appendChild(confetti);
    }

    // Clean up after animation
    setTimeout(() => {
        container.innerHTML = '';
    }, 2000);
}

// ═══════════════════════════════════════════════════════════════════════════════
// AI SUGGESTIONS
// ═══════════════════════════════════════════════════════════════════════════════

async function loadAISuggestion() {
    const aiBox = document.getElementById('ai-suggestion-box');
    const aiMessage = document.getElementById('ai-message');
    const aiActions = document.getElementById('ai-actions');

    try {
        const response = await fetch('/mercado/api/checkout-ai.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'cart_suggestion',
                items: checkoutData.items,
                total: checkoutData.cartTotal
            })
        });

        const data = await response.json();

        if (data.success && data.suggestion) {
            aiMessage.textContent = data.suggestion;

            if (data.product) {
                aiActions.innerHTML = `
                    <button class="ai-btn-primary" onclick="addSuggestedProduct(${data.product.id})">
                        <i class="fas fa-plus"></i> Adicionar R$ ${data.product.price.toFixed(2).replace('.', ',')}
                    </button>
                    <button class="ai-btn-secondary" onclick="hideAiSuggestion()">
                        Não, obrigado
                    </button>
                `;
            }
        } else {
            // Default suggestion
            aiMessage.textContent = getDefaultSuggestion();
        }
    } catch (error) {
        aiMessage.textContent = getDefaultSuggestion();
    }
}

function getDefaultSuggestion() {
    const suggestions = [
        'Sabia que PIX é o método mais rápido? Seu pedido é processado instantaneamente!',
        'Para pedidos acima de R$150, considere frete agendado para economizar!',
        'Comprando produtos congelados? Recomendo entrega expressa para manter a qualidade.',
        'Seu carrinho está quase completo! Falta algo para a semana?'
    ];
    return suggestions[Math.floor(Math.random() * suggestions.length)];
}

function showPaymentAISuggestion() {
    const aiMessage = document.getElementById('ai-message');
    const aiActions = document.getElementById('ai-actions');

    if (checkoutState.selectedPayment === 'pix') {
        aiMessage.textContent = 'Excelente escolha! PIX é instantâneo e sem taxas. Seu pedido será processado assim que o pagamento for confirmado.';
    } else if (checkoutState.selectedPayment === 'card') {
        aiMessage.textContent = 'Parcelamento sem juros em até 3x! Quer trocar para PIX e ter o pedido processado ainda mais rápido?';
    } else {
        aiMessage.textContent = 'Boleto leva até 2 dias para compensar. Prefere PIX para receber mais rápido?';
    }

    aiActions.innerHTML = '';
}

function hideAiSuggestion() {
    document.getElementById('ai-suggestion-box').classList.add('hidden');
}

async function addSuggestedProduct(productId) {
    try {
        const response = await fetch('/mercado/api/cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'add', product_id: productId, qty: 1 })
        });

        const data = await response.json();

        if (data.success) {
            showToast('Produto adicionado ao carrinho!', 'success');
            hideAiSuggestion();

            // Reload page to update cart
            setTimeout(() => location.reload(), 1000);
        }
    } catch (error) {
        showToast('Erro ao adicionar produto', 'error');
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// TOAST NOTIFICATIONS
// ═══════════════════════════════════════════════════════════════════════════════

function showToast(message, type = '') {
    const toast = document.getElementById('toast');
    const icons = {
        success: 'check-circle',
        error: 'exclamation-circle',
        info: 'info-circle',
        '': 'info-circle'
    };

    toast.innerHTML = `<i class="fas fa-${icons[type]}"></i> ${message}`;
    toast.className = 'toast show ' + type;

    setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}

// ═══════════════════════════════════════════════════════════════════════════════
// MODAL CLOSE ON OVERLAY CLICK
// ═══════════════════════════════════════════════════════════════════════════════

document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
        }
    });
});

// Close modals with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(modal => {
            modal.classList.remove('active');
        });
    }
});
