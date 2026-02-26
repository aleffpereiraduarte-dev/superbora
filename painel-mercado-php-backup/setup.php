<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PAINEL DO MERCADO - Assistente de Configuracao Inicial
 * Primeiro login apos assinatura de contrato: parceiro configura loja
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

// ── Buscar dados do parceiro ────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT p.partner_id, p.name, p.owner_name, p.nome_fantasia, p.email, p.phone,
           p.logo, p.banner, p.horario_funcionamento,
           p.bank_name, p.bank_agency, p.bank_account,
           p.pix_key, p.pix_type,
           p.contract_signed_at, p.first_setup_complete
    FROM om_market_partners p
    WHERE p.partner_id = ?
");
$stmt->execute([$mercado_id]);
$partner = $stmt->fetch();

if (!$partner) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Se setup ja completo, ir para dashboard
if (!empty($partner['first_setup_complete']) && $partner['first_setup_complete'] == 1) {
    header('Location: index.php');
    exit;
}

// Se contrato nao assinado, voltar para contrato
if (empty($partner['contract_signed_at'])) {
    header('Location: contrato.php');
    exit;
}

// ── Gerar token para chamadas API ───────────────────────────────────────────
$partnerName = $partner['nome_fantasia'] ?: $partner['name'] ?: $partner['owner_name'];

$authToken = OmAuth::getInstance()->generateToken(OmAuth::USER_TYPE_PARTNER, $mercado_id, [
    'name' => $partnerName,
    'email' => $partner['email'] ?? '',
]);

// ── Horario existente (JSON) ────────────────────────────────────────────────
$horarioExistente = null;
if (!empty($partner['horario_funcionamento'])) {
    $horarioExistente = json_decode($partner['horario_funcionamento'], true);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuracao Inicial - SuperBora</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lucide-static@latest/font/lucide.min.css">
    <link rel="stylesheet" href="/frontend/src/styles/design-system.css">
    <style>
        /* ── Layout ─────────────────────────────────────────────────── */
        * { box-sizing: border-box; }

        body {
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            background: linear-gradient(135deg, var(--om-primary-50) 0%, var(--om-gray-100) 100%);
            padding: var(--om-space-6) var(--om-space-4);
        }

        .setup-container {
            width: 100%;
            max-width: 700px;
        }

        .setup-card {
            background: var(--om-white);
            border-radius: var(--om-radius-2xl);
            box-shadow: var(--om-shadow-xl);
            padding: var(--om-space-8);
            position: relative;
        }

        .setup-brand {
            text-align: center;
            margin-bottom: var(--om-space-5);
        }

        .setup-brand-name {
            font-size: var(--om-font-3xl);
            font-weight: var(--om-font-bold);
            color: var(--om-primary);
        }

        .setup-brand-sub {
            font-size: var(--om-font-sm);
            color: var(--om-text-secondary);
            margin-top: var(--om-space-1);
        }

        /* ── Progress Bar ────────────────────────────────────────────── */
        .progress-bar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            margin-bottom: var(--om-space-8);
            padding: 0 var(--om-space-4);
        }

        .progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 1;
        }

        .progress-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: var(--om-font-sm);
            font-weight: var(--om-font-bold);
            border: 3px solid var(--om-gray-200);
            background: var(--om-white);
            color: var(--om-gray-400);
            transition: all 0.3s ease;
        }

        .progress-circle i {
            font-size: 16px;
        }

        .progress-step.completed .progress-circle {
            background: var(--om-success);
            border-color: var(--om-success);
            color: var(--om-white);
        }

        .progress-step.active .progress-circle {
            background: var(--om-primary);
            border-color: var(--om-primary);
            color: var(--om-white);
            box-shadow: 0 0 0 4px var(--om-primary-100);
        }

        .progress-label {
            font-size: 11px;
            color: var(--om-text-muted);
            margin-top: var(--om-space-1);
            white-space: nowrap;
            font-weight: var(--om-font-medium);
        }

        .progress-step.active .progress-label {
            color: var(--om-primary);
            font-weight: var(--om-font-semibold);
        }

        .progress-step.completed .progress-label {
            color: var(--om-success);
        }

        .progress-line {
            flex: 1;
            height: 3px;
            background: var(--om-gray-200);
            margin: 0 var(--om-space-1);
            margin-bottom: 20px;
            transition: background 0.3s ease;
        }

        .progress-line.completed {
            background: var(--om-success);
        }

        /* ── Step Content ────────────────────────────────────────────── */
        .step-content {
            display: none;
        }

        .step-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .step-title {
            font-size: var(--om-font-xl);
            font-weight: var(--om-font-bold);
            color: var(--om-text-primary);
            margin-bottom: var(--om-space-1);
            display: flex;
            align-items: center;
            gap: var(--om-space-2);
        }

        .step-title i {
            color: var(--om-primary);
        }

        .step-desc {
            font-size: var(--om-font-sm);
            color: var(--om-text-secondary);
            margin-bottom: var(--om-space-6);
            line-height: 1.5;
        }

        /* ── Upload Areas ────────────────────────────────────────────── */
        .upload-grid {
            display: grid;
            grid-template-columns: 160px 1fr;
            gap: var(--om-space-6);
            margin-bottom: var(--om-space-4);
        }

        @media (max-width: 600px) {
            .upload-grid {
                grid-template-columns: 1fr;
            }
        }

        .upload-area {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: var(--om-space-2);
        }

        .upload-area label.upload-label-text {
            font-size: var(--om-font-sm);
            font-weight: var(--om-font-semibold);
            color: var(--om-text-primary);
        }

        .upload-zone {
            border: 2px dashed var(--om-gray-300);
            border-radius: var(--om-radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            overflow: hidden;
            position: relative;
            background: var(--om-gray-50);
        }

        .upload-zone:hover {
            border-color: var(--om-primary);
            background: var(--om-primary-50);
        }

        .upload-zone.has-image {
            border-style: solid;
            border-color: var(--om-success);
        }

        .upload-zone img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .upload-zone-logo {
            width: 140px;
            height: 140px;
            border-radius: 50%;
        }

        .upload-zone-logo.has-image {
            border-radius: 50%;
        }

        .upload-zone-banner {
            width: 100%;
            height: 140px;
        }

        .upload-placeholder {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: var(--om-space-1);
            color: var(--om-text-muted);
            padding: var(--om-space-3);
        }

        .upload-placeholder i {
            font-size: 28px;
            color: var(--om-gray-400);
        }

        .upload-placeholder span {
            font-size: var(--om-font-xs);
            text-align: center;
        }

        .upload-loading {
            display: none;
            position: absolute;
            inset: 0;
            background: rgba(255,255,255,0.85);
            align-items: center;
            justify-content: center;
            z-index: 2;
        }

        .upload-loading.visible {
            display: flex;
        }

        /* ── Schedule Grid ───────────────────────────────────────────── */
        .schedule-grid {
            display: flex;
            flex-direction: column;
            gap: var(--om-space-2);
        }

        .schedule-row {
            display: grid;
            grid-template-columns: 90px 44px 1fr 24px 1fr;
            gap: var(--om-space-2);
            align-items: center;
            padding: var(--om-space-2) var(--om-space-3);
            background: var(--om-gray-50);
            border-radius: var(--om-radius-md);
            transition: opacity 0.2s ease;
        }

        .schedule-row.disabled {
            opacity: 0.45;
        }

        .schedule-day {
            font-size: var(--om-font-sm);
            font-weight: var(--om-font-semibold);
            color: var(--om-text-primary);
        }

        .schedule-separator {
            text-align: center;
            font-size: var(--om-font-sm);
            color: var(--om-text-muted);
        }

        .schedule-toggle {
            width: 38px;
            height: 22px;
            border-radius: 11px;
            background: var(--om-gray-300);
            position: relative;
            cursor: pointer;
            transition: background 0.2s ease;
            border: none;
            padding: 0;
            flex-shrink: 0;
        }

        .schedule-toggle.on {
            background: var(--om-success);
        }

        .schedule-toggle-thumb {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: var(--om-white);
            position: absolute;
            top: 2px;
            left: 2px;
            transition: transform 0.2s ease;
            box-shadow: var(--om-shadow-sm);
        }

        .schedule-toggle.on .schedule-toggle-thumb {
            transform: translateX(16px);
        }

        .schedule-select {
            width: 100%;
            padding: var(--om-space-1) var(--om-space-2);
            border: 1px solid var(--om-gray-200);
            border-radius: var(--om-radius-md);
            font-size: var(--om-font-sm);
            font-family: var(--om-font-family);
            background: var(--om-white);
            color: var(--om-text-primary);
            cursor: pointer;
        }

        .schedule-select:focus {
            outline: none;
            border-color: var(--om-primary);
            box-shadow: 0 0 0 3px var(--om-primary-100);
        }

        @media (max-width: 600px) {
            .schedule-row {
                grid-template-columns: 60px 38px 1fr 16px 1fr;
                gap: var(--om-space-1);
                padding: var(--om-space-2);
            }
            .schedule-day {
                font-size: var(--om-font-xs);
            }
        }

        /* ── Bank Form ───────────────────────────────────────────────── */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--om-space-4);
        }

        @media (max-width: 600px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            .setup-card {
                padding: var(--om-space-5);
            }
        }

        .om-form-group {
            margin-bottom: var(--om-space-4);
        }

        /* ── Menu AI Section ─────────────────────────────────────────── */
        .menu-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--om-space-4);
            margin-bottom: var(--om-space-6);
        }

        @media (max-width: 600px) {
            .menu-options {
                grid-template-columns: 1fr;
            }
        }

        .menu-option-card {
            border: 2px solid var(--om-gray-200);
            border-radius: var(--om-radius-lg);
            padding: var(--om-space-5);
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .menu-option-card:hover {
            border-color: var(--om-primary-300);
            background: var(--om-primary-50);
        }

        .menu-option-card.selected {
            border-color: var(--om-primary);
            background: var(--om-primary-50);
            box-shadow: 0 0 0 3px var(--om-primary-100);
        }

        .menu-option-icon {
            font-size: 32px;
            color: var(--om-primary);
            margin-bottom: var(--om-space-2);
        }

        .menu-option-title {
            font-weight: var(--om-font-semibold);
            color: var(--om-text-primary);
            margin-bottom: var(--om-space-1);
        }

        .menu-option-desc {
            font-size: var(--om-font-xs);
            color: var(--om-text-secondary);
            line-height: 1.4;
        }

        .ai-section {
            display: none;
            border: 1px solid var(--om-gray-200);
            border-radius: var(--om-radius-lg);
            padding: var(--om-space-5);
            background: var(--om-gray-50);
        }

        .ai-section.visible {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        .ai-upload-zone {
            border: 2px dashed var(--om-gray-300);
            border-radius: var(--om-radius-lg);
            padding: var(--om-space-6);
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-bottom: var(--om-space-4);
            background: var(--om-white);
        }

        .ai-upload-zone:hover {
            border-color: var(--om-primary);
            background: var(--om-primary-50);
        }

        .ai-upload-zone.has-file {
            border-color: var(--om-success);
            border-style: solid;
        }

        .ai-divider {
            display: flex;
            align-items: center;
            gap: var(--om-space-3);
            margin-bottom: var(--om-space-4);
            color: var(--om-text-muted);
            font-size: var(--om-font-sm);
        }

        .ai-divider::before,
        .ai-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--om-gray-200);
        }

        .ai-results {
            display: none;
            margin-top: var(--om-space-4);
        }

        .ai-results.visible {
            display: block;
        }

        .ai-category {
            margin-bottom: var(--om-space-4);
        }

        .ai-category-title {
            font-size: var(--om-font-sm);
            font-weight: var(--om-font-bold);
            color: var(--om-text-primary);
            margin-bottom: var(--om-space-2);
            display: flex;
            align-items: center;
            gap: var(--om-space-2);
        }

        .ai-category-title i {
            color: var(--om-primary);
            font-size: 16px;
        }

        .ai-product-list {
            display: flex;
            flex-direction: column;
            gap: var(--om-space-1);
        }

        .ai-product-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: var(--om-space-2) var(--om-space-3);
            background: var(--om-white);
            border: 1px solid var(--om-gray-200);
            border-radius: var(--om-radius-md);
            font-size: var(--om-font-sm);
        }

        .ai-product-name {
            color: var(--om-text-primary);
        }

        .ai-product-price {
            color: var(--om-success-dark);
            font-weight: var(--om-font-semibold);
        }

        .ai-summary {
            background: var(--om-success-bg);
            border: 1px solid var(--om-success-light);
            border-radius: var(--om-radius-lg);
            padding: var(--om-space-4);
            text-align: center;
            margin-top: var(--om-space-4);
            display: none;
        }

        .ai-summary.visible {
            display: block;
        }

        .ai-summary-count {
            font-size: var(--om-font-2xl);
            font-weight: var(--om-font-bold);
            color: var(--om-success-dark);
        }

        .ai-summary-label {
            font-size: var(--om-font-sm);
            color: var(--om-success-dark);
        }

        /* ── AI Loading Animation ─────────────────────────────────────── */
        .ai-loading-animation {
            display: inline-block;
            position: relative;
        }
        .ai-loading-icon {
            width: 72px;
            height: 72px;
            background: linear-gradient(135deg, var(--om-primary), var(--om-primary-dark));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            animation: aiPulse 2s ease-in-out infinite;
        }
        .ai-loading-icon i {
            font-size: 32px;
            color: white;
        }
        @keyframes aiPulse {
            0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(255,106,0,0.4); }
            50% { transform: scale(1.08); box-shadow: 0 0 0 16px rgba(255,106,0,0); }
        }
        .ai-progress-bar {
            width: 100%;
            max-width: 280px;
            height: 6px;
            background: var(--om-gray-200);
            border-radius: 3px;
            overflow: hidden;
            margin: 0 auto;
        }
        .ai-progress-fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, var(--om-primary), var(--om-primary-light));
            border-radius: 3px;
            transition: width 0.5s ease;
        }

        /* AI Review editable items */
        .ai-review-cat {
            border: 1px solid var(--om-gray-200);
            border-radius: var(--om-radius-lg);
            margin-bottom: var(--om-space-3);
            overflow: hidden;
        }
        .ai-review-cat-header {
            background: var(--om-gray-50);
            padding: var(--om-space-3) var(--om-space-4);
            font-weight: var(--om-font-semibold);
            border-bottom: 1px solid var(--om-gray-200);
            display: flex;
            align-items: center;
            gap: var(--om-space-2);
        }
        .ai-review-cat-header input {
            flex: 1;
            border: none;
            background: transparent;
            font-weight: var(--om-font-semibold);
            font-size: var(--om-font-base);
            font-family: var(--om-font-family);
            outline: none;
            padding: 2px 4px;
            border-radius: var(--om-radius-sm);
        }
        .ai-review-cat-header input:focus {
            background: var(--om-white);
            box-shadow: 0 0 0 2px var(--om-primary-200);
        }
        .ai-review-item {
            padding: var(--om-space-3) var(--om-space-4);
            border-bottom: 1px solid var(--om-gray-100);
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: var(--om-space-3);
            align-items: start;
        }
        .ai-review-item:last-child { border-bottom: none; }
        .ai-review-item-fields { display: flex; flex-direction: column; gap: 4px; }
        .ai-review-item input, .ai-review-item textarea {
            border: 1px solid transparent;
            background: transparent;
            font-family: var(--om-font-family);
            padding: 2px 4px;
            border-radius: var(--om-radius-sm);
            width: 100%;
            outline: none;
            resize: none;
        }
        .ai-review-item input:focus, .ai-review-item textarea:focus {
            border-color: var(--om-primary-200);
            background: var(--om-white);
        }
        .ai-review-item .item-name {
            font-weight: var(--om-font-medium);
            font-size: var(--om-font-sm);
        }
        .ai-review-item .item-desc {
            font-size: var(--om-font-xs);
            color: var(--om-text-muted);
        }
        .ai-review-item .item-price-input {
            width: 90px;
            text-align: right;
            font-weight: var(--om-font-semibold);
            color: var(--om-success-dark);
            font-size: var(--om-font-sm);
        }
        .ai-review-item .item-delete {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--om-gray-400);
            padding: 4px;
            border-radius: var(--om-radius-sm);
        }
        .ai-review-item .item-delete:hover { color: var(--om-error); background: var(--om-error-bg); }
        .ai-review-item .item-options {
            grid-column: 1 / -1;
            font-size: var(--om-font-xs);
            color: var(--om-text-muted);
            padding-left: 4px;
        }
        .ai-review-item .item-options span {
            display: inline-block;
            background: var(--om-gray-100);
            padding: 1px 6px;
            border-radius: 99px;
            margin: 1px 2px;
        }
        .ai-add-complement-btn {
            background: none;
            border: none;
            color: var(--om-primary);
            font-size: var(--om-font-xs);
            cursor: pointer;
            font-family: var(--om-font-family);
            padding: 2px 4px;
            grid-column: 1 / -1;
        }
        .ai-add-complement-btn:hover { text-decoration: underline; }

        /* ── Manual Menu Builder ──────────────────────────────────────── */
        .cat-block {
            border: 1px solid var(--om-gray-200);
            border-radius: var(--om-radius-lg);
            background: var(--om-white);
            margin-bottom: var(--om-space-3);
            overflow: hidden;
        }
        .cat-header {
            display: flex;
            align-items: center;
            gap: var(--om-space-3);
            padding: var(--om-space-3) var(--om-space-4);
            background: var(--om-gray-50);
            border-bottom: 1px solid var(--om-gray-200);
            cursor: pointer;
        }
        .cat-header:hover { background: var(--om-gray-100); }
        .cat-name {
            flex: 1;
            font-weight: var(--om-font-semibold);
            font-size: var(--om-font-base);
        }
        .cat-count {
            font-size: var(--om-font-xs);
            color: var(--om-text-muted);
            background: var(--om-gray-200);
            padding: 2px 8px;
            border-radius: 99px;
        }
        .cat-actions {
            display: flex;
            gap: var(--om-space-1);
        }
        .cat-actions button {
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
            border-radius: var(--om-radius-sm);
            color: var(--om-text-muted);
            font-size: 16px;
        }
        .cat-actions button:hover { background: var(--om-gray-200); color: var(--om-text-primary); }
        .cat-actions button.danger:hover { background: var(--om-error-bg); color: var(--om-error); }
        .cat-body {
            padding: var(--om-space-3) var(--om-space-4);
        }
        .cat-body.collapsed { display: none; }
        .prod-row {
            display: flex;
            align-items: center;
            gap: var(--om-space-3);
            padding: var(--om-space-2) 0;
            border-bottom: 1px solid var(--om-gray-100);
        }
        .prod-row:last-child { border-bottom: none; }
        .prod-img {
            width: 48px;
            height: 48px;
            border-radius: var(--om-radius-md);
            background: var(--om-gray-100);
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            cursor: pointer;
            position: relative;
        }
        .prod-img img { width: 100%; height: 100%; object-fit: cover; }
        .prod-img i { font-size: 18px; color: var(--om-gray-400); }
        .prod-info { flex: 1; min-width: 0; }
        .prod-name {
            font-weight: var(--om-font-medium);
            font-size: var(--om-font-sm);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .prod-desc {
            font-size: var(--om-font-xs);
            color: var(--om-text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .prod-price {
            font-weight: var(--om-font-semibold);
            color: var(--om-success-dark);
            font-size: var(--om-font-sm);
            white-space: nowrap;
        }
        .prod-actions { display: flex; gap: 2px; }
        .prod-actions button {
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
            border-radius: var(--om-radius-sm);
            color: var(--om-text-muted);
            font-size: 14px;
        }
        .prod-actions button:hover { background: var(--om-gray-100); color: var(--om-text-primary); }
        .prod-actions button.danger:hover { background: var(--om-error-bg); color: var(--om-error); }
        .add-prod-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: var(--om-space-2);
            width: 100%;
            padding: var(--om-space-2);
            border: 1px dashed var(--om-gray-300);
            border-radius: var(--om-radius-md);
            background: none;
            color: var(--om-primary);
            font-size: var(--om-font-sm);
            font-weight: var(--om-font-medium);
            cursor: pointer;
            font-family: var(--om-font-family);
            margin-top: var(--om-space-2);
        }
        .add-prod-btn:hover { background: var(--om-primary-50); border-color: var(--om-primary-200); }

        /* Modal for adding/editing product */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: var(--om-space-4);
        }
        .modal-overlay.visible { display: flex; }
        .modal-card {
            background: var(--om-white);
            border-radius: var(--om-radius-xl);
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            padding: var(--om-space-6);
            box-shadow: var(--om-shadow-xl);
        }
        .modal-title {
            font-size: var(--om-font-lg);
            font-weight: var(--om-font-bold);
            margin-bottom: var(--om-space-4);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .modal-close {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 20px;
            color: var(--om-text-muted);
            padding: 4px;
        }
        .modal-close:hover { color: var(--om-text-primary); }

        /* Option groups inside product modal */
        .opt-group-block {
            border: 1px solid var(--om-gray-200);
            border-radius: var(--om-radius-md);
            padding: var(--om-space-3);
            margin-bottom: var(--om-space-3);
            background: var(--om-gray-50);
        }
        .opt-group-header {
            display: flex;
            align-items: center;
            gap: var(--om-space-2);
            margin-bottom: var(--om-space-2);
        }
        .opt-group-header input { flex: 1; }
        .opt-item {
            display: flex;
            align-items: center;
            gap: var(--om-space-2);
            margin-bottom: var(--om-space-1);
        }
        .opt-item input:first-child { flex: 1; }
        .opt-item input:nth-child(2) { width: 90px; }

        @media (max-width: 600px) {
            .menu-options { grid-template-columns: 1fr !important; }
        }

        /* ── Navigation Buttons ──────────────────────────────────────── */
        .step-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: var(--om-space-8);
            gap: var(--om-space-3);
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
            color: var(--om-text-primary);
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
            transform: translateY(-1px);
            box-shadow: var(--om-shadow-lg);
        }

        .btn-next:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .btn-skip {
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

        .btn-skip:hover {
            background: var(--om-gray-50);
            border-color: var(--om-gray-400);
        }

        /* ── Alert ────────────────────────────────────────────────────── */
        .alert-msg {
            padding: var(--om-space-3) var(--om-space-4);
            border-radius: var(--om-radius-lg);
            font-size: var(--om-font-sm);
            display: none;
            align-items: center;
            gap: var(--om-space-2);
            margin-bottom: var(--om-space-4);
        }

        .alert-msg.visible {
            display: flex;
        }

        .alert-msg.error {
            background: var(--om-error-bg);
            border: 1px solid var(--om-error-light);
            color: var(--om-error-dark);
        }

        .alert-msg.success {
            background: var(--om-success-bg);
            border: 1px solid var(--om-success-light);
            color: var(--om-success-dark);
        }

        /* ── Spinner ──────────────────────────────────────────────────── */
        .spinner-sm {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: var(--om-white);
            border-radius: 50%;
            animation: om-spin 0.8s linear infinite;
        }

        .spinner-dark {
            border-color: var(--om-gray-200);
            border-top-color: var(--om-primary);
        }

        @keyframes om-spin {
            to { transform: rotate(360deg); }
        }

        /* ── Celebration Overlay ──────────────────────────────────────── */
        .celebration-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.6);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: var(--om-space-4);
        }

        .celebration-overlay.visible {
            display: flex;
        }

        .celebration-card {
            background: var(--om-white);
            border-radius: var(--om-radius-2xl);
            padding: var(--om-space-10) var(--om-space-8);
            text-align: center;
            max-width: 440px;
            width: 100%;
            animation: scaleIn 0.4s ease;
        }

        .celebration-icon {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: var(--om-success-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto var(--om-space-5);
        }

        .celebration-icon i {
            font-size: 2.5rem;
            color: var(--om-success);
        }

        @keyframes scaleIn {
            0% { transform: scale(0.5); opacity: 0; }
            60% { transform: scale(1.05); }
            100% { transform: scale(1); opacity: 1; }
        }

        .celebration-title {
            font-size: var(--om-font-2xl);
            font-weight: var(--om-font-bold);
            color: var(--om-text-primary);
            margin-bottom: var(--om-space-2);
        }

        .celebration-message {
            font-size: var(--om-font-sm);
            color: var(--om-text-secondary);
            line-height: 1.6;
            margin-bottom: var(--om-space-5);
        }

        .celebration-confetti {
            font-size: 2rem;
            margin-bottom: var(--om-space-3);
        }

        /* ── Footer ───────────────────────────────────────────────────── */
        .setup-footer {
            text-align: center;
            margin-top: var(--om-space-6);
            font-size: var(--om-font-sm);
            color: var(--om-text-muted);
        }

        .setup-footer a {
            color: var(--om-primary);
            font-weight: var(--om-font-medium);
        }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-card">
            <!-- Brand -->
            <div class="setup-brand">
                <div class="setup-brand-name">SuperBora</div>
                <div class="setup-brand-sub">Configure sua loja para comecar a vender</div>
            </div>

            <!-- Progress Bar -->
            <div class="progress-bar" id="progressBar">
                <div class="progress-step active" data-step="1">
                    <div class="progress-circle">1</div>
                    <div class="progress-label">Logo</div>
                </div>
                <div class="progress-line" data-line="1"></div>
                <div class="progress-step" data-step="2">
                    <div class="progress-circle">2</div>
                    <div class="progress-label">Horarios</div>
                </div>
                <div class="progress-line" data-line="2"></div>
                <div class="progress-step" data-step="3">
                    <div class="progress-circle">3</div>
                    <div class="progress-label">Banco</div>
                </div>
                <div class="progress-line" data-line="3"></div>
                <div class="progress-step" data-step="4">
                    <div class="progress-circle">4</div>
                    <div class="progress-label">Cardapio</div>
                </div>
            </div>

            <!-- Alert area -->
            <div class="alert-msg" id="alertMsg">
                <i class="lucide-alert-circle" style="flex-shrink:0;"></i>
                <span id="alertMsgText"></span>
            </div>

            <!-- ══════════════════════════════════════════════════════════
                 STEP 1 - Logo e Banner
                 ══════════════════════════════════════════════════════════ -->
            <div class="step-content active" id="step1">
                <h2 class="step-title">
                    <i class="lucide-image"></i>
                    Logo e Banner
                </h2>
                <p class="step-desc">Adicione a logo e banner do seu estabelecimento. Essas imagens aparecerao na vitrine para os clientes.</p>

                <div class="upload-grid">
                    <!-- Logo -->
                    <div class="upload-area">
                        <label class="upload-label-text">Logo</label>
                        <div class="upload-zone upload-zone-logo" id="logoZone" onclick="document.getElementById('logoInput').click()">
                            <?php if (!empty($partner['logo'])): ?>
                                <img src="<?= htmlspecialchars($partner['logo']) ?>" alt="Logo" id="logoPreview">
                            <?php else: ?>
                                <div class="upload-placeholder" id="logoPlaceholder">
                                    <i class="lucide-camera"></i>
                                    <span>Clique para enviar</span>
                                </div>
                                <img src="" alt="Logo" id="logoPreview" style="display:none;">
                            <?php endif; ?>
                            <div class="upload-loading" id="logoLoading">
                                <div class="spinner-sm spinner-dark"></div>
                            </div>
                        </div>
                        <input type="file" id="logoInput" accept="image/*" hidden>
                    </div>

                    <!-- Banner -->
                    <div class="upload-area">
                        <label class="upload-label-text">Banner</label>
                        <div class="upload-zone upload-zone-banner" id="bannerZone" onclick="document.getElementById('bannerInput').click()">
                            <?php if (!empty($partner['banner'])): ?>
                                <img src="<?= htmlspecialchars($partner['banner']) ?>" alt="Banner" id="bannerPreview">
                            <?php else: ?>
                                <div class="upload-placeholder" id="bannerPlaceholder">
                                    <i class="lucide-image-plus"></i>
                                    <span>Clique para enviar banner</span>
                                </div>
                                <img src="" alt="Banner" id="bannerPreview" style="display:none;">
                            <?php endif; ?>
                            <div class="upload-loading" id="bannerLoading">
                                <div class="spinner-sm spinner-dark"></div>
                            </div>
                        </div>
                        <input type="file" id="bannerInput" accept="image/*" hidden>
                    </div>
                </div>

                <div class="step-nav">
                    <div></div>
                    <button type="button" class="btn-next" onclick="saveAndNext(1)">
                        Proximo <i class="lucide-arrow-right"></i>
                    </button>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════════
                 STEP 2 - Horario de Funcionamento
                 ══════════════════════════════════════════════════════════ -->
            <div class="step-content" id="step2">
                <h2 class="step-title">
                    <i class="lucide-clock"></i>
                    Horario de Funcionamento
                </h2>
                <p class="step-desc">Defina os dias e horarios que seu estabelecimento ficara aberto para receber pedidos.</p>

                <div class="schedule-grid" id="scheduleGrid">
                    <!-- Rows generated by JS -->
                </div>

                <div class="step-nav">
                    <button type="button" class="btn-back" onclick="goToStep(1)">
                        <i class="lucide-arrow-left"></i> Voltar
                    </button>
                    <button type="button" class="btn-next" onclick="saveAndNext(2)">
                        Proximo <i class="lucide-arrow-right"></i>
                    </button>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════════
                 STEP 3 - Dados Bancarios
                 ══════════════════════════════════════════════════════════ -->
            <div class="step-content" id="step3">
                <h2 class="step-title">
                    <i class="lucide-landmark"></i>
                    Dados Bancarios
                </h2>
                <p class="step-desc">Informe os dados para receber os repasses das vendas realizadas pela plataforma.</p>

                <div class="om-form-group">
                    <label class="om-label" for="bankName">Banco *</label>
                    <select id="bankName" class="om-input">
                        <option value="">Selecione o banco</option>
                        <option value="Banco do Brasil">Banco do Brasil</option>
                        <option value="Bradesco">Bradesco</option>
                        <option value="Itau">Itau</option>
                        <option value="Caixa">Caixa Economica Federal</option>
                        <option value="Santander">Santander</option>
                        <option value="Nubank">Nubank</option>
                        <option value="Inter">Banco Inter</option>
                        <option value="C6">C6 Bank</option>
                        <option value="PagBank">PagBank</option>
                        <option value="Sicoob">Sicoob</option>
                        <option value="Sicredi">Sicredi</option>
                        <option value="Outros">Outros</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="om-form-group">
                        <label class="om-label" for="bankAgency">Agencia</label>
                        <input type="text" id="bankAgency" class="om-input" placeholder="0001" maxlength="10">
                    </div>
                    <div class="om-form-group">
                        <label class="om-label" for="bankAccount">Conta</label>
                        <input type="text" id="bankAccount" class="om-input" placeholder="12345-6" maxlength="20">
                    </div>
                </div>

                <div class="form-row">
                    <div class="om-form-group">
                        <label class="om-label" for="pixType">Tipo PIX *</label>
                        <select id="pixType" class="om-input">
                            <option value="">Selecione</option>
                            <option value="cpf">CPF</option>
                            <option value="cnpj">CNPJ</option>
                            <option value="email">Email</option>
                            <option value="telefone">Telefone</option>
                            <option value="aleatoria">Chave aleatoria</option>
                        </select>
                    </div>
                    <div class="om-form-group">
                        <label class="om-label" for="pixKey">Chave PIX *</label>
                        <input type="text" id="pixKey" class="om-input" placeholder="Sua chave PIX" maxlength="100">
                    </div>
                </div>

                <div class="step-nav">
                    <button type="button" class="btn-back" onclick="goToStep(2)">
                        <i class="lucide-arrow-left"></i> Voltar
                    </button>
                    <button type="button" class="btn-next" onclick="saveAndNext(3)">
                        Proximo <i class="lucide-arrow-right"></i>
                    </button>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════════
                 STEP 4 - Cardapio
                 ══════════════════════════════════════════════════════════ -->
            <div class="step-content" id="step4">
                <h2 class="step-title">
                    <i class="lucide-utensils"></i>
                    Cardapio
                </h2>
                <p class="step-desc">Monte o cardapio da sua loja. Escolha como quer comecar:</p>

                <div class="menu-options" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:var(--om-space-3);">
                    <div class="menu-option-card" id="optionAI" onclick="selectMenuOption('ai')">
                        <div class="menu-option-icon"><i class="lucide-sparkles"></i></div>
                        <div class="menu-option-title">Importar com IA</div>
                        <div class="menu-option-desc">Envie foto ou texto do cardapio e a IA cria tudo automaticamente</div>
                    </div>
                    <div class="menu-option-card" id="optionManual" onclick="selectMenuOption('manual')">
                        <div class="menu-option-icon"><i class="lucide-plus-circle"></i></div>
                        <div class="menu-option-title">Montar do zero</div>
                        <div class="menu-option-desc">Crie categorias e produtos um por um com total controle</div>
                    </div>
                    <div class="menu-option-card" id="optionSkip" onclick="selectMenuOption('skip')">
                        <div class="menu-option-icon"><i class="lucide-fast-forward"></i></div>
                        <div class="menu-option-title">Fazer depois</div>
                        <div class="menu-option-desc">Pule e cadastre no painel quando quiser</div>
                    </div>
                </div>

                <!-- Manual Section - Criador de cardapio do zero estilo iFood -->
                <div class="ai-section" id="manualSection" style="display:none;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--om-space-4);">
                        <h3 style="font-size:var(--om-font-lg);font-weight:var(--om-font-semibold);margin:0;">Seu Cardapio</h3>
                        <button type="button" class="om-btn om-btn-primary" onclick="addCategory()" style="font-size:var(--om-font-sm);padding:var(--om-space-2) var(--om-space-3);">
                            <i class="lucide-folder-plus"></i> Nova Categoria
                        </button>
                    </div>

                    <div id="manualCategories">
                        <!-- Categories and products rendered here by JS -->
                    </div>

                    <div id="emptyMenuMsg" style="text-align:center;padding:var(--om-space-8);color:var(--om-text-muted);">
                        <i class="lucide-utensils" style="font-size:40px;opacity:0.3;display:block;margin-bottom:var(--om-space-3);"></i>
                        Comece criando sua primeira categoria<br>
                        <small>Ex: Lanches, Bebidas, Sobremesas, Combos</small>
                    </div>

                    <div class="manual-summary" id="manualSummary" style="display:none;margin-top:var(--om-space-4);padding:var(--om-space-3);background:var(--om-success-bg);border-radius:var(--om-radius-md);text-align:center;color:var(--om-success-dark);">
                        <strong id="manualCountCat">0</strong> categorias &bull; <strong id="manualCountProd">0</strong> produtos criados
                    </div>
                </div>

                <!-- AI Section - estilo iFood "Digitalize seu cardapio em 5 minutos" -->
                <div class="ai-section" id="aiSection">

                    <!-- FASE 1: Upload -->
                    <div id="aiPhase1">
                        <div style="text-align:center;margin-bottom:var(--om-space-4);">
                            <i class="lucide-sparkles" style="font-size:32px;color:var(--om-primary);"></i>
                            <h3 style="font-size:var(--om-font-lg);font-weight:var(--om-font-semibold);margin:var(--om-space-2) 0;">Digitalize seu cardapio</h3>
                            <p style="font-size:var(--om-font-sm);color:var(--om-text-secondary);">Envie fotos do seu cardapio e a IA cria tudo automaticamente em minutos</p>
                        </div>

                        <!-- Upload de multiplas imagens -->
                        <div style="margin-bottom:var(--om-space-4);">
                            <label class="om-label">Fotos do cardapio</label>
                            <div class="ai-upload-zone" id="aiUploadZone" onclick="document.getElementById('aiMenuInput').click()">
                                <i class="lucide-image-plus" style="font-size:32px;color:var(--om-gray-400);"></i>
                                <div style="font-size:var(--om-font-sm);color:var(--om-text-muted);margin-top:var(--om-space-2);">
                                    Clique para selecionar fotos
                                </div>
                                <div style="font-size:var(--om-font-xs);color:var(--om-gray-400);margin-top:2px;">
                                    Aceita JPG, PNG, WEBP - ate 10MB cada
                                </div>
                            </div>
                            <input type="file" id="aiMenuInput" accept="image/jpeg,image/png,image/webp" multiple hidden>

                            <!-- Previews das imagens enviadas -->
                            <div id="aiImagePreviews" style="display:flex;gap:var(--om-space-2);flex-wrap:wrap;margin-top:var(--om-space-2);"></div>
                        </div>

                        <div class="ai-divider">ou</div>

                        <div class="om-form-group" style="margin-bottom:var(--om-space-4);">
                            <label class="om-label" for="aiMenuText">Cole ou digite o cardapio</label>
                            <textarea id="aiMenuText" class="om-input" rows="5" placeholder="Cole o cardapio aqui. Exemplo:&#10;&#10;LANCHES&#10;Hamburguer Classico - R$ 25,90&#10;  Adicional: Bacon +R$ 3,00, Queijo extra +R$ 2,00&#10;X-Burger - R$ 28,90&#10;&#10;BEBIDAS&#10;Refrigerante Lata - R$ 6,00&#10;Suco Natural - R$ 8,00"></textarea>
                        </div>

                        <button type="button" class="om-btn om-btn-primary om-btn-block om-btn-lg" id="btnAnalyze" onclick="analyzeMenu()">
                            <i class="lucide-sparkles"></i>
                            Digitalizar Cardapio
                        </button>
                    </div>

                    <!-- FASE 2: Loading/Processando -->
                    <div id="aiPhase2" style="display:none;">
                        <div style="text-align:center;padding:var(--om-space-8) 0;">
                            <div class="ai-loading-animation">
                                <div class="ai-loading-icon">
                                    <i class="lucide-sparkles"></i>
                                </div>
                            </div>
                            <h3 style="font-size:var(--om-font-lg);font-weight:var(--om-font-semibold);margin:var(--om-space-4) 0 var(--om-space-2);">Analisando seu cardapio...</h3>
                            <p style="font-size:var(--om-font-sm);color:var(--om-text-secondary);" id="aiLoadingMsg">
                                A inteligencia artificial esta lendo e organizando seus produtos
                            </p>
                            <div class="ai-progress-bar" style="margin-top:var(--om-space-4);">
                                <div class="ai-progress-fill" id="aiProgressFill"></div>
                            </div>
                            <p style="font-size:var(--om-font-xs);color:var(--om-gray-400);margin-top:var(--om-space-3);">
                                Nao feche esta pagina. Isso pode levar ate 1 minuto.
                            </p>
                        </div>
                    </div>

                    <!-- FASE 3: Revisao editavel (estilo iFood) -->
                    <div id="aiPhase3" style="display:none;">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--om-space-4);">
                            <div>
                                <h3 style="font-size:var(--om-font-lg);font-weight:var(--om-font-semibold);margin:0;">Revise seu cardapio</h3>
                                <p style="font-size:var(--om-font-sm);color:var(--om-text-secondary);margin:2px 0 0;">Compare com o original e edite o que precisar</p>
                            </div>
                            <span class="cat-count" id="aiTotalCount" style="font-size:var(--om-font-sm);">0 itens</span>
                        </div>

                        <div style="background:var(--om-info-bg);border:1px solid var(--om-info-light);border-radius:var(--om-radius-md);padding:var(--om-space-3);margin-bottom:var(--om-space-4);font-size:var(--om-font-sm);color:var(--om-info-dark);display:flex;align-items:flex-start;gap:var(--om-space-2);">
                            <i class="lucide-info" style="margin-top:2px;flex-shrink:0;"></i>
                            <span>Confira nomes, precos e descricoes. Voce pode <strong>editar</strong>, <strong>excluir</strong> itens e <strong>adicionar complementos</strong> antes de salvar.</span>
                        </div>

                        <!-- Categorias e produtos editaveis -->
                        <div id="aiReviewList"></div>

                        <div style="display:flex;gap:var(--om-space-3);margin-top:var(--om-space-4);">
                            <button type="button" class="om-btn" style="flex:0 0 auto;background:var(--om-gray-100);color:var(--om-text-secondary);" onclick="aiBackToUpload()">
                                <i class="lucide-arrow-left"></i> Voltar
                            </button>
                            <button type="button" class="om-btn om-btn-primary om-btn-block om-btn-lg" id="btnSaveMenu" onclick="saveAIMenu()">
                                <i class="lucide-check"></i>
                                Salvar e ir para cardapio
                            </button>
                        </div>
                    </div>

                    <!-- FASE 4: Sucesso -->
                    <div id="aiPhase4" style="display:none;">
                        <div style="text-align:center;padding:var(--om-space-6) 0;">
                            <div style="width:64px;height:64px;background:var(--om-success-bg);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto var(--om-space-3);">
                                <i class="lucide-check" style="font-size:32px;color:var(--om-success);"></i>
                            </div>
                            <h3 style="font-size:var(--om-font-lg);font-weight:var(--om-font-semibold);color:var(--om-success-dark);">Cardapio importado!</h3>
                            <p style="font-size:var(--om-font-sm);color:var(--om-text-secondary);margin:var(--om-space-2) 0;">
                                <strong id="aiSavedCount">0</strong> produtos foram adicionados ao seu cardapio
                            </p>
                            <p style="font-size:var(--om-font-xs);color:var(--om-text-muted);">
                                Voce pode editar, adicionar ou remover produtos a qualquer momento no painel.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="step-nav">
                    <button type="button" class="btn-back" onclick="goToStep(3)">
                        <i class="lucide-arrow-left"></i> Voltar
                    </button>
                    <button type="button" class="btn-next" id="btnFinish" onclick="finishSetup()">
                        <i class="lucide-check"></i> Concluir
                    </button>
                </div>
            </div>
        </div>

        <div class="setup-footer">
            Duvidas? <a href="mailto:suporte@onemundo.com.br">Fale com o suporte</a>
            &nbsp;|&nbsp;
            <a href="logout.php">Sair</a>
        </div>
    </div>

    <!-- Modal: Nova Categoria -->
    <div class="modal-overlay" id="modalCategory">
        <div class="modal-card" style="max-width:400px;">
            <div class="modal-title">
                <span id="modalCatTitle">Nova Categoria</span>
                <button class="modal-close" onclick="closeModal('modalCategory')">&times;</button>
            </div>
            <div class="om-form-group">
                <label class="om-label" for="catName">Nome da categoria *</label>
                <input type="text" id="catName" class="om-input" maxlength="100" placeholder="Ex: Lanches, Bebidas, Combos...">
            </div>
            <div style="display:flex;gap:var(--om-space-3);margin-top:var(--om-space-4);">
                <button type="button" class="om-btn" style="flex:1;background:var(--om-gray-100);color:var(--om-text-secondary);" onclick="closeModal('modalCategory')">Cancelar</button>
                <button type="button" class="om-btn om-btn-primary" style="flex:1;" id="btnSaveCat" onclick="saveCategory()">Salvar</button>
            </div>
        </div>
    </div>

    <!-- Modal: Novo/Editar Produto -->
    <div class="modal-overlay" id="modalProduct">
        <div class="modal-card">
            <div class="modal-title">
                <span id="modalProdTitle">Novo Produto</span>
                <button class="modal-close" onclick="closeModal('modalProduct')">&times;</button>
            </div>

            <div class="om-form-group">
                <label class="om-label" for="prodName">Nome do produto *</label>
                <input type="text" id="prodName" class="om-input" maxlength="255" placeholder="Ex: Hamburguer Classico">
            </div>

            <div class="om-form-group">
                <label class="om-label" for="prodDesc">Descricao</label>
                <textarea id="prodDesc" class="om-input" rows="2" maxlength="500" placeholder="Ingredientes, porcao, observacoes..."></textarea>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--om-space-3);">
                <div class="om-form-group">
                    <label class="om-label" for="prodPrice">Preco (R$) *</label>
                    <input type="text" id="prodPrice" class="om-input" placeholder="0,00">
                </div>
                <div class="om-form-group">
                    <label class="om-label" for="prodPromoPrice">Preco promo</label>
                    <input type="text" id="prodPromoPrice" class="om-input" placeholder="Opcional">
                </div>
            </div>

            <div class="om-form-group">
                <label class="om-label">Foto do produto</label>
                <div style="display:flex;align-items:center;gap:var(--om-space-3);">
                    <div class="prod-img" style="width:64px;height:64px;" onclick="document.getElementById('prodImgInput').click()">
                        <img id="prodImgPreview" src="" alt="" style="display:none;">
                        <i class="lucide-camera" id="prodImgIcon"></i>
                    </div>
                    <div style="font-size:var(--om-font-xs);color:var(--om-text-muted);">Clique para enviar (opcional)</div>
                    <input type="file" id="prodImgInput" accept="image/*" hidden>
                </div>
                <input type="hidden" id="prodImgUrl" value="">
            </div>

            <!-- Grupos de Complementos / Opcionais -->
            <div style="margin-top:var(--om-space-4);">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--om-space-2);">
                    <label class="om-label" style="margin:0;">Complementos / Opcionais</label>
                    <button type="button" style="background:none;border:none;color:var(--om-primary);cursor:pointer;font-size:var(--om-font-sm);font-weight:var(--om-font-medium);font-family:var(--om-font-family);" onclick="addOptionGroup()">
                        <i class="lucide-plus"></i> Grupo
                    </button>
                </div>
                <div id="prodOptionGroups">
                    <!-- Option groups rendered here -->
                </div>
                <div style="font-size:var(--om-font-xs);color:var(--om-text-muted);">
                    Ex: Tamanho (P, M, G), Adicionais (Bacon +R$3, Queijo +R$2)
                </div>
            </div>

            <div style="display:flex;gap:var(--om-space-3);margin-top:var(--om-space-5);">
                <button type="button" class="om-btn" style="flex:1;background:var(--om-gray-100);color:var(--om-text-secondary);" onclick="closeModal('modalProduct')">Cancelar</button>
                <button type="button" class="om-btn om-btn-primary" style="flex:1;" id="btnSaveProd" onclick="saveProduct()">Salvar Produto</button>
            </div>
        </div>
    </div>

    <!-- Celebration Overlay -->
    <div class="celebration-overlay" id="celebrationOverlay">
        <div class="celebration-card">
            <div class="celebration-confetti">&#127881;</div>
            <div class="celebration-icon">
                <i class="lucide-check"></i>
            </div>
            <div class="celebration-title">Tudo pronto!</div>
            <div class="celebration-message">
                Sua loja foi configurada com sucesso. Agora voce ja pode acessar o painel, gerenciar produtos e comecar a receber pedidos.
            </div>
            <div style="color:var(--om-text-muted);font-size:var(--om-font-sm);">Redirecionando para o painel...</div>
            <div class="spinner-sm spinner-dark" style="margin:var(--om-space-3) auto 0;"></div>
        </div>
    </div>

    <script>
    /* ═══════════════════════════════════════════════════════════════════════════
       STATE & CONFIG
       ═══════════════════════════════════════════════════════════════════════════ */
    const authToken = '<?= $authToken ?>';
    const tokenForAPI = localStorage.getItem('partner_token') || localStorage.getItem('auth_token') || authToken;

    let currentStep = 1;
    let logoUrl = <?= json_encode($partner['logo'] ?? '') ?>;
    let bannerUrl = <?= json_encode($partner['banner'] ?? '') ?>;
    let aiMenuData = null; // holds parsed AI results
    let aiMenuFile = null; // uploaded menu image

    const DAYS = [
        { key: 'seg', label: 'Seg' },
        { key: 'ter', label: 'Ter' },
        { key: 'qua', label: 'Qua' },
        { key: 'qui', label: 'Qui' },
        { key: 'sex', label: 'Sex' },
        { key: 'sab', label: 'Sab' },
        { key: 'dom', label: 'Dom' }
    ];

    const DEFAULTS = {
        seg: { open: true,  start: '08:00', end: '18:00' },
        ter: { open: true,  start: '08:00', end: '18:00' },
        qua: { open: true,  start: '08:00', end: '18:00' },
        qui: { open: true,  start: '08:00', end: '18:00' },
        sex: { open: true,  start: '08:00', end: '18:00' },
        sab: { open: true,  start: '08:00', end: '13:00' },
        dom: { open: false, start: '08:00', end: '18:00' }
    };

    // Pre-fill from existing data
    const existingSchedule = <?= json_encode($horarioExistente) ?>;

    // Pre-fill bank data
    const existingBank = {
        bank_name: <?= json_encode($partner['bank_name'] ?? '') ?>,
        bank_agency: <?= json_encode($partner['bank_agency'] ?? '') ?>,
        bank_account: <?= json_encode($partner['bank_account'] ?? '') ?>,
        pix_type: <?= json_encode($partner['pix_type'] ?? '') ?>,
        pix_key: <?= json_encode($partner['pix_key'] ?? '') ?>
    };

    /* ═══════════════════════════════════════════════════════════════════════════
       INIT
       ═══════════════════════════════════════════════════════════════════════════ */
    document.addEventListener('DOMContentLoaded', function() {
        buildScheduleGrid();
        prefillBankData();
        updateLogoZoneState();
        updateBannerZoneState();
    });

    /* ═══════════════════════════════════════════════════════════════════════════
       PROGRESS BAR
       ═══════════════════════════════════════════════════════════════════════════ */
    function updateProgress() {
        document.querySelectorAll('.progress-step').forEach(function(el) {
            var step = parseInt(el.dataset.step);
            el.classList.remove('active', 'completed');
            if (step < currentStep) {
                el.classList.add('completed');
                el.querySelector('.progress-circle').innerHTML = '<i class="lucide-check"></i>';
            } else if (step === currentStep) {
                el.classList.add('active');
                el.querySelector('.progress-circle').textContent = step;
            } else {
                el.querySelector('.progress-circle').textContent = step;
            }
        });

        document.querySelectorAll('.progress-line').forEach(function(el) {
            var lineStep = parseInt(el.dataset.line);
            el.classList.toggle('completed', lineStep < currentStep);
        });
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       NAVIGATION
       ═══════════════════════════════════════════════════════════════════════════ */
    function goToStep(step) {
        hideAlert();
        document.getElementById('step' + currentStep).classList.remove('active');
        currentStep = step;
        document.getElementById('step' + currentStep).classList.add('active');
        updateProgress();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       ALERT
       ═══════════════════════════════════════════════════════════════════════════ */
    function showAlert(msg, type) {
        var el = document.getElementById('alertMsg');
        var txt = document.getElementById('alertMsgText');
        el.className = 'alert-msg visible ' + (type || 'error');
        txt.textContent = msg;
        el.querySelector('i').className = type === 'success' ? 'lucide-check-circle' : 'lucide-alert-circle';
        el.style.flexShrink = '0';
    }

    function hideAlert() {
        document.getElementById('alertMsg').classList.remove('visible');
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       STEP 1 - LOGO & BANNER UPLOAD
       ═══════════════════════════════════════════════════════════════════════════ */
    function updateLogoZoneState() {
        var zone = document.getElementById('logoZone');
        var preview = document.getElementById('logoPreview');
        var placeholder = document.getElementById('logoPlaceholder');
        if (logoUrl) {
            zone.classList.add('has-image');
            preview.src = logoUrl;
            preview.style.display = 'block';
            if (placeholder) placeholder.style.display = 'none';
        }
    }

    function updateBannerZoneState() {
        var zone = document.getElementById('bannerZone');
        var preview = document.getElementById('bannerPreview');
        var placeholder = document.getElementById('bannerPlaceholder');
        if (bannerUrl) {
            zone.classList.add('has-image');
            preview.src = bannerUrl;
            preview.style.display = 'block';
            if (placeholder) placeholder.style.display = 'none';
        }
    }

    document.getElementById('logoInput').addEventListener('change', function(e) {
        if (e.target.files.length) uploadImage(e.target.files[0], 'logo');
    });

    document.getElementById('bannerInput').addEventListener('change', function(e) {
        if (e.target.files.length) uploadImage(e.target.files[0], 'banner');
    });

    async function uploadImage(file, type) {
        var loadingEl = document.getElementById(type + 'Loading');
        loadingEl.classList.add('visible');

        var formData = new FormData();
        formData.append('file', file);
        formData.append('type', type);

        try {
            var res = await fetch('/api/mercado/upload.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            var data = await res.json();

            if (data.success && data.data && data.data.url) {
                if (type === 'logo') {
                    logoUrl = data.data.url;
                    updateLogoZoneState();
                } else {
                    bannerUrl = data.data.url;
                    updateBannerZoneState();
                }
                showAlert('Imagem enviada com sucesso!', 'success');
                setTimeout(hideAlert, 2000);
            } else {
                showAlert(data.message || 'Erro ao enviar imagem.', 'error');
            }
        } catch (err) {
            showAlert('Erro de conexao ao enviar imagem.', 'error');
        } finally {
            loadingEl.classList.remove('visible');
        }
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       STEP 2 - SCHEDULE
       ═══════════════════════════════════════════════════════════════════════════ */
    function generateTimeOptions() {
        var options = '';
        for (var h = 0; h < 24; h++) {
            for (var m = 0; m < 60; m += 30) {
                var time = String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
                options += '<option value="' + time + '">' + time + '</option>';
            }
        }
        return options;
    }

    function buildScheduleGrid() {
        var grid = document.getElementById('scheduleGrid');
        var timeOpts = generateTimeOptions();
        var html = '';

        DAYS.forEach(function(day) {
            var sched = DEFAULTS[day.key];
            // Override with existing data if available
            if (existingSchedule && existingSchedule[day.key]) {
                sched = existingSchedule[day.key];
            }

            var isOpen = sched.open !== false;
            var startTime = sched.start || '08:00';
            var endTime = sched.end || '18:00';

            html += '<div class="schedule-row' + (isOpen ? '' : ' disabled') + '" id="row_' + day.key + '">';
            html += '  <div class="schedule-day">' + day.label + '</div>';
            html += '  <button type="button" class="schedule-toggle' + (isOpen ? ' on' : '') + '" id="toggle_' + day.key + '" onclick="toggleDay(\'' + day.key + '\')">';
            html += '    <span class="schedule-toggle-thumb"></span>';
            html += '  </button>';
            html += '  <select class="schedule-select" id="start_' + day.key + '"' + (isOpen ? '' : ' disabled') + '>' + timeOpts + '</select>';
            html += '  <div class="schedule-separator">as</div>';
            html += '  <select class="schedule-select" id="end_' + day.key + '"' + (isOpen ? '' : ' disabled') + '>' + timeOpts + '</select>';
            html += '</div>';
        });

        grid.innerHTML = html;

        // Set selected values after DOM render
        DAYS.forEach(function(day) {
            var sched = DEFAULTS[day.key];
            if (existingSchedule && existingSchedule[day.key]) {
                sched = existingSchedule[day.key];
            }
            var startTime = sched.start || '08:00';
            var endTime = sched.end || '18:00';
            document.getElementById('start_' + day.key).value = startTime;
            document.getElementById('end_' + day.key).value = endTime;
        });
    }

    function toggleDay(key) {
        var toggle = document.getElementById('toggle_' + key);
        var row = document.getElementById('row_' + key);
        var startSel = document.getElementById('start_' + key);
        var endSel = document.getElementById('end_' + key);
        var isOn = toggle.classList.toggle('on');

        row.classList.toggle('disabled', !isOn);
        startSel.disabled = !isOn;
        endSel.disabled = !isOn;
    }

    function getScheduleData() {
        var schedule = {};
        DAYS.forEach(function(day) {
            var toggle = document.getElementById('toggle_' + day.key);
            var isOpen = toggle.classList.contains('on');
            schedule[day.key] = {
                open: isOpen,
                start: document.getElementById('start_' + day.key).value,
                end: document.getElementById('end_' + day.key).value
            };
        });
        return schedule;
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       STEP 3 - BANK DATA
       ═══════════════════════════════════════════════════════════════════════════ */
    function prefillBankData() {
        if (existingBank.bank_name) {
            document.getElementById('bankName').value = existingBank.bank_name;
        }
        if (existingBank.bank_agency) {
            document.getElementById('bankAgency').value = existingBank.bank_agency;
        }
        if (existingBank.bank_account) {
            document.getElementById('bankAccount').value = existingBank.bank_account;
        }
        if (existingBank.pix_type) {
            document.getElementById('pixType').value = existingBank.pix_type;
        }
        if (existingBank.pix_key) {
            document.getElementById('pixKey').value = existingBank.pix_key;
        }
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       STEP 4 - MENU AI
       ═══════════════════════════════════════════════════════════════════════════ */
    function selectMenuOption(option) {
        document.getElementById('optionAI').classList.toggle('selected', option === 'ai');
        document.getElementById('optionManual').classList.toggle('selected', option === 'manual');
        document.getElementById('optionSkip').classList.toggle('selected', option === 'skip');

        var aiSection = document.getElementById('aiSection');
        var manualSection = document.getElementById('manualSection');

        aiSection.classList.toggle('visible', option === 'ai');
        manualSection.classList.toggle('visible', option === 'manual');
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       MANUAL MENU BUILDER - Montar cardapio do zero (estilo iFood)
       ═══════════════════════════════════════════════════════════════════════════ */
    let menuCategories = []; // { id, name, products: [{ name, desc, price, promoPrice, image, optionGroups: [{ name, required, options: [{ name, price }] }] }] }
    let editingCatIdx = -1;
    let editingProdIdx = -1;
    let editingProdCatIdx = -1;
    let tempOptionGroups = [];
    let tempProdImgUrl = '';

    function addCategory() {
        editingCatIdx = -1;
        document.getElementById('catName').value = '';
        document.getElementById('modalCatTitle').textContent = 'Nova Categoria';
        openModal('modalCategory');
        document.getElementById('catName').focus();
    }

    function editCategory(idx) {
        editingCatIdx = idx;
        document.getElementById('catName').value = menuCategories[idx].name;
        document.getElementById('modalCatTitle').textContent = 'Editar Categoria';
        openModal('modalCategory');
        document.getElementById('catName').focus();
    }

    function saveCategory() {
        var name = document.getElementById('catName').value.trim();
        if (!name) { document.getElementById('catName').style.borderColor = 'var(--om-error)'; return; }
        document.getElementById('catName').style.borderColor = '';

        if (editingCatIdx >= 0) {
            menuCategories[editingCatIdx].name = name;
        } else {
            menuCategories.push({ id: Date.now(), name: name, products: [], collapsed: false });
        }
        closeModal('modalCategory');
        renderMenu();
    }

    function deleteCategory(idx) {
        if (menuCategories[idx].products.length > 0) {
            if (!confirm('Apagar categoria "' + menuCategories[idx].name + '" e todos os ' + menuCategories[idx].products.length + ' produtos?')) return;
        }
        menuCategories.splice(idx, 1);
        renderMenu();
    }

    function toggleCategory(idx) {
        menuCategories[idx].collapsed = !menuCategories[idx].collapsed;
        renderMenu();
    }

    function addProduct(catIdx) {
        editingProdCatIdx = catIdx;
        editingProdIdx = -1;
        tempOptionGroups = [];
        tempProdImgUrl = '';
        document.getElementById('prodName').value = '';
        document.getElementById('prodDesc').value = '';
        document.getElementById('prodPrice').value = '';
        document.getElementById('prodPromoPrice').value = '';
        document.getElementById('prodImgUrl').value = '';
        document.getElementById('prodImgPreview').style.display = 'none';
        document.getElementById('prodImgIcon').style.display = '';
        document.getElementById('modalProdTitle').textContent = 'Novo Produto';
        renderOptionGroups();
        openModal('modalProduct');
        document.getElementById('prodName').focus();
    }

    function editProduct(catIdx, prodIdx) {
        editingProdCatIdx = catIdx;
        editingProdIdx = prodIdx;
        var p = menuCategories[catIdx].products[prodIdx];
        tempOptionGroups = JSON.parse(JSON.stringify(p.optionGroups || []));
        tempProdImgUrl = p.image || '';
        document.getElementById('prodName').value = p.name;
        document.getElementById('prodDesc').value = p.desc || '';
        document.getElementById('prodPrice').value = formatMoney(p.price);
        document.getElementById('prodPromoPrice').value = p.promoPrice ? formatMoney(p.promoPrice) : '';
        document.getElementById('prodImgUrl').value = tempProdImgUrl;
        if (tempProdImgUrl) {
            document.getElementById('prodImgPreview').src = tempProdImgUrl;
            document.getElementById('prodImgPreview').style.display = 'block';
            document.getElementById('prodImgIcon').style.display = 'none';
        } else {
            document.getElementById('prodImgPreview').style.display = 'none';
            document.getElementById('prodImgIcon').style.display = '';
        }
        document.getElementById('modalProdTitle').textContent = 'Editar Produto';
        renderOptionGroups();
        openModal('modalProduct');
    }

    function saveProduct() {
        var name = document.getElementById('prodName').value.trim();
        var price = parseMoney(document.getElementById('prodPrice').value);
        if (!name) { document.getElementById('prodName').style.borderColor = 'var(--om-error)'; return; }
        if (!price || price <= 0) { document.getElementById('prodPrice').style.borderColor = 'var(--om-error)'; return; }
        document.getElementById('prodName').style.borderColor = '';
        document.getElementById('prodPrice').style.borderColor = '';

        var prod = {
            name: name,
            desc: document.getElementById('prodDesc').value.trim(),
            price: price,
            promoPrice: parseMoney(document.getElementById('prodPromoPrice').value) || null,
            image: document.getElementById('prodImgUrl').value || tempProdImgUrl,
            optionGroups: tempOptionGroups.filter(function(g) { return g.name.trim(); })
        };

        if (editingProdIdx >= 0) {
            menuCategories[editingProdCatIdx].products[editingProdIdx] = prod;
        } else {
            menuCategories[editingProdCatIdx].products.push(prod);
        }
        closeModal('modalProduct');
        renderMenu();

        // Auto-save to API in background
        saveMenuToAPI();
    }

    function deleteProduct(catIdx, prodIdx) {
        menuCategories[catIdx].products.splice(prodIdx, 1);
        renderMenu();
    }

    // Option Groups inside product modal
    function addOptionGroup() {
        tempOptionGroups.push({ name: '', required: false, options: [{ name: '', price: 0 }] });
        renderOptionGroups();
    }

    function removeOptionGroup(gIdx) {
        tempOptionGroups.splice(gIdx, 1);
        renderOptionGroups();
    }

    function addOption(gIdx) {
        tempOptionGroups[gIdx].options.push({ name: '', price: 0 });
        renderOptionGroups();
    }

    function removeOption(gIdx, oIdx) {
        tempOptionGroups[gIdx].options.splice(oIdx, 1);
        renderOptionGroups();
    }

    function syncOptionGroupData() {
        tempOptionGroups.forEach(function(g, gIdx) {
            var nameEl = document.getElementById('og_name_' + gIdx);
            var reqEl = document.getElementById('og_req_' + gIdx);
            if (nameEl) g.name = nameEl.value;
            if (reqEl) g.required = reqEl.checked;
            g.options.forEach(function(o, oIdx) {
                var onEl = document.getElementById('oo_name_' + gIdx + '_' + oIdx);
                var opEl = document.getElementById('oo_price_' + gIdx + '_' + oIdx);
                if (onEl) o.name = onEl.value;
                if (opEl) o.price = parseMoney(opEl.value) || 0;
            });
        });
    }

    function renderOptionGroups() {
        syncOptionGroupData();
        var container = document.getElementById('prodOptionGroups');
        if (!tempOptionGroups.length) {
            container.innerHTML = '';
            return;
        }
        var html = '';
        tempOptionGroups.forEach(function(g, gIdx) {
            html += '<div class="opt-group-block">';
            html += '<div class="opt-group-header">';
            html += '<input type="text" id="og_name_' + gIdx + '" class="om-input" style="font-size:var(--om-font-sm);" placeholder="Nome do grupo (ex: Tamanho)" value="' + escAttr(g.name) + '">';
            html += '<label style="display:flex;align-items:center;gap:4px;font-size:var(--om-font-xs);white-space:nowrap;cursor:pointer;">';
            html += '<input type="checkbox" id="og_req_' + gIdx + '" ' + (g.required ? 'checked' : '') + '> Obrig.';
            html += '</label>';
            html += '<button type="button" class="cat-actions" style="display:inline;" onclick="removeOptionGroup(' + gIdx + ')"><button class="danger" title="Remover grupo" style="background:none;border:none;cursor:pointer;color:var(--om-text-muted);"><i class="lucide-trash-2"></i></button></button>';
            html += '</div>';

            g.options.forEach(function(o, oIdx) {
                html += '<div class="opt-item">';
                html += '<input type="text" id="oo_name_' + gIdx + '_' + oIdx + '" class="om-input" style="font-size:var(--om-font-xs);" placeholder="Nome da opcao" value="' + escAttr(o.name) + '">';
                html += '<input type="text" id="oo_price_' + gIdx + '_' + oIdx + '" class="om-input" style="font-size:var(--om-font-xs);" placeholder="+R$" value="' + (o.price > 0 ? formatMoney(o.price) : '') + '">';
                html += '<button type="button" onclick="removeOption(' + gIdx + ',' + oIdx + ')" style="background:none;border:none;cursor:pointer;color:var(--om-text-muted);font-size:12px;" title="Remover"><i class="lucide-x"></i></button>';
                html += '</div>';
            });

            html += '<button type="button" onclick="addOption(' + gIdx + ')" style="background:none;border:none;cursor:pointer;color:var(--om-primary);font-size:var(--om-font-xs);margin-top:4px;font-family:var(--om-font-family);"><i class="lucide-plus"></i> Opcao</button>';
            html += '</div>';
        });
        container.innerHTML = html;
    }

    function renderMenu() {
        var container = document.getElementById('manualCategories');
        var empty = document.getElementById('emptyMenuMsg');
        var summary = document.getElementById('manualSummary');

        if (!menuCategories.length) {
            container.innerHTML = '';
            empty.style.display = '';
            summary.style.display = 'none';
            return;
        }

        empty.style.display = 'none';
        var totalProds = 0;
        var html = '';

        menuCategories.forEach(function(cat, cIdx) {
            totalProds += cat.products.length;
            html += '<div class="cat-block">';
            html += '<div class="cat-header" onclick="toggleCategory(' + cIdx + ')">';
            html += '<i class="lucide-' + (cat.collapsed ? 'chevron-right' : 'chevron-down') + '" style="font-size:16px;color:var(--om-text-muted);"></i>';
            html += '<span class="cat-name">' + esc(cat.name) + '</span>';
            html += '<span class="cat-count">' + cat.products.length + ' item' + (cat.products.length !== 1 ? 's' : '') + '</span>';
            html += '<div class="cat-actions" onclick="event.stopPropagation()">';
            html += '<button title="Editar" onclick="editCategory(' + cIdx + ')"><i class="lucide-pencil"></i></button>';
            html += '<button class="danger" title="Excluir" onclick="deleteCategory(' + cIdx + ')"><i class="lucide-trash-2"></i></button>';
            html += '</div>';
            html += '</div>';

            html += '<div class="cat-body' + (cat.collapsed ? ' collapsed' : '') + '">';
            cat.products.forEach(function(p, pIdx) {
                html += '<div class="prod-row">';
                html += '<div class="prod-img">';
                if (p.image) {
                    html += '<img src="' + escAttr(p.image) + '" alt="">';
                } else {
                    html += '<i class="lucide-image"></i>';
                }
                html += '</div>';
                html += '<div class="prod-info">';
                html += '<div class="prod-name">' + esc(p.name) + '</div>';
                if (p.desc) html += '<div class="prod-desc">' + esc(p.desc) + '</div>';
                if (p.optionGroups && p.optionGroups.length) {
                    html += '<div class="prod-desc">' + p.optionGroups.length + ' grupo(s) de opcoes</div>';
                }
                html += '</div>';
                html += '<div class="prod-price">R$ ' + formatMoney(p.price) + '</div>';
                html += '<div class="prod-actions">';
                html += '<button title="Editar" onclick="editProduct(' + cIdx + ',' + pIdx + ')"><i class="lucide-pencil"></i></button>';
                html += '<button class="danger" title="Excluir" onclick="deleteProduct(' + cIdx + ',' + pIdx + ')"><i class="lucide-trash-2"></i></button>';
                html += '</div>';
                html += '</div>';
            });
            html += '<button class="add-prod-btn" onclick="addProduct(' + cIdx + ')"><i class="lucide-plus"></i> Adicionar produto</button>';
            html += '</div>';
            html += '</div>';
        });

        container.innerHTML = html;
        document.getElementById('manualCountCat').textContent = menuCategories.length;
        document.getElementById('manualCountProd').textContent = totalProds;
        summary.style.display = totalProds > 0 ? '' : 'none';
    }

    // Save manual menu to API (batch import)
    async function saveMenuToAPI() {
        if (!menuCategories.length) return;
        var payload = { categories: [] };
        menuCategories.forEach(function(cat) {
            if (!cat.products.length) return;
            var catData = { name: cat.name, products: [] };
            cat.products.forEach(function(p) {
                var prod = { name: p.name, description: p.desc || '', price: p.price, options: [] };
                (p.optionGroups || []).forEach(function(g) {
                    if (!g.name.trim()) return;
                    prod.options.push({
                        group_name: g.name,
                        required: g.required,
                        options: g.options.filter(function(o) { return o.name.trim(); }).map(function(o) {
                            return { name: o.name, price_modifier: o.price || 0 };
                        })
                    });
                });
                catData.products.push(prod);
            });
            payload.categories.push(catData);
        });

        try {
            var res = await fetch('/api/mercado/partner/ai-menu-confirm.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + tokenForAPI },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            });
            var data = await res.json();
            if (data.success) {
                console.log('[setup] Menu salvo:', data.data);
            } else {
                console.warn('[setup] Erro ao salvar menu:', data.message);
            }
        } catch (err) {
            console.warn('[setup] Erro ao salvar menu:', err);
        }
    }

    // Product image upload
    document.getElementById('prodImgInput').addEventListener('change', async function(e) {
        if (!e.target.files.length) return;
        var file = e.target.files[0];
        if (file.size > 2 * 1024 * 1024) { alert('Imagem muito grande (max 2MB)'); return; }

        // Preview
        var reader = new FileReader();
        reader.onload = function(ev) {
            document.getElementById('prodImgPreview').src = ev.target.result;
            document.getElementById('prodImgPreview').style.display = 'block';
            document.getElementById('prodImgIcon').style.display = 'none';
        };
        reader.readAsDataURL(file);

        // Upload
        var fd = new FormData();
        fd.append('file', file);
        fd.append('type', 'product');
        try {
            var res = await fetch('/api/mercado/upload.php', { method: 'POST', body: fd });
            var data = await res.json();
            if (data.success) {
                tempProdImgUrl = data.data.url;
                document.getElementById('prodImgUrl').value = data.data.url;
            }
        } catch (err) {
            console.warn('[setup] Upload erro:', err);
        }
    });

    // Helpers
    function openModal(id) { document.getElementById(id).classList.add('visible'); }
    function closeModal(id) { document.getElementById(id).classList.remove('visible'); }
    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function escAttr(s) { return String(s).replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
    function formatMoney(v) { return Number(v).toFixed(2).replace('.', ','); }
    function parseMoney(s) { if (!s) return 0; return parseFloat(String(s).replace(/[^\d,.-]/g, '').replace(',', '.')); }

    // Price mask for product inputs
    ['prodPrice', 'prodPromoPrice'].forEach(function(id) {
        document.getElementById(id).addEventListener('input', function(e) {
            var v = e.target.value.replace(/[^\d,]/g, '');
            e.target.value = v;
        });
    });

    // ═══ AI MENU - Fluxo estilo iFood com 4 fases ═══
    let aiMenuFiles = []; // multiplas imagens

    // Upload de multiplas imagens com preview
    document.getElementById('aiMenuInput').addEventListener('change', function(e) {
        for (var i = 0; i < e.target.files.length; i++) {
            var file = e.target.files[i];
            if (file.size > 10 * 1024 * 1024) { alert('Imagem ' + file.name + ' muito grande (max 10MB)'); continue; }
            aiMenuFiles.push(file);
        }
        renderImagePreviews();
        if (aiMenuFiles.length) document.getElementById('aiUploadZone').classList.add('has-file');
    });

    function renderImagePreviews() {
        var container = document.getElementById('aiImagePreviews');
        var html = '';
        aiMenuFiles.forEach(function(file, idx) {
            var url = URL.createObjectURL(file);
            html += '<div style="position:relative;width:72px;height:72px;border-radius:var(--om-radius-md);overflow:hidden;border:1px solid var(--om-gray-200);">';
            html += '<img src="' + url + '" style="width:100%;height:100%;object-fit:cover;">';
            html += '<button onclick="removeAIImage(' + idx + ')" style="position:absolute;top:2px;right:2px;background:rgba(0,0,0,0.6);color:white;border:none;border-radius:50%;width:20px;height:20px;cursor:pointer;font-size:12px;display:flex;align-items:center;justify-content:center;">&times;</button>';
            html += '</div>';
        });
        container.innerHTML = html;
    }

    function removeAIImage(idx) {
        aiMenuFiles.splice(idx, 1);
        renderImagePreviews();
        if (!aiMenuFiles.length) document.getElementById('aiUploadZone').classList.remove('has-file');
    }

    function showAIPhase(n) {
        for (var i = 1; i <= 4; i++) {
            document.getElementById('aiPhase' + i).style.display = (i === n) ? '' : 'none';
        }
    }

    function aiBackToUpload() {
        showAIPhase(1);
    }

    // Progresso simulado durante o loading
    let aiProgressInterval = null;
    function startProgress() {
        var fill = document.getElementById('aiProgressFill');
        var pct = 0;
        var messages = [
            'Lendo o cardapio...',
            'Identificando categorias e produtos...',
            'Extraindo precos e descricoes...',
            'Organizando seu cardapio...',
            'Quase pronto...'
        ];
        var msgEl = document.getElementById('aiLoadingMsg');
        fill.style.width = '0%';

        aiProgressInterval = setInterval(function() {
            pct += Math.random() * 8 + 2;
            if (pct > 90) pct = 90;
            fill.style.width = pct + '%';
            var msgIdx = Math.min(Math.floor(pct / 20), messages.length - 1);
            msgEl.textContent = messages[msgIdx];
        }, 800);
    }
    function stopProgress(success) {
        clearInterval(aiProgressInterval);
        var fill = document.getElementById('aiProgressFill');
        fill.style.width = success ? '100%' : '0%';
    }

    async function analyzeMenu() {
        hideAlert();

        var text = document.getElementById('aiMenuText').value.trim();
        var hasFiles = aiMenuFiles.length > 0;

        if (!hasFiles && !text) {
            showAlert('Envie fotos do cardapio ou digite/cole o texto.', 'error');
            return;
        }

        // Ir para fase 2 (loading)
        showAIPhase(2);
        startProgress();

        try {
            var res;
            if (hasFiles) {
                // Envia a primeira imagem (API aceita 1 por vez, podemos fazer loop)
                // Para multiplas: envia sequencialmente e combina resultados
                var allCategories = [];
                for (var fi = 0; fi < aiMenuFiles.length; fi++) {
                    var formData = new FormData();
                    formData.append('image', aiMenuFiles[fi]);
                    if (text && fi === 0) formData.append('text', text);

                    res = await fetch('/api/mercado/partner/ai-menu.php', {
                        method: 'POST',
                        headers: { 'Authorization': 'Bearer ' + tokenForAPI },
                        body: formData,
                        credentials: 'same-origin'
                    });
                    var data = await res.json();
                    if (data.success && data.data) {
                        var cats = data.data.categories || [];
                        allCategories = allCategories.concat(cats);
                    }
                }
                // Merge categorias com mesmo nome
                aiMenuData = { categories: mergeCategoriesByName(allCategories) };
            } else {
                res = await fetch('/api/mercado/partner/ai-menu.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + tokenForAPI },
                    body: JSON.stringify({ text: text }),
                    credentials: 'same-origin'
                });
                var data = await res.json();
                if (data.success && data.data) {
                    aiMenuData = data.data;
                } else {
                    throw new Error(data.message || 'Erro ao analisar');
                }
            }

            stopProgress(true);

            if (aiMenuData && aiMenuData.categories && aiMenuData.categories.length) {
                renderAIReview(aiMenuData.categories);
                showAIPhase(3);
            } else {
                stopProgress(false);
                showAIPhase(1);
                showAlert('Nao foi possivel identificar itens no cardapio. Tente com outra foto ou digite o texto.', 'error');
            }
        } catch (err) {
            stopProgress(false);
            showAIPhase(1);
            showAlert('Erro ao analisar cardapio: ' + (err.message || 'Tente novamente.'), 'error');
        }
    }

    function mergeCategoriesByName(cats) {
        var merged = {};
        cats.forEach(function(cat) {
            var key = (cat.name || 'Geral').toLowerCase().trim();
            if (!merged[key]) {
                merged[key] = { name: cat.name || 'Geral', products: [] };
            }
            merged[key].products = merged[key].products.concat(cat.products || []);
        });
        return Object.values(merged);
    }

    // FASE 3: Renderizar revisao editavel (estilo iFood)
    function renderAIReview(categories) {
        var container = document.getElementById('aiReviewList');
        var totalItems = 0;
        var html = '';

        categories.forEach(function(cat, cIdx) {
            var products = cat.products || [];
            totalItems += products.length;

            html += '<div class="ai-review-cat">';
            html += '<div class="ai-review-cat-header">';
            html += '<i class="lucide-folder" style="color:var(--om-primary);font-size:16px;flex-shrink:0;"></i>';
            html += '<input type="text" data-cat="' + cIdx + '" class="ai-cat-name" value="' + escAttr(cat.name || 'Categoria') + '">';
            html += '<span class="cat-count">' + products.length + '</span>';
            html += '</div>';

            products.forEach(function(prod, pIdx) {
                var name = prod.name || prod.nome || '';
                var desc = prod.description || prod.descricao || '';
                var price = prod.price || prod.preco || 0;
                var options = prod.options || [];

                html += '<div class="ai-review-item">';
                html += '<div class="ai-review-item-fields">';
                html += '<input type="text" class="item-name" data-cat="' + cIdx + '" data-prod="' + pIdx + '" data-field="name" value="' + escAttr(name) + '" placeholder="Nome do produto">';
                html += '<input type="text" class="item-desc" data-cat="' + cIdx + '" data-prod="' + pIdx + '" data-field="description" value="' + escAttr(desc) + '" placeholder="Descricao (ingredientes, porcao...)">';
                html += '</div>';
                html += '<input type="text" class="item-price-input" data-cat="' + cIdx + '" data-prod="' + pIdx + '" data-field="price" value="' + formatMoney(price) + '">';
                html += '<button class="item-delete" onclick="deleteAIItem(' + cIdx + ',' + pIdx + ')" title="Remover item"><i class="lucide-trash-2"></i></button>';

                // Mostrar complementos existentes
                if (options.length) {
                    html += '<div class="item-options">';
                    options.forEach(function(og) {
                        var gName = og.group_name || og.name || '';
                        var opts = og.options || [];
                        if (gName || opts.length) {
                            html += '<strong>' + esc(gName) + ':</strong> ';
                            opts.forEach(function(o) {
                                var extra = o.price_modifier || o.price_extra || 0;
                                html += '<span>' + esc(o.name) + (extra > 0 ? ' +R$' + formatMoney(extra) : '') + '</span> ';
                            });
                        }
                    });
                    html += '</div>';
                }

                html += '<button class="ai-add-complement-btn" onclick="addAIComplement(' + cIdx + ',' + pIdx + ')"><i class="lucide-plus"></i> Adicionar complemento</button>';
                html += '</div>';
            });

            html += '</div>';
        });

        container.innerHTML = html;
        document.getElementById('aiTotalCount').textContent = totalItems + ' ite' + (totalItems !== 1 ? 'ns' : 'm');
    }

    function deleteAIItem(cIdx, pIdx) {
        syncAIReviewData();
        aiMenuData.categories[cIdx].products.splice(pIdx, 1);
        if (aiMenuData.categories[cIdx].products.length === 0) {
            aiMenuData.categories.splice(cIdx, 1);
        }
        renderAIReview(aiMenuData.categories);
    }

    function addAIComplement(cIdx, pIdx) {
        var groupName = prompt('Nome do grupo de complemento (ex: Adicionais, Tamanho, Molho):');
        if (!groupName) return;
        var optionsStr = prompt('Opcoes separadas por virgula com preco (ex: Bacon +3, Queijo +2, Cheddar +2.50):');
        if (!optionsStr) return;

        syncAIReviewData();
        var prod = aiMenuData.categories[cIdx].products[pIdx];
        if (!prod.options) prod.options = [];

        var opts = optionsStr.split(',').map(function(s) {
            s = s.trim();
            var match = s.match(/^(.+?)\s*\+?\s*(\d+(?:[.,]\d+)?)?$/);
            return {
                name: match ? match[1].trim() : s,
                price_modifier: match && match[2] ? parseFloat(match[2].replace(',', '.')) : 0
            };
        });

        prod.options.push({ group_name: groupName, required: false, options: opts });
        renderAIReview(aiMenuData.categories);
    }

    // Sincronizar dados editados no DOM de volta para aiMenuData
    function syncAIReviewData() {
        if (!aiMenuData || !aiMenuData.categories) return;

        // Sync category names
        document.querySelectorAll('.ai-cat-name').forEach(function(el) {
            var cIdx = parseInt(el.dataset.cat);
            if (aiMenuData.categories[cIdx]) aiMenuData.categories[cIdx].name = el.value;
        });

        // Sync product fields
        document.querySelectorAll('.ai-review-item input[data-field]').forEach(function(el) {
            var cIdx = parseInt(el.dataset.cat);
            var pIdx = parseInt(el.dataset.prod);
            var field = el.dataset.field;
            if (aiMenuData.categories[cIdx] && aiMenuData.categories[cIdx].products[pIdx]) {
                if (field === 'price') {
                    aiMenuData.categories[cIdx].products[pIdx][field] = parseMoney(el.value);
                } else {
                    aiMenuData.categories[cIdx].products[pIdx][field] = el.value;
                }
            }
        });
    }

    // FASE 3 → FASE 4: Salvar cardapio revisado
    async function saveAIMenu() {
        syncAIReviewData();

        if (!aiMenuData || !aiMenuData.categories || !aiMenuData.categories.length) {
            showAlert('Nenhum item para salvar.', 'error');
            return;
        }

        var btn = document.getElementById('btnSaveMenu');
        btn.disabled = true;
        btn.innerHTML = '<div class="spinner-sm"></div> Salvando...';

        try {
            var res = await fetch('/api/mercado/partner/ai-menu-confirm.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + tokenForAPI },
                body: JSON.stringify(aiMenuData),
                credentials: 'same-origin'
            });
            var data = await res.json();

            if (data.success) {
                var count = data.data?.products_created || 0;
                document.getElementById('aiSavedCount').textContent = count;
                showAIPhase(4);
            } else {
                showAlert(data.message || 'Erro ao salvar cardapio.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="lucide-check"></i> Salvar e ir para cardapio';
            }
        } catch (err) {
            showAlert('Erro de conexao ao salvar.', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="lucide-check"></i> Salvar e ir para cardapio';
        }
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       SAVE STEP DATA
       ═══════════════════════════════════════════════════════════════════════════ */
    async function saveStepData(step, stepData) {
        try {
            var res = await fetch('/api/mercado/partner/setup-complete.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + tokenForAPI
                },
                body: JSON.stringify({ step: step, data: stepData }),
                credentials: 'same-origin'
            });

            var data = await res.json();
            return data;
        } catch (err) {
            return { success: false, message: 'Erro de conexao.' };
        }
    }

    async function saveAndNext(step) {
        hideAlert();

        var stepData = {};
        var stepName = '';

        // Validate + gather data per step
        if (step === 1) {
            stepName = 'logo';
            stepData = { logo: logoUrl, banner: bannerUrl };
        } else if (step === 2) {
            stepName = 'horario';
            stepData = { horario_funcionamento: getScheduleData() };
        } else if (step === 3) {
            stepName = 'banco';
            var bankName = document.getElementById('bankName').value;
            var pixType = document.getElementById('pixType').value;
            var pixKey = document.getElementById('pixKey').value.trim();

            if (!bankName) {
                showAlert('Selecione o banco.', 'error');
                return;
            }
            if (!pixType) {
                showAlert('Selecione o tipo de chave PIX.', 'error');
                return;
            }
            if (!pixKey) {
                showAlert('Informe a chave PIX.', 'error');
                return;
            }

            stepData = {
                bank_name: bankName,
                bank_agency: document.getElementById('bankAgency').value.trim(),
                bank_account: document.getElementById('bankAccount').value.trim(),
                pix_type: pixType,
                pix_key: pixKey
            };
        }

        // Find the "next" button to show loading state
        var nextBtn = document.querySelector('#step' + step + ' .btn-next');
        if (nextBtn) {
            nextBtn.disabled = true;
            nextBtn.innerHTML = '<div class="spinner-sm"></div> Salvando...';
        }

        var result = await saveStepData(stepName, stepData);

        if (nextBtn) {
            nextBtn.disabled = false;
            if (step === 4) {
                nextBtn.innerHTML = '<i class="lucide-check"></i> Concluir';
            } else {
                nextBtn.innerHTML = 'Proximo <i class="lucide-arrow-right"></i>';
            }
        }

        if (result.success) {
            goToStep(step + 1);
        } else {
            // Still navigate even if save had issues (data will be retried)
            showAlert(result.message || 'Erro ao salvar, mas voce pode continuar.', 'error');
            goToStep(step + 1);
        }
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       FINISH SETUP
       ═══════════════════════════════════════════════════════════════════════════ */
    async function finishSetup() {
        hideAlert();

        var btn = document.getElementById('btnFinish');
        btn.disabled = true;
        btn.innerHTML = '<div class="spinner-sm"></div> Finalizando...';

        // Save final step marker
        var result = await saveStepData('finalizar', { complete: true });

        if (result.success) {
            // Show celebration
            document.getElementById('celebrationOverlay').classList.add('visible');

            // Redirect after 3 seconds
            setTimeout(function() {
                window.location.href = 'index.php';
            }, 3000);
        } else {
            showAlert(result.message || 'Erro ao finalizar. Tente novamente.', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="lucide-check"></i> Concluir';
        }
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       UTILITIES
       ═══════════════════════════════════════════════════════════════════════════ */
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
    </script>
</body>
</html>
