<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════════════════╗
 * ║           🚀 TRABALHE CONOSCO - ONEMUNDO MARKET                                          ║
 * ╠══════════════════════════════════════════════════════════════════════════════════════════╣
 * ║  Landing page estilo DoorDash/Instacart para cadastro de:                                ║
 * ║  • 🛒 Shoppers (fazem compras)                                                           ║
 * ║  • 🚴 Drivers/Entregadores (fazem entregas)                                              ║
 * ║  • ⭐ Full Service (fazem os dois!)                                                       ║
 * ╚══════════════════════════════════════════════════════════════════════════════════════════╝
 */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trabalhe Conosco - OneMundo Market</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --shopper: #10b981;
            --driver: #f97316;
            --full: #8b5cf6;
            --dark: #0f172a;
            --gray: #64748b;
            --light: #f8fafc;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', sans-serif;
            background: var(--light);
            color: var(--dark);
            min-height: 100vh;
        }
        
        /* Header */
        .header {
            background: var(--dark);
            padding: 15px 20px;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 100;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            font-size: 1.5rem;
            font-weight: 800;
            color: #fff;
        }
        
        .logo span { color: var(--shopper); }
        
        .header-btn {
            padding: 10px 20px;
            background: var(--shopper);
            color: #fff;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        /* Hero */
        .hero {
            padding: 120px 20px 60px;
            background: linear-gradient(135deg, var(--dark) 0%, #1e293b 100%);
            text-align: center;
            color: #fff;
        }
        
        .hero h1 {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 15px;
            line-height: 1.2;
        }
        
        .hero h1 span {
            background: linear-gradient(135deg, var(--shopper), var(--full));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .hero p {
            font-size: 1.1rem;
            color: #94a3b8;
            max-width: 500px;
            margin: 0 auto 30px;
        }
        
        .hero-stats {
            display: flex;
            justify-content: center;
            gap: 40px;
            margin-top: 40px;
        }
        
        .hero-stat {
            text-align: center;
        }
        
        .hero-stat .value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--shopper);
        }
        
        .hero-stat .label {
            font-size: 0.85rem;
            color: #94a3b8;
        }
        
        /* Options */
        .options {
            padding: 60px 20px;
            max-width: 1000px;
            margin: 0 auto;
        }
        
        .options h2 {
            text-align: center;
            font-size: 1.8rem;
            margin-bottom: 40px;
        }
        
        .options-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
        }
        
        .option-card {
            background: #fff;
            border-radius: 24px;
            padding: 35px 25px;
            text-align: center;
            text-decoration: none;
            color: inherit;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 3px solid transparent;
            position: relative;
            overflow: hidden;
        }
        
        .option-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.15);
        }
        
        .option-card.shopper:hover { border-color: var(--shopper); }
        .option-card.driver:hover { border-color: var(--driver); }
        .option-card.full:hover { border-color: var(--full); }
        
        .option-card .icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            margin: 0 auto 20px;
        }
        
        .option-card.shopper .icon { background: rgba(16, 185, 129, 0.15); }
        .option-card.driver .icon { background: rgba(249, 115, 22, 0.15); }
        .option-card.full .icon { background: rgba(139, 92, 246, 0.15); }
        
        .option-card h3 {
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .option-card.shopper h3 { color: var(--shopper); }
        .option-card.driver h3 { color: var(--driver); }
        .option-card.full h3 { color: var(--full); }
        
        .option-card .desc {
            color: var(--gray);
            font-size: 0.95rem;
            margin-bottom: 20px;
            line-height: 1.5;
        }
        
        .option-card .earnings {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 20px;
        }
        
        .option-card.shopper .earnings { color: var(--shopper); }
        .option-card.driver .earnings { color: var(--driver); }
        .option-card.full .earnings { color: var(--full); }
        
        .option-card .btn {
            display: inline-block;
            padding: 14px 30px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.95rem;
            color: #fff;
            transition: all 0.3s;
        }
        
        .option-card.shopper .btn { background: var(--shopper); }
        .option-card.driver .btn { background: var(--driver); }
        .option-card.full .btn { background: var(--full); }
        
        .option-card .btn:hover { opacity: 0.9; transform: scale(1.02); }
        
        .option-card .tag {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            color: #fff;
        }
        
        .option-card.full .tag {
            background: linear-gradient(135deg, var(--full), #a855f7);
        }
        
        /* Benefits */
        .benefits {
            padding: 60px 20px;
            background: #fff;
        }
        
        .benefits h2 {
            text-align: center;
            font-size: 1.8rem;
            margin-bottom: 40px;
        }
        
        .benefits-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            max-width: 1000px;
            margin: 0 auto;
        }
        
        .benefit {
            text-align: center;
            padding: 25px;
        }
        
        .benefit .icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
        }
        
        .benefit h3 {
            font-size: 1.1rem;
            margin-bottom: 10px;
        }
        
        .benefit p {
            color: var(--gray);
            font-size: 0.9rem;
            line-height: 1.5;
        }
        
        /* Requirements */
        .requirements {
            padding: 60px 20px;
            background: var(--light);
        }
        
        .requirements h2 {
            text-align: center;
            font-size: 1.8rem;
            margin-bottom: 40px;
        }
        
        .requirements-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            max-width: 800px;
            margin: 0 auto;
        }
        
        .requirement {
            background: #fff;
            padding: 20px;
            border-radius: 16px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .requirement .icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .requirement p {
            font-size: 0.9rem;
            color: var(--dark);
        }
        
        /* How it works */
        .how-it-works {
            padding: 60px 20px;
            background: #fff;
        }
        
        .how-it-works h2 {
            text-align: center;
            font-size: 1.8rem;
            margin-bottom: 40px;
        }
        
        .steps {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 30px;
            max-width: 900px;
            margin: 0 auto;
        }
        
        .step {
            text-align: center;
            flex: 1;
            min-width: 200px;
            max-width: 250px;
        }
        
        .step .number {
            width: 50px;
            height: 50px;
            background: var(--shopper);
            color: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            font-weight: 700;
            margin: 0 auto 15px;
        }
        
        .step h3 {
            font-size: 1rem;
            margin-bottom: 8px;
        }
        
        .step p {
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        /* CTA */
        .cta {
            padding: 80px 20px;
            background: linear-gradient(135deg, var(--dark), #1e293b);
            text-align: center;
            color: #fff;
        }
        
        .cta h2 {
            font-size: 2rem;
            margin-bottom: 15px;
        }
        
        .cta p {
            color: #94a3b8;
            margin-bottom: 30px;
        }
        
        .cta-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .cta-buttons a {
            padding: 16px 32px;
            border-radius: 14px;
            font-weight: 600;
            text-decoration: none;
            font-size: 1rem;
            transition: all 0.3s;
        }
        
        .cta-buttons .primary {
            background: var(--shopper);
            color: #fff;
        }
        
        .cta-buttons .secondary {
            background: rgba(255,255,255,0.1);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        /* Footer */
        .footer {
            padding: 30px 20px;
            background: var(--dark);
            text-align: center;
            color: #64748b;
            font-size: 0.85rem;
        }
        
        /* Login link */
        .login-link {
            text-align: center;
            padding: 20px;
            background: #fff;
            border-top: 1px solid #e2e8f0;
        }
        
        .login-link a {
            color: var(--shopper);
            text-decoration: none;
            font-weight: 600;
        }
        
        @media (max-width: 600px) {
            .hero h1 { font-size: 1.8rem; }
            .hero-stats { gap: 20px; }
            .options-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="logo">One<span>Mundo</span></div>
        <a href="login.php" class="header-btn">Já tenho conta</a>
    </header>
    
    <!-- Hero -->
    <section class="hero">
        <h1>Ganhe dinheiro <span>no seu tempo</span></h1>
        <p>Seja um parceiro OneMundo e trabalhe quando quiser. Faça compras, entregas ou os dois!</p>
        
        <div class="hero-stats">
            <div class="hero-stat">
                <div class="value">R$ 3.500+</div>
                <div class="label">Média mensal</div>
            </div>
            <div class="hero-stat">
                <div class="value">500+</div>
                <div class="label">Parceiros ativos</div>
            </div>
            <div class="hero-stat">
                <div class="value">27</div>
                <div class="label">Cidades</div>
            </div>
        </div>
    </section>
    
    <!-- Options -->
    <section class="options">
        <h2>Escolha como quer trabalhar</h2>
        
        <div class="options-grid">
            <!-- Shopper -->
            <a href="cadastro.php?tipo=shopper" class="option-card shopper">
                <div class="icon">🛒</div>
                <h3>SHOPPER</h3>
                <p class="desc">Faça compras nos supermercados parceiros e prepare os pedidos dos clientes</p>
                <div class="earnings">Até R$ 25/pedido</div>
                <span class="btn">Quero ser Shopper →</span>
            </a>
            
            <!-- Driver -->
            <a href="cadastro.php?tipo=driver" class="option-card driver">
                <div class="icon">🚴</div>
                <h3>ENTREGADOR</h3>
                <p class="desc">Entregue os pedidos diretamente na casa dos clientes com agilidade</p>
                <div class="earnings">Até R$ 18/entrega</div>
                <span class="btn">Quero ser Entregador →</span>
            </a>
            
            <!-- Full Service -->
            <a href="cadastro.php?tipo=full" class="option-card full">
                <span class="tag">⭐ GANHE MAIS</span>
                <div class="icon">🌟</div>
                <h3>FULL SERVICE</h3>
                <p class="desc">Faça as compras E entregue! Ganhe os dois valores e maximize seus ganhos</p>
                <div class="earnings">Até R$ 43/pedido</div>
                <span class="btn">Quero ser Full Service →</span>
            </a>
        </div>
    </section>
    
    <!-- Benefits -->
    <section class="benefits">
        <h2>Por que ser parceiro OneMundo?</h2>
        
        <div class="benefits-grid">
            <div class="benefit">
                <div class="icon">⏰</div>
                <h3>Horário Flexível</h3>
                <p>Trabalhe quando quiser, sem horários fixos. Você decide sua rotina.</p>
            </div>
            <div class="benefit">
                <div class="icon">💰</div>
                <h3>Pagamento Semanal</h3>
                <p>Receba toda semana direto na sua conta. Sem enrolação.</p>
            </div>
            <div class="benefit">
                <div class="icon">📈</div>
                <h3>Bonificações</h3>
                <p>Ganhe bônus por desempenho, desafios diários e indicações.</p>
            </div>
            <div class="benefit">
                <div class="icon">🎮</div>
                <h3>Gamificação</h3>
                <p>Suba de nível, ganhe badges e desbloqueie benefícios exclusivos.</p>
            </div>
        </div>
    </section>
    
    <!-- Requirements -->
    <section class="requirements">
        <h2>O que você precisa</h2>
        
        <div class="requirements-grid">
            <div class="requirement">
                <div class="icon">🎂</div>
                <p>Ter 18 anos ou mais</p>
            </div>
            <div class="requirement">
                <div class="icon">📱</div>
                <p>Smartphone com internet</p>
            </div>
            <div class="requirement">
                <div class="icon">📄</div>
                <p>CPF e RG válidos</p>
            </div>
            <div class="requirement">
                <div class="icon">🏠</div>
                <p>Comprovante de residência</p>
            </div>
            <div class="requirement">
                <div class="icon">🏍️</div>
                <p>CNH + Veículo (drivers)</p>
            </div>
            <div class="requirement">
                <div class="icon">📋</div>
                <p>MEI (opcional)</p>
            </div>
        </div>
    </section>
    
    <!-- How it works -->
    <section class="how-it-works">
        <h2>Como funciona</h2>
        
        <div class="steps">
            <div class="step">
                <div class="number">1</div>
                <h3>Cadastre-se</h3>
                <p>Preencha seus dados e envie os documentos</p>
            </div>
            <div class="step">
                <div class="number">2</div>
                <h3>Aguarde aprovação</h3>
                <p>Nossa equipe analisa em até 48h</p>
            </div>
            <div class="step">
                <div class="number">3</div>
                <h3>Comece a ganhar</h3>
                <p>Fique online e aceite ofertas!</p>
            </div>
        </div>
    </section>
    
    <!-- CTA -->
    <section class="cta">
        <h2>Pronto para começar?</h2>
        <p>Junte-se a milhares de parceiros que já estão ganhando com a OneMundo</p>
        <div class="cta-buttons">
            <a href="cadastro.php" class="primary">Fazer meu cadastro</a>
            <a href="login.php" class="secondary">Já tenho conta</a>
        </div>
    </section>
    
    <!-- Login link -->
    <div class="login-link">
        Já é parceiro? <a href="login.php">Faça login aqui</a>
    </div>
    
    <!-- Footer -->
    <footer class="footer">
        OneMundo © 2025 - Todos os direitos reservados
    </footer>
</body>
</html>
