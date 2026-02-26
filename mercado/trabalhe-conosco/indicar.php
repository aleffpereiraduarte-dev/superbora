<?php
require_once __DIR__ . '/includes/theme.php';
$code = 'MARIA50';
pageStart('Indicar Amigos');
echo renderHeader('Indicar Amigos');
?>
<main class="main">
    <div class="hero-card purple" style="text-align:center;">
        <div style="font-size:48px;margin-bottom:12px;"><?= icon('users') ?></div>
        <div class="hero-value">R$ 50</div>
        <div class="hero-subtitle">por cada amigo que se cadastrar</div>
    </div>

    <div class="card" style="text-align:center;">
        <p style="font-size:14px;color:var(--txt2);margin-bottom:16px;">Seu código de indicação</p>
        <div style="background:var(--bg2);border:2px dashed var(--border);border-radius:12px;padding:16px;margin-bottom:16px;">
            <span style="font-size:28px;font-weight:700;letter-spacing:4px;color:var(--brand);" id="code"><?= $code ?></span>
        </div>
        <div class="btn-group" style="margin-top:0;">
            <button class="btn btn-secondary" onclick="copyCode()"><?= icon('copy') ?>Copiar</button>
            <button class="btn btn-primary" onclick="shareCode()"><?= icon('share') ?>Compartilhar</button>
        </div>
    </div>

    <section class="section">
        <div class="section-header"><h3 class="section-title">Como funciona</h3></div>
        <div class="list-item" style="cursor:default;"><div class="list-icon green" style="width:36px;height:36px;"><span style="font-weight:700;">1</span></div><div class="list-body"><div class="list-title">Compartilhe seu código</div><div class="list-subtitle">Envie para amigos</div></div></div>
        <div class="list-item" style="cursor:default;"><div class="list-icon blue" style="width:36px;height:36px;"><span style="font-weight:700;">2</span></div><div class="list-body"><div class="list-title">Amigo se cadastra</div><div class="list-subtitle">Usando seu código</div></div></div>
        <div class="list-item" style="cursor:default;"><div class="list-icon purple" style="width:36px;height:36px;"><span style="font-weight:700;">3</span></div><div class="list-body"><div class="list-title">Amigo faz 10 entregas</div><div class="list-subtitle">Nos primeiros 30 dias</div></div></div>
        <div class="list-item" style="cursor:default;"><div class="list-icon orange" style="width:36px;height:36px;"><span style="font-weight:700;">4</span></div><div class="list-body"><div class="list-title">Vocês dois ganham!</div><div class="list-subtitle">R$ 50 para cada</div></div></div>
    </section>

    <section class="section">
        <div class="section-header"><h3 class="section-title">Suas Indicações</h3></div>
        <div class="stat-grid">
            <div class="stat-card"><div class="stat-value" style="color:var(--brand);">5</div><div class="stat-label">Amigos indicados</div></div>
            <div class="stat-card"><div class="stat-value" style="color:var(--orange);">R$ 150</div><div class="stat-label">Total ganho</div></div>
        </div>
    </section>
</main>
<script>
function copyCode(){navigator.clipboard.writeText('<?= $code ?>');alert('Código copiado!\n\n<?= $code ?>');}
function shareCode(){const t='Faça entregas comigo no OneMundo e ganhe R$50! Use meu código: <?= $code ?>\n\nBaixe: https://onemundo.com.br/app';if(navigator.share)navigator.share({title:'OneMundo',text:t});else{navigator.clipboard.writeText(t);alert('Link copiado!');}}
</script>
<?php pageEnd(); ?>
