<?php
require_once __DIR__ . '/includes/theme.php';
pageStart('Ajuda');
echo renderHeader('Ajuda');
?>
<main class="main">
    <div class="input-group" style="margin-bottom:24px;">
        <div class="input-icon"><?= icon('search') ?><input type="text" class="input" placeholder="Buscar ajuda..." id="search-input" oninput="filterFAQ()"></div>
    </div>

    <section class="section">
        <div class="section-header"><h3 class="section-title">Tópicos Populares</h3></div>
        
        <div class="list-item faq-item" onclick="toggleFAQ(this)" data-keywords="pagamento receber dinheiro saque">
            <div class="list-icon green"><?= icon('money') ?></div>
            <div class="list-body"><div class="list-title">Como recebo meus pagamentos?</div></div>
            <div class="list-arrow"><?= icon('arrow-right') ?></div>
        </div>
        <div class="faq-answer" style="display:none;padding:0 16px 16px 70px;color:var(--txt2);font-size:14px;line-height:1.6;">
            Os pagamentos são creditados automaticamente após cada entrega. Você pode sacar a qualquer momento via PIX, sem taxa e com transferência instantânea.
        </div>
        
        <div class="list-item faq-item" onclick="toggleFAQ(this)" data-keywords="cancelar pedido cancelamento">
            <div class="list-icon red"><?= icon('x') ?></div>
            <div class="list-body"><div class="list-title">Como cancelar um pedido?</div></div>
            <div class="list-arrow"><?= icon('arrow-right') ?></div>
        </div>
        <div class="faq-answer" style="display:none;padding:0 16px 16px 70px;color:var(--txt2);font-size:14px;line-height:1.6;">
            Para cancelar um pedido, acesse os detalhes do pedido e clique em "Cancelar". Cancelamentos frequentes afetam sua avaliação.
        </div>
        
        <div class="list-item faq-item" onclick="toggleFAQ(this)" data-keywords="documento documentos cnh">
            <div class="list-icon blue"><?= icon('document') ?></div>
            <div class="list-body"><div class="list-title">Quais documentos são necessários?</div></div>
            <div class="list-arrow"><?= icon('arrow-right') ?></div>
        </div>
        <div class="faq-answer" style="display:none;padding:0 16px 16px 70px;color:var(--txt2);font-size:14px;line-height:1.6;">
            Você precisa de: RG ou CNH, CPF, comprovante de residência e foto do veículo. Para carros e motos, também é necessário o CRLV.
        </div>
        
        <div class="list-item faq-item" onclick="toggleFAQ(this)" data-keywords="bonus desafio premio">
            <div class="list-icon orange"><?= icon('gift') ?></div>
            <div class="list-body"><div class="list-title">Como funcionam os bônus?</div></div>
            <div class="list-arrow"><?= icon('arrow-right') ?></div>
        </div>
        <div class="faq-answer" style="display:none;padding:0 16px 16px 70px;color:var(--txt2);font-size:14px;line-height:1.6;">
            Você ganha bônus completando desafios diários e semanais. Os bônus são creditados automaticamente.
        </div>
    </section>

    <section class="section">
        <div class="section-header"><h3 class="section-title">Precisa de mais ajuda?</h3></div>
        <div class="list-item" onclick="alert('Abrindo chat...')"><div class="list-icon green"><?= icon('chat') ?></div><div class="list-body"><div class="list-title">Chat com Suporte</div><div class="list-subtitle">Tempo médio: 5 min</div></div><div class="list-arrow"><?= icon('arrow-right') ?></div></div>
        <div class="list-item" onclick="location.href='tel:08001234567'"><div class="list-icon blue"><?= icon('phone') ?></div><div class="list-body"><div class="list-title">Ligar para Suporte</div><div class="list-subtitle">0800 123 4567 (24h)</div></div><div class="list-arrow"><?= icon('arrow-right') ?></div></div>
    </section>
</main>

<script>
function toggleFAQ(el){const a=el.nextElementSibling,ar=el.querySelector('.list-arrow'),o=a.style.display!=='none';document.querySelectorAll('.faq-answer').forEach(x=>x.style.display='none');document.querySelectorAll('.faq-item .list-arrow').forEach(x=>x.style.transform='');if(!o){a.style.display='block';ar.style.transform='rotate(90deg)';}}
function filterFAQ(){const s=document.getElementById('search-input').value.toLowerCase();document.querySelectorAll('.faq-item').forEach(i=>{const k=i.dataset.keywords.toLowerCase(),t=i.querySelector('.list-title').textContent.toLowerCase();i.style.display=(k.includes(s)||t.includes(s)||s==='')?'flex':'none';i.nextElementSibling.style.display='none';});}
</script>
<?php pageEnd(); ?>
