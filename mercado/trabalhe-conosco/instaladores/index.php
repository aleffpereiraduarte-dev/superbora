<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════════════╗
 * ║          🚀 INSTALADOR COMPLETO - ONEMUNDO WORKERS v3.0                              ║
 * ║                   Shopper / Delivery / Full Service                                  ║
 * ╚══════════════════════════════════════════════════════════════════════════════════════╝
 */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OneMundo Workers - Instalador</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0a0a0a;
            --card: #111;
            --border: #222;
            --text: #fff;
            --text2: #888;
            --green: #10b981;
            --orange: #f59e0b;
            --purple: #8b5cf6;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding: 40px 20px;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .header {
            text-align: center;
            margin-bottom: 50px;
        }
        
        .logo {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, var(--green), #059669);
            border-radius: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 50px;
            margin: 0 auto 20px;
            box-shadow: 0 10px 40px rgba(16, 185, 129, 0.3);
        }
        
        h1 {
            font-size: 36px;
            font-weight: 800;
            margin-bottom: 10px;
        }
        
        .subtitle {
            color: var(--text2);
            font-size: 18px;
        }
        
        .types {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin: 40px 0;
            flex-wrap: wrap;
        }
        
        .type-card {
            background: var(--card);
            border: 2px solid var(--border);
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            width: 200px;
            transition: all 0.3s;
        }
        
        .type-card.shopper:hover { border-color: var(--green); transform: translateY(-5px); }
        .type-card.delivery:hover { border-color: var(--orange); transform: translateY(-5px); }
        .type-card.fullservice:hover { border-color: var(--purple); transform: translateY(-5px); }
        
        .type-icon { font-size: 48px; margin-bottom: 16px; }
        .type-name { font-size: 18px; font-weight: 700; }
        .type-card.shopper .type-name { color: var(--green); }
        .type-card.delivery .type-name { color: var(--orange); }
        .type-card.fullservice .type-name { color: var(--purple); }
        .type-desc { font-size: 13px; color: var(--text2); margin-top: 8px; }
        
        .installers {
            margin-top: 50px;
        }
        
        .installer-item {
            display: flex;
            align-items: center;
            gap: 20px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px 24px;
            margin-bottom: 12px;
            text-decoration: none;
            color: var(--text);
            transition: all 0.2s;
        }
        
        .installer-item:hover {
            border-color: var(--green);
            transform: translateX(8px);
        }
        
        .installer-number {
            width: 48px;
            height: 48px;
            background: var(--green);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: 800;
            color: #000;
        }
        
        .installer-info { flex: 1; }
        .installer-title { font-size: 16px; font-weight: 600; margin-bottom: 4px; }
        .installer-desc { font-size: 13px; color: var(--text2); }
        .installer-arrow { font-size: 24px; color: var(--text2); }
        
        .start-btn {
            display: block;
            background: linear-gradient(135deg, var(--green), #059669);
            color: #fff;
            text-decoration: none;
            padding: 20px;
            border-radius: 16px;
            text-align: center;
            font-size: 18px;
            font-weight: 700;
            margin-top: 40px;
            transition: all 0.2s;
        }
        
        .start-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(16, 185, 129, 0.3);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">🚀</div>
            <h1>OneMundo Workers</h1>
            <p class="subtitle">Sistema Completo de Shopper, Delivery e Full Service</p>
        </div>
        
        <div class="types">
            <div class="type-card shopper">
                <div class="type-icon">🛒</div>
                <div class="type-name">SHOPPER</div>
                <div class="type-desc">Faz compras no supermercado</div>
            </div>
            <div class="type-card delivery">
                <div class="type-icon">🚴</div>
                <div class="type-name">DELIVERY</div>
                <div class="type-desc">Entrega pedidos aos clientes</div>
            </div>
            <div class="type-card fullservice">
                <div class="type-icon">⭐</div>
                <div class="type-name">FULL SERVICE</div>
                <div class="type-desc">Faz compras + entregas</div>
            </div>
        </div>
        
        <div class="installers">
            <a href="01_instalar_tabelas.php" class="installer-item">
                <div class="installer-number">01</div>
                <div class="installer-info">
                    <div class="installer-title">📦 Tabelas do Banco</div>
                    <div class="installer-desc">Criar todas as 16 tabelas necessárias</div>
                </div>
                <span class="installer-arrow">→</span>
            </a>
            
            <a href="02_instalar_ferramentas_shopper.php" class="installer-item">
                <div class="installer-number">02</div>
                <div class="installer-info">
                    <div class="installer-title">🛒 Dashboard Shopper</div>
                    <div class="installer-desc">Interface de compras estilo Instacart</div>
                </div>
                <span class="installer-arrow">→</span>
            </a>
            
            <a href="03_instalar_dashboard_delivery.php" class="installer-item">
                <div class="installer-number">03</div>
                <div class="installer-info">
                    <div class="installer-title">🚴 Dashboard Delivery</div>
                    <div class="installer-desc">Interface de entregas estilo Uber</div>
                </div>
                <span class="installer-arrow">→</span>
            </a>
            
            <a href="04_instalar_dashboard_fullservice.php" class="installer-item">
                <div class="installer-number">04</div>
                <div class="installer-info">
                    <div class="installer-title">⭐ Dashboard Full Service</div>
                    <div class="installer-desc">Interface unificada (compras + entregas)</div>
                </div>
                <span class="installer-arrow">→</span>
            </a>
            
            <a href="05_instalar_login_cadastro.php" class="installer-item">
                <div class="installer-number">05</div>
                <div class="installer-info">
                    <div class="installer-title">🔐 Login e Cadastro</div>
                    <div class="installer-desc">Autenticação e wizard de cadastro</div>
                </div>
                <span class="installer-arrow">→</span>
            </a>
            
            <a href="06_instalar_apis.php" class="installer-item">
                <div class="installer-number">06</div>
                <div class="installer-info">
                    <div class="installer-title">📡 APIs do Sistema</div>
                    <div class="installer-desc">24 endpoints para todas as funcionalidades</div>
                </div>
                <span class="installer-arrow">→</span>
            </a>
            
            <a href="07_instalar_gamificacao.php" class="installer-item">
                <div class="installer-number">07</div>
                <div class="installer-info">
                    <div class="installer-title">🏆 Gamificação</div>
                    <div class="installer-desc">Desafios, ranking, badges e XP</div>
                </div>
                <span class="installer-arrow">→</span>
            </a>
            
            <a href="08_instalar_notificacoes.php" class="installer-item">
                <div class="installer-number">08</div>
                <div class="installer-info">
                    <div class="installer-title">🔔 Notificações</div>
                    <div class="installer-desc">Push, SMS, email e in-app</div>
                </div>
                <span class="installer-arrow">→</span>
            </a>
            
            <a href="09_instalar_painel_rh.php" class="installer-item">
                <div class="installer-number">09</div>
                <div class="installer-info">
                    <div class="installer-title">👔 Painel RH</div>
                    <div class="installer-desc">Aprovar/rejeitar workers</div>
                </div>
                <span class="installer-arrow">→</span>
            </a>
            
            <a href="10_instalar_finalizacao.php" class="installer-item">
                <div class="installer-number">10</div>
                <div class="installer-info">
                    <div class="installer-title">🎉 Finalização</div>
                    <div class="installer-desc">Resumo e testes</div>
                </div>
                <span class="installer-arrow">→</span>
            </a>
        </div>
        
        <a href="01_instalar_tabelas.php" class="start-btn">
            🚀 Iniciar Instalação Completa
        </a>
    </div>
</body>
</html>
