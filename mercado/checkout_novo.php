<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * CHECKOUT ULTRA PREMIUM v1.0
 * One-Page Checkout estilo Instacart/DoorDash com Claude AI
 * ══════════════════════════════════════════════════════════════════════════════
 */

session_name('OCSESSID');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/includes/env_loader.php';

// Conexão com banco
$pdo = null;
try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    $config_file = dirname(__DIR__) . '/config.php';
    if (file_exists($config_file)) {
        require_once $config_file;
        $pdo = new PDO(
            "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
            DB_USERNAME, DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
}

$customer_id = $_SESSION['customer_id'] ?? 0;

// Redirecionar se não logado
if (!$customer_id) {
    header('Location: /mercado/mercado-login.php?redirect=checkout_novo');
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// CARREGAR DADOS DO CLIENTE
// ══════════════════════════════════════════════════════════════════════════════

$user = [
    'id' => $customer_id,
    'name' => 'Visitante',
    'firstname' => 'Visitante',
    'lastname' => '',
    'email' => '',
    'phone' => '',
    'cpf' => '',
];

$addresses = [];
$cards = [];
$cart_items = [];
$cart_total = 0;

if ($pdo) {
    try {
        // Dados do cliente
        $stmt = $pdo->prepare("SELECT * FROM oc_customer WHERE customer_id = ?");
        $stmt->execute([$customer_id]);
        $customer = $stmt->fetch();
        if ($customer) {
            $user = [
                'id' => $customer_id,
                'name' => trim($customer['firstname'] . ' ' . $customer['lastname']),
                'firstname' => $customer['firstname'],
                'lastname' => $customer['lastname'],
                'email' => $customer['email'],
                'phone' => $customer['telephone'],
                'cpf' => $customer['custom_field'] ?? '',
            ];
        }

        // Endereços
        $stmt = $pdo->prepare("
            SELECT a.*, z.name as zone_name, c.name as country_name
            FROM oc_address a
            LEFT JOIN oc_zone z ON a.zone_id = z.zone_id
            LEFT JOIN oc_country c ON a.country_id = c.country_id
            WHERE a.customer_id = ?
            ORDER BY a.address_id DESC
        ");
        $stmt->execute([$customer_id]);
        $addresses = $stmt->fetchAll() ?: [];

        // Cartões salvos
        try {
            $stmt = $pdo->prepare("SELECT * FROM om_customer_cards WHERE customer_id = ? AND status = 'active' ORDER BY is_default DESC");
            $stmt->execute([$customer_id]);
            $cards = $stmt->fetchAll() ?: [];
        } catch (Exception $e) {}

        // Carrinho - Tentar pegar da sessão do OpenCart
        if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
            foreach ($_SESSION['cart'] as $key => $qty) {
                $product_id = (int)explode(':', $key)[0];
                $stmt = $pdo->prepare("
                    SELECT p.product_id, p.price, p.image, pd.name,
                           COALESCE(ps.price, p.price) as final_price
                    FROM oc_product p
                    JOIN oc_product_description pd ON p.product_id = pd.product_id AND pd.language_id = 2
                    LEFT JOIN oc_product_special ps ON p.product_id = ps.product_id
                        AND ps.date_start <= NOW() AND (ps.date_end >= NOW() OR ps.date_end = '0000-00-00')
                    WHERE p.product_id = ? AND p.status = 1
                ");
                $stmt->execute([$product_id]);
                $product = $stmt->fetch();
                if ($product) {
                    $product['quantity'] = (int)$qty;
                    $product['subtotal'] = $product['final_price'] * $product['quantity'];
                    $cart_items[] = $product;
                    $cart_total += $product['subtotal'];
                }
            }
        }

    } catch (Exception $e) {
        error_log("Checkout error: " . $e->getMessage());
    }
}

// Dados demo se carrinho vazio
if (empty($cart_items)) {
    $cart_items = [
        ['product_id' => 1, 'name' => 'Arroz Tio João 5kg', 'final_price' => 24.90, 'quantity' => 2, 'subtotal' => 49.80, 'image' => ''],
        ['product_id' => 2, 'name' => 'Feijão Carioca 1kg', 'final_price' => 8.99, 'quantity' => 2, 'subtotal' => 17.98, 'image' => ''],
        ['product_id' => 3, 'name' => 'Óleo de Soja 900ml', 'final_price' => 7.49, 'quantity' => 1, 'subtotal' => 7.49, 'image' => ''],
        ['product_id' => 4, 'name' => 'Açúcar Cristal 1kg', 'final_price' => 4.99, 'quantity' => 2, 'subtotal' => 9.98, 'image' => ''],
        ['product_id' => 5, 'name' => 'Leite Integral 1L', 'final_price' => 5.49, 'quantity' => 6, 'subtotal' => 32.94, 'image' => ''],
    ];
    $cart_total = array_sum(array_column($cart_items, 'subtotal'));
}

if (empty($addresses)) {
    $addresses = [
        ['address_id' => 1, 'address_1' => 'Rua das Flores, 123', 'address_2' => 'Apto 45', 'city' => 'São Paulo', 'zone_name' => 'SP', 'postcode' => '01234-567'],
    ];
}

// Calcular frete
$frete_normal = 7.99;
$frete_expresso = 14.99;
$frete_agendado = 5.99;
$frete_selecionado = $frete_normal;

// Total final
$total_final = $cart_total + $frete_selecionado;

// Emojis para produtos
$product_emojis = ['🍚', '🫘', '🫒', '🍬', '🥛', '🥖', '🍌', '🥩', '🧀', '🥚', '🍫', '☕'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Checkout - SuperBora</title>
    <meta name="theme-color" content="#059669">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/mercado/assets/css/checkout-premium.css">
</head>
<body>

<!-- Header -->
<header class="checkout-header">
    <div class="header-content">
        <a href="/mercado/carrinho.php" class="btn-back">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div class="header-title">
            <h1>Finalizar Pedido</h1>
            <p class="header-subtitle"><?= count($cart_items) ?> itens no carrinho</p>
        </div>
        <div class="header-secure">
            <i class="fas fa-shield-alt"></i>
        </div>
    </div>

    <!-- Progress Bar -->
    <div class="progress-bar">
        <div class="progress-step active" data-step="1">
            <div class="step-circle"><i class="fas fa-map-marker-alt"></i></div>
            <span>Endereço</span>
        </div>
        <div class="progress-line"></div>
        <div class="progress-step" data-step="2">
            <div class="step-circle"><i class="fas fa-truck"></i></div>
            <span>Entrega</span>
        </div>
        <div class="progress-line"></div>
        <div class="progress-step" data-step="3">
            <div class="step-circle"><i class="fas fa-credit-card"></i></div>
            <span>Pagamento</span>
        </div>
        <div class="progress-line"></div>
        <div class="progress-step" data-step="4">
            <div class="step-circle"><i class="fas fa-check"></i></div>
            <span>Revisão</span>
        </div>
    </div>
</header>

<main class="checkout-main">
    <div class="checkout-container">
        <!-- Accordion Sections -->
        <div class="checkout-sections">

            <!-- Section 1: Endereço -->
            <section class="checkout-section active" data-section="1">
                <div class="section-header" onclick="toggleSection(1)">
                    <div class="section-info">
                        <div class="section-icon"><i class="fas fa-map-marker-alt"></i></div>
                        <div class="section-title">
                            <h2>Endereço de Entrega</h2>
                            <p class="section-summary" id="address-summary">Selecione ou adicione um endereço</p>
                        </div>
                    </div>
                    <div class="section-toggle">
                        <span class="section-status"><i class="fas fa-check-circle"></i></span>
                        <i class="fas fa-chevron-down toggle-icon"></i>
                    </div>
                </div>
                <div class="section-content">
                    <div class="addresses-list" id="addresses-list">
                        <?php foreach ($addresses as $i => $addr): ?>
                        <div class="address-card <?= $i === 0 ? 'selected' : '' ?>" data-address-id="<?= $addr['address_id'] ?>" onclick="selectAddress(this)">
                            <div class="address-radio">
                                <div class="radio-dot"></div>
                            </div>
                            <div class="address-content">
                                <div class="address-tag"><?= $i === 0 ? '<i class="fas fa-home"></i> Casa' : '<i class="fas fa-briefcase"></i> Trabalho' ?></div>
                                <p class="address-line"><?= htmlspecialchars($addr['address_1']) ?></p>
                                <?php if (!empty($addr['address_2'])): ?>
                                <p class="address-complement"><?= htmlspecialchars($addr['address_2']) ?></p>
                                <?php endif; ?>
                                <p class="address-city"><?= htmlspecialchars($addr['city']) ?> - <?= htmlspecialchars($addr['zone_name'] ?? 'SP') ?>, <?= htmlspecialchars($addr['postcode'] ?? '') ?></p>
                            </div>
                            <button class="btn-edit-address" onclick="event.stopPropagation(); editAddress(<?= $addr['address_id'] ?>)">
                                <i class="fas fa-pen"></i>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <button class="btn-add-address" onclick="showAddressModal()">
                        <i class="fas fa-plus"></i>
                        Adicionar novo endereço
                    </button>

                    <button class="btn-continue" onclick="completeSection(1)">
                        Continuar para Entrega
                        <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </section>

            <!-- Section 2: Tipo de Entrega -->
            <section class="checkout-section" data-section="2">
                <div class="section-header" onclick="toggleSection(2)">
                    <div class="section-info">
                        <div class="section-icon"><i class="fas fa-truck"></i></div>
                        <div class="section-title">
                            <h2>Tipo de Entrega</h2>
                            <p class="section-summary" id="delivery-summary">Escolha o tipo de entrega</p>
                        </div>
                    </div>
                    <div class="section-toggle">
                        <span class="section-status"><i class="fas fa-check-circle"></i></span>
                        <i class="fas fa-chevron-down toggle-icon"></i>
                    </div>
                </div>
                <div class="section-content">
                    <div class="delivery-options">
                        <div class="delivery-option selected" data-delivery="normal" data-price="<?= $frete_normal ?>" onclick="selectDelivery(this)">
                            <div class="delivery-radio">
                                <div class="radio-dot"></div>
                            </div>
                            <div class="delivery-icon normal">
                                <i class="fas fa-truck"></i>
                            </div>
                            <div class="delivery-info">
                                <h3>Entrega Normal</h3>
                                <p>Chegará em 40-60 minutos</p>
                            </div>
                            <div class="delivery-price">R$ <?= number_format($frete_normal, 2, ',', '.') ?></div>
                        </div>

                        <div class="delivery-option" data-delivery="express" data-price="<?= $frete_expresso ?>" onclick="selectDelivery(this)">
                            <div class="delivery-radio">
                                <div class="radio-dot"></div>
                            </div>
                            <div class="delivery-icon express">
                                <i class="fas fa-bolt"></i>
                            </div>
                            <div class="delivery-info">
                                <h3>Entrega Expressa</h3>
                                <p>Chegará em 20-30 minutos</p>
                                <span class="delivery-badge">Mais rápida</span>
                            </div>
                            <div class="delivery-price">R$ <?= number_format($frete_expresso, 2, ',', '.') ?></div>
                        </div>

                        <div class="delivery-option" data-delivery="scheduled" data-price="<?= $frete_agendado ?>" onclick="selectDelivery(this)">
                            <div class="delivery-radio">
                                <div class="radio-dot"></div>
                            </div>
                            <div class="delivery-icon scheduled">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <div class="delivery-info">
                                <h3>Entrega Agendada</h3>
                                <p>Escolha dia e horário</p>
                                <span class="delivery-badge green">Mais econômica</span>
                            </div>
                            <div class="delivery-price">R$ <?= number_format($frete_agendado, 2, ',', '.') ?></div>
                        </div>
                    </div>

                    <!-- Schedule Picker (hidden by default) -->
                    <div class="schedule-picker" id="schedule-picker" style="display: none;">
                        <h4><i class="fas fa-calendar"></i> Escolha o horário</h4>
                        <div class="schedule-days">
                            <button class="schedule-day selected" data-date="hoje">
                                <span class="day-name">Hoje</span>
                                <span class="day-date"><?= date('d/m') ?></span>
                            </button>
                            <button class="schedule-day" data-date="amanha">
                                <span class="day-name">Amanhã</span>
                                <span class="day-date"><?= date('d/m', strtotime('+1 day')) ?></span>
                            </button>
                            <button class="schedule-day" data-date="depois">
                                <span class="day-name"><?= strftime('%a', strtotime('+2 days')) ?></span>
                                <span class="day-date"><?= date('d/m', strtotime('+2 days')) ?></span>
                            </button>
                        </div>
                        <div class="schedule-times">
                            <button class="schedule-time selected">08:00 - 10:00</button>
                            <button class="schedule-time">10:00 - 12:00</button>
                            <button class="schedule-time">12:00 - 14:00</button>
                            <button class="schedule-time">14:00 - 16:00</button>
                            <button class="schedule-time">16:00 - 18:00</button>
                            <button class="schedule-time">18:00 - 20:00</button>
                        </div>
                    </div>

                    <button class="btn-continue" onclick="completeSection(2)">
                        Continuar para Pagamento
                        <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </section>

            <!-- Section 3: Pagamento -->
            <section class="checkout-section" data-section="3">
                <div class="section-header" onclick="toggleSection(3)">
                    <div class="section-info">
                        <div class="section-icon"><i class="fas fa-credit-card"></i></div>
                        <div class="section-title">
                            <h2>Forma de Pagamento</h2>
                            <p class="section-summary" id="payment-summary">Escolha como pagar</p>
                        </div>
                    </div>
                    <div class="section-toggle">
                        <span class="section-status"><i class="fas fa-check-circle"></i></span>
                        <i class="fas fa-chevron-down toggle-icon"></i>
                    </div>
                </div>
                <div class="section-content">
                    <!-- Payment Tabs -->
                    <div class="payment-tabs">
                        <button class="payment-tab active" data-tab="pix" onclick="selectPaymentTab(this)">
                            <i class="fas fa-qrcode"></i>
                            <span>PIX</span>
                        </button>
                        <button class="payment-tab" data-tab="card" onclick="selectPaymentTab(this)">
                            <i class="fas fa-credit-card"></i>
                            <span>Cartão</span>
                        </button>
                        <button class="payment-tab" data-tab="boleto" onclick="selectPaymentTab(this)">
                            <i class="fas fa-barcode"></i>
                            <span>Boleto</span>
                        </button>
                    </div>

                    <!-- PIX Content -->
                    <div class="payment-content active" id="payment-pix">
                        <div class="pix-benefits">
                            <div class="benefit-item">
                                <i class="fas fa-bolt"></i>
                                <span>Pagamento instantâneo</span>
                            </div>
                            <div class="benefit-item">
                                <i class="fas fa-percent"></i>
                                <span>Sem taxas</span>
                            </div>
                            <div class="benefit-item">
                                <i class="fas fa-shield-alt"></i>
                                <span>100% Seguro</span>
                            </div>
                        </div>
                        <div class="pix-info">
                            <div class="pix-icon">
                                <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 512 512'%3E%3Cpath fill='%2332bcad' d='M242.4 292.5C247.8 287.1 257.1 287.1 262.5 292.5L339.5 369.5C353.7 383.7 372.6 391.5 392.6 391.5H407.7L310.6 488.6C280.3 518.1 231.1 518.1 200.8 488.6L103.3 391.2H112.6C132.6 391.2 151.5 383.4 165.7 369.2L242.4 292.5zM262.5 218.9C257.1 224.4 247.9 224.5 242.4 218.9L165.7 142.2C151.5 128 132.6 120.2 112.6 120.2H103.3L200.2 23.31C230.5-7.049 279.6-7.049 309.9 23.31L407.8 121.2H392.6C372.6 121.2 353.7 129 339.5 143.2L262.5 218.9zM112.6 142.7C126.4 142.7 139.1 148.3 149.7 158.1L226.4 234.8C233.6 241.1 243 245.6 253.5 245.6C263.1 245.6 273.4 241.1 280.6 234.8L357.3 158.1C ## 148.3 139.1 142.7 126.4 142.7H80.63L24.49 198.9C-7.969 231.3-7.969 279.5 24.49 312L80.63 368.2H112.6C126.4 368.2 139.1 362.6 149.7 352.8L226.4 276.1C240.6 261.9 266.4 261.9 280.6 276.1L357.3 352.8C368.9 364.4 383.6 370.5 399.1 368.6L339.5 369.5L431.4 369.5L487.5 313.4C519.1 280.9 519.1 232.7 487.5 200.2L431.4 144H392.6C## 162.6 368.9 144.3 357.3 158.9z'/%3E%3C/svg%3E" alt="PIX" width="60">
                            </div>
                            <p>O QR Code será gerado após confirmar o pedido</p>
                        </div>
                    </div>

                    <!-- Card Content -->
                    <div class="payment-content" id="payment-card">
                        <!-- Saved Cards -->
                        <?php if (!empty($cards)): ?>
                        <div class="saved-cards">
                            <h4>Cartões salvos</h4>
                            <?php foreach ($cards as $card): ?>
                            <div class="saved-card" onclick="selectSavedCard(this)" data-card-id="<?= $card['id'] ?>">
                                <div class="card-brand <?= strtolower($card['brand']) ?>">
                                    <i class="fab fa-cc-<?= strtolower($card['brand']) ?>"></i>
                                </div>
                                <div class="card-info">
                                    <span class="card-number">•••• •••• •••• <?= $card['last_four'] ?></span>
                                </div>
                                <div class="card-check"><i class="fas fa-check-circle"></i></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="divider"><span>ou use um novo cartão</span></div>
                        <?php endif; ?>

                        <!-- Card Form -->
                        <div class="card-form">
                            <!-- 3D Card Preview -->
                            <div class="card-preview">
                                <div class="card-3d">
                                    <div class="card-front">
                                        <div class="card-brand-logo" id="card-brand-logo"></div>
                                        <div class="card-chip"></div>
                                        <div class="card-number-display" id="card-number-display">•••• •••• •••• ••••</div>
                                        <div class="card-bottom">
                                            <div class="card-holder" id="card-holder-display">NOME DO TITULAR</div>
                                            <div class="card-expiry" id="card-expiry-display">MM/AA</div>
                                        </div>
                                    </div>
                                    <div class="card-back">
                                        <div class="card-stripe"></div>
                                        <div class="card-cvv-box">
                                            <span id="card-cvv-display">•••</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Número do cartão</label>
                                <div class="input-icon-wrapper">
                                    <input type="text" id="card-number" placeholder="0000 0000 0000 0000" maxlength="19" inputmode="numeric" autocomplete="cc-number">
                                    <div class="card-brand-indicator" id="card-brand-indicator"></div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Nome no cartão</label>
                                <input type="text" id="card-name" placeholder="Como está no cartão" autocomplete="cc-name">
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Validade</label>
                                    <input type="text" id="card-expiry" placeholder="MM/AA" maxlength="5" inputmode="numeric" autocomplete="cc-exp">
                                </div>
                                <div class="form-group">
                                    <label>CVV</label>
                                    <div class="input-icon-wrapper">
                                        <input type="text" id="card-cvv" placeholder="•••" maxlength="4" inputmode="numeric" autocomplete="cc-csc">
                                        <i class="fas fa-question-circle cvv-help" onclick="showCvvHelp()"></i>
                                    </div>
                                </div>
                            </div>

                            <!-- Installments -->
                            <div class="form-group">
                                <label>Parcelas</label>
                                <select id="installments" class="select-styled">
                                    <option value="1">1x de R$ <?= number_format($total_final, 2, ',', '.') ?> (sem juros)</option>
                                    <option value="2">2x de R$ <?= number_format($total_final / 2, 2, ',', '.') ?> (sem juros)</option>
                                    <option value="3">3x de R$ <?= number_format($total_final / 3, 2, ',', '.') ?> (sem juros)</option>
                                    <?php for ($i = 4; $i <= 12; $i++):
                                        $juros = 1 + (($i - 3) * 0.0199);
                                        $valor_parcela = ($total_final * $juros) / $i;
                                    ?>
                                    <option value="<?= $i ?>"><?= $i ?>x de R$ <?= number_format($valor_parcela, 2, ',', '.') ?> (<?= number_format(($juros - 1) * 100, 2, ',', '.') ?>% a.m.)</option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <label class="checkbox-label">
                                <input type="checkbox" id="save-card">
                                <span class="checkmark"></span>
                                Salvar cartão para próximas compras
                            </label>
                        </div>
                    </div>

                    <!-- Boleto Content -->
                    <div class="payment-content" id="payment-boleto">
                        <div class="boleto-info">
                            <div class="boleto-icon">
                                <i class="fas fa-barcode"></i>
                            </div>
                            <h4>Pague com Boleto Bancário</h4>
                            <ul class="boleto-details">
                                <li><i class="fas fa-calendar-alt"></i> Vencimento em 3 dias úteis</li>
                                <li><i class="fas fa-clock"></i> Compensação em até 2 dias</li>
                                <li><i class="fas fa-exclamation-triangle"></i> Pedido confirmado após pagamento</li>
                            </ul>
                        </div>

                        <!-- CPF para Boleto -->
                        <div class="form-group">
                            <label>CPF do pagador</label>
                            <input type="text" id="boleto-cpf" placeholder="000.000.000-00" maxlength="14" inputmode="numeric">
                        </div>
                    </div>

                    <button class="btn-continue" onclick="completeSection(3)">
                        Revisar Pedido
                        <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </section>

            <!-- Section 4: Revisão -->
            <section class="checkout-section" data-section="4">
                <div class="section-header" onclick="toggleSection(4)">
                    <div class="section-info">
                        <div class="section-icon"><i class="fas fa-clipboard-check"></i></div>
                        <div class="section-title">
                            <h2>Revisão do Pedido</h2>
                            <p class="section-summary" id="review-summary">Confira os detalhes</p>
                        </div>
                    </div>
                    <div class="section-toggle">
                        <span class="section-status"><i class="fas fa-check-circle"></i></span>
                        <i class="fas fa-chevron-down toggle-icon"></i>
                    </div>
                </div>
                <div class="section-content">
                    <!-- Order Items -->
                    <div class="review-items">
                        <h4><i class="fas fa-shopping-bag"></i> Itens do Pedido</h4>
                        <div class="items-list">
                            <?php foreach ($cart_items as $i => $item): ?>
                            <div class="review-item">
                                <div class="item-emoji"><?= $product_emojis[$i % count($product_emojis)] ?></div>
                                <div class="item-info">
                                    <span class="item-name"><?= htmlspecialchars($item['name']) ?></span>
                                    <span class="item-qty"><?= $item['quantity'] ?>x R$ <?= number_format($item['final_price'], 2, ',', '.') ?></span>
                                </div>
                                <span class="item-total">R$ <?= number_format($item['subtotal'], 2, ',', '.') ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Review Summary Cards -->
                    <div class="review-cards">
                        <div class="review-card" onclick="toggleSection(1)">
                            <div class="review-card-icon"><i class="fas fa-map-marker-alt"></i></div>
                            <div class="review-card-content">
                                <h5>Entregar em</h5>
                                <p id="review-address"><?= htmlspecialchars($addresses[0]['address_1'] ?? 'Selecione um endereço') ?></p>
                            </div>
                            <i class="fas fa-pen review-edit"></i>
                        </div>

                        <div class="review-card" onclick="toggleSection(2)">
                            <div class="review-card-icon"><i class="fas fa-truck"></i></div>
                            <div class="review-card-content">
                                <h5>Tipo de Entrega</h5>
                                <p id="review-delivery">Entrega Normal - 40-60 min</p>
                            </div>
                            <i class="fas fa-pen review-edit"></i>
                        </div>

                        <div class="review-card" onclick="toggleSection(3)">
                            <div class="review-card-icon"><i class="fas fa-credit-card"></i></div>
                            <div class="review-card-content">
                                <h5>Pagamento</h5>
                                <p id="review-payment">PIX</p>
                            </div>
                            <i class="fas fa-pen review-edit"></i>
                        </div>
                    </div>

                    <!-- Coupon -->
                    <div class="coupon-section">
                        <div class="coupon-input-wrap">
                            <i class="fas fa-ticket-alt"></i>
                            <input type="text" id="coupon-input" placeholder="Código do cupom">
                            <button class="btn-apply-coupon" onclick="applyCoupon()">Aplicar</button>
                        </div>
                    </div>

                    <!-- Totals -->
                    <div class="order-totals">
                        <div class="total-row">
                            <span>Subtotal</span>
                            <span>R$ <?= number_format($cart_total, 2, ',', '.') ?></span>
                        </div>
                        <div class="total-row">
                            <span>Entrega</span>
                            <span id="delivery-total">R$ <?= number_format($frete_selecionado, 2, ',', '.') ?></span>
                        </div>
                        <div class="total-row discount" id="discount-row" style="display: none;">
                            <span>Desconto</span>
                            <span id="discount-value">-R$ 0,00</span>
                        </div>
                        <div class="total-row final">
                            <span>Total</span>
                            <span id="final-total">R$ <?= number_format($total_final, 2, ',', '.') ?></span>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- AI Suggestion Box -->
        <div class="ai-suggestion-box" id="ai-suggestion-box">
            <div class="ai-avatar">
                <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ccircle cx='50' cy='50' r='50' fill='%2310b981'/%3E%3Ctext x='50' y='55' font-size='40' text-anchor='middle' fill='white'%3EAI%3C/text%3E%3C/svg%3E" alt="AI">
            </div>
            <div class="ai-content">
                <div class="ai-header">
                    <span class="ai-badge"><i class="fas fa-magic"></i> Sugestão Inteligente</span>
                    <button class="ai-close" onclick="hideAiSuggestion()"><i class="fas fa-times"></i></button>
                </div>
                <p class="ai-message" id="ai-message">Analisando seu carrinho...</p>
                <div class="ai-actions" id="ai-actions"></div>
            </div>
        </div>

        <!-- Sidebar Summary (Desktop) -->
        <aside class="checkout-sidebar">
            <div class="sidebar-card">
                <h3><i class="fas fa-shopping-bag"></i> Resumo do Pedido</h3>

                <div class="sidebar-items">
                    <?php foreach (array_slice($cart_items, 0, 3) as $i => $item): ?>
                    <div class="sidebar-item">
                        <span class="item-qty-badge"><?= $item['quantity'] ?>x</span>
                        <span class="item-name"><?= htmlspecialchars($item['name']) ?></span>
                        <span class="item-price">R$ <?= number_format($item['subtotal'], 2, ',', '.') ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php if (count($cart_items) > 3): ?>
                    <div class="sidebar-more">
                        + <?= count($cart_items) - 3 ?> outros itens
                    </div>
                    <?php endif; ?>
                </div>

                <div class="sidebar-totals">
                    <div class="sidebar-row">
                        <span>Subtotal</span>
                        <span>R$ <?= number_format($cart_total, 2, ',', '.') ?></span>
                    </div>
                    <div class="sidebar-row">
                        <span>Entrega</span>
                        <span id="sidebar-delivery">R$ <?= number_format($frete_selecionado, 2, ',', '.') ?></span>
                    </div>
                    <div class="sidebar-row final">
                        <span>Total</span>
                        <span id="sidebar-total">R$ <?= number_format($total_final, 2, ',', '.') ?></span>
                    </div>
                </div>

                <button class="btn-finalize" id="btn-finalize" onclick="finalizarPedido()">
                    <span class="btn-text">Finalizar Pedido</span>
                    <span class="btn-price">R$ <?= number_format($total_final, 2, ',', '.') ?></span>
                </button>

                <div class="sidebar-secure">
                    <i class="fas fa-lock"></i>
                    Pagamento 100% seguro
                </div>
            </div>
        </aside>
    </div>
</main>

<!-- Bottom Bar (Mobile) -->
<div class="checkout-bottom-bar">
    <div class="bottom-bar-info">
        <span class="bottom-total-label">Total</span>
        <span class="bottom-total-value" id="bottom-total">R$ <?= number_format($total_final, 2, ',', '.') ?></span>
    </div>
    <button class="btn-finalize-mobile" id="btn-finalize-mobile" onclick="finalizarPedido()">
        Finalizar Pedido
    </button>
</div>

<!-- Modal: Novo Endereço -->
<div class="modal-overlay" id="address-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-map-marker-alt"></i> Novo Endereço</h3>
            <button class="modal-close" onclick="hideAddressModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>CEP</label>
                <div class="input-icon-wrapper">
                    <input type="text" id="new-cep" placeholder="00000-000" maxlength="9" inputmode="numeric">
                    <div class="input-loader" id="cep-loader"><i class="fas fa-spinner fa-spin"></i></div>
                </div>
            </div>
            <div class="form-group">
                <label>Rua</label>
                <input type="text" id="new-rua" placeholder="Nome da rua">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Número</label>
                    <input type="text" id="new-numero" placeholder="123">
                </div>
                <div class="form-group">
                    <label>Complemento</label>
                    <input type="text" id="new-complemento" placeholder="Apto, Bloco...">
                </div>
            </div>
            <div class="form-group">
                <label>Bairro</label>
                <input type="text" id="new-bairro" placeholder="Bairro">
            </div>
            <div class="form-row">
                <div class="form-group flex-2">
                    <label>Cidade</label>
                    <input type="text" id="new-cidade" placeholder="Cidade">
                </div>
                <div class="form-group flex-1">
                    <label>Estado</label>
                    <select id="new-estado" class="select-styled">
                        <option value="">UF</option>
                        <option value="SP">SP</option>
                        <option value="RJ">RJ</option>
                        <option value="MG">MG</option>
                        <option value="PR">PR</option>
                        <option value="RS">RS</option>
                        <option value="SC">SC</option>
                        <option value="BA">BA</option>
                        <option value="PE">PE</option>
                        <option value="CE">CE</option>
                        <option value="GO">GO</option>
                        <option value="DF">DF</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" onclick="hideAddressModal()">Cancelar</button>
            <button class="btn-primary" onclick="saveNewAddress()">Salvar Endereço</button>
        </div>
    </div>
</div>

<!-- Modal: Pagamento PIX -->
<div class="modal-overlay" id="pix-modal">
    <div class="modal-content modal-pix">
        <div class="modal-header">
            <h3><i class="fas fa-qrcode"></i> Pagamento via PIX</h3>
            <button class="modal-close" onclick="hidePixModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="pix-qr-container">
                <div class="pix-qr-loading" id="pix-qr-loading">
                    <div class="qr-skeleton"></div>
                    <p>Gerando QR Code...</p>
                </div>
                <div class="pix-qr-ready" id="pix-qr-ready" style="display: none;">
                    <img id="pix-qr-image" src="" alt="QR Code PIX">
                    <div class="pix-timer">
                        <i class="fas fa-clock"></i>
                        <span>Expira em <strong id="pix-timer">15:00</strong></span>
                    </div>
                    <div class="pix-progress">
                        <div class="pix-progress-bar" id="pix-progress-bar"></div>
                    </div>
                </div>
            </div>
            <div class="pix-code-section">
                <label>Código PIX Copia e Cola</label>
                <div class="pix-code-box">
                    <input type="text" id="pix-code" readonly value="">
                    <button class="btn-copy" onclick="copyPixCode()">
                        <i class="fas fa-copy"></i>
                        Copiar
                    </button>
                </div>
            </div>
            <div class="pix-instructions">
                <h4>Como pagar:</h4>
                <ol>
                    <li>Abra o app do seu banco</li>
                    <li>Escolha pagar com PIX</li>
                    <li>Escaneie o QR Code ou cole o código</li>
                    <li>Confirme o pagamento</li>
                </ol>
            </div>
        </div>
        <div class="modal-footer">
            <p class="pix-status" id="pix-status">
                <i class="fas fa-spinner fa-spin"></i>
                Aguardando pagamento...
            </p>
        </div>
    </div>
</div>

<!-- Modal: Sucesso -->
<div class="modal-overlay" id="success-modal">
    <div class="modal-content modal-success">
        <div class="success-animation">
            <div class="checkmark-circle">
                <div class="checkmark draw"></div>
            </div>
            <div class="confetti-container" id="confetti-container"></div>
        </div>
        <h2>Pedido Confirmado!</h2>
        <p class="order-number">Pedido <strong id="order-number">#123456</strong></p>
        <p class="success-message">Seu pedido foi recebido e está sendo preparado!</p>
        <div class="success-eta">
            <i class="fas fa-clock"></i>
            <span>Previsão de entrega: <strong id="eta-time">40-60 min</strong></span>
        </div>
        <div class="success-actions">
            <a href="/mercado/acompanhar-pedido.php" id="tracking-link" class="btn-primary">
                <i class="fas fa-truck"></i>
                Acompanhar em Tempo Real
            </a>
            <a href="/mercado/" class="btn-secondary">
                Continuar Comprando
            </a>
        </div>
        <p class="tracking-hint">
            <i class="fas fa-info-circle"></i>
            Voce pode adicionar mais itens ate o shopper iniciar as compras!
        </p>
    </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<!-- JavaScript Data -->
<script>
const checkoutData = {
    customerId: <?= $customer_id ?>,
    cartTotal: <?= $cart_total ?>,
    deliveryFee: <?= $frete_selecionado ?>,
    total: <?= $total_final ?>,
    items: <?= json_encode(array_map(function($item) {
        return [
            'id' => $item['product_id'],
            'name' => $item['name'],
            'price' => $item['final_price'],
            'quantity' => $item['quantity']
        ];
    }, $cart_items)) ?>,
    customer: <?= json_encode([
        'name' => $user['name'],
        'email' => $user['email'],
        'phone' => $user['phone'],
        'cpf' => $user['cpf']
    ]) ?>,
    address: <?= !empty($addresses) ? json_encode($addresses[0]) : 'null' ?>
};
</script>
<script src="/mercado/assets/js/checkout-premium.js"></script>

</body>
</html>
