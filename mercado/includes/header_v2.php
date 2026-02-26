<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║  HEADER PREMIUM ONEMUNDO - COM SELETOR DE ENDEREÇO ESTILO AMAZON           ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */

$customer_id = $_SESSION['customer_id'] ?? 0;
$customer_name = '';
$cart_count = 0;
$endereco_atual = null;
$tempo_estimado = '25-35';
$mercado_disponivel = true;

if ($customer_id && isset($pdo)) {
    // Nome do cliente
    $stmt = $pdo->prepare("SELECT firstname FROM oc_customer WHERE customer_id = ?");
    $stmt->execute([$customer_id]);
    $c = $stmt->fetch();
    if ($c) $customer_name = $c['firstname'];
    
    // Endereço atual
    $endereco_id = $_SESSION['endereco_entrega_id'] ?? 0;
    
    if ($endereco_id) {
        $stmt = $pdo->prepare("SELECT * FROM oc_address WHERE address_id = ? AND customer_id = ?");
        $stmt->execute([$endereco_id, $customer_id]);
        $endereco_atual = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Se não tem endereço selecionado, pegar o padrão
    if (!$endereco_atual) {
        $stmt = $pdo->prepare("
            SELECT a.* FROM oc_address a
            JOIN oc_customer c ON c.address_id = a.address_id
            WHERE c.customer_id = ?
        ");
        $stmt->execute([$customer_id]);
        $endereco_atual = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Qualquer endereço
    if (!$endereco_atual) {
        $stmt = $pdo->prepare("SELECT * FROM oc_address WHERE customer_id = ? LIMIT 1");
        $stmt->execute([$customer_id]);
        $endereco_atual = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Verificar tempo estimado do mercado
    if (isset($_SESSION['mercado_entrega']['tempo'])) {
        $tempo_estimado = $_SESSION['mercado_entrega']['tempo'];
    }
}

// Carrinho
$cart = $_SESSION['market_cart'] ?? [];
foreach ($cart as $item) $cart_count += (int)($item['qty'] ?? 0);

// Texto do endereço
$endereco_texto = 'Informe seu CEP';
if ($endereco_atual) {
    $endereco_texto = $endereco_atual['address_1'] ?? 'Selecionar';
    if (strlen($endereco_texto) > 25) {
        $endereco_texto = substr($endereco_texto, 0, 22) . '...';
    }
} elseif ($customer_id) {
    $endereco_texto = 'Adicionar endereço';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= $page_title ?? 'OneMundo Mercado' ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/mercado/assets/css/om-premium.css">
    <link rel="stylesheet" href="/mercado/assets/css/mobile-responsive-fixes.css">
    <style>
        :root {
            --primary: #00AA5B;
            --primary-dark: #008F4C;
            --primary-light: #E6F7EE;
            --dark: #1A1D1F;
            --gray-600: #6F767E;
            --gray-100: #F4F4F4;
            --white: #FFFFFF;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, sans-serif;
            background: var(--gray-100);
            -webkit-font-smoothing: antialiased;
        }
        
        /* Header Container */
        .om-header-new {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: var(--white);
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        
        /* Topbar Verde - Endereço */
        .om-topbar-new {
            background: linear-gradient(135deg, var(--primary) 0%, #00CC6A 100%);
            padding: 10px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: white;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .om-topbar-new:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
        }
        
        .om-topbar-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
            min-width: 0;
        }
        
        .om-topbar-icon {
            width: 36px;
            height: 36px;
            background: rgba(255,255,255,0.2);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .om-topbar-icon svg {
            width: 20px;
            height: 20px;
        }
        
        .om-topbar-content {
            min-width: 0;
            flex: 1;
        }
        
        .om-topbar-label {
            font-size: 11px;
            opacity: 0.9;
            line-height: 1;
            margin-bottom: 2px;
        }
        
        .om-topbar-address {
            font-size: 14px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .om-topbar-arrow {
            width: 14px;
            height: 14px;
            flex-shrink: 0;
            opacity: 0.8;
        }
        
        .om-topbar-time {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
            background: rgba(255,255,255,0.2);
            padding: 6px 12px;
            border-radius: 20px;
            flex-shrink: 0;
        }
        
        .om-topbar-time svg {
            width: 16px;
            height: 16px;
        }
        
        /* Header Row */
        .om-header-row-new {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
        }
        
        /* Logo */
        .om-logo-new {
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            flex-shrink: 0;
        }
        
        .om-logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary) 0%, #00CC6A 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }
        
        .om-logo-text {
            font-size: 18px;
            font-weight: 800;
            color: var(--dark);
        }
        
        @media (max-width: 480px) {
            .om-logo-text { display: none; }
        }
        
        /* Search */
        .om-search-new {
            flex: 1;
            display: flex;
            align-items: center;
            background: var(--gray-100);
            border-radius: 12px;
            padding: 0 14px;
            height: 44px;
            gap: 10px;
        }
        
        .om-search-new svg {
            width: 20px;
            height: 20px;
            color: var(--gray-600);
            flex-shrink: 0;
        }
        
        .om-search-new input {
            flex: 1;
            border: none;
            background: none;
            font-size: 15px;
            outline: none;
            font-family: inherit;
            min-width: 0;
        }
        
        .om-search-new input::placeholder {
            color: var(--gray-600);
        }
        
        /* User */
        .om-user-new {
            text-decoration: none;
            flex-shrink: 0;
        }
        
        .om-user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary) 0%, #00CC6A 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 16px;
        }
        
        .om-user-guest {
            width: 40px;
            height: 40px;
            background: var(--gray-100);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .om-user-guest svg {
            width: 22px;
            height: 22px;
            color: var(--gray-600);
        }
        
        /* Cart */
        .om-cart-new {
            position: relative;
            width: 40px;
            height: 40px;
            background: var(--gray-100);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            flex-shrink: 0;
            transition: background 0.2s;
        }
        
        .om-cart-new:hover {
            background: var(--primary-light);
        }
        
        .om-cart-new svg {
            width: 22px;
            height: 22px;
            color: var(--dark);
        }
        
        .om-cart-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: #FF5722;
            color: white;
            font-size: 11px;
            font-weight: 700;
            min-width: 20px;
            height: 20px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
        }
        
        /* Modal de Endereços */
        .endereco-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 10000;
            display: none;
            align-items: flex-start;
            justify-content: center;
            padding-top: 60px;
            opacity: 0;
            transition: opacity 0.2s;
        }
        
        .endereco-modal-overlay.active {
            display: flex;
            opacity: 1;
        }
        
        .endereco-modal {
            background: white;
            border-radius: 20px;
            width: 100%;
            max-width: 420px;
            max-height: 75vh;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            transform: translateY(-20px);
            transition: transform 0.2s;
            margin: 0 16px;
        }
        
        .endereco-modal-overlay.active .endereco-modal {
            transform: translateY(0);
        }
        
        .endereco-modal-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .endereco-modal-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark);
        }
        
        .endereco-modal-close {
            width: 36px;
            height: 36px;
            border: none;
            background: var(--gray-100);
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }
        
        .endereco-modal-close:hover {
            background: #eee;
        }
        
        .endereco-modal-body {
            padding: 16px 20px;
            max-height: 55vh;
            overflow-y: auto;
        }
        
        .endereco-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 16px;
            border: 2px solid #eee;
            border-radius: 14px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .endereco-item:hover {
            border-color: #ccc;
            background: #fafafa;
        }
        
        .endereco-item.selected {
            border-color: var(--primary);
            background: var(--primary-light);
        }
        
        .endereco-item.indisponivel {
            opacity: 0.5;
        }
        
        .endereco-radio {
            width: 22px;
            height: 22px;
            border: 2px solid #ccc;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 2px;
            transition: all 0.2s;
        }
        
        .endereco-item.selected .endereco-radio {
            border-color: var(--primary);
        }
        
        .endereco-item.selected .endereco-radio::after {
            content: '';
            width: 12px;
            height: 12px;
            background: var(--primary);
            border-radius: 50%;
        }
        
        .endereco-item-info {
            flex: 1;
            min-width: 0;
        }
        
        .endereco-item-nome {
            font-size: 15px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .endereco-item-texto {
            font-size: 13px;
            color: var(--gray-600);
            line-height: 1.5;
        }
        
        .endereco-badge {
            font-size: 10px;
            padding: 3px 8px;
            border-radius: 6px;
            font-weight: 600;
        }
        
        .endereco-badge.disponivel {
            background: var(--primary-light);
            color: var(--primary);
        }
        
        .endereco-badge.indisponivel {
            background: #ffeaea;
            color: #dc3545;
        }
        
        .endereco-add-new {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            border: 2px dashed #ccc;
            border-radius: 14px;
            cursor: pointer;
            transition: all 0.2s;
            color: var(--gray-600);
        }
        
        .endereco-add-new:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }
        
        .endereco-add-icon {
            width: 28px;
            height: 28px;
        }
        
        /* CEP Input para não logados */
        .cep-input-wrapper {
            padding: 20px;
        }
        
        .cep-input-wrapper p {
            margin-bottom: 16px;
            font-size: 14px;
            color: var(--gray-600);
            line-height: 1.5;
        }
        
        .cep-input-group {
            display: flex;
            gap: 10px;
        }
        
        .cep-input {
            flex: 1;
            padding: 14px 18px;
            border: 2px solid #eee;
            border-radius: 12px;
            font-size: 16px;
            outline: none;
            transition: border-color 0.2s;
            font-family: inherit;
        }
        
        .cep-input:focus {
            border-color: var(--primary);
        }
        
        .cep-btn {
            padding: 14px 24px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
            font-family: inherit;
        }
        
        .cep-btn:hover {
            background: var(--primary-dark);
        }
        
        .cep-result {
            margin-top: 16px;
            padding: 14px;
            border-radius: 12px;
            font-size: 14px;
            display: none;
            line-height: 1.5;
        }
        
        .cep-result.success {
            display: block;
            background: var(--primary-light);
            color: var(--primary-dark);
        }
        
        .cep-result.error {
            display: block;
            background: #ffeaea;
            color: #dc3545;
        }
    </style>
</head>
<body>

<header class="om-header-new">
    <!-- Topbar Verde - Endereço -->
    <div class="om-topbar-new" onclick="abrirModalEndereco()">
        <div class="om-topbar-left">
            <div class="om-topbar-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                    <circle cx="12" cy="10" r="3"/>
                </svg>
            </div>
            <div class="om-topbar-content">
                <div class="om-topbar-label">Entregar em</div>
                <div class="om-topbar-address">
                    <?= htmlspecialchars($endereco_texto) ?>
                    <svg class="om-topbar-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </div>
            </div>
        </div>
        <div class="om-topbar-time">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="12 6 12 12 16 14"/>
            </svg>
            <?= $tempo_estimado ?> min
        </div>
    </div>
    
    <!-- Header Row -->
    <div class="om-header-row-new">
        <a href="/mercado/" class="om-logo-new">
            <div class="om-logo-icon">🛒</div>
            <span class="om-logo-text">Mercado</span>
        </a>
        
        <form action="/mercado/busca.php" method="GET" class="om-search-new">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" name="q" placeholder="Buscar produtos...">
        </form>
        
        <?php if ($customer_id): ?>
        <a href="/mercado/conta.php" class="om-user-new">
            <div class="om-user-avatar"><?= strtoupper(substr($customer_name ?: 'U', 0, 1)) ?></div>
        </a>
        <?php else: ?>
        <a href="/mercado/mercado-login.php" class="om-user-new">
            <div class="om-user-guest">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
            </div>
        </a>
        <?php endif; ?>
        
        <a href="/mercado/carrinho.php" class="om-cart-new">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                <line x1="3" y1="6" x2="21" y2="6"/>
                <path d="M16 10a4 4 0 0 1-8 0"/>
            </svg>
            <?php if ($cart_count > 0): ?>
            <span class="om-cart-badge"><?= $cart_count ?></span>
            <?php endif; ?>
        </a>
    </div>
</header>

<!-- Modal de Endereços -->
<div class="endereco-modal-overlay" id="endereco-modal" onclick="if(event.target === this) fecharModalEndereco()">
    <div class="endereco-modal">
        <div class="endereco-modal-header">
            <span class="endereco-modal-title">
                <?= $customer_id ? 'Escolha o endereço' : 'Onde você está?' ?>
            </span>
            <button class="endereco-modal-close" onclick="fecharModalEndereco()">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        
        <?php if ($customer_id): ?>
            <div class="endereco-modal-body" id="lista-enderecos">
                <div style="text-align: center; padding: 30px; color: var(--gray-600);">
                    <div style="font-size: 24px; margin-bottom: 10px;">⏳</div>
                    Carregando endereços...
                </div>
            </div>
        <?php else: ?>
            <div class="cep-input-wrapper">
                <p>
                    Digite seu CEP para verificar se entregamos na sua região
                </p>
                <div class="cep-input-group">
                    <input type="text" class="cep-input" id="cep-input" placeholder="00000-000" maxlength="9" 
                           oninput="formatarCEP(this)" onkeypress="if(event.key==='Enter'){event.preventDefault();verificarCEP();}">
                    <button class="cep-btn" onclick="verificarCEP()">Verificar</button>
                </div>
                <div class="cep-result" id="cep-result"></div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
const enderecoModal = document.getElementById('endereco-modal');
let enderecosCarregados = false;

function abrirModalEndereco() {
    enderecoModal.classList.add('active');
    document.body.style.overflow = 'hidden';
    
    <?php if ($customer_id): ?>
    if (!enderecosCarregados) {
        carregarEnderecos();
    }
    <?php endif; ?>
}

function fecharModalEndereco() {
    enderecoModal.classList.remove('active');
    document.body.style.overflow = '';
}

<?php if ($customer_id): ?>
async function carregarEnderecos() {
    try {
        const res = await fetch('/mercado/api/localizacao.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'listar_enderecos' })
        });
        
        const data = await res.json();
        enderecosCarregados = true;
        
        if (data.success && data.enderecos && data.enderecos.length > 0) {
            renderizarEnderecos(data.enderecos);
        } else {
            document.getElementById('lista-enderecos').innerHTML = `
                <div class="endereco-add-new" onclick="window.location.href='/mercado/conta.php?tab=endereco'">
                    <svg class="endereco-add-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="16"/>
                        <line x1="8" y1="12" x2="16" y2="12"/>
                    </svg>
                    <span style="font-weight: 600;">Adicionar novo endereço</span>
                </div>
            `;
        }
    } catch (err) {
        console.error('Erro ao carregar endereços:', err);
        document.getElementById('lista-enderecos').innerHTML = `
            <div style="text-align: center; padding: 30px; color: #dc3545;">
                Erro ao carregar endereços. Tente novamente.
            </div>
        `;
    }
}

function renderizarEnderecos(enderecos) {
    const container = document.getElementById('lista-enderecos');
    const enderecoSelecionado = <?= json_encode($_SESSION['endereco_entrega_id'] ?? ($endereco_atual['address_id'] ?? 0)) ?>;
    
    let html = '';
    
    enderecos.forEach(e => {
        const isSelected = e.id == enderecoSelecionado;
        const classes = ['endereco-item'];
        if (isSelected) classes.push('selected');
        if (!e.disponivel) classes.push('indisponivel');
        
        html += `
            <div class="${classes.join(' ')}" onclick="selecionarEndereco(${e.id}, ${e.disponivel})" data-id="${e.id}">
                <div class="endereco-radio"></div>
                <div class="endereco-item-info">
                    <div class="endereco-item-nome">
                        ${e.nome || 'Meu endereço'}
                        ${e.is_default ? '<span class="endereco-badge disponivel">Padrão</span>' : ''}
                        ${e.disponivel ? '' : '<span class="endereco-badge indisponivel">Fora da área</span>'}
                    </div>
                    <div class="endereco-item-texto">
                        ${e.resumo}<br>
                        CEP: ${e.cep}
                    </div>
                </div>
            </div>
        `;
    });
    
    html += `
        <div class="endereco-add-new" onclick="window.location.href='/mercado/conta.php?tab=endereco'">
            <svg class="endereco-add-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="16"/>
                <line x1="8" y1="12" x2="16" y2="12"/>
            </svg>
            <span style="font-weight: 600;">Adicionar novo endereço</span>
        </div>
    `;
    
    container.innerHTML = html;
}

async function selecionarEndereco(addressId, disponivel) {
    if (!disponivel) {
        alert('Infelizmente ainda não atendemos este endereço. Por favor, escolha outro endereço.');
        return;
    }
    
    try {
        const res = await fetch('/mercado/api/localizacao.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'set_endereco_entrega', address_id: addressId })
        });
        
        const data = await res.json();
        
        if (data.success && data.disponivel) {
            // Atualizar visual
            document.querySelectorAll('.endereco-item').forEach(el => {
                el.classList.remove('selected');
            });
            document.querySelector(`.endereco-item[data-id="${addressId}"]`)?.classList.add('selected');
            
            fecharModalEndereco();
            
            // Recarregar página
            window.location.reload();
        } else {
            alert(data.mensagem || 'Não foi possível selecionar este endereço.');
        }
    } catch (err) {
        console.error('Erro ao selecionar endereço:', err);
        alert('Erro ao selecionar endereço. Tente novamente.');
    }
}
<?php endif; ?>

// Para não logados - verificar CEP
function formatarCEP(input) {
    let value = input.value.replace(/\D/g, '');
    if (value.length > 5) {
        value = value.substring(0, 5) + '-' + value.substring(5, 8);
    }
    input.value = value;
}

async function verificarCEP() {
    const cep = document.getElementById('cep-input').value.replace(/\D/g, '');
    const resultEl = document.getElementById('cep-result');
    
    if (cep.length !== 8) {
        resultEl.className = 'cep-result error';
        resultEl.textContent = 'Digite um CEP válido com 8 dígitos';
        return;
    }
    
    resultEl.className = 'cep-result';
    resultEl.style.display = 'block';
    resultEl.innerHTML = '⏳ Verificando...';
    
    try {
        const res = await fetch('/mercado/api/localizacao.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'verificar_cep', cep: cep })
        });
        
        const data = await res.json();
        
        if (data.disponivel) {
            resultEl.className = 'cep-result success';
            resultEl.innerHTML = `
                <strong>✅ Ótimo! Entregamos na sua região!</strong><br>
                📍 ${data.localizacao?.cidade || ''} ${data.localizacao?.uf ? '- ' + data.localizacao.uf : ''}<br>
                🚀 ${data.mensagem || 'Entrega rápida disponível'}
            `;
            
            // Atualizar topbar
            document.querySelector('.om-topbar-address').innerHTML = `
                ${data.localizacao?.cidade || 'Disponível'}
                <svg class="om-topbar-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            `;
            
            // Fechar modal após 2 segundos
            setTimeout(() => {
                fecharModalEndereco();
            }, 2000);
        } else {
            resultEl.className = 'cep-result error';
            resultEl.innerHTML = `
                <strong>😔 Ainda não atendemos sua região</strong><br>
                ${data.mensagem || 'Estamos trabalhando para expandir nossa cobertura!'}
            `;
        }
    } catch (err) {
        resultEl.className = 'cep-result error';
        resultEl.textContent = 'Erro ao verificar CEP. Tente novamente.';
    }
}

// Fechar com ESC
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && enderecoModal.classList.contains('active')) {
        fecharModalEndereco();
    }
});
</script>

<main>
