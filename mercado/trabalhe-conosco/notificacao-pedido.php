<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ╔══════════════════════════════════════════════════════════════════════════════════════╗
 * ║                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg> SISTEMA DE NOTIFICAÇÕES ESTILO UBER                            ║
 * ║               Push Notification + Som + Vibração + Modal Premium                     ║
 * ╚══════════════════════════════════════════════════════════════════════════════════════╝
 */

session_start();
if (!isset($_SESSION['worker_id'])) { header('Location: login.php'); exit; }

$conn = getMySQLi();
$conn->set_charset('utf8mb4');

$worker_id = $_SESSION['worker_id'];
$worker = $conn->query("SELECT * FROM om_workers WHERE worker_id = $worker_id")->fetch_assoc();
$is_online = (bool)$worker['is_online'];
$nome = explode(' ', $worker['name'])[0];

$conn->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#0A0A0A">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>OneMundo Worker</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0A0A0A;
            --card: #141414;
            --border: rgba(255,255,255,0.08);
            --text: #FFFFFF;
            --text-secondary: rgba(255,255,255,0.7);
            --text-muted: rgba(255,255,255,0.45);
            --accent: #00D26A;
            --accent-glow: rgba(0,210,106,0.3);
            --orange: #F59E0B;
            --red: #EF4444;
            --blue: #3B82F6;
            --purple: #8B5CF6;
            --safe-top: env(safe-area-inset-top, 0px);
            --safe-bottom: env(safe-area-inset-bottom, 24px);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background: var(--bg); 
            color: var(--text); 
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* ═══════════════════════════════════════════════════════════════════════════════ */
        /* NOTIFICATION OVERLAY - ESTILO UBER */
        /* ═══════════════════════════════════════════════════════════════════════════════ */
        .notification-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.9);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: fadeIn 0.3s ease;
        }

        .notification-overlay.active {
            display: flex;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .notification-card {
            width: 100%;
            max-width: 400px;
            background: linear-gradient(180deg, #1A1A1A 0%, #0D0D0D 100%);
            border-radius: 32px;
            overflow: hidden;
            animation: slideUp 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            box-shadow: 0 40px 100px rgba(0,0,0,0.5);
            border: 1px solid var(--border);
        }

        @keyframes slideUp {
            from { 
                opacity: 0; 
                transform: translateY(100px) scale(0.9); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0) scale(1); 
            }
        }

        /* Header com Timer Circular */
        .notif-header {
            padding: 32px 24px;
            text-align: center;
            position: relative;
            background: linear-gradient(180deg, rgba(0,210,106,0.1) 0%, transparent 100%);
        }

        .timer-ring {
            width: 120px;
            height: 120px;
            margin: 0 auto 20px;
            position: relative;
        }

        .timer-ring svg {
            transform: rotate(-90deg);
            width: 120px;
            height: 120px;
        }

        .timer-ring circle {
            fill: none;
            stroke-width: 8;
        }

        .timer-ring .bg {
            stroke: rgba(255,255,255,0.1);
        }

        .timer-ring .progress {
            stroke: var(--accent);
            stroke-linecap: round;
            stroke-dasharray: 339.292;
            stroke-dashoffset: 0;
            transition: stroke-dashoffset 1s linear;
            filter: drop-shadow(0 0 10px var(--accent));
        }

        .timer-ring.warning .progress {
            stroke: var(--orange);
            filter: drop-shadow(0 0 10px var(--orange));
        }

        .timer-ring.critical .progress {
            stroke: var(--red);
            filter: drop-shadow(0 0 10px var(--red));
            animation: pulse-ring 0.5s ease infinite;
        }

        @keyframes pulse-ring {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }

        .timer-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }

        .timer-seconds {
            font-size: 42px;
            font-weight: 900;
            line-height: 1;
            color: var(--accent);
        }

        .timer-ring.warning .timer-seconds { color: var(--orange); }
        .timer-ring.critical .timer-seconds { color: var(--red); }

        .timer-label {
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 4px;
        }

        .notif-title {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .notif-subtitle {
            font-size: 14px;
            color: var(--text-secondary);
        }

        /* Store Info */
        .notif-store {
            padding: 20px 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            background: rgba(255,255,255,0.02);
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }

        .store-logo {
            width: 64px;
            height: 64px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 800;
            color: #fff;
            flex-shrink: 0;
        }

        .store-info h3 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .store-info p {
            font-size: 13px;
            color: var(--text-muted);
        }

        .store-badge {
            margin-left: auto;
            background: rgba(0,210,106,0.15);
            border: 1px solid rgba(0,210,106,0.3);
            padding: 8px 14px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
            color: var(--accent);
        }

        /* Order Details */
        .notif-details {
            padding: 24px;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }

        .detail-item {
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 16px 12px;
            text-align: center;
        }

        .detail-icon {
            font-size: 24px;
            margin-bottom: 8px;
        }

        .detail-value {
            font-size: 18px;
            font-weight: 800;
        }

        .detail-label {
            font-size: 10px;
            color: var(--text-muted);
            margin-top: 4px;
            text-transform: uppercase;
        }

        /* Customer */
        .notif-customer {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px;
            background: rgba(255,255,255,0.02);
            border-radius: 16px;
            margin-bottom: 20px;
        }

        .customer-avatar {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            background: linear-gradient(145deg, var(--purple), #7C3AED);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 700;
        }

        .customer-info h4 {
            font-size: 16px;
            font-weight: 600;
        }

        .customer-info p {
            font-size: 13px;
            color: var(--text-muted);
        }

        .customer-rating {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 15px;
            font-weight: 700;
        }

        /* Earnings */
        .notif-earnings {
            background: linear-gradient(145deg, rgba(0,210,106,0.1), rgba(0,168,85,0.05));
            border: 2px solid var(--accent);
            border-radius: 20px;
            padding: 24px;
            text-align: center;
            margin-bottom: 8px;
        }

        .earnings-label {
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }

        .earnings-value {
            font-size: 52px;
            font-weight: 900;
            background: linear-gradient(135deg, var(--accent), #00E676);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            line-height: 1;
        }

        .earnings-breakdown {
            display: flex;
            justify-content: center;
            gap: 16px;
            margin-top: 12px;
            font-size: 13px;
        }

        .earnings-breakdown span {
            color: var(--text-secondary);
        }

        .earnings-breakdown .tip { color: var(--accent); }
        .earnings-breakdown .bonus { color: var(--orange); }

        /* Actions */
        .notif-actions {
            padding: 24px;
            display: flex;
            gap: 12px;
        }

        .btn-decline {
            flex: 1;
            padding: 20px;
            background: rgba(239,68,68,0.1);
            border: 2px solid rgba(239,68,68,0.3);
            border-radius: 18px;
            color: var(--red);
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s;
        }

        .btn-decline:active {
            transform: scale(0.95);
            background: rgba(239,68,68,0.2);
        }

        .btn-accept {
            flex: 2;
            padding: 20px;
            background: linear-gradient(145deg, var(--accent), #00A855);
            border: none;
            border-radius: 18px;
            color: #000;
            font-size: 18px;
            font-weight: 800;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s;
            box-shadow: 0 12px 40px var(--accent-glow);
            position: relative;
            overflow: hidden;
        }

        .btn-accept::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            to { left: 100%; }
        }

        .btn-accept:active {
            transform: scale(0.95);
        }

        /* ═══════════════════════════════════════════════════════════════════════════════ */
        /* MINI NOTIFICATION TOAST */
        /* ═══════════════════════════════════════════════════════════════════════════════ */
        .mini-toast {
            position: fixed;
            top: calc(20px + var(--safe-top));
            left: 20px;
            right: 20px;
            background: linear-gradient(145deg, #1A1A1A, #0D0D0D);
            border: 2px solid var(--accent);
            border-radius: 20px;
            padding: 16px 20px;
            display: none;
            align-items: center;
            gap: 16px;
            z-index: 9998;
            cursor: pointer;
            animation: slideDown 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            box-shadow: 0 20px 60px rgba(0,210,106,0.2);
        }

        .mini-toast.active {
            display: flex;
        }

        @keyframes slideDown {
            from { 
                opacity: 0; 
                transform: translateY(-100%); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0); 
            }
        }

        .toast-pulse {
            width: 48px;
            height: 48px;
            background: var(--accent);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            animation: pulse-icon 1s ease infinite;
            flex-shrink: 0;
        }

        @keyframes pulse-icon {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .toast-content {
            flex: 1;
        }

        .toast-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 2px;
        }

        .toast-subtitle {
            font-size: 13px;
            color: var(--text-muted);
        }

        .toast-value {
            font-size: 24px;
            font-weight: 900;
            color: var(--accent);
        }

        /* ═══════════════════════════════════════════════════════════════════════════════ */
        /* MAIN CONTENT - AGUARDANDO PEDIDOS */
        /* ═══════════════════════════════════════════════════════════════════════════════ */
        .main-content {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            padding: calc(24px + var(--safe-top)) 24px calc(24px + var(--safe-bottom));
        }

        .status-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .status-header h1 {
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: <?= $is_online ? 'rgba(0,210,106,0.15)' : 'rgba(239,68,68,0.15)' ?>;
            border: 1px solid <?= $is_online ? 'rgba(0,210,106,0.3)' : 'rgba(239,68,68,0.3)' ?>;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
            color: <?= $is_online ? 'var(--accent)' : 'var(--red)' ?>;
        }

        .status-dot {
            width: 10px;
            height: 10px;
            background: <?= $is_online ? 'var(--accent)' : 'var(--red)' ?>;
            border-radius: 50%;
            <?php if($is_online): ?>
            animation: blink 1.5s ease infinite;
            <?php endif; ?>
        }

        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }

        .waiting-animation {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .radar-container {
            width: 200px;
            height: 200px;
            position: relative;
            margin-bottom: 40px;
        }

        .radar-ring {
            position: absolute;
            border: 2px solid var(--accent);
            border-radius: 50%;
            opacity: 0;
            animation: radar-pulse 3s ease-out infinite;
        }

        .radar-ring:nth-child(1) { animation-delay: 0s; }
        .radar-ring:nth-child(2) { animation-delay: 1s; }
        .radar-ring:nth-child(3) { animation-delay: 2s; }

        @keyframes radar-pulse {
            0% {
                width: 40px;
                height: 40px;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                opacity: 1;
            }
            100% {
                width: 200px;
                height: 200px;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                opacity: 0;
            }
        }

        .radar-center {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 80px;
            height: 80px;
            background: linear-gradient(145deg, var(--accent), #00A855);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            box-shadow: 0 0 60px var(--accent-glow);
        }

        .waiting-text {
            text-align: center;
        }

        .waiting-text h2 {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .waiting-text p {
            font-size: 15px;
            color: var(--text-muted);
        }

        /* Demo Button */
        .demo-btn {
            position: fixed;
            bottom: calc(24px + var(--safe-bottom));
            left: 24px;
            right: 24px;
            padding: 20px;
            background: var(--card);
            border: 2px solid var(--border);
            border-radius: 20px;
            color: var(--text);
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
        }

        .demo-btn:active {
            transform: scale(0.98);
        }

        /* Offline State */
        .offline-state {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .offline-icon {
            width: 120px;
            height: 120px;
            background: var(--card);
            border-radius: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 56px;
            margin-bottom: 32px;
        }

        .offline-state h2 {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 12px;
        }

        .offline-state p {
            font-size: 15px;
            color: var(--text-muted);
            margin-bottom: 32px;
            max-width: 280px;
        }

        .btn-go-online {
            padding: 20px 48px;
            background: linear-gradient(145deg, var(--accent), #00A855);
            border: none;
            border-radius: 20px;
            color: #000;
            font-size: 18px;
            font-weight: 800;
            cursor: pointer;
            font-family: inherit;
            box-shadow: 0 12px 40px var(--accent-glow);
        }
    </style>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/clean-theme.css">
<!-- HEADER PREMIUM v3.0 -->
<style>

/* ══════════════════════════════════════════════════════════════════════════════
   🎨 HEADER PREMIUM v3.0 - OneMundo Mercado
   ══════════════════════════════════════════════════════════════════════════════ */

/* Variáveis do Header */
:root {
    --header-bg: rgba(255, 255, 255, 0.92);
    --header-bg-scrolled: rgba(255, 255, 255, 0.98);
    --header-blur: 20px;
    --header-shadow: 0 4px 30px rgba(0, 0, 0, 0.08);
    --header-border: rgba(0, 0, 0, 0.04);
    --header-height: 72px;
    --header-height-mobile: 64px;
}

/* Header Principal */
.header, .site-header, [class*="header-main"] {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    z-index: 1000 !important;
    background: var(--header-bg) !important;
    backdrop-filter: blur(var(--header-blur)) saturate(180%) !important;
    -webkit-backdrop-filter: blur(var(--header-blur)) saturate(180%) !important;
    border-bottom: 1px solid var(--header-border) !important;
    box-shadow: none !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    height: auto !important;
    min-height: var(--header-height) !important;
}

.header.scrolled, .site-header.scrolled {
    background: var(--header-bg-scrolled) !important;
    box-shadow: var(--header-shadow) !important;
}

/* Container do Header */
.header-inner, .header-content, .header > div:first-child {
    max-width: 1400px !important;
    margin: 0 auto !important;
    padding: 12px 24px !important;
    display: flex !important;
    align-items: center !important;
    gap: 20px !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   LOCALIZAÇÃO - Estilo Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.location-btn, .endereco, [class*="location"], [class*="endereco"], [class*="address"] {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 10px 18px !important;
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.08), rgba(16, 185, 129, 0.04)) !important;
    border: 1px solid rgba(16, 185, 129, 0.15) !important;
    border-radius: 14px !important;
    cursor: pointer !important;
    transition: all 0.3s ease !important;
    min-width: 200px !important;
    max-width: 320px !important;
}

.location-btn:hover, .endereco:hover, [class*="location"]:hover {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0.06)) !important;
    border-color: rgba(16, 185, 129, 0.25) !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15) !important;
}

/* Ícone de localização */
.location-btn svg, .location-btn i, [class*="location"] svg {
    width: 22px !important;
    height: 22px !important;
    color: #10b981 !important;
    flex-shrink: 0 !important;
}

/* Texto da localização */
.location-text, .endereco-text {
    flex: 1 !important;
    min-width: 0 !important;
}

.location-label, .entregar-em {
    font-size: 11px !important;
    font-weight: 500 !important;
    color: #64748b !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    margin-bottom: 2px !important;
}

.location-address, .endereco-rua {
    font-size: 14px !important;
    font-weight: 600 !important;
    color: #1e293b !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

/* Seta da localização */
.location-arrow, .location-btn > svg:last-child {
    width: 16px !important;
    height: 16px !important;
    color: #94a3b8 !important;
    transition: transform 0.2s ease !important;
}

.location-btn:hover .location-arrow {
    transform: translateX(3px) !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   TEMPO DE ENTREGA - Badge Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.delivery-time, .tempo-entrega, [class*="delivery-time"], [class*="tempo"] {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    padding: 10px 16px !important;
    background: linear-gradient(135deg, #0f172a, #1e293b) !important;
    border-radius: 12px !important;
    color: white !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.2) !important;
    transition: all 0.3s ease !important;
}

.delivery-time:hover, .tempo-entrega:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 20px rgba(15, 23, 42, 0.25) !important;
}

.delivery-time svg, .tempo-entrega svg, .delivery-time i {
    width: 18px !important;
    height: 18px !important;
    color: #10b981 !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   LOGO - Design Moderno
   ══════════════════════════════════════════════════════════════════════════════ */

.logo, .site-logo, [class*="logo"] {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    text-decoration: none !important;
    transition: transform 0.3s ease !important;
}

.logo:hover {
    transform: scale(1.02) !important;
}

.logo-icon, .logo img, .logo svg {
    width: 48px !important;
    height: 48px !important;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
    border-radius: 14px !important;
    padding: 10px !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3) !important;
    transition: all 0.3s ease !important;
}

.logo:hover .logo-icon, .logo:hover img {
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4) !important;
    transform: rotate(-3deg) !important;
}

.logo-text, .logo span, .site-title {
    font-size: 1.5rem !important;
    font-weight: 800 !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    -webkit-background-clip: text !important;
    -webkit-text-fill-color: transparent !important;
    background-clip: text !important;
    letter-spacing: -0.02em !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   BUSCA - Search Bar Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.search-container, .search-box, [class*="search"], .busca {
    flex: 1 !important;
    max-width: 600px !important;
    position: relative !important;
}

.search-input, input[type="search"], input[name*="search"], input[name*="busca"], .busca input {
    width: 100% !important;
    padding: 14px 20px 14px 52px !important;
    background: #f1f5f9 !important;
    border: 2px solid transparent !important;
    border-radius: 16px !important;
    font-size: 15px !important;
    font-weight: 500 !important;
    color: #1e293b !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.02) !important;
}

.search-input:hover, input[type="search"]:hover {
    background: #e2e8f0 !important;
}

.search-input:focus, input[type="search"]:focus {
    background: #ffffff !important;
    border-color: #10b981 !important;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.12), inset 0 2px 4px rgba(0, 0, 0, 0.02) !important;
    outline: none !important;
}

.search-input::placeholder {
    color: #94a3b8 !important;
    font-weight: 400 !important;
}

/* Ícone da busca */
.search-icon, .search-container svg, .busca svg {
    position: absolute !important;
    left: 18px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    width: 22px !important;
    height: 22px !important;
    color: #94a3b8 !important;
    pointer-events: none !important;
    transition: color 0.3s ease !important;
}

.search-input:focus + .search-icon,
.search-container:focus-within svg {
    color: #10b981 !important;
}

/* Botão de busca por voz (opcional) */
.search-voice-btn {
    position: absolute !important;
    right: 12px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    width: 36px !important;
    height: 36px !important;
    background: transparent !important;
    border: none !important;
    border-radius: 10px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
}

.search-voice-btn:hover {
    background: rgba(16, 185, 129, 0.1) !important;
}

.search-voice-btn svg {
    width: 20px !important;
    height: 20px !important;
    color: #64748b !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   CARRINHO - Cart Button Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.cart-btn, .carrinho-btn, [class*="cart"], [class*="carrinho"], a[href*="cart"], a[href*="carrinho"] {
    position: relative !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 52px !important;
    height: 52px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border: none !important;
    border-radius: 16px !important;
    cursor: pointer !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35) !important;
}

.cart-btn:hover, .carrinho-btn:hover, [class*="cart"]:hover {
    transform: translateY(-3px) scale(1.02) !important;
    box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4) !important;
}

.cart-btn:active {
    transform: translateY(-1px) scale(0.98) !important;
}

.cart-btn svg, .carrinho-btn svg, [class*="cart"] svg {
    width: 26px !important;
    height: 26px !important;
    color: white !important;
}

/* Badge do carrinho */
.cart-badge, .carrinho-badge, [class*="cart-count"], [class*="badge"] {
    position: absolute !important;
    top: -6px !important;
    right: -6px !important;
    min-width: 24px !important;
    height: 24px !important;
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    color: white !important;
    font-size: 12px !important;
    font-weight: 800 !important;
    border-radius: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 6px !important;
    border: 3px solid white !important;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4) !important;
    animation: badge-pulse 2s ease-in-out infinite !important;
}

@keyframes badge-pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

/* ══════════════════════════════════════════════════════════════════════════════
   MENU MOBILE
   ══════════════════════════════════════════════════════════════════════════════ */

.menu-btn, .hamburger, [class*="menu-toggle"] {
    display: none !important;
    width: 44px !important;
    height: 44px !important;
    background: #f1f5f9 !important;
    border: none !important;
    border-radius: 12px !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
}

.menu-btn:hover {
    background: #e2e8f0 !important;
}

.menu-btn svg {
    width: 24px !important;
    height: 24px !important;
    color: #475569 !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   RESPONSIVO
   ══════════════════════════════════════════════════════════════════════════════ */

@media (max-width: 1024px) {
    .search-container, .search-box {
        max-width: 400px !important;
    }
    
    .location-btn, .endereco {
        max-width: 250px !important;
    }
}

@media (max-width: 768px) {
    :root {
        --header-height: var(--header-height-mobile);
    }
    
    .header-inner, .header-content {
        padding: 10px 16px !important;
        gap: 12px !important;
    }
    
    /* Esconder busca no header mobile - mover para baixo */
    .search-container, .search-box, [class*="search"]:not(.search-icon) {
        position: absolute !important;
        top: 100% !important;
        left: 0 !important;
        right: 0 !important;
        max-width: 100% !important;
        padding: 12px 16px !important;
        background: white !important;
        border-top: 1px solid #e2e8f0 !important;
        display: none !important;
    }
    
    .search-container.active {
        display: block !important;
    }
    
    /* Logo menor */
    .logo-icon, .logo img {
        width: 42px !important;
        height: 42px !important;
        border-radius: 12px !important;
    }
    
    .logo-text {
        display: none !important;
    }
    
    /* Localização compacta */
    .location-btn, .endereco {
        min-width: auto !important;
        max-width: 180px !important;
        padding: 8px 12px !important;
    }
    
    .location-label, .entregar-em {
        display: none !important;
    }
    
    .location-address {
        font-size: 13px !important;
    }
    
    /* Tempo de entrega menor */
    .delivery-time, .tempo-entrega {
        padding: 8px 12px !important;
        font-size: 12px !important;
    }
    
    /* Carrinho menor */
    .cart-btn, .carrinho-btn {
        width: 46px !important;
        height: 46px !important;
        border-radius: 14px !important;
    }
    
    .cart-btn svg {
        width: 22px !important;
        height: 22px !important;
    }
    
    /* Mostrar menu button */
    .menu-btn, .hamburger {
        display: flex !important;
    }
}

@media (max-width: 480px) {
    .location-btn, .endereco {
        max-width: 140px !important;
    }
    
    .delivery-time, .tempo-entrega {
        display: none !important;
    }
}

/* ══════════════════════════════════════════════════════════════════════════════
   ANIMAÇÕES DE ENTRADA
   ══════════════════════════════════════════════════════════════════════════════ */

@keyframes headerSlideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.header, .site-header {
    animation: headerSlideDown 0.5s ease forwards !important;
}

.header-inner > *, .header-content > * {
    animation: headerSlideDown 0.5s ease forwards !important;
}

.header-inner > *:nth-child(1) { animation-delay: 0.05s !important; }
.header-inner > *:nth-child(2) { animation-delay: 0.1s !important; }
.header-inner > *:nth-child(3) { animation-delay: 0.15s !important; }
.header-inner > *:nth-child(4) { animation-delay: 0.2s !important; }
.header-inner > *:nth-child(5) { animation-delay: 0.25s !important; }

/* ══════════════════════════════════════════════════════════════════════════════
   AJUSTES DE BODY PARA HEADER FIXED
   ══════════════════════════════════════════════════════════════════════════════ */

body {
    padding-top: calc(var(--header-height) + 10px) !important;
}

@media (max-width: 768px) {
    body {
        padding-top: calc(var(--header-height-mobile) + 10px) !important;
    }
}

</style>
</head>
<body>
    <!-- NOTIFICATION OVERLAY - ESTILO UBER -->
    <div class="notification-overlay" id="notification-overlay">
        <div class="notification-card">
            <div class="notif-header">
                <div class="timer-ring" id="timer-ring">
                    <svg viewBox="0 0 120 120">
                        <circle class="bg" cx="60" cy="60" r="54"/>
                        <circle class="progress" id="timer-progress" cx="60" cy="60" r="54"/>
                    </svg>
                    <div class="timer-text">
                        <div class="timer-seconds" id="timer-seconds">30</div>
                        <div class="timer-label">segundos</div>
                    </div>
                </div>
                <h2 class="notif-title"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg> Novo Pedido!</h2>
                <p class="notif-subtitle">Você tem um novo pedido disponível</p>
            </div>

            <div class="notif-store">
                <div class="store-logo" id="store-logo" style="background: linear-gradient(145deg, #43B02A, #3a9c24);">P</div>
                <div class="store-info">
                    <h3 id="store-name">Pão de Açúcar</h3>
                    <p id="store-address"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg> Av. Paulista, 1500</p>
                </div>
                <div class="store-badge">Verificado ✓</div>
            </div>

            <div class="notif-details">
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="detail-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg></div>
                        <div class="detail-value" id="detail-items">18</div>
                        <div class="detail-label">Itens</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg></div>
                        <div class="detail-value" id="detail-distance">3.2km</div>
                        <div class="detail-label">Distância</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
                        <div class="detail-value" id="detail-time">35min</div>
                        <div class="detail-label">Estimado</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-icon">🏷️</div>
                        <div class="detail-value" id="detail-type">Full</div>
                        <div class="detail-label">Tipo</div>
                    </div>
                </div>

                <div class="notif-customer">
                    <div class="customer-avatar" id="customer-avatar">M</div>
                    <div class="customer-info">
                        <h4 id="customer-name">Marina Costa</h4>
                        <p id="customer-address">Jardins, São Paulo</p>
                    </div>
                    <div class="customer-rating"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg> <span id="customer-rating">4.9</span></div>
                </div>

                <div class="notif-earnings">
                    <div class="earnings-label">Você vai ganhar</div>
                    <div class="earnings-value" id="earnings-total">R$ 65,00</div>
                    <div class="earnings-breakdown">
                        <span>Base: R$<span id="earnings-base">45</span></span>
                        <span class="tip">Gorjeta: R$<span id="earnings-tip">12</span></span>
                        <span class="bonus">Bônus: R$<span id="earnings-bonus">8</span></span>
                    </div>
                </div>
            </div>

            <div class="notif-actions">
                <button class="btn-decline" onclick="declineOrder()">Recusar</button>
                <button class="btn-accept" onclick="acceptOrder()">Aceitar Pedido</button>
            </div>
        </div>
    </div>

    <!-- MINI TOAST -->
    <div class="mini-toast" id="mini-toast" onclick="expandNotification()">
        <div class="toast-pulse"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg></div>
        <div class="toast-content">
            <div class="toast-title">Novo pedido disponível!</div>
            <div class="toast-subtitle">Toque para ver detalhes</div>
        </div>
        <div class="toast-value" id="toast-value">R$65</div>
    </div>

    <!-- MAIN CONTENT -->
    <main class="main-content">
        <div class="status-header">
            <h1>Olá, <?= htmlspecialchars($nome) ?>! 👋</h1>
            <div class="status-badge">
                <span class="status-dot"></span>
                <?= $is_online ? 'Você está Online' : 'Você está Offline' ?>
            </div>
        </div>

        <?php if ($is_online): ?>
        <div class="waiting-animation">
            <div class="radar-container">
                <div class="radar-ring"></div>
                <div class="radar-ring"></div>
                <div class="radar-ring"></div>
                <div class="radar-center"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg></div>
            </div>
            <div class="waiting-text">
                <h2>Buscando pedidos...</h2>
                <p>Aguarde, estamos procurando o melhor pedido para você</p>
            </div>
        </div>

        <button class="demo-btn" onclick="simulateNewOrder()">
            🧪 Simular Novo Pedido (Demo)
        </button>
        <?php else: ?>
        <div class="offline-state">
            <div class="offline-icon">😴</div>
            <h2>Você está offline</h2>
            <p>Fique online para começar a receber pedidos e ganhar dinheiro</p>
            <button class="btn-go-online" onclick="goOnline()">Ficar Online</button>
        </div>
        <?php endif; ?>
    </main>

    <!-- AUDIO NOTIFICATION -->
    <audio id="notification-sound" preload="auto">
        <source src="data:audio/mp3;base64,SUQzBAAAAAAAI1RTU0UAAAAPAAADTGF2ZjU4Ljc2LjEwMAAAAAAAAAAAAAAA//tQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAWGluZwAAAA8AAAACAAABhgC7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7//////////////////////////////////////////////////////////////////8AAAAATGF2YzU4LjEzAAAAAAAAAAAAAAAAJAAAAAAAAAAAAYYNfcpPAAAAAAD/+9DEAAAIAANIAAAAEyYAaRgAACEAAAAATDAQBAIBQEAQDH/B98H+D4Pg+D4OAgGP/9YPg+D5//WD4Pg+CAIAgD//rB8HwfB8HwQBAEAfWIAAoWi0Wm0mm1Wu2G02+43W63nA4nG5HK5nO6HS6na7Xc73g8Xk83o9Xs93w+Xy+34/n++H8/4A=" type="audio/mp3">
    </audio>

    <script>
    let timerInterval = null;
    let currentOrder = null;
    let secondsLeft = 30;
    const TIMER_TOTAL = 30;
    const circumference = 2 * Math.PI * 54; // 339.292

    // Pedidos de exemplo
    const sampleOrders = [
        {
            id: 1,
            store: 'Pão de Açúcar',
            storeColor: '#43B02A',
            address: 'Av. Paulista, 1500',
            customer: 'Marina Costa',
            customerAddress: 'Jardins, São Paulo',
            customerRating: 4.9,
            items: 18,
            distance: 3.2,
            time: 35,
            type: 'Full',
            base: 45,
            tip: 12,
            bonus: 8
        },
        {
            id: 2,
            store: 'Carrefour',
            storeColor: '#004E9A',
            address: 'Rua Augusta, 2300',
            customer: 'Ricardo Mendes',
            customerAddress: 'Consolação, São Paulo',
            customerRating: 4.7,
            items: 12,
            distance: 2.1,
            time: 25,
            type: 'Shop',
            base: 28,
            tip: 8,
            bonus: 0
        },
        {
            id: 3,
            store: 'Extra',
            storeColor: '#E31837',
            address: 'Av. Rebouças, 800',
            customer: 'Ana Paula',
            customerAddress: 'Pinheiros, São Paulo',
            customerRating: 5.0,
            items: 8,
            distance: 4.5,
            time: 20,
            type: 'Delivery',
            base: 22,
            tip: 5,
            bonus: 3
        }
    ];

    function simulateNewOrder() {
        // Escolher pedido aleatório
        const order = sampleOrders[Math.floor(Math.random() * sampleOrders.length)];
        currentOrder = order;
        
        // Preencher dados
        document.getElementById('store-name').textContent = order.store;
        document.getElementById('store-address').textContent = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg> ' + order.address;
        document.getElementById('store-logo').textContent = order.store[0];
        document.getElementById('store-logo').style.background = `linear-gradient(145deg, ${order.storeColor}, ${adjustColor(order.storeColor, -20)})`;
        
        document.getElementById('customer-name').textContent = order.customer;
        document.getElementById('customer-address').textContent = order.customerAddress;
        document.getElementById('customer-avatar').textContent = order.customer[0];
        document.getElementById('customer-rating').textContent = order.customerRating;
        
        document.getElementById('detail-items').textContent = order.items;
        document.getElementById('detail-distance').textContent = order.distance + 'km';
        document.getElementById('detail-time').textContent = order.time + 'min';
        document.getElementById('detail-type').textContent = order.type;
        
        const total = order.base + order.tip + order.bonus;
        document.getElementById('earnings-total').textContent = 'R$ ' + total.toFixed(2).replace('.', ',');
        document.getElementById('earnings-base').textContent = order.base;
        document.getElementById('earnings-tip').textContent = order.tip;
        document.getElementById('earnings-bonus').textContent = order.bonus;
        document.getElementById('toast-value').textContent = 'R$' + total;
        
        // Mostrar notificação
        showNotification();
    }

    function showNotification() {
        // Tocar som
        playNotificationSound();
        
        // Vibrar
        if ('vibrate' in navigator) {
            navigator.vibrate([200, 100, 200, 100, 200]);
        }
        
        // Resetar timer
        secondsLeft = TIMER_TOTAL;
        updateTimerDisplay();
        
        // Mostrar overlay
        document.getElementById('notification-overlay').classList.add('active');
        
        // Iniciar countdown
        startTimer();
        
        // Tentar enviar push notification
        sendPushNotification();
    }

    function startTimer() {
        if (timerInterval) clearInterval(timerInterval);
        
        timerInterval = setInterval(() => {
            secondsLeft--;
            updateTimerDisplay();
            
            if (secondsLeft <= 0) {
                clearInterval(timerInterval);
                hideNotification();
                showMissedToast();
            }
        }, 1000);
    }

    function updateTimerDisplay() {
        const timerRing = document.getElementById('timer-ring');
        const progress = document.getElementById('timer-progress');
        const seconds = document.getElementById('timer-seconds');
        
        // Atualizar texto
        seconds.textContent = secondsLeft;
        
        // Atualizar círculo
        const offset = circumference * (1 - secondsLeft / TIMER_TOTAL);
        progress.style.strokeDashoffset = offset;
        
        // Mudar cor conforme tempo
        timerRing.classList.remove('warning', 'critical');
        if (secondsLeft <= 10) {
            timerRing.classList.add('critical');
        } else if (secondsLeft <= 15) {
            timerRing.classList.add('warning');
        }
    }

    function hideNotification() {
        document.getElementById('notification-overlay').classList.remove('active');
        if (timerInterval) clearInterval(timerInterval);
    }

    function showMissedToast() {
        // Mostrar toast de pedido perdido
        alert('⏰ Tempo esgotado! O pedido foi oferecido a outro entregador.');
    }

    function acceptOrder() {
        if ('vibrate' in navigator) {
            navigator.vibrate([50, 50, 100]);
        }
        
        hideNotification();
        
        // Redirecionar para shopping.php
        window.location.href = 'shopping.php?id=' + currentOrder.id;
    }

    function declineOrder() {
        if ('vibrate' in navigator) {
            navigator.vibrate(100);
        }
        
        hideNotification();
    }

    function expandNotification() {
        document.getElementById('mini-toast').classList.remove('active');
        document.getElementById('notification-overlay').classList.add('active');
    }

    function playNotificationSound() {
        const audio = document.getElementById('notification-sound');
        
        // Criar som via Web Audio API
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            
            // Criar sequência de beeps
            const playBeep = (freq, start, duration) => {
                const oscillator = audioContext.createOscillator();
                const gainNode = audioContext.createGain();
                
                oscillator.connect(gainNode);
                gainNode.connect(audioContext.destination);
                
                oscillator.frequency.value = freq;
                oscillator.type = 'sine';
                
                gainNode.gain.setValueAtTime(0.3, audioContext.currentTime + start);
                gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + start + duration);
                
                oscillator.start(audioContext.currentTime + start);
                oscillator.stop(audioContext.currentTime + start + duration);
            };
            
            // Som estilo Uber - 3 tons ascendentes
            playBeep(523.25, 0, 0.15);    // C5
            playBeep(659.25, 0.15, 0.15); // E5
            playBeep(783.99, 0.30, 0.25); // G5
            
        } catch (e) {
            console.log('Audio not supported');
        }
    }

    function sendPushNotification() {
        if ('Notification' in window && Notification.permission === 'granted') {
            const total = currentOrder.base + currentOrder.tip + currentOrder.bonus;
            new Notification('<svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg> Novo Pedido!', {
                body: `${currentOrder.store} - R$ ${total.toFixed(2)} - ${currentOrder.items} itens`,
                icon: '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>',
                badge: '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>',
                tag: 'new-order',
                requireInteraction: true,
                vibrate: [200, 100, 200]
            });
        }
    }

    function goOnline() {
        fetch('api/toggle-online.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ online: true })
        }).then(() => location.reload());
    }

    function adjustColor(color, amount) {
        const num = parseInt(color.replace('#', ''), 16);
        const r = Math.max(0, Math.min(255, (num >> 16) + amount));
        const g = Math.max(0, Math.min(255, ((num >> 8) & 0x00FF) + amount));
        const b = Math.max(0, Math.min(255, (num & 0x0000FF) + amount));
        return '#' + (0x1000000 + r * 0x10000 + g * 0x100 + b).toString(16).slice(1);
    }

    // Pedir permissão para notificações
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
    }

    // Simular pedidos periodicamente quando online (para demo)
    <?php if ($is_online): ?>
    // Auto-simular após 10 segundos para demonstração
    setTimeout(() => {
        simulateNewOrder();
    }, 10000);
    <?php endif; ?>
    </script>

<script>
// Header scroll effect
(function() {
    const header = document.querySelector('.header, .site-header, [class*="header-main"]');
    if (!header) return;
    
    let lastScroll = 0;
    let ticking = false;
    
    function updateHeader() {
        const currentScroll = window.pageYOffset;
        
        if (currentScroll > 50) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
        
        // Hide/show on scroll (opcional)
        /*
        if (currentScroll > lastScroll && currentScroll > 100) {
            header.style.transform = 'translateY(-100%)';
        } else {
            header.style.transform = 'translateY(0)';
        }
        */
        
        lastScroll = currentScroll;
        ticking = false;
    }
    
    window.addEventListener('scroll', function() {
        if (!ticking) {
            requestAnimationFrame(updateHeader);
            ticking = true;
        }
    });
    
    // Cart badge animation
    window.animateCartBadge = function() {
        const badge = document.querySelector('.cart-badge, .carrinho-badge, [class*="cart-count"]');
        if (badge) {
            badge.style.transform = 'scale(1.3)';
            setTimeout(() => {
                badge.style.transform = 'scale(1)';
            }, 200);
        }
    };
    
    // Mobile search toggle
    const searchToggle = document.querySelector('.search-toggle, [class*="search-btn"]');
    const searchContainer = document.querySelector('.search-container, .search-box');
    
    if (searchToggle && searchContainer) {
        searchToggle.addEventListener('click', function() {
            searchContainer.classList.toggle('active');
        });
    }
})();
</script>

<!-- ═══════════════════════════════════════════════════════════════════════════════
     🎨 ONEMUNDO HEADER PREMIUM v3.0 - CSS FINAL UNIFICADO
═══════════════════════════════════════════════════════════════════════════════ -->
<style id="om-header-final">
/* RESET */
.mkt-header, .mkt-header-row, .mkt-logo, .mkt-logo-box, .mkt-logo-text,
.mkt-user, .mkt-user-avatar, .mkt-guest, .mkt-cart, .mkt-cart-count, .mkt-search,
.om-topbar, .om-topbar-main, .om-topbar-icon, .om-topbar-content,
.om-topbar-label, .om-topbar-address, .om-topbar-arrow, .om-topbar-time {
    all: revert;
}

/* TOPBAR VERDE */
.om-topbar {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding: 14px 20px !important;
    background: linear-gradient(135deg, #047857 0%, #059669 40%, #10b981 100%) !important;
    color: #fff !important;
    cursor: pointer !important;
    transition: all 0.3s ease !important;
    position: relative !important;
    overflow: hidden !important;
}

.om-topbar::before {
    content: '' !important;
    position: absolute !important;
    top: 0 !important;
    left: -100% !important;
    width: 100% !important;
    height: 100% !important;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent) !important;
    transition: left 0.6s ease !important;
}

.om-topbar:hover::before { left: 100% !important; }
.om-topbar:hover { background: linear-gradient(135deg, #065f46 0%, #047857 40%, #059669 100%) !important; }

.om-topbar-main {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    flex: 1 !important;
    min-width: 0 !important;
}

.om-topbar-icon {
    width: 40px !important;
    height: 40px !important;
    background: rgba(255,255,255,0.18) !important;
    border-radius: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    backdrop-filter: blur(10px) !important;
    transition: all 0.3s ease !important;
}

.om-topbar:hover .om-topbar-icon {
    background: rgba(255,255,255,0.25) !important;
    transform: scale(1.05) !important;
}

.om-topbar-icon svg { width: 20px !important; height: 20px !important; color: #fff !important; }

.om-topbar-content { flex: 1 !important; min-width: 0 !important; }

.om-topbar-label {
    font-size: 11px !important;
    font-weight: 500 !important;
    opacity: 0.85 !important;
    margin-bottom: 2px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    display: block !important;
}

.om-topbar-address {
    font-size: 14px !important;
    font-weight: 700 !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    max-width: 220px !important;
}

.om-topbar-arrow {
    width: 32px !important;
    height: 32px !important;
    background: rgba(255,255,255,0.12) !important;
    border-radius: 8px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    transition: all 0.3s ease !important;
    margin-right: 12px !important;
}

.om-topbar:hover .om-topbar-arrow {
    background: rgba(255,255,255,0.2) !important;
    transform: translateX(3px) !important;
}

.om-topbar-arrow svg { width: 16px !important; height: 16px !important; color: #fff !important; }

.om-topbar-time {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    padding: 8px 14px !important;
    background: rgba(0,0,0,0.2) !important;
    border-radius: 50px !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    flex-shrink: 0 !important;
    backdrop-filter: blur(10px) !important;
    transition: all 0.3s ease !important;
}

.om-topbar-time:hover { background: rgba(0,0,0,0.3) !important; transform: scale(1.02) !important; }
.om-topbar-time svg { width: 16px !important; height: 16px !important; color: #34d399 !important; }

/* HEADER BRANCO */
.mkt-header {
    background: #ffffff !important;
    padding: 0 !important;
    position: sticky !important;
    top: 0 !important;
    z-index: 9999 !important;
    box-shadow: 0 2px 20px rgba(0,0,0,0.08) !important;
    border-bottom: none !important;
}

.mkt-header-row {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 14px 20px !important;
    margin-bottom: 0 !important;
    background: #fff !important;
    border-bottom: 1px solid rgba(0,0,0,0.06) !important;
}

/* LOGO */
.mkt-logo {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    text-decoration: none !important;
    flex-shrink: 0 !important;
}

.mkt-logo-box {
    width: 44px !important;
    height: 44px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border-radius: 14px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 22px !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35) !important;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}

.mkt-logo:hover .mkt-logo-box {
    transform: scale(1.05) rotate(-3deg) !important;
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.45) !important;
}

.mkt-logo-text {
    font-size: 20px !important;
    font-weight: 800 !important;
    color: #10b981 !important;
    letter-spacing: -0.02em !important;
}

/* USER */
.mkt-user { margin-left: auto !important; text-decoration: none !important; }

.mkt-user-avatar {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 42px !important;
    height: 42px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border-radius: 50% !important;
    color: #fff !important;
    font-weight: 700 !important;
    font-size: 16px !important;
    box-shadow: 0 3px 12px rgba(16, 185, 129, 0.3) !important;
    transition: all 0.3s ease !important;
}

.mkt-user-avatar:hover {
    transform: scale(1.08) !important;
    box-shadow: 0 5px 18px rgba(16, 185, 129, 0.4) !important;
}

.mkt-user.mkt-guest {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 42px !important;
    height: 42px !important;
    background: #f1f5f9 !important;
    border-radius: 12px !important;
    transition: all 0.3s ease !important;
}

.mkt-user.mkt-guest:hover { background: #e2e8f0 !important; }
.mkt-user.mkt-guest svg { width: 24px !important; height: 24px !important; color: #64748b !important; }

/* CARRINHO */
.mkt-cart {
    position: relative !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 46px !important;
    height: 46px !important;
    background: linear-gradient(135deg, #1e293b, #0f172a) !important;
    border: none !important;
    border-radius: 14px !important;
    cursor: pointer !important;
    flex-shrink: 0 !important;
    box-shadow: 0 4px 15px rgba(15, 23, 42, 0.25) !important;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}

.mkt-cart:hover {
    transform: translateY(-3px) scale(1.02) !important;
    box-shadow: 0 8px 25px rgba(15, 23, 42, 0.3) !important;
}

.mkt-cart:active { transform: translateY(-1px) scale(0.98) !important; }
.mkt-cart svg { width: 22px !important; height: 22px !important; color: #fff !important; }

.mkt-cart-count {
    position: absolute !important;
    top: -6px !important;
    right: -6px !important;
    min-width: 22px !important;
    height: 22px !important;
    padding: 0 6px !important;
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    border-radius: 11px !important;
    color: #fff !important;
    font-size: 11px !important;
    font-weight: 800 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    border: 2px solid #fff !important;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4) !important;
    animation: cartPulse 2s ease-in-out infinite !important;
}

@keyframes cartPulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }

/* BUSCA */
.mkt-search {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    background: #f1f5f9 !important;
    border-radius: 14px !important;
    padding: 0 16px !important;
    margin: 0 16px 16px !important;
    border: 2px solid transparent !important;
    transition: all 0.3s ease !important;
}

.mkt-search:focus-within {
    background: #fff !important;
    border-color: #10b981 !important;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1) !important;
}

.mkt-search svg {
    width: 20px !important;
    height: 20px !important;
    color: #94a3b8 !important;
    flex-shrink: 0 !important;
    transition: color 0.3s ease !important;
}

.mkt-search:focus-within svg { color: #10b981 !important; }

.mkt-search input {
    flex: 1 !important;
    border: none !important;
    background: transparent !important;
    font-size: 15px !important;
    font-weight: 500 !important;
    color: #1e293b !important;
    outline: none !important;
    padding: 14px 0 !important;
    width: 100% !important;
}

.mkt-search input::placeholder { color: #94a3b8 !important; }

/* RESPONSIVO */
@media (max-width: 480px) {
    .om-topbar { padding: 12px 16px !important; }
    .om-topbar-icon { width: 36px !important; height: 36px !important; }
    .om-topbar-address { max-width: 150px !important; font-size: 13px !important; }
    .om-topbar-arrow { display: none !important; }
    .om-topbar-time { padding: 6px 10px !important; font-size: 11px !important; }
    .mkt-header-row { padding: 12px 16px !important; }
    .mkt-logo-box { width: 40px !important; height: 40px !important; font-size: 18px !important; }
    .mkt-logo-text { font-size: 18px !important; }
    .mkt-cart { width: 42px !important; height: 42px !important; }
    .mkt-search { margin: 0 12px 12px !important; }
    .mkt-search input { font-size: 14px !important; padding: 12px 0 !important; }
}

/* ANIMAÇÕES */
@keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
.mkt-header { animation: slideDown 0.4s ease !important; }

::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
::selection { background: rgba(16, 185, 129, 0.2); color: #047857; }
</style>

<script>
(function() {
    var h = document.querySelector('.mkt-header');
    if (h && !document.querySelector('.om-topbar')) {
        var t = document.createElement('div');
        t.className = 'om-topbar';
        t.innerHTML = '<div class="om-topbar-main"><div class="om-topbar-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg></div><div class="om-topbar-content"><div class="om-topbar-label">Entregar em</div><div class="om-topbar-address" id="omAddrFinal">Carregando...</div></div><div class="om-topbar-arrow"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></div></div><div class="om-topbar-time"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>25-35 min</div>';
        h.insertBefore(t, h.firstChild);
        fetch('/mercado/api/address.php?action=list').then(r=>r.json()).then(d=>{var el=document.getElementById('omAddrFinal');if(el&&d.current)el.textContent=d.current.address_1||'Selecionar';}).catch(()=>{});
    }
    var l = document.querySelector('.mkt-logo');
    if (l && !l.querySelector('.mkt-logo-text')) {
        var s = document.createElement('span');
        s.className = 'mkt-logo-text';
        s.textContent = 'Mercado';
        l.appendChild(s);
    }
})();
</script>
</body>
</html>
