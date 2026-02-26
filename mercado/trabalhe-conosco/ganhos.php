<?php
require_once __DIR__ . '/includes/theme.php';
if (!isset($_SESSION['worker_id'])) { header('Location: login.php'); exit; }

pageStart('Meus Ganhos');
echo renderHeader('Meus Ganhos');
?>
<main class="main">
    <div class="tabs">
        <button class="tab active">Hoje</button>
        <button class="tab">Semana</button>
        <button class="tab">Mês</button>
    </div>

    <div class="hero-card">
        <div class="hero-label">Ganhos de hoje</div>
        <div class="hero-value">R$ 342,00</div>
        <div class="hero-subtitle">9 entregas realizadas</div>
    </div>

    <div class="stat-grid">
        <div class="stat-card"><div class="stat-value" style="color:var(--brand);">R$ 275</div><div class="stat-label">Entregas</div></div>
        <div class="stat-card"><div class="stat-value" style="color:var(--orange);">R$ 67</div><div class="stat-label">Gorjetas</div></div>
        <div class="stat-card"><div class="stat-value" style="color:var(--purple);">R$ 25</div><div class="stat-label">Bônus</div></div>
        <div class="stat-card"><div class="stat-value" style="color:var(--blue);">7.2h</div><div class="stat-label">Online</div></div>
    </div>

    <section class="section">
        <div class="section-header"><h3 class="section-title">Detalhamento</h3></div>
        <div class="list-item"><div class="list-icon green"><?= icon('package') ?></div><div class="list-body"><div class="list-title">9 entregas</div><div class="list-subtitle">Média R$ 30,56 por entrega</div></div><div class="list-value">R$ 275,00</div></div>
        <div class="list-item"><div class="list-icon orange"><?= icon('star') ?></div><div class="list-body"><div class="list-title">Gorjetas</div><div class="list-subtitle">4 clientes deixaram gorjeta</div></div><div class="list-value">R$ 67,00</div></div>
        <div class="list-item"><div class="list-icon purple"><?= icon('trophy') ?></div><div class="list-body"><div class="list-title">Bônus desafio</div><div class="list-subtitle">Complete 10 entregas</div></div><div class="list-value">R$ 25,00</div></div>
    </section>
</main>
<?php pageEnd(); ?>
