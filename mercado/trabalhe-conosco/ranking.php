<?php
require_once __DIR__ . '/includes/theme.php';
pageStart('Ranking');
echo renderHeader('Ranking');
$ranking = [
    ['pos'=>1,'name'=>'Carlos M.','orders'=>156,'avatar'=>'CM'],
    ['pos'=>2,'name'=>'Ana P.','orders'=>148,'avatar'=>'AP'],
    ['pos'=>3,'name'=>'Roberto S.','orders'=>142,'avatar'=>'RS'],
    ['pos'=>4,'name'=>'Juliana L.','orders'=>138,'avatar'=>'JL'],
    ['pos'=>5,'name'=>'Fernando C.','orders'=>135,'avatar'=>'FC'],
    ['pos'=>6,'name'=>'Patrícia R.','orders'=>131,'avatar'=>'PR'],
    ['pos'=>7,'name'=>'Lucas A.','orders'=>128,'avatar'=>'LA'],
    ['pos'=>8,'name'=>'Maria S.','orders'=>125,'avatar'=>'MS','me'=>true],
    ['pos'=>9,'name'=>'João V.','orders'=>122,'avatar'=>'JV'],
    ['pos'=>10,'name'=>'Camila F.','orders'=>118,'avatar'=>'CF'],
];
?>
<main class="main">
    <div class="tabs">
        <button class="tab active">Esta Semana</button>
        <button class="tab">Este Mês</button>
        <button class="tab">Geral</button>
    </div>

    <div style="display:flex;justify-content:center;align-items:flex-end;gap:12px;margin-bottom:32px;padding:0 20px;">
        <div style="text-align:center;flex:1;"><div style="width:56px;height:56px;background:var(--blue);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:600;color:#fff;margin:0 auto 8px;">AP</div><div style="font-size:14px;font-weight:600;">Ana P.</div><div style="font-size:13px;color:var(--txt2);">148 pedidos</div><div style="background:var(--blue);color:#fff;padding:4px 12px;border-radius:99px;font-size:12px;font-weight:600;margin-top:8px;display:inline-block;">2º</div></div>
        <div style="text-align:center;flex:1;"><div style="width:72px;height:72px;background:var(--orange);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:600;color:#fff;margin:0 auto 8px;box-shadow:0 4px 16px rgba(234,134,0,.3);">CM</div><div style="font-size:16px;font-weight:600;">Carlos M.</div><div style="font-size:13px;color:var(--txt2);">156 pedidos</div><div style="background:var(--orange);color:#fff;padding:6px 16px;border-radius:99px;font-size:14px;font-weight:600;margin-top:8px;display:inline-block;">🏆 1º</div></div>
        <div style="text-align:center;flex:1;"><div style="width:56px;height:56px;background:var(--purple);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:600;color:#fff;margin:0 auto 8px;">RS</div><div style="font-size:14px;font-weight:600;">Roberto S.</div><div style="font-size:13px;color:var(--txt2);">142 pedidos</div><div style="background:var(--purple);color:#fff;padding:4px 12px;border-radius:99px;font-size:12px;font-weight:600;margin-top:8px;display:inline-block;">3º</div></div>
    </div>

    <section class="section">
        <div class="section-header"><h3 class="section-title">Classificação</h3></div>
        <?php foreach(array_slice($ranking,3) as $r): ?>
        <div class="list-item" style="<?= isset($r['me'])?'background:var(--brand-lt);border-color:var(--brand);':'' ?>">
            <div style="width:32px;font-size:16px;font-weight:700;color:<?= isset($r['me'])?'var(--brand)':'var(--txt2)' ?>;"><?= $r['pos'] ?>º</div>
            <div class="avatar" style="width:40px;height:40px;font-size:14px;background:<?= isset($r['me'])?'var(--brand)':'var(--bg3)' ?>;color:<?= isset($r['me'])?'#fff':'var(--txt2)' ?>;"><?= $r['avatar'] ?></div>
            <div class="list-body"><div class="list-title"><?= $r['name'] ?> <?= isset($r['me'])?'(Você)':'' ?></div><div class="list-subtitle"><?= $r['orders'] ?> pedidos</div></div>
            <?php if(isset($r['me'])): ?><span class="badge badge-success">Você</span><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </section>

    <div class="alert alert-info">
        <div class="alert-icon"><?= icon('info') ?></div>
        <div class="alert-content"><div class="alert-title">Suba no ranking!</div><div class="alert-text">Complete mais entregas para ganhar prêmios.</div></div>
    </div>
</main>
<?php pageEnd(); ?>
