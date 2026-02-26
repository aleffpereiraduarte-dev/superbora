<?php
require_once __DIR__ . '/includes/theme.php';
if (!isset($_SESSION['worker_id'])) { header('Location: login.php'); exit; }

pageStart('Configurações');
echo renderHeader('Configurações');
?>
<main class="main">
    <section class="section">
        <div class="section-header"><h3 class="section-title">Notificações</h3></div>
        <div class="card">
            <div class="info-row">
                <div><div style="font-weight:500;margin-bottom:2px;">Novos pedidos</div><div class="text-sm text-muted">Receber alertas de novos pedidos</div></div>
                <div class="toggle active" onclick="this.classList.toggle('active')"></div>
            </div>
            <div class="info-row">
                <div><div style="font-weight:500;margin-bottom:2px;">Som de alerta</div><div class="text-sm text-muted">Tocar som ao receber pedido</div></div>
                <div class="toggle active" onclick="this.classList.toggle('active')"></div>
            </div>
            <div class="info-row">
                <div><div style="font-weight:500;margin-bottom:2px;">Vibração</div><div class="text-sm text-muted">Vibrar ao receber pedido</div></div>
                <div class="toggle active" onclick="this.classList.toggle('active')"></div>
            </div>
            <div class="info-row">
                <div><div style="font-weight:500;margin-bottom:2px;">Promoções</div><div class="text-sm text-muted">Receber ofertas e bônus</div></div>
                <div class="toggle active" onclick="this.classList.toggle('active')"></div>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="section-header"><h3 class="section-title">Preferências</h3></div>
        <div class="list-item"><div class="list-icon blue"><?= icon('chat') ?></div><div class="list-body"><div class="list-title">Idioma</div><div class="list-subtitle">Português (Brasil)</div></div><div class="list-arrow"><?= icon('arrow-right') ?></div></div>
        <div class="list-item"><div class="list-icon green"><?= icon('map') ?></div><div class="list-body"><div class="list-title">Raio de entrega</div><div class="list-subtitle">Até 10 km</div></div><div class="list-arrow"><?= icon('arrow-right') ?></div></div>
        <div class="list-item"><div class="list-icon orange"><?= icon('package') ?></div><div class="list-body"><div class="list-title">Tipos de pedido</div><div class="list-subtitle">Supermercado, Farmácia</div></div><div class="list-arrow"><?= icon('arrow-right') ?></div></div>
    </section>

    <section class="section">
        <div class="section-header"><h3 class="section-title">Privacidade</h3></div>
        <div class="list-item"><div class="list-icon purple"><?= icon('cog') ?></div><div class="list-body"><div class="list-title">Alterar senha</div><div class="list-subtitle">Última alteração há 30 dias</div></div><div class="list-arrow"><?= icon('arrow-right') ?></div></div>
        <div class="list-item"><div class="list-icon green"><?= icon('check') ?></div><div class="list-body"><div class="list-title">Verificação em duas etapas</div><div class="list-subtitle">Ativado</div></div><div class="list-arrow"><?= icon('arrow-right') ?></div></div>
    </section>

    <section class="section">
        <div class="section-header"><h3 class="section-title">Sobre</h3></div>
        <div class="card">
            <div class="info-row"><span class="info-label">Versão do app</span><span class="info-value">2.5.0</span></div>
            <div class="info-row"><span class="info-label">Última atualização</span><span class="info-value">15/12/2024</span></div>
        </div>
    </section>

    <button class="btn btn-danger" onclick="if(confirm('Sair da conta?'))location.href='login.php?logout=1'"><?= icon('logout') ?>Sair da conta</button>
</main>
<?php pageEnd(); ?>
