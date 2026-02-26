<?php
require_once __DIR__ . '/includes/theme.php';
if (!isset($_SESSION['worker_id'])) { header('Location: login.php'); exit; }

pageStart('Histórico');
echo renderHeader('Histórico');
$pedidos = [
    ['id'=>'OM-45893','loja'=>'Supermercado Extra','valor'=>58.00,'status'=>'Entregue','hora'=>'15:42'],
    ['id'=>'OM-45892','loja'=>'Drogaria São Paulo','valor'=>42.50,'status'=>'Entregue','hora'=>'14:30'],
    ['id'=>'OM-45891','loja'=>'Pão de Açúcar','valor'=>67.00,'status'=>'Entregue','hora'=>'12:15'],
    ['id'=>'OM-45890','loja'=>'Carrefour Express','valor'=>38.00,'status'=>'Entregue','hora'=>'10:30'],
];
?>
<main class="main">
    <div class="tabs">
        <button class="tab active">Hoje</button>
        <button class="tab">Semana</button>
        <button class="tab">Mês</button>
    </div>

    <section class="section">
        <div class="section-header"><h3 class="section-title">Hoje - <?= count($pedidos) ?> entregas</h3></div>
        <?php foreach($pedidos as $p): ?>
        <div class="list-item" onclick="location.href='detalhes-pedido.php?id=<?= $p['id'] ?>'">
            <div class="list-icon green"><?= icon('check') ?></div>
            <div class="list-body">
                <div class="list-title"><?= $p['loja'] ?></div>
                <div class="list-subtitle"><?= $p['id'] ?> • <?= $p['hora'] ?></div>
            </div>
            <div class="list-meta">
                <div class="list-value">R$ <?= number_format($p['valor'],2,',','.') ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </section>
</main>
<?php pageEnd(); ?>
