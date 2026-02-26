<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PAINEL DO MERCADO - Assinatura de Contrato Digital
 * Parceiro aprovado assina contrato para ativar a conta
 * ══════════════════════════════════════════════════════════════════════════════
 */

session_start();

// Autenticacao obrigatoria
if (!isset($_SESSION['mercado_id'])) {
    header('Location: login.php');
    exit;
}

require_once dirname(__DIR__, 2) . '/database.php';
require_once dirname(__DIR__, 2) . '/includes/classes/OmAuth.php';

$db = getDB();
OmAuth::getInstance()->setDb($db);

$mercado_id = (int) $_SESSION['mercado_id'];

// ── Buscar dados do parceiro + plano ──────────────────────────────────────
$stmt = $db->prepare("
    SELECT p.partner_id, p.name, p.owner_name, p.cnpj, p.cpf, p.document_type,
           p.razao_social, p.nome_fantasia, p.email, p.phone,
           p.address, p.address_number, p.address_complement, p.bairro,
           p.city, p.state, p.cep, p.categoria,
           p.plan_id, p.contract_signed_at, p.first_setup_complete,
           pl.slug AS plan_slug, pl.name AS plan_name,
           pl.commission_rate, pl.commission_online_rate,
           pl.uses_platform_delivery
    FROM om_market_partners p
    LEFT JOIN om_partner_plans pl ON pl.id = p.plan_id
    WHERE p.partner_id = ?
");
$stmt->execute([$mercado_id]);
$partner = $stmt->fetch();

if (!$partner) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Se contrato ja assinado, redirecionar
if (!empty($partner['contract_signed_at'])) {
    if (empty($partner['first_setup_complete']) || $partner['first_setup_complete'] == 0) {
        header('Location: setup.php');
    } else {
        header('Location: index.php');
    }
    exit;
}

// ── Montar dados para exibicao ────────────────────────────────────────────
$planSlug = $partner['plan_slug'] ?? 'basico';
$planLabel = $planSlug === 'premium' ? 'Premium' : 'Basico';
$commissionRate = number_format((float)($partner['commission_rate'] ?? 5.00), 0);
$commissionOnlineRate = number_format((float)($partner['commission_online_rate'] ?? 8.00), 0);
$usesPlatformDelivery = (bool)($partner['uses_platform_delivery'] ?? false);

$partnerName = $partner['nome_fantasia'] ?: $partner['name'] ?: $partner['owner_name'];
$razaoSocial = $partner['razao_social'] ?: $partnerName;
$documentType = $partner['document_type'] ?? 'cnpj';

if ($documentType === 'cpf' && !empty($partner['cpf'])) {
    $docNumber = $partner['cpf'];
    $docLabel = 'CPF';
    $docFormatted = preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', preg_replace('/\D/', '', $docNumber));
} else {
    $docNumber = $partner['cnpj'] ?? '';
    $docLabel = 'CNPJ';
    $docFormatted = preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', preg_replace('/\D/', '', $docNumber));
}

// Endereco completo
$addressParts = array_filter([
    $partner['address'],
    $partner['address_number'] ? 'n. ' . $partner['address_number'] : null,
    $partner['address_complement'],
    $partner['bairro'],
    $partner['city'],
    $partner['state'],
    $partner['cep'] ? 'CEP ' . $partner['cep'] : null,
]);
$fullAddress = implode(', ', $addressParts) ?: 'Endereco nao informado';

$dataAtual = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('d/m/Y');

$categoriaLabel = match($partner['categoria'] ?? 'mercado') {
    'restaurante' => 'restaurante',
    'farmacia'    => 'farmacia',
    'padaria'     => 'padaria',
    'acougue'     => 'acougue',
    'hortifruti'  => 'hortifruti',
    'petshop'     => 'petshop',
    'conveniencia'=> 'loja de conveniencia',
    'bebidas'     => 'loja de bebidas',
    'loja'        => 'loja',
    default       => 'estabelecimento comercial',
};

$deliveryClause = $usesPlatformDelivery
    ? "A PLATAFORMA disponibilizara servico de entrega atraves de entregadores parceiros, sendo o custo de entrega repassado ao consumidor final."
    : "O PARCEIRO sera responsavel por realizar suas proprias entregas, podendo optar pelo servico de entrega da PLATAFORMA quando disponivel.";

// Gerar token para a chamada API (session-based fallback)
$partnerToken = OmAuth::getInstance()->generateToken(OmAuth::USER_TYPE_PARTNER, $mercado_id, [
    'name' => $partnerName,
    'email' => $partner['email'] ?? '',
]);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contrato de Adesao - SuperBora</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lucide-static@latest/font/lucide.min.css">
    <link rel="stylesheet" href="/frontend/src/styles/design-system.css">
    <style>
        /* ── Layout ─────────────────────────────────────────────────── */
        body {
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            background: linear-gradient(135deg, var(--om-primary-50) 0%, var(--om-gray-100) 100%);
            padding: var(--om-space-6) var(--om-space-4);
        }

        .contract-container {
            width: 100%;
            max-width: 800px;
        }

        .contract-card {
            background: var(--om-white);
            border-radius: var(--om-radius-2xl);
            box-shadow: var(--om-shadow-xl);
            padding: var(--om-space-8);
            position: relative;
        }

        .contract-brand {
            text-align: center;
            margin-bottom: var(--om-space-6);
        }

        .contract-brand-name {
            font-size: var(--om-font-3xl);
            font-weight: var(--om-font-bold);
            color: var(--om-primary);
        }

        .contract-brand-sub {
            font-size: var(--om-font-sm);
            color: var(--om-text-secondary);
            margin-top: var(--om-space-1);
        }

        .contract-title {
            text-align: center;
            font-size: var(--om-font-2xl);
            font-weight: var(--om-font-bold);
            color: var(--om-text-primary);
            margin-bottom: var(--om-space-2);
        }

        .contract-subtitle {
            text-align: center;
            font-size: var(--om-font-sm);
            color: var(--om-text-secondary);
            margin-bottom: var(--om-space-6);
        }

        /* ── Contract info banner ──────────────────────────────────── */
        .contract-info {
            background: var(--om-gray-50);
            border: 1px solid var(--om-gray-200);
            border-radius: var(--om-radius-lg);
            padding: var(--om-space-4);
            margin-bottom: var(--om-space-6);
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--om-space-3);
        }

        .contract-info-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .contract-info-label {
            font-size: var(--om-font-xs);
            color: var(--om-text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: var(--om-font-medium);
        }

        .contract-info-value {
            font-size: var(--om-font-sm);
            color: var(--om-text-primary);
            font-weight: var(--om-font-semibold);
        }

        /* ── Contract text area ────────────────────────────────────── */
        .contract-text-wrapper {
            border: 1px solid var(--om-gray-200);
            border-radius: var(--om-radius-lg);
            margin-bottom: var(--om-space-6);
            position: relative;
        }

        .contract-text-header {
            display: flex;
            align-items: center;
            gap: var(--om-space-2);
            padding: var(--om-space-3) var(--om-space-4);
            background: var(--om-gray-50);
            border-bottom: 1px solid var(--om-gray-200);
            border-radius: var(--om-radius-lg) var(--om-radius-lg) 0 0;
            font-size: var(--om-font-sm);
            font-weight: var(--om-font-medium);
            color: var(--om-text-secondary);
        }

        .contract-text-header i {
            color: var(--om-primary);
        }

        .contract-text {
            max-height: 500px;
            overflow-y: auto;
            padding: var(--om-space-5) var(--om-space-6);
            font-size: var(--om-font-sm);
            line-height: 1.8;
            color: var(--om-text-primary);
            white-space: pre-wrap;
            font-family: 'Inter', -apple-system, sans-serif;
        }

        .contract-text::-webkit-scrollbar {
            width: 8px;
        }

        .contract-text::-webkit-scrollbar-track {
            background: var(--om-gray-50);
            border-radius: 4px;
        }

        .contract-text::-webkit-scrollbar-thumb {
            background: var(--om-gray-300);
            border-radius: 4px;
        }

        .contract-text::-webkit-scrollbar-thumb:hover {
            background: var(--om-gray-400);
        }

        .contract-scroll-hint {
            display: none;
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 60px;
            background: linear-gradient(transparent, rgba(255,255,255,0.95));
            border-radius: 0 0 var(--om-radius-lg) var(--om-radius-lg);
            pointer-events: none;
            text-align: center;
            padding-top: 30px;
        }

        .contract-scroll-hint.visible {
            display: block;
        }

        .contract-scroll-hint span {
            font-size: var(--om-font-xs);
            color: var(--om-text-muted);
            background: var(--om-white);
            padding: var(--om-space-1) var(--om-space-3);
            border-radius: var(--om-radius-full);
            border: 1px solid var(--om-gray-200);
        }

        /* ── Form section ──────────────────────────────────────────── */
        .sign-section {
            border-top: 2px solid var(--om-gray-100);
            padding-top: var(--om-space-6);
        }

        .sign-section-title {
            font-size: var(--om-font-lg);
            font-weight: var(--om-font-bold);
            color: var(--om-text-primary);
            margin-bottom: var(--om-space-1);
            display: flex;
            align-items: center;
            gap: var(--om-space-2);
        }

        .sign-section-title i {
            color: var(--om-primary);
        }

        .sign-section-desc {
            font-size: var(--om-font-sm);
            color: var(--om-text-secondary);
            margin-bottom: var(--om-space-5);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--om-space-4);
        }

        @media (max-width: 600px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            .contract-card {
                padding: var(--om-space-5);
            }
            .contract-info {
                grid-template-columns: 1fr;
            }
            .contract-text {
                padding: var(--om-space-4);
                max-height: 400px;
            }
        }

        .om-form-group {
            margin-bottom: var(--om-space-4);
        }

        .field-error {
            border-color: var(--om-error) !important;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15) !important;
        }

        .field-error-msg {
            color: var(--om-error);
            font-size: var(--om-font-xs);
            margin-top: var(--om-space-1);
            display: none;
        }

        .field-error-msg.visible {
            display: block;
        }

        /* ── Checkbox ──────────────────────────────────────────────── */
        .checkbox-row {
            display: flex;
            align-items: flex-start;
            gap: var(--om-space-3);
            margin-bottom: var(--om-space-5);
            padding: var(--om-space-4);
            background: var(--om-gray-50);
            border-radius: var(--om-radius-lg);
            border: 2px solid var(--om-gray-200);
            transition: border-color 0.2s ease, background 0.2s ease;
        }

        .checkbox-row.checked {
            border-color: var(--om-primary);
            background: var(--om-primary-50);
        }

        .checkbox-row input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-top: 2px;
            accent-color: var(--om-primary);
            flex-shrink: 0;
            cursor: pointer;
        }

        .checkbox-row label {
            font-size: var(--om-font-sm);
            color: var(--om-text-secondary);
            cursor: pointer;
            line-height: 1.5;
        }

        .checkbox-row label strong {
            color: var(--om-text-primary);
        }

        /* ── Button ────────────────────────────────────────────────── */
        .btn-sign {
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: var(--om-space-2);
            padding: var(--om-space-4) var(--om-space-6);
            border: none;
            border-radius: var(--om-radius-lg);
            background: var(--om-primary);
            color: var(--om-white);
            font-weight: var(--om-font-semibold);
            font-size: var(--om-font-base);
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: var(--om-font-family);
        }

        .btn-sign:hover:not(:disabled) {
            background: var(--om-primary-dark);
            transform: translateY(-1px);
            box-shadow: var(--om-shadow-lg);
        }

        .btn-sign:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        /* ── Alert ─────────────────────────────────────────────────── */
        .alert-error {
            background: var(--om-error-bg);
            border: 1px solid var(--om-error-light);
            color: var(--om-error-dark);
            padding: var(--om-space-3) var(--om-space-4);
            border-radius: var(--om-radius-lg);
            font-size: var(--om-font-sm);
            display: none;
            align-items: flex-start;
            gap: var(--om-space-2);
            margin-bottom: var(--om-space-4);
        }

        .alert-error.visible {
            display: flex;
        }

        /* ── Spinner ───────────────────────────────────────────────── */
        .spinner-sm {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: var(--om-white);
            border-radius: 50%;
            animation: om-spin 0.8s linear infinite;
        }

        @keyframes om-spin {
            to { transform: rotate(360deg); }
        }

        /* ── Success overlay ───────────────────────────────────────── */
        .success-overlay {
            display: none;
            text-align: center;
            padding: var(--om-space-8) var(--om-space-4);
        }

        .success-overlay.visible {
            display: block;
        }

        .success-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--om-success-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto var(--om-space-4);
            animation: scaleIn 0.4s ease;
        }

        @keyframes scaleIn {
            0% { transform: scale(0); opacity: 0; }
            60% { transform: scale(1.1); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon i {
            font-size: 2.5rem;
            color: var(--om-success);
        }

        .success-title {
            font-size: var(--om-font-2xl);
            font-weight: var(--om-font-bold);
            color: var(--om-text-primary);
            margin-bottom: var(--om-space-2);
        }

        .success-message {
            font-size: var(--om-font-sm);
            color: var(--om-text-secondary);
            margin-bottom: var(--om-space-6);
            line-height: 1.6;
        }

        .success-detail {
            background: var(--om-gray-50);
            border-radius: var(--om-radius-lg);
            padding: var(--om-space-4);
            margin-bottom: var(--om-space-6);
            text-align: left;
        }

        .success-detail-row {
            display: flex;
            justify-content: space-between;
            padding: var(--om-space-2) 0;
            font-size: var(--om-font-sm);
            border-bottom: 1px solid var(--om-gray-200);
        }

        .success-detail-row:last-child {
            border-bottom: none;
        }

        .success-detail-label {
            color: var(--om-text-secondary);
        }

        .success-detail-value {
            color: var(--om-text-primary);
            font-weight: var(--om-font-semibold);
        }

        /* ── Footer ────────────────────────────────────────────────── */
        .contract-footer {
            text-align: center;
            margin-top: var(--om-space-6);
            font-size: var(--om-font-sm);
            color: var(--om-text-muted);
        }

        .contract-footer a {
            color: var(--om-primary);
            font-weight: var(--om-font-medium);
        }
    </style>
</head>
<body>
    <div class="contract-container">
        <div class="contract-card">
            <!-- Brand -->
            <div class="contract-brand">
                <div class="contract-brand-name">SuperBora</div>
                <div class="contract-brand-sub">Plataforma de Delivery</div>
            </div>

            <!-- Content: contract view -->
            <div id="contractView">
                <h1 class="contract-title">Contrato de Adesao</h1>
                <p class="contract-subtitle">Leia atentamente e assine para continuar</p>

                <!-- Partner info summary -->
                <div class="contract-info">
                    <div class="contract-info-item">
                        <span class="contract-info-label">Estabelecimento</span>
                        <span class="contract-info-value"><?= htmlspecialchars($partnerName) ?></span>
                    </div>
                    <div class="contract-info-item">
                        <span class="contract-info-label"><?= htmlspecialchars($docLabel) ?></span>
                        <span class="contract-info-value"><?= htmlspecialchars($docFormatted) ?></span>
                    </div>
                    <div class="contract-info-item">
                        <span class="contract-info-label">Plano</span>
                        <span class="contract-info-value"><?= htmlspecialchars($planLabel) ?></span>
                    </div>
                    <div class="contract-info-item">
                        <span class="contract-info-label">Comissao</span>
                        <span class="contract-info-value"><?= $commissionRate ?>% / <?= $commissionOnlineRate ?>% online</span>
                    </div>
                </div>

                <!-- Contract text -->
                <div class="contract-text-wrapper" id="contractWrapper">
                    <div class="contract-text-header">
                        <i class="lucide-file-text"></i>
                        Contrato de Adesao e Parceria Comercial
                    </div>
                    <div class="contract-text" id="contractText">CONTRATO DE ADESAO E PARCERIA COMERCIAL
PLATAFORMA SUPERBORA DELIVERY

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

IDENTIFICACAO DAS PARTES

CONTRATANTE (PLATAFORMA): SUPERBORA DELIVERY, plataforma digital de intermediacao de pedidos e entregas, de propriedade de ONEMUNDO TECNOLOGIA LTDA, doravante denominada simplesmente "PLATAFORMA".

CONTRATADO (PARCEIRO): <?= htmlspecialchars($razaoSocial) ?>, <?= htmlspecialchars($categoriaLabel) ?>, inscrito no <?= htmlspecialchars($docLabel) ?> sob o numero <?= htmlspecialchars($docFormatted) ?>, com sede/endereco em <?= htmlspecialchars($fullAddress) ?>, doravante denominado simplesmente "PARCEIRO".

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

CLAUSULA 1 - DO OBJETO

1.1. O presente contrato tem por objeto estabelecer as condicoes comerciais para a intermediacao de vendas de produtos do PARCEIRO atraves da PLATAFORMA SuperBora Delivery, incluindo a exibicao de produtos, processamento de pedidos e pagamentos.

1.2. A PLATAFORMA atuara como intermediadora entre o PARCEIRO e os consumidores finais, disponibilizando tecnologia para realizacao de pedidos online, aplicativo movel e painel de gestao.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

CLAUSULA 2 - DO PLANO E CONDICOES COMERCIAIS

2.1. O PARCEIRO adere ao Plano <?= htmlspecialchars($planLabel) ?>, com as seguintes condicoes:

   a) Taxa de comissao sobre vendas com pagamento em dinheiro/PIX presencial: <?= $commissionRate ?>% (por cento) sobre o valor dos produtos.

   b) Taxa de comissao sobre vendas com pagamento online (cartao de credito, debito ou PIX via plataforma): <?= $commissionOnlineRate ?>% (por cento) sobre o valor dos produtos.

   c) As taxas de comissao incidem exclusivamente sobre o valor dos produtos vendidos, nao incidindo sobre a taxa de entrega cobrada do consumidor.

2.2. <?= htmlspecialchars($deliveryClause) ?>

2.3. Os repasses serao realizados conforme calendario financeiro da PLATAFORMA, com periodicidade semanal ou quinzenal, descontadas as comissoes previstas neste contrato.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

CLAUSULA 3 - DAS OBRIGACOES DA PLATAFORMA

A PLATAFORMA se compromete a:

3.1. Disponibilizar o aplicativo e site para exibicao dos produtos e cardapio do PARCEIRO aos consumidores.

3.2. Processar os pagamentos online realizados pelos consumidores e repassar os valores devidos ao PARCEIRO conforme os prazos estabelecidos.

3.3. Fornecer painel administrativo para gestao de pedidos, cardapio, horarios de funcionamento e relatorios financeiros.

3.4. Oferecer suporte tecnico para utilizacao da plataforma.

3.5. Realizar acoes de marketing digital para atrair consumidores a plataforma.

3.6. Manter a seguranca dos dados conforme a Lei Geral de Protecao de Dados (LGPD - Lei 13.709/2018).

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

CLAUSULA 4 - DAS OBRIGACOES DO PARCEIRO

O PARCEIRO se compromete a:

4.1. Manter o cadastro atualizado na plataforma, incluindo cardapio, precos, horarios de funcionamento e informacoes de contato.

4.2. Garantir a qualidade dos produtos oferecidos, atendendo as normas sanitarias e de seguranca alimentar aplicaveis.

4.3. Cumprir os horarios de funcionamento cadastrados na plataforma.

4.4. Preparar e disponibilizar os pedidos dentro do tempo estimado informado na plataforma.

4.5. Manter os precos praticados na plataforma compativeis com os precos praticados no estabelecimento fisico, salvo acordo especifico.

4.6. Comunicar a PLATAFORMA com antecedencia sobre fechamentos extraordinarios, alteracoes de cardapio ou qualquer situacao que afete o atendimento.

4.7. Respeitar o Codigo de Defesa do Consumidor (Lei 8.078/1990) em todas as transacoes realizadas atraves da plataforma.

4.8. Manter regularidade fiscal e documental do estabelecimento durante a vigencia deste contrato.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

CLAUSULA 5 - DA VIGENCIA E RESCISAO

5.1. O presente contrato e firmado por prazo indeterminado, entrando em vigor na data de sua assinatura digital.

5.2. Qualquer das partes podera rescindir este contrato a qualquer momento, mediante comunicacao previa de 30 (trinta) dias, sem incidencia de multa ou penalidade.

5.3. A rescisao nao exime as partes das obrigacoes pendentes, incluindo repasses financeiros de pedidos ja realizados.

5.4. A PLATAFORMA reserva-se o direito de suspender ou rescindir este contrato imediatamente em caso de:
   a) Violacao das obrigacoes previstas neste contrato;
   b) Praticas que prejudiquem a reputacao da plataforma;
   c) Fraude ou tentativa de fraude;
   d) Descumprimento de normas sanitarias ou legais.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

CLAUSULA 6 - DA PROPRIEDADE INTELECTUAL

6.1. As marcas, logotipos, softwares e demais elementos de propriedade intelectual da PLATAFORMA permanecem de titularidade exclusiva da ONEMUNDO TECNOLOGIA LTDA.

6.2. O PARCEIRO autoriza a PLATAFORMA a utilizar seu nome, marca e imagens dos produtos para fins de divulgacao na plataforma e em materiais de marketing.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

CLAUSULA 7 - DA PROTECAO DE DADOS

7.1. As partes comprometem-se a tratar os dados pessoais de consumidores e colaboradores em conformidade com a Lei Geral de Protecao de Dados (LGPD - Lei 13.709/2018).

7.2. O PARCEIRO nao devera utilizar dados de consumidores obtidos atraves da plataforma para finalidades distintas do atendimento dos pedidos.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

CLAUSULA 8 - DAS DISPOSICOES GERAIS

8.1. Este contrato e regido pelas leis da Republica Federativa do Brasil.

8.2. As partes elegem o foro da comarca de domicilio da PLATAFORMA para dirimir quaisquer controversias oriundas deste contrato.

8.3. A tolerancia de qualquer das partes quanto ao descumprimento de clausula deste contrato nao implicara em renuncia ou novacao.

8.4. Alteracoes nas condicoes comerciais (comissoes, planos) serao comunicadas com antecedencia minima de 30 (trinta) dias, cabendo ao PARCEIRO aceitar ou rescindir o contrato.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

ASSINATURA DIGITAL

Ao assinar digitalmente este contrato, o PARCEIRO declara que:
- Leu e compreendeu todas as clausulas acima;
- Concorda integralmente com os termos e condicoes estabelecidos;
- As informacoes fornecidas sao verdadeiras e completas;
- Esta ciente de que a assinatura digital possui validade juridica conforme a Medida Provisoria 2.200-2/2001.

Estabelecimento: <?= htmlspecialchars($partnerName) ?>

Razao Social: <?= htmlspecialchars($razaoSocial) ?>

<?= htmlspecialchars($docLabel) ?>: <?= htmlspecialchars($docFormatted) ?>

Endereco: <?= htmlspecialchars($fullAddress) ?>

Plano: <?= htmlspecialchars($planLabel) ?>

Data: <?= $dataAtual ?></div>
                    <div class="contract-scroll-hint visible" id="scrollHint">
                        <span><i class="lucide-chevrons-down" style="font-size:12px;"></i> Role para ler o contrato completo</span>
                    </div>
                </div>

                <!-- Sign form -->
                <div class="sign-section">
                    <div class="sign-section-title">
                        <i class="lucide-pen-tool"></i>
                        Assinatura Digital
                    </div>
                    <p class="sign-section-desc">Preencha os dados abaixo para assinar o contrato eletronicamente.</p>

                    <div class="alert-error" id="signError">
                        <i class="lucide-alert-circle" style="flex-shrink:0;margin-top:2px;"></i>
                        <span id="signErrorMsg"></span>
                    </div>

                    <div class="form-row">
                        <div class="om-form-group">
                            <label class="om-label" for="signerName">Nome completo *</label>
                            <input type="text" id="signerName" class="om-input" maxlength="255" placeholder="Seu nome completo" autocomplete="name">
                            <div class="field-error-msg" id="signerNameError">Informe seu nome completo</div>
                        </div>
                        <div class="om-form-group">
                            <label class="om-label" for="signerDocument">CPF *</label>
                            <input type="text" id="signerDocument" class="om-input" maxlength="14" placeholder="000.000.000-00" autocomplete="off">
                            <div class="field-error-msg" id="signerDocumentError">Informe um CPF valido</div>
                        </div>
                    </div>

                    <div class="checkbox-row" id="acceptRow">
                        <input type="checkbox" id="acceptTerms">
                        <label for="acceptTerms">
                            <strong>Declaro que li e concordo integralmente</strong> com todos os termos e condicoes do contrato de adesao acima. Estou ciente de que esta assinatura digital tem validade juridica.
                        </label>
                    </div>

                    <button type="button" class="btn-sign" id="btnSign" onclick="signContract()" disabled>
                        <i class="lucide-pen-tool"></i>
                        Assinar Contrato
                    </button>
                </div>
            </div>

            <!-- Success overlay -->
            <div class="success-overlay" id="successOverlay">
                <div class="success-icon">
                    <i class="lucide-check"></i>
                </div>
                <div class="success-title">Contrato assinado com sucesso!</div>
                <div class="success-message">
                    Sua adesao foi confirmada. Agora vamos configurar sua loja para comecar a receber pedidos.
                </div>
                <div class="success-detail">
                    <div class="success-detail-row">
                        <span class="success-detail-label">Assinante</span>
                        <span class="success-detail-value" id="successSignerName">-</span>
                    </div>
                    <div class="success-detail-row">
                        <span class="success-detail-label">Plano</span>
                        <span class="success-detail-value"><?= htmlspecialchars($planLabel) ?></span>
                    </div>
                    <div class="success-detail-row">
                        <span class="success-detail-label">Data</span>
                        <span class="success-detail-value"><?= $dataAtual ?></span>
                    </div>
                </div>
                <p class="success-message">Redirecionando para a configuracao inicial...</p>
                <div class="spinner-sm" style="margin:0 auto;border-color:var(--om-gray-200);border-top-color:var(--om-primary);"></div>
            </div>
        </div>

        <div class="contract-footer">
            Duvidas? <a href="mailto:suporte@onemundo.com.br">Fale com o suporte</a>
            &nbsp;|&nbsp;
            <a href="logout.php">Sair</a>
        </div>
    </div>

    <script>
    /* ═══════════════════════════════════════════════════════════════════════════
       STATE
       ═══════════════════════════════════════════════════════════════════════════ */
    const PARTNER_TOKEN = <?= json_encode($partnerToken) ?>;

    /* ═══════════════════════════════════════════════════════════════════════════
       SCROLL HINT
       ═══════════════════════════════════════════════════════════════════════════ */
    const contractTextEl = document.getElementById('contractText');
    const scrollHint = document.getElementById('scrollHint');

    function checkScroll() {
        const el = contractTextEl;
        const atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 40;
        if (atBottom) {
            scrollHint.classList.remove('visible');
        }
    }

    contractTextEl.addEventListener('scroll', checkScroll);

    // Also check on load in case content is shorter than container
    setTimeout(function() {
        if (contractTextEl.scrollHeight <= contractTextEl.clientHeight) {
            scrollHint.classList.remove('visible');
        }
    }, 100);

    /* ═══════════════════════════════════════════════════════════════════════════
       CHECKBOX + BUTTON STATE
       ═══════════════════════════════════════════════════════════════════════════ */
    const acceptTerms = document.getElementById('acceptTerms');
    const acceptRow = document.getElementById('acceptRow');
    const btnSign = document.getElementById('btnSign');

    acceptTerms.addEventListener('change', function() {
        btnSign.disabled = !this.checked;
        acceptRow.classList.toggle('checked', this.checked);
    });

    /* ═══════════════════════════════════════════════════════════════════════════
       CPF MASK
       ═══════════════════════════════════════════════════════════════════════════ */
    document.getElementById('signerDocument').addEventListener('input', function(e) {
        let v = e.target.value.replace(/\D/g, '');
        if (v.length > 11) v = v.slice(0, 11);
        if (v.length > 9) v = v.replace(/^(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
        else if (v.length > 6) v = v.replace(/^(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
        else if (v.length > 3) v = v.replace(/^(\d{3})(\d{1,3})/, '$1.$2');
        e.target.value = v;
    });

    /* ═══════════════════════════════════════════════════════════════════════════
       CLEAR FIELD ERRORS ON INPUT
       ═══════════════════════════════════════════════════════════════════════════ */
    document.querySelectorAll('.om-input').forEach(function(input) {
        input.addEventListener('input', function() {
            this.classList.remove('field-error');
            var errorEl = this.parentElement.querySelector('.field-error-msg');
            if (errorEl) errorEl.classList.remove('visible');
        });
    });

    /* ═══════════════════════════════════════════════════════════════════════════
       VALIDATION
       ═══════════════════════════════════════════════════════════════════════════ */
    function validateCPF(cpf) {
        cpf = cpf.replace(/\D/g, '');
        if (cpf.length !== 11) return false;
        if (/^(\d)\1{10}$/.test(cpf)) return false;

        var sum = 0;
        for (var i = 0; i < 9; i++) sum += parseInt(cpf.charAt(i)) * (10 - i);
        var check = 11 - (sum % 11);
        if (check >= 10) check = 0;
        if (parseInt(cpf.charAt(9)) !== check) return false;

        sum = 0;
        for (var i = 0; i < 10; i++) sum += parseInt(cpf.charAt(i)) * (11 - i);
        check = 11 - (sum % 11);
        if (check >= 10) check = 0;
        if (parseInt(cpf.charAt(10)) !== check) return false;

        return true;
    }

    function validate() {
        var valid = true;
        var signerName = document.getElementById('signerName').value.trim();
        var signerDoc = document.getElementById('signerDocument').value;

        // Clear previous errors
        document.querySelectorAll('.field-error').forEach(function(el) { el.classList.remove('field-error'); });
        document.querySelectorAll('.field-error-msg').forEach(function(el) { el.classList.remove('visible'); });

        if (!signerName || signerName.length < 3) {
            document.getElementById('signerName').classList.add('field-error');
            document.getElementById('signerNameError').classList.add('visible');
            valid = false;
        }

        if (!validateCPF(signerDoc)) {
            document.getElementById('signerDocument').classList.add('field-error');
            document.getElementById('signerDocumentError').classList.add('visible');
            valid = false;
        }

        if (!acceptTerms.checked) {
            showError('Voce precisa aceitar os termos para assinar o contrato.');
            valid = false;
        }

        return valid;
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       ERROR DISPLAY
       ═══════════════════════════════════════════════════════════════════════════ */
    function showError(msg) {
        document.getElementById('signErrorMsg').textContent = msg;
        document.getElementById('signError').classList.add('visible');
    }

    function hideError() {
        document.getElementById('signError').classList.remove('visible');
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       SIGN CONTRACT
       ═══════════════════════════════════════════════════════════════════════════ */
    async function signContract() {
        hideError();

        if (!validate()) return;

        var signerName = document.getElementById('signerName').value.trim();
        var signerDocument = document.getElementById('signerDocument').value.replace(/\D/g, '');

        btnSign.disabled = true;
        btnSign.innerHTML = '<div class="spinner-sm"></div> Assinando...';

        // Use token from localStorage (set during registration) or the server-generated one
        var token = localStorage.getItem('partner_token') || localStorage.getItem('auth_token') || PARTNER_TOKEN;

        try {
            var res = await fetch('/api/mercado/parceiro/contrato.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + token
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    signer_name: signerName,
                    signer_document: signerDocument
                })
            });

            var data = await res.json();

            if (data.success) {
                // Show success
                document.getElementById('contractView').style.display = 'none';
                document.getElementById('successOverlay').classList.add('visible');
                document.getElementById('successSignerName').textContent = signerName;

                // Redirect to setup after 3 seconds
                setTimeout(function() {
                    window.location.href = 'setup.php';
                }, 3000);
            } else {
                showError(data.message || 'Erro ao assinar o contrato. Tente novamente.');
                btnSign.disabled = false;
                btnSign.innerHTML = '<i class="lucide-pen-tool"></i> Assinar Contrato';
            }
        } catch (e) {
            showError('Erro de conexao. Verifique sua internet e tente novamente.');
            btnSign.disabled = false;
            btnSign.innerHTML = '<i class="lucide-pen-tool"></i> Assinar Contrato';
        }
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       KEYBOARD SUPPORT (Enter to submit when focused on last field)
       ═══════════════════════════════════════════════════════════════════════════ */
    document.getElementById('signerDocument').addEventListener('keypress', function(e) {
        if (e.key === 'Enter' && acceptTerms.checked) {
            signContract();
        }
    });
    </script>
</body>
</html>
