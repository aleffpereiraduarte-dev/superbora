<?php
require_once __DIR__ . '/includes/theme.php';
if (!isset($_SESSION['worker_id'])) { header('Location: login.php'); exit; }

pageStart('Documentos');
echo renderHeader('Documentos');
?>
<main class="main">
    <div class="alert alert-success" style="margin-bottom:24px;">
        <div class="alert-icon"><?= icon('check') ?></div>
        <div class="alert-content"><div class="alert-title">Todos os documentos aprovados</div><div class="alert-text">Você está apto a realizar entregas!</div></div>
    </div>

    <section class="section">
        <div class="section-header"><h3 class="section-title">Documentos Pessoais</h3></div>
        <div class="list-item"><div class="list-icon green"><?= icon('document') ?></div><div class="list-body"><div class="list-title">CNH</div><div class="list-subtitle">Válida até 12/2028</div></div><span class="badge badge-success">Aprovado</span></div>
        <div class="list-item"><div class="list-icon green"><?= icon('document') ?></div><div class="list-body"><div class="list-title">CPF</div><div class="list-subtitle">Enviado em 10/01/2024</div></div><span class="badge badge-success">Aprovado</span></div>
        <div class="list-item"><div class="list-icon green"><?= icon('document') ?></div><div class="list-body"><div class="list-title">Comprovante de Residência</div><div class="list-subtitle">Atualizado em 01/12/2024</div></div><span class="badge badge-success">Aprovado</span></div>
        <div class="list-item"><div class="list-icon green"><?= icon('camera') ?></div><div class="list-body"><div class="list-title">Foto de Perfil</div><div class="list-subtitle">Atualizado em 15/11/2024</div></div><span class="badge badge-success">Aprovado</span></div>
    </section>

    <section class="section">
        <div class="section-header"><h3 class="section-title">Documentos do Veículo</h3></div>
        <div class="list-item"><div class="list-icon orange"><?= icon('document') ?></div><div class="list-body"><div class="list-title">CRLV 2024</div><div class="list-subtitle">Vence em 31/12/2024</div></div><span class="badge badge-warning">Renovar</span></div>
        <div class="list-item"><div class="list-icon green"><?= icon('photo') ?></div><div class="list-body"><div class="list-title">Foto do Veículo</div><div class="list-subtitle">Enviado em 10/12/2024</div></div><span class="badge badge-success">Aprovado</span></div>
    </section>

    <div class="divider"></div>
    <button class="btn btn-primary" onclick="alert('Selecione o tipo de documento')"><?= icon('upload') ?>Enviar Novo Documento</button>
</main>
<?php pageEnd(); ?>
