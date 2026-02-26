<?php
require_once __DIR__ . '/includes/theme.php';
if (!isset($_SESSION['worker_id'])) { header('Location: login.php'); exit; }

pageStart('Meu Veículo');
echo renderHeader('Meu Veículo');
?>
<main class="main">
    <div class="card" style="text-align:center;padding:32px 20px;">
        <div class="card-icon blue" style="width:64px;height:64px;margin:0 auto 16px;"><?= icon('car') ?></div>
        <h2 style="font-size:20px;font-weight:600;margin-bottom:4px;">Honda Civic</h2>
        <p class="text-muted">ABC-1D23 • 2020</p>
        <span class="badge badge-success" style="margin-top:12px;">Aprovado</span>
    </div>

    <section class="section">
        <div class="section-header"><h3 class="section-title">Informações do Veículo</h3><span class="section-link" onclick="editVehicle()">Editar</span></div>
        <div class="card">
            <div class="info-row"><span class="info-label">Tipo</span><span class="info-value">Carro</span></div>
            <div class="info-row"><span class="info-label">Modelo</span><span class="info-value">Honda Civic</span></div>
            <div class="info-row"><span class="info-label">Placa</span><span class="info-value">ABC-1D23</span></div>
            <div class="info-row"><span class="info-label">Ano</span><span class="info-value">2020</span></div>
            <div class="info-row"><span class="info-label">Cor</span><span class="info-value">Prata</span></div>
        </div>
    </section>

    <section class="section">
        <div class="section-header"><h3 class="section-title">Documentos do Veículo</h3></div>
        <div class="list-item"><div class="list-icon green"><?= icon('document') ?></div><div class="list-body"><div class="list-title">CRLV 2024</div><div class="list-subtitle">Enviado em 15/01/2024</div></div><span class="badge badge-success">Aprovado</span></div>
        <div class="list-item"><div class="list-icon green"><?= icon('photo') ?></div><div class="list-body"><div class="list-title">Foto do Veículo</div><div class="list-subtitle">Atualizado em 10/12/2024</div></div><span class="badge badge-success">Aprovado</span></div>
    </section>

    <div class="alert alert-info">
        <div class="alert-icon"><?= icon('info') ?></div>
        <div class="alert-content"><div class="alert-title">Mantenha documentos atualizados</div><div class="alert-text">O CRLV deve ser renovado anualmente.</div></div>
    </div>

    <button class="btn btn-secondary" onclick="if(confirm('Cadastrar novo veículo?'))alert('Redirecionar...')"><?= icon('refresh') ?>Trocar Veículo</button>
</main>
<script>function editVehicle(){alert('Função em desenvolvimento');}</script>
<?php pageEnd(); ?>
