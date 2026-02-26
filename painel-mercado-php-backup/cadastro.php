<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PAINEL DO MERCADO - Cadastro de Parceiro (Wizard 6 etapas)
 * Pagina publica (sem autenticacao)
 * ══════════════════════════════════════════════════════════════════════════════
 */

session_start();

// Se ja estiver logado, redirecionar
if (isset($_SESSION['mercado_id'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastre-se como Parceiro - SuperBora</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lucide-static@latest/font/lucide.min.css">
    <link rel="stylesheet" href="/frontend/src/styles/design-system.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
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

        .wizard-container {
            width: 100%;
            max-width: 700px;
        }

        .wizard-card {
            background: var(--om-white);
            border-radius: var(--om-radius-2xl);
            box-shadow: var(--om-shadow-xl);
            padding: var(--om-space-8);
            position: relative;
        }

        .wizard-brand {
            text-align: center;
            margin-bottom: var(--om-space-6);
        }

        .wizard-brand-name {
            font-size: var(--om-font-3xl);
            font-weight: var(--om-font-bold);
            color: var(--om-primary);
        }

        .wizard-brand-sub {
            font-size: var(--om-font-sm);
            color: var(--om-text-secondary);
            margin-top: var(--om-space-1);
        }

        /* ── Progress bar ───────────────────────────────────────────── */
        .progress-bar {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: var(--om-space-8);
            gap: 0;
        }

        .progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 1;
            min-width: 52px;
        }

        .step-circle {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: var(--om-font-sm);
            font-weight: var(--om-font-semibold);
            border: 2px solid var(--om-gray-300);
            background: var(--om-white);
            color: var(--om-gray-400);
            transition: all 0.3s ease;
        }

        .step-circle i {
            font-size: 16px;
        }

        .step-label {
            font-size: 10px;
            color: var(--om-text-muted);
            margin-top: var(--om-space-1);
            white-space: nowrap;
            transition: color 0.3s ease;
        }

        .progress-step.active .step-circle {
            border-color: var(--om-primary);
            background: var(--om-primary);
            color: var(--om-white);
        }

        .progress-step.active .step-label {
            color: var(--om-primary);
            font-weight: var(--om-font-semibold);
        }

        .progress-step.completed .step-circle {
            border-color: var(--om-success);
            background: var(--om-success);
            color: var(--om-white);
        }

        .progress-step.completed .step-label {
            color: var(--om-success);
        }

        .progress-line {
            flex: 1;
            height: 2px;
            background: var(--om-gray-200);
            margin: 0 2px;
            margin-bottom: 20px;
            transition: background 0.3s ease;
        }

        .progress-line.filled {
            background: var(--om-success);
        }

        /* ── Steps container ────────────────────────────────────────── */
        .step-content {
            display: none;
            opacity: 0;
            transform: translateX(20px);
            transition: opacity 0.35s ease, transform 0.35s ease;
        }

        .step-content.active {
            display: block;
        }

        .step-content.visible {
            opacity: 1;
            transform: translateX(0);
        }

        .step-title {
            font-size: var(--om-font-xl);
            font-weight: var(--om-font-bold);
            color: var(--om-text-primary);
            margin-bottom: var(--om-space-1);
        }

        .step-subtitle {
            font-size: var(--om-font-sm);
            color: var(--om-text-secondary);
            margin-bottom: var(--om-space-6);
        }

        /* ── Form helpers ───────────────────────────────────────────── */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--om-space-4);
        }

        .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: var(--om-space-4);
        }

        @media (max-width: 600px) {
            .form-row,
            .form-row-3 {
                grid-template-columns: 1fr;
            }
            .wizard-card {
                padding: var(--om-space-5);
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

        /* ── Radio group ────────────────────────────────────────────── */
        .radio-group {
            display: flex;
            gap: var(--om-space-3);
            margin-bottom: var(--om-space-4);
        }

        .radio-option {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: var(--om-space-2);
            padding: var(--om-space-3) var(--om-space-4);
            border: 2px solid var(--om-gray-200);
            border-radius: var(--om-radius-lg);
            cursor: pointer;
            transition: all 0.2s ease;
            font-weight: var(--om-font-medium);
            color: var(--om-text-secondary);
        }

        .radio-option:hover {
            border-color: var(--om-primary-200);
        }

        .radio-option.selected {
            border-color: var(--om-primary);
            background: var(--om-primary-50);
            color: var(--om-primary);
        }

        .radio-option input[type="radio"] {
            display: none;
        }

        /* ── Alert boxes ────────────────────────────────────────────── */
        .alert-warning {
            background: var(--om-warning-bg);
            border: 1px solid var(--om-warning-light);
            color: var(--om-warning-dark);
            padding: var(--om-space-3) var(--om-space-4);
            border-radius: var(--om-radius-lg);
            font-size: var(--om-font-sm);
            display: flex;
            align-items: flex-start;
            gap: var(--om-space-2);
            margin-bottom: var(--om-space-4);
        }

        .alert-error {
            background: var(--om-error-bg);
            border: 1px solid var(--om-error-light);
            color: var(--om-error-dark);
            padding: var(--om-space-3) var(--om-space-4);
            border-radius: var(--om-radius-lg);
            font-size: var(--om-font-sm);
            display: flex;
            align-items: flex-start;
            gap: var(--om-space-2);
            margin-bottom: var(--om-space-4);
        }

        .alert-success {
            background: var(--om-success-bg);
            border: 1px solid var(--om-success-light);
            color: var(--om-success-dark);
            padding: var(--om-space-3) var(--om-space-4);
            border-radius: var(--om-radius-lg);
            font-size: var(--om-font-sm);
            display: flex;
            align-items: flex-start;
            gap: var(--om-space-2);
            margin-bottom: var(--om-space-4);
        }

        .alert-info {
            background: var(--om-info-bg);
            border: 1px solid var(--om-info-light);
            color: var(--om-info-dark);
            padding: var(--om-space-3) var(--om-space-4);
            border-radius: var(--om-radius-lg);
            font-size: var(--om-font-sm);
            display: flex;
            align-items: flex-start;
            gap: var(--om-space-2);
            margin-bottom: var(--om-space-4);
        }

        /* ── CNPJ result box ───────────────────────────────────────── */
        .cnpj-result {
            background: var(--om-gray-50);
            border: 1px solid var(--om-gray-200);
            border-radius: var(--om-radius-lg);
            padding: var(--om-space-4);
            margin-bottom: var(--om-space-4);
            display: none;
        }

        .cnpj-result.visible {
            display: block;
        }

        .cnpj-result-row {
            display: flex;
            justify-content: space-between;
            padding: var(--om-space-2) 0;
            border-bottom: 1px solid var(--om-gray-200);
            font-size: var(--om-font-sm);
        }

        .cnpj-result-row:last-child {
            border-bottom: none;
        }

        .cnpj-result-label {
            color: var(--om-text-secondary);
            font-weight: var(--om-font-medium);
        }

        .cnpj-result-value {
            color: var(--om-text-primary);
            font-weight: var(--om-font-semibold);
            text-align: right;
            max-width: 60%;
        }

        /* ── Logo upload ────────────────────────────────────────────── */
        .logo-upload-area {
            display: flex;
            align-items: center;
            gap: var(--om-space-4);
            margin-bottom: var(--om-space-4);
        }

        .logo-preview-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--om-gray-100);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            cursor: pointer;
            border: 2px dashed var(--om-gray-300);
            flex-shrink: 0;
            transition: border-color 0.2s ease;
        }

        .logo-preview-circle:hover {
            border-color: var(--om-primary);
        }

        .logo-preview-circle img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .logo-preview-circle i {
            font-size: 1.5rem;
            color: var(--om-gray-400);
        }

        .logo-upload-text {
            font-size: var(--om-font-sm);
            color: var(--om-text-secondary);
        }

        .logo-upload-text strong {
            color: var(--om-primary);
            cursor: pointer;
        }

        /* ── Map ────────────────────────────────────────────────────── */
        #map {
            width: 100%;
            height: 280px;
            border-radius: var(--om-radius-lg);
            border: 1px solid var(--om-gray-200);
            margin-bottom: var(--om-space-4);
            z-index: 0;
        }

        /* ── Plan cards ─────────────────────────────────────────────── */
        .plans-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--om-space-4);
            margin-bottom: var(--om-space-4);
        }

        @media (max-width: 600px) {
            .plans-grid {
                grid-template-columns: 1fr;
            }
        }

        .plan-card {
            border: 2px solid var(--om-gray-200);
            border-radius: var(--om-radius-xl);
            padding: var(--om-space-5);
            cursor: pointer;
            transition: all 0.25s ease;
            position: relative;
        }

        .plan-card:hover:not(.plan-disabled) {
            border-color: var(--om-primary-200);
            box-shadow: var(--om-shadow-md);
        }

        .plan-card.selected {
            border-color: var(--om-primary);
            background: var(--om-primary-50);
            box-shadow: 0 0 0 3px rgba(255, 106, 0, 0.15);
        }

        .plan-card.plan-disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }

        .plan-tag {
            display: inline-block;
            padding: 2px var(--om-space-2);
            border-radius: var(--om-radius-full);
            font-size: var(--om-font-xs);
            font-weight: var(--om-font-semibold);
            margin-bottom: var(--om-space-2);
        }

        .plan-tag-basic {
            background: var(--om-gray-100);
            color: var(--om-gray-700);
        }

        .plan-tag-premium {
            background: var(--om-primary-100);
            color: var(--om-primary-700);
        }

        .plan-name {
            font-size: var(--om-font-lg);
            font-weight: var(--om-font-bold);
            color: var(--om-text-primary);
            margin-bottom: var(--om-space-1);
        }

        .plan-price {
            font-size: var(--om-font-sm);
            color: var(--om-primary);
            font-weight: var(--om-font-semibold);
            margin-bottom: var(--om-space-3);
        }

        .plan-commission {
            font-size: var(--om-font-xs);
            color: var(--om-text-secondary);
            margin-bottom: var(--om-space-3);
            line-height: 1.6;
        }

        .plan-features {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .plan-features li {
            font-size: var(--om-font-sm);
            color: var(--om-text-secondary);
            padding: var(--om-space-1) 0;
            display: flex;
            align-items: center;
            gap: var(--om-space-2);
        }

        .plan-features li i {
            color: var(--om-success);
            font-size: 14px;
            flex-shrink: 0;
        }

        .plan-note {
            margin-top: var(--om-space-3);
            padding-top: var(--om-space-3);
            border-top: 1px solid var(--om-gray-200);
            font-size: var(--om-font-xs);
            color: var(--om-text-muted);
            font-style: italic;
        }

        .plan-unavailable-msg {
            background: var(--om-warning-bg);
            color: var(--om-warning-dark);
            padding: var(--om-space-2) var(--om-space-3);
            border-radius: var(--om-radius-md);
            font-size: var(--om-font-xs);
            margin-top: var(--om-space-3);
            display: none;
        }

        .plan-disabled .plan-unavailable-msg {
            display: block;
        }

        .pickup-note {
            background: var(--om-info-bg);
            border: 1px solid var(--om-info-light);
            color: var(--om-info-dark);
            padding: var(--om-space-3) var(--om-space-4);
            border-radius: var(--om-radius-lg);
            font-size: var(--om-font-sm);
            display: flex;
            align-items: center;
            gap: var(--om-space-2);
        }

        /* ── Summary (step 6) ──────────────────────────────────────── */
        .summary-section {
            margin-bottom: var(--om-space-5);
        }

        .summary-section-title {
            font-size: var(--om-font-sm);
            font-weight: var(--om-font-semibold);
            color: var(--om-primary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: var(--om-space-2);
            display: flex;
            align-items: center;
            gap: var(--om-space-2);
        }

        .summary-grid {
            background: var(--om-gray-50);
            border-radius: var(--om-radius-lg);
            padding: var(--om-space-3) var(--om-space-4);
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: var(--om-space-2) 0;
            font-size: var(--om-font-sm);
            border-bottom: 1px solid var(--om-gray-200);
        }

        .summary-row:last-child {
            border-bottom: none;
        }

        .summary-row-label {
            color: var(--om-text-secondary);
        }

        .summary-row-value {
            color: var(--om-text-primary);
            font-weight: var(--om-font-medium);
            text-align: right;
            max-width: 55%;
            word-break: break-word;
        }

        /* ── Checkbox ───────────────────────────────────────────────── */
        .checkbox-row {
            display: flex;
            align-items: flex-start;
            gap: var(--om-space-3);
            margin-bottom: var(--om-space-4);
        }

        .checkbox-row input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-top: 2px;
            accent-color: var(--om-primary);
            flex-shrink: 0;
        }

        .checkbox-row label {
            font-size: var(--om-font-sm);
            color: var(--om-text-secondary);
            cursor: pointer;
        }

        .checkbox-row label a {
            color: var(--om-primary);
            text-decoration: underline;
        }

        /* ── Navigation buttons ─────────────────────────────────────── */
        .wizard-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: var(--om-space-6);
            padding-top: var(--om-space-4);
            border-top: 1px solid var(--om-gray-100);
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: var(--om-space-2);
            padding: var(--om-space-3) var(--om-space-5);
            border: 1px solid var(--om-gray-300);
            border-radius: var(--om-radius-lg);
            background: var(--om-white);
            color: var(--om-text-secondary);
            font-weight: var(--om-font-medium);
            font-size: var(--om-font-sm);
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: var(--om-font-family);
        }

        .btn-back:hover {
            background: var(--om-gray-50);
            border-color: var(--om-gray-400);
        }

        .btn-next {
            display: inline-flex;
            align-items: center;
            gap: var(--om-space-2);
            padding: var(--om-space-3) var(--om-space-6);
            border: none;
            border-radius: var(--om-radius-lg);
            background: var(--om-primary);
            color: var(--om-white);
            font-weight: var(--om-font-semibold);
            font-size: var(--om-font-sm);
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: var(--om-font-family);
        }

        .btn-next:hover:not(:disabled) {
            background: var(--om-primary-dark);
        }

        .btn-next:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-submit {
            width: 100%;
            padding: var(--om-space-4) var(--om-space-6);
            font-size: var(--om-font-base);
        }

        /* ── Spinner inline ─────────────────────────────────────────── */
        .spinner-sm {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid var(--om-gray-200);
            border-top-color: var(--om-primary);
            border-radius: 50%;
            animation: om-spin 0.8s linear infinite;
        }

        .spinner-white {
            border-color: rgba(255,255,255,0.3);
            border-top-color: var(--om-white);
        }

        @keyframes om-spin {
            to { transform: rotate(360deg); }
        }

        /* ── Final success overlay ──────────────────────────────────── */
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

        /* ── Footer ─────────────────────────────────────────────────── */
        .wizard-footer {
            text-align: center;
            margin-top: var(--om-space-6);
            font-size: var(--om-font-sm);
            color: var(--om-text-muted);
        }

        .wizard-footer a {
            color: var(--om-primary);
            font-weight: var(--om-font-medium);
        }

        /* ── Global alert ───────────────────────────────────────────── */
        #globalAlert {
            display: none;
            padding: var(--om-space-3) var(--om-space-4);
            border-radius: var(--om-radius-lg);
            margin-bottom: var(--om-space-4);
            font-size: var(--om-font-sm);
            align-items: center;
            gap: var(--om-space-2);
        }

        #globalAlert.visible {
            display: flex;
        }

        .om-input[readonly] {
            background: var(--om-gray-50);
            color: var(--om-text-secondary);
        }
    </style>
</head>
<body>
    <div class="wizard-container">
        <div class="wizard-card">
            <!-- Brand -->
            <div class="wizard-brand">
                <div class="wizard-brand-name">SuperBora</div>
                <div class="wizard-brand-sub">Cadastre sua loja e comece a vender hoje</div>
            </div>

            <!-- Progress bar -->
            <div class="progress-bar" id="progressBar">
                <div class="progress-step active" data-step="1">
                    <div class="step-circle"><span>1</span></div>
                    <div class="step-label">Documento</div>
                </div>
                <div class="progress-line" data-line="1"></div>
                <div class="progress-step" data-step="2">
                    <div class="step-circle"><span>2</span></div>
                    <div class="step-label">Loja</div>
                </div>
                <div class="progress-line" data-line="2"></div>
                <div class="progress-step" data-step="3">
                    <div class="step-circle"><span>3</span></div>
                    <div class="step-label">Endereco</div>
                </div>
                <div class="progress-line" data-line="3"></div>
                <div class="progress-step" data-step="4">
                    <div class="step-circle"><span>4</span></div>
                    <div class="step-label">Responsavel</div>
                </div>
                <div class="progress-line" data-line="4"></div>
                <div class="progress-step" data-step="5">
                    <div class="step-circle"><span>5</span></div>
                    <div class="step-label">Plano</div>
                </div>
                <div class="progress-line" data-line="5"></div>
                <div class="progress-step" data-step="6">
                    <div class="step-circle"><span>6</span></div>
                    <div class="step-label">Confirmar</div>
                </div>
            </div>

            <!-- Global alert -->
            <div id="globalAlert"></div>

            <!-- ═══════════════════════════════════════════════════════
                 STEP 1 - Documento (CPF ou CNPJ)
                 ═══════════════════════════════════════════════════════ -->
            <div class="step-content active visible" id="step1">
                <div class="step-title">Documento</div>
                <div class="step-subtitle">Informe o CPF ou CNPJ da sua empresa</div>

                <div class="radio-group">
                    <label class="radio-option selected" id="radioOptCnpj" onclick="selectDocType('cnpj')">
                        <input type="radio" name="doc_type" value="cnpj" checked>
                        <i class="lucide-building-2"></i> CNPJ
                    </label>
                    <label class="radio-option" id="radioOptCpf" onclick="selectDocType('cpf')">
                        <input type="radio" name="doc_type" value="cpf">
                        <i class="lucide-user"></i> CPF
                    </label>
                </div>

                <!-- CPF fields -->
                <div id="cpfFields" style="display:none;">
                    <div class="om-form-group">
                        <label class="om-label" for="cpfInput">CPF *</label>
                        <input type="text" id="cpfInput" class="om-input" maxlength="14" placeholder="000.000.000-00" autocomplete="off">
                        <div class="field-error-msg" id="cpfError">Informe um CPF valido</div>
                    </div>
                    <div class="alert-warning" id="cpfWarning">
                        <i class="lucide-alert-triangle" style="flex-shrink:0;margin-top:2px;"></i>
                        <span>Com CPF voce pode usar a plataforma por 1 ano. Apos isso, sera necessario um CNPJ.</span>
                    </div>
                </div>

                <!-- CNPJ fields -->
                <div id="cnpjFields">
                    <div class="om-form-group">
                        <label class="om-label" for="cnpjInput">CNPJ *</label>
                        <div style="display:flex;gap:var(--om-space-2);">
                            <input type="text" id="cnpjInput" class="om-input" maxlength="18" placeholder="00.000.000/0000-00" autocomplete="off" style="flex:1;">
                            <button type="button" id="btnConsultarCnpj" class="om-btn om-btn-outline" onclick="consultarCNPJ()" style="white-space:nowrap;">
                                <i class="lucide-search"></i> Consultar
                            </button>
                        </div>
                        <div class="field-error-msg" id="cnpjError">Informe um CNPJ valido</div>
                    </div>

                    <div class="cnpj-result" id="cnpjResult">
                        <div class="cnpj-result-row">
                            <span class="cnpj-result-label">Razao Social</span>
                            <span class="cnpj-result-value" id="cnpjRazao">-</span>
                        </div>
                        <div class="cnpj-result-row">
                            <span class="cnpj-result-label">Nome Fantasia</span>
                            <span class="cnpj-result-value" id="cnpjFantasia">-</span>
                        </div>
                        <div class="cnpj-result-row">
                            <span class="cnpj-result-label">Situacao</span>
                            <span class="cnpj-result-value" id="cnpjSituacao">-</span>
                        </div>
                        <div class="cnpj-result-row">
                            <span class="cnpj-result-label">CNAE Valido</span>
                            <span class="cnpj-result-value" id="cnpjCnae">-</span>
                        </div>
                    </div>

                    <div class="alert-error" id="cnaeError" style="display:none;">
                        <i class="lucide-x-circle" style="flex-shrink:0;margin-top:2px;"></i>
                        <span>O CNAE do seu CNPJ nao e compativel com a plataforma. Verifique sua atividade economica.</span>
                    </div>

                    <div class="alert-success" id="cnpjSuccess" style="display:none;">
                        <i class="lucide-check-circle" style="flex-shrink:0;margin-top:2px;"></i>
                        <span>CNPJ consultado com sucesso! Dados carregados automaticamente.</span>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════
                 STEP 2 - Loja
                 ═══════════════════════════════════════════════════════ -->
            <div class="step-content" id="step2">
                <div class="step-title">Dados da Loja</div>
                <div class="step-subtitle">Como sua loja aparecera no aplicativo</div>

                <div class="om-form-group">
                    <label class="om-label" for="nomeApp">Nome que aparece no app *</label>
                    <input type="text" id="nomeApp" class="om-input" maxlength="100" placeholder="Ex: Mercado Bom Preco">
                    <div class="field-error-msg" id="nomeAppError">Informe o nome da loja</div>
                </div>

                <div class="form-row">
                    <div class="om-form-group">
                        <label class="om-label" for="categoriaSelect">Categoria *</label>
                        <select id="categoriaSelect" class="om-input">
                            <option value="">Selecione...</option>
                            <option value="mercado">Mercado</option>
                            <option value="restaurante">Restaurante</option>
                            <option value="farmacia">Farmacia</option>
                            <option value="loja">Loja</option>
                            <option value="padaria">Padaria</option>
                            <option value="acougue">Acougue</option>
                            <option value="hortifruti">Hortifruti</option>
                            <option value="petshop">Petshop</option>
                            <option value="conveniencia">Conveniencia</option>
                            <option value="bebidas">Bebidas</option>
                            <option value="outros">Outros</option>
                        </select>
                        <div class="field-error-msg" id="categoriaError">Selecione uma categoria</div>
                    </div>
                    <div class="om-form-group">
                        <label class="om-label" for="especialidade">Especialidade *</label>
                        <input type="text" id="especialidade" class="om-input" maxlength="100" placeholder="Ex: Pizzaria, Sushi, Lanches">
                        <div class="field-error-msg" id="especialidadeError">Informe a especialidade</div>
                    </div>
                </div>

                <!-- Logo upload -->
                <div class="om-form-group">
                    <label class="om-label">Logo da loja (opcional)</label>
                    <div class="logo-upload-area">
                        <div class="logo-preview-circle" id="logoCircle" onclick="document.getElementById('logoFile').click()">
                            <i class="lucide-camera" id="logoIcon"></i>
                            <img id="logoPreview" src="" alt="" style="display:none;">
                        </div>
                        <div class="logo-upload-text">
                            <strong onclick="document.getElementById('logoFile').click()">Clique para enviar</strong><br>
                            <span style="font-size:var(--om-font-xs);color:var(--om-text-muted);">JPG, PNG. Max 2MB.</span>
                        </div>
                    </div>
                    <input type="file" id="logoFile" accept="image/*" style="display:none;" onchange="previewLogo(this)">
                    <input type="hidden" id="logoUrl" value="">
                </div>

                <!-- Razao Social (only for CNPJ) -->
                <div class="om-form-group" id="razaoSocialGroup" style="display:none;">
                    <label class="om-label" for="razaoSocial">Razao Social</label>
                    <input type="text" id="razaoSocial" class="om-input" readonly>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════
                 STEP 3 - Endereco
                 ═══════════════════════════════════════════════════════ -->
            <div class="step-content" id="step3">
                <div class="step-title">Endereco</div>
                <div class="step-subtitle">Onde sua loja esta localizada</div>

                <div class="form-row">
                    <div class="om-form-group">
                        <label class="om-label" for="cepInput">CEP *</label>
                        <div style="position:relative;">
                            <input type="text" id="cepInput" class="om-input" maxlength="9" placeholder="00000-000" autocomplete="off">
                            <div id="cepSpinner" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);display:none;">
                                <div class="spinner-sm"></div>
                            </div>
                        </div>
                        <div class="field-error-msg" id="cepError">Informe um CEP valido</div>
                    </div>
                    <div class="om-form-group">
                        <label class="om-label" for="endNumero">Numero *</label>
                        <input type="text" id="endNumero" class="om-input" maxlength="10" placeholder="123">
                        <div class="field-error-msg" id="endNumeroError">Informe o numero</div>
                    </div>
                </div>

                <div class="om-form-group">
                    <label class="om-label" for="endRua">Endereco / Rua *</label>
                    <input type="text" id="endRua" class="om-input" maxlength="255" placeholder="Rua, Avenida...">
                    <div class="field-error-msg" id="endRuaError">Informe o endereco</div>
                </div>

                <div class="om-form-group">
                    <label class="om-label" for="endComplemento">Complemento</label>
                    <input type="text" id="endComplemento" class="om-input" maxlength="100" placeholder="Sala, Bloco, Loja...">
                </div>

                <div class="form-row-3">
                    <div class="om-form-group">
                        <label class="om-label" for="endBairro">Bairro *</label>
                        <input type="text" id="endBairro" class="om-input" maxlength="100" placeholder="Bairro">
                        <div class="field-error-msg" id="endBairroError">Informe o bairro</div>
                    </div>
                    <div class="om-form-group">
                        <label class="om-label" for="endCidade">Cidade *</label>
                        <input type="text" id="endCidade" class="om-input" maxlength="100" placeholder="Cidade">
                        <div class="field-error-msg" id="endCidadeError">Informe a cidade</div>
                    </div>
                    <div class="om-form-group">
                        <label class="om-label" for="endEstado">Estado *</label>
                        <input type="text" id="endEstado" class="om-input" maxlength="2" placeholder="UF">
                        <div class="field-error-msg" id="endEstadoError">Informe o estado</div>
                    </div>
                </div>

                <div id="map"></div>

                <input type="hidden" id="endLat" value="">
                <input type="hidden" id="endLng" value="">
            </div>

            <!-- ═══════════════════════════════════════════════════════
                 STEP 4 - Responsavel
                 ═══════════════════════════════════════════════════════ -->
            <div class="step-content" id="step4">
                <div class="step-title">Responsavel</div>
                <div class="step-subtitle">Dados do responsavel pela loja</div>

                <div class="om-form-group">
                    <label class="om-label" for="respNome">Nome completo *</label>
                    <input type="text" id="respNome" class="om-input" maxlength="255" placeholder="Seu nome completo">
                    <div class="field-error-msg" id="respNomeError">Informe o nome completo</div>
                </div>

                <div class="form-row">
                    <div class="om-form-group">
                        <label class="om-label" for="respCpf">CPF do responsavel *</label>
                        <input type="text" id="respCpf" class="om-input" maxlength="14" placeholder="000.000.000-00" autocomplete="off">
                        <div class="field-error-msg" id="respCpfError">Informe um CPF valido</div>
                    </div>
                    <div class="om-form-group">
                        <label class="om-label" for="respTelefone">Telefone *</label>
                        <input type="tel" id="respTelefone" class="om-input" maxlength="15" placeholder="(00) 00000-0000" autocomplete="off">
                        <div class="field-error-msg" id="respTelefoneError">Informe o telefone</div>
                    </div>
                </div>

                <div class="om-form-group">
                    <label class="om-label" for="respEmail">Email *</label>
                    <input type="email" id="respEmail" class="om-input" maxlength="255" placeholder="contato@sualoja.com">
                    <div class="field-error-msg" id="respEmailError">Informe um email valido</div>
                </div>

                <div class="form-row">
                    <div class="om-form-group">
                        <label class="om-label" for="respSenha">Senha * <small style="color:var(--om-text-muted);">(min 6 caracteres)</small></label>
                        <input type="password" id="respSenha" class="om-input" minlength="6" maxlength="50" placeholder="Crie uma senha">
                        <div class="field-error-msg" id="respSenhaError">Senha deve ter no minimo 6 caracteres</div>
                    </div>
                    <div class="om-form-group">
                        <label class="om-label" for="respSenhaConfirm">Confirmar senha *</label>
                        <input type="password" id="respSenhaConfirm" class="om-input" minlength="6" maxlength="50" placeholder="Repita a senha">
                        <div class="field-error-msg" id="respSenhaConfirmError">As senhas nao conferem</div>
                    </div>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════
                 STEP 5 - Plano
                 ═══════════════════════════════════════════════════════ -->
            <div class="step-content" id="step5">
                <div class="step-title">Escolha seu Plano</div>
                <div class="step-subtitle">Selecione o plano ideal para seu negocio</div>

                <div class="plans-grid">
                    <!-- Basico - Entrega Propria -->
                    <div class="plan-card selected" id="planBasico" onclick="selectPlan('basico')">
                        <span class="plan-tag plan-tag-basic">Entrega Propria</span>
                        <div class="plan-name">Tenho Entregador</div>
                        <div class="plan-price">Gratis para comecar</div>
                        <div class="plan-commission">
                            <strong>10%</strong> de comissao
                        </div>
                        <ul class="plan-features">
                            <li><i class="lucide-check"></i> Listagem no app</li>
                            <li><i class="lucide-check"></i> Painel de gestao completo</li>
                            <li><i class="lucide-check"></i> Suporte via chat</li>
                            <li><i class="lucide-check"></i> Voce gerencia suas entregas</li>
                        </ul>
                        <div class="plan-note">Entrega com seus proprios entregadores</div>
                    </div>

                    <!-- Premium - BoraUm -->
                    <div class="plan-card" id="planPremium" onclick="selectPlan('premium')">
                        <span class="plan-tag plan-tag-premium">BoraUm</span>
                        <div class="plan-name">Usar BoraUm</div>
                        <div class="plan-price">Entrega sem preocupacao</div>
                        <div class="plan-commission">
                            <strong>18%</strong> de comissao
                        </div>
                        <ul class="plan-features">
                            <li><i class="lucide-check"></i> Tudo do plano anterior +</li>
                            <li><i class="lucide-check"></i> Entregadores BoraUm</li>
                            <li><i class="lucide-check"></i> Marketing destacado</li>
                            <li><i class="lucide-check"></i> IA assistente</li>
                        </ul>
                        <div class="plan-note">Entregadores da plataforma cuidam da entrega</div>
                        <div class="plan-unavailable-msg">
                            <i class="lucide-info"></i> Ainda nao temos entregadores BoraUm na sua regiao. Em breve!
                        </div>
                    </div>
                </div>

                <div class="pickup-note">
                    <i class="lucide-shopping-bag" style="flex-shrink:0;"></i>
                    <span>Pedidos para retirada: <strong>10% de comissao</strong> (todos os planos)</span>
                </div>

                <input type="hidden" id="planoSelecionado" value="basico">
            </div>

            <!-- ═══════════════════════════════════════════════════════
                 STEP 6 - Confirmacao
                 ═══════════════════════════════════════════════════════ -->
            <div class="step-content" id="step6">
                <div id="summaryView">
                    <div class="step-title">Confirme seus dados</div>
                    <div class="step-subtitle">Revise as informacoes antes de finalizar</div>

                    <!-- Documento -->
                    <div class="summary-section">
                        <div class="summary-section-title"><i class="lucide-file-text"></i> Documento</div>
                        <div class="summary-grid" id="summaryDocumento"></div>
                    </div>

                    <!-- Loja -->
                    <div class="summary-section">
                        <div class="summary-section-title"><i class="lucide-store"></i> Loja</div>
                        <div class="summary-grid" id="summaryLoja"></div>
                    </div>

                    <!-- Endereco -->
                    <div class="summary-section">
                        <div class="summary-section-title"><i class="lucide-map-pin"></i> Endereco</div>
                        <div class="summary-grid" id="summaryEndereco"></div>
                    </div>

                    <!-- Responsavel -->
                    <div class="summary-section">
                        <div class="summary-section-title"><i class="lucide-user"></i> Responsavel</div>
                        <div class="summary-grid" id="summaryResponsavel"></div>
                    </div>

                    <!-- Plano -->
                    <div class="summary-section">
                        <div class="summary-section-title"><i class="lucide-star"></i> Plano</div>
                        <div class="summary-grid" id="summaryPlano"></div>
                    </div>

                    <!-- Terms -->
                    <div class="checkbox-row">
                        <input type="checkbox" id="termsCheck">
                        <label for="termsCheck">Li e aceito os <a href="/termos" target="_blank">termos de uso</a> e <a href="/privacidade" target="_blank">politica de privacidade</a></label>
                    </div>

                    <div id="submitError" class="alert-error" style="display:none;">
                        <i class="lucide-alert-circle" style="flex-shrink:0;margin-top:2px;"></i>
                        <span id="submitErrorMsg"></span>
                    </div>
                </div>

                <!-- Success overlay -->
                <div class="success-overlay" id="successOverlay">
                    <div class="success-icon">
                        <i class="lucide-check"></i>
                    </div>
                    <div class="success-title">Cadastro realizado!</div>
                    <div class="success-message">
                        Voce recebera um email em ate 24 horas com a aprovacao.<br>
                        Redirecionando para o painel...
                    </div>
                    <div class="spinner-sm" style="margin:0 auto;"></div>
                </div>
            </div>

            <!-- Navigation -->
            <div class="wizard-nav" id="wizardNav">
                <button type="button" class="btn-back" id="btnBack" onclick="prevStep()" style="visibility:hidden;">
                    <i class="lucide-arrow-left"></i> Voltar
                </button>
                <button type="button" class="btn-next" id="btnNext" onclick="nextStep()">
                    Proximo <i class="lucide-arrow-right"></i>
                </button>
            </div>
        </div>

        <div class="wizard-footer">
            Ja tem uma conta? <a href="login.php">Faca login</a>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
    /* ═══════════════════════════════════════════════════════════════════════════
       STATE
       ═══════════════════════════════════════════════════════════════════════════ */
    let currentStep = 1;
    const totalSteps = 6;
    let cnpjData = null;
    let cnaeValid = false;
    let premiumAvailable = false;
    let map = null;
    let marker = null;
    let mapInitialized = false;

    /* ═══════════════════════════════════════════════════════════════════════════
       NAVIGATION
       ═══════════════════════════════════════════════════════════════════════════ */
    function goToStep(step) {
        if (step < 1 || step > totalSteps) return;

        // Hide current step
        const currentEl = document.getElementById('step' + currentStep);
        currentEl.classList.remove('visible');
        setTimeout(() => {
            currentEl.classList.remove('active');

            currentStep = step;

            // Show new step
            const newEl = document.getElementById('step' + currentStep);
            newEl.classList.add('active');
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    newEl.classList.add('visible');
                });
            });

            updateProgressBar();
            updateNavButtons();

            // Step-specific actions
            if (currentStep === 2) {
                onStep2Enter();
            }
            if (currentStep === 3 && !mapInitialized) {
                setTimeout(initMap, 200);
            }
            if (currentStep === 5) {
                checkPremiumAvailability();
            }
            if (currentStep === 6) {
                buildSummary();
            }

            // Scroll to top
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }, 200);
    }

    function nextStep() {
        if (!validateStep(currentStep)) return;
        goToStep(currentStep + 1);
    }

    function prevStep() {
        goToStep(currentStep - 1);
    }

    function updateProgressBar() {
        document.querySelectorAll('.progress-step').forEach(el => {
            const s = parseInt(el.dataset.step);
            el.classList.remove('active', 'completed');
            if (s === currentStep) {
                el.classList.add('active');
                el.querySelector('.step-circle').innerHTML = '<span>' + s + '</span>';
            } else if (s < currentStep) {
                el.classList.add('completed');
                el.querySelector('.step-circle').innerHTML = '<i class="lucide-check"></i>';
            } else {
                el.querySelector('.step-circle').innerHTML = '<span>' + s + '</span>';
            }
        });

        document.querySelectorAll('.progress-line').forEach(el => {
            const l = parseInt(el.dataset.line);
            el.classList.toggle('filled', l < currentStep);
        });
    }

    function updateNavButtons() {
        const btnBack = document.getElementById('btnBack');
        const btnNext = document.getElementById('btnNext');
        const nav = document.getElementById('wizardNav');

        btnBack.style.visibility = currentStep === 1 ? 'hidden' : 'visible';

        if (currentStep === totalSteps) {
            btnNext.innerHTML = '<i class="lucide-check-circle"></i> Finalizar Cadastro';
            btnNext.classList.add('btn-submit');
            btnNext.onclick = submitCadastro;
        } else {
            btnNext.innerHTML = 'Proximo <i class="lucide-arrow-right"></i>';
            btnNext.classList.remove('btn-submit');
            btnNext.onclick = nextStep;
        }

        // Hide nav after success
        if (document.getElementById('successOverlay').classList.contains('visible')) {
            nav.style.display = 'none';
        }
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       VALIDATION
       ═══════════════════════════════════════════════════════════════════════════ */
    function validateStep(step) {
        clearErrors();
        let valid = true;

        if (step === 1) {
            const docType = getDocType();
            if (docType === 'cpf') {
                const cpf = document.getElementById('cpfInput').value.replace(/\D/g, '');
                if (cpf.length !== 11) {
                    showFieldError('cpfInput', 'cpfError');
                    valid = false;
                }
            } else {
                const cnpj = document.getElementById('cnpjInput').value.replace(/\D/g, '');
                if (cnpj.length !== 14) {
                    showFieldError('cnpjInput', 'cnpjError');
                    valid = false;
                }
                if (cnpjData && !cnaeValid) {
                    valid = false;
                    showGlobalAlert('O CNAE do seu CNPJ nao e compativel. Nao e possivel avancar.', 'error');
                }
            }
        }

        if (step === 2) {
            if (!document.getElementById('nomeApp').value.trim()) {
                showFieldError('nomeApp', 'nomeAppError');
                valid = false;
            }
            if (!document.getElementById('categoriaSelect').value) {
                showFieldError('categoriaSelect', 'categoriaError');
                valid = false;
            }
            if (!document.getElementById('especialidade').value.trim()) {
                showFieldError('especialidade', 'especialidadeError');
                valid = false;
            }
        }

        if (step === 3) {
            const cep = document.getElementById('cepInput').value.replace(/\D/g, '');
            if (cep.length !== 8) {
                showFieldError('cepInput', 'cepError');
                valid = false;
            }
            if (!document.getElementById('endRua').value.trim()) {
                showFieldError('endRua', 'endRuaError');
                valid = false;
            }
            if (!document.getElementById('endNumero').value.trim()) {
                showFieldError('endNumero', 'endNumeroError');
                valid = false;
            }
            if (!document.getElementById('endBairro').value.trim()) {
                showFieldError('endBairro', 'endBairroError');
                valid = false;
            }
            if (!document.getElementById('endCidade').value.trim()) {
                showFieldError('endCidade', 'endCidadeError');
                valid = false;
            }
            if (!document.getElementById('endEstado').value.trim()) {
                showFieldError('endEstado', 'endEstadoError');
                valid = false;
            }
        }

        if (step === 4) {
            if (!document.getElementById('respNome').value.trim()) {
                showFieldError('respNome', 'respNomeError');
                valid = false;
            }
            const cpf = document.getElementById('respCpf').value.replace(/\D/g, '');
            if (cpf.length !== 11) {
                showFieldError('respCpf', 'respCpfError');
                valid = false;
            }
            const tel = document.getElementById('respTelefone').value.replace(/\D/g, '');
            if (tel.length < 10) {
                showFieldError('respTelefone', 'respTelefoneError');
                valid = false;
            }
            const email = document.getElementById('respEmail').value.trim();
            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                showFieldError('respEmail', 'respEmailError');
                valid = false;
            }
            const senha = document.getElementById('respSenha').value;
            if (senha.length < 6) {
                showFieldError('respSenha', 'respSenhaError');
                valid = false;
            }
            const confirm = document.getElementById('respSenhaConfirm').value;
            if (confirm !== senha) {
                showFieldError('respSenhaConfirm', 'respSenhaConfirmError');
                valid = false;
            }
        }

        // Step 5: always valid (plan is pre-selected)
        // Step 6: checked separately in submit

        return valid;
    }

    function showFieldError(inputId, errorId) {
        document.getElementById(inputId).classList.add('field-error');
        document.getElementById(errorId).classList.add('visible');
    }

    function clearErrors() {
        document.querySelectorAll('.field-error').forEach(el => el.classList.remove('field-error'));
        document.querySelectorAll('.field-error-msg').forEach(el => el.classList.remove('visible'));
        hideGlobalAlert();
    }

    function showGlobalAlert(msg, type) {
        const el = document.getElementById('globalAlert');
        el.textContent = msg;
        el.style.background = type === 'error' ? 'var(--om-error-bg)' : type === 'success' ? 'var(--om-success-bg)' : 'var(--om-info-bg)';
        el.style.color = type === 'error' ? 'var(--om-error-dark)' : type === 'success' ? 'var(--om-success-dark)' : 'var(--om-info-dark)';
        el.style.border = '1px solid ' + (type === 'error' ? 'var(--om-error-light)' : type === 'success' ? 'var(--om-success-light)' : 'var(--om-info-light)');
        el.classList.add('visible');
    }

    function hideGlobalAlert() {
        document.getElementById('globalAlert').classList.remove('visible');
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       STEP 1 - DOCUMENT TYPE
       ═══════════════════════════════════════════════════════════════════════════ */
    function getDocType() {
        return document.querySelector('input[name="doc_type"]:checked').value;
    }

    function selectDocType(type) {
        document.querySelectorAll('.radio-option').forEach(el => el.classList.remove('selected'));
        if (type === 'cnpj') {
            document.getElementById('radioOptCnpj').classList.add('selected');
            document.getElementById('cnpjFields').style.display = '';
            document.getElementById('cpfFields').style.display = 'none';
            document.querySelector('input[name="doc_type"][value="cnpj"]').checked = true;
        } else {
            document.getElementById('radioOptCpf').classList.add('selected');
            document.getElementById('cpfFields').style.display = '';
            document.getElementById('cnpjFields').style.display = 'none';
            document.querySelector('input[name="doc_type"][value="cpf"]').checked = true;
        }
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       STEP 1 - CNPJ CONSULTATION
       ═══════════════════════════════════════════════════════════════════════════ */
    async function consultarCNPJ() {
        const cnpj = document.getElementById('cnpjInput').value.replace(/\D/g, '');
        if (cnpj.length !== 14) {
            showFieldError('cnpjInput', 'cnpjError');
            return;
        }

        const btn = document.getElementById('btnConsultarCnpj');
        btn.disabled = true;
        btn.innerHTML = '<div class="spinner-sm"></div>';

        document.getElementById('cnpjResult').classList.remove('visible');
        document.getElementById('cnaeError').style.display = 'none';
        document.getElementById('cnpjSuccess').style.display = 'none';

        try {
            const res = await fetch('/api/mercado/parceiro/consultar-cnpj.php?cnpj=' + cnpj);
            const data = await res.json();

            if (data.success) {
                cnpjData = data.data || data;

                document.getElementById('cnpjRazao').textContent = cnpjData.razao_social || cnpjData.razaoSocial || '-';
                document.getElementById('cnpjFantasia').textContent = cnpjData.nome_fantasia || cnpjData.nomeFantasia || '-';
                document.getElementById('cnpjSituacao').textContent = cnpjData.situacao || '-';

                cnaeValid = cnpjData.cnae_valido !== false && cnpjData.cnaeValido !== false;
                document.getElementById('cnpjCnae').textContent = cnaeValid ? 'Sim' : 'Nao';
                document.getElementById('cnpjCnae').style.color = cnaeValid ? 'var(--om-success)' : 'var(--om-error)';

                document.getElementById('cnpjResult').classList.add('visible');

                if (!cnaeValid) {
                    document.getElementById('cnaeError').style.display = 'flex';
                } else {
                    document.getElementById('cnpjSuccess').style.display = 'flex';
                }
            } else {
                showGlobalAlert(data.message || 'Erro ao consultar CNPJ', 'error');
            }
        } catch (e) {
            showGlobalAlert('Erro de conexao ao consultar CNPJ', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="lucide-search"></i> Consultar';
        }
    }

    // Auto-consult when 14 digits
    let cnpjAutoTimer = null;
    document.getElementById('cnpjInput').addEventListener('input', function() {
        clearTimeout(cnpjAutoTimer);
        const digits = this.value.replace(/\D/g, '');
        if (digits.length === 14) {
            cnpjAutoTimer = setTimeout(() => consultarCNPJ(), 400);
        }
    });

    /* ═══════════════════════════════════════════════════════════════════════════
       STEP 2 - ENTER (pre-fill from CNPJ)
       ═══════════════════════════════════════════════════════════════════════════ */
    function onStep2Enter() {
        const docType = getDocType();
        const razaoGroup = document.getElementById('razaoSocialGroup');

        if (docType === 'cnpj') {
            razaoGroup.style.display = '';
            if (cnpjData) {
                const fantasia = cnpjData.nome_fantasia || cnpjData.nomeFantasia || '';
                const razao = cnpjData.razao_social || cnpjData.razaoSocial || '';
                if (fantasia && !document.getElementById('nomeApp').value.trim()) {
                    document.getElementById('nomeApp').value = fantasia;
                }
                document.getElementById('razaoSocial').value = razao;
            }
        } else {
            razaoGroup.style.display = 'none';
        }
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       STEP 2 - LOGO UPLOAD
       ═══════════════════════════════════════════════════════════════════════════ */
    function previewLogo(input) {
        if (input.files && input.files[0]) {
            const file = input.files[0];
            if (file.size > 2 * 1024 * 1024) {
                showGlobalAlert('Imagem muito grande. Maximo 2MB.', 'error');
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('logoPreview').src = e.target.result;
                document.getElementById('logoPreview').style.display = 'block';
                document.getElementById('logoIcon').style.display = 'none';
            };
            reader.readAsDataURL(file);
            uploadLogo(file);
        }
    }

    async function uploadLogo(file) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('type', 'logo');

        try {
            const res = await fetch('/api/mercado/upload.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                document.getElementById('logoUrl').value = data.data.url || data.url || '';
            }
        } catch (e) {
            console.error('Erro ao enviar logo:', e);
        }
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       STEP 3 - CEP LOOKUP + MAP
       ═══════════════════════════════════════════════════════════════════════════ */
    function initMap() {
        if (mapInitialized) return;
        mapInitialized = true;

        // Default: center of Brazil
        map = L.map('map').setView([-15.79, -47.88], 4);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap'
        }).addTo(map);

        // If we already have coords (from CEP), place marker
        const lat = parseFloat(document.getElementById('endLat').value);
        const lng = parseFloat(document.getElementById('endLng').value);
        if (lat && lng) {
            placeMarker(lat, lng);
            map.setView([lat, lng], 16);
        }
    }

    function placeMarker(lat, lng) {
        if (marker) {
            marker.setLatLng([lat, lng]);
        } else {
            marker = L.marker([lat, lng], { draggable: true }).addTo(map);
            marker.on('dragend', function(e) {
                const pos = e.target.getLatLng();
                document.getElementById('endLat').value = pos.lat.toFixed(7);
                document.getElementById('endLng').value = pos.lng.toFixed(7);
            });
        }
        document.getElementById('endLat').value = lat.toFixed(7);
        document.getElementById('endLng').value = lng.toFixed(7);
    }

    async function geocodeAddress() {
        const rua = document.getElementById('endRua').value.trim();
        const cidade = document.getElementById('endCidade').value.trim();
        const estado = document.getElementById('endEstado').value.trim();

        if (!rua || !cidade) return;

        const query = encodeURIComponent(rua + ', ' + cidade + ', ' + estado + ', Brazil');
        try {
            const res = await fetch('https://nominatim.openstreetmap.org/search?format=json&q=' + query + '&limit=1', {
                headers: { 'Accept-Language': 'pt-BR' }
            });
            const data = await res.json();
            if (data && data.length > 0) {
                const lat = parseFloat(data[0].lat);
                const lng = parseFloat(data[0].lon);
                if (map) {
                    placeMarker(lat, lng);
                    map.setView([lat, lng], 16);
                } else {
                    document.getElementById('endLat').value = lat.toFixed(7);
                    document.getElementById('endLng').value = lng.toFixed(7);
                }
            }
        } catch (e) {
            console.error('Geocoding error:', e);
        }
    }

    // CEP auto-fill
    let cepTimer = null;
    document.getElementById('cepInput').addEventListener('input', function() {
        clearTimeout(cepTimer);
        const cep = this.value.replace(/\D/g, '');
        if (cep.length === 8) {
            cepTimer = setTimeout(() => buscarCEP(cep), 300);
        }
    });

    async function buscarCEP(cep) {
        document.getElementById('cepSpinner').style.display = '';

        try {
            const res = await fetch('https://viacep.com.br/ws/' + cep + '/json/');
            const data = await res.json();

            if (!data.erro) {
                document.getElementById('endRua').value = data.logradouro || '';
                document.getElementById('endBairro').value = data.bairro || '';
                document.getElementById('endCidade').value = data.localidade || '';
                document.getElementById('endEstado').value = data.uf || '';

                // Focus on numero
                document.getElementById('endNumero').focus();

                // Geocode after fill
                setTimeout(geocodeAddress, 300);
            }
        } catch (e) {
            console.error('CEP lookup error:', e);
        } finally {
            document.getElementById('cepSpinner').style.display = 'none';
        }
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       STEP 5 - PLANS
       ═══════════════════════════════════════════════════════════════════════════ */
    function selectPlan(plan) {
        const premiumCard = document.getElementById('planPremium');
        if (plan === 'premium' && premiumCard.classList.contains('plan-disabled')) return;

        document.getElementById('planBasico').classList.remove('selected');
        document.getElementById('planPremium').classList.remove('selected');
        document.getElementById('plan' + plan.charAt(0).toUpperCase() + plan.slice(1)).classList.add('selected');
        document.getElementById('planoSelecionado').value = plan;
    }

    async function checkPremiumAvailability() {
        const lat = document.getElementById('endLat').value;
        const lng = document.getElementById('endLng').value;

        if (!lat || !lng) {
            // No coords, disable premium
            disablePremium();
            return;
        }

        try {
            const res = await fetch('/api/mercado/parceiro/check-premium.php?lat=' + lat + '&lng=' + lng);
            const data = await res.json();
            premiumAvailable = data.premium_available === true || data.premiumAvailable === true;
        } catch (e) {
            premiumAvailable = false;
        }

        if (!premiumAvailable) {
            disablePremium();
        } else {
            enablePremium();
        }
    }

    function disablePremium() {
        const el = document.getElementById('planPremium');
        el.classList.add('plan-disabled');
        el.classList.remove('selected');
        // Ensure basico is selected
        document.getElementById('planBasico').classList.add('selected');
        document.getElementById('planoSelecionado').value = 'basico';
    }

    function enablePremium() {
        document.getElementById('planPremium').classList.remove('plan-disabled');
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       STEP 6 - SUMMARY
       ═══════════════════════════════════════════════════════════════════════════ */
    function buildSummary() {
        const docType = getDocType();

        // Documento
        let docHtml = '';
        if (docType === 'cpf') {
            docHtml = summaryRow('Tipo', 'CPF') + summaryRow('CPF', document.getElementById('cpfInput').value);
        } else {
            docHtml = summaryRow('Tipo', 'CNPJ') + summaryRow('CNPJ', document.getElementById('cnpjInput').value);
            if (cnpjData) {
                docHtml += summaryRow('Razao Social', cnpjData.razao_social || cnpjData.razaoSocial || '-');
            }
        }
        document.getElementById('summaryDocumento').innerHTML = docHtml;

        // Loja
        const catSelect = document.getElementById('categoriaSelect');
        const catText = catSelect.options[catSelect.selectedIndex]?.text || '-';
        let lojaHtml = summaryRow('Nome', document.getElementById('nomeApp').value);
        lojaHtml += summaryRow('Categoria', catText);
        lojaHtml += summaryRow('Especialidade', document.getElementById('especialidade').value);
        if (docType === 'cnpj' && document.getElementById('razaoSocial').value) {
            lojaHtml += summaryRow('Razao Social', document.getElementById('razaoSocial').value);
        }
        document.getElementById('summaryLoja').innerHTML = lojaHtml;

        // Endereco
        let endHtml = summaryRow('CEP', document.getElementById('cepInput').value);
        let endereco = document.getElementById('endRua').value;
        const numero = document.getElementById('endNumero').value;
        if (numero) endereco += ', ' + numero;
        const complemento = document.getElementById('endComplemento').value;
        if (complemento) endereco += ' - ' + complemento;
        endHtml += summaryRow('Endereco', endereco);
        endHtml += summaryRow('Bairro', document.getElementById('endBairro').value);
        endHtml += summaryRow('Cidade/UF', document.getElementById('endCidade').value + ' / ' + document.getElementById('endEstado').value);
        document.getElementById('summaryEndereco').innerHTML = endHtml;

        // Responsavel
        let respHtml = summaryRow('Nome', document.getElementById('respNome').value);
        respHtml += summaryRow('CPF', document.getElementById('respCpf').value);
        respHtml += summaryRow('Telefone', document.getElementById('respTelefone').value);
        respHtml += summaryRow('Email', document.getElementById('respEmail').value);
        document.getElementById('summaryResponsavel').innerHTML = respHtml;

        // Plano
        const plano = document.getElementById('planoSelecionado').value;
        let planoHtml = summaryRow('Entrega', plano === 'premium' ? 'Entregadores BoraUm (18%)' : 'Entrega Propria (10%)');
        planoHtml += summaryRow('Comissao', plano === 'premium' ? '18% sobre vendas' : '10% sobre vendas');
        planoHtml += summaryRow('Retirada', '10% (todos os planos)');
        document.getElementById('summaryPlano').innerHTML = planoHtml;
    }

    function summaryRow(label, value) {
        return '<div class="summary-row"><span class="summary-row-label">' + escapeHtml(label) + '</span><span class="summary-row-value">' + escapeHtml(value) + '</span></div>';
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       SUBMIT
       ═══════════════════════════════════════════════════════════════════════════ */
    async function submitCadastro() {
        // Check terms
        if (!document.getElementById('termsCheck').checked) {
            document.getElementById('submitError').style.display = 'flex';
            document.getElementById('submitErrorMsg').textContent = 'Voce precisa aceitar os termos de uso para continuar.';
            return;
        }

        document.getElementById('submitError').style.display = 'none';

        const btn = document.getElementById('btnNext');
        btn.disabled = true;
        btn.innerHTML = '<div class="spinner-sm spinner-white"></div> Enviando...';

        const docType = getDocType();

        const payload = {
            doc_type: docType,
            cpf_empresa: docType === 'cpf' ? document.getElementById('cpfInput').value.replace(/\D/g, '') : null,
            cnpj: docType === 'cnpj' ? document.getElementById('cnpjInput').value.replace(/\D/g, '') : null,
            name: document.getElementById('nomeApp').value.trim(),
            categoria: document.getElementById('categoriaSelect').value,
            especialidade: document.getElementById('especialidade').value.trim(),
            logo: document.getElementById('logoUrl').value,
            razao_social: document.getElementById('razaoSocial').value || null,
            cep: document.getElementById('cepInput').value.replace(/\D/g, ''),
            endereco: document.getElementById('endRua').value.trim(),
            numero: document.getElementById('endNumero').value.trim(),
            complemento: document.getElementById('endComplemento').value.trim(),
            bairro: document.getElementById('endBairro').value.trim(),
            cidade: document.getElementById('endCidade').value.trim(),
            estado: document.getElementById('endEstado').value.trim(),
            lat: document.getElementById('endLat').value || null,
            lng: document.getElementById('endLng').value || null,
            responsavel_nome: document.getElementById('respNome').value.trim(),
            responsavel_cpf: document.getElementById('respCpf').value.replace(/\D/g, ''),
            telefone: document.getElementById('respTelefone').value.replace(/\D/g, ''),
            email: document.getElementById('respEmail').value.trim(),
            senha: document.getElementById('respSenha').value,
            plano: document.getElementById('planoSelecionado').value
        };

        try {
            const res = await fetch('/api/mercado/parceiro/cadastro.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();

            if (data.success) {
                // Save token if returned
                if (data.token) {
                    localStorage.setItem('auth_token', data.token);
                }
                if (data.data && data.data.token) {
                    localStorage.setItem('auth_token', data.data.token);
                }

                // Show success
                document.getElementById('summaryView').style.display = 'none';
                document.getElementById('successOverlay').classList.add('visible');
                document.getElementById('wizardNav').style.display = 'none';

                // Redirect after 4s to login page
                setTimeout(() => {
                    window.location.href = 'login.php?registered=1';
                }, 4000);
            } else {
                document.getElementById('submitError').style.display = 'flex';
                document.getElementById('submitErrorMsg').textContent = data.message || 'Erro ao processar cadastro. Tente novamente.';
                btn.disabled = false;
                btn.innerHTML = '<i class="lucide-check-circle"></i> Finalizar Cadastro';
            }
        } catch (e) {
            document.getElementById('submitError').style.display = 'flex';
            document.getElementById('submitErrorMsg').textContent = 'Erro de conexao. Verifique sua internet e tente novamente.';
            btn.disabled = false;
            btn.innerHTML = '<i class="lucide-check-circle"></i> Finalizar Cadastro';
        }
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       INPUT MASKS
       ═══════════════════════════════════════════════════════════════════════════ */
    function maskCPF(e) {
        let v = e.target.value.replace(/\D/g, '');
        if (v.length > 11) v = v.slice(0, 11);
        if (v.length > 9) v = v.replace(/^(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
        else if (v.length > 6) v = v.replace(/^(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
        else if (v.length > 3) v = v.replace(/^(\d{3})(\d{1,3})/, '$1.$2');
        e.target.value = v;
    }

    function maskCNPJ(e) {
        let v = e.target.value.replace(/\D/g, '');
        if (v.length > 14) v = v.slice(0, 14);
        if (v.length > 12) v = v.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{1,2})/, '$1.$2.$3/$4-$5');
        else if (v.length > 8) v = v.replace(/^(\d{2})(\d{3})(\d{3})(\d{1,4})/, '$1.$2.$3/$4');
        else if (v.length > 5) v = v.replace(/^(\d{2})(\d{3})(\d{1,3})/, '$1.$2.$3');
        else if (v.length > 2) v = v.replace(/^(\d{2})(\d{1,3})/, '$1.$2');
        e.target.value = v;
    }

    function maskCEP(e) {
        let v = e.target.value.replace(/\D/g, '');
        if (v.length > 8) v = v.slice(0, 8);
        if (v.length > 5) v = v.replace(/^(\d{5})(\d{1,3})/, '$1-$2');
        e.target.value = v;
    }

    function maskPhone(e) {
        let v = e.target.value.replace(/\D/g, '');
        if (v.length > 11) v = v.slice(0, 11);
        if (v.length > 6) v = v.replace(/^(\d{2})(\d{4,5})(\d{1,4})/, '($1) $2-$3');
        else if (v.length > 2) v = v.replace(/^(\d{2})(\d{1,5})/, '($1) $2');
        e.target.value = v;
    }

    // Attach masks
    document.getElementById('cpfInput').addEventListener('input', maskCPF);
    document.getElementById('cnpjInput').addEventListener('input', maskCNPJ);
    document.getElementById('cepInput').addEventListener('input', maskCEP);
    document.getElementById('respCpf').addEventListener('input', maskCPF);
    document.getElementById('respTelefone').addEventListener('input', maskPhone);

    // Clear field error on input
    document.querySelectorAll('.om-input').forEach(input => {
        input.addEventListener('input', function() {
            this.classList.remove('field-error');
            const errorEl = this.parentElement.querySelector('.field-error-msg');
            if (errorEl) errorEl.classList.remove('visible');
        });
    });
    </script>
</body>
</html>
