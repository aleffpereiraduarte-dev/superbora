<?php
require_once __DIR__ . '/includes/theme.php';
pageStart('Notificações');
echo renderHeader('Notificações', true, ['label' => 'Limpar', 'onclick' => "if(confirm('Limpar todas?'))document.querySelectorAll('.notif').forEach(x=>x.remove())"]);
?>
<main class="main">
    <div class="tabs">
        <button class="tab active" onclick="filterNotifs('all',this)">Todas</button>
        <button class="tab" onclick="filterNotifs('payments',this)">Pagamentos</button>
        <button class="tab" onclick="filterNotifs('orders',this)">Pedidos</button>
        <button class="tab" onclick="filterNotifs('promos',this)">Promoções</button>
    </div>

    <section class="section"><div class="section-header"><h3 class="section-title">Hoje</h3></div>
        <div class="list-item notif" data-type="payments"><div class="list-icon green"><?= icon('check') ?></div><div class="list-body"><div class="list-title">Pagamento recebido</div><div class="list-subtitle">R$ 58,00 do pedido #OM-45892 • 15:42</div></div></div>
        <div class="list-item notif" data-type="promos"><div class="list-icon purple"><?= icon('lightning') ?></div><div class="list-body"><div class="list-title">Bônus 2x ativado!</div><div class="list-subtitle">Ganhe o dobro até 21h • 14:00</div></div></div>
        <div class="list-item notif" data-type="orders"><div class="list-icon orange"><?= icon('trophy') ?></div><div class="list-body"><div class="list-title">Falta 1 pedido!</div><div class="list-subtitle">Complete o desafio e ganhe R$ 50 • 13:30</div></div></div>
    </section>

    <section class="section"><div class="section-header"><h3 class="section-title">Ontem</h3></div>
        <div class="list-item notif" data-type="payments"><div class="list-icon green"><?= icon('check') ?></div><div class="list-body"><div class="list-title">Saque realizado</div><div class="list-subtitle">R$ 500,00 via PIX • 18:30</div></div></div>
        <div class="list-item notif" data-type="orders"><div class="list-icon blue"><?= icon('star') ?></div><div class="list-body"><div class="list-title">Nova avaliação</div><div class="list-subtitle">Cliente deu 5 estrelas! • 17:45</div></div></div>
        <div class="list-item notif" data-type="promos"><div class="list-icon orange"><?= icon('gift') ?></div><div class="list-body"><div class="list-title">Desafio concluído!</div><div class="list-subtitle">Você ganhou R$ 25,00 • 16:00</div></div></div>
    </section>
</main>
<script>
function filterNotifs(t,btn){document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));btn.classList.add('active');document.querySelectorAll('.notif').forEach(n=>{n.style.display=(t==='all'||n.dataset.type===t)?'flex':'none';});}
</script>
<?php pageEnd(); ?>
