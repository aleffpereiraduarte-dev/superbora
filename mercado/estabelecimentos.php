<?php
/**
 * Vitrine de Estabelecimentos - SuperBora / OneMundo Mercado
 * Experiencia iFood-like: CEP, busca lojas/produtos, mini-loja modal, carrinho separado
 */

require_once 'auth-guard.php';
require_once __DIR__ . '/includes/env_loader.php';

$pdo = null;
try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    $oc_root = dirname(__DIR__);
    if (file_exists($oc_root . '/config.php') && !defined('DB_DATABASE')) {
        require_once($oc_root . '/config.php');
    }
    if (defined('DB_HOSTNAME') && defined('DB_DATABASE')) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
                DB_USERNAME, DB_PASSWORD,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e2) {}
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_name('OCSESSID');
    session_start();
}

$customer_id = $_SESSION['customer_id'] ?? 0;
$customer_primeiro_nome = '';
$customer_bairro = '';
$cart_count = 0;

if ($customer_id && $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT firstname FROM oc_customer WHERE customer_id = ?");
        $stmt->execute([$customer_id]);
        $c = $stmt->fetch();
        if ($c) $customer_primeiro_nome = $c['firstname'];

        $stmt = $pdo->prepare("SELECT address_1 FROM oc_address WHERE customer_id = ? ORDER BY address_id DESC LIMIT 1");
        $stmt->execute([$customer_id]);
        $addr = $stmt->fetch();
        if ($addr) $customer_bairro = $addr['address_1'];
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estabelecimentos - OneMundo Mercado</title>
    <meta name="description" content="Encontre mercados, restaurantes, farmacias e lojas perto de voce. Faca suas compras online com entrega rapida.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f5f5f5;
            color: #1f2937;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }
        a { text-decoration: none; color: inherit; }

        /* Page Container */
        .vitrine-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 16px;
        }

        /* Hero / Search Section */
        .vitrine-hero {
            background: white;
            padding: 16px 0 20px;
            color: #1f2937;
            position: relative;
            border-bottom: 1px solid #e5e7eb;
        }
        .vitrine-hero-inner {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 16px;
            position: relative;
            z-index: 1;
        }
        .vitrine-hero h1 {
            display: none;
        }
        .vitrine-hero p {
            display: none;
        }

        /* Sticky Header */
        .vitrine-sticky-header {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid #e5e7eb;
            padding: 10px 0;
            transition: box-shadow 0.2s;
        }
        .vitrine-sticky-header.scrolled {
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }
        .vitrine-sticky-inner {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .sticky-address {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            cursor: pointer;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .sticky-address:hover { color: #059669; }
        .sticky-address-icon { font-size: 16px; }
        .sticky-address-arrow { font-size: 10px; color: #9ca3af; }
        .sticky-search {
            flex: 1;
            position: relative;
            max-width: 500px;
        }
        .sticky-search input {
            width: 100%;
            padding: 10px 14px 10px 38px;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            color: #1f2937;
            background: #f9fafb;
            transition: border-color 0.15s;
        }
        .sticky-search input:focus { outline: none; border-color: #10b981; background: white; }
        .sticky-search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 15px;
            pointer-events: none;
        }

        /* Address / CEP Row */
        .cep-row {
            display: flex;
            gap: 10px;
            max-width: 700px;
            margin-bottom: 16px;
        }
        .cep-input-wrap {
            position: relative;
            flex: 0 0 180px;
        }
        .cep-input-wrap input {
            width: 100%;
            padding: 14px 16px 14px 40px;
            border: 1.5px solid #e5e7eb;
            border-radius: 12px;
            font-size: 15px;
            font-family: inherit;
            background: #f9fafb;
            color: #1f2937;
        }
        .cep-input-wrap input:focus { outline: none; border-color: #10b981; background: white; }
        .cep-input-wrap input::placeholder { color: #9ca3af; }
        .cep-input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 16px;
            pointer-events: none;
        }
        .cep-btn {
            padding: 14px 24px;
            background: #fbbf24;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            font-family: inherit;
            color: #1f2937;
            cursor: pointer;
            white-space: nowrap;
            transition: background 0.2s;
        }
        .cep-btn:hover { background: #f59e0b; }
        .cep-btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .vitrine-location-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 14px 16px;
            background: #f9fafb;
            border: 1.5px solid #e5e7eb;
            border-radius: 12px;
            color: #374151;
            font-size: 14px;
            font-family: inherit;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s;
        }
        .vitrine-location-btn:hover { border-color: #10b981; color: #059669; }
        .cep-label {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 8px;
            min-height: 20px;
        }

        /* Search Row */
        .vitrine-search-row {
            display: flex;
            gap: 12px;
            max-width: 700px;
        }
        .vitrine-search-box {
            flex: 1;
            position: relative;
        }
        .vitrine-search-box input {
            width: 100%;
            padding: 14px 16px 14px 46px;
            border: 1.5px solid #e5e7eb;
            border-radius: 12px;
            font-size: 15px;
            font-family: inherit;
            background: #f9fafb;
            color: #1f2937;
        }
        .vitrine-search-box input:focus { outline: none; border-color: #10b981; background: white; }
        .vitrine-search-box input::placeholder { color: #9ca3af; }
        .vitrine-search-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 18px;
            pointer-events: none;
        }

        /* Search Mode Toggle (Lojas / Produtos) */
        .search-toggle {
            display: flex;
            gap: 4px;
            background: #f3f4f6;
            border-radius: 100px;
            padding: 4px;
            max-width: 260px;
            margin-top: 12px;
        }
        .search-toggle-btn {
            flex: 1;
            padding: 8px 20px;
            border: none;
            border-radius: 100px;
            font-size: 13px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.2s;
            background: transparent;
            color: #9ca3af;
        }
        .search-toggle-btn.active {
            background: white;
            color: #059669;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .search-toggle-btn:not(.active):hover {
            color: #374151;
            background: rgba(255,255,255,0.5);
        }

        /* Category Circles */
        .vitrine-tabs {
            display: flex;
            gap: 16px;
            padding: 24px 0 16px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            scroll-snap-type: x mandatory;
        }
        .vitrine-tabs::-webkit-scrollbar { display: none; }
        .vitrine-tab {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 0;
            border-radius: 0;
            border: none;
            background: transparent;
            font-size: 12px;
            font-weight: 600;
            font-family: inherit;
            color: #6b7280;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s;
            scroll-snap-align: start;
            min-width: 72px;
        }
        .vitrine-tab:hover { color: #059669; }
        .vitrine-tab:hover .vitrine-tab-icon { transform: scale(1.08); box-shadow: 0 4px 12px rgba(0,0,0,0.12); }
        .vitrine-tab.active { color: #059669; font-weight: 700; }
        .vitrine-tab.active .vitrine-tab-icon {
            box-shadow: 0 0 0 3px #059669;
        }
        .vitrine-tab-icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            transition: all 0.2s;
            background: #f3f4f6;
        }
        .vitrine-tab-icon.cat-mercado { background: #dcfce7; }
        .vitrine-tab-icon.cat-restaurante { background: #fee2e2; }
        .vitrine-tab-icon.cat-farmacia { background: #dbeafe; }
        .vitrine-tab-icon.cat-loja { background: #ede9fe; }
        .vitrine-tab-icon.cat-todos { background: #f3f4f6; }

        /* Results info */
        .vitrine-results-info {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 0 16px;
            font-size: 14px;
            color: #6b7280;
        }
        .vitrine-results-count { font-weight: 600; color: #374151; }

        /* Cards Grid */
        .vitrine-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            padding-bottom: 40px;
        }

        /* Store Card - Redesigned */
        .store-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
            border: none;
        }
        .store-card:hover {
            transform: scale(1.02);
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        }
        .store-card__banner {
            width: 100%;
            height: 180px;
            position: relative;
            overflow: hidden;
        }
        .store-card__banner-bg {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 56px;
        }
        .store-card__banner-bg.bg-mercado { background: linear-gradient(135deg, #d1fae5, #a7f3d0); }
        .store-card__banner-bg.bg-restaurante { background: linear-gradient(135deg, #fecaca, #fca5a5); }
        .store-card__banner-bg.bg-farmacia { background: linear-gradient(135deg, #bfdbfe, #93c5fd); }
        .store-card__banner-bg.bg-loja { background: linear-gradient(135deg, #ddd6fe, #c4b5fd); }
        .store-card__banner-bg.bg-supermercado { background: linear-gradient(135deg, #d1fae5, #6ee7b7); }
        .store-card__banner-bg.bg-padaria { background: linear-gradient(135deg, #fef3c7, #fcd34d); }
        .store-card__banner-bg.bg-acougue { background: linear-gradient(135deg, #fecaca, #f87171); }
        .store-card__banner-bg.bg-petshop { background: linear-gradient(135deg, #e0e7ff, #a5b4fc); }
        .store-card__banner-bg.bg-conveniencia { background: linear-gradient(135deg, #ccfbf1, #5eead4); }
        .store-card__banner img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .store-card__badge {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 4px 10px;
            border-radius: 100px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.3px;
            backdrop-filter: blur(8px);
        }
        .badge-mercado { background: rgba(16,185,129,0.9); color: white; }
        .badge-restaurante { background: rgba(239,68,68,0.9); color: white; }
        .badge-farmacia { background: rgba(59,130,246,0.9); color: white; }
        .badge-loja { background: rgba(139,92,246,0.9); color: white; }
        .badge-supermercado { background: rgba(16,185,129,0.9); color: white; }
        .badge-padaria { background: rgba(245,158,11,0.9); color: white; }
        .badge-acougue { background: rgba(220,38,38,0.9); color: white; }
        .badge-petshop { background: rgba(99,102,241,0.9); color: white; }
        .badge-conveniencia { background: rgba(20,184,166,0.9); color: white; }
        .store-card__status {
            position: absolute;
            top: 12px;
            left: 12px;
            padding: 4px 10px;
            border-radius: 100px;
            font-size: 11px;
            font-weight: 700;
        }
        .status-aberto { background: rgba(16,185,129,0.9); color: white; }
        .status-fechado { background: rgba(107,114,128,0.85); color: white; }

        .store-card__body { padding: 14px 16px 16px; }
        .store-card__header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        .store-card__logo {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: #f3f4f6;
            overflow: hidden;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            border: 2px solid #e5e7eb;
        }
        .store-card__logo img { width: 100%; height: 100%; object-fit: cover; }
        .store-card__name {
            font-size: 15px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 1px;
            display: -webkit-box;
            -webkit-line-clamp: 1;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .store-card__cat { font-size: 12px; color: #9ca3af; font-weight: 500; }
        .store-card__meta {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 4px;
            font-size: 13px;
            color: #6b7280;
        }
        .store-card__meta-item { display: flex; align-items: center; gap: 3px; }
        .store-card__meta-sep { color: #d1d5db; font-size: 10px; }
        .store-card__rating { color: #f59e0b; font-weight: 700; }
        .store-card__meta-delivery { color: #059669; font-weight: 600; }

        /* Highlight Horizontal Sections */
        .highlight-section {
            padding: 8px 0 16px;
        }
        .highlight-section h3 {
            font-size: 17px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 14px;
        }
        .highlight-scroll {
            display: flex;
            gap: 14px;
            overflow-x: auto;
            scrollbar-width: none;
            padding-bottom: 4px;
        }
        .highlight-scroll::-webkit-scrollbar { display: none; }
        .highlight-card {
            flex: 0 0 280px;
            background: white;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .highlight-card:hover {
            transform: scale(1.02);
            box-shadow: 0 6px 16px rgba(0,0,0,0.1);
        }
        .highlight-card__banner {
            width: 100%;
            height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 42px;
        }
        .highlight-card__body {
            padding: 10px 14px 14px;
        }
        .highlight-card__name {
            font-size: 14px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .highlight-card__meta {
            font-size: 12px;
            color: #6b7280;
        }

        /* Product Card (for global search) */
        .product-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            border: 1px solid rgba(0,0,0,0.04);
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
        }
        .product-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.08);
        }
        .product-card__img {
            width: 100%;
            height: 140px;
            background: #f3f4f6;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
        }
        .product-card__img img { width: 100%; height: 100%; object-fit: cover; }
        .product-card__body { padding: 12px; }
        .product-card__name {
            font-size: 14px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 4px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .product-card__price {
            font-size: 16px;
            font-weight: 800;
            color: #059669;
            margin-bottom: 6px;
        }
        .product-card__price-old {
            font-size: 12px;
            color: #9ca3af;
            text-decoration: line-through;
            margin-left: 6px;
            font-weight: 500;
        }
        .product-card__store {
            display: flex;
            align-items: center;
            gap: 6px;
            padding-top: 8px;
            border-top: 1px solid #f3f4f6;
            font-size: 11px;
            color: #6b7280;
        }
        .product-card__store-logo {
            width: 20px;
            height: 20px;
            border-radius: 4px;
            overflow: hidden;
            background: #f3f4f6;
            flex-shrink: 0;
        }
        .product-card__store-logo img { width: 100%; height: 100%; object-fit: cover; }

        /* Store group header (global search grouped) */
        .store-group-header {
            grid-column: 1 / -1;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 0 8px;
            cursor: pointer;
        }
        .store-group-header:first-child { padding-top: 0; }
        .store-group-logo {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            overflow: hidden;
            background: #f3f4f6;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            border: 2px solid #e5e7eb;
        }
        .store-group-logo img { width: 100%; height: 100%; object-fit: cover; }
        .store-group-info { flex: 1; }
        .store-group-name { font-size: 16px; font-weight: 700; color: #111827; }
        .store-group-meta { font-size: 12px; color: #6b7280; }
        .store-group-arrow { font-size: 20px; color: #9ca3af; }

        /* Empty State */
        .vitrine-empty {
            text-align: center;
            padding: 80px 20px;
            grid-column: 1 / -1;
        }
        .vitrine-empty-icon { font-size: 64px; margin-bottom: 16px; }
        .vitrine-empty h3 { font-size: 20px; font-weight: 700; color: #374151; margin-bottom: 8px; }
        .vitrine-empty p { font-size: 15px; color: #6b7280; max-width: 400px; margin: 0 auto; }

        /* Skeleton Loading */
        .skeleton-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid rgba(0,0,0,0.04);
        }
        .skeleton-banner {
            width: 100%;
            height: 180px;
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
        }
        .skeleton-body { padding: 16px; }
        .skeleton-line {
            height: 14px;
            border-radius: 6px;
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
            margin-bottom: 10px;
        }
        .skeleton-line.w60 { width: 60%; }
        .skeleton-line.w80 { width: 80%; }
        .skeleton-line.w40 { width: 40%; }
        .skeleton-circle {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
        }
        @keyframes shimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* ==================== MINI-LOJA MODAL ==================== */
        .mini-loja-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            display: none;
            align-items: flex-end;
            justify-content: center;
            backdrop-filter: blur(2px);
        }
        .mini-loja-overlay.open { display: flex; }
        .mini-loja-panel {
            background: #f9fafb;
            width: 100%;
            max-width: 800px;
            max-height: 92vh;
            border-radius: 20px 20px 0 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: slideUp 0.3s ease;
        }
        @keyframes slideUp {
            from { transform: translateY(100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Mini-loja header */
        .ml-header {
            position: relative;
            background: linear-gradient(135deg, #059669, #065f46);
            color: white;
            padding: 20px;
            flex-shrink: 0;
        }
        .ml-close {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 36px;
            height: 36px;
            border: none;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            color: white;
            font-size: 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }
        .ml-close:hover { background: rgba(255,255,255,0.35); }
        .ml-header-top {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 10px;
        }
        .ml-logo {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            background: white;
            overflow: hidden;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }
        .ml-logo img { width: 100%; height: 100%; object-fit: cover; }
        .ml-store-name { font-size: 20px; font-weight: 800; }
        .ml-store-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 13px;
            opacity: 0.9;
        }
        .ml-meta-item { display: flex; align-items: center; gap: 4px; }
        .ml-status-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 100px;
            font-size: 11px;
            font-weight: 700;
        }
        .ml-status-open { background: rgba(255,255,255,0.25); }
        .ml-status-closed { background: rgba(239,68,68,0.7); }

        /* Mini-loja promos carousel */
        .ml-promos {
            padding: 16px 20px;
            background: white;
            border-bottom: 1px solid #e5e7eb;
            flex-shrink: 0;
        }
        .ml-promos-title {
            font-size: 14px;
            font-weight: 700;
            color: #dc2626;
            margin-bottom: 10px;
        }
        .ml-promos-scroll {
            display: flex;
            gap: 12px;
            overflow-x: auto;
            scrollbar-width: none;
            padding-bottom: 4px;
        }
        .ml-promos-scroll::-webkit-scrollbar { display: none; }
        .ml-promo-card {
            flex: 0 0 140px;
            background: #fff7ed;
            border-radius: 12px;
            padding: 10px;
            border: 1px solid #fed7aa;
            cursor: pointer;
            transition: transform 0.15s;
        }
        .ml-promo-card:hover { transform: scale(1.03); }
        .ml-promo-img {
            width: 100%;
            height: 80px;
            border-radius: 8px;
            overflow: hidden;
            background: #f3f4f6;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
        }
        .ml-promo-img img { width: 100%; height: 100%; object-fit: cover; }
        .ml-promo-name {
            font-size: 12px;
            font-weight: 600;
            color: #111827;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            margin-bottom: 4px;
        }
        .ml-promo-prices { display: flex; align-items: center; gap: 6px; }
        .ml-promo-price-new { font-size: 14px; font-weight: 800; color: #dc2626; }
        .ml-promo-price-old { font-size: 11px; color: #9ca3af; text-decoration: line-through; }
        .ml-promo-badge {
            position: absolute;
            top: 6px;
            right: 6px;
            padding: 2px 6px;
            background: #dc2626;
            color: white;
            font-size: 10px;
            font-weight: 700;
            border-radius: 4px;
        }

        /* Mini-loja categories nav */
        .ml-cats {
            display: flex;
            gap: 6px;
            padding: 12px 20px;
            overflow-x: auto;
            scrollbar-width: none;
            background: white;
            border-bottom: 1px solid #e5e7eb;
            flex-shrink: 0;
        }
        .ml-cats::-webkit-scrollbar { display: none; }
        .ml-cat-btn {
            padding: 6px 16px;
            border: 1.5px solid #e5e7eb;
            border-radius: 100px;
            background: white;
            font-size: 13px;
            font-weight: 600;
            font-family: inherit;
            color: #6b7280;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.15s;
        }
        .ml-cat-btn:hover { border-color: #10b981; color: #059669; }
        .ml-cat-btn.active { background: #10b981; border-color: #10b981; color: white; }
        .ml-cat-count {
            font-size: 11px;
            opacity: 0.7;
            margin-left: 4px;
        }

        /* Mini-loja search */
        .ml-search {
            padding: 12px 20px;
            background: white;
            border-bottom: 1px solid #e5e7eb;
            flex-shrink: 0;
        }
        .ml-search-input {
            width: 100%;
            padding: 10px 14px 10px 38px;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            color: #1f2937;
            background: #f9fafb;
            transition: border-color 0.15s;
        }
        .ml-search-input:focus { outline: none; border-color: #10b981; }
        .ml-search-wrap {
            position: relative;
        }
        .ml-search-wrap::before {
            content: '\1F50D';
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 14px;
            pointer-events: none;
        }

        /* Mini-loja products grid */
        .ml-products {
            flex: 1;
            overflow-y: auto;
            padding: 16px 20px;
        }
        .ml-products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 12px;
        }
        .ml-product-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
            transition: box-shadow 0.15s;
        }
        .ml-product-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .ml-product-img {
            width: 100%;
            height: 110px;
            background: #f3f4f6;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
        }
        .ml-product-img img { width: 100%; height: 100%; object-fit: cover; }
        .ml-product-body { padding: 10px; }
        .ml-product-name {
            font-size: 13px;
            font-weight: 600;
            color: #111827;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            margin-bottom: 6px;
            min-height: 36px;
        }
        .ml-product-price { font-size: 15px; font-weight: 800; color: #059669; }
        .ml-product-price-promo { font-size: 15px; font-weight: 800; color: #dc2626; }
        .ml-product-price-old {
            font-size: 11px;
            color: #9ca3af;
            text-decoration: line-through;
            display: block;
            margin-bottom: 2px;
        }
        .ml-product-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 8px;
        }
        .ml-product-unit { font-size: 11px; color: #9ca3af; }
        .ml-qty-control {
            display: flex;
            align-items: center;
            gap: 0;
            border: 1.5px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
        }
        .ml-qty-btn {
            width: 30px;
            height: 30px;
            border: none;
            background: white;
            font-size: 16px;
            font-weight: 700;
            color: #059669;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.1s;
        }
        .ml-qty-btn:hover { background: #f0fdf4; }
        .ml-qty-btn:disabled { color: #d1d5db; cursor: default; }
        .ml-qty-btn:disabled:hover { background: white; }
        .ml-qty-val {
            width: 28px;
            text-align: center;
            font-size: 13px;
            font-weight: 700;
            color: #111827;
        }
        .ml-add-btn {
            width: 30px;
            height: 30px;
            border: none;
            border-radius: 8px;
            background: #059669;
            color: white;
            font-size: 20px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s;
        }
        .ml-add-btn:hover { background: #047857; }

        /* Mini-loja pagination */
        .ml-pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            padding: 16px 0;
        }
        .ml-page-btn {
            padding: 8px 16px;
            border: 1.5px solid #e5e7eb;
            border-radius: 8px;
            background: white;
            font-size: 13px;
            font-weight: 600;
            font-family: inherit;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.15s;
        }
        .ml-page-btn:hover { border-color: #10b981; color: #059669; }
        .ml-page-btn.active { background: #10b981; border-color: #10b981; color: white; }
        .ml-page-btn:disabled { opacity: 0.4; cursor: default; }

        /* Mini-loja loading skeleton */
        .ml-skeleton {
            padding: 20px;
        }
        .ml-skeleton-line {
            height: 16px;
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
            border-radius: 8px;
            margin-bottom: 12px;
        }

        /* Mini-loja cart bar (sticky bottom) */
        .ml-cart-bar {
            display: none;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            background: #059669;
            color: white;
            cursor: pointer;
            flex-shrink: 0;
            transition: background 0.2s;
        }
        .ml-cart-bar:hover { background: #047857; }
        .ml-cart-bar.visible { display: flex; }
        .ml-cart-bar-count {
            width: 28px;
            height: 28px;
            background: rgba(255,255,255,0.25);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            flex-shrink: 0;
        }
        .ml-cart-bar-label { flex: 1; font-size: 14px; font-weight: 600; }
        .ml-cart-bar-total { font-size: 16px; font-weight: 800; }

        /* ==================== SWITCH STORE DIALOG ==================== */
        .switch-dialog-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 3000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .switch-dialog-overlay.open { display: flex; }
        .switch-dialog {
            background: white;
            border-radius: 20px;
            padding: 28px;
            max-width: 380px;
            width: 100%;
            text-align: center;
            animation: popIn 0.2s ease;
        }
        @keyframes popIn {
            from { transform: scale(0.9); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        .switch-dialog-icon { font-size: 48px; margin-bottom: 12px; }
        .switch-dialog h3 { font-size: 18px; font-weight: 700; color: #111827; margin-bottom: 8px; }
        .switch-dialog p { font-size: 14px; color: #6b7280; margin-bottom: 24px; line-height: 1.6; }
        .switch-dialog-btns { display: flex; gap: 10px; }
        .switch-dialog-btn {
            flex: 1;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }
        .switch-btn-keep {
            background: #f3f4f6;
            color: #374151;
        }
        .switch-btn-keep:hover { background: #e5e7eb; }
        .switch-btn-clear {
            background: #dc2626;
            color: white;
        }
        .switch-btn-clear:hover { background: #b91c1c; }

        /* ==================== TOAST ==================== */
        .om-toast {
            position: fixed;
            bottom: 100px;
            left: 50%;
            transform: translateX(-50%) translateY(50px);
            background: #1e293b;
            color: #fff;
            padding: 14px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            z-index: 9999;
            opacity: 0;
            transition: all 0.3s;
            pointer-events: none;
            white-space: nowrap;
            max-width: 90vw;
        }
        .om-toast.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }
        .om-toast.success { background: #059669; }
        .om-toast.error { background: #dc2626; }

        /* ==================== RESPONSIVE ==================== */
        @media (min-width: 769px) {
            .mini-loja-overlay {
                align-items: center;
            }
            .mini-loja-panel {
                border-radius: 20px;
                max-height: 85vh;
            }
        }
        @media (max-width: 1024px) {
            .vitrine-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 640px) {
            .vitrine-grid { grid-template-columns: 1fr; gap: 14px; }
            .cep-row { flex-direction: column; }
            .cep-input-wrap { flex: 1; }
            .vitrine-search-row { flex-direction: column; }
            .store-card__banner { height: 160px; }
            .vitrine-hero { padding: 12px 0 16px; }
            .ml-products-grid { grid-template-columns: repeat(2, 1fr); }
            .vitrine-tabs { gap: 12px; padding: 16px 0 12px; }
            .vitrine-tab-icon { width: 60px; height: 60px; font-size: 24px; }
            .vitrine-tab { min-width: 60px; font-size: 11px; }
            .sort-filter-bar { overflow-x: auto; flex-wrap: nowrap; scrollbar-width: none; }
            .sort-filter-bar::-webkit-scrollbar { display: none; }
            .sticky-address { display: none; }
            .sticky-search { max-width: 100%; }
            .highlight-card { flex: 0 0 240px; }
        }
        /* ==================== PRODUCT DETAIL MODAL ==================== */
        .pd-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.55);
            z-index: 2500;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            backdrop-filter: blur(2px);
        }
        .pd-overlay.open { display: flex; }
        .pd-panel {
            background: white;
            border-radius: 20px;
            max-width: 480px;
            width: 100%;
            max-height: 85vh;
            overflow-y: auto;
            animation: popIn 0.2s ease;
        }
        .pd-img {
            width: 100%;
            height: 220px;
            background: #f3f4f6;
            border-radius: 20px 20px 0 0;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 72px;
            position: relative;
        }
        .pd-img img { width: 100%; height: 100%; object-fit: cover; }
        .pd-close {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 34px;
            height: 34px;
            border: none;
            border-radius: 50%;
            background: rgba(0,0,0,0.4);
            color: white;
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .pd-body { padding: 20px; }
        .pd-name { font-size: 20px; font-weight: 800; color: #111827; margin-bottom: 4px; }
        .pd-desc { font-size: 14px; color: #6b7280; margin-bottom: 12px; line-height: 1.6; }
        .pd-prices { display: flex; align-items: baseline; gap: 8px; margin-bottom: 16px; }
        .pd-price { font-size: 24px; font-weight: 800; color: #059669; }
        .pd-price-promo { color: #dc2626; }
        .pd-price-old { font-size: 15px; color: #9ca3af; text-decoration: line-through; }
        .pd-unit { font-size: 13px; color: #9ca3af; }
        .pd-section-title { font-size: 14px; font-weight: 700; color: #374151; margin-bottom: 8px; padding-top: 12px; border-top: 1px solid #f3f4f6; }
        .pd-required { font-size: 11px; color: #dc2626; font-weight: 600; margin-left: 6px; }
        .pd-option-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f9fafb;
        }
        .pd-option-label { font-size: 14px; color: #374151; display: flex; align-items: center; gap: 8px; }
        .pd-option-check {
            width: 20px;
            height: 20px;
            border: 2px solid #d1d5db;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s;
            flex-shrink: 0;
        }
        .pd-option-check.checked { background: #059669; border-color: #059669; color: white; font-size: 12px; }
        .pd-option-extra { font-size: 13px; color: #6b7280; white-space: nowrap; }
        .pd-notes { margin-top: 12px; }
        .pd-notes textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            min-height: 60px;
            color: #374151;
        }
        .pd-notes textarea:focus { outline: none; border-color: #10b981; }
        .pd-footer {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 20px;
            border-top: 1px solid #e5e7eb;
            background: #f9fafb;
            border-radius: 0 0 20px 20px;
        }
        .pd-qty-control {
            display: flex;
            align-items: center;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
        }
        .pd-qty-btn {
            width: 40px;
            height: 40px;
            border: none;
            background: white;
            font-size: 18px;
            font-weight: 700;
            color: #059669;
            cursor: pointer;
        }
        .pd-qty-btn:hover { background: #f0fdf4; }
        .pd-qty-val { width: 36px; text-align: center; font-size: 16px; font-weight: 700; }
        .pd-add-btn {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 10px;
            background: #059669;
            color: white;
            font-size: 15px;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            transition: background 0.2s;
        }
        .pd-add-btn:hover { background: #047857; }

        /* ==================== FAVORITES HEART ==================== */
        .store-card__fav {
            position: absolute;
            bottom: 10px;
            right: 10px;
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 50%;
            background: rgba(255,255,255,0.9);
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.15s;
            z-index: 2;
        }
        .store-card__fav:hover { transform: scale(1.15); }
        .store-card__fav.faved { background: #fef2f2; }

        /* ==================== SORT / FILTER BAR ==================== */
        .sort-filter-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .sort-select {
            padding: 8px 12px;
            border: 1.5px solid #e5e7eb;
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
            color: #374151;
            background: white;
            cursor: pointer;
        }
        .filter-chip {
            padding: 6px 14px;
            border: 1.5px solid #e5e7eb;
            border-radius: 100px;
            background: white;
            font-size: 12px;
            font-weight: 600;
            font-family: inherit;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.15s;
        }
        .filter-chip:hover { border-color: #10b981; color: #059669; }
        .filter-chip.active { background: #10b981; border-color: #10b981; color: white; }

        /* ==================== BANNERS CAROUSEL ==================== */
        .banners-section {
            padding: 20px 0 0;
        }
        .banners-scroll {
            display: flex;
            gap: 14px;
            overflow-x: auto;
            scrollbar-width: none;
            padding-bottom: 6px;
        }
        .banners-scroll::-webkit-scrollbar { display: none; }
        .banner-card {
            flex: 0 0 380px;
            padding: 24px;
            border-radius: 16px;
            color: white;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            min-height: 140px;
            transition: transform 0.15s;
        }
        .banner-card:hover { transform: scale(1.02); }
        .banner-card__title { font-size: 18px; font-weight: 800; margin-bottom: 6px; }
        .banner-card__sub { font-size: 14px; opacity: 0.9; }
        .banner-card__icon { font-size: 36px; position: absolute; right: 16px; top: 50%; transform: translateY(-50%); opacity: 0.3; }
        .coupon-card {
            flex: 0 0 200px;
            padding: 14px;
            border-radius: 12px;
            border: 2px dashed #10b981;
            background: #f0fdf4;
            cursor: pointer;
            transition: all 0.15s;
        }
        .coupon-card:hover { border-color: #059669; background: #ecfdf5; }
        .coupon-card__code { font-size: 15px; font-weight: 800; color: #059669; margin-bottom: 4px; }
        .coupon-card__desc { font-size: 12px; color: #6b7280; margin-bottom: 6px; }
        .coupon-card__val { font-size: 11px; color: #9ca3af; }

        /* ==================== REORDER SECTION ==================== */
        .reorder-section {
            padding: 16px 0;
        }
        .reorder-section h3 { font-size: 16px; font-weight: 700; color: #111827; margin-bottom: 12px; }
        .reorder-scroll {
            display: flex;
            gap: 12px;
            overflow-x: auto;
            scrollbar-width: none;
            padding-bottom: 4px;
        }
        .reorder-scroll::-webkit-scrollbar { display: none; }
        .reorder-card {
            flex: 0 0 260px;
            background: white;
            border-radius: 14px;
            padding: 14px;
            border: 1px solid #e5e7eb;
            cursor: pointer;
            transition: box-shadow 0.15s;
        }
        .reorder-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .reorder-card__header { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .reorder-card__logo {
            width: 36px; height: 36px; border-radius: 8px; overflow: hidden;
            background: #f3f4f6; flex-shrink: 0; display: flex; align-items: center;
            justify-content: center; font-size: 18px; border: 1px solid #e5e7eb;
        }
        .reorder-card__logo img { width: 100%; height: 100%; object-fit: cover; }
        .reorder-card__store { font-size: 14px; font-weight: 600; color: #111827; }
        .reorder-card__date { font-size: 11px; color: #9ca3af; }
        .reorder-card__items { font-size: 12px; color: #6b7280; margin-bottom: 10px; line-height: 1.5; }
        .reorder-card__total { font-size: 14px; font-weight: 700; color: #059669; }
        .reorder-btn {
            display: inline-block;
            margin-top: 8px;
            padding: 6px 16px;
            border: none;
            border-radius: 8px;
            background: #059669;
            color: white;
            font-size: 12px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
        }
        .reorder-btn:hover { background: #047857; }

        /* ==================== ORDER TRACKING ==================== */
        .tracking-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-top: 2px solid #10b981;
            padding: 12px 20px;
            z-index: 1500;
            display: none;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.1);
            cursor: pointer;
        }
        .tracking-bar.visible { display: flex; align-items: center; gap: 14px; }
        .tracking-bar__icon { font-size: 24px; flex-shrink: 0; }
        .tracking-bar__info { flex: 1; }
        .tracking-bar__status { font-size: 14px; font-weight: 700; color: #059669; }
        .tracking-bar__detail { font-size: 12px; color: #6b7280; }
        .tracking-bar__arrow { font-size: 20px; color: #9ca3af; }

        .tracking-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 3500;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .tracking-modal-overlay.open { display: flex; }
        .tracking-modal {
            background: white;
            border-radius: 20px;
            max-width: 420px;
            width: 100%;
            padding: 24px;
            animation: popIn 0.2s ease;
        }
        .tracking-modal h3 { font-size: 18px; font-weight: 700; margin-bottom: 16px; }
        .tracking-steps { display: flex; flex-direction: column; gap: 0; }
        .tracking-step {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            position: relative;
            padding-bottom: 20px;
        }
        .tracking-step:last-child { padding-bottom: 0; }
        .tracking-step::before {
            content: '';
            position: absolute;
            left: 14px;
            top: 30px;
            bottom: 0;
            width: 2px;
            background: #e5e7eb;
        }
        .tracking-step:last-child::before { display: none; }
        .tracking-step.active::before { background: #10b981; }
        .tracking-dot {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
            z-index: 1;
        }
        .tracking-step.active .tracking-dot { background: #10b981; color: white; }
        .tracking-step.done .tracking-dot { background: #059669; color: white; }
        .tracking-step-label { font-size: 14px; font-weight: 600; color: #374151; }
        .tracking-step-time { font-size: 12px; color: #9ca3af; }
        .tracking-step.active .tracking-step-label { color: #059669; }

        /* ==================== FLOATING CART BUTTON ==================== */
        .floating-cart {
            position: fixed;
            bottom: 24px;
            right: 24px;
            display: none;
            align-items: center;
            gap: 10px;
            padding: 14px 20px;
            background: #059669;
            color: white;
            border: none;
            border-radius: 16px;
            font-size: 14px;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            z-index: 1400;
            box-shadow: 0 8px 24px rgba(5,150,105,0.35);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .floating-cart:hover { transform: translateY(-2px); box-shadow: 0 12px 32px rgba(5,150,105,0.4); }
        .floating-cart.visible { display: flex; }
        .floating-cart__badge {
            width: 24px;
            height: 24px;
            background: rgba(255,255,255,0.25);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }

        /* ==================== SCHEDULE DELIVERY ==================== */
        .schedule-section { padding: 12px 20px; background: white; border-bottom: 1px solid #e5e7eb; flex-shrink: 0; }
        .schedule-row { display: flex; align-items: center; gap: 10px; }
        .schedule-label { font-size: 13px; color: #6b7280; flex-shrink: 0; }
        .schedule-select {
            padding: 8px 12px;
            border: 1.5px solid #e5e7eb;
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
            color: #374151;
            background: white;
        }

        /* ==================== ADDRESS MANAGER ==================== */
        .addr-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 3000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .addr-overlay.open { display: flex; }
        .addr-panel {
            background: white;
            border-radius: 20px;
            max-width: 440px;
            width: 100%;
            max-height: 80vh;
            overflow-y: auto;
            padding: 24px;
            animation: popIn 0.2s ease;
        }
        .addr-panel h3 { font-size: 18px; font-weight: 700; margin-bottom: 16px; }
        .addr-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border: 1.5px solid #e5e7eb;
            border-radius: 12px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: border-color 0.15s;
        }
        .addr-item:hover { border-color: #10b981; }
        .addr-item.active { border-color: #10b981; background: #f0fdf4; }
        .addr-item__icon { font-size: 20px; flex-shrink: 0; }
        .addr-item__info { flex: 1; }
        .addr-item__label { font-size: 14px; font-weight: 600; color: #111827; }
        .addr-item__addr { font-size: 12px; color: #6b7280; }
        .addr-item__del { width: 28px; height: 28px; border: none; background: none; font-size: 16px; color: #9ca3af; cursor: pointer; border-radius: 6px; }
        .addr-item__del:hover { background: #fef2f2; color: #dc2626; }
        .addr-add-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 12px;
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            background: none;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            color: #6b7280;
            cursor: pointer;
            margin-top: 8px;
        }
        .addr-add-btn:hover { border-color: #10b981; color: #059669; }
        .addr-form { margin-top: 16px; display: none; }
        .addr-form.show { display: block; }
        .addr-form input, .addr-form select {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            color: #374151;
            margin-bottom: 8px;
        }
        .addr-form input:focus, .addr-form select:focus { outline: none; border-color: #10b981; }
        .addr-save-btn {
            padding: 10px 24px;
            background: #059669;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
        }

        /* ==================== RATING MODAL ==================== */
        .rating-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 3500;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .rating-overlay.open { display: flex; }
        .rating-panel {
            background: white;
            border-radius: 20px;
            max-width: 380px;
            width: 100%;
            padding: 28px;
            text-align: center;
            animation: popIn 0.2s ease;
        }
        .rating-panel h3 { font-size: 18px; font-weight: 700; margin-bottom: 8px; }
        .rating-panel p { font-size: 14px; color: #6b7280; margin-bottom: 16px; }
        .rating-stars { display: flex; justify-content: center; gap: 8px; margin-bottom: 16px; }
        .rating-star {
            font-size: 36px;
            cursor: pointer;
            transition: transform 0.1s;
            filter: grayscale(1);
        }
        .rating-star:hover { transform: scale(1.2); }
        .rating-star.active { filter: grayscale(0); }
        .rating-comment {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            min-height: 60px;
            margin-bottom: 16px;
        }
        .rating-submit {
            width: 100%;
            padding: 12px;
            background: #059669;
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
        }
        .rating-submit:hover { background: #047857; }

        @media (max-width: 640px) {
            .pd-panel { max-width: 100%; border-radius: 20px 20px 0 0; }
            .pd-img { height: 180px; }
            .banner-card { flex: 0 0 300px; }
            .floating-cart { bottom: 16px; right: 16px; }
        }

        /* ==================== A2: AUTOCOMPLETE DROPDOWN ==================== */
        .ac-dropdown {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
            z-index: 100;
            display: none;
            max-height: 380px;
            overflow-y: auto;
        }
        .ac-dropdown.open { display: block; }
        .ac-section { padding: 8px 0; }
        .ac-section-title { font-size: 11px; font-weight: 700; color: #9ca3af; text-transform: uppercase; padding: 6px 16px; letter-spacing: 0.5px; }
        .ac-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            cursor: pointer;
            transition: background 0.1s;
        }
        .ac-item:hover { background: #f0fdf4; }
        .ac-item-img {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            overflow: hidden;
            background: #f3f4f6;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        .ac-item-img img { width: 100%; height: 100%; object-fit: cover; }
        .ac-item-info { flex: 1; min-width: 0; }
        .ac-item-name { font-size: 14px; font-weight: 600; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ac-item-meta { font-size: 12px; color: #6b7280; }
        .ac-item-price { font-size: 14px; font-weight: 700; color: #059669; white-space: nowrap; }
        .ac-recent { padding: 8px 16px; }
        .ac-recent-title { font-size: 11px; font-weight: 700; color: #9ca3af; text-transform: uppercase; margin-bottom: 6px; }
        .ac-recent-item {
            display: inline-block;
            padding: 4px 12px;
            background: #f3f4f6;
            border-radius: 100px;
            font-size: 13px;
            color: #374151;
            margin: 2px 4px 2px 0;
            cursor: pointer;
        }
        .ac-recent-item:hover { background: #e5e7eb; }

        /* ==================== A3: CATEGORY PILLS (sub-categorias) ==================== */
        .cat-pills {
            display: flex;
            gap: 8px;
            padding: 0 0 16px;
            overflow-x: auto;
            scrollbar-width: none;
        }
        .cat-pills::-webkit-scrollbar { display: none; }
        .cat-pill {
            padding: 8px 18px;
            border-radius: 100px;
            border: 1.5px solid #e5e7eb;
            background: white;
            font-size: 13px;
            font-weight: 600;
            font-family: inherit;
            color: #6b7280;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.15s;
        }
        .cat-pill:hover { border-color: #10b981; color: #059669; }
        .cat-pill.active { background: #10b981; border-color: #10b981; color: white; }

        /* ==================== A4: DELIVERY/PICKUP TOGGLE ==================== */
        .delivery-toggle {
            display: flex;
            gap: 4px;
            background: #f3f4f6;
            border-radius: 100px;
            padding: 4px;
            max-width: 260px;
            margin-top: 12px;
        }
        .delivery-toggle-btn {
            flex: 1;
            padding: 8px 20px;
            border: none;
            border-radius: 100px;
            font-size: 13px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.2s;
            background: transparent;
            color: #9ca3af;
        }
        .delivery-toggle-btn.active {
            background: white;
            color: #059669;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        /* ==================== A5: REVIEWS IN MINI-LOJA ==================== */
        .ml-reviews { padding: 16px 20px; border-top: 8px solid #f3f4f6; }
        .ml-reviews-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
        .ml-reviews-title { font-size: 16px; font-weight: 700; color: #111827; }
        .ml-reviews-avg { display: flex; align-items: center; gap: 6px; font-size: 14px; }
        .ml-reviews-score { font-weight: 800; color: #f59e0b; font-size: 18px; }
        .ml-review-card {
            padding: 12px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            margin-bottom: 8px;
        }
        .ml-review-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
        .ml-review-name { font-size: 13px; font-weight: 600; color: #374151; }
        .ml-review-date { font-size: 11px; color: #9ca3af; }
        .ml-review-stars { font-size: 12px; color: #f59e0b; margin-bottom: 4px; }
        .ml-review-text { font-size: 13px; color: #6b7280; line-height: 1.5; }
        .ml-reviews-dist { display: flex; gap: 6px; align-items: center; margin-bottom: 12px; }
        .ml-dist-bar { flex: 1; height: 6px; background: #f3f4f6; border-radius: 3px; overflow: hidden; }
        .ml-dist-fill { height: 100%; background: #f59e0b; border-radius: 3px; }
        .ml-dist-label { font-size: 12px; color: #6b7280; min-width: 20px; }

        /* ==================== A6: DELIVERY FEE SIMULATION ==================== */
        .ml-delivery-info {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            background: #f0fdf4;
            border-radius: 8px;
            font-size: 13px;
            color: #059669;
            font-weight: 600;
            margin-top: 8px;
        }
        .ml-delivery-free { background: #dcfce7; color: #16a34a; }

        /* ==================== A7: STORE HOURS ==================== */
        .ml-hours-dropdown {
            display: none;
            padding: 8px 12px;
            background: rgba(255,255,255,0.1);
            border-radius: 8px;
            margin-top: 8px;
            font-size: 13px;
            color: rgba(255,255,255,0.9);
        }
        .ml-hours-dropdown.open { display: block; }

        /* ==================== A1: COUPON INPUT IN CART BAR ==================== */
        .ml-coupon-row {
            display: flex;
            gap: 8px;
            padding: 10px 20px;
            background: #f9fafb;
            border-top: 1px solid #e5e7eb;
            flex-shrink: 0;
        }
        .ml-coupon-input {
            flex: 1;
            padding: 8px 12px;
            border: 1.5px solid #e5e7eb;
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
            text-transform: uppercase;
        }
        .ml-coupon-input:focus { outline: none; border-color: #10b981; }
        .ml-coupon-btn {
            padding: 8px 16px;
            background: #059669;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
        }
        .ml-coupon-btn:hover { background: #047857; }
        .ml-coupon-applied {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 20px;
            background: #f0fdf4;
            border-top: 1px solid #dcfce7;
            font-size: 13px;
            color: #16a34a;
            font-weight: 600;
            flex-shrink: 0;
        }
        .ml-coupon-remove {
            background: none;
            border: none;
            color: #dc2626;
            font-size: 12px;
            cursor: pointer;
            font-weight: 600;
        }

        /* ==================== B1+B2: CHECKOUT MODAL ==================== */
        .checkout-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 4000;
            display: none;
            align-items: flex-end;
            justify-content: center;
            backdrop-filter: blur(2px);
        }
        .checkout-overlay.open { display: flex; }
        .checkout-panel {
            background: white;
            width: 100%;
            max-width: 520px;
            max-height: 92vh;
            border-radius: 20px 20px 0 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: slideUp 0.3s ease;
        }
        @media (min-width: 769px) {
            .checkout-overlay { align-items: center; }
            .checkout-panel { border-radius: 20px; max-height: 85vh; }
        }
        .ck-header {
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .ck-header h3 { font-size: 18px; font-weight: 700; }
        .ck-close {
            width: 34px;
            height: 34px;
            border: none;
            border-radius: 50%;
            background: #f3f4f6;
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .ck-steps {
            display: flex;
            gap: 0;
            padding: 0 20px;
            border-bottom: 1px solid #e5e7eb;
            flex-shrink: 0;
        }
        .ck-step-tab {
            flex: 1;
            padding: 12px 0;
            text-align: center;
            font-size: 13px;
            font-weight: 600;
            color: #9ca3af;
            border-bottom: 2px solid transparent;
            cursor: pointer;
            transition: all 0.2s;
        }
        .ck-step-tab.active { color: #059669; border-bottom-color: #059669; }
        .ck-step-tab.done { color: #10b981; }
        .ck-body {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
        }
        .ck-section { margin-bottom: 20px; }
        .ck-section-title { font-size: 14px; font-weight: 700; color: #374151; margin-bottom: 10px; }
        .ck-input {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            color: #374151;
            margin-bottom: 8px;
        }
        .ck-input:focus { outline: none; border-color: #10b981; }
        .ck-radio-group { display: flex; flex-direction: column; gap: 8px; }
        .ck-radio {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.15s;
        }
        .ck-radio:hover { border-color: #10b981; }
        .ck-radio.selected { border-color: #10b981; background: #f0fdf4; }
        .ck-radio-dot {
            width: 20px;
            height: 20px;
            border: 2px solid #d1d5db;
            border-radius: 50%;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .ck-radio.selected .ck-radio-dot { border-color: #059669; }
        .ck-radio.selected .ck-radio-dot::after {
            content: '';
            width: 10px;
            height: 10px;
            background: #059669;
            border-radius: 50%;
        }
        .ck-radio-label { font-size: 14px; font-weight: 600; color: #374151; }
        .ck-radio-desc { font-size: 12px; color: #6b7280; }
        .ck-summary { background: #f9fafb; border-radius: 12px; padding: 16px; }
        .ck-summary-row {
            display: flex;
            justify-content: space-between;
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 6px;
        }
        .ck-summary-total {
            display: flex;
            justify-content: space-between;
            font-size: 18px;
            font-weight: 800;
            color: #111827;
            padding-top: 10px;
            border-top: 1px solid #e5e7eb;
            margin-top: 6px;
        }
        .ck-footer {
            padding: 16px 20px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            gap: 10px;
            flex-shrink: 0;
        }
        .ck-btn-back {
            padding: 12px 20px;
            background: #f3f4f6;
            color: #374151;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
        }
        .ck-btn-next {
            flex: 1;
            padding: 12px;
            background: #059669;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
        }
        .ck-btn-next:hover { background: #047857; }
        .ck-btn-next:disabled { opacity: 0.5; cursor: not-allowed; }

        /* PIX QR Code */
        .ck-pix-container { text-align: center; padding: 20px 0; }
        .ck-pix-qr {
            width: 200px;
            height: 200px;
            margin: 0 auto 16px;
            background: #f3f4f6;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 80px;
        }
        .ck-pix-code {
            padding: 10px;
            background: #f3f4f6;
            border-radius: 8px;
            font-size: 11px;
            word-break: break-all;
            margin-bottom: 12px;
            cursor: pointer;
        }
        .ck-pix-status { font-size: 14px; color: #f59e0b; font-weight: 600; }
        .ck-pix-status.paid { color: #16a34a; }

        /* ==================== B3: CHAT IN TRACKING ==================== */
        .tracking-tabs { display: flex; border-bottom: 1px solid #e5e7eb; margin-bottom: 16px; }
        .tracking-tab-btn {
            flex: 1;
            padding: 10px;
            border: none;
            background: none;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            color: #9ca3af;
            cursor: pointer;
            border-bottom: 2px solid transparent;
        }
        .tracking-tab-btn.active { color: #059669; border-bottom-color: #059669; }
        .chat-container { max-height: 300px; overflow-y: auto; }
        .chat-messages { display: flex; flex-direction: column; gap: 8px; padding: 8px 0; }
        .chat-bubble {
            max-width: 80%;
            padding: 10px 14px;
            border-radius: 16px;
            font-size: 14px;
            line-height: 1.4;
        }
        .chat-bubble.sent {
            align-self: flex-end;
            background: #059669;
            color: white;
            border-bottom-right-radius: 4px;
        }
        .chat-bubble.received {
            align-self: flex-start;
            background: #f3f4f6;
            color: #374151;
            border-bottom-left-radius: 4px;
        }
        .chat-time { font-size: 10px; opacity: 0.7; margin-top: 2px; }
        .chat-input-row {
            display: flex;
            gap: 8px;
            padding-top: 12px;
            border-top: 1px solid #e5e7eb;
        }
        .chat-input {
            flex: 1;
            padding: 10px 14px;
            border: 1.5px solid #e5e7eb;
            border-radius: 20px;
            font-size: 14px;
            font-family: inherit;
        }
        .chat-input:focus { outline: none; border-color: #10b981; }
        .chat-send-btn {
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 50%;
            background: #059669;
            color: white;
            font-size: 16px;
            cursor: pointer;
        }
        .chat-badge {
            width: 8px;
            height: 8px;
            background: #dc2626;
            border-radius: 50%;
            display: none;
            position: absolute;
            top: -2px;
            right: -2px;
        }

        /* ==================== B4: TIP BUTTONS ==================== */
        .tip-section { margin-top: 16px; padding-top: 16px; border-top: 1px solid #e5e7eb; }
        .tip-title { font-size: 14px; font-weight: 700; color: #374151; margin-bottom: 8px; }
        .tip-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
        .tip-btn {
            padding: 8px 16px;
            border: 1.5px solid #e5e7eb;
            border-radius: 100px;
            background: white;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            color: #374151;
            cursor: pointer;
            transition: all 0.15s;
        }
        .tip-btn:hover { border-color: #10b981; color: #059669; }
        .tip-btn.active { background: #059669; border-color: #059669; color: white; }
        .tip-custom {
            padding: 8px 12px;
            border: 1.5px solid #e5e7eb;
            border-radius: 100px;
            width: 90px;
            font-size: 14px;
            font-family: inherit;
            text-align: center;
        }

        /* ==================== B5: PRODUCT FILTERS IN MINI-LOJA ==================== */
        .ml-filters {
            display: flex;
            gap: 8px;
            padding: 8px 20px;
            overflow-x: auto;
            scrollbar-width: none;
            background: white;
            border-bottom: 1px solid #e5e7eb;
            flex-shrink: 0;
        }
        .ml-filters::-webkit-scrollbar { display: none; }
        .ml-filter-chip {
            padding: 6px 14px;
            border: 1.5px solid #e5e7eb;
            border-radius: 100px;
            background: white;
            font-size: 12px;
            font-weight: 600;
            font-family: inherit;
            color: #6b7280;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.15s;
        }
        .ml-filter-chip:hover { border-color: #10b981; color: #059669; }
        .ml-filter-chip.active { background: #10b981; border-color: #10b981; color: white; }

        /* ==================== B6: LOW STOCK BADGE ==================== */
        .stock-badge {
            position: absolute;
            top: 6px;
            left: 6px;
            padding: 2px 8px;
            background: #dc2626;
            color: white;
            font-size: 10px;
            font-weight: 700;
            border-radius: 4px;
            z-index: 1;
        }

        /* ==================== B7: SUBSTITUTION TOGGLE ==================== */
        .pd-substitute {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
            border-top: 1px solid #f3f4f6;
            margin-top: 12px;
        }
        .pd-substitute-label { font-size: 13px; color: #374151; }
        .toggle-switch {
            position: relative;
            width: 44px;
            height: 24px;
            background: #d1d5db;
            border-radius: 12px;
            cursor: pointer;
            transition: background 0.2s;
            flex-shrink: 0;
        }
        .toggle-switch.on { background: #10b981; }
        .toggle-switch::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 20px;
            height: 20px;
            background: white;
            border-radius: 50%;
            transition: transform 0.2s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.15);
        }
        .toggle-switch.on::after { transform: translateX(20px); }

        /* ==================== B8: MAP ==================== */
        .tracking-map {
            width: 100%;
            height: 200px;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 16px;
            background: #f3f4f6;
        }

        /* ==================== C1: PUSH NOTIFICATIONS ==================== */
        .push-banner {
            display: none;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            margin-bottom: 16px;
        }
        .push-banner.show { display: flex; }
        .push-banner-text { flex: 1; font-size: 13px; color: #1e40af; }
        .push-banner-btn {
            padding: 6px 16px;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
        }
        .push-banner-close {
            background: none;
            border: none;
            font-size: 16px;
            color: #6b7280;
            cursor: pointer;
        }

        /* ==================== C2+C3: RECOMMENDATIONS & TRENDING ==================== */
        .rec-section { padding: 16px 0; }
        .rec-section h3 { font-size: 16px; font-weight: 700; color: #111827; margin-bottom: 12px; }
        .rec-scroll {
            display: flex;
            gap: 12px;
            overflow-x: auto;
            scrollbar-width: none;
            padding-bottom: 4px;
        }
        .rec-scroll::-webkit-scrollbar { display: none; }
        .rec-card {
            flex: 0 0 160px;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
            cursor: pointer;
            transition: transform 0.15s;
        }
        .rec-card:hover { transform: scale(1.03); }
        .rec-card-img {
            width: 100%;
            height: 100px;
            background: #f3f4f6;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
        }
        .rec-card-img img { width: 100%; height: 100%; object-fit: cover; }
        .rec-card-body { padding: 10px; }
        .rec-card-name {
            font-size: 12px;
            font-weight: 600;
            color: #111827;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            margin-bottom: 4px;
            min-height: 32px;
        }
        .rec-card-price { font-size: 14px; font-weight: 800; color: #059669; }
        .rec-card-store { font-size: 11px; color: #6b7280; margin-top: 4px; }

        /* ==================== C4: SHARE CART ==================== */
        .share-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 8px;
            color: white;
            font-size: 12px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
        }

        /* ==================== C5: LOYALTY POINTS ==================== */
        .loyalty-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            background: #fef3c7;
            border-radius: 100px;
            font-size: 12px;
            font-weight: 700;
            color: #92400e;
        }

        /* ==================== ORDER SUCCESS ==================== */
        .order-success { text-align: center; padding: 30px 0; }
        .order-success-icon { font-size: 64px; margin-bottom: 12px; }
        .order-success h3 { font-size: 20px; font-weight: 800; color: #059669; margin-bottom: 8px; }
        .order-success p { font-size: 14px; color: #6b7280; }
    </style>
</head>
<body>

<?php include __DIR__ . '/components/header-mercado.php'; ?>

<!-- Sticky Header Bar -->
<div class="vitrine-sticky-header" id="stickyHeader">
    <div class="vitrine-sticky-inner">
        <div class="sticky-address" onclick="abrirEnderecos()">
            <span class="sticky-address-icon">&#128205;</span>
            <span id="stickyAddrLabel"><?php echo $customer_bairro ? htmlspecialchars($customer_bairro) : 'Selecione o endereco'; ?></span>
            <span class="sticky-address-arrow">&#9660;</span>
        </div>
        <div class="sticky-search">
            <span class="sticky-search-icon">&#128269;</span>
            <input type="text" id="vitrineSearch" placeholder="Buscar restaurante, mercado, produto..." autocomplete="off">
            <!-- A2: Autocomplete Dropdown -->
            <div class="ac-dropdown" id="acDropdown"></div>
        </div>
    </div>
</div>

<!-- Hero / Search (CEP + toggles) -->
<div class="vitrine-hero">
    <div class="vitrine-hero-inner">
        <h1>Estabelecimentos</h1>
        <p>Encontre mercados, restaurantes, farmacias e lojas perto de voce</p>

        <!-- CEP / Address Row -->
        <div class="cep-row">
            <div class="cep-input-wrap">
                <span class="cep-input-icon">&#128205;</span>
                <input type="text" id="cepInput" placeholder="00000-000" maxlength="9" autocomplete="off">
            </div>
            <button class="cep-btn" id="btnBuscarCep" onclick="buscarPorCep()">Buscar</button>
            <button class="vitrine-location-btn" id="btnLocalizacao" onclick="pedirLocalizacao()">
                <span>&#128225;</span>
                <span id="locLabel">GPS</span>
            </button>
            <button class="vitrine-location-btn" onclick="abrirEnderecos()" title="Meus enderecos">
                <span>&#127968;</span>
            </button>
        </div>
        <div class="cep-label" id="cepLabel"></div>

        <!-- Toggle: Lojas / Produtos -->
        <div class="search-toggle" id="searchToggle">
            <button class="search-toggle-btn active" data-mode="lojas" onclick="setSearchMode('lojas')">Lojas</button>
            <button class="search-toggle-btn" data-mode="produtos" onclick="setSearchMode('produtos')">Produtos</button>
        </div>

        <!-- A4: Delivery / Pickup Toggle -->
        <div class="delivery-toggle" id="deliveryToggle">
            <button class="delivery-toggle-btn active" data-mode="delivery" onclick="setDeliveryMode('delivery')">Entrega</button>
            <button class="delivery-toggle-btn" data-mode="pickup" onclick="setDeliveryMode('pickup')">Retirada</button>
        </div>
    </div>
</div>

<!-- Tabs + Content -->
<div class="vitrine-container">
    <div class="vitrine-tabs" role="tablist" id="vitrineTabs">
        <button class="vitrine-tab active" data-cat="" onclick="filtrarCategoria('')">
            <span class="vitrine-tab-icon cat-todos">&#127968;</span>
            <span>Todos</span>
        </button>
        <button class="vitrine-tab" data-cat="restaurante" onclick="filtrarCategoria('restaurante')">
            <span class="vitrine-tab-icon cat-restaurante">&#127869;</span>
            <span>Restaurantes</span>
        </button>
        <button class="vitrine-tab" data-cat="mercado" onclick="filtrarCategoria('mercado')">
            <span class="vitrine-tab-icon cat-mercado">&#128722;</span>
            <span>Mercados</span>
        </button>
        <button class="vitrine-tab" data-cat="farmacia" onclick="filtrarCategoria('farmacia')">
            <span class="vitrine-tab-icon cat-farmacia">&#128138;</span>
            <span>Farm&aacute;cias</span>
        </button>
        <button class="vitrine-tab" data-cat="loja" onclick="filtrarCategoria('loja')">
            <span class="vitrine-tab-icon cat-loja">&#128092;</span>
            <span>Lojas</span>
        </button>
    </div>

    <!-- A3: Sub-category Pills -->
    <div class="cat-pills" id="catPills">
        <button class="cat-pill active" data-subcat="" onclick="filtrarSubCategoria('')">Todos</button>
        <button class="cat-pill" data-subcat="supermercado" onclick="filtrarSubCategoria('supermercado')">Supermercado</button>
        <button class="cat-pill" data-subcat="padaria" onclick="filtrarSubCategoria('padaria')">Padaria</button>
        <button class="cat-pill" data-subcat="acougue" onclick="filtrarSubCategoria('acougue')">Acougue</button>
        <button class="cat-pill" data-subcat="farmacia" onclick="filtrarSubCategoria('farmacia')">Farmacia</button>
        <button class="cat-pill" data-subcat="restaurante" onclick="filtrarSubCategoria('restaurante')">Restaurante</button>
        <button class="cat-pill" data-subcat="petshop" onclick="filtrarSubCategoria('petshop')">Pet Shop</button>
        <button class="cat-pill" data-subcat="conveniencia" onclick="filtrarSubCategoria('conveniencia')">Conveniencia</button>
    </div>

    <!-- C1: Push Notification Banner -->
    <div class="push-banner" id="pushBanner">
        <span>&#128276;</span>
        <span class="push-banner-text">Ative notificacoes para acompanhar seus pedidos em tempo real</span>
        <button class="push-banner-btn" onclick="ativarPushNotifications()">Ativar</button>
        <button class="push-banner-close" onclick="fecharPushBanner()">&times;</button>
    </div>

    <!-- Banners / Coupons Carousel -->
    <div class="banners-section" id="bannersSection" style="display:none">
        <div class="banners-scroll" id="bannersScroll"></div>
    </div>

    <!-- Highlights: "Restaurantes em destaque" -->
    <div class="highlight-section" id="highlightRestSection" style="display:none">
        <h3>&#11088; Restaurantes em destaque</h3>
        <div class="highlight-scroll" id="highlightRestScroll"></div>
    </div>

    <!-- Highlights: "Novidades perto de voce" -->
    <div class="highlight-section" id="highlightNewSection" style="display:none">
        <h3>&#10024; Novidades perto de voce</h3>
        <div class="highlight-scroll" id="highlightNewScroll"></div>
    </div>

    <!-- C2: Recommendations "Pra voce" -->
    <div class="rec-section" id="recSection" style="display:none">
        <h3>&#127775; Pra voce</h3>
        <div class="rec-scroll" id="recScroll"></div>
    </div>

    <!-- C3: Trending "Em alta perto de voce" -->
    <div class="rec-section" id="trendingSection" style="display:none">
        <h3>&#128293; Em alta perto de voce</h3>
        <div class="rec-scroll" id="trendingScroll"></div>
    </div>

    <!-- Reorder Section -->
    <div class="reorder-section" id="reorderSection" style="display:none">
        <h3>&#128260; Pedir novamente</h3>
        <div class="reorder-scroll" id="reorderScroll"></div>
    </div>

    <div class="vitrine-results-info">
        <div>
            <span id="resultsText">Carregando...</span>
        </div>
        <!-- Sort / Filter -->
        <div class="sort-filter-bar">
            <select class="sort-select" id="sortSelect" onchange="aplicarOrdenacao()">
                <option value="">Ordenar por</option>
                <option value="rating">Melhor avaliados</option>
                <option value="delivery_fee">Menor taxa</option>
                <option value="delivery_time">Mais rapido</option>
                <option value="distance">Mais perto</option>
                <option value="name">A-Z</option>
            </select>
            <button class="filter-chip" id="filterOpen" onclick="toggleFilter('aberto')">Aberto agora</button>
            <button class="filter-chip" id="filterFree" onclick="toggleFilter('gratis')">Entrega gratis</button>
            <button class="filter-chip" id="filterFav" onclick="toggleFilter('favoritos')">&#10084; Favoritos</button>
        </div>
    </div>

    <div class="vitrine-grid" id="vitrineGrid">
        <?php for ($i = 0; $i < 6; $i++): ?>
        <div class="skeleton-card">
            <div class="skeleton-banner"></div>
            <div class="skeleton-body">
                <div style="display:flex;gap:12px;align-items:center;margin-bottom:12px;">
                    <div class="skeleton-circle"></div>
                    <div style="flex:1">
                        <div class="skeleton-line w60"></div>
                        <div class="skeleton-line w40"></div>
                    </div>
                </div>
                <div class="skeleton-line w80"></div>
                <div class="skeleton-line w60"></div>
            </div>
        </div>
        <?php endfor; ?>
    </div>
</div>

<!-- Mini-Loja Modal -->
<div class="mini-loja-overlay" id="miniLojaOverlay" onclick="if(event.target===this)fecharMiniLoja()">
    <div class="mini-loja-panel" id="miniLojaPanel">
        <div class="ml-header" id="mlHeader">
            <button class="ml-close" onclick="fecharMiniLoja()">&times;</button>
            <div class="ml-skeleton">
                <div class="ml-skeleton-line" style="width:60%;height:24px"></div>
                <div class="ml-skeleton-line" style="width:40%"></div>
            </div>
        </div>
        <div class="ml-products" id="mlBody">
            <div class="ml-skeleton">
                <div class="ml-skeleton-line" style="width:80%"></div>
                <div class="ml-skeleton-line" style="width:60%"></div>
                <div class="ml-skeleton-line" style="width:90%"></div>
            </div>
        </div>
        <div class="ml-cart-bar" id="mlCartBar" onclick="abrirCheckout()">
            <div class="ml-cart-bar-count" id="mlCartCount">0</div>
            <div class="ml-cart-bar-label">Finalizar pedido</div>
            <div class="ml-cart-bar-total" id="mlCartTotal">R$ 0,00</div>
        </div>
    </div>
</div>

<!-- Switch Store Dialog -->
<div class="switch-dialog-overlay" id="switchDialogOverlay" onclick="if(event.target===this)fecharSwitchDialog()">
    <div class="switch-dialog">
        <div class="switch-dialog-icon">&#128722;</div>
        <h3>Trocar de loja?</h3>
        <p id="switchDialogMsg">Seu carrinho tem itens de outra loja. Deseja limpar o carrinho e adicionar este produto?</p>
        <div class="switch-dialog-btns">
            <button class="switch-dialog-btn switch-btn-keep" onclick="fecharSwitchDialog()">Manter carrinho</button>
            <button class="switch-dialog-btn switch-btn-clear" id="switchBtnClear">Limpar e adicionar</button>
        </div>
    </div>
</div>

<!-- Product Detail Modal -->
<div class="pd-overlay" id="pdOverlay" onclick="if(event.target===this)fecharProdutoDetalhe()">
    <div class="pd-panel" id="pdPanel"></div>
</div>

<!-- Address Manager -->
<div class="addr-overlay" id="addrOverlay" onclick="if(event.target===this)fecharEnderecos()">
    <div class="addr-panel" id="addrPanel">
        <h3>&#128205; Meus enderecos</h3>
        <div id="addrList"></div>
        <button class="addr-add-btn" onclick="mostrarFormEndereco()">+ Adicionar endereco</button>
        <div class="addr-form" id="addrForm">
            <select id="addrLabel">
                <option value="Casa">Casa</option>
                <option value="Trabalho">Trabalho</option>
                <option value="Outro">Outro</option>
            </select>
            <input type="text" id="addrCep" placeholder="CEP" maxlength="9">
            <input type="text" id="addrRua" placeholder="Endereco completo">
            <input type="text" id="addrCidade" placeholder="Cidade">
            <input type="text" id="addrEstado" placeholder="UF" maxlength="2">
            <button class="addr-save-btn" onclick="salvarEndereco()">Salvar</button>
        </div>
    </div>
</div>

<!-- Rating Modal -->
<div class="rating-overlay" id="ratingOverlay" onclick="if(event.target===this)fecharAvaliacao()">
    <div class="rating-panel">
        <h3>Como foi sua experiencia?</h3>
        <p id="ratingStoreName"></p>
        <div class="rating-stars" id="ratingStars">
            <span class="rating-star" data-val="1" onclick="setRating(1)">&#11088;</span>
            <span class="rating-star" data-val="2" onclick="setRating(2)">&#11088;</span>
            <span class="rating-star" data-val="3" onclick="setRating(3)">&#11088;</span>
            <span class="rating-star" data-val="4" onclick="setRating(4)">&#11088;</span>
            <span class="rating-star" data-val="5" onclick="setRating(5)">&#11088;</span>
        </div>
        <textarea class="rating-comment" id="ratingComment" placeholder="Deixe um comentario (opcional)"></textarea>
        <button class="rating-submit" onclick="enviarAvaliacao()">Enviar avaliacao</button>
    </div>
</div>

<!-- Order Tracking Modal -->
<div class="tracking-modal-overlay" id="trackingOverlay" onclick="if(event.target===this)fecharTracking()">
    <div class="tracking-modal" id="trackingModal"></div>
</div>

<!-- Order Tracking Bar -->
<div class="tracking-bar" id="trackingBar" onclick="abrirTracking()">
    <div class="tracking-bar__icon">&#128666;</div>
    <div class="tracking-bar__info">
        <div class="tracking-bar__status" id="trackingBarStatus">Pedido em andamento</div>
        <div class="tracking-bar__detail" id="trackingBarDetail"></div>
    </div>
    <div class="tracking-bar__arrow">&#10095;</div>
</div>

<!-- Floating Cart Button -->
<button class="floating-cart" id="floatingCart" onclick="abrirCheckout()">
    <span class="floating-cart__badge" id="floatingCartBadge">0</span>
    <span>Ver carrinho</span>
    <span id="floatingCartTotal">R$ 0,00</span>
</button>

<!-- B1+B2: Checkout Modal -->
<div class="checkout-overlay" id="checkoutOverlay" onclick="if(event.target===this)fecharCheckout()">
    <div class="checkout-panel" id="checkoutPanel">
        <div class="ck-header">
            <h3>Finalizar pedido</h3>
            <button class="ck-close" onclick="fecharCheckout()">&times;</button>
        </div>
        <div class="ck-steps" id="ckSteps">
            <div class="ck-step-tab active" data-step="1">1. Entrega</div>
            <div class="ck-step-tab" data-step="2">2. Pagamento</div>
            <div class="ck-step-tab" data-step="3">3. Confirmar</div>
        </div>
        <div class="ck-body" id="ckBody"></div>
        <div class="ck-footer" id="ckFooter">
            <button class="ck-btn-back" id="ckBtnBack" onclick="ckPrevStep()" style="display:none">Voltar</button>
            <button class="ck-btn-next" id="ckBtnNext" onclick="ckNextStep()">Continuar</button>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="om-toast" id="toast"></div>

<!-- B8: Leaflet CSS for Map -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="" defer></script>

<?php include __DIR__ . '/components/footer-mercado.php'; ?>

<script>
(function() {
    'use strict';

    // ==================== STATE ====================
    var todosEstabelecimentos = [];
    var categoriaAtual = '';
    var buscaAtual = '';
    var searchMode = 'lojas';
    var userLat = null;
    var userLng = null;
    var cepAtual = localStorage.getItem('superbora_cep') || '';
    var cepFiltrado = false;
    var activeFilters = { aberto: false, gratis: false, favoritos: false };
    var sortBy = '';
    var favoriteStores = JSON.parse(localStorage.getItem('superbora_fav_stores') || '[]');

    // Mini-loja state
    var mlPartnerId = 0;
    var mlCategoryId = null;
    var mlPage = 1;
    var mlQuery = '';
    var mlData = null;

    // Cart state
    var cartPartnerId = parseInt(localStorage.getItem('cart_partner_id') || '0');
    var cartItems = {}; // { product_id: quantity }
    var cartTotal = 0;
    var cartCount = 0;
    var customerId = <?php echo (int)$customer_id; ?>;
    var sessionId = '<?php echo session_id(); ?>';

    // DOM refs
    var grid = document.getElementById('vitrineGrid');
    var resultsText = document.getElementById('resultsText');
    var searchInput = document.getElementById('vitrineSearch');
    var cepInput = document.getElementById('cepInput');
    var cepLabel = document.getElementById('cepLabel');

    var catLabels = {
        'mercado': 'Mercado',
        'supermercado': 'Supermercado',
        'restaurante': 'Restaurante',
        'farmacia': 'Farm\u00e1cia',
        'loja': 'Loja',
        'padaria': 'Padaria',
        'acougue': 'A\u00e7ougue',
        'petshop': 'Pet Shop',
        'conveniencia': 'Conveni\u00eancia'
    };
    var catIcons = {
        'mercado': '\uD83D\uDED2',
        'supermercado': '\uD83D\uDED2',
        'restaurante': '\uD83C\uDF7D',
        'farmacia': '\uD83D\uDC8A',
        'loja': '\uD83C\uDFAA',
        'padaria': '\uD83E\uDD50',
        'acougue': '\uD83E\uDD69',
        'petshop': '\uD83D\uDC3E',
        'conveniencia': '\uD83C\uDFEA'
    };
    // Map subcategories to main vitrine API categories
    var catToMainCat = {
        'mercado': 'mercado',
        'supermercado': 'mercado',
        'restaurante': 'restaurante',
        'farmacia': 'farmacia',
        'loja': 'loja',
        'padaria': 'loja',
        'acougue': 'mercado',
        'petshop': 'loja',
        'conveniencia': 'loja'
    };
    var bannerBgClass = function(cat) {
        var map = {
            'mercado': 'bg-mercado',
            'supermercado': 'bg-supermercado',
            'restaurante': 'bg-restaurante',
            'farmacia': 'bg-farmacia',
            'loja': 'bg-loja',
            'padaria': 'bg-padaria',
            'acougue': 'bg-acougue',
            'petshop': 'bg-petshop',
            'conveniencia': 'bg-conveniencia'
        };
        return map[cat] || 'bg-mercado';
    };
    // Match store category against main tab filter
    // "mercado" tab matches: mercado, supermercado, acougue
    // "loja" tab matches: loja, padaria, petshop, conveniencia
    function matchesMainCat(storeCat, filterCat) {
        if (!filterCat) return true;
        if (storeCat === filterCat) return true;
        if (filterCat === 'mercado' && (storeCat === 'supermercado' || storeCat === 'acougue')) return true;
        if (filterCat === 'loja' && (storeCat === 'padaria' || storeCat === 'petshop' || storeCat === 'conveniencia')) return true;
        return false;
    }

    // ==================== CEP MASK ====================
    cepInput.addEventListener('input', function() {
        var v = this.value.replace(/\D/g, '');
        if (v.length > 5) v = v.substring(0, 5) + '-' + v.substring(5, 8);
        this.value = v;
    });

    // Restore saved CEP
    if (cepAtual) {
        var fmtCep = cepAtual;
        if (fmtCep.length === 8) fmtCep = fmtCep.substring(0, 5) + '-' + fmtCep.substring(5);
        cepInput.value = fmtCep;
        buscarPorCep();
    }

    // ==================== CEP SEARCH ====================
    window.buscarPorCep = function() {
        var raw = cepInput.value.replace(/\D/g, '');
        if (raw.length !== 8) {
            showToast('Informe um CEP valido com 8 digitos', 'error');
            return;
        }
        cepAtual = raw;
        localStorage.setItem('superbora_cep', raw);

        var btn = document.getElementById('btnBuscarCep');
        btn.disabled = true;
        btn.textContent = 'Buscando...';

        // Resolve address via ViaCEP
        fetch('https://viacep.com.br/ws/' + raw + '/json/')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.erro) {
                    cepLabel.textContent = 'CEP nao encontrado';
                    cepFiltrado = false;
                    carregarEstabelecimentos();
                } else {
                    var local = data.bairro || data.localidade;
                    cepLabel.textContent = 'Mostrando lojas em ' + local + ' - ' + data.localidade + '/' + data.uf;
                    // Now filter by CEP
                    carregarPorCep(raw);
                }
            })
            .catch(function() {
                cepLabel.textContent = '';
                carregarPorCep(raw);
            })
            .finally(function() {
                btn.disabled = false;
                btn.textContent = 'Buscar';
            });
    };

    function carregarPorCep(cep) {
        cepFiltrado = true;
        showSkeletons();

        fetch('/api/mercado/parceiros/por-cep.php?cep=' + cep)
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.success && json.data && json.data.parceiros) {
                    todosEstabelecimentos = json.data.parceiros.map(function(p) {
                        return {
                            id: p.id,
                            nome: p.nome,
                            logo: p.logo,
                            categoria: 'mercado',
                            endereco: p.endereco || '',
                            cidade: p.cidade || '',
                            estado: '',
                            aberto: true,
                            avaliacao: p.avaliacao || 5.0,
                            taxa_entrega: p.taxa_entrega || 0,
                            tempo_estimado: p.tempo_estimado || 60,
                            total_produtos: 0,
                            distancia: null,
                            mensagem: p.mensagem || ''
                        };
                    });
                } else {
                    todosEstabelecimentos = [];
                }
                renderCards();
            })
            .catch(function() {
                todosEstabelecimentos = [];
                renderCards();
            });
    }

    // ==================== GEOLOCATION ====================
    window.pedirLocalizacao = function() {
        var label = document.getElementById('locLabel');
        if (!navigator.geolocation) {
            showToast('Navegador sem suporte a GPS', 'error');
            return;
        }
        label.textContent = '...';
        navigator.geolocation.getCurrentPosition(
            function(pos) {
                userLat = pos.coords.latitude;
                userLng = pos.coords.longitude;
                label.textContent = 'OK';
                cepFiltrado = false;
                cepLabel.textContent = '';
                carregarEstabelecimentos();
            },
            function() {
                label.textContent = 'GPS';
                showToast('Nao foi possivel obter sua localizacao', 'error');
            },
            { timeout: 10000 }
        );
    };

    // ==================== SEARCH MODE TOGGLE ====================
    window.setSearchMode = function(mode) {
        searchMode = mode;
        document.querySelectorAll('.search-toggle-btn').forEach(function(b) {
            b.classList.toggle('active', b.getAttribute('data-mode') === mode);
        });

        var tabsEl = document.getElementById('vitrineTabs');
        if (mode === 'produtos') {
            tabsEl.style.display = 'none';
            searchInput.placeholder = 'Buscar produtos em todas as lojas...';
        } else {
            tabsEl.style.display = 'flex';
            searchInput.placeholder = 'Buscar estabelecimento ou produto...';
        }

        // Trigger search if there's a query
        if (buscaAtual.length >= 2 && mode === 'produtos') {
            buscarProdutosGlobal(buscaAtual);
        } else {
            renderCards();
        }
    };

    // ==================== SEARCH INPUT ====================
    var searchTimeout;
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            buscaAtual = searchInput.value.trim().toLowerCase();
            if (searchMode === 'produtos' && buscaAtual.length >= 2) {
                buscarProdutosGlobal(buscaAtual);
            } else {
                renderCards();
            }
        }, 300);
    });

    // ==================== GLOBAL PRODUCT SEARCH ====================
    function buscarProdutosGlobal(q) {
        showSkeletons();
        var url = '/api/mercado/produtos/buscar-global.php?q=' + encodeURIComponent(q) + '&limit=30';
        if (cepAtual) url += '&cep=' + cepAtual;

        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.success && json.data) {
                    renderProdutosGlobal(json.data);
                } else {
                    grid.innerHTML = '<div class="vitrine-empty"><div class="vitrine-empty-icon">&#128269;</div><h3>Nenhum produto encontrado</h3><p>Tente buscar com outro termo.</p></div>';
                    resultsText.innerHTML = '<span class="vitrine-results-count">0</span> produtos encontrados';
                }
            })
            .catch(function() {
                grid.innerHTML = '<div class="vitrine-empty"><div class="vitrine-empty-icon">&#9888;</div><h3>Erro na busca</h3><p>Nao foi possivel buscar produtos. Tente novamente.</p></div>';
            });
    }

    function renderProdutosGlobal(data) {
        if (!data.agrupado || data.agrupado.length === 0) {
            grid.innerHTML = '<div class="vitrine-empty"><div class="vitrine-empty-icon">&#128269;</div><h3>Nenhum produto encontrado</h3><p>Tente buscar com outro termo.</p></div>';
            resultsText.innerHTML = '<span class="vitrine-results-count">0</span> produtos encontrados';
            return;
        }

        resultsText.innerHTML = '<span class="vitrine-results-count">' + data.total + '</span> produto' + (data.total !== 1 ? 's' : '') + ' em ' + data.agrupado.length + ' loja' + (data.agrupado.length !== 1 ? 's' : '');

        var html = '';
        data.agrupado.forEach(function(grupo) {
            // Store group header
            var logoHtml = grupo.parceiro_logo
                ? '<img src="' + escapeHtml(grupo.parceiro_logo) + '" alt="" onerror="this.parentNode.innerHTML=\'&#128722;\'">'
                : '&#128722;';

            html += '<div class="store-group-header" onclick="abrirMiniLoja(' + grupo.parceiro_id + ')">' +
                '<div class="store-group-logo">' + logoHtml + '</div>' +
                '<div class="store-group-info">' +
                    '<div class="store-group-name">' + escapeHtml(grupo.parceiro_nome) + '</div>' +
                    '<div class="store-group-meta">' +
                        '&#11088; ' + grupo.parceiro_avaliacao.toFixed(1) +
                        ' &middot; &#128666; ' + (grupo.parceiro_taxa > 0 ? 'R$ ' + grupo.parceiro_taxa.toFixed(2).replace('.', ',') : 'Gratis') +
                        ' &middot; ' + grupo.produtos.length + ' produto' + (grupo.produtos.length !== 1 ? 's' : '') +
                    '</div>' +
                '</div>' +
                '<div class="store-group-arrow">&#10095;</div>' +
            '</div>';

            // Products in this group
            grupo.produtos.forEach(function(p) {
                html += renderProductCard(p);
            });
        });

        grid.innerHTML = html;
    }

    function renderProductCard(p) {
        var imgHtml = p.imagem
            ? '<img src="' + escapeHtml(p.imagem) + '" alt="" onerror="this.parentNode.innerHTML=\'&#128230;\'">'
            : '&#128230;';

        var priceHtml;
        if (p.preco_promo) {
            priceHtml = '<span class="product-card__price-old">R$ ' + p.preco.toFixed(2).replace('.', ',') + '</span>' +
                '<span class="product-card__price" style="color:#dc2626">R$ ' + p.preco_promo.toFixed(2).replace('.', ',') + '</span>';
        } else {
            priceHtml = '<span class="product-card__price">R$ ' + p.preco.toFixed(2).replace('.', ',') + '</span>';
        }

        var storeLogoHtml = p.parceiro_logo
            ? '<img src="' + escapeHtml(p.parceiro_logo) + '" alt="">'
            : '';

        return '<div class="product-card" onclick="abrirMiniLoja(' + p.parceiro_id + ')">' +
            '<div class="product-card__img">' + imgHtml + '</div>' +
            '<div class="product-card__body">' +
                '<div class="product-card__name">' + escapeHtml(p.nome) + '</div>' +
                '<div>' + priceHtml + '</div>' +
                '<div class="product-card__store">' +
                    '<div class="product-card__store-logo">' + storeLogoHtml + '</div>' +
                    '<span>' + escapeHtml(p.parceiro_nome) + '</span>' +
                '</div>' +
            '</div>' +
        '</div>';
    }

    // ==================== LOAD ESTABLISHMENTS ====================
    function carregarEstabelecimentos() {
        showSkeletons();

        var url = '/api/mercado/parceiros/vitrine.php?_=' + Date.now();
        if (userLat && userLng) {
            url += '&lat=' + userLat + '&lng=' + userLng;
        }

        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.success && json.data && json.data.parceiros) {
                    todosEstabelecimentos = json.data.parceiros;
                } else {
                    todosEstabelecimentos = [];
                }
                renderCards();
            })
            .catch(function() {
                todosEstabelecimentos = [];
                renderCards();
            });
    }

    function showSkeletons() {
        var html = '';
        for (var i = 0; i < 6; i++) {
            html += '<div class="skeleton-card"><div class="skeleton-banner"></div><div class="skeleton-body">' +
                '<div style="display:flex;gap:12px;align-items:center;margin-bottom:12px"><div class="skeleton-circle"></div>' +
                '<div style="flex:1"><div class="skeleton-line w60"></div><div class="skeleton-line w40"></div></div></div>' +
                '<div class="skeleton-line w80"></div><div class="skeleton-line w60"></div></div></div>';
        }
        grid.innerHTML = html;
    }

    // ==================== CATEGORY FILTER ====================
    window.filtrarCategoria = function(cat) {
        categoriaAtual = cat;
        document.querySelectorAll('.vitrine-tab').forEach(function(tab) {
            tab.classList.toggle('active', tab.getAttribute('data-cat') === cat);
        });
        renderCards();
    };

    // ==================== RENDER STORE CARDS ====================
    function renderCards() {
        // This is overridden by the patched version below
        // Keeping as fallback that delegates to buildStoreCard
        if (searchMode === 'produtos' && buscaAtual.length >= 2) return;

        var filtered = todosEstabelecimentos.filter(function(e) {
            if (categoriaAtual && !matchesMainCat(e.categoria, categoriaAtual)) return false;
            if (buscaAtual && e.nome.toLowerCase().indexOf(buscaAtual) === -1) return false;
            return true;
        });
        filtered = filterEstabelecimentos(filtered);
        filtered = sortEstabelecimentos(filtered);

        resultsText.innerHTML = '<span class="vitrine-results-count">' + filtered.length + '</span> estabelecimento' + (filtered.length !== 1 ? 's' : '') + ' encontrado' + (filtered.length !== 1 ? 's' : '');

        if (filtered.length === 0) {
            grid.innerHTML = '<div class="vitrine-empty"><div class="vitrine-empty-icon">&#128269;</div><h3>Nenhum estabelecimento encontrado</h3><p>Tente buscar com outro termo ou selecione uma categoria diferente.</p></div>';
            return;
        }

        var html = '';
        filtered.forEach(function(e) {
            html += buildStoreCard(e);
        });
        grid.innerHTML = html;
    }

    // ==================== MINI-LOJA ====================
    window.abrirMiniLoja = function(partnerId) {
        mlPartnerId = partnerId;
        mlCategoryId = null;
        mlPage = 1;
        mlQuery = '';
        mlData = null;

        document.getElementById('miniLojaOverlay').classList.add('open');
        document.body.style.overflow = 'hidden';

        // Show skeleton
        document.getElementById('mlHeader').innerHTML = '<button class="ml-close" onclick="fecharMiniLoja()">&times;</button>' +
            '<div class="ml-skeleton"><div class="ml-skeleton-line" style="width:60%;height:24px"></div><div class="ml-skeleton-line" style="width:40%"></div></div>';
        document.getElementById('mlBody').innerHTML = '<div class="ml-skeleton"><div class="ml-skeleton-line" style="width:80%"></div><div class="ml-skeleton-line" style="width:60%"></div><div class="ml-skeleton-line" style="width:90%"></div></div>';

        // Remove promos/cats/search if they exist
        var oldPromos = document.querySelector('.ml-promos');
        if (oldPromos) oldPromos.remove();
        var oldCats = document.querySelector('.ml-cats');
        if (oldCats) oldCats.remove();
        var oldSearch = document.querySelector('.ml-search');
        if (oldSearch) oldSearch.remove();

        carregarMiniLoja();
    };

    window.fecharMiniLoja = function() {
        document.getElementById('miniLojaOverlay').classList.remove('open');
        document.body.style.overflow = '';
    };

    function carregarMiniLoja() {
        var url = '/api/mercado/parceiros/mini-loja.php?id=' + mlPartnerId + '&page=' + mlPage;
        if (mlCategoryId) url += '&category_id=' + mlCategoryId;
        if (mlQuery) url += '&q=' + encodeURIComponent(mlQuery);

        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.success && json.data) {
                    mlData = json.data;
                    renderMiniLoja();
                } else {
                    document.getElementById('mlBody').innerHTML = '<div class="vitrine-empty"><div class="vitrine-empty-icon">&#9888;</div><h3>Erro ao carregar</h3><p>Nao foi possivel carregar os dados desta loja.</p></div>';
                }
            })
            .catch(function() {
                document.getElementById('mlBody').innerHTML = '<div class="vitrine-empty"><div class="vitrine-empty-icon">&#9888;</div><h3>Erro de conexao</h3><p>Tente novamente.</p></div>';
            });
    }

    function renderMiniLoja() {
        var p = mlData.parceiro;
        var panel = document.getElementById('miniLojaPanel');

        // Header
        var logoHtml = p.logo
            ? '<img src="' + escapeHtml(p.logo) + '" alt="" onerror="this.parentNode.innerHTML=\'&#128722;\'">'
            : '&#128722;';

        var statusBadge = p.aberto
            ? '<span class="ml-status-badge ml-status-open">Aberto</span>'
            : '<span class="ml-status-badge ml-status-closed">Fechado</span>';

        document.getElementById('mlHeader').innerHTML =
            '<button class="ml-close" onclick="fecharMiniLoja()">&times;</button>' +
            '<div class="ml-header-top">' +
                '<div class="ml-logo">' + logoHtml + '</div>' +
                '<div>' +
                    '<div class="ml-store-name">' + escapeHtml(p.nome) + '</div>' +
                    statusBadge +
                '</div>' +
            '</div>' +
            '<div class="ml-store-meta">' +
                '<div class="ml-meta-item">&#11088; ' + p.avaliacao.toFixed(1) + '</div>' +
                '<div class="ml-meta-item">&#128666; ' + (p.taxa_entrega > 0 ? 'R$ ' + p.taxa_entrega.toFixed(2).replace('.', ',') : 'Gratis') + '</div>' +
                '<div class="ml-meta-item">&#9200; ' + p.tempo_estimado + ' min</div>' +
                (p.pedido_minimo > 0 ? '<div class="ml-meta-item">Pedido min: R$ ' + p.pedido_minimo.toFixed(2).replace('.', ',') + '</div>' : '') +
            '</div>';

        // Remove old dynamic sections
        var oldPromos = panel.querySelector('.ml-promos');
        if (oldPromos) oldPromos.remove();
        var oldCats = panel.querySelector('.ml-cats');
        if (oldCats) oldCats.remove();
        var oldSearch = panel.querySelector('.ml-search');
        if (oldSearch) oldSearch.remove();

        var header = document.getElementById('mlHeader');
        var body = document.getElementById('mlBody');

        // Promotions carousel (only on first load)
        if (mlData.promocoes && mlData.promocoes.length > 0 && mlPage === 1 && !mlCategoryId && !mlQuery) {
            var promosDiv = document.createElement('div');
            promosDiv.className = 'ml-promos';
            var promosHtml = '<div class="ml-promos-title">&#128293; Ofertas</div><div class="ml-promos-scroll">';
            mlData.promocoes.forEach(function(promo) {
                var pImgHtml = promo.imagem
                    ? '<img src="' + escapeHtml(promo.imagem) + '" alt="" onerror="this.parentNode.innerHTML=\'&#127873;\'">'
                    : '&#127873;';
                promosHtml += '<div class="ml-promo-card" onclick="adicionarAoCarrinhoMl(' + promo.id + ', ' + mlPartnerId + ', ' + (promo.preco_promo || promo.preco) + ')">' +
                    '<div class="ml-promo-img">' + pImgHtml + '</div>' +
                    '<div class="ml-promo-name">' + escapeHtml(promo.nome) + '</div>' +
                    '<div class="ml-promo-prices">' +
                        '<span class="ml-promo-price-new">R$ ' + promo.preco_promo.toFixed(2).replace('.', ',') + '</span>' +
                        '<span class="ml-promo-price-old">R$ ' + promo.preco.toFixed(2).replace('.', ',') + '</span>' +
                    '</div>' +
                '</div>';
            });
            promosHtml += '</div>';
            promosDiv.innerHTML = promosHtml;
            header.after(promosDiv);
        }

        // Category nav
        if (mlData.categorias && mlData.categorias.length > 0) {
            var catsDiv = document.createElement('div');
            catsDiv.className = 'ml-cats';
            catsDiv.id = 'mlCats';
            var catsHtml = '<button class="ml-cat-btn' + (!mlCategoryId ? ' active' : '') + '" onclick="mlFiltrarCategoria(null)">Todos</button>';
            mlData.categorias.forEach(function(cat) {
                catsHtml += '<button class="ml-cat-btn' + (mlCategoryId === cat.id ? ' active' : '') + '" onclick="mlFiltrarCategoria(' + cat.id + ')">' +
                    escapeHtml(cat.nome) + '<span class="ml-cat-count">(' + cat.total + ')</span></button>';
            });
            catsDiv.innerHTML = catsHtml;

            // Insert after promos or header
            var insertAfter = panel.querySelector('.ml-promos') || header;
            insertAfter.after(catsDiv);
        }

        // Search bar
        var searchDiv = document.createElement('div');
        searchDiv.className = 'ml-search';
        searchDiv.innerHTML = '<div class="ml-search-wrap"><input type="text" class="ml-search-input" id="mlSearchInput" placeholder="Buscar nesta loja..." value="' + escapeHtml(mlQuery) + '"></div>';
        var insertAfterSearch = panel.querySelector('.ml-cats') || panel.querySelector('.ml-promos') || header;
        insertAfterSearch.after(searchDiv);

        // Bind search
        var mlSearchInput = document.getElementById('mlSearchInput');
        var mlSearchTimeout;
        mlSearchInput.addEventListener('input', function() {
            clearTimeout(mlSearchTimeout);
            mlSearchTimeout = setTimeout(function() {
                mlQuery = mlSearchInput.value.trim();
                mlPage = 1;
                carregarMiniLoja();
            }, 300);
        });
        mlSearchInput.focus();

        // Schedule delivery section
        var schedDiv = panel.querySelector('.schedule-section');
        if (schedDiv) schedDiv.remove();
        var insertAfterSched = panel.querySelector('.ml-search') || panel.querySelector('.ml-cats') || header;
        var schedEl = document.createElement('div');
        schedEl.innerHTML = getScheduleHtml();
        var schedSection = schedEl.firstElementChild;
        insertAfterSched.after(schedSection);

        // Products grid
        renderMiniLojaProdutos();

        // Update cart bar
        atualizarCartBar();
    }

    function renderMiniLojaProdutos() {
        var prods = mlData.produtos;
        var body = document.getElementById('mlBody');

        if (prods.itens.length === 0) {
            body.innerHTML = '<div class="vitrine-empty" style="padding:40px 20px"><div class="vitrine-empty-icon">&#128230;</div><h3>Nenhum produto</h3><p>Nenhum produto encontrado nesta categoria.</p></div>';
            return;
        }

        var html = '<div class="ml-products-grid">';
        prods.itens.forEach(function(prod) {
            var imgHtml = prod.imagem
                ? '<img src="' + escapeHtml(prod.imagem) + '" alt="" onerror="this.parentNode.innerHTML=\'&#128230;\'">'
                : '&#128230;';

            var priceHtml;
            if (prod.preco_promo) {
                priceHtml = '<div class="ml-product-price-old">R$ ' + prod.preco.toFixed(2).replace('.', ',') + '</div>' +
                    '<div class="ml-product-price-promo">R$ ' + prod.preco_promo.toFixed(2).replace('.', ',') + '</div>';
            } else {
                priceHtml = '<div class="ml-product-price">R$ ' + prod.preco.toFixed(2).replace('.', ',') + '</div>';
            }

            var qty = cartItems[prod.id] || 0;
            var effectivePrice = prod.preco_promo || prod.preco;

            var actionsHtml;
            if (qty > 0) {
                actionsHtml = '<div class="ml-qty-control">' +
                    '<button class="ml-qty-btn" onclick="event.stopPropagation();mlAlterarQtd(' + prod.id + ',' + mlPartnerId + ',' + effectivePrice + ',-1)">-</button>' +
                    '<span class="ml-qty-val" id="mlQty_' + prod.id + '">' + qty + '</span>' +
                    '<button class="ml-qty-btn" onclick="event.stopPropagation();mlAlterarQtd(' + prod.id + ',' + mlPartnerId + ',' + effectivePrice + ',1)">+</button>' +
                '</div>';
            } else {
                actionsHtml = '<button class="ml-add-btn" onclick="event.stopPropagation();adicionarAoCarrinhoMl(' + prod.id + ',' + mlPartnerId + ',' + effectivePrice + ')" title="Adicionar">+</button>';
            }

            var prodJson = JSON.stringify(prod).replace(/'/g, "\\'").replace(/"/g, '&quot;');
            html += '<div class="ml-product-card" onclick="abrirProdutoDetalhe(JSON.parse(this.getAttribute(\'data-prod\')))" data-prod="' + prodJson + '">' +
                '<div class="ml-product-img">' + imgHtml + '</div>' +
                '<div class="ml-product-body">' +
                    '<div class="ml-product-name">' + escapeHtml(prod.nome) + '</div>' +
                    priceHtml +
                    '<div class="ml-product-actions">' +
                        '<div class="ml-product-unit">' + escapeHtml(prod.unidade) + '</div>' +
                        actionsHtml +
                    '</div>' +
                '</div>' +
            '</div>';
        });
        html += '</div>';

        // Pagination
        var totalPages = Math.ceil(prods.total / prods.por_pagina);
        if (totalPages > 1) {
            html += '<div class="ml-pagination">';
            html += '<button class="ml-page-btn" onclick="mlMudarPagina(' + (mlPage - 1) + ')"' + (mlPage <= 1 ? ' disabled' : '') + '>&laquo;</button>';
            for (var pg = 1; pg <= totalPages; pg++) {
                if (pg <= 3 || pg >= totalPages - 1 || Math.abs(pg - mlPage) <= 1) {
                    html += '<button class="ml-page-btn' + (pg === mlPage ? ' active' : '') + '" onclick="mlMudarPagina(' + pg + ')">' + pg + '</button>';
                } else if (pg === 4 && mlPage > 5) {
                    html += '<span style="padding:8px 4px;color:#9ca3af">...</span>';
                }
            }
            html += '<button class="ml-page-btn" onclick="mlMudarPagina(' + (mlPage + 1) + ')"' + (mlPage >= totalPages ? ' disabled' : '') + '>&raquo;</button>';
            html += '</div>';
        }

        body.innerHTML = html;
    }

    window.mlFiltrarCategoria = function(catId) {
        mlCategoryId = catId;
        mlPage = 1;
        carregarMiniLoja();
    };

    window.mlMudarPagina = function(pg) {
        mlPage = pg;
        carregarMiniLoja();
    };

    // ==================== CART OPERATIONS ====================
    window.adicionarAoCarrinhoMl = function(productId, partnerId, price) {
        // Check if different store
        if (cartPartnerId && cartPartnerId !== partnerId && cartCount > 0) {
            mostrarSwitchDialog(productId, partnerId, price);
            return;
        }

        enviarAddCart(productId, partnerId, 1);
    };

    window.mlAlterarQtd = function(productId, partnerId, price, delta) {
        var currentQty = cartItems[productId] || 0;
        var newQty = currentQty + delta;

        if (newQty <= 0) {
            // Remove from cart
            removerDoCarrinho(productId);
            return;
        }

        enviarAddCart(productId, partnerId, delta);
    };

    function enviarAddCart(productId, partnerId, quantity) {
        fetch('/api/mercado/carrinho/adicionar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                customer_id: customerId,
                session_id: sessionId,
                partner_id: partnerId,
                product_id: productId,
                quantity: quantity
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(json) {
            if (json.success) {
                cartPartnerId = partnerId;
                localStorage.setItem('cart_partner_id', partnerId);

                // Update local cart state from response
                cartItems = {};
                cartTotal = json.data.total || 0;
                cartCount = json.data.itens || 0;
                if (json.data.carrinho) {
                    json.data.carrinho.forEach(function(item) {
                        cartItems[item.product_id] = item.quantity;
                    });
                }

                showToast('Adicionado ao carrinho', 'success');
                atualizarCartBar();
                atualizarFloatingCart();
                if (mlData) renderMiniLojaProdutos();
            } else {
                showToast(json.message || 'Erro ao adicionar', 'error');
            }
        })
        .catch(function() {
            showToast('Erro de conexao', 'error');
        });
    }

    function removerDoCarrinho(productId) {
        // Find item ID from cart
        fetch('/api/mercado/carrinho/listar.php?customer_id=' + customerId + '&session_id=' + sessionId)
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.success && json.data) {
                    var items = json.data.carrinho || json.data;
                    var item = null;
                    for (var i = 0; i < items.length; i++) {
                        if (parseInt(items[i].product_id) === productId) {
                            item = items[i];
                            break;
                        }
                    }
                    if (item) {
                        return fetch('/api/mercado/carrinho/remover.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                id: item.id,
                                customer_id: customerId,
                                session_id: sessionId
                            })
                        });
                    }
                }
            })
            .then(function(r) { return r ? r.json() : null; })
            .then(function() {
                delete cartItems[productId];
                cartCount = Object.keys(cartItems).length;
                cartTotal = 0; // Will be recalculated
                if (cartCount === 0) {
                    cartPartnerId = 0;
                    localStorage.removeItem('cart_partner_id');
                }
                atualizarCartBar();
                if (mlData) renderMiniLojaProdutos();
                showToast('Item removido', 'success');
            })
            .catch(function() {
                showToast('Erro ao remover item', 'error');
            });
    }

    // ==================== SWITCH STORE DIALOG ====================
    var pendingAdd = null;

    function mostrarSwitchDialog(productId, partnerId, price) {
        pendingAdd = { productId: productId, partnerId: partnerId, price: price };
        document.getElementById('switchDialogOverlay').classList.add('open');
    }

    window.fecharSwitchDialog = function() {
        pendingAdd = null;
        document.getElementById('switchDialogOverlay').classList.remove('open');
    };

    document.getElementById('switchBtnClear').addEventListener('click', function() {
        if (!pendingAdd) return;
        var add = pendingAdd;
        fecharSwitchDialog();

        // Clear cart then add
        fetch('/api/mercado/carrinho/limpar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                customer_id: customerId,
                session_id: sessionId
            })
        })
        .then(function(r) { return r.json(); })
        .then(function() {
            cartItems = {};
            cartTotal = 0;
            cartCount = 0;
            cartPartnerId = 0;
            localStorage.removeItem('cart_partner_id');
            enviarAddCart(add.productId, add.partnerId, 1);
        })
        .catch(function() {
            showToast('Erro ao limpar carrinho', 'error');
        });
    });

    // ==================== CART BAR ====================
    function atualizarCartBar() {
        var bar = document.getElementById('mlCartBar');
        if (cartCount > 0 && cartPartnerId === mlPartnerId) {
            bar.classList.add('visible');
            document.getElementById('mlCartCount').textContent = cartCount;
            document.getElementById('mlCartTotal').textContent = 'R$ ' + cartTotal.toFixed(2).replace('.', ',');
        } else {
            bar.classList.remove('visible');
        }
    }

    // Load current cart on init
    function carregarCarrinhoAtual() {
        fetch('/api/mercado/carrinho/listar.php?customer_id=' + customerId + '&session_id=' + sessionId)
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.success && json.data) {
                    var items = json.data.carrinho || json.data;
                    if (Array.isArray(items)) {
                        cartItems = {};
                        cartTotal = 0;
                        items.forEach(function(item) {
                            cartItems[item.product_id] = parseInt(item.quantity);
                            cartTotal += parseFloat(item.price) * parseInt(item.quantity);
                            if (!cartPartnerId) cartPartnerId = parseInt(item.partner_id);
                        });
                        cartCount = items.length;
                        if (cartPartnerId) localStorage.setItem('cart_partner_id', cartPartnerId);
                    }
                }
            })
            .catch(function() {});
    }

    // ==================== TOAST ====================
    window.showToast = function(msg, type) {
        var t = document.getElementById('toast');
        t.textContent = msg;
        t.className = 'om-toast ' + (type || 'success') + ' show';
        setTimeout(function() { t.className = 'om-toast'; }, 3000);
    };

    // ==================== FAVORITES ====================
    window.toggleFavorite = function(storeId, evt) {
        if (evt) { evt.stopPropagation(); evt.preventDefault(); }
        var idx = favoriteStores.indexOf(storeId);
        if (idx >= 0) {
            favoriteStores.splice(idx, 1);
        } else {
            favoriteStores.push(storeId);
        }
        localStorage.setItem('superbora_fav_stores', JSON.stringify(favoriteStores));
        renderCards();
    };

    // ==================== SORT & FILTER ====================
    window.aplicarOrdenacao = function() {
        sortBy = document.getElementById('sortSelect').value;
        renderCards();
    };

    window.toggleFilter = function(filter) {
        activeFilters[filter] = !activeFilters[filter];
        var btnMap = { aberto: 'filterOpen', gratis: 'filterFree', favoritos: 'filterFav' };
        var btn = document.getElementById(btnMap[filter]);
        if (btn) btn.classList.toggle('active', activeFilters[filter]);
        renderCards();
    };

    function sortEstabelecimentos(arr) {
        if (!sortBy) return arr;
        return arr.slice().sort(function(a, b) {
            switch (sortBy) {
                case 'rating': return (b.avaliacao || 0) - (a.avaliacao || 0);
                case 'delivery_fee': return (a.taxa_entrega || 0) - (b.taxa_entrega || 0);
                case 'delivery_time': return (a.tempo_estimado || 999) - (b.tempo_estimado || 999);
                case 'distance': return (a.distancia || 999) - (b.distancia || 999);
                case 'name': return (a.nome || '').localeCompare(b.nome || '');
                default: return 0;
            }
        });
    }

    function filterEstabelecimentos(arr) {
        return arr.filter(function(e) {
            if (activeFilters.aberto && !e.aberto) return false;
            if (activeFilters.gratis && (e.taxa_entrega || 0) > 0) return false;
            if (activeFilters.favoritos && favoriteStores.indexOf(e.id) < 0) return false;
            return true;
        });
    }

    // ==================== PRODUCT DETAIL MODAL ====================
    var pdProduct = null;
    var pdQuantity = 1;
    var pdSelectedOptions = {};

    window.abrirProdutoDetalhe = function(prod) {
        pdProduct = prod;
        pdQuantity = 1;
        pdSelectedOptions = {};
        renderProdutoDetalhe();
        document.getElementById('pdOverlay').classList.add('open');
    };

    window.fecharProdutoDetalhe = function() {
        document.getElementById('pdOverlay').classList.remove('open');
    };

    function renderProdutoDetalhe() {
        var p = pdProduct;
        var imgHtml = p.imagem
            ? '<img src="' + escapeHtml(p.imagem) + '" alt="" onerror="this.parentNode.innerHTML=\'&#128230;\'">'
            : '&#128230;';

        var priceHtml;
        if (p.preco_promo) {
            priceHtml = '<span class="pd-price-old">R$ ' + p.preco.toFixed(2).replace('.', ',') + '</span>' +
                '<span class="pd-price pd-price-promo">R$ ' + p.preco_promo.toFixed(2).replace('.', ',') + '</span>';
        } else {
            priceHtml = '<span class="pd-price">R$ ' + p.preco.toFixed(2).replace('.', ',') + '</span>';
        }

        var optionsHtml = '';
        if (p.option_groups && p.option_groups.length > 0) {
            p.option_groups.forEach(function(g) {
                var reqLabel = g.required ? '<span class="pd-required">Obrigatorio</span>' : '';
                optionsHtml += '<div class="pd-section-title">' + escapeHtml(g.name) + reqLabel + '</div>';
                g.options.forEach(function(o) {
                    var extraText = o.price_extra > 0 ? '+ R$ ' + o.price_extra.toFixed(2).replace('.', ',') : '';
                    var isChecked = pdSelectedOptions[g.id] && pdSelectedOptions[g.id].indexOf(o.id) >= 0;
                    optionsHtml += '<div class="pd-option-row" onclick="togglePdOption(' + g.id + ',' + o.id + ',' + g.max_select + ')">' +
                        '<div class="pd-option-label">' +
                            '<div class="pd-option-check' + (isChecked ? ' checked' : '') + '">' + (isChecked ? '&#10003;' : '') + '</div>' +
                            escapeHtml(o.name) +
                        '</div>' +
                        '<div class="pd-option-extra">' + extraText + '</div>' +
                    '</div>';
                });
            });
        }

        var effectivePrice = p.preco_promo || p.preco;
        var totalExtras = 0;
        Object.keys(pdSelectedOptions).forEach(function(gid) {
            pdSelectedOptions[gid].forEach(function(oid) {
                p.option_groups.forEach(function(g) {
                    if (g.id == gid) {
                        g.options.forEach(function(o) {
                            if (o.id == oid) totalExtras += o.price_extra;
                        });
                    }
                });
            });
        });
        var totalPrice = (effectivePrice + totalExtras) * pdQuantity;

        var html = '<div class="pd-img">' + imgHtml +
            '<button class="pd-close" onclick="fecharProdutoDetalhe()">&times;</button></div>' +
            '<div class="pd-body">' +
                '<div class="pd-name">' + escapeHtml(p.nome) + '</div>' +
                (p.descricao ? '<div class="pd-desc">' + escapeHtml(p.descricao) + '</div>' : '') +
                '<div class="pd-prices">' + priceHtml + '<span class="pd-unit">/ ' + escapeHtml(p.unidade) + '</span></div>' +
                optionsHtml +
                '<div class="pd-notes"><div class="pd-section-title">Observacoes</div>' +
                    '<textarea id="pdNotes" placeholder="Ex: sem cebola, bem passado..."></textarea>' +
                '</div>' +
            '</div>' +
            '<div class="pd-footer">' +
                '<div class="pd-qty-control">' +
                    '<button class="pd-qty-btn" onclick="pdChangeQty(-1)">-</button>' +
                    '<span class="pd-qty-val" id="pdQtyVal">' + pdQuantity + '</span>' +
                    '<button class="pd-qty-btn" onclick="pdChangeQty(1)">+</button>' +
                '</div>' +
                '<button class="pd-add-btn" onclick="pdAddToCart()">Adicionar R$ ' + totalPrice.toFixed(2).replace('.', ',') + '</button>' +
            '</div>';

        document.getElementById('pdPanel').innerHTML = html;
    }

    window.togglePdOption = function(groupId, optionId, maxSelect) {
        if (!pdSelectedOptions[groupId]) pdSelectedOptions[groupId] = [];
        var idx = pdSelectedOptions[groupId].indexOf(optionId);
        if (idx >= 0) {
            pdSelectedOptions[groupId].splice(idx, 1);
        } else {
            if (maxSelect === 1) {
                pdSelectedOptions[groupId] = [optionId];
            } else if (pdSelectedOptions[groupId].length < maxSelect) {
                pdSelectedOptions[groupId].push(optionId);
            }
        }
        renderProdutoDetalhe();
    };

    window.pdChangeQty = function(delta) {
        pdQuantity = Math.max(1, Math.min(99, pdQuantity + delta));
        renderProdutoDetalhe();
    };

    window.pdAddToCart = function() {
        if (!pdProduct) return;
        var notes = document.getElementById('pdNotes') ? document.getElementById('pdNotes').value : '';
        fecharProdutoDetalhe();
        // Check store conflict
        if (cartPartnerId && cartPartnerId !== mlPartnerId && cartCount > 0) {
            mostrarSwitchDialog(pdProduct.id, mlPartnerId, pdProduct.preco_promo || pdProduct.preco);
            return;
        }
        enviarAddCart(pdProduct.id, mlPartnerId, pdQuantity);
    };

    // ==================== BANNERS & COUPONS ====================
    function carregarBanners() {
        fetch('/api/mercado/parceiros/banners.php')
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.success && json.data) {
                    var b = json.data.banners || [];
                    var c = json.data.cupons || [];
                    if (b.length === 0 && c.length === 0) return;

                    var html = '';
                    b.forEach(function(banner) {
                        html += '<div class="banner-card" style="background:' + escapeHtml(banner.bg_color) + ';color:' + escapeHtml(banner.text_color) + '"' +
                            (banner.partner_id ? ' onclick="abrirMiniLoja(' + banner.partner_id + ')"' : (banner.link ? ' onclick="window.location.href=\'' + escapeHtml(banner.link) + '\'"' : '')) + '>' +
                            '<div class="banner-card__title">' + escapeHtml(banner.titulo) + '</div>' +
                            '<div class="banner-card__sub">' + escapeHtml(banner.subtitulo || '') + '</div>' +
                            (banner.icone ? '<div class="banner-card__icon">' + banner.icone + '</div>' : '') +
                        '</div>';
                    });
                    c.forEach(function(cupom) {
                        var desconto = cupom.tipo === 'percentage' ? cupom.valor + '%' : 'R$ ' + cupom.valor.toFixed(2).replace('.', ',');
                        html += '<div class="coupon-card" onclick="copiarCupom(\'' + escapeHtml(cupom.codigo) + '\')">' +
                            '<div class="coupon-card__code">' + escapeHtml(cupom.codigo) + '</div>' +
                            '<div class="coupon-card__desc">' + desconto + ' OFF' + (cupom.pedido_minimo > 0 ? ' (min R$ ' + cupom.pedido_minimo.toFixed(0) + ')' : '') + '</div>' +
                            (cupom.validade ? '<div class="coupon-card__val">Ate ' + new Date(cupom.validade).toLocaleDateString('pt-BR') + '</div>' : '') +
                        '</div>';
                    });

                    document.getElementById('bannersScroll').innerHTML = html;
                    document.getElementById('bannersSection').style.display = 'block';
                }
            })
            .catch(function() {});
    }

    window.copiarCupom = function(code) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(code);
        }
        showToast('Cupom ' + code + ' copiado!', 'success');
    };

    // ==================== REORDER / HISTORY ====================
    function carregarHistorico() {
        if (!customerId) return;
        fetch('/api/mercado/pedidos/historico.php?customer_id=' + customerId + '&limit=5')
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.success && json.data && json.data.pedidos && json.data.pedidos.length > 0) {
                    renderHistorico(json.data.pedidos);
                }
            })
            .catch(function() {});
    }

    function renderHistorico(pedidos) {
        var html = '';
        pedidos.forEach(function(p) {
            var logoHtml = p.parceiro_logo
                ? '<img src="' + escapeHtml(p.parceiro_logo) + '" alt="">'
                : '&#128722;';
            var itensStr = (p.itens || []).slice(0, 3).map(function(i) { return i.nome; }).join(', ');
            if (p.itens && p.itens.length > 3) itensStr += '...';
            var dataStr = new Date(p.data).toLocaleDateString('pt-BR');

            html += '<div class="reorder-card">' +
                '<div class="reorder-card__header">' +
                    '<div class="reorder-card__logo">' + logoHtml + '</div>' +
                    '<div>' +
                        '<div class="reorder-card__store">' + escapeHtml(p.parceiro_nome) + '</div>' +
                        '<div class="reorder-card__date">' + dataStr + '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="reorder-card__items">' + escapeHtml(itensStr) + '</div>' +
                '<div class="reorder-card__total">R$ ' + p.total.toFixed(2).replace('.', ',') + '</div>' +
                '<button class="reorder-btn" onclick="reordenar(' + p.partner_id + ',' + JSON.stringify(p.itens || []).replace(/"/g, '&quot;') + ')">Pedir novamente</button>' +
            '</div>';
        });
        document.getElementById('reorderScroll').innerHTML = html;
        document.getElementById('reorderSection').style.display = 'block';
    }

    window.reordenar = function(partnerId, itens) {
        if (!itens || itens.length === 0) {
            abrirMiniLoja(partnerId);
            return;
        }
        // Check store conflict
        if (cartPartnerId && cartPartnerId !== partnerId && cartCount > 0) {
            showToast('Limpe o carrinho atual antes de pedir novamente', 'error');
            return;
        }
        // Add items one by one
        var addNext = function(idx) {
            if (idx >= itens.length) {
                showToast('Itens adicionados ao carrinho!', 'success');
                atualizarFloatingCart();
                return;
            }
            var item = itens[idx];
            fetch('/api/mercado/carrinho/adicionar.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    customer_id: customerId,
                    session_id: sessionId,
                    partner_id: partnerId,
                    product_id: item.product_id,
                    quantity: item.quantidade || 1
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.success && json.data) {
                    cartPartnerId = partnerId;
                    localStorage.setItem('cart_partner_id', partnerId);
                    cartTotal = json.data.total || 0;
                    cartCount = json.data.itens || 0;
                    if (json.data.carrinho) {
                        cartItems = {};
                        json.data.carrinho.forEach(function(ci) { cartItems[ci.product_id] = ci.quantity; });
                    }
                }
                addNext(idx + 1);
            })
            .catch(function() { addNext(idx + 1); });
        };
        addNext(0);
    };

    // ==================== ADDRESS MANAGER ====================
    var savedAddresses = JSON.parse(localStorage.getItem('superbora_addresses') || '[]');

    window.abrirEnderecos = function() {
        renderEnderecos();
        document.getElementById('addrOverlay').classList.add('open');
    };

    window.fecharEnderecos = function() {
        document.getElementById('addrOverlay').classList.remove('open');
    };

    function renderEnderecos() {
        var html = '';
        var icons = { 'Casa': '&#127968;', 'Trabalho': '&#127970;', 'Outro': '&#128205;' };
        savedAddresses.forEach(function(a, i) {
            var isActive = a.cep && a.cep.replace(/\D/g, '') === cepAtual;
            html += '<div class="addr-item' + (isActive ? ' active' : '') + '" onclick="usarEndereco(' + i + ')">' +
                '<div class="addr-item__icon">' + (icons[a.label] || '&#128205;') + '</div>' +
                '<div class="addr-item__info">' +
                    '<div class="addr-item__label">' + escapeHtml(a.label) + '</div>' +
                    '<div class="addr-item__addr">' + escapeHtml(a.address) + (a.city ? ', ' + escapeHtml(a.city) : '') + '</div>' +
                '</div>' +
                '<button class="addr-item__del" onclick="event.stopPropagation();removerEndereco(' + i + ')">&times;</button>' +
            '</div>';
        });
        document.getElementById('addrList').innerHTML = html;
    }

    window.usarEndereco = function(idx) {
        var a = savedAddresses[idx];
        if (a && a.cep) {
            var raw = a.cep.replace(/\D/g, '');
            cepInput.value = raw.length === 8 ? raw.substring(0, 5) + '-' + raw.substring(5) : raw;
            fecharEnderecos();
            buscarPorCep();
        }
    };

    window.removerEndereco = function(idx) {
        savedAddresses.splice(idx, 1);
        localStorage.setItem('superbora_addresses', JSON.stringify(savedAddresses));
        renderEnderecos();
    };

    window.mostrarFormEndereco = function() {
        document.getElementById('addrForm').classList.toggle('show');
    };

    window.salvarEndereco = function() {
        var label = document.getElementById('addrLabel').value;
        var cep = document.getElementById('addrCep').value;
        var rua = document.getElementById('addrRua').value;
        var cidade = document.getElementById('addrCidade').value;
        var estado = document.getElementById('addrEstado').value;
        if (!rua) { showToast('Preencha o endereco', 'error'); return; }
        savedAddresses.push({ label: label, cep: cep, address: rua, city: cidade, state: estado });
        localStorage.setItem('superbora_addresses', JSON.stringify(savedAddresses));
        document.getElementById('addrForm').classList.remove('show');
        document.getElementById('addrCep').value = '';
        document.getElementById('addrRua').value = '';
        document.getElementById('addrCidade').value = '';
        document.getElementById('addrEstado').value = '';
        renderEnderecos();
        showToast('Endereco salvo', 'success');
    };

    // ==================== RATING / REVIEW ====================
    var ratingData = { orderId: 0, partnerId: 0, value: 0 };

    window.abrirAvaliacao = function(orderId, partnerId, storeName) {
        ratingData = { orderId: orderId, partnerId: partnerId, value: 0 };
        document.getElementById('ratingStoreName').textContent = storeName;
        document.getElementById('ratingComment').value = '';
        document.querySelectorAll('.rating-star').forEach(function(s) { s.classList.remove('active'); });
        document.getElementById('ratingOverlay').classList.add('open');
    };

    window.fecharAvaliacao = function() {
        document.getElementById('ratingOverlay').classList.remove('open');
    };

    window.setRating = function(val) {
        ratingData.value = val;
        document.querySelectorAll('.rating-star').forEach(function(s) {
            s.classList.toggle('active', parseInt(s.getAttribute('data-val')) <= val);
        });
    };

    window.enviarAvaliacao = function() {
        if (ratingData.value < 1) { showToast('Selecione uma nota', 'error'); return; }
        fetch('/api/mercado/avaliacoes/salvar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                order_id: ratingData.orderId,
                partner_id: ratingData.partnerId,
                customer_id: customerId,
                rating: ratingData.value,
                comment: document.getElementById('ratingComment').value
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(json) {
            fecharAvaliacao();
            showToast(json.success ? 'Avaliacao enviada!' : (json.message || 'Erro'), json.success ? 'success' : 'error');
        })
        .catch(function() { showToast('Erro de conexao', 'error'); });
    };

    // ==================== ORDER TRACKING ====================
    var activeOrders = [];

    function verificarPedidosAtivos() {
        if (!customerId) return;
        fetch('/api/mercado/pedidos/status.php?customer_id=' + customerId)
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.success && json.data && json.data.pedidos && json.data.pedidos.length > 0) {
                    activeOrders = json.data.pedidos;
                    var o = activeOrders[0];
                    document.getElementById('trackingBarStatus').textContent = o.status_label;
                    document.getElementById('trackingBarDetail').textContent = o.parceiro_nome + (o.eta_minutos ? ' - ~' + o.eta_minutos + ' min' : '');
                    document.getElementById('trackingBar').classList.add('visible');
                } else {
                    document.getElementById('trackingBar').classList.remove('visible');
                }
            })
            .catch(function() {});
    }

    window.abrirTracking = function() {
        if (activeOrders.length === 0) return;
        var o = activeOrders[0];
        var steps = [
            { label: 'Pedido recebido', icon: '&#128230;' },
            { label: 'Confirmado', icon: '&#9989;' },
            { label: 'Em preparo', icon: '&#128722;' },
            { label: 'Pronto', icon: '&#127873;' },
            { label: 'Saiu para entrega', icon: '&#128666;' }
        ];

        var html = '<h3>Acompanhar pedido #' + o.order_id + '</h3>' +
            '<p style="font-size:14px;color:#6b7280;margin-bottom:16px">' + escapeHtml(o.parceiro_nome) +
            (o.eta_minutos ? ' - Previsao: ~' + o.eta_minutos + ' min' : '') + '</p>' +
            '<div class="tracking-steps">';

        steps.forEach(function(s, i) {
            var stepNum = i + 1;
            var cls = stepNum < o.step ? 'done' : (stepNum === o.step ? 'active' : '');
            html += '<div class="tracking-step ' + cls + '">' +
                '<div class="tracking-dot">' + s.icon + '</div>' +
                '<div>' +
                    '<div class="tracking-step-label">' + s.label + '</div>' +
                '</div>' +
            '</div>';
        });

        html += '</div>';
        if (o.progresso_itens !== null && o.total_itens) {
            html += '<div style="margin-top:16px;padding-top:12px;border-top:1px solid #e5e7eb">' +
                '<div style="font-size:13px;color:#6b7280;margin-bottom:6px">Progresso: ' + (o.progresso_itens || 0) + '%</div>' +
                '<div style="height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden">' +
                    '<div style="height:100%;width:' + (o.progresso_itens || 0) + '%;background:#10b981;border-radius:3px;transition:width 0.3s"></div>' +
                '</div></div>';
        }
        html += '<button style="width:100%;margin-top:16px;padding:12px;background:#f3f4f6;border:none;border-radius:12px;font-size:14px;font-weight:600;font-family:inherit;cursor:pointer" onclick="fecharTracking()">Fechar</button>';

        document.getElementById('trackingModal').innerHTML = html;
        document.getElementById('trackingOverlay').classList.add('open');
    };

    window.fecharTracking = function() {
        document.getElementById('trackingOverlay').classList.remove('open');
    };

    // ==================== FLOATING CART ====================
    function atualizarFloatingCart() {
        var fc = document.getElementById('floatingCart');
        if (cartCount > 0) {
            fc.classList.add('visible');
            document.getElementById('floatingCartBadge').textContent = cartCount;
            document.getElementById('floatingCartTotal').textContent = 'R$ ' + cartTotal.toFixed(2).replace('.', ',');
        } else {
            fc.classList.remove('visible');
        }
    }

    // ==================== SCHEDULE DELIVERY ====================
    window.getScheduleHtml = function() {
        var today = new Date();
        var options = '<option value="">Entrega imediata</option>';
        for (var d = 0; d < 7; d++) {
            var dt = new Date(today);
            dt.setDate(dt.getDate() + d);
            var label = d === 0 ? 'Hoje' : (d === 1 ? 'Amanha' : dt.toLocaleDateString('pt-BR', { weekday: 'short', day: 'numeric', month: 'short' }));
            options += '<option value="' + dt.toISOString().split('T')[0] + '">' + label + '</option>';
        }
        var timeOptions = '<option value="">Qualquer horario</option>';
        for (var h = 8; h <= 21; h++) {
            timeOptions += '<option value="' + String(h).padStart(2, '0') + ':00">' + String(h).padStart(2, '0') + ':00 - ' + String(h + 1).padStart(2, '0') + ':00</option>';
        }
        return '<div class="schedule-section"><div class="schedule-row">' +
            '<span class="schedule-label">&#128197; Agendar:</span>' +
            '<select class="schedule-select" id="scheduleDate">' + options + '</select>' +
            '<select class="schedule-select" id="scheduleTime">' + timeOptions + '</select>' +
        '</div></div>';
    };

    // ==================== UTILS ====================
    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // ==================== A1: COUPON STATE ====================
    var appliedCoupon = null;

    // ==================== A2: AUTOCOMPLETE ====================
    var acTimeout;
    var recentSearches = JSON.parse(localStorage.getItem('superbora_recent_searches') || '[]');

    searchInput.addEventListener('input', function() {
        clearTimeout(acTimeout);
        var q = searchInput.value.trim();
        if (q.length < 2) {
            closeAcDropdown();
            return;
        }
        acTimeout = setTimeout(function() {
            fetchSugestoes(q);
        }, 250);
    });

    searchInput.addEventListener('focus', function() {
        var q = searchInput.value.trim();
        if (q.length >= 2) {
            fetchSugestoes(q);
        } else if (recentSearches.length > 0) {
            showRecentSearches();
        }
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.sticky-search') && !e.target.closest('.vitrine-search-box')) closeAcDropdown();
    });

    function fetchSugestoes(q) {
        fetch('/api/mercado/produtos/sugestoes.php?q=' + encodeURIComponent(q) + '&limit=8')
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.success && json.data) {
                    renderAcDropdown(json.data, q);
                }
            })
            .catch(function() {});
    }

    function renderAcDropdown(data, q) {
        var dd = document.getElementById('acDropdown');
        var html = '';

        if (data.lojas && data.lojas.length > 0) {
            html += '<div class="ac-section"><div class="ac-section-title">Lojas</div>';
            data.lojas.forEach(function(l) {
                var logoHtml = l.logo ? '<img src="' + escapeHtml(l.logo) + '" alt="">' : '&#128722;';
                html += '<div class="ac-item" onclick="abrirMiniLoja(' + l.id + ');closeAcDropdown();saveRecentSearch(\'' + escapeHtml(q) + '\')">' +
                    '<div class="ac-item-img">' + logoHtml + '</div>' +
                    '<div class="ac-item-info"><div class="ac-item-name">' + escapeHtml(l.nome) + '</div>' +
                    '<div class="ac-item-meta">' + escapeHtml(l.categoria) + (l.aberto ? ' - Aberto' : ' - Fechado') + '</div></div>' +
                    '<div class="ac-item-meta">&#11088; ' + l.avaliacao.toFixed(1) + '</div></div>';
            });
            html += '</div>';
        }

        if (data.produtos && data.produtos.length > 0) {
            html += '<div class="ac-section"><div class="ac-section-title">Produtos</div>';
            data.produtos.forEach(function(p) {
                var imgHtml = p.imagem ? '<img src="' + escapeHtml(p.imagem) + '" alt="">' : '&#128230;';
                var priceStr = p.preco_promo
                    ? 'R$ ' + p.preco_promo.toFixed(2).replace('.', ',')
                    : 'R$ ' + p.preco.toFixed(2).replace('.', ',');
                html += '<div class="ac-item" onclick="abrirMiniLoja(' + p.parceiro_id + ');closeAcDropdown();saveRecentSearch(\'' + escapeHtml(q) + '\')">' +
                    '<div class="ac-item-img">' + imgHtml + '</div>' +
                    '<div class="ac-item-info"><div class="ac-item-name">' + escapeHtml(p.nome) + '</div>' +
                    '<div class="ac-item-meta">' + escapeHtml(p.parceiro_nome) + '</div></div>' +
                    '<div class="ac-item-price">' + priceStr + '</div></div>';
            });
            html += '</div>';
        }

        if (!html && data.populares && data.populares.length > 0) {
            html = '<div class="ac-recent"><div class="ac-recent-title">Buscas populares</div>';
            data.populares.forEach(function(t) {
                html += '<span class="ac-recent-item" onclick="searchInput.value=\'' + escapeHtml(t) + '\';buscaAtual=\'' + escapeHtml(t) + '\';closeAcDropdown();renderCards()">' + escapeHtml(t) + '</span>';
            });
            html += '</div>';
        }

        if (html) {
            dd.innerHTML = html;
            dd.classList.add('open');
        } else {
            closeAcDropdown();
        }
    }

    function showRecentSearches() {
        if (recentSearches.length === 0) return;
        var dd = document.getElementById('acDropdown');
        var html = '<div class="ac-recent"><div class="ac-recent-title">Buscas recentes</div>';
        recentSearches.slice(0, 5).forEach(function(t) {
            html += '<span class="ac-recent-item" onclick="searchInput.value=\'' + escapeHtml(t) + '\';buscaAtual=\'' + escapeHtml(t) + '\';closeAcDropdown();renderCards()">' + escapeHtml(t) + '</span>';
        });
        html += '</div>';
        dd.innerHTML = html;
        dd.classList.add('open');
    }

    window.closeAcDropdown = function() {
        document.getElementById('acDropdown').classList.remove('open');
    };

    window.saveRecentSearch = function(q) {
        if (!q || q.length < 2) return;
        var idx = recentSearches.indexOf(q);
        if (idx >= 0) recentSearches.splice(idx, 1);
        recentSearches.unshift(q);
        if (recentSearches.length > 10) recentSearches = recentSearches.slice(0, 10);
        localStorage.setItem('superbora_recent_searches', JSON.stringify(recentSearches));
    };

    // ==================== A3: SUB-CATEGORY FILTER ====================
    var subCategoriaAtual = '';

    window.filtrarSubCategoria = function(sub) {
        subCategoriaAtual = sub;
        document.querySelectorAll('.cat-pill').forEach(function(p) {
            p.classList.toggle('active', p.getAttribute('data-subcat') === sub);
        });
        renderCards();
    };

    // ==================== A4: DELIVERY/PICKUP TOGGLE ====================
    var deliveryMode = 'delivery';

    window.setDeliveryMode = function(mode) {
        deliveryMode = mode;
        document.querySelectorAll('.delivery-toggle-btn').forEach(function(b) {
            b.classList.toggle('active', b.getAttribute('data-mode') === mode);
        });
        renderCards();
    };

    // ==================== PATCH renderCards for A3, A4, C7 ====================
    var _originalRenderCards = renderCards;
    renderCards = function() {
        if (searchMode === 'produtos' && buscaAtual.length >= 2) return;

        var filtered = todosEstabelecimentos.filter(function(e) {
            if (categoriaAtual && !matchesMainCat(e.categoria, categoriaAtual)) return false;
            if (subCategoriaAtual && e.categoria !== subCategoriaAtual) return false;
            if (buscaAtual && e.nome.toLowerCase().indexOf(buscaAtual) === -1) return false;
            return true;
        });
        filtered = filterEstabelecimentos(filtered);
        filtered = sortEstabelecimentos(filtered);

        resultsText.innerHTML = '<span class="vitrine-results-count">' + filtered.length + '</span> estabelecimento' + (filtered.length !== 1 ? 's' : '') + ' encontrado' + (filtered.length !== 1 ? 's' : '');

        if (filtered.length === 0) {
            grid.innerHTML = '<div class="vitrine-empty"><div class="vitrine-empty-icon">&#128269;</div><h3>Nenhum estabelecimento encontrado</h3><p>Tente buscar com outro termo ou selecione uma categoria diferente.</p></div>';
            return;
        }

        // Render highlight sections
        renderHighlightSections(filtered);

        var html = '';
        filtered.forEach(function(e) {
            html += buildStoreCard(e);
        });

        grid.innerHTML = html;
    };

    function buildStoreCard(e) {
        var cat = e.categoria || 'mercado';
        var catLabel = catLabels[cat] || cat;
        var catIcon = catIcons[cat] || '\uD83C\uDFEA';
        var badgeClass = 'badge-' + (catToMainCat[cat] || cat);
        var statusClass = e.aberto ? 'status-aberto' : 'status-fechado';
        var statusLabel = e.aberto ? 'Aberto' : 'Fechado';

        var logoHtml;
        if (e.logo) {
            logoHtml = '<img src="' + escapeHtml(e.logo) + '" alt="' + escapeHtml(e.nome) + '" onerror="this.parentNode.innerHTML=\'' + catIcon + '\'">';
        } else {
            logoHtml = catIcon;
        }

        var isFav = favoriteStores.indexOf(e.id) >= 0;

        // Delivery vs pickup info
        var taxaHtml, tempoHtml;
        if (deliveryMode === 'pickup') {
            taxaHtml = '<span class="store-card__meta-delivery">Gr\u00e1tis (retirada)</span>';
            tempoHtml = '';
        } else {
            var taxa = e.taxa_entrega || 0;
            taxaHtml = taxa > 0
                ? '\uD83D\uDE9B R$ ' + taxa.toFixed(2).replace('.', ',')
                : '<span class="store-card__meta-delivery">\uD83D\uDE9B Gr\u00e1tis</span>';
            var baseTime = e.tempo_estimado || 60;
            var minTime = Math.max(10, baseTime - 10);
            var maxTime = baseTime + 10;
            tempoHtml = '\u23F0 ' + minTime + '-' + maxTime + ' min';
        }

        // Min order
        var minOrderHtml = '';
        if (e.pedido_min && e.pedido_min > 0) {
            minOrderHtml = '<span class="store-card__meta-sep">\u2022</span>' +
                '<div class="store-card__meta-item">Ped. min R$ ' + e.pedido_min.toFixed(2).replace('.', ',') + '</div>';
        }

        return '<div class="store-card" onclick="abrirMiniLoja(' + e.id + ')">' +
            '<div class="store-card__banner">' +
                '<div class="store-card__banner-bg ' + bannerBgClass(cat) + '">' + catIcon + '</div>' +
                '<span class="store-card__status ' + statusClass + '">' + statusLabel + '</span>' +
                '<span class="store-card__badge ' + badgeClass + '">' + catLabel + '</span>' +
                '<button class="store-card__fav' + (isFav ? ' faved' : '') + '" onclick="toggleFavorite(' + e.id + ',event)">' + (isFav ? '&#10084;' : '&#9825;') + '</button>' +
            '</div>' +
            '<div class="store-card__body">' +
                '<div class="store-card__header">' +
                    '<div class="store-card__logo">' + logoHtml + '</div>' +
                    '<div>' +
                        '<div class="store-card__name">' + escapeHtml(e.nome) + '</div>' +
                        '<div class="store-card__cat">' + catLabel + '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="store-card__meta">' +
                    '<div class="store-card__meta-item"><span class="store-card__rating">\u2B50 ' + (e.avaliacao || 5).toFixed(1) + '</span></div>' +
                    '<span class="store-card__meta-sep">\u2022</span>' +
                    '<div class="store-card__meta-item">' + taxaHtml + '</div>' +
                    (tempoHtml ? '<span class="store-card__meta-sep">\u2022</span><div class="store-card__meta-item">' + tempoHtml + '</div>' : '') +
                    minOrderHtml +
                '</div>' +
            '</div>' +
        '</div>';
    }

    // Render highlight horizontal sections
    function renderHighlightSections(allStores) {
        // "Restaurantes em destaque" - top-rated restaurants
        var restSection = document.getElementById('highlightRestSection');
        var restScroll = document.getElementById('highlightRestScroll');
        var restaurants = allStores.filter(function(e) { return e.categoria === 'restaurante'; });
        restaurants.sort(function(a, b) { return (b.avaliacao || 0) - (a.avaliacao || 0); });

        if (restaurants.length > 0 && !categoriaAtual) {
            restSection.style.display = '';
            var restHtml = '';
            restaurants.slice(0, 8).forEach(function(e) {
                var cat = e.categoria || 'mercado';
                var catIcon = catIcons[cat] || '\uD83C\uDFEA';
                var logoHtml = e.logo
                    ? '<img src="' + escapeHtml(e.logo) + '" alt="" onerror="this.parentNode.innerHTML=\'' + catIcon + '\'">'
                    : catIcon;
                var taxa = e.taxa_entrega || 0;
                var taxaStr = taxa > 0 ? 'R$ ' + taxa.toFixed(2).replace('.', ',') : 'Entrega gr\u00e1tis';
                restHtml += '<div class="highlight-card" onclick="abrirMiniLoja(' + e.id + ')">' +
                    '<div class="highlight-card__banner ' + bannerBgClass(cat) + '">' + catIcon + '</div>' +
                    '<div class="highlight-card__body">' +
                        '<div class="highlight-card__name">' + escapeHtml(e.nome) + '</div>' +
                        '<div class="highlight-card__meta">\u2B50 ' + (e.avaliacao || 5).toFixed(1) + ' \u2022 ' + taxaStr + '</div>' +
                    '</div>' +
                '</div>';
            });
            restScroll.innerHTML = restHtml;
        } else {
            restSection.style.display = 'none';
        }

        // "Novidades perto de voce" - non-restaurant stores
        var newSection = document.getElementById('highlightNewSection');
        var newScroll = document.getElementById('highlightNewScroll');
        var newStores = allStores.filter(function(e) { return e.categoria !== 'restaurante' && e.categoria !== 'supermercado'; });

        if (newStores.length > 0 && !categoriaAtual) {
            newSection.style.display = '';
            var newHtml = '';
            newStores.slice(0, 8).forEach(function(e) {
                var cat = e.categoria || 'mercado';
                var catIcon = catIcons[cat] || '\uD83C\uDFEA';
                var taxa = e.taxa_entrega || 0;
                var taxaStr = taxa > 0 ? 'R$ ' + taxa.toFixed(2).replace('.', ',') : 'Entrega gr\u00e1tis';
                newHtml += '<div class="highlight-card" onclick="abrirMiniLoja(' + e.id + ')">' +
                    '<div class="highlight-card__banner ' + bannerBgClass(cat) + '">' + catIcon + '</div>' +
                    '<div class="highlight-card__body">' +
                        '<div class="highlight-card__name">' + escapeHtml(e.nome) + '</div>' +
                        '<div class="highlight-card__meta">\u2B50 ' + (e.avaliacao || 5).toFixed(1) + ' \u2022 ' + taxaStr + '</div>' +
                    '</div>' +
                '</div>';
            });
            newScroll.innerHTML = newHtml;
        } else {
            newSection.style.display = 'none';
        }
    }

    // ==================== A5: REVIEWS IN MINI-LOJA ====================
    function carregarReviews(partnerId) {
        fetch('/api/mercado/avaliacoes/listar.php?partner_id=' + partnerId + '&limit=5')
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.success && json.data && json.data.total > 0) {
                    renderReviewsSection(json.data);
                }
            })
            .catch(function() {});
    }

    function renderReviewsSection(data) {
        var body = document.getElementById('mlBody');
        var existing = body.querySelector('.ml-reviews');
        if (existing) existing.remove();

        var div = document.createElement('div');
        div.className = 'ml-reviews';

        var stars = '';
        for (var i = 1; i <= 5; i++) {
            stars += i <= Math.round(data.media) ? '&#11088;' : '&#9734;';
        }

        var html = '<div class="ml-reviews-header">' +
            '<div class="ml-reviews-title">Avaliacoes</div>' +
            '<div class="ml-reviews-avg"><span class="ml-reviews-score">' + data.media.toFixed(1) + '</span> ' + stars + ' <span style="color:#9ca3af;font-size:12px">(' + data.total + ')</span></div>' +
        '</div>';

        // Distribution bars
        var dist = data.distribuicao || {};
        for (var r = 5; r >= 1; r--) {
            var count = dist[r] || 0;
            var pct = data.total > 0 ? Math.round((count / data.total) * 100) : 0;
            html += '<div class="ml-reviews-dist">' +
                '<span class="ml-dist-label">' + r + '</span>' +
                '<div class="ml-dist-bar"><div class="ml-dist-fill" style="width:' + pct + '%"></div></div>' +
                '<span class="ml-dist-label">' + count + '</span></div>';
        }

        // Review cards
        if (data.avaliacoes && data.avaliacoes.length > 0) {
            data.avaliacoes.forEach(function(av) {
                var avStars = '';
                for (var s = 1; s <= 5; s++) {
                    avStars += s <= av.rating ? '&#11088;' : '&#9734;';
                }
                var dateStr = av.data ? new Date(av.data).toLocaleDateString('pt-BR') : '';
                html += '<div class="ml-review-card">' +
                    '<div class="ml-review-top"><span class="ml-review-name">' + escapeHtml(av.cliente) + '</span><span class="ml-review-date">' + dateStr + '</span></div>' +
                    '<div class="ml-review-stars">' + avStars + '</div>' +
                    (av.comentario ? '<div class="ml-review-text">' + escapeHtml(av.comentario) + '</div>' : '') +
                '</div>';
            });
        }

        div.innerHTML = html;
        body.appendChild(div);
    }

    // ==================== A6: DELIVERY FEE SIMULATION ====================
    function renderDeliveryFeeInfo() {
        if (!mlData || !mlData.parceiro) return;
        var p = mlData.parceiro;
        var header = document.getElementById('mlHeader');
        var existing = header.querySelector('.ml-delivery-info');
        if (existing) existing.remove();

        var freeAbove = p.entrega_gratis_acima;
        var fee = p.taxa_entrega || 0;

        // Check if cart subtotal >= free delivery threshold
        var isFree = false;
        if (freeAbove && cartPartnerId === p.id) {
            var sub = 0;
            Object.keys(cartItems).forEach(function(pid) {
                sub += (cartItems[pid] || 0) * 10; // approximate
            });
            if (sub >= freeAbove || fee === 0) isFree = true;
        }
        if (fee === 0) isFree = true;
        if (deliveryMode === 'pickup') isFree = true;

        var div = document.createElement('div');
        div.className = 'ml-delivery-info' + (isFree ? ' ml-delivery-free' : '');

        if (deliveryMode === 'pickup') {
            div.innerHTML = '&#128230; Retirada - sem taxa de entrega';
        } else if (isFree) {
            div.innerHTML = '&#128666; Entrega GRATIS';
        } else if (freeAbove) {
            div.innerHTML = '&#128666; Taxa R$ ' + fee.toFixed(2).replace('.', ',') + ' | Gratis acima de R$ ' + freeAbove.toFixed(2).replace('.', ',');
        } else {
            div.innerHTML = '&#128666; Taxa de entrega: R$ ' + fee.toFixed(2).replace('.', ',');
        }

        header.appendChild(div);
    }

    // ==================== A7: STORE HOURS ====================
    function renderStoreHours() {
        if (!mlData || !mlData.parceiro) return;
        var p = mlData.parceiro;
        var header = document.getElementById('mlHeader');
        var existing = header.querySelector('.ml-hours-dropdown');
        if (existing) existing.remove();

        if (!p.horario_funcionamento && !p.horario_abertura && !p.horario_fechamento) return;

        var open = (p.horario_funcionamento && p.horario_funcionamento.abertura) || p.horario_abertura || '';
        var close = (p.horario_funcionamento && p.horario_funcionamento.fechamento) || p.horario_fechamento || '';

        if (!open && !close) return;

        var div = document.createElement('div');
        div.className = 'ml-hours-dropdown';
        div.id = 'mlHoursDropdown';
        div.innerHTML = '&#128336; Horario: ' + (open || '--:--') + ' as ' + (close || '--:--');
        header.appendChild(div);

        // Make status badge clickable
        var badge = header.querySelector('.ml-status-badge');
        if (badge) {
            badge.style.cursor = 'pointer';
            badge.onclick = function() { div.classList.toggle('open'); };
        }
    }

    // ==================== PATCH renderMiniLoja for A1,A5,A6,A7,B5,B6 ====================
    var _originalRenderMiniLoja = renderMiniLoja;
    renderMiniLoja = function() {
        _originalRenderMiniLoja();
        renderDeliveryFeeInfo();
        renderStoreHours();
        renderMiniLojaFilters();
        renderCouponRow();
        if (mlData && mlData.parceiro) {
            carregarReviews(mlData.parceiro.id);
        }
    };

    // ==================== B5: PRODUCT FILTERS IN MINI-LOJA ====================
    var mlPriceFilter = '';
    var mlOnSale = false;

    function renderMiniLojaFilters() {
        var panel = document.getElementById('miniLojaPanel');
        var existing = panel.querySelector('.ml-filters');
        if (existing) existing.remove();

        var insertAfter = panel.querySelector('.ml-search') || panel.querySelector('.ml-cats') || document.getElementById('mlHeader');
        var div = document.createElement('div');
        div.className = 'ml-filters';
        div.innerHTML =
            '<button class="ml-filter-chip' + (mlOnSale ? ' active' : '') + '" onclick="toggleMlOnSale()">Em promocao</button>' +
            '<button class="ml-filter-chip' + (mlPriceFilter === '0-10' ? ' active' : '') + '" onclick="setMlPriceFilter(\'0-10\')">Ate R$10</button>' +
            '<button class="ml-filter-chip' + (mlPriceFilter === '10-30' ? ' active' : '') + '" onclick="setMlPriceFilter(\'10-30\')">R$10-30</button>' +
            '<button class="ml-filter-chip' + (mlPriceFilter === '30-50' ? ' active' : '') + '" onclick="setMlPriceFilter(\'30-50\')">R$30-50</button>' +
            '<button class="ml-filter-chip' + (mlPriceFilter === '50+' ? ' active' : '') + '" onclick="setMlPriceFilter(\'50+\')">R$50+</button>';
        insertAfter.after(div);
    }

    window.toggleMlOnSale = function() {
        mlOnSale = !mlOnSale;
        mlPage = 1;
        recarregarMiniLojaComFiltros();
    };

    window.setMlPriceFilter = function(range) {
        mlPriceFilter = mlPriceFilter === range ? '' : range;
        mlPage = 1;
        recarregarMiniLojaComFiltros();
    };

    function recarregarMiniLojaComFiltros() {
        var url = '/api/mercado/parceiros/mini-loja.php?id=' + mlPartnerId + '&page=' + mlPage;
        if (mlCategoryId) url += '&category_id=' + mlCategoryId;
        if (mlQuery) url += '&q=' + encodeURIComponent(mlQuery);
        if (mlOnSale) url += '&on_sale=1';
        if (mlPriceFilter) {
            var parts = mlPriceFilter.split('-');
            if (parts[0]) url += '&price_min=' + parts[0];
            if (parts[1]) url += '&price_max=' + parts[1];
            if (mlPriceFilter === '50+') url += '&price_min=50';
        }

        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.success && json.data) {
                    mlData = json.data;
                    renderMiniLoja();
                }
            })
            .catch(function() {});
    }

    // ==================== B6: LOW STOCK BADGE ====================
    // Patch renderMiniLojaProdutos to add stock badges
    var _origRenderMlProdutos = renderMiniLojaProdutos;
    renderMiniLojaProdutos = function() {
        _origRenderMlProdutos();
        // Add stock badges
        if (!mlData || !mlData.produtos) return;
        mlData.produtos.itens.forEach(function(prod) {
            if (prod.estoque > 0 && prod.estoque <= 5) {
                var cards = document.querySelectorAll('.ml-product-card');
                cards.forEach(function(card) {
                    try {
                        var dp = card.getAttribute('data-prod');
                        if (dp) {
                            var parsed = JSON.parse(dp);
                            if (parsed.id === prod.id) {
                                var imgDiv = card.querySelector('.ml-product-img');
                                if (imgDiv && !imgDiv.querySelector('.stock-badge')) {
                                    imgDiv.style.position = 'relative';
                                    var badge = document.createElement('span');
                                    badge.className = 'stock-badge';
                                    badge.textContent = 'Ultimas ' + prod.estoque + ' un!';
                                    imgDiv.appendChild(badge);
                                }
                            }
                        }
                    } catch(e) {}
                });
            }
        });
    };

    // ==================== B7: SUBSTITUTION TOGGLE ====================
    // Patch renderProdutoDetalhe to add substitution toggle
    var _origRenderProdutoDetalhe = renderProdutoDetalhe;
    renderProdutoDetalhe = function() {
        _origRenderProdutoDetalhe();
        var body = document.getElementById('pdPanel').querySelector('.pd-body');
        if (!body) return;
        var notesDiv = body.querySelector('.pd-notes');
        if (!notesDiv) return;

        // Add substitution toggle before notes
        var subDiv = document.createElement('div');
        subDiv.className = 'pd-substitute';
        var isOn = pdProduct._acceptSubstitute || false;
        subDiv.innerHTML = '<span class="pd-substitute-label">Se indisponivel, aceitar substituto similar?</span>' +
            '<div class="toggle-switch' + (isOn ? ' on' : '') + '" onclick="toggleSubstitute(this)"></div>';
        notesDiv.parentNode.insertBefore(subDiv, notesDiv);

        // B6: Stock badge in product detail
        if (pdProduct && pdProduct.estoque > 0 && pdProduct.estoque <= 5) {
            var nameEl = body.querySelector('.pd-name');
            if (nameEl) {
                nameEl.innerHTML += ' <span style="display:inline-block;padding:2px 8px;background:#dc2626;color:white;font-size:11px;font-weight:700;border-radius:4px;vertical-align:middle">Ultimas ' + pdProduct.estoque + ' un!</span>';
            }
        }
    };

    window.toggleSubstitute = function(el) {
        el.classList.toggle('on');
        if (pdProduct) pdProduct._acceptSubstitute = el.classList.contains('on');
    };

    // ==================== A1: COUPON INPUT ====================
    function renderCouponRow() {
        var panel = document.getElementById('miniLojaPanel');
        var existing = panel.querySelector('.ml-coupon-row');
        if (existing) existing.remove();
        var existingApplied = panel.querySelector('.ml-coupon-applied');
        if (existingApplied) existingApplied.remove();

        var cartBar = document.getElementById('mlCartBar');

        if (appliedCoupon) {
            var appliedDiv = document.createElement('div');
            appliedDiv.className = 'ml-coupon-applied';
            appliedDiv.innerHTML = '<span>&#127915; ' + escapeHtml(appliedCoupon.descricao) + ' (-R$ ' + appliedCoupon.desconto.toFixed(2).replace('.', ',') + ')</span>' +
                '<button class="ml-coupon-remove" onclick="removerCupom()">Remover</button>';
            cartBar.parentNode.insertBefore(appliedDiv, cartBar);
        } else if (cartCount > 0 && cartPartnerId === mlPartnerId) {
            var couponDiv = document.createElement('div');
            couponDiv.className = 'ml-coupon-row';
            couponDiv.innerHTML = '<input type="text" class="ml-coupon-input" id="mlCouponInput" placeholder="Codigo do cupom" maxlength="30">' +
                '<button class="ml-coupon-btn" onclick="aplicarCupom()">Aplicar</button>';
            cartBar.parentNode.insertBefore(couponDiv, cartBar);
        }
    }

    window.aplicarCupom = function() {
        var input = document.getElementById('mlCouponInput');
        if (!input) return;
        var code = input.value.trim();
        if (!code) { showToast('Digite o codigo do cupom', 'error'); return; }

        fetch('/api/mercado/carrinho/cupom.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                code: code,
                customer_id: customerId,
                session_id: sessionId,
                partner_id: mlPartnerId,
                subtotal: cartTotal,
                cart_items_count: cartCount
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(json) {
            if (json.success && json.data && json.data.valido) {
                appliedCoupon = json.data;
                showToast('Cupom aplicado: ' + json.data.descricao, 'success');
                renderCouponRow();
            } else {
                showToast(json.message || 'Cupom invalido', 'error');
            }
        })
        .catch(function() { showToast('Erro ao validar cupom', 'error'); });
    };

    window.removerCupom = function() {
        appliedCoupon = null;
        renderCouponRow();
        showToast('Cupom removido', 'success');
    };

    // ==================== B1+B2: CHECKOUT MODAL ====================
    var ckStep = 1;
    var ckData = {
        address: '',
        cep: '',
        city: '',
        state: '',
        payment_method: 'pix',
        is_pickup: false,
        schedule_date: '',
        schedule_time: '',
        notes: '',
        change_for: 0
    };

    // Redirect cart bar to checkout instead of index.php
    var originalCartBarOnclick = document.getElementById('mlCartBar').onclick;
    document.getElementById('mlCartBar').onclick = function() { abrirCheckout(); };
    document.getElementById('floatingCart').onclick = function() { abrirCheckout(); };

    window.abrirCheckout = function() {
        if (cartCount <= 0) {
            showToast('Carrinho vazio', 'error');
            return;
        }
        ckStep = 1;
        ckData.is_pickup = deliveryMode === 'pickup';
        renderCheckoutStep();
        document.getElementById('checkoutOverlay').classList.add('open');
    };

    window.fecharCheckout = function() {
        document.getElementById('checkoutOverlay').classList.remove('open');
    };

    function renderCheckoutStep() {
        // Update tabs
        document.querySelectorAll('.ck-step-tab').forEach(function(t) {
            var step = parseInt(t.getAttribute('data-step'));
            t.classList.toggle('active', step === ckStep);
            t.classList.toggle('done', step < ckStep);
        });

        var backBtn = document.getElementById('ckBtnBack');
        var nextBtn = document.getElementById('ckBtnNext');
        backBtn.style.display = ckStep > 1 ? 'block' : 'none';

        var body = document.getElementById('ckBody');

        if (ckStep === 1) {
            nextBtn.textContent = 'Continuar';
            var addr = ckData.address || localStorage.getItem('superbora_last_address') || '';
            var cep = ckData.cep || cepAtual || '';

            body.innerHTML =
                '<div class="ck-section">' +
                    '<div class="ck-section-title">Modo de entrega</div>' +
                    '<div class="ck-radio-group">' +
                        '<div class="ck-radio' + (!ckData.is_pickup ? ' selected' : '') + '" onclick="ckSetPickup(false)">' +
                            '<div class="ck-radio-dot"></div>' +
                            '<div><div class="ck-radio-label">&#128666; Entrega</div><div class="ck-radio-desc">Receba no seu endereco</div></div>' +
                        '</div>' +
                        '<div class="ck-radio' + (ckData.is_pickup ? ' selected' : '') + '" onclick="ckSetPickup(true)">' +
                            '<div class="ck-radio-dot"></div>' +
                            '<div><div class="ck-radio-label">&#128230; Retirada</div><div class="ck-radio-desc">Retire na loja - sem taxa</div></div>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="ck-section" id="ckAddrSection"' + (ckData.is_pickup ? ' style="display:none"' : '') + '>' +
                    '<div class="ck-section-title">Endereco de entrega</div>' +
                    '<input class="ck-input" id="ckCep" placeholder="CEP" value="' + escapeHtml(cep) + '" maxlength="9">' +
                    '<input class="ck-input" id="ckAddr" placeholder="Endereco completo" value="' + escapeHtml(addr) + '">' +
                    '<div style="display:flex;gap:8px">' +
                        '<input class="ck-input" id="ckCity" placeholder="Cidade" value="' + escapeHtml(ckData.city) + '" style="flex:1">' +
                        '<input class="ck-input" id="ckState" placeholder="UF" value="' + escapeHtml(ckData.state) + '" style="width:60px">' +
                    '</div>' +
                '</div>' +
                '<div class="ck-section">' +
                    '<div class="ck-section-title">Agendar entrega</div>' +
                    '<div style="display:flex;gap:8px">' +
                        '<input class="ck-input" type="date" id="ckSchedDate" value="' + ckData.schedule_date + '" style="flex:1">' +
                        '<input class="ck-input" type="time" id="ckSchedTime" value="' + ckData.schedule_time + '" style="width:120px">' +
                    '</div>' +
                '</div>' +
                '<!-- C6: Order Notes -->' +
                '<div class="ck-section">' +
                    '<div class="ck-section-title">Observacoes gerais</div>' +
                    '<textarea class="ck-input" id="ckNotes" rows="2" placeholder="Ex: Portao azul, tocar campainha 2x..." style="resize:vertical">' + escapeHtml(ckData.notes) + '</textarea>' +
                '</div>';

        } else if (ckStep === 2) {
            nextBtn.textContent = 'Continuar';
            body.innerHTML =
                '<div class="ck-section">' +
                    '<div class="ck-section-title">Forma de pagamento</div>' +
                    '<div class="ck-radio-group">' +
                        '<div class="ck-radio' + (ckData.payment_method === 'pix' ? ' selected' : '') + '" onclick="ckSetPayment(\'pix\')">' +
                            '<div class="ck-radio-dot"></div>' +
                            '<div><div class="ck-radio-label">PIX</div><div class="ck-radio-desc">Pagamento instantaneo via QR code</div></div>' +
                        '</div>' +
                        '<div class="ck-radio' + (ckData.payment_method === 'credito' ? ' selected' : '') + '" onclick="ckSetPayment(\'credito\')">' +
                            '<div class="ck-radio-dot"></div>' +
                            '<div><div class="ck-radio-label">Cartao de Credito</div><div class="ck-radio-desc">Visa, Mastercard, Elo</div></div>' +
                        '</div>' +
                        '<div class="ck-radio' + (ckData.payment_method === 'debito' ? ' selected' : '') + '" onclick="ckSetPayment(\'debito\')">' +
                            '<div class="ck-radio-dot"></div>' +
                            '<div><div class="ck-radio-label">Cartao de Debito</div><div class="ck-radio-desc">Pagamento na entrega</div></div>' +
                        '</div>' +
                        '<div class="ck-radio' + (ckData.payment_method === 'dinheiro' ? ' selected' : '') + '" onclick="ckSetPayment(\'dinheiro\')">' +
                            '<div class="ck-radio-dot"></div>' +
                            '<div><div class="ck-radio-label">Dinheiro</div><div class="ck-radio-desc">Pagamento na entrega</div></div>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                (ckData.payment_method === 'dinheiro' ?
                '<div class="ck-section"><div class="ck-section-title">Precisa de troco pra quanto?</div>' +
                    '<input class="ck-input" id="ckChangeFor" type="number" placeholder="Ex: 50.00" value="' + (ckData.change_for || '') + '">' +
                '</div>' : '');

        } else if (ckStep === 3) {
            nextBtn.textContent = 'Confirmar pedido';
            var deliveryFee = ckData.is_pickup ? 0 : (mlData && mlData.parceiro ? mlData.parceiro.taxa_entrega : 0);
            if (mlData && mlData.parceiro && mlData.parceiro.entrega_gratis_acima && cartTotal >= mlData.parceiro.entrega_gratis_acima) deliveryFee = 0;
            var discount = appliedCoupon ? appliedCoupon.desconto : 0;
            if (appliedCoupon && appliedCoupon.free_delivery) { deliveryFee = 0; discount = 0; }
            var totalFinal = cartTotal - discount + deliveryFee;
            if (totalFinal < 0) totalFinal = 0;

            body.innerHTML =
                '<div class="ck-section">' +
                    '<div class="ck-section-title">Resumo do pedido</div>' +
                    '<div class="ck-summary">' +
                        '<div class="ck-summary-row"><span>Subtotal</span><span>R$ ' + cartTotal.toFixed(2).replace('.', ',') + '</span></div>' +
                        (discount > 0 ? '<div class="ck-summary-row" style="color:#16a34a"><span>Desconto cupom</span><span>-R$ ' + discount.toFixed(2).replace('.', ',') + '</span></div>' : '') +
                        '<div class="ck-summary-row"><span>Taxa de entrega</span><span>' + (deliveryFee > 0 ? 'R$ ' + deliveryFee.toFixed(2).replace('.', ',') : 'Gratis') + '</span></div>' +
                        '<div class="ck-summary-total"><span>Total</span><span>R$ ' + totalFinal.toFixed(2).replace('.', ',') + '</span></div>' +
                    '</div>' +
                '</div>' +
                '<div class="ck-section">' +
                    '<div class="ck-summary-row"><span>Entrega</span><span>' + (ckData.is_pickup ? 'Retirada na loja' : escapeHtml(ckData.address || 'Endereco nao informado')) + '</span></div>' +
                    '<div class="ck-summary-row"><span>Pagamento</span><span>' + escapeHtml(ckData.payment_method.toUpperCase()) + '</span></div>' +
                    (ckData.schedule_date ? '<div class="ck-summary-row"><span>Agendado</span><span>' + ckData.schedule_date + (ckData.schedule_time ? ' ' + ckData.schedule_time : '') + '</span></div>' : '') +
                    (ckData.notes ? '<div class="ck-summary-row"><span>Obs</span><span>' + escapeHtml(ckData.notes) + '</span></div>' : '') +
                '</div>';
        }
    }

    window.ckSetPickup = function(val) {
        ckData.is_pickup = val;
        renderCheckoutStep();
    };

    window.ckSetPayment = function(method) {
        ckData.payment_method = method;
        renderCheckoutStep();
    };

    window.ckNextStep = function() {
        if (ckStep === 1) {
            // Save step 1 data
            if (!ckData.is_pickup) {
                var addr = document.getElementById('ckAddr');
                if (addr) ckData.address = addr.value.trim();
                var cep = document.getElementById('ckCep');
                if (cep) ckData.cep = cep.value.replace(/\D/g, '');
                var city = document.getElementById('ckCity');
                if (city) ckData.city = city.value.trim();
                var state = document.getElementById('ckState');
                if (state) ckData.state = state.value.trim();

                if (!ckData.address) {
                    showToast('Informe o endereco de entrega', 'error');
                    return;
                }
                localStorage.setItem('superbora_last_address', ckData.address);
            }
            var sd = document.getElementById('ckSchedDate');
            if (sd) ckData.schedule_date = sd.value;
            var st = document.getElementById('ckSchedTime');
            if (st) ckData.schedule_time = st.value;
            var notes = document.getElementById('ckNotes');
            if (notes) ckData.notes = notes.value.trim();

            ckStep = 2;
            renderCheckoutStep();
        } else if (ckStep === 2) {
            if (ckData.payment_method === 'dinheiro') {
                var cf = document.getElementById('ckChangeFor');
                if (cf) ckData.change_for = parseFloat(cf.value) || 0;
            }
            ckStep = 3;
            renderCheckoutStep();
        } else if (ckStep === 3) {
            enviarCheckout();
        }
    };

    window.ckPrevStep = function() {
        if (ckStep > 1) {
            ckStep--;
            renderCheckoutStep();
        }
    };

    function enviarCheckout() {
        var btn = document.getElementById('ckBtnNext');
        btn.disabled = true;
        btn.textContent = 'Processando...';

        var deliveryFee = ckData.is_pickup ? 0 : (mlData && mlData.parceiro ? mlData.parceiro.taxa_entrega : 0);
        if (mlData && mlData.parceiro && mlData.parceiro.entrega_gratis_acima && cartTotal >= mlData.parceiro.entrega_gratis_acima) deliveryFee = 0;
        var discount = appliedCoupon ? appliedCoupon.desconto : 0;
        if (appliedCoupon && appliedCoupon.free_delivery) { deliveryFee = 0; discount = 0; }

        fetch('/api/mercado/checkout/processar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                customer_id: customerId,
                session_id: sessionId,
                partner_id: cartPartnerId,
                payment_method: ckData.payment_method,
                address: ckData.address,
                cep: ckData.cep,
                city: ckData.city,
                state: ckData.state,
                is_pickup: ckData.is_pickup ? 1 : 0,
                schedule_date: ckData.schedule_date,
                schedule_time: ckData.schedule_time,
                notes: ckData.notes,
                coupon_id: appliedCoupon ? appliedCoupon.cupom_id : 0,
                coupon_discount: discount,
                tip: 0,
                change_for: ckData.change_for,
                customer_name: '<?php echo addslashes($customer_primeiro_nome); ?>' || 'Cliente',
                customer_phone: '',
                customer_email: ''
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(json) {
            btn.disabled = false;
            if (json.success && json.data) {
                // Clear cart state
                cartItems = {};
                cartTotal = 0;
                cartCount = 0;
                cartPartnerId = 0;
                localStorage.removeItem('cart_partner_id');
                appliedCoupon = null;
                atualizarCartBar();
                atualizarFloatingCart();

                if (json.data.forma_pagamento === 'pix' && json.data.pix) {
                    renderPixPayment(json.data);
                } else {
                    renderOrderSuccess(json.data);
                }
            } else {
                btn.textContent = 'Confirmar pedido';
                showToast(json.message || 'Erro ao processar pedido', 'error');
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = 'Confirmar pedido';
            showToast('Erro de conexao', 'error');
        });
    }

    function renderPixPayment(data) {
        var body = document.getElementById('ckBody');
        document.getElementById('ckFooter').style.display = 'none';
        document.querySelectorAll('.ck-step-tab').forEach(function(t) { t.classList.add('done'); });

        body.innerHTML = '<div class="ck-pix-container">' +
            '<div class="ck-pix-qr">&#128274;</div>' +
            '<p style="font-size:16px;font-weight:700;color:#111827;margin-bottom:8px">Pague via PIX</p>' +
            '<p style="font-size:13px;color:#6b7280;margin-bottom:16px">Pedido #' + data.order_number + ' - R$ ' + data.total.toFixed(2).replace('.', ',') + '</p>' +
            '<div class="ck-pix-code" onclick="copiarPixCode(this)" title="Clique para copiar">' + escapeHtml(data.pix.qr_code_text || '') + '</div>' +
            '<button class="ck-btn-next" style="max-width:200px;margin:0 auto" onclick="copiarPixCode()">Copiar codigo PIX</button>' +
            '<div class="ck-pix-status" id="ckPixStatus" style="margin-top:16px">Aguardando pagamento...</div>' +
        '</div>';

        // Start polling
        var pixPollInterval = setInterval(function() {
            fetch('/api/mercado/checkout/pix-status.php?order_id=' + data.order_id + '&customer_id=' + customerId)
                .then(function(r) { return r.json(); })
                .then(function(json) {
                    if (json.success && json.data && json.data.pix_paid) {
                        clearInterval(pixPollInterval);
                        document.getElementById('ckPixStatus').textContent = 'Pagamento confirmado!';
                        document.getElementById('ckPixStatus').className = 'ck-pix-status paid';
                        setTimeout(function() { renderOrderSuccess(data); }, 2000);
                    }
                })
                .catch(function() {});
        }, 3000);

        // Stop polling after 30 min
        setTimeout(function() { clearInterval(pixPollInterval); }, 1800000);
    }

    window.copiarPixCode = function(el) {
        var code = el ? el.textContent : (document.querySelector('.ck-pix-code') || {}).textContent || '';
        if (navigator.clipboard && code) {
            navigator.clipboard.writeText(code);
            showToast('Codigo PIX copiado!', 'success');
        }
    };

    function renderOrderSuccess(data) {
        var body = document.getElementById('ckBody');
        document.getElementById('ckFooter').innerHTML = '<button class="ck-btn-next" onclick="fecharCheckout();verificarPedidosAtivos()">Acompanhar pedido</button>';
        document.getElementById('ckFooter').style.display = 'flex';

        body.innerHTML = '<div class="order-success">' +
            '<div class="order-success-icon">&#9989;</div>' +
            '<h3>Pedido confirmado!</h3>' +
            '<p>Pedido #' + escapeHtml(data.order_number || '') + '</p>' +
            '<p style="margin-top:8px">Tempo estimado: ' + (data.tempo_estimado || 60) + ' min</p>' +
            '<p style="margin-top:4px;font-size:16px;font-weight:700;color:#111827">Total: R$ ' + data.total.toFixed(2).replace('.', ',') + '</p>' +
        '</div>';

        // Refresh tracking
        setTimeout(verificarPedidosAtivos, 2000);
    }

    // ==================== B3: CHAT IN TRACKING ====================
    var chatMessages = [];
    var chatPollInterval = null;

    var _origAbrirTracking = window.abrirTracking;
    window.abrirTracking = function() {
        if (activeOrders.length === 0) return;
        var o = activeOrders[0];
        var steps = [
            { label: 'Pedido recebido', icon: '&#128230;' },
            { label: 'Confirmado', icon: '&#9989;' },
            { label: 'Em preparo', icon: '&#128722;' },
            { label: 'Pronto', icon: '&#127873;' },
            { label: 'Saiu para entrega', icon: '&#128666;' }
        ];

        var html = '<h3>Acompanhar pedido #' + o.order_id + '</h3>' +
            '<div class="tracking-tabs">' +
                '<button class="tracking-tab-btn active" onclick="showTrackingTab(\'status\',this)">Status</button>' +
                '<button class="tracking-tab-btn" onclick="showTrackingTab(\'chat\',this)" style="position:relative">Chat<span class="chat-badge" id="chatBadge"></span></button>' +
                '<button class="tracking-tab-btn" onclick="showTrackingTab(\'map\',this)">Mapa</button>' +
            '</div>' +
            '<div id="trackingTabContent">';

        // Status tab
        html += '<div id="trackingTabStatus">' +
            '<p style="font-size:14px;color:#6b7280;margin-bottom:16px">' + escapeHtml(o.parceiro_nome) +
            (o.eta_minutos ? ' - Previsao: ~' + o.eta_minutos + ' min' : '') + '</p>' +
            '<div class="tracking-steps">';

        steps.forEach(function(s, i) {
            var stepNum = i + 1;
            var cls = stepNum < o.step ? 'done' : (stepNum === o.step ? 'active' : '');
            html += '<div class="tracking-step ' + cls + '"><div class="tracking-dot">' + s.icon + '</div><div><div class="tracking-step-label">' + s.label + '</div></div></div>';
        });
        html += '</div>';

        // B4: Tip section
        var showTip = ['em_entrega', 'delivering', 'entregue', 'delivered'].indexOf(o.status) >= 0;
        if (showTip) {
            html += '<div class="tip-section"><div class="tip-title">Gorjeta para o entregador</div>' +
                '<div class="tip-buttons">' +
                    '<button class="tip-btn" onclick="enviarGorjeta(' + o.order_id + ',2)">R$2</button>' +
                    '<button class="tip-btn" onclick="enviarGorjeta(' + o.order_id + ',5)">R$5</button>' +
                    '<button class="tip-btn" onclick="enviarGorjeta(' + o.order_id + ',10)">R$10</button>' +
                    '<input class="tip-custom" id="tipCustom" type="number" placeholder="Outro">' +
                    '<button class="tip-btn" onclick="enviarGorjetaCustom(' + o.order_id + ')">Enviar</button>' +
                '</div></div>';
        }
        html += '</div>';

        // Chat tab (hidden)
        html += '<div id="trackingTabChat" style="display:none">' +
            '<div class="chat-container" id="chatContainer"><div class="chat-messages" id="chatMessages">Carregando...</div></div>' +
            '<div class="chat-input-row"><input class="chat-input" id="chatInput" placeholder="Digite uma mensagem..."><button class="chat-send-btn" onclick="enviarMensagem(' + o.order_id + ')">&#10148;</button></div>' +
        '</div>';

        // Map tab (hidden) - B8
        html += '<div id="trackingTabMap" style="display:none">' +
            '<div class="tracking-map" id="trackingMap"></div>' +
            '<p style="font-size:13px;color:#6b7280;text-align:center">Atualizacao a cada 10 segundos</p>' +
        '</div>';

        html += '</div>';
        html += '<button style="width:100%;margin-top:16px;padding:12px;background:#f3f4f6;border:none;border-radius:12px;font-size:14px;font-weight:600;font-family:inherit;cursor:pointer" onclick="fecharTracking()">Fechar</button>';

        document.getElementById('trackingModal').innerHTML = html;
        document.getElementById('trackingOverlay').classList.add('open');

        // Start chat polling
        carregarChatMessages(o.order_id);
        chatPollInterval = setInterval(function() { carregarChatMessages(o.order_id); }, 5000);
    };

    var _origFecharTracking = window.fecharTracking;
    window.fecharTracking = function() {
        if (chatPollInterval) clearInterval(chatPollInterval);
        document.getElementById('trackingOverlay').classList.remove('open');
    };

    window.showTrackingTab = function(tab, btn) {
        document.querySelectorAll('.tracking-tab-btn').forEach(function(b) { b.classList.remove('active'); });
        btn.classList.add('active');
        document.getElementById('trackingTabStatus').style.display = tab === 'status' ? 'block' : 'none';
        document.getElementById('trackingTabChat').style.display = tab === 'chat' ? 'block' : 'none';
        document.getElementById('trackingTabMap').style.display = tab === 'map' ? 'block' : 'none';

        if (tab === 'map') initTrackingMap();
        if (tab === 'chat') {
            var badge = document.getElementById('chatBadge');
            if (badge) badge.style.display = 'none';
        }
    };

    function carregarChatMessages(orderId) {
        fetch('/api/mercado/chat/mensagens.php?order_id=' + orderId)
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.success && json.data) {
                    var msgs = json.data.mensagens || json.data;
                    if (Array.isArray(msgs)) {
                        chatMessages = msgs;
                        renderChatMessages();
                    }
                }
            })
            .catch(function() {});
    }

    function renderChatMessages() {
        var container = document.getElementById('chatMessages');
        if (!container) return;
        if (chatMessages.length === 0) {
            container.innerHTML = '<p style="text-align:center;color:#9ca3af;font-size:14px;padding:20px">Nenhuma mensagem ainda</p>';
            return;
        }
        var html = '';
        chatMessages.forEach(function(m) {
            var isSent = (m.sender_type || m.remetente_tipo) === 'cliente';
            var time = m.created_at ? new Date(m.created_at).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : '';
            html += '<div class="chat-bubble ' + (isSent ? 'sent' : 'received') + '">' +
                escapeHtml(m.message || m.mensagem) +
                '<div class="chat-time">' + time + '</div>' +
            '</div>';
        });
        container.innerHTML = html;
        container.parentNode.scrollTop = container.parentNode.scrollHeight;
    }

    window.enviarMensagem = function(orderId) {
        var input = document.getElementById('chatInput');
        if (!input) return;
        var msg = input.value.trim();
        if (!msg) return;
        input.value = '';

        fetch('/api/mercado/chat/enviar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                order_id: orderId,
                remetente_tipo: 'cliente',
                remetente_id: customerId,
                mensagem: msg
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(json) {
            if (json.success) {
                carregarChatMessages(orderId);
            } else {
                showToast(json.message || 'Erro ao enviar', 'error');
            }
        })
        .catch(function() { showToast('Erro de conexao', 'error'); });
    };

    // ==================== B4: TIP ====================
    window.enviarGorjeta = function(orderId, amount) {
        fetch('/api/mercado/pedidos/gorjeta.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: orderId, customer_id: customerId, amount: amount })
        })
        .then(function(r) { return r.json(); })
        .then(function(json) {
            showToast(json.success ? 'Gorjeta enviada! Obrigado!' : (json.message || 'Erro'), json.success ? 'success' : 'error');
        })
        .catch(function() { showToast('Erro de conexao', 'error'); });
    };

    window.enviarGorjetaCustom = function(orderId) {
        var input = document.getElementById('tipCustom');
        var amount = parseFloat(input ? input.value : 0);
        if (amount > 0) {
            enviarGorjeta(orderId, amount);
        } else {
            showToast('Informe um valor', 'error');
        }
    };

    // ==================== B8: MAP ====================
    var trackingMap = null;
    var driverMarker = null;
    var mapPollInterval = null;

    function initTrackingMap() {
        if (!window.L) return;
        var mapEl = document.getElementById('trackingMap');
        if (!mapEl) return;
        if (trackingMap) { trackingMap.invalidateSize(); return; }

        // Default center (Sao Paulo)
        var lat = userLat || -23.55;
        var lng = userLng || -46.63;

        trackingMap = L.map('trackingMap').setView([lat, lng], 14);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap'
        }).addTo(trackingMap);

        // Add delivery address marker
        L.marker([lat, lng]).addTo(trackingMap).bindPopup('Seu endereco');

        // Poll driver location
        updateDriverLocation();
        mapPollInterval = setInterval(updateDriverLocation, 10000);
    }

    function updateDriverLocation() {
        if (activeOrders.length === 0) return;
        var o = activeOrders[0];
        if (!o.shopper_lat && !o.shopper_latitude) return;

        var dlat = o.shopper_lat || o.shopper_latitude || 0;
        var dlng = o.shopper_lng || o.shopper_longitude || 0;
        if (!dlat || !dlng) return;

        if (!trackingMap) return;
        if (driverMarker) {
            driverMarker.setLatLng([dlat, dlng]);
        } else {
            var icon = L.divIcon({ html: '<div style="font-size:24px">&#128666;</div>', className: '', iconSize: [30, 30] });
            driverMarker = L.marker([dlat, dlng], { icon: icon }).addTo(trackingMap).bindPopup('Entregador');
        }
    }

    // ==================== C1: PUSH NOTIFICATIONS ====================
    function checkPushSupport() {
        if ('Notification' in window && 'serviceWorker' in navigator && Notification.permission === 'default') {
            if (!localStorage.getItem('superbora_push_dismissed')) {
                document.getElementById('pushBanner').classList.add('show');
            }
        }
    }

    window.ativarPushNotifications = function() {
        Notification.requestPermission().then(function(perm) {
            if (perm === 'granted') {
                showToast('Notificacoes ativadas!', 'success');
                document.getElementById('pushBanner').classList.remove('show');
                // In production, register service worker and save subscription
            } else {
                showToast('Permissao negada', 'error');
            }
        });
    };

    window.fecharPushBanner = function() {
        document.getElementById('pushBanner').classList.remove('show');
        localStorage.setItem('superbora_push_dismissed', '1');
    };

    // ==================== C2: RECOMMENDATIONS ====================
    function carregarRecomendados() {
        var url = '/api/mercado/produtos/recomendados.php?limit=10';
        if (customerId) url += '&customer_id=' + customerId;

        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.success && json.data && json.data.produtos && json.data.produtos.length > 0) {
                    renderRecSection('recSection', 'recScroll', json.data.produtos);
                }
            })
            .catch(function() {});
    }

    // ==================== C3: TRENDING ====================
    function carregarTrending() {
        var url = '/api/mercado/produtos/trending.php?limit=10';
        if (cepAtual) url += '&cep=' + cepAtual;

        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.success && json.data && json.data.produtos && json.data.produtos.length > 0) {
                    renderRecSection('trendingSection', 'trendingScroll', json.data.produtos);
                }
            })
            .catch(function() {});
    }

    function renderRecSection(sectionId, scrollId, produtos) {
        var html = '';
        produtos.forEach(function(p) {
            var imgHtml = p.imagem ? '<img src="' + escapeHtml(p.imagem) + '" alt="" onerror="this.parentNode.innerHTML=\'&#128230;\'">' : '&#128230;';
            var priceHtml = p.preco_promo
                ? '<span style="text-decoration:line-through;font-size:11px;color:#9ca3af">R$ ' + p.preco.toFixed(2).replace('.', ',') + '</span> <span class="rec-card-price" style="color:#dc2626">R$ ' + p.preco_promo.toFixed(2).replace('.', ',') + '</span>'
                : '<span class="rec-card-price">R$ ' + p.preco.toFixed(2).replace('.', ',') + '</span>';
            html += '<div class="rec-card" onclick="abrirMiniLoja(' + p.parceiro_id + ')">' +
                '<div class="rec-card-img">' + imgHtml + '</div>' +
                '<div class="rec-card-body">' +
                    '<div class="rec-card-name">' + escapeHtml(p.nome) + '</div>' +
                    '<div>' + priceHtml + '</div>' +
                    '<div class="rec-card-store">' + escapeHtml(p.parceiro_nome || '') + '</div>' +
                '</div></div>';
        });
        document.getElementById(scrollId).innerHTML = html;
        document.getElementById(sectionId).style.display = 'block';
    }

    // ==================== C4: SHARE CART ====================
    window.compartilharCarrinho = function() {
        if (cartCount <= 0) { showToast('Carrinho vazio', 'error'); return; }
        var cartData = { partner_id: cartPartnerId, items: cartItems };
        var encoded = btoa(JSON.stringify(cartData));
        var url = window.location.origin + '/mercado/estabelecimentos.php?shared_cart=' + encoded;

        if (navigator.share) {
            navigator.share({ title: 'Meu carrinho SuperBora', url: url }).catch(function() {});
        } else if (navigator.clipboard) {
            navigator.clipboard.writeText(url);
            showToast('Link do carrinho copiado!', 'success');
        }
    };

    // ==================== C5: LOYALTY POINTS ====================
    function carregarPontosFidelidade() {
        // Simplified - in production, create a dedicated API
        // For now, show badge if customer has points from localStorage
        var points = parseInt(localStorage.getItem('superbora_loyalty_points') || '0');
        if (points > 0) {
            var header = document.querySelector('.vitrine-hero-inner h1');
            if (header) {
                header.innerHTML += ' <span class="loyalty-badge">&#127775; ' + points + ' pts</span>';
            }
        }
    }

    // ==================== INIT ====================
    carregarCarrinhoAtual();
    carregarBanners();
    carregarHistorico();
    if (!cepAtual) {
        carregarEstabelecimentos();
    }
    // Check active orders every 30s
    verificarPedidosAtivos();
    setInterval(verificarPedidosAtivos, 30000);
    // Update floating cart on load
    setTimeout(atualizarFloatingCart, 1500);

    // New feature inits
    checkPushSupport();
    carregarRecomendados();
    carregarTrending();
    carregarPontosFidelidade();

    // Handle shared cart links (C4)
    var urlParams = new URLSearchParams(window.location.search);
    var sharedCart = urlParams.get('shared_cart');
    if (sharedCart) {
        try {
            var shared = JSON.parse(atob(sharedCart));
            if (shared && shared.partner_id) {
                abrirMiniLoja(shared.partner_id);
                showToast('Carrinho compartilhado carregado!', 'success');
            }
        } catch(e) {}
    }
})();
</script>

</body>
</html>
